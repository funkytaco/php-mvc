<?php

declare(strict_types=1);

namespace App\Helix;

use PDO;

/**
 * Mock Helix adapter (DESIGN-DD §4). Backed by `mock_helix_tickets` so state
 * survives reloads and every client sees the same thing — closer to real Helix
 * than an in-memory array.
 *
 * NOTE ON SQL QUOTING: every string literal below uses single quotes.
 * PostgreSQL treats "double quoted" text as an *identifier* (a column name),
 * so `LIKE "HLX-%"` fails with `column "HLX-%" does not exist`.
 */
final class MockHelixClient implements HelixClientInterface
{
    /** The canonical queue order a new ticket walks through. */
    public const DEFAULT_QUEUES = [
        'Virtualization',
        'Linux Engineering',
        'Security Engineering',
        'Directory Services',
        'PKI / Certificate',
        'Network Security',
        'Service Desk',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * THE ONE WRITE (Golden Rule 2). Only OrderIntakeService::submit() calls this.
     *
     * @param array<string,mixed> $blob
     */
    public function createTicket(array $blob): string
    {
        $ticketId = $this->nextTicketId();
        $queueNames = $blob['queues'] ?? self::DEFAULT_QUEUES;

        $queues = [];
        foreach (array_values($queueNames) as $i => $name) {
            $queues[] = ['name' => $name, 'state' => $i === 0 ? 'ready' : 'pending'];
        }

        $history = [[
            'at' => gmdate('c'),
            'actor' => 'host-build-gateway',
            'event' => 'ticket.created',
            'queue' => $queues[0]['name'] ?? null,
        ]];

        $stmt = $this->pdo->prepare(
            'INSERT INTO mock_helix_tickets
                (id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at)
             VALUES (:id, :order_ref, :status, :current_queue, :queues, NULL, :history, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':id' => $ticketId,
            ':order_ref' => $blob['orderRef'],
            ':status' => 'received',
            ':current_queue' => $queues[0]['name'] ?? null,
            ':queues' => json_encode($queues),
            ':history' => json_encode($history),
        ]);

        return $ticketId;
    }

    /**
     * @param array<string,mixed> $filter
     * @return array<int,array<string,mixed>>
     */
    public function listTickets(array $filter = []): array
    {
        $sql = 'SELECT * FROM mock_helix_tickets';
        $params = [];

        if (!empty($filter['status'])) {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $filter['status'];
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            fn (array $row): array => $this->hydrate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /** @return array<string,mixed>|null */
    public function getTicket(string $ticketId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mock_helix_tickets WHERE id = :id');
        $stmt->execute([':id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Dev/demo-only progression standing in for work teams do IN Helix
     * (DESIGN-DD §4). Deliberately NOT on HelixClientInterface — the real
     * adapter has no such method. Reachable only via POST /api/dev/helix/advance,
     * which ApiController gates on app_env ∈ {local, demo}.
     */
    public function advance(string $ticketId): bool
    {
        $ticket = $this->getTicket($ticketId);
        if ($ticket === null) {
            return false;
        }

        $queues = $ticket['queues'];
        $history = $ticket['history'];

        // A blocker clears first — one advance unblocks, the next moves on.
        if (!empty($ticket['blocker'])) {
            foreach ($queues as $i => $q) {
                if ($q['state'] === 'blocked') {
                    $queues[$i]['state'] = 'in_progress';
                }
            }
            $history[] = [
                'at' => gmdate('c'),
                'actor' => $ticket['blocker']['team'] ?? 'team',
                'event' => 'blocker.cleared',
                'queue' => $ticket['currentQueue'],
            ];

            return $this->persist($ticketId, 'in_fulfillment', $ticket['currentQueue'], $queues, null, $history);
        }

        // Complete the current queue, open the next one.
        $idx = null;
        foreach ($queues as $i => $q) {
            if ($q['name'] === $ticket['currentQueue']) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return false;
        }

        $queues[$idx]['state'] = 'done';
        $history[] = [
            'at' => gmdate('c'),
            'actor' => $queues[$idx]['name'],
            'event' => 'queue.completed',
            'queue' => $queues[$idx]['name'],
        ];

        if ($idx + 1 >= count($queues)) {
            $history[] = ['at' => gmdate('c'), 'actor' => 'Service Desk', 'event' => 'ticket.delivered', 'queue' => $queues[$idx]['name']];

            return $this->persist($ticketId, 'delivered', $queues[$idx]['name'], $queues, null, $history);
        }

        $queues[$idx + 1]['state'] = 'in_progress';
        $next = $queues[$idx + 1]['name'];
        $history[] = ['at' => gmdate('c'), 'actor' => $next, 'event' => 'queue.entered', 'queue' => $next];

        return $this->persist($ticketId, 'in_fulfillment', $next, $queues, null, $history);
    }

    /**
     * @param array<int,array<string,mixed>> $queues
     * @param array<string,mixed>|null       $blocker
     * @param array<int,array<string,mixed>> $history
     */
    private function persist(string $id, string $status, ?string $queue, array $queues, ?array $blocker, array $history): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mock_helix_tickets
                SET status = :status,
                    current_queue = :queue,
                    queues_json = :queues,
                    blocker_json = :blocker,
                    history_json = :history
              WHERE id = :id'
        );

        return $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':queue' => $queue,
            ':queues' => json_encode($queues),
            ':blocker' => $blocker === null ? null : json_encode($blocker),
            ':history' => json_encode($history),
        ]);
    }

    /**
     * Row → the Ticket read shape of DESIGN-DD §5.1.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => $row['id'],
            'orderRef' => $row['order_ref'],
            'status' => $row['status'],
            'currentQueue' => $row['current_queue'],
            'queues' => json_decode((string) ($row['queues_json'] ?? '[]'), true) ?: [],
            'blocker' => $row['blocker_json'] ? json_decode((string) $row['blocker_json'], true) : null,
            'history' => json_decode((string) ($row['history_json'] ?? '[]'), true) ?: [],
            'createdAt' => $row['created_at'],
        ];
    }

    /** Allocates the next HLX-* id. Single-quoted LIKE literal — see class docblock. */
    private function nextTicketId(): string
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM mock_helix_tickets WHERE id LIKE 'HLX-%' ORDER BY id DESC LIMIT 1"
        );
        $last = $stmt ? $stmt->fetchColumn() : false;

        $n = 88000;
        if (is_string($last) && preg_match('/HLX-(\d+)/', $last, $m) === 1) {
            $n = (int) $m[1];
        }

        return sprintf('HLX-%d', $n + 1);
    }
}
