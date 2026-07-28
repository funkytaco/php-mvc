<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Composer\Script\Event;

/**
 * DevTask - Generate the dev-mode compose overlay for an app, and commit
 * live app/ edits back to their source of truth.
 *
 * dev(): writes <app>-compose.dev.yml which bind-mounts served code from the
 * host and adds a code-server (VS Code web) sidecar. Container lifecycle is
 * NOT handled here: bin/nimbus dev applies the overlay via
 *   podman-compose -f <app>-compose.yml -f <app>-compose.dev.yml up --build -d
 *
 * commit(): dev-mode edits live in .installer/apps/<name>/ (each app's own
 * dir, served directly) — this copies the app-agnostic assets back to the
 * shared template so future nimbus:create runs include them.
 */
class DevTask extends BaseTask
{
    private AppManager $appManager;

    public function __construct()
    {
        $this->appManager = new AppManager();
    }

    public function execute(Event $event): void
    {
        $this->dev($event);
    }

    public function dev(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $appName = $args[0] ?? null;

        if (!$appName) {
            $apps = $this->appManager->listApps();
            if (empty($apps)) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first with: composer nimbus:create');
                return;
            }
            $appNames = array_keys($apps);
            $choice = $io->select('Select app for dev mode:', $appNames);
            $appName = $appNames[$choice];
        }

        try {
            $result = $this->appManager->generateDevCompose($appName);

            echo self::ansiFormat('SUCCESS', "Dev overlay written: " . basename($result['file']));
            echo self::ansiFormat('INFO', "Dev mode serves this app's own directory: .installer/apps/$appName/ — edit it locally or in code-server; changes are live, apps stay isolated, and edits survive installs.");
            echo self::ansiFormat('INFO', "🖥  code-server (VS Code web): http://localhost:{$result['port']}  password: {$result['password']}");
            echo self::ansiFormat('INFO', "Start with: bin/nimbus dev $appName   (or: podman-compose -f $appName-compose.yml -f $appName-compose.dev.yml up --build -d)");
            echo self::ansiFormat('INFO', "💡 Share your edits with future apps via: bin/nimbus commit $appName (copies them to the shared template)");
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to generate dev overlay: ' . $e->getMessage());
        }
    }

    public function commit(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        // --app-only is retired: dev mode now serves .installer/apps/<name>/
        // directly, so edits already live in the app's own copy — there is
        // nothing separate to copy "to the app".
        if (in_array('--app-only', $args, true)) {
            echo self::ansiFormat('INFO', '--app-only is no longer needed: dev mode edits .installer/apps/<app>/ directly, so your changes are already saved per-app. Committing to the shared template instead.');
            $args = array_values(array_filter($args, fn ($a) => $a !== '--app-only'));
        }

        $appName = $args[0] ?? null;

        if (!$appName) {
            $apps = $this->appManager->listApps();
            if (empty($apps)) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first with: composer nimbus:create');
                return;
            }
            $appNames = array_keys($apps);
            $choice = $io->select('Select app to commit edits from:', $appNames);
            $appName = $appNames[$choice];
        }

        try {
            $result = $this->appManager->commitAppToTemplate($appName);
            $committed = $result['committed'];
            $skipped = $result['skipped'];

            if (empty($committed) && empty($skipped)) {
                echo self::ansiFormat('WARNING', "Nothing to commit for '$appName' — no matching content for its asset map.");
                return;
            }

            if (!empty($committed)) {
                echo self::ansiFormat('SUCCESS', "Committed " . count($committed) . " asset path(s) from .installer/apps/$appName/ to the shared template:");
                foreach ($committed as $target) {
                    echo "  ✓ $target" . PHP_EOL;
                }
                echo self::ansiFormat('INFO', 'This updates the SHARED template — future nimbus:create runs for this template type will include these changes.');
            }
            if (!empty($skipped)) {
                echo self::ansiFormat('INFO', 'Skipped (per-app resolved values, never template material):');
                foreach ($skipped as $target) {
                    echo "  ⊘ $target" . PHP_EOL;
                }
            }
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Refusing to commit to template:')) {
                echo self::ansiFormat('WARNING', $e->getMessage());
                echo self::ansiFormat('INFO', "Nothing was written — this commit is all-or-nothing, so no other files were touched either.");
            } else {
                echo self::ansiFormat('ERROR', 'Failed to commit: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to commit: ' . $e->getMessage());
        }
    }
}
