<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Persona;

/**
 * Catalog Builder — App Owner (spec §5.1, FR-CAT-01…08).
 *
 * Composes a Solution Catalog item from stakeholder-facing SKUs. Policy-injected
 * components are rendered locked and are re-derived server-side at publish time,
 * so a tampered form cannot drop them (FR-CAT-03).
 */
final class CatalogController extends SoeController
{
    public function index(): void
    {
        if (!$this->guard(Persona::APP_OWNER, '/catalog')) {
            return;
        }
        if (!$this->dbAvailable()) {
            echo $this->render('protected/no-database', $this->baseData('/catalog'));

            return;
        }

        $phpStream = (string) ($_SESSION['catalog_php'] ?? 'php8.2');
        $components = $_SESSION['catalog_components'] ?? ['httpd', 'mariadb'];

        echo $this->render('catalog/index', array_merge($this->baseData('/catalog'), [
            'php_choices' => $this->catalog->phpStreamChoices($phpStream),
            'component_choices' => $this->catalog->componentChoices(is_array($components) ? $components : []),
            'policy_skus' => $this->catalog->policySkus(),
            'suggested_name' => $this->catalog->suggestName($phpStream),
            'published' => $this->catalog->publishedItems(),
            'errors' => array_map(
                static fn (string $e): array => ['message' => $e],
                $_SESSION['catalog_errors'] ?? []
            ),
            'has_errors' => !empty($_SESSION['catalog_errors']),
            'flash' => $_SESSION['catalog_flash'] ?? null,
        ]));

        unset($_SESSION['catalog_errors'], $_SESSION['catalog_flash']);
    }

    /**
     * Publish a catalog item (FR-CAT-06). Write ledger bucket 2 — no Helix.
     */
    public function publish(): void
    {
        if (!$this->guard(Persona::APP_OWNER, '/catalog')) {
            return;
        }
        if (!$this->dbAvailable()) {
            $this->redirect('/catalog');

            return;
        }

        $data = $this->getRequestData();

        $phpStream = (string) ($data['php_stream'] ?? 'php8.2');
        $components = $data['components'] ?? [];
        if (is_string($components)) {
            $components = [$components];
        }
        $components = array_values(array_filter((array) $components, 'is_string'));

        // Remember the composition so a failed publish re-renders the picker
        // in the state the user left it.
        $_SESSION['catalog_php'] = $phpStream;
        $_SESSION['catalog_components'] = $components;

        $result = $this->catalog->publish((string) ($data['name'] ?? ''), $phpStream, $components);

        if ($result['ok']) {
            $_SESSION['catalog_flash'] = sprintf('Published "%s" to the solution catalog.', $result['name']);
        } else {
            $_SESSION['catalog_errors'] = $result['errors'];
        }

        $this->redirect('/catalog');
    }
}
