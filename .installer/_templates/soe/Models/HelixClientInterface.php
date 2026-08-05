<?php

declare(strict_types=1);

namespace App\Models;

/**
 * The Helix adapter port: one write, read-only thereafter.
 * Two implementations: MockHelixClient (v1) and HelixHttpClient (real, future).
 * Selection is config-driven (HELIX_DRIVER env var or app.config.php).
 */
interface HelixClientInterface
{
    /**
     * The single write: create a new ticket from an order submission.
     * Returns the created ticket ID (e.g., 'HLX-88231').
     *
     * @param array $blob {source, orderRef, summary, catalogItem, app, environment, sensitivity, expectedUsers, needBy, resolvedProfile, requirementsRecord, queues[]}
     * @return array with 'id' key (e.g., ['id' => 'HLX-88231'])
     * @throws \Exception on creation failure
     */
    public function createTicket(array $blob): array;

    /**
     * List all tickets, ordered by creation date descending.
     *
     * @return array of tickets, each with id, orderRef, status, currentQueue, queues[], blocker, history, createdAt
     */
    public function listTickets(): array;

    /**
     * Retrieve a single ticket by its ID.
     *
     * @param string $ticketId (e.g., 'HLX-88231')
     * @return array with id, orderRef, status, currentQueue, queues[], blocker, history, createdAt
     * @throws \Exception if ticket not found
     */
    public function getTicket(string $ticketId): array;
}
