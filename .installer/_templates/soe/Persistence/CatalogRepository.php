<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;

/**
 * The `catalog_items`, `environment_profiles`, `frameworks` and `templates`
 * tables — everything the App Owner and governance author.
 */
final class CatalogRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Published catalog items with their SKU lists decoded (FR-CAT-07).
     *
     * @return array<int,array<string,mixed>>
     */
    public function published(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM catalog_items WHERE published = TRUE ORDER BY created_at DESC'
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        return array_map(
            static function (array $r): array {
                $r['stakeholder_skus'] = json_decode((string) $r['stakeholder_skus'], true) ?: [];
                $r['policy_skus'] = json_decode((string) $r['policy_skus'], true) ?: [];

                return $r;
            },
            $rows
        );
    }

    public function nameExists(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM catalog_items WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Publish a catalog item at v1 (FR-CAT-06).
     *
     * @param array<int,string> $stakeholderSkus
     * @param array<int,string> $policySkus
     */
    public function publish(string $name, string $solution, array $stakeholderSkus, array $policySkus, string $sizing): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO catalog_items (name, version, solution, sizing, stakeholder_skus, policy_skus, published)
             VALUES (:name, :version, :solution, :sizing, :stake, :policy, TRUE)'
        );

        $stmt->execute([
            ':name' => $name,
            ':version' => 'v1',
            ':solution' => $solution,
            ':sizing' => $sizing,
            ':stake' => json_encode(array_values($stakeholderSkus)),
            ':policy' => json_encode(array_values($policySkus)),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function environmentProfiles(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM environment_profiles ORDER BY name');

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Resolve the environment profile for a sensitivity (FR-ORD-03).
     *
     * @return array<string,mixed>|null
     */
    public function profileForSensitivity(string $sensitivity): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM environment_profiles WHERE LOWER(sensitivity) = LOWER(:s) ORDER BY version DESC LIMIT 1'
        );
        $stmt->execute([':s' => $sensitivity]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function templates(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM templates ORDER BY name, version DESC');

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Find a certified template for a catalog item (FR-ORD-03, FR-ORD-07).
     *
     * @return array<string,mixed>|null
     */
    public function certifiedTemplateFor(string $catalogItemName): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM templates
              WHERE catalog_item_name = :name AND status = 'certified'
              ORDER BY version DESC LIMIT 1"
        );
        $stmt->execute([':name' => $catalogItemName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
