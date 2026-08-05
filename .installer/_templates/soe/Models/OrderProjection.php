<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Pure function: project a Helix ticket + order record into a UI view.
 * One-way, no side effects — the source of truth for what the Tracker renders.
 * Matches DESIGN-DD.md §6 exactly.
 */
final class OrderProjection
{
    /**
     * Project a ticket and order record into a tracker view model.
     *
     * @param array $ticket from HelixClient::getTicket()
     * @param array $order from OrderModel::getOrder()
     * @return array with stages, lanes, blocker, elapsed time, etc.
     */
    public static function project(array $ticket, array $order): array
    {
        // Default stage list, mapped from queue names
        $stageList = [
            'Received' => 'Virtualization',
            'Provisioning' => 'Virtualization',
            'OS & Hardening' => ['Linux Engineering', 'Security Engineering'],
            'Security Gates' => 'Security Engineering',
            'Build-out' => ['Directory Services', 'PKI / Certificate', 'Linux Engineering'],
            'Evidence' => 'Service Desk',
            'Handover' => 'Service Desk',
            'Delivered' => 'Service Desk'
        ];

        // Compute current stage based on ticket status and currentQueue
        $currentStage = self::mapQueueToStage($ticket['currentQueue'] ?? '', $stageList);

        // Extract buildout lanes from queues; only show during the build-out stage
        $lanes = [];
        if (in_array($currentStage, ['Build-out', 'Security Gates', 'Evidence'], true)) {
            // Show parallel work: app stack, security, identity, cert, host fw, network fw
            $lanes = [
                ['name' => 'App Stack', 'status' => 'in_progress'],
                ['name' => 'Security Agent', 'status' => 'in_progress'],
                ['name' => 'Identity (AD/PAM)', 'status' => 'pending'],
                ['name' => 'Certificate', 'status' => 'pending'],
                ['name' => 'Host Firewall', 'status' => 'pending'],
                ['name' => 'Network Firewall', 'status' => $ticket['blocker'] ? 'blocked' : 'pending']
            ];
        }

        // Map queue states to stage completion
        $stageStates = [];
        foreach ($stageList as $stage => $queueNames) {
            if (!is_array($queueNames)) {
                $queueNames = [$queueNames];
            }

            $stageState = 'pending';
            foreach ($ticket['queues'] as $q) {
                if (in_array($q['name'], $queueNames, true)) {
                    if ($q['state'] === 'done') {
                        $stageState = 'done';
                        break;
                    } elseif ($q['state'] === 'in_progress') {
                        $stageState = 'in_progress';
                    } elseif ($q['state'] === 'blocked' && $stageState !== 'in_progress') {
                        $stageState = 'blocked';
                    }
                }
            }

            $stageStates[$stage] = $stageState;
        }

        // Blocker banner
        $blockerBanner = null;
        if ($ticket['blocker']) {
            $blockerBanner = [
                'team' => $ticket['blocker']['team'] ?? 'Unknown',
                'reason' => $ticket['blocker']['reason'] ?? 'Blocked',
                'since' => $ticket['blocker']['since'] ?? date('c'),
                'age' => self::formatAge($ticket['blocker']['since'] ?? date('c'))
            ];
        }

        // Elapsed time since submission
        $createdAt = strtotime($ticket['createdAt'] ?? 'now');
        $elapsedSeconds = time() - $createdAt;
        $elapsedDays = (int) floor($elapsedSeconds / 86400);
        $elapsedHours = (int) floor(($elapsedSeconds % 86400) / 3600);

        return [
            'orderId' => $order['id'] ?? null,
            'orderRef' => $ticket['orderRef'] ?? 'unknown',
            'appName' => $order['app_name'] ?? '',
            'status' => $ticket['status'] ?? 'unknown',
            'currentStage' => $currentStage,
            'stages' => $stageStates,
            'lanes' => $lanes,
            'blocker' => $blockerBanner,
            'elapsed' => [
                'days' => $elapsedDays,
                'hours' => $elapsedHours,
                'formatted' => self::formatElapsed($elapsedDays, $elapsedHours)
            ],
            'needBy' => $order['need_by'] ?? null,
            'requirementsRecord' => $order['requirements_record'] ?? '',
            'createdAt' => $ticket['createdAt'] ?? date('c')
        ];
    }

    /**
     * Map a queue name to a stage name.
     */
    private static function mapQueueToStage(string $queue, array $stageList): string
    {
        foreach ($stageList as $stage => $queueNames) {
            if (!is_array($queueNames)) {
                $queueNames = [$queueNames];
            }
            if (in_array($queue, $queueNames, true)) {
                return $stage;
            }
        }

        return 'Received';
    }

    /**
     * Format elapsed time in a human-readable way.
     */
    private static function formatElapsed(int $days, int $hours): string
    {
        if ($days > 0) {
            $remainingHours = $hours % 24;
            return "{$days}d {$remainingHours}h";
        }
        return "{$hours}h";
    }

    /**
     * Format the age of a blocker (time since it began).
     */
    private static function formatAge(string $blockerSince): string
    {
        $since = strtotime($blockerSince);
        $now = time();
        $diff = $now - $since;

        $hours = (int) floor($diff / 3600);
        $days = (int) floor($hours / 24);
        $remainingHours = $hours % 24;

        if ($days > 0) {
            return "{$days}d {$remainingHours}h";
        }
        return "{$hours}h";
    }
}
