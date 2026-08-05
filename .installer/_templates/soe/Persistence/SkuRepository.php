<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;

/**
 * The `skus` table — the SKU catalog with lifecycle and conflict rules
 * (FR-CAT-01, FR-CAT-04).
 */
final class SkuRepository
{
    /** Display order and labels for SKU categories (FR-CAT-01). */
    public const CATEGORIES = [
        'os' => 'OS',
        'app' => 'App',
        'security' => 'Security',
        'identity' => 'Identity',
        'cert' => 'Cert',
        'firewall' => 'Firewall',
        'license' => 'License',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM skus ORDER BY category, name');

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** Stakeholder-facing SKUs the App Owner may actually pick (FR-CAT-02). */
    public function stakeholderSkus(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM skus WHERE policy_injected = FALSE AND lifecycle <> 'denied' ORDER BY name"
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** Policy-injected SKUs — locked, read-only, each with its rationale (FR-CAT-03). */
    public function policySkus(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM skus WHERE policy_injected = TRUE AND lifecycle <> 'denied' ORDER BY category, name"
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM skus WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<int,string> $ids
     * @return array<int,array<string,mixed>>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM skus WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
