<?php

declare(strict_types=1);

namespace App\Domain;

use App\Helix\QueueMap;

/**
 * Ticket + Order → OrderView (DESIGN-DD §6).
 *
 * PURE by contract: no database, no clock injection beyond $now, no side
 * effects, no I/O. The same ticket and order always project to the same view,
 * which is why there is nothing to reconcile and why two clients viewing one
 * order always agree. Keep it that way — this class is directly unit-tested.
 *
 * Golden Rule 3: this READS Helix state and shapes it. It never persists a
 * copy of build progress.
 */
final class OrderProjection
{
    /**
     * @param array<string,mixed> $ticket Helix ticket read shape (DESIGN-DD §5.1)
     * @param array<string,mixed> $order  The app's order row
     * @param int|null            $now    Unix timestamp; injected for testability
     * @return array<string,mixed>        OrderView
     */
    public function project(array $ticket, array $order, ?int $now = null): array
    {
        $now ??= time();

        $status = (string) ($ticket['status'] ?? 'received');
        $blocker = $ticket['blocker'] ?? null;
        $isBlocked = is_array($blocker) && $blocker !== [];
        $isDelivered = $status === 'delivered';

        $activeIndex = QueueMap::stageIndex(
            QueueMap::stageForQueue($ticket['currentQueue'] ?? null)
        );

        $stages = $this->buildStages($activeIndex, $isBlocked, $isDelivered);
        $inBuildout = !$isDelivered && QueueMap::STAGES[$activeIndex]['key'] === 'buildout';

        $createdAt = $this->toTimestamp($ticket['createdAt'] ?? null) ?? $now;

        return [
            'order_ref' => $order['order_ref'] ?? ($ticket['orderRef'] ?? ''),
            'app_name' => $order['app_name'] ?? '',
            'environment' => $order['environment'] ?? '',
            'sensitivity' => $order['sensitivity'] ?? '',
            'expected_users' => (int) ($order['expected_users'] ?? 0),
            'need_by' => $order['need_by'] ?? null,

            // FR-TRK-05: plain-language requirements record + resolved profile.
            'requirements_record' => $order['requirements_record'] ?? '',
            'resolved_profile' => $order['resolved_profile'] ?? null,
            'current_owner' => $isDelivered ? '—' : QueueMap::STAGES[$activeIndex]['team'],

            'status' => $isBlocked ? 'blocked' : ($isDelivered ? 'delivered' : 'in_progress'),
            'status_label' => $isBlocked ? 'Blocked at build-out' : ($isDelivered ? 'Delivered' : 'In fulfilment'),
            'is_blocked' => $isBlocked,
            'is_delivered' => $isDelivered,

            'stages' => $stages,
            'show_lanes' => $inBuildout,
            'lanes' => $inBuildout ? $this->buildLanes($ticket['queues'] ?? []) : [],

            // FR-TRK-04: blocker carries owner, plain-language reason, and age.
            'blocker' => $isBlocked ? [
                'team' => $blocker['team'] ?? 'Unknown team',
                'reason' => $blocker['reason'] ?? '',
                'since' => $blocker['since'] ?? null,
                'age' => $this->humanDuration($now - ($this->toTimestamp($blocker['since'] ?? null) ?? $now)),
            ] : null,

            // FR-TRK-06 / FR-TIME-02: elapsed is DISPLAY ONLY. No SLA target,
            // no breach calculation, no escalation (Golden Rule 6, NG6).
            'elapsed' => $this->humanDuration($now - $createdAt),
            'created_at' => $ticket['createdAt'] ?? null,
            'helix_ticket_ref' => $ticket['id'] ?? null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildStages(int $activeIndex, bool $isBlocked, bool $isDelivered): array
    {
        $stages = [];

        foreach (QueueMap::STAGES as $i => $stage) {
            if ($isDelivered) {
                $state = 'done';
                $label = 'Done';
            } elseif ($i < $activeIndex) {
                $state = 'done';
                $label = 'Done';
            } elseif ($i === $activeIndex) {
                $state = $isBlocked ? 'blocked' : 'active';
                $label = $isBlocked ? 'Blocked' : 'In progress';
            } else {
                $state = 'pending';
                $label = 'Pending';
            }

            $stages[] = [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'team' => $stage['team'],
                'state' => $state,
                'state_label' => $label,
                'is_done' => $state === 'done',
                'is_active' => $state === 'active',
                'is_blocked' => $state === 'blocked',
                'is_pending' => $state === 'pending',
            ];
        }

        return $stages;
    }

    /**
     * Build-out lanes, each reflecting its owning Helix queue's state (FR-TRK-03).
     *
     * Note: two lanes are owned by Linux Engineering (app stack and host
     * firewall), so they share that queue's state. Helix owns queue state and
     * has seven queues to six lanes, so the mapping is lossy by design — we
     * show what Helix actually knows rather than inventing per-lane state.
     *
     * @param array<int,array<string,mixed>> $queues
     * @return array<int,array<string,mixed>>
     */
    private function buildLanes(array $queues): array
    {
        $byName = [];
        foreach ($queues as $q) {
            if (isset($q['name'])) {
                $byName[(string) $q['name']] = (string) ($q['state'] ?? 'pending');
            }
        }

        $lanes = [];
        foreach (QueueMap::LANES as $i => $lane) {
            $queueState = $byName[QueueMap::queueForLane($i) ?? ''] ?? 'pending';

            $state = match ($queueState) {
                'done' => 'done',
                'in_progress' => 'in_progress',
                'blocked' => 'blocked',
                default => 'queued',
            };

            $lanes[] = [
                'label' => $lane['label'],
                'team' => $lane['team'],
                'abbr' => $lane['abbr'],
                'state' => $state,
                'state_label' => match ($state) {
                    'done' => 'Done',
                    'in_progress' => 'In progress',
                    'blocked' => 'Blocked',
                    default => 'Queued',
                },
                'is_done' => $state === 'done',
                'is_in_progress' => $state === 'in_progress',
                'is_blocked' => $state === 'blocked',
                'is_queued' => $state === 'queued',
            ];
        }

        return $lanes;
    }

    /**
     * Mirrors the prototype's fmtDur(): under an hour → minutes, under two
     * days → hours, otherwise days + hours.
     */
    private function humanDuration(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }

        $hours = intdiv($seconds, 3600);

        if ($hours < 1) {
            return max(1, intdiv($seconds, 60)) . 'm';
        }
        if ($hours < 48) {
            return $hours . 'h';
        }

        return intdiv($hours, 24) . 'd ' . ($hours % 24) . 'h';
    }

    private function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }
}
