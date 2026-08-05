<?php

declare(strict_types=1);

namespace Test\Templates\Soe;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SOE projection and customer serializer (DESIGN-DD §13.1).
 *
 * OrderProjection is pure and dependency-free by design, so it can be required
 * directly and exercised without a database, a container, or the framework.
 * That purity is the property being protected here as much as the mapping.
 */
final class OrderProjectionTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../.installer/_templates/soe';

    public static function setUpBeforeClass(): void
    {
        require_once self::TEMPLATE . '/Helix/QueueMap.php';
        require_once self::TEMPLATE . '/Domain/OrderProjection.php';
        require_once self::TEMPLATE . '/Domain/Serializer/CustomerSerializer.php';
    }

    /** A blocked ticket: the active stage is blocked and the blocker carries an age. */
    public function testBlockedTicketProjectsBlockerWithAge(): void
    {
        $now = strtotime('2026-08-05T12:00:00Z');

        $view = (new \App\Domain\OrderProjection())->project(
            $this->ticket([
                'status' => 'blocked',
                'currentQueue' => 'Network Security',
                'blocker' => [
                    'team' => 'Network Security',
                    'reason' => 'Awaiting Firewall Change Approval.',
                    'since' => '2026-08-05T03:00:00Z',
                ],
            ]),
            $this->order(),
            $now
        );

        $this->assertSame('blocked', $view['status']);
        $this->assertTrue($view['is_blocked']);
        $this->assertSame('Network Security', $view['blocker']['team']);
        $this->assertSame('9h', $view['blocker']['age'], 'Blocker age is 03:00Z → 12:00Z.');
        $this->assertSame('Multiple teams', $view['current_owner']);

        $states = array_column($view['stages'], 'state', 'key');
        $this->assertSame('blocked', $states['buildout']);
        $this->assertSame('done', $states['received']);
        $this->assertSame('pending', $states['handover']);
    }

    /** Build-out shows its parallel lanes; other stages do not. */
    public function testLanesAppearOnlyDuringBuildout(): void
    {
        $projection = new \App\Domain\OrderProjection();

        $buildout = $projection->project(
            $this->ticket(['currentQueue' => 'Network Security', 'status' => 'in_fulfillment']),
            $this->order()
        );
        $this->assertTrue($buildout['show_lanes']);
        $this->assertCount(6, $buildout['lanes'], 'Six build-out lanes per FR-TRK-03.');

        $early = $projection->project(
            $this->ticket(['currentQueue' => 'Virtualization', 'status' => 'received']),
            $this->order()
        );
        $this->assertFalse($early['show_lanes']);
        $this->assertSame([], $early['lanes']);
    }

    /**
     * FR-TASK-06: host and network firewall are distinct lanes with distinct
     * owners and must never be collapsed into one.
     */
    public function testHostAndNetworkFirewallRemainSeparateLanes(): void
    {
        $view = (new \App\Domain\OrderProjection())->project(
            $this->ticket(['currentQueue' => 'Network Security', 'status' => 'in_fulfillment']),
            $this->order()
        );

        $firewallLanes = array_values(array_filter(
            $view['lanes'],
            static fn (array $l): bool => str_contains(strtolower($l['label']), 'firewall')
        ));

        $this->assertCount(2, $firewallLanes);
        $this->assertNotSame(
            $firewallLanes[0]['team'],
            $firewallLanes[1]['team'],
            'Host and network firewall must have different owning teams.'
        );
    }

    /** A delivered ticket completes every stage and reports no blocker. */
    public function testDeliveredTicketCompletesEveryStage(): void
    {
        $view = (new \App\Domain\OrderProjection())->project(
            $this->ticket(['status' => 'delivered', 'currentQueue' => 'Service Desk']),
            $this->order()
        );

        $this->assertTrue($view['is_delivered']);
        $this->assertNull($view['blocker']);
        foreach ($view['stages'] as $stage) {
            $this->assertSame('done', $stage['state'], $stage['key'] . ' should be done.');
        }
    }

    /** Elapsed is display-only, and formatted like the reference prototype. */
    public function testElapsedFormatting(): void
    {
        $projection = new \App\Domain\OrderProjection();
        $created = '2026-08-01T00:00:00Z';

        $cases = [
            '2026-08-01T00:30:00Z' => '30m',
            '2026-08-01T06:00:00Z' => '6h',
            '2026-08-03T06:00:00Z' => '2d 6h',
        ];

        foreach ($cases as $now => $expected) {
            $view = $projection->project(
                $this->ticket(['createdAt' => $created]),
                $this->order(),
                strtotime($now)
            );
            $this->assertSame($expected, $view['elapsed'], "elapsed at $now");
        }
    }

    /** Projection is pure: identical inputs, identical output, no drift. */
    public function testProjectionIsDeterministic(): void
    {
        $projection = new \App\Domain\OrderProjection();
        $now = strtotime('2026-08-05T12:00:00Z');

        $a = $projection->project($this->ticket(), $this->order(), $now);
        $b = $projection->project($this->ticket(), $this->order(), $now);

        $this->assertSame($a, $b);
    }

    /**
     * GOLDEN RULE 7 — a customer payload must carry no SKU field. This is the
     * structural check: the serializer allow-lists, so `catalog_item` cannot
     * survive even though the projection had access to it.
     */
    public function testCustomerSerializerOmitsSkuFields(): void
    {
        $view = (new \App\Domain\OrderProjection())->project($this->ticket(), $this->order());

        // The projection input deliberately carries SKU-ish data.
        $view['catalog_item'] = 'LAMP-PHP8-RHEL9';
        $view['stakeholder_skus'] = ['php8.0', 'httpd'];

        $payload = (new \App\Domain\Serializer\CustomerSerializer())->serializeOrder($view);

        $this->assertArrayNotHasKey('catalog_item', $payload);
        $this->assertArrayNotHasKey('stakeholder_skus', $payload);
        $this->assertTrue(
            \App\Domain\Serializer\CustomerSerializer::isCustomerSafe($payload),
            'Customer payload contains a SKU-ish key at some depth.'
        );

        // ...while still carrying what a customer legitimately needs.
        $this->assertSame('ORD-1042', $payload['order_ref']);
        $this->assertArrayHasKey('stages', $payload);
        $this->assertArrayHasKey('requirements_record', $payload);
    }

    /**
     * GOLDEN RULE 7 — build-out lane labels must name no product.
     *
     * The lanes render on the Tracker, which is a customer surface. The
     * reference prototype labelled them with product names ("Security agent ·
     * Tripwire"), which would tell a customer exactly which security,
     * identity and app SKUs the build uses. FR-TRK-03 names the lanes
     * generically and the spec wins on scope.
     */
    public function testBuildoutLaneLabelsNameNoSku(): void
    {
        $view = (new \App\Domain\OrderProjection())->project(
            $this->ticket(['currentQueue' => 'Network Security', 'status' => 'in_fulfillment']),
            $this->order()
        );

        $products = ['tripwire', 'delinea', 'mariadb', 'httpd', 'apache', 'rhel', 'php'];

        foreach ($view['lanes'] as $lane) {
            foreach ($products as $product) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $product,
                    $lane['label'],
                    "Lane label \"{$lane['label']}\" names the product \"$product\"; "
                    . 'the Tracker is a customer surface and must not disclose SKUs.'
                );
            }
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function ticket(array $overrides = []): array
    {
        return array_merge([
            'id' => 'HLX-88231',
            'orderRef' => 'ORD-1042',
            'status' => 'in_fulfillment',
            'currentQueue' => 'Network Security',
            'queues' => [
                ['name' => 'Virtualization', 'state' => 'done'],
                ['name' => 'Linux Engineering', 'state' => 'done'],
                ['name' => 'Security Engineering', 'state' => 'in_progress'],
                ['name' => 'Directory Services', 'state' => 'done'],
                ['name' => 'PKI / Certificate', 'state' => 'done'],
                ['name' => 'Network Security', 'state' => 'blocked'],
                ['name' => 'Service Desk', 'state' => 'pending'],
            ],
            'blocker' => null,
            'history' => [],
            'createdAt' => '2026-08-03T06:00:00Z',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function order(): array
    {
        return [
            'order_ref' => 'ORD-1042',
            'app_name' => 'Benefits Portal',
            'environment' => 'Production',
            'sensitivity' => 'Moderate',
            'expected_users' => 200,
            'need_by' => '2026-08-15',
            'requirements_record' => 'LAMP stack with PHP 8 for the Benefits Portal.',
            'resolved_profile' => 'DC-East-Moderate v5',
        ];
    }
}
