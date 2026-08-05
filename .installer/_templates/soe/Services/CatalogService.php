<?php

declare(strict_types=1);

namespace App\Services;

use App\Persistence\AuditLog;
use App\Persistence\CatalogRepository;
use App\Persistence\SkuRepository;

/**
 * Catalog Builder logic (FR-CAT-01…08). Write ledger bucket 2 — app authoring.
 * Touches Helix not at all.
 */
final class CatalogService
{
    public function __construct(
        private SkuRepository $skus,
        private CatalogRepository $catalog,
        private AuditLog $audit,
    ) {
    }

    /**
     * SKUs grouped by category for display, with lifecycle surfaced (FR-CAT-01).
     *
     * @return array<int,array<string,mixed>>
     */
    public function groupedSkus(): array
    {
        $all = $this->skus->all();

        $groups = [];
        foreach (SkuRepository::CATEGORIES as $key => $label) {
            $items = array_values(array_filter($all, static fn (array $s): bool => $s['category'] === $key));
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'category' => $key,
                'category_label' => $label,
                'skus' => array_map(static fn (array $s): array => [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'lifecycle' => $s['lifecycle'],
                    'lifecycle_note' => $s['lifecycle_note'],
                    'has_warning' => in_array($s['lifecycle'], ['sunset', 'denied'], true),
                    'is_denied' => $s['lifecycle'] === 'denied',
                    'rationale' => $s['rationale'],
                    'policy_injected' => (bool) $s['policy_injected'],
                ], $items),
            ];
        }

        return $groups;
    }

    /**
     * The PHP module stream choice — single-select (FR-CAT-02).
     *
     * @return array<int,array<string,mixed>>
     */
    public function phpStreamChoices(string $selected): array
    {
        $out = [];
        foreach (['php8.2', 'php8.1', 'php8.0'] as $id) {
            $sku = $this->skus->find($id);
            if ($sku === null) {
                continue;
            }
            $out[] = [
                'id' => $sku['id'],
                'name' => $sku['name'],
                'selected' => $sku['id'] === $selected,
                'warning' => $sku['lifecycle_note'],
                'has_warning' => !empty($sku['lifecycle_note']),
            ];
        }

        return $out;
    }

    /**
     * Web/database components — multi-select (FR-CAT-02).
     *
     * @param array<int,string> $selected
     * @return array<int,array<string,mixed>>
     */
    public function componentChoices(array $selected): array
    {
        $out = [];
        foreach (['httpd', 'mariadb'] as $id) {
            $sku = $this->skus->find($id);
            if ($sku === null) {
                continue;
            }
            $out[] = [
                'id' => $sku['id'],
                'name' => $sku['name'],
                'selected' => in_array($id, $selected, true),
            ];
        }

        return $out;
    }

    /**
     * Policy-injected SKUs, locked with rationale (FR-CAT-03).
     *
     * @return array<int,array<string,mixed>>
     */
    public function policySkus(): array
    {
        return array_map(static fn (array $s): array => [
            'id' => $s['id'],
            'name' => $s['name'],
            'rationale' => $s['rationale'],
            'category_label' => SkuRepository::CATEGORIES[$s['category']] ?? $s['category'],
        ], $this->skus->policySkus());
    }

    /** Auto-generated, editable catalog item name (FR-CAT-05). */
    public function suggestName(string $phpStream): string
    {
        return 'LAMP-' . strtoupper(str_replace('php', 'PHP', $phpStream)) . '-RHEL9';
    }

    /**
     * Compose-time lifecycle and conflict enforcement (FR-CAT-04).
     *
     * @param array<int,string> $skuIds
     * @return array<int,string> Errors; empty means the composition is legal.
     */
    public function validateComposition(array $skuIds): array
    {
        $errors = [];
        $selected = $this->skus->findMany($skuIds);
        $byId = [];
        foreach ($selected as $s) {
            $byId[$s['id']] = $s;
        }

        foreach ($selected as $sku) {
            // Deprecated SKUs are blocked with their successor named.
            if ($sku['lifecycle'] === 'denied') {
                $successor = $sku['successor_id']
                    ? ($this->skus->find((string) $sku['successor_id'])['name'] ?? $sku['successor_id'])
                    : 'a supported alternative';
                $errors[] = sprintf('%s is out of support — use %s instead.', $sku['name'], $successor);
            }

            // Mutually exclusive SKUs may not coexist.
            if (!empty($sku['conflicts_with'])) {
                foreach (explode(',', (string) $sku['conflicts_with']) as $conflictId) {
                    $conflictId = trim($conflictId);
                    if ($conflictId !== '' && isset($byId[$conflictId])) {
                        $errors[] = sprintf('%s cannot be combined with %s.', $sku['name'], $byId[$conflictId]['name']);
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Publish a catalog item at v1 (FR-CAT-06).
     *
     * @param array<int,string> $componentIds
     * @return array{ok:bool,errors:array<int,string>,name:string}
     */
    public function publish(string $name, string $phpStream, array $componentIds): array
    {
        $name = trim($name) !== '' ? trim($name) : $this->suggestName($phpStream);
        $stakeholder = array_merge([$phpStream], $componentIds);

        $errors = $this->validateComposition($stakeholder);

        // FR-CAT-05: uniqueness validation.
        if ($this->catalog->nameExists($name)) {
            $errors[] = sprintf('A catalog item named "%s" already exists.', $name);
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'name' => $name];
        }

        $phpSku = $this->skus->find($phpStream);
        $solution = 'LAMP Stack (' . ($phpSku ? explode(' (', (string) $phpSku['name'])[0] : $phpStream) . ')';
        $policyIds = array_column($this->skus->policySkus(), 'id');

        $this->catalog->publish($name, $solution, $stakeholder, $policyIds, '4 vCPU · 16 GB · 200 GB');
        $this->audit->record('catalog.published', $name, $solution);

        return ['ok' => true, 'errors' => [], 'name' => $name];
    }

    /**
     * Published items for display (FR-CAT-07).
     *
     * @return array<int,array<string,mixed>>
     */
    public function publishedItems(): array
    {
        return array_map(static fn (array $item): array => [
            'name' => $item['name'],
            'version' => $item['version'],
            'solution' => $item['solution'],
            'sizing' => $item['sizing'],
            'stakeholder_skus' => array_map(
                static fn (string $id): array => ['id' => $id],
                $item['stakeholder_skus']
            ),
            'policy_count' => count($item['policy_skus']),
        ], $this->catalog->published());
    }
}
