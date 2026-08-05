<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\OrderProjection;
use App\Domain\Persona;
use App\Domain\Serializer\CustomerSerializer;

/**
 * Order Tracker — read-only, open to every persona (spec §5.3, FR-TRK-01…08).
 *
 * FR-TRK-08: every value shown here is a projection of a Helix ticket read.
 * This controller performs NO writes of any kind — not to Helix, not to the
 * order record. If a feature request needs a write on this surface, it belongs
 * somewhere else.
 *
 * FR-TRK-05: the customer payload goes through CustomerSerializer so SKU
 * fields are structurally absent rather than merely unrendered.
 */
final class TrackerController extends SoeController
{
    public function index(): void
    {
        if (!$this->guard(Persona::ALL_PERSONAS, '/tracker')) {
            return;
        }
        if (!$this->dbAvailable()) {
            echo $this->render('protected/no-database', $this->baseData('/tracker'));

            return;
        }

        $views = $this->projectAll();

        echo $this->render('tracker/index', array_merge($this->baseData('/tracker'), [
            'orders' => $views,
            'has_orders' => $views !== [],
        ]));
    }

    public function detail(string $ref): void
    {
        if (!$this->guard(Persona::ALL_PERSONAS, '/tracker')) {
            return;
        }
        if (!$this->dbAvailable()) {
            echo $this->render('protected/no-database', $this->baseData('/tracker'));

            return;
        }

        $view = $this->projectOne($ref);
        if ($view === null) {
            http_response_code(404);
            echo $this->render('protected/not-found', array_merge($this->baseData('/tracker'), [
                'ref' => $ref,
            ]));

            return;
        }

        echo $this->render('tracker/detail', array_merge($this->baseData('/tracker'), [
            'order' => $view,
            'switcher' => $this->switcher($ref),
            // The "simulate next step" control is demo-only (DESIGN-DD §4).
            'can_advance' => $this->isDemoEnv() && !$view['is_delivered'],
        ]));
    }

    /**
     * Order switcher entries (FR-TRK-01) — app, environment, state chip.
     *
     * @return array<int,array<string,mixed>>
     */
    private function switcher(string $currentRef): array
    {
        return array_map(
            static fn (array $v): array => [
                'order_ref' => $v['order_ref'],
                'app_name' => $v['app_name'],
                'environment' => $v['environment'],
                'status' => $v['status'],
                'status_label' => $v['is_blocked'] ? 'Blocked' : ($v['is_delivered'] ? 'Delivered' : 'In progress'),
                'is_current' => $v['order_ref'] === $currentRef,
                'is_blocked' => $v['is_blocked'],
                'is_delivered' => $v['is_delivered'],
            ],
            $this->projectAll()
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function projectAll(): array
    {
        $projection = new OrderProjection();
        $serializer = new CustomerSerializer();

        $out = [];
        foreach ($this->orders->all() as $order) {
            $ref = $order['helix_ticket_ref'] ?? null;
            if (!is_string($ref)) {
                continue;
            }

            $ticket = $this->helix->getTicket($ref);
            if ($ticket === null) {
                continue;
            }

            $out[] = $serializer->serializeOrder($projection->project($ticket, $order));
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function projectOne(string $orderRef): ?array
    {
        $order = $this->orders->findByRef($orderRef);
        if ($order === null || !is_string($order['helix_ticket_ref'] ?? null)) {
            return null;
        }

        $ticket = $this->helix->getTicket((string) $order['helix_ticket_ref']);
        if ($ticket === null) {
            return null;
        }

        return (new CustomerSerializer())->serializeOrder(
            (new OrderProjection())->project($ticket, $order)
        );
    }
}
