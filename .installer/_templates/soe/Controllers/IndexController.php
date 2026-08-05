<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Landing surface. Sends a signed-in user to their persona's home
 * (spec §3), and otherwise explains what this system is.
 */
final class IndexController extends SoeController
{
    public function index(): void
    {
        if ($this->keycloakEnabled() && $this->currentUser() !== null) {
            $home = $this->persona()->homePath();
            if ($home !== '/') {
                $this->redirect($home);

                return;
            }
        }

        echo $this->render('index', array_merge($this->baseData('/'), [
            'sso_ready' => $this->keycloakEnabled(),
            'signed_in' => $this->currentUser() !== null,
        ]));
    }
}
