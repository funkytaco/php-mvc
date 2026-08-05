<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Persona;
use App\Helix\HelixClientInterface;
use App\Helix\MockHelixClient;
use App\Persistence\AuditLog;
use App\Persistence\CatalogRepository;
use App\Persistence\OrderRepository;
use App\Persistence\SkuRepository;
use App\Persistence\SopNoteRepository;
use App\Services\CatalogService;
use App\Services\OrderIntakeService;
use App\Services\SopService;
use Nimbus\Controller\AbstractController;
use PDO;

/**
 * Shared base for every SOE surface: wiring, persona gating, common view data.
 *
 * Golden Rule 7 lives in guard(): persona comes from the Keycloak realm roles
 * the server put in the session, never from anything the browser supplies.
 */
abstract class SoeController extends AbstractController
{
    /** Path → page title, so views get a <title> without every caller passing one. */
    private const TITLES = [
        '/' => 'Home',
        '/catalog' => 'Catalog Builder',
        '/order' => 'Order Gateway',
        '/tracker' => 'Order Tracker',
        '/sops' => 'Team SOPs',
    ];

    protected ?PDO $db = null;
    protected ?HelixClientInterface $helix = null;
    protected ?OrderRepository $orders = null;
    protected ?CatalogRepository $catalogRepo = null;
    protected ?SkuRepository $skus = null;
    protected ?SopNoteRepository $sopNotes = null;
    protected ?AuditLog $audit = null;
    protected ?OrderIntakeService $intake = null;
    protected ?CatalogService $catalog = null;
    protected ?SopService $sops = null;

    protected function initialize(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $this->db = $this->getDb();

            // v1 resolves the mock adapter. `helix.driver = http` would build a
            // HelixHttpClient here instead — an adapter swap, not a code change
            // above the port (FR-HELIX-05).
            $this->helix = new MockHelixClient($this->db);

            $this->orders = new OrderRepository($this->db);
            $this->catalogRepo = new CatalogRepository($this->db);
            $this->skus = new SkuRepository($this->db);
            $this->sopNotes = new SopNoteRepository($this->db);
            $this->audit = new AuditLog($this->db);

            $this->intake = new OrderIntakeService($this->helix, $this->orders, $this->catalogRepo, $this->audit);
            $this->catalog = new CatalogService($this->skus, $this->catalogRepo, $this->audit);
            $this->sops = new SopService($this->helix, $this->sopNotes, $this->audit);
        } catch (\Throwable $e) {
            // PDO connects lazily; a down database must not fatal the process.
            error_log('SOE initialisation failed: ' . $e->getMessage());
            $this->db = null;
        }
    }

    protected function keycloakEnabled(): bool
    {
        $config = $this->getConfig();

        return filter_var($config['keycloak']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function persona(): Persona
    {
        return Persona::fromSession();
    }

    /** @return array<string,mixed>|null */
    protected function currentUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * Gate a surface on a Keycloak realm role.
     *
     * Returns true when the request may proceed. When it returns false it has
     * already emitted a response (SSO-required page, login redirect, or 403)
     * and the caller must return immediately.
     */
    protected function guard(string $requiredRole, string $path): bool
    {
        if (!$this->keycloakEnabled()) {
            http_response_code(503);
            echo $this->render('protected/sso-required', [
                'app_name' => $this->appName(),
                'surface' => $path,
                'installer_name' => $this->installerName(),
            ]);

            return false;
        }

        if ($this->currentUser() === null) {
            $this->redirect('/auth/login?redirect=' . urlencode($path));

            return false;
        }

        if (!$this->persona()->can($requiredRole)) {
            http_response_code(403);
            echo $this->render('protected/forbidden', array_merge($this->baseData($path), [
                'required_role' => $requiredRole,
                'held_roles' => array_map(
                    static fn (string $r): array => ['name' => $r],
                    $this->persona()->roles()
                ),
                'has_roles' => $this->persona()->roles() !== [],
            ]));

            return false;
        }

        return true;
    }

    /**
     * View data every surface needs: chrome, persona, navigation.
     *
     * @return array<string,mixed>
     */
    protected function baseData(string $currentPath): array
    {
        $persona = $this->persona();
        $user = $this->currentUser();

        return [
            'page_title' => self::TITLES[$currentPath] ?? '',
            'app_name' => $this->appName(),
            'installer_name' => $this->installerName(),
            'has_keycloak' => $this->keycloakEnabled(),
            'user' => $user,
            'persona_label' => $persona->label(),
            'nav' => $persona->navigation($currentPath),
            'is_demo' => $this->isDemoEnv(),
        ];
    }

    protected function appName(): string
    {
        $config = $this->getConfig();

        return (string) ($config['app_name'] ?? 'Host Build — Order & Tracking Gateway');
    }

    protected function installerName(): string
    {
        $config = $this->getConfig();

        return (string) ($config['installer-name'] ?? 'app');
    }

    /**
     * True only in local/demo. Gates the dev-only Helix progression
     * (DESIGN-DD §4) — it must never exist in production.
     */
    protected function isDemoEnv(): bool
    {
        $config = $this->getConfig();

        return in_array((string) ($config['app_env'] ?? 'production'), ['local', 'demo'], true);
    }

    protected function dbAvailable(): bool
    {
        return $this->db !== null;
    }
}
