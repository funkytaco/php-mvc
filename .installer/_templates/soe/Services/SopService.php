<?php

declare(strict_types=1);

namespace App\Services;

use App\Helix\HelixClientInterface;
use App\Persistence\AuditLog;
use App\Persistence\SopNoteRepository;
use App\Persistence\SopRunRepository;

/**
 * Team SOPs and the Task View (FR-TASK-01…06, DESIGN-DD §7).
 *
 * This surface is READ + COLLABORATE, never write-to-Helix. Teams perform and
 * attest their work IN Helix (their queue, their tool); that attestation moves
 * the ticket and we observe it via getTicket(). The exit-contract checklist
 * below is therefore informational — it tells a member what "done" means, it
 * does not let them mark it done here. Adding a "complete this node" action
 * that called Helix would be a second write and would break Golden Rule 2.
 */
final class SopService
{
    /**
     * The seven teams, their Helix queue name, and their default SOP
     * (FR-TASK-01). Mirrors the prototype's TEAMS and SOPS.
     */
    public const TEAMS = [
        'virt' => [
            'name' => 'Virtualization',
            'queue' => 'Virtualization',
            'role' => 'VM provisioning (tracked in vCenter)',
            'abbr' => 'VZ',
            'sop' => [
                'Confirm capacity/entitlement for the requested sizing.',
                'Reserve hostname and IP per the environment profile.',
                'Provision the VM in vCenter to the certified template.',
                'Attach storage; attach the VM record as evidence.',
            ],
            'exit_contract' => ['VM record attached', 'Hostname and IP reserved', 'Storage attached'],
        ],
        'linux' => [
            'name' => 'Linux Engineering',
            'queue' => 'Linux Engineering',
            'role' => 'OS, content, app stack, host firewall',
            'abbr' => 'LX',
            'sop' => [
                'Deploy the OS SKU in FIPS mode where the profile requires.',
                'Register the host to the approved content source.',
                'Apply the patch baseline; enable the requested module stream.',
                'Install Apache/MariaDB; apply host firewall default-deny.',
            ],
            'exit_contract' => ['OS SKU deployed', 'Patch baseline applied', 'Host firewall default-deny in place'],
        ],
        'security' => [
            'name' => 'Security Engineering',
            'queue' => 'Security Engineering',
            'role' => 'Security SKUs & scans',
            'abbr' => 'SE',
            'sop' => [
                'Deploy the Tripwire FIM agent.',
                'Apply the hardening/STIG baseline for the OS SKU.',
                'Run the vulnerability scan; attach the scan report as evidence.',
            ],
            'exit_contract' => ['FIM agent reporting', 'STIG baseline applied', 'Scan report attached'],
        ],
        'dir' => [
            'name' => 'Directory Services',
            'queue' => 'Directory Services',
            'role' => 'AD / identity SKUs',
            'abbr' => 'DS',
            'sop' => [
                'Join the host to Active Directory.',
                'Place the computer object in the correct OU/group.',
                'Enrol the host in Delinea privileged access.',
            ],
            'exit_contract' => ['Host domain-joined', 'Computer object in correct OU', 'PAM enrolment confirmed'],
        ],
        'pki' => [
            'name' => 'PKI / Certificate',
            'queue' => 'PKI / Certificate',
            'role' => 'SSL certificates',
            'abbr' => 'PK',
            'sop' => [
                'Generate the CSR using the final FQDN.',
                'Request the certificate from the profile-designated CA.',
                'Install and bind the certificate; verify chain and expiry.',
                'Record serial and expiry — renewal auto-spawns near expiry.',
            ],
            'exit_contract' => ['Certificate installed and bound', 'Chain verified', 'Serial and expiry recorded'],
        ],
        'netsec' => [
            'name' => 'Network Security',
            'queue' => 'Network Security',
            'role' => 'Network firewall changes',
            'abbr' => 'NS',
            'sop' => [
                'Receive the network firewall change request.',
                'Route through the Firewall Change Approval gate (approver ≠ implementer).',
                'Implement the perimeter/zone policy; attach the change number.',
            ],
            // FR-GOV-02: segregation of duties is part of this team's exit contract.
            'exit_contract' => ['Change approved by someone other than the implementer', 'Zone policy implemented', 'Change number attached'],
        ],
        'desk' => [
            'name' => 'Service Desk',
            'queue' => 'Service Desk',
            'role' => 'CMDB record & handover',
            'abbr' => 'SD',
            'sop' => [
                'Create the CMDB record for the delivered host.',
                'Open the handover ticket to the requester.',
                'Confirm delivery and close the order.',
            ],
            'exit_contract' => ['CMDB record created', 'Handover ticket opened', 'Delivery confirmed'],
        ],
    ];

    public function __construct(
        private HelixClientInterface $helix,
        private SopNoteRepository $notes,
        private AuditLog $audit,
        private SopRunRepository $runs,
    ) {
    }

    /**
     * Every team with its SOP, projected queue and notes (FR-TASK-01, 02, 05).
     *
     * @return array<int,array<string,mixed>>
     */
    public function allTeams(): array
    {
        $tickets = $this->helix->listTickets();
        $notesByTeam = $this->notes->groupedByTeam();

        $out = [];
        foreach (self::TEAMS as $key => $team) {
            $out[] = $this->assembleTeam($key, $team, $tickets, $notesByTeam[$key] ?? [], null);
        }

        return $out;
    }

    /**
     * One team. When $orderRef is given, each SOP step also carries the state
     * of its most recent rulebook run against THAT order — runs are per-order
     * so two builds never share a checkmark.
     *
     * @return array<string,mixed>|null
     */
    public function team(string $key, ?string $orderRef = null): ?array
    {
        if (!isset(self::TEAMS[$key])) {
            return null;
        }

        return $this->assembleTeam(
            $key,
            self::TEAMS[$key],
            $this->helix->listTickets(),
            $this->notes->forTeam($key),
            $orderRef
        );
    }

    /**
     * @param array<string,mixed>            $team
     * @param array<int,array<string,mixed>> $tickets
     * @param array<int,array<string,mixed>> $notes
     * @return array<string,mixed>
     */
    private function assembleTeam(string $key, array $team, array $tickets, array $notes, ?string $orderRef): array
    {
        $bindings = $this->runs->bindingsFor($key);
        $latestRuns = $orderRef !== null ? $this->runs->latestRunsFor($key, $orderRef) : [];

        return [
            'key' => $key,
            'name' => $team['name'],
            'role' => $team['role'],
            'abbr' => $team['abbr'],
            'sop' => $this->buildSteps($team['sop'], $bindings, $latestRuns, $orderRef !== null),
            'exit_contract' => array_map(static fn (string $c): array => ['item' => $c], $team['exit_contract']),
            'queue' => $this->queueFor($team['queue'], $tickets),
            'notes' => array_map(static fn (array $n): array => [
                'author' => $n['author'],
                'body' => $n['body'],
                'is_cross_post' => (bool) $n['is_cross_post'],
                'is_customer' => (bool) $n['is_customer'],
                'created_at' => $n['created_at'],
                'ago' => self::ago((string) $n['created_at']),
            ], $notes),
            'note_count' => count($notes),
            'has_notes' => $notes !== [],
        ];
    }

    /**
     * SOP steps decorated with their rulebook binding and, when an order is in
     * context, the state of the latest run against that order.
     *
     * The step TEXT stays governed — it comes from self::TEAMS, not the
     * database — so a team can automate a step without rewriting the certified
     * procedure.
     *
     * @param array<int,string>              $steps
     * @param array<int,string>              $bindings   step index => rulebook
     * @param array<int,array<string,mixed>> $latestRuns step index => run row
     * @return array<int,array<string,mixed>>
     */
    private function buildSteps(array $steps, array $bindings, array $latestRuns, bool $haveOrder): array
    {
        $out = [];

        foreach (array_values($steps) as $i => $text) {
            $rulebook = $bindings[$i] ?? null;
            $run = $latestRuns[$i] ?? null;
            $status = $run['status'] ?? null;

            $out[] = [
                'index' => $i,
                'number' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'step' => $text,

                'rulebook' => $rulebook,
                'has_rulebook' => $rulebook !== null,

                // Runnable only when a rulebook is bound AND we know which
                // order to run it for.
                'can_run' => $rulebook !== null && $haveOrder,

                'status' => $status,
                'has_run' => $run !== null,
                'is_completed' => $status === SopRunRepository::COMPLETED,
                'is_failed' => $status === SopRunRepository::FAILED,
                'is_running' => in_array($status, [SopRunRepository::QUEUED, SopRunRepository::RUNNING], true),
                'status_label' => match ($status) {
                    SopRunRepository::COMPLETED => 'Completed',
                    SopRunRepository::FAILED => 'Failed',
                    SopRunRepository::RUNNING => 'Running',
                    SopRunRepository::QUEUED => 'Queued',
                    default => null,
                },
                'result' => $run['result'] ?? null,
                'ran_ago' => $run !== null
                    ? self::ago((string) ($run['finished_at'] ?? $run['started_at']))
                    : null,
                'actor' => $run['actor'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * A team's projected queue: the Ready / In Progress items Helix says are
     * theirs, oldest-waiting first (FR-TASK-02).
     *
     * Sorted by age, never by an SLA target — Golden Rule 6 / NG6 forbid
     * SLA-based prioritisation.
     *
     * @param array<int,array<string,mixed>> $tickets
     * @return array<int,array<string,mixed>>
     */
    private function queueFor(string $queueName, array $tickets): array
    {
        $items = [];

        foreach ($tickets as $ticket) {
            foreach (($ticket['queues'] ?? []) as $q) {
                if (($q['name'] ?? null) !== $queueName) {
                    continue;
                }

                $state = (string) ($q['state'] ?? 'pending');
                if (!in_array($state, ['ready', 'in_progress', 'blocked'], true)) {
                    continue;
                }

                $created = strtotime((string) ($ticket['createdAt'] ?? 'now')) ?: time();
                $items[] = [
                    'order_ref' => $ticket['orderRef'],
                    'ticket_ref' => $ticket['id'],
                    'state' => $state,
                    'state_label' => match ($state) {
                        'ready' => 'Ready',
                        'in_progress' => 'In progress',
                        default => 'Blocked',
                    },
                    'is_blocked' => $state === 'blocked',
                    'waiting' => self::ago((string) ($ticket['createdAt'] ?? '')),
                    '_sort' => $created,
                ];
            }
        }

        // Oldest waiting first.
        usort($items, static fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);

        return array_map(static function (array $i): array {
            unset($i['_sort']);

            return $i;
        }, $items);
    }

    /**
     * Add an attributed, time-stamped note or cross-post (FR-TASK-05).
     * Write ledger bucket 3 — never touches Helix.
     */
    public function addNote(string $team, string $authorKey, string $body): bool
    {
        if (!isset(self::TEAMS[$team]) || trim($body) === '') {
            return false;
        }

        if ($authorKey === 'customer') {
            $this->notes->add($team, 'Customer', null, false, true, trim($body));
        } elseif (isset(self::TEAMS[$authorKey])) {
            $this->notes->add($team, self::TEAMS[$authorKey]['name'], $authorKey, true, false, trim($body));
        } else {
            return false;
        }

        $this->audit->record('sop.note_added', $team, 'Note posted by ' . $authorKey);

        return true;
    }

    /** Teams available as cross-post authors on a given SOP. */
    public function crossPostAuthors(string $excludeTeam): array
    {
        $out = [['key' => 'customer', 'name' => 'Customer']];
        foreach (self::TEAMS as $key => $team) {
            if ($key !== $excludeTeam) {
                $out[] = ['key' => $key, 'name' => $team['name'] . ' (cross-post)'];
            }
        }

        return $out;
    }

    private static function ago(string $timestamp): string
    {
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return '—';
        }

        $seconds = max(0, time() - $ts);
        $hours = intdiv($seconds, 3600);

        if ($hours < 1) {
            return max(1, intdiv($seconds, 60)) . 'm ago';
        }
        if ($hours < 48) {
            return $hours . 'h ago';
        }

        return intdiv($hours, 24) . 'd ' . ($hours % 24) . 'h ago';
    }
}
