<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\OrderProjection;
use App\Domain\Persona;
use App\Domain\Serializer\CustomerSerializer;
use App\Domain\Serializer\OwnerSerializer;
use App\Helix\MockHelixClient;

/**
 * The JSON API (DESIGN-DD §8), implemented as PHP-MVC routes dispatched to a
 * single controller rather than a separate micro-framework.
 *
 * Every endpoint is role-scoped server-side (NFR-04), and customer-facing
 * payloads pass through CustomerSerializer so the SKU omission of Golden
 * Rule 7 is enforced once, here, at the API seam.
 */
final class ApiController extends SoeController
{
    /**
     * JSON equivalent of SoeController::guard() — never redirects, never
     * renders HTML.
     */
    private function apiGuard(string $requiredRole): bool
    {
        if (!$this->keycloakEnabled()) {
            $this->json(['success' => false, 'error' => 'SSO is not installed for this app.'], 503);

            return false;
        }
        if ($this->currentUser() === null) {
            $this->json(['success' => false, 'error' => 'Not authenticated.'], 401);

            return false;
        }
        if (!$this->persona()->can($requiredRole)) {
            $this->json(['success' => false, 'error' => 'Forbidden for this persona.'], 403);

            return false;
        }
        if (!$this->dbAvailable()) {
            $this->json(['success' => false, 'error' => 'Database unavailable.'], 503);

            return false;
        }

        return true;
    }

    /** GET /api/catalog — published solution catalog (App Owner, Requester). */
    public function catalog(): void
    {
        if (!$this->keycloakEnabled() || $this->currentUser() === null) {
            $this->json(['success' => false, 'error' => 'Not authenticated.'], 401);

            return;
        }
        $persona = $this->persona();
        if (!$persona->can(Persona::APP_OWNER) && !$persona->can(Persona::REQUESTER)) {
            $this->json(['success' => false, 'error' => 'Forbidden for this persona.'], 403);

            return;
        }
        if (!$this->dbAvailable()) {
            $this->json(['success' => false, 'error' => 'Database unavailable.'], 503);

            return;
        }

        $this->json([
            'success' => true,
            'data' => (new OwnerSerializer())->serializeCatalog($this->catalogRepo->published()),
        ]);
    }

    /**
     * POST /api/orders/resolve — the live "what intake resolves" preview
     * (FR-ORD-04). Reads only; creates nothing.
     */
    public function resolve(): void
    {
        if (!$this->apiGuard(Persona::REQUESTER)) {
            return;
        }

        $this->json(['success' => true, 'data' => $this->intake->resolve($this->getRequestData())]);
    }

    /** GET /api/orders — the tracker's order switcher, customer-scoped. */
    public function orders(): void
    {
        if (!$this->apiGuard(Persona::CUSTOMER)) {
            return;
        }

        $projection = new OrderProjection();
        $serializer = new CustomerSerializer();

        $out = [];
        foreach ($this->orders->all() as $order) {
            $ticket = is_string($order['helix_ticket_ref'] ?? null)
                ? $this->helix->getTicket((string) $order['helix_ticket_ref'])
                : null;
            if ($ticket === null) {
                continue;
            }
            $out[] = $serializer->serializeOrder($projection->project($ticket, $order));
        }

        $this->json(['success' => true, 'data' => $out]);
    }

    /**
     * GET /api/orders/{ref} — the projected order view. This is what the
     * tracker polls every 15 s (DESIGN-DD §9).
     */
    public function order(string $ref): void
    {
        if (!$this->apiGuard(Persona::CUSTOMER)) {
            return;
        }

        $order = $this->orders->findByRef($ref);
        if ($order === null || !is_string($order['helix_ticket_ref'] ?? null)) {
            $this->json(['success' => false, 'error' => 'Order not found: ' . $ref], 404);

            return;
        }

        $ticket = $this->helix->getTicket((string) $order['helix_ticket_ref']);
        if ($ticket === null) {
            $this->json(['success' => false, 'error' => 'Helix ticket not found.'], 404);

            return;
        }

        $this->json([
            'success' => true,
            'data' => (new CustomerSerializer())->serializeOrder(
                (new OrderProjection())->project($ticket, $order)
            ),
        ]);
    }

    /** GET /api/sops/{team} — a team's SOP, queue and notes. */
    public function sop(string $team): void
    {
        if (!$this->apiGuard(Persona::TEAM_MEMBER)) {
            return;
        }

        $data = $this->sops->team($team);
        if ($data === null) {
            $this->json(['success' => false, 'error' => 'Unknown team: ' . $team], 404);

            return;
        }

        $this->json(['success' => true, 'data' => $data]);
    }

    /** POST /api/sops/{team}/notes — add a note or cross-post (FR-TASK-05). */
    public function addNote(string $team): void
    {
        if (!$this->apiGuard(Persona::TEAM_MEMBER)) {
            return;
        }

        $data = $this->getRequestData();
        $ok = $this->sops->addNote($team, (string) ($data['author'] ?? 'customer'), (string) ($data['body'] ?? ''));

        if (!$ok) {
            $this->json(['success' => false, 'error' => 'Unknown team or empty note.'], 422);

            return;
        }

        $this->json(['success' => true, 'data' => $this->sops->team($team)]);
    }

    /**
     * POST /api/dev/helix/advance — simulate the next Helix step.
     *
     * DEV/DEMO ONLY (DESIGN-DD §4, AGENTS.md). In production, teams advance
     * tickets in Helix; this endpoint 404s so it is indistinguishable from
     * not existing. Do not remove the app_env gate.
     */
    public function advance(): void
    {
        if (!$this->isDemoEnv()) {
            $this->json(['success' => false, 'error' => 'Not found.'], 404);

            return;
        }
        if (!$this->apiGuard(Persona::CUSTOMER)) {
            return;
        }

        $data = $this->getRequestData();
        $orderRef = (string) ($data['order_ref'] ?? '');

        $order = $this->orders->findByRef($orderRef);
        if ($order === null || !is_string($order['helix_ticket_ref'] ?? null)) {
            $this->json(['success' => false, 'error' => 'Order not found: ' . $orderRef], 404);

            return;
        }

        // advance() is deliberately NOT on HelixClientInterface — it is mock-only.
        if (!$this->helix instanceof MockHelixClient) {
            $this->json(['success' => false, 'error' => 'Progression is only available on the mock adapter.'], 409);

            return;
        }

        $this->helix->advance((string) $order['helix_ticket_ref']);
        $this->audit->record('dev.helix_advanced', $orderRef, 'Simulated Helix progression (demo only).');

        $ticket = $this->helix->getTicket((string) $order['helix_ticket_ref']);

        $this->json([
            'success' => true,
            'data' => (new CustomerSerializer())->serializeOrder(
                (new OrderProjection())->project($ticket ?? [], $order)
            ),
        ]);
    }
}
