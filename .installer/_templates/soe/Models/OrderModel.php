<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Order persistence: CRUD for orders table.
 * Follows DemoModel's plain PDO wrapper style.
 */
final class OrderModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check database connectivity.
     */
    public function isConnected(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Create a new order from a submission.
     */
    public function createOrder(array $data, string $helixTicketRef): string
    {
        $orderRef = $this->generateOrderRef();

        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (order_ref, app_name, environment, sensitivity, expected_users, need_by, requirements_record, helix_ticket_ref, state, created_at)
            VALUES (:order_ref, :app_name, :environment, :sensitivity, :expected_users, :need_by, :requirements_record, :helix_ticket_ref, :state, NOW())'
        );

        $stmt->execute([
            ':order_ref' => $orderRef,
            ':app_name' => $data['app_name'] ?? '',
            ':environment' => $data['environment'] ?? 'dev',
            ':sensitivity' => $data['sensitivity'] ?? 'internal',
            ':expected_users' => (int) ($data['expected_users'] ?? 1),
            ':need_by' => $data['need_by'] ?? null,
            ':requirements_record' => $data['context'] ?? '',
            ':helix_ticket_ref' => $helixTicketRef,
            ':state' => 'submitted'
        ]);

        return $orderRef;
    }

    /**
     * Retrieve a single order by its order_ref.
     */
    public function getOrder(string $orderRef): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders WHERE order_ref = :order_ref LIMIT 1'
        );

        $stmt->execute([':order_ref' => $orderRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * List all orders, newest first.
     */
    public function getAllOrders(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM orders ORDER BY created_at DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Generate a unique order reference like ORD-1045.
     */
    private function generateOrderRef(): string
    {
        $maxNum = 1000;
        $stmt = $this->pdo->query(
            'SELECT order_ref FROM orders WHERE order_ref LIKE "ORD-%" ORDER BY order_ref DESC LIMIT 1'
        );
        $lastRef = $stmt->fetchColumn();

        if ($lastRef && preg_match('/ORD-(\d+)/', $lastRef, $m)) {
            $maxNum = (int) $m[1];
        }

        return sprintf('ORD-%d', $maxNum + 1);
    }
}
