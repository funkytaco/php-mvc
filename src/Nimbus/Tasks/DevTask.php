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
 * commit(): app/ is gitignored and gets overwritten by the next
 * nimbus:install — this copies whatever was live-edited there (directly, or
 * via the code-server sidecar) back out to the template so it survives.
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
            if (!is_file(getcwd() . '/app/app.config.php')) {
                echo self::ansiFormat('WARNING', "app/ is not installed yet — run nimbus:install $appName first (bin/nimbus dev does this automatically).");
            }

            $result = $this->appManager->generateDevCompose($appName);

            echo self::ansiFormat('SUCCESS', "Dev overlay written: " . basename($result['file']));
            echo self::ansiFormat('INFO', 'Dev mode mounts app/, src/, public/index.php and html/assets from the host — edits are live, no rebuild.');
            echo self::ansiFormat('INFO', "🖥  code-server (VS Code web): http://localhost:{$result['port']}  password: {$result['password']}");
            echo self::ansiFormat('INFO', "Start with: bin/nimbus dev $appName   (or: podman-compose -f $appName-compose.yml -f $appName-compose.dev.yml up --build -d)");
            echo self::ansiFormat('WARNING', "app/ is gitignored and gets overwritten by the next install — run 'bin/nimbus commit $appName' to save edits back to the template before deleting this app or reinstalling.");
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to generate dev overlay: ' . $e->getMessage());
        }
    }

    public function commit(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $appOnly = in_array('--app-only', $args, true);
        $args = array_values(array_filter($args, fn ($a) => $a !== '--app-only'));

        $appName = $args[0] ?? null;

        if (!$appName) {
            $apps = $this->appManager->listApps();
            if (empty($apps)) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first with: composer nimbus:create');
                return;
            }
            $appNames = array_keys($apps);
            $choice = $io->select('Select app to commit app/ edits from:', $appNames);
            $appName = $appNames[$choice];
        }

        try {
            $result = $this->appManager->commitAppToTemplate($appName, !$appOnly);
            $committed = $result['committed'];
            $skipped = $result['skipped'];

            if (empty($committed) && empty($skipped)) {
                echo self::ansiFormat('WARNING', "Nothing to commit for '$appName' — app/ may not be installed, or has no matching content for its asset map.");
                return;
            }

            $destination = $appOnly
                ? ".installer/apps/$appName/"
                : '.installer/_templates/<template>/ (shared template)';

            if (!empty($committed)) {
                echo self::ansiFormat('SUCCESS', "Committed " . count($committed) . " asset path(s) from app/ to $destination:");
                foreach ($committed as $target) {
                    echo "  ✓ $target" . PHP_EOL;
                }
            }
            if (!empty($skipped)) {
                echo self::ansiFormat('INFO', 'Skipped (resolved per-app values, not safe for a shared template):');
                foreach ($skipped as $target) {
                    echo "  ⊘ $target" . PHP_EOL;
                }
                echo self::ansiFormat('INFO', "To copy these too, use: bin/nimbus commit $appName --app-only (writes only to this app's own .installer/apps/ copy)");
            }
            if (!$appOnly && !empty($committed)) {
                echo self::ansiFormat('INFO', 'This updates the SHARED template — future nimbus:create runs for this template type will include these changes.');
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
