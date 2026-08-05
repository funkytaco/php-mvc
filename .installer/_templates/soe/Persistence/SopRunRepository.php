<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;

/**
 * `sop_step_bindings` and `sop_step_runs` — which rulebook a team attached to
 * an SOP step, and the history of runs against individual orders.
 *
 * Golden Rule 3: neither table is fulfillment state. Helix still decides
 * whether a build advanced, and OrderProjection never reads these.
 */
// Not final: tests substitute a double for this collaborator, which matches the
// repo's existing convention of subclassing seams rather than mocking through
// an interface that exists only for tests.
class SopRunRepository
{
    /** Run states, in lifecycle order. */
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public function __construct(private PDO $pdo)
    {
    }

    // ---- bindings ---------------------------------------------------------

    /**
     * Bindings for a team, keyed by step index.
     *
     * @return array<int,string> step_index => rulebook
     */
    public function bindingsFor(string $team): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT step_index, rulebook FROM sop_step_bindings WHERE team = :team'
        );
        $stmt->execute([':team' => $team]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['step_index']] = (string) $row['rulebook'];
        }

        return $out;
    }

    public function binding(string $team, int $stepIndex): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT rulebook FROM sop_step_bindings WHERE team = :team AND step_index = :idx LIMIT 1'
        );
        $stmt->execute([':team' => $team, ':idx' => $stepIndex]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /** Attach a rulebook to a step, replacing any existing binding. */
    public function bind(string $team, int $stepIndex, string $rulebook, string $actor): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sop_step_bindings (team, step_index, rulebook, created_by)
             VALUES (:team, :idx, :rulebook, :actor)
             ON CONFLICT (team, step_index)
             DO UPDATE SET rulebook = EXCLUDED.rulebook, created_by = EXCLUDED.created_by'
        );

        $stmt->execute([
            ':team' => $team,
            ':idx' => $stepIndex,
            ':rulebook' => $rulebook,
            ':actor' => $actor,
        ]);
    }

    public function unbind(string $team, int $stepIndex): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM sop_step_bindings WHERE team = :team AND step_index = :idx'
        );
        $stmt->execute([':team' => $team, ':idx' => $stepIndex]);
    }

    // ---- runs -------------------------------------------------------------

    /**
     * Open a run and return its id. Status starts at `queued`; the caller
     * flips it to `running` once EDA has accepted the webhook.
     */
    public function startRun(string $team, int $stepIndex, string $orderRef, string $rulebook, string $actor, string $token): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sop_step_runs (team, step_index, order_ref, rulebook, status, actor, callback_token, started_at)
             VALUES (:team, :idx, :order_ref, :rulebook, :status, :actor, :token, CURRENT_TIMESTAMP)
             RETURNING id'
        );

        $stmt->execute([
            ':team' => $team,
            ':idx' => $stepIndex,
            ':order_ref' => $orderRef,
            ':rulebook' => $rulebook,
            ':status' => self::QUEUED,
            ':actor' => $actor,
            ':token' => $token,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function markRunning(int $runId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sop_step_runs SET status = :status WHERE id = :id'
        );
        $stmt->execute([':id' => $runId, ':status' => self::RUNNING]);
    }

    /**
     * Close a run. Terminal states only; a run already finished is left alone
     * so a duplicate callback cannot rewrite history.
     */
    public function finishRun(int $runId, string $status, ?string $result): bool
    {
        if (!in_array($status, [self::COMPLETED, self::FAILED], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE sop_step_runs
                SET status = :status, result = :result, finished_at = CURRENT_TIMESTAMP
              WHERE id = :id AND status IN ('queued', 'running')"
        );
        $stmt->execute([':id' => $runId, ':status' => $status, ':result' => $result]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $runId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sop_step_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * The most recent run per step for one team+order, keyed by step index.
     * DISTINCT ON is Postgres-specific and is the cheapest way to get
     * "latest row per group" without a window-function subquery.
     *
     * @return array<int,array<string,mixed>>
     */
    public function latestRunsFor(string $team, string $orderRef): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT ON (step_index) *
               FROM sop_step_runs
              WHERE team = :team AND order_ref = :order_ref
              ORDER BY step_index, started_at DESC, id DESC'
        );
        $stmt->execute([':team' => $team, ':order_ref' => $orderRef]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['step_index']] = $row;
        }

        return $out;
    }

    /**
     * Recent run history for a team, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentRuns(string $team, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sop_step_runs WHERE team = :team ORDER BY started_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':team', $team);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
