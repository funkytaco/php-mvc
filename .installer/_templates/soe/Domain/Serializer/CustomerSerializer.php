<?php

declare(strict_types=1);

namespace App\Domain\Serializer;

/**
 * Customer-facing serialization (Golden Rule 7, FR-CAT-08, FR-TRK-05, NFR-04).
 *
 * Customers must NEVER receive SKU fields. This is enforced structurally, by
 * building an allow-listed payload here, rather than by hiding fields in a
 * template. A field absent from ALLOWED_ORDER_FIELDS cannot reach a customer
 * even if a view or a future endpoint asks for it.
 *
 * Deny-listing was deliberately not used: a new SKU-bearing column would leak
 * by default. With an allow-list, new fields are invisible until someone adds
 * them here on purpose.
 */
final class CustomerSerializer
{
    /**
     * Everything a customer may see about an order. Note the absence of
     * `catalog_item`, `stakeholder_skus`, `policy_skus` and anything else
     * that names a component.
     */
    private const ALLOWED_ORDER_FIELDS = [
        'order_ref',
        'app_name',
        'environment',
        'sensitivity',
        'expected_users',
        'need_by',
        'requirements_record',
        'resolved_profile',
        'current_owner',
        'status',
        'status_label',
        'is_blocked',
        'is_delivered',
        'stages',
        'show_lanes',
        'lanes',
        'blocker',
        'elapsed',
        'created_at',
    ];

    /** Substrings that must never appear as a key in a customer payload. */
    private const FORBIDDEN_KEY_FRAGMENTS = ['sku', 'catalog_item', 'entitlement'];

    /**
     * @param array<string,mixed> $orderView An OrderProjection result.
     * @return array<string,mixed>
     */
    public function serializeOrder(array $orderView): array
    {
        $out = [];
        foreach (self::ALLOWED_ORDER_FIELDS as $field) {
            if (array_key_exists($field, $orderView)) {
                $out[$field] = $orderView[$field];
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $orderViews
     * @return array<int,array<string,mixed>>
     */
    public function serializeOrderList(array $orderViews): array
    {
        return array_map(fn (array $v): array => $this->serializeOrder($v), $orderViews);
    }

    /**
     * Self-check used by the role-scoping invariant test: true when a payload
     * carries no SKU-ish key at any depth.
     *
     * @param array<string,mixed> $payload
     */
    public static function isCustomerSafe(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                if (str_contains($lower, $fragment)) {
                    return false;
                }
            }
            if (is_array($value) && !self::isCustomerSafe($value)) {
                return false;
            }
        }

        return true;
    }
}
