<?php

declare(strict_types=1);

namespace App\Controllers;

use Nimbus\Controller\AbstractController;

/**
 * Example protected routes for Keycloak SSO — one per protection level.
 *
 *   GET /protected  any signed-in user (the seeded test user qualifies)
 *   GET /admin      only users holding the realm role 'admin'
 *                   (keycloak-init.sh creates the role; it assigns it to
 *                   no one, so /admin answers 403 until you grant it —
 *                   which is itself the demonstration)
 *
 * Deliberately self-contained: authentication state is read from the same
 * session AuthController::callback() writes, and configuration from the app
 * config at request time. Copy either action as the starting point for a
 * real protected page.
 *
 * @package App\Controllers
 * @license Apache-2.0
 * @copyright 2025 SmallCloud, LLC
 */
class ProtectedController extends AbstractController
{
    protected function initialize(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * GET /protected — requires a login, any role.
     */
    public function member(): void
    {
        if (!$this->keycloakEnabled()) {
            echo $this->render('protected/disabled', $this->disabledData('/protected'));
            return;
        }

        $user = $this->currentUser();
        if ($user === null) {
            $this->redirect('/auth/login?redirect=' . urlencode('/protected'));
            return;
        }

        echo $this->render('protected/member', $this->userData($user) + [
            'title' => 'Protected page',
        ]);
    }

    /**
     * GET /admin — requires a login and the 'admin' realm role.
     */
    public function admin(): void
    {
        if (!$this->keycloakEnabled()) {
            echo $this->render('protected/disabled', $this->disabledData('/admin'));
            return;
        }

        $user = $this->currentUser();
        if ($user === null) {
            $this->redirect('/auth/login?redirect=' . urlencode('/admin'));
            return;
        }

        $roles = $user['roles'] ?? [];

        if (!in_array('admin', $roles, true)) {
            // A signed-in user without the role gets a page explaining what
            // is missing rather than a bare error — but still a real 403.
            http_response_code(403);
            echo $this->render('protected/admin', $this->userData($user) + [
                'title' => 'Admin page',
                'denied' => true,
            ]);
            return;
        }

        echo $this->render('protected/admin', $this->userData($user) + [
            'title' => 'Admin page',
            'denied' => false,
        ]);
    }

    /**
     * Whether this app was built with Keycloak at all. Same test the login
     * flow uses, so the two can never disagree.
     */
    private function keycloakEnabled(): bool
    {
        $keycloak = $this->getConfig()['keycloak'] ?? [];

        return isset($keycloak['enabled'])
            && ($keycloak['enabled'] === true || $keycloak['enabled'] === 'true');
    }

    /**
     * The signed-in user, or null when there is no live session.
     *
     * An expired token counts as signed out: redirecting to /auth/login is
     * cheap because Keycloak still holds the SSO session and bounces straight
     * back. (The token-refresh dance is deliberately not duplicated in an
     * example — see AuthController for the full flow.)
     *
     * @return array<string, mixed>|null
     */
    private function currentUser(): ?array
    {
        if (!isset($_SESSION['user'], $_SESSION['keycloak_token'])) {
            return null;
        }

        if (isset($_SESSION['token_expires_at']) && time() >= $_SESSION['token_expires_at']) {
            return null;
        }

        return $_SESSION['user'];
    }

    /**
     * View data for a signed-in user.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userData(array $user): array
    {
        $roles = array_values(array_filter((array) ($user['roles'] ?? []), 'is_string'));

        $config = $this->getConfig();
        $hostPort = $config['keycloak']['host_port'] ?? null;

        return [
            'username' => $user['username'] ?? '',
            'email' => $user['email'] ?? '',
            'name' => $user['name'] ?? ($user['username'] ?? ''),
            'roles' => array_map(fn (string $role): array => ['name' => $role], $roles),
            'has_roles' => $roles !== [],
            'realm' => $config['keycloak']['realm'] ?? '',
            // Browser-reachable admin console, for the "grant the role" hint
            'admin_console' => $hostPort ? 'http://localhost:' . $hostPort : '',
        ];
    }

    /**
     * View data for the "this app has no SSO" page.
     *
     * @return array<string, mixed>
     */
    private function disabledData(string $route): array
    {
        return [
            'title' => 'Protected route',
            'route' => $route,
            'app_name' => $this->getConfig()['installer-name'] ?? '',
        ];
    }
}
