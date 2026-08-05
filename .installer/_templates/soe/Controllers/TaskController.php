<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Persona;
use App\Services\SopService;

/**
 * Team SOPs & Task View — Team Member (spec §5.4, FR-TASK-01…06).
 *
 * Read + collaborate (DESIGN-DD §7). Members see their projected Helix queue,
 * their team's SOP, the exit contract that defines "done", and the notes
 * thread. They attest completion in Helix, not here — the only write this
 * surface performs is an SOP note, which is app-owned collaboration metadata
 * (write ledger bucket 3) and never touches a ticket.
 */
final class TaskController extends SoeController
{
    public function index(): void
    {
        if (!$this->guard(Persona::TEAM_MEMBER, '/sops')) {
            return;
        }
        if (!$this->dbAvailable()) {
            echo $this->render('protected/no-database', $this->baseData('/sops'));

            return;
        }

        echo $this->render('sops/index', array_merge($this->baseData('/sops'), [
            'teams' => $this->sops->allTeams(),
            'flash' => $_SESSION['sop_flash'] ?? null,
        ]));

        unset($_SESSION['sop_flash']);
    }

    public function team(string $team): void
    {
        if (!$this->guard(Persona::TEAM_MEMBER, '/sops')) {
            return;
        }
        if (!$this->dbAvailable()) {
            echo $this->render('protected/no-database', $this->baseData('/sops'));

            return;
        }

        // Runs are per-order, so the SOP page needs an order in context. Default
        // to the oldest-waiting item in this team's queue — the one FR-TASK-02
        // says they should pick up next.
        $probe = $this->sops->team($team);
        if ($probe === null) {
            http_response_code(404);
            echo $this->render('protected/not-found', array_merge($this->baseData('/sops'), [
                'ref' => $team,
            ]));

            return;
        }

        $queue = $probe['queue'];
        $orderRef = (string) ($_GET['order'] ?? '');
        $known = array_column($queue, 'order_ref');

        if ($orderRef === '' || !in_array($orderRef, $known, true)) {
            $orderRef = (string) ($queue[0]['order_ref'] ?? '');
        }

        $data = $orderRef !== '' ? $this->sops->team($team, $orderRef) : $probe;

        echo $this->render('sops/team', array_merge($this->baseData('/sops'), [
            'team' => $data,
            'authors' => $this->sops->crossPostAuthors($team),
            'flash' => $_SESSION['sop_flash'] ?? null,
            'flash_is_error' => $_SESSION['sop_flash_error'] ?? false,

            // Order context for the run controls.
            'order_ref' => $orderRef,
            'has_order' => $orderRef !== '',
            'order_choices' => array_map(
                static fn (array $q): array => [
                    'order_ref' => $q['order_ref'],
                    'selected' => $q['order_ref'] === $orderRef,
                ],
                $queue
            ),

            // Rulebook binding controls.
            'eda_enabled' => $this->sopRunner->edaEnabled(),
            'rulebooks' => $this->sopRunner->rulebookChoices(null),
            'recent_runs' => array_map(
                static fn (array $r): array => [
                    'id' => $r['id'],
                    'step_index' => $r['step_index'],
                    'order_ref' => $r['order_ref'],
                    'rulebook' => $r['rulebook'],
                    'status' => $r['status'],
                    'actor' => $r['actor'],
                    'result' => $r['result'],
                ],
                $this->sopRuns->recentRuns($team, 8)
            ),
        ]));

        unset($_SESSION['sop_flash'], $_SESSION['sop_flash_error']);
    }

    /**
     * Attach or detach a rulebook on an SOP step (write ledger bucket 2).
     * The step TEXT is not editable — it is the team's certified procedure.
     */
    public function bindRulebook(string $team, string $stepIndex): void
    {
        if (!$this->guard(Persona::TEAM_MEMBER, '/sops')) {
            return;
        }
        if (!$this->dbAvailable()) {
            $this->redirect('/sops/' . $team);

            return;
        }

        $data = $this->getRequestData();
        $rulebook = trim((string) ($data['rulebook'] ?? ''));
        $actor = (string) ($this->currentUser()['username'] ?? 'unknown');
        $index = (int) $stepIndex;

        if ($rulebook === '') {
            $this->sopRunner->unbind($team, $index, $actor);
            $this->flash(sprintf('Rulebook detached from step %02d.', $index + 1));
        } elseif ($this->sopRunner->bind($team, $index, $rulebook, $actor)) {
            $this->flash(sprintf('%s bound to step %02d.', $rulebook, $index + 1));
        } else {
            $this->flash('Unknown rulebook — nothing was bound.', true);
        }

        $this->redirect($this->backTo($team, $data));
    }

    /**
     * Fire a step's bound rulebook for the selected order (FR-TASK-03).
     *
     * The platform records the run; the rulebook shipped with this template
     * only simulates work. See SopRunnerService for why that boundary matters.
     */
    public function runStep(string $team, string $stepIndex): void
    {
        if (!$this->guard(Persona::TEAM_MEMBER, '/sops')) {
            return;
        }
        if (!$this->dbAvailable()) {
            $this->redirect('/sops/' . $team);

            return;
        }

        $data = $this->getRequestData();
        $orderRef = trim((string) ($data['order_ref'] ?? ''));
        $actor = (string) ($this->currentUser()['username'] ?? 'unknown');

        if ($orderRef === '') {
            $this->flash('Pick an order before running a step.', true);
            $this->redirect('/sops/' . $team);

            return;
        }

        $result = $this->sopRunner->run($team, (int) $stepIndex, $orderRef, $actor);
        $this->flash($result['message'], !$result['ok']);

        $this->redirect($this->backTo($team, $data));
    }

    private function flash(string $message, bool $isError = false): void
    {
        $_SESSION['sop_flash'] = $message;
        $_SESSION['sop_flash_error'] = $isError;
    }

    /** Return to the same team page with the order still selected. */
    private function backTo(string $team, array $data): string
    {
        $orderRef = trim((string) ($data['order_ref'] ?? ''));

        return '/sops/' . $team . ($orderRef !== '' ? '?order=' . urlencode($orderRef) : '');
    }

    /**
     * Add a note or cross-post to a team's SOP (FR-TASK-05).
     */
    public function addNote(string $team): void
    {
        if (!$this->guard(Persona::TEAM_MEMBER, '/sops')) {
            return;
        }
        if (!$this->dbAvailable()) {
            $this->redirect('/sops');

            return;
        }

        $data = $this->getRequestData();
        $author = (string) ($data['author'] ?? 'customer');
        $body = (string) ($data['body'] ?? '');

        if ($this->sops->addNote($team, $author, $body)) {
            $teamName = SopService::TEAMS[$team]['name'] ?? $team;
            $_SESSION['sop_flash'] = $author === 'customer'
                ? sprintf('Note added to the %s SOP.', $teamName)
                : sprintf('Cross-posted to the %s SOP.', $teamName);
        } else {
            $_SESSION['sop_flash'] = 'Nothing posted — write a note first.';
        }

        $this->redirect('/sops/' . $team);
    }
}
