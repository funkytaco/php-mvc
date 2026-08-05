<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;

/**
 * The `sop_notes` table — app-owned collaboration (FR-TASK-05).
 *
 * Write ledger bucket 3 (DESIGN-DD §5.3). Notes and cross-posts never mutate
 * a Helix ticket, which is why the Task View can be a write surface without
 * breaching the one-write rule.
 */
final class SopNoteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Notes posted on one team's SOP, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forTeam(string $team): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sop_notes WHERE team = :team ORDER BY created_at DESC'
        );
        $stmt->execute([':team' => $team]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All notes grouped by the team whose SOP they sit on.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function groupedByTeam(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sop_notes ORDER BY created_at DESC');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['team']][] = $row;
        }

        return $grouped;
    }

    /**
     * Add an attributed, time-stamped note or cross-post (FR-TASK-05).
     */
    public function add(string $team, string $author, ?string $authorTeam, bool $isCrossPost, bool $isCustomer, string $body): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sop_notes (team, author, author_team, is_cross_post, is_customer, body, created_at)
             VALUES (:team, :author, :author_team, :cross, :customer, :body, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            ':team' => $team,
            ':author' => $author,
            ':author_team' => $authorTeam,
            ':cross' => $isCrossPost ? 1 : 0,
            ':customer' => $isCustomer ? 1 : 0,
            ':body' => $body,
        ]);
    }
}
