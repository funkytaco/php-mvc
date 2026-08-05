<?php

declare(strict_types=1);

namespace App\Domain\Serializer;

/**
 * App Owner / Requester serialization.
 *
 * These personas legitimately see SKUs — the App Owner composes them, the
 * Requester's intake resolves them. This serializer is the counterpart to
 * CustomerSerializer and exists so the choice of "who sees SKUs" is made once,
 * explicitly, at the API seam rather than scattered through views.
 */
final class OwnerSerializer
{
    /**
     * @param array<string,mixed> $catalogItem
     * @return array<string,mixed>
     */
    public function serializeCatalogItem(array $catalogItem): array
    {
        return [
            'name' => $catalogItem['name'] ?? '',
            'version' => $catalogItem['version'] ?? 'v1',
            'solution' => $catalogItem['solution'] ?? '',
            'sizing' => $catalogItem['sizing'] ?? '',
            'stakeholder_skus' => $catalogItem['stakeholder_skus'] ?? [],
            'policy_skus' => $catalogItem['policy_skus'] ?? [],
            'policy_count' => is_array($catalogItem['policy_skus'] ?? null)
                ? count($catalogItem['policy_skus'])
                : 0,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function serializeCatalog(array $items): array
    {
        return array_map(fn (array $i): array => $this->serializeCatalogItem($i), $items);
    }
}
