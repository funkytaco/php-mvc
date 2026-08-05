<?php

declare(strict_types=1);

namespace App\Services;

use App\Persistence\AuditLog;
use App\Persistence\SopRunRepository;

/**
 * Runs an SOP step's bound rulebook against one order, via Event-Driven Ansible.
 *
 * ============================================================================
 * SCOPE — read this before making a rulebook do real work.
 *
 * Golden Rule 1 (soe/AGENTS.md) is "track, don't touch": this platform does not
 * provision, install, configure or otherwise change infrastructure, and holds
 * no infrastructure credentials. The rulebook shipped with this template
 * (`sop-demo.yml`) therefore only logs, waits, and reports back — the plumbing
 * is real, the work is simulated.
 *
 * Pointing a step at a rulebook that performs actual build work would breach
 * Rule 1 and NG1. That is a spec decision, not a config change: amend
 * AGENTS.md and SPEC-DD first, and expect EDA to need real credentials.
 * ============================================================================
 *
 * Flow:
 *   app  --POST /endpoint-->  EDA (<app>-eda:5000)  --run_playbook-->  playbook
 *   app  <--POST /api/sops/runs/{id}/complete--------------------------  playbook
 *
 * The callback carries a single-use per-run token because it arrives from the
 * EDA container, which has no browser session and therefore no Keycloak role.
 */
final class SopRunnerService
{
    /** Rulebooks a step may be bound to. Anything else is rejected. */
    public const AVAILABLE_RULEBOOKS = [
        'sop-demo.yml' => 'Demo — logs, waits, reports completion',
    ];

    private const EDA_WEBHOOK_PORT = 5000;
    private const EDA_TIMEOUT_SECONDS = 10;

    public function __construct(
        private SopRunRepository $runs,
        private AuditLog $audit,
        private string $appSlug,
        private bool $edaEnabled,
    ) {
    }

    public function edaEnabled(): bool
    {
        return $this->edaEnabled;
    }

    /**
     * Rulebook choices for the binding UI.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rulebookChoices(?string $selected): array
    {
        $out = [];
        foreach (self::AVAILABLE_RULEBOOKS as $file => $label) {
            $out[] = ['file' => $file, 'label' => $label, 'selected' => $file === $selected];
        }

        return $out;
    }

    public function isKnownRulebook(string $rulebook): bool
    {
        return array_key_exists($rulebook, self::AVAILABLE_RULEBOOKS);
    }

    /**
     * Bind a rulebook to a step (write ledger bucket 2 — app authoring).
     */
    public function bind(string $team, int $stepIndex, string $rulebook, string $actor): bool
    {
        if (!$this->isKnownRulebook($rulebook)) {
            return false;
        }

        $this->runs->bind($team, $stepIndex, $rulebook, $actor);
        $this->audit->record('sop.rulebook_bound', $team . '#' . $stepIndex, $rulebook, $actor);

        return true;
    }

    public function unbind(string $team, int $stepIndex, string $actor): void
    {
        $this->runs->unbind($team, $stepIndex);
        $this->audit->record('sop.rulebook_unbound', $team . '#' . $stepIndex, null, $actor);
    }

    /**
     * Fire the bound rulebook for one step against one order.
     *
     * @return array{ok:bool,message:string,run_id:int|null}
     */
    public function run(string $team, int $stepIndex, string $orderRef, string $actor): array
    {
        if (!$this->edaEnabled) {
            return [
                'ok' => false,
                'run_id' => null,
                'message' => 'Event-Driven Ansible is not installed for this app — run '
                    . 'composer nimbus:add-eda ' . $this->appSlug . ' first.',
            ];
        }

        $rulebook = $this->runs->binding($team, $stepIndex);
        if ($rulebook === null) {
            return ['ok' => false, 'run_id' => null, 'message' => 'No rulebook is bound to that step.'];
        }

        // 32 hex chars of CSPRNG. Guessing it is the only way to forge a
        // completion callback, and it is single-use per run.
        $token = bin2hex(random_bytes(16));
        $runId = $this->runs->startRun($team, $stepIndex, $orderRef, $rulebook, $actor, $token);

        $payload = [
            'event' => 'sop.run',
            'run_id' => $runId,
            'team' => $team,
            'step_index' => $stepIndex,
            'order_ref' => $orderRef,
            'rulebook' => $rulebook,
            'callback_token' => $token,
            // Container-to-container: EDA reaches the app on its internal port,
            // never the host-published one.
            'callback_url' => sprintf(
                'http://%s-app:8080/api/sops/runs/%d/complete',
                $this->appSlug,
                $runId
            ),
        ];

        $dispatched = $this->postToEda($payload, $error);

        if (!$dispatched) {
            $this->runs->finishRun($runId, SopRunRepository::FAILED, 'Could not reach EDA: ' . $error);
            $this->audit->record('sop.run_failed', $team . '#' . $stepIndex, $error, $actor);

            return ['ok' => false, 'run_id' => $runId, 'message' => 'Could not reach EDA: ' . $error];
        }

        $this->runs->markRunning($runId);
        $this->audit->record(
            'sop.run_started',
            $team . '#' . $stepIndex,
            sprintf('%s for %s (run %d)', $rulebook, $orderRef, $runId),
            $actor
        );

        return ['ok' => true, 'run_id' => $runId, 'message' => sprintf('Running %s for %s.', $rulebook, $orderRef)];
    }

    /**
     * Close a run from the EDA callback. Verifies the per-run token with a
     * timing-safe comparison before touching anything.
     *
     * @return array{ok:bool,message:string}
     */
    public function complete(int $runId, string $token, string $status, ?string $result): array
    {
        $run = $this->runs->findRun($runId);
        if ($run === null) {
            return ['ok' => false, 'message' => 'Unknown run.'];
        }

        if (!hash_equals((string) $run['callback_token'], $token)) {
            return ['ok' => false, 'message' => 'Invalid callback token.'];
        }

        $status = $status === SopRunRepository::COMPLETED
            ? SopRunRepository::COMPLETED
            : SopRunRepository::FAILED;

        if (!$this->runs->finishRun($runId, $status, $result)) {
            return ['ok' => false, 'message' => 'Run already finished.'];
        }

        $this->audit->record(
            'sop.run_' . $status,
            $run['team'] . '#' . $run['step_index'],
            sprintf('%s for %s (run %d)', $run['rulebook'], $run['order_ref'], $runId),
            'eda'
        );

        return ['ok' => true, 'message' => 'Run recorded.'];
    }

    /**
     * POST the event to the EDA container's webhook source.
     *
     * @param array<string,mixed> $payload
     */
    private function postToEda(array $payload, ?string &$error = null): bool
    {
        $url = sprintf('http://%s-eda:%d/endpoint', $this->appSlug, self::EDA_WEBHOOK_PORT);

        $ch = curl_init($url);
        if ($ch === false) {
            $error = 'could not initialise HTTP client';

            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::EDA_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $error = $curlError !== '' ? $curlError : 'no response';

            return false;
        }

        if ($code >= 400) {
            $error = 'EDA returned HTTP ' . $code;

            return false;
        }

        return true;
    }
}
