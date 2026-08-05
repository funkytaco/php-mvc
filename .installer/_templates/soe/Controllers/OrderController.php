<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Persona;

/**
 * Order Gateway — Requester (spec §5.2, FR-ORD-01…10).
 *
 * A short solutioning conversation, not a shopping form. FR-ORD-02: this
 * surface must NEVER ask about OS versions, security tooling, certificates,
 * firewalls, or datacenters — policy resolves all of those. If you find
 * yourself adding such a field, that is a spec change, not a UI tweak.
 *
 * The actual Helix write lives in OrderIntakeService::submit(), not here.
 */
final class OrderController extends SoeController
{
    public function index(): void
    {
        if (!$this->guard(Persona::REQUESTER, '/order')) {
            return;
        }
        if (!$this->dbAvailable()) {
            echo $this->render('protected/no-database', $this->baseData('/order'));

            return;
        }

        $items = $this->catalog->publishedItems();

        echo $this->render('order/index', array_merge($this->baseData('/order'), [
            'catalog_items' => $items,
            'has_catalog' => $items !== [],
            'environments' => $this->options(['Production', 'Test', 'Development']),
            'sensitivities' => $this->options(['Moderate', 'Low', 'High']),
            'errors' => array_map(
                static fn (string $e): array => ['message' => $e],
                $_SESSION['order_errors'] ?? []
            ),
            'has_errors' => !empty($_SESSION['order_errors']),
            'old' => $_SESSION['order_old'] ?? null,
        ]));

        unset($_SESSION['order_errors'], $_SESSION['order_old']);
    }

    /**
     * Submit the order (FR-ORD-09/10). This is the request that ultimately
     * produces the platform's single outbound Helix write.
     */
    public function submit(): void
    {
        if (!$this->guard(Persona::REQUESTER, '/order')) {
            return;
        }
        if (!$this->dbAvailable()) {
            $_SESSION['order_errors'] = ['Database unavailable — the order was not submitted.'];
            $this->redirect('/order');

            return;
        }

        $data = $this->getRequestData();

        $errors = $this->intake->validateIntake($data);
        if ($errors !== []) {
            $_SESSION['order_errors'] = $errors;
            $_SESSION['order_old'] = $data;
            $this->redirect('/order');

            return;
        }

        try {
            // Idempotency key from the form's hidden field, so a double-submit
            // or a browser retry cannot create two Helix tickets.
            $key = (string) ($data['idempotency_key'] ?? '');
            $result = $this->intake->submit($data, $key !== '' ? $key : null);

            $this->redirect('/tracker/' . $result['order_ref']);
        } catch (\Throwable $e) {
            error_log('Order submission failed: ' . $e->getMessage());
            $_SESSION['order_errors'] = ['Order submission failed: ' . $e->getMessage()];
            $_SESSION['order_old'] = $data;
            $this->redirect('/order');
        }
    }

    /**
     * @param array<int,string> $values
     * @return array<int,array<string,string>>
     */
    private function options(array $values): array
    {
        return array_map(static fn (string $v): array => ['value' => $v, 'label' => $v], $values);
    }
}
