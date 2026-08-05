<?php

declare(strict_types=1);

namespace App\Helix;

/**
 * The Helix port — the ONLY surface the rest of the app knows about
 * (DESIGN-DD §3, AGENTS.md Golden Rule 5).
 *
 * GOLDEN RULE 2: this interface exposes exactly one mutator, createTicket(),
 * and it is called from App\Services\OrderIntakeService::submit() and nowhere
 * else. Adding a second mutator here, or a second caller of createTicket(),
 * breaks the invariant and its architecture test. Everything else is a read.
 *
 * Two adapters implement this: MockHelixClient (v1, DB-backed) and a future
 * HelixHttpClient. Selection is the `helix.driver` config switch, not a code
 * change (FR-HELIX-05).
 */
interface HelixClientInterface
{
    /**
     * The single write. Creates the order's Helix ticket and returns its reference.
     *
     * @param array<string,mixed> $blob The TicketBlob (DESIGN-DD §5.1).
     * @return string The created ticket id, e.g. "HLX-88413".
     */
    public function createTicket(array $blob): string;

    /**
     * Read — lists tickets, drives the Tracker's order switcher.
     *
     * @param array<string,mixed> $filter
     * @return array<int,array<string,mixed>>
     */
    public function listTickets(array $filter = []): array;

    /**
     * Read — one ticket's status, current queue, per-queue state, blocker, history.
     *
     * @return array<string,mixed>|null Null when the ticket does not exist.
     */
    public function getTicket(string $ticketId): ?array;
}
