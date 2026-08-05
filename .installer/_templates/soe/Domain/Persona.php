<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persona resolution from Keycloak realm roles (spec §3, NFR-04).
 *
 * Golden Rule 7: role scoping is server-side and structural. Persona is
 * derived here, on the server, from the roles Keycloak put in the access
 * token — never from a query string, a form field, or anything the browser
 * can choose. Surfaces call requires() before rendering, and the API
 * serializes through the persona's serializer.
 */
final class Persona
{
    public const APP_OWNER = 'app-owner';
    public const REQUESTER = 'requester';
    public const CUSTOMER = 'customer';
    public const TEAM_MEMBER = 'team-member';

    /** Every persona role — surfaces open to all personas gate on this set. */
    public const ALL_PERSONAS = [
        self::APP_OWNER,
        self::REQUESTER,
        self::CUSTOMER,
        self::TEAM_MEMBER,
    ];

    /** Persona → the surface it lands on (spec §3). */
    public const HOME = [
        self::APP_OWNER => '/catalog',
        self::REQUESTER => '/order',
        self::CUSTOMER => '/tracker',
        self::TEAM_MEMBER => '/sops',
    ];

    public const LABEL = [
        self::APP_OWNER => 'App Owner',
        self::REQUESTER => 'Requester',
        self::CUSTOMER => 'Customer · status',
        self::TEAM_MEMBER => 'Team Member',
    ];

    /**
     * @param array<int,string> $roles Realm roles from the access token.
     */
    public function __construct(private array $roles)
    {
    }

    /**
     * Reads roles from the session that AuthController populated at callback.
     * Returns a persona with no roles when nobody is signed in.
     */
    public static function fromSession(): self
    {
        $roles = $_SESSION['user']['roles'] ?? [];

        return new self(is_array($roles) ? array_values(array_filter($roles, 'is_string')) : []);
    }

    public function has(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** @return array<int,string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * True when this persona may see a surface. `admin` is a superuser role so
     * an operator can inspect every surface without four separate logins.
     */
    public function can(string $requiredRole): bool
    {
        return $this->has($requiredRole) || $this->has('admin');
    }

    /**
     * True when this persona holds ANY of the given roles (or `admin`).
     * Multi-persona surfaces — currently only the read-only Order Tracker —
     * gate on this instead of can().
     */
    public function canAny(string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->can($role)) {
                return true;
            }
        }

        return false;
    }

    /** The first persona role held, used to pick a landing surface. */
    public function primary(): ?string
    {
        foreach (self::ALL_PERSONAS as $role) {
            if ($this->has($role)) {
                return $role;
            }
        }

        return $this->has('admin') ? self::CUSTOMER : null;
    }

    public function label(): string
    {
        $primary = $this->primary();

        return $primary === null ? 'No persona' : self::LABEL[$primary];
    }

    public function homePath(): string
    {
        $primary = $this->primary();

        return $primary === null ? '/' : self::HOME[$primary];
    }

    /**
     * Nav entries this persona may reach. Surfaces they cannot enter are not
     * rendered at all, but the real enforcement is the per-route can() check —
     * this only decides what the tab bar shows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function navigation(string $currentPath): array
    {
        $tabs = [
            ['path' => '/catalog', 'label' => 'Catalog Builder', 'roles' => [self::APP_OWNER]],
            ['path' => '/order',   'label' => 'Order Gateway',   'roles' => [self::REQUESTER]],
            ['path' => '/tracker', 'label' => 'Order Tracker',   'roles' => self::ALL_PERSONAS],
            ['path' => '/sops',    'label' => 'Team SOPs',       'roles' => [self::TEAM_MEMBER]],
        ];

        $out = [];
        foreach ($tabs as $tab) {
            if (!$this->canAny(...$tab['roles'])) {
                continue;
            }
            $tab['is_active'] = str_starts_with($currentPath, $tab['path']);
            $out[] = $tab;
        }

        return $out;
    }
}
