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

        $data = $this->sops->team($team);
        if ($data === null) {
            http_response_code(404);
            echo $this->render('protected/not-found', array_merge($this->baseData('/sops'), [
                'ref' => $team,
            ]));

            return;
        }

        echo $this->render('sops/team', array_merge($this->baseData('/sops'), [
            'team' => $data,
            'authors' => $this->sops->crossPostAuthors($team),
            'flash' => $_SESSION['sop_flash'] ?? null,
        ]));

        unset($_SESSION['sop_flash']);
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
