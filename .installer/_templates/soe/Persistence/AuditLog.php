<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;

/**
 * Immutable app-side action log (AGENTS.md "Audit", FR-SM-01, FR-SM-03, NFR-02).
 *
 * Append-only by design: there is no update() and no delete(). Combined with
 * the Helix ticket's own history, this makes a build's full story
 * reconstructable from the audit trail alone.
 */
// Not final: tests substitute a double for this collaborator.
class AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(string $action, ?string $subject = null, ?string $detail = null, ?string $actor = null): void
    {
        $actor ??= $_SESSION['user']['username'] ?? 'system';

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor, action, subject, detail, created_at)
             VALUES (:actor, :action, :subject, :detail, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':subject' => $subject,
            ':detail' => $detail,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
