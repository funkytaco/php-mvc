<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;

/**
 * The `orders` table — app-owned intent plus the single join to Helix
 * (`helix_ticket_ref`). Everything about an order's *progress* is derived at
 * read time from the Helix ticket, never stored here (Golden Rule 3).
 */
final class OrderRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM orders ORDER BY created_at DESC');

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,mixed>|null */
    public function findByRef(string $orderRef): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE order_ref = :ref LIMIT 1');
        $stmt->execute([':ref' => $orderRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Idempotency support (DESIGN-DD §8.1): a retry with the same key returns
     * the existing order rather than creating a second Helix ticket.
     *
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE idempotency_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Allocates the next ORD-* reference.
     *
     * Called BEFORE the Helix ticket is created, because the TicketBlob must
     * carry orderRef (DESIGN-DD §5.1) — the ticket cannot be created first and
     * back-filled.
     *
     * Single-quoted LIKE literal: PostgreSQL parses "double quotes" as an
     * identifier, so a double-quoted pattern raises `column ... does not exist`.
     */
    public function nextOrderRef(): string
    {
        $stmt = $this->pdo->query(
            "SELECT order_ref FROM orders WHERE order_ref LIKE 'ORD-%' ORDER BY order_ref DESC LIMIT 1"
        );
        $last = $stmt ? $stmt->fetchColumn() : false;

        $n = 1000;
        if (is_string($last) && preg_match('/ORD-(\d+)/', $last, $m) === 1) {
            $n = (int) $m[1];
        }

        return sprintf('ORD-%d', $n + 1);
    }

    /**
     * @param array<string,mixed> $order
     */
    public function insert(array $order): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders
                (order_ref, app_name, catalog_item, environment, sensitivity, expected_users,
                 need_by, requirements_record, resolved_profile, frameworks, approvals,
                 helix_ticket_ref, state, idempotency_key, created_at)
             VALUES
                (:order_ref, :app_name, :catalog_item, :environment, :sensitivity, :expected_users,
                 :need_by, :requirements_record, :resolved_profile, :frameworks, :approvals,
                 :helix_ticket_ref, :state, :idempotency_key, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            ':order_ref' => $order['order_ref'],
            ':app_name' => $order['app_name'],
            ':catalog_item' => $order['catalog_item'] ?? null,
            ':environment' => $order['environment'],
            ':sensitivity' => $order['sensitivity'],
            ':expected_users' => (int) ($order['expected_users'] ?? 1),
            ':need_by' => $order['need_by'] ?: null,
            ':requirements_record' => $order['requirements_record'] ?? '',
            ':resolved_profile' => $order['resolved_profile'] ?? null,
            ':frameworks' => json_encode($order['frameworks'] ?? []),
            ':approvals' => json_encode($order['approvals'] ?? []),
            ':helix_ticket_ref' => $order['helix_ticket_ref'] ?? null,
            ':state' => $order['state'] ?? 'submitted',
            ':idempotency_key' => $order['idempotency_key'] ?? null,
        ]);
    }
}
