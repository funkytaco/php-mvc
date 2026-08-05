<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Mock Helix ticket client backed by MariaDB.
 * Seeded with demo tickets; supports progression for dev/demo (advance method).
 * Matches DESIGN-DD.md §4 exactly.
 */
final class MockHelixClient implements HelixClientInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new ticket from an order submission.
     * Generates a HLX-* ID and stores it in mock_helix_tickets.
     */
    public function createTicket(array $blob): array
    {
        $ticketId = $this->generateTicketId();

        $queues = $blob['queues'] ?? [
            'Virtualization',
            'Linux Engineering',
            'Security Engineering',
            'Directory Services',
            'PKI / Certificate',
            'Network Security',
            'Service Desk'
        ];

        // Initialize each queue with 'pending' state
        $queuesJson = json_encode(
            array_map(fn($q) => ['name' => $q, 'state' => 'pending'], $queues)
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO mock_helix_tickets (id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at)
            VALUES (:id, :order_ref, :status, :current_queue, :queues_json, :blocker_json, :history_json, NOW())'
        );

        $stmt->execute([
            ':id' => $ticketId,
            ':order_ref' => $blob['orderRef'] ?? null,
            ':status' => 'received',
            ':current_queue' => $queues[0] ?? null,
            ':queues_json' => $queuesJson,
            ':blocker_json' => null,
            ':history_json' => json_encode([
                [
                    'at' => date('c'),
                    'actor' => 'system',
                    'event' => 'created',
                    'details' => 'Order submitted'
                ]
            ])
        ]);

        return ['id' => $ticketId];
    }

    /**
     * List all tickets, ordered by creation date descending.
     */
    public function listTickets(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at
            FROM mock_helix_tickets
            ORDER BY created_at DESC'
        );

        $tickets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tickets[] = $this->parseTicketRow($row);
        }

        return $tickets;
    }

    /**
     * Retrieve a single ticket by ID.
     */
    public function getTicket(string $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, order_ref, status, current_queue, queues_json, blocker_json, history_json, created_at
            FROM mock_helix_tickets
            WHERE id = :id'
        );

        $stmt->execute([':id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \Exception("Ticket not found: {$ticketId}");
        }

        return $this->parseTicketRow($row);
    }

    /**
     * Dev-only progression: move ticket to next queue, flip states.
     * Not part of the HelixClientInterface (real Helix client has no such method).
     */
    public function advance(string $ticketId): void
    {
        $ticket = $this->getTicket($ticketId);

        $queues = $ticket['queues'] ?? [];
        $currentQueueIdx = array_search($ticket['currentQueue'], array_column($queues, 'name'), true);

        if ($currentQueueIdx === false || $currentQueueIdx >= count($queues) - 1) {
            // Already at last queue; mark as delivered
            $stmt = $this->pdo->prepare(
                'UPDATE mock_helix_tickets SET status = :status, current_queue = :queue WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $ticketId,
                ':status' => 'delivered',
                ':queue' => $queues[count($queues) - 1]['name'] ?? null
            ]);
            return;
        }

        // Mark current queue as done, move to next
        foreach ($queues as &$q) {
            if ($q['name'] === $ticket['currentQueue']) {
                $q['state'] = 'done';
            }
        }
        $queues[$currentQueueIdx + 1]['state'] = 'in_progress';

        $nextQueueName = $queues[$currentQueueIdx + 1]['name'] ?? null;

        $stmt = $this->pdo->prepare(
            'UPDATE mock_helix_tickets
            SET status = :status, current_queue = :queue, queues_json = :queues_json, blocker_json = :blocker_json
            WHERE id = :id'
        );

        $stmt->execute([
            ':id' => $ticketId,
            ':status' => 'in_fulfillment',
            ':queue' => $nextQueueName,
            ':queues_json' => json_encode($queues),
            ':blocker_json' => null
        ]);
    }

    /**
     * Generate a unique ticket ID like HLX-88231.
     */
    private function generateTicketId(): string
    {
        $maxNum = 0;
        $stmt = $this->pdo->query(
            'SELECT id FROM mock_helix_tickets WHERE id LIKE "HLX-%" ORDER BY id DESC LIMIT 1'
        );
        $lastId = $stmt->fetchColumn();

        if ($lastId && preg_match('/HLX-(\d+)/', $lastId, $m)) {
            $maxNum = (int) $m[1];
        }

        return sprintf('HLX-%d', $maxNum + 1);
    }

    /**
     * Parse a database row into the Helix ticket shape.
     */
    private function parseTicketRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'orderRef' => $row['order_ref'],
            'status' => $row['status'],
            'currentQueue' => $row['current_queue'],
            'queues' => json_decode($row['queues_json'] ?? '[]', true) ?? [],
            'blocker' => $row['blocker_json'] ? json_decode($row['blocker_json'], true) : null,
            'history' => json_decode($row['history_json'] ?? '[]', true) ?? [],
            'createdAt' => $row['created_at']
        ];
    }
}
