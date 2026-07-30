<?php

namespace Nimbus\UI;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\App\AppManagerFactory;
use Nimbus\Template\TemplateConfig;
use Nimbus\UI\StepList;
use Nimbus\Vault\VaultManager;
use Composer\IO\IOInterface;

class InteractiveHelper extends BaseTask
{
    public function execute(\Composer\Script\Event $event): void
    {
        // Not used directly as a task
    }

    public function interactiveNextSteps(string $appName, IOInterface $io, AppManager $manager, array $features = [], bool $isNewApp = true): void
    {
        echo self::ansiFormat('INFO', "🚀 Next steps:");
        echo PHP_EOL;
        
        $wasRunning = false;
        if (!$isNewApp) {
            $apps = $manager->getStartableApps();
            $app = array_filter($apps, fn($a) => $a['name'] === $appName);
            if (!empty($app)) {
                $appInfo = array_values($app)[0];
                $wasRunning = $appInfo['is_running'] ?? false;
            }
        }
        
        $needsReinstall = false;
        $addedFeatures = [];
        
        if (!in_array('eda', $features) || !in_array('keycloak', $features)) {
            echo "  1. Optional enhancements" . PHP_EOL;
            
            if (!in_array('eda', $features)) {
                if ($io->askConfirmation("     Add Event-Driven Ansible (EDA)? [y/N]: ", false)) {
                    try {
                        $manager->addEda($appName);
                        echo self::ansiFormat('SUCCESS', "     ✓ EDA added successfully!");
                        $addedFeatures[] = 'eda';
                    } catch (\Exception $e) {
                        echo self::ansiFormat('ERROR', '     ✗ Failed to add EDA: ' . $e->getMessage());
                    }
                }
            }
            
            if (!in_array('keycloak', $features)) {
                if ($io->askConfirmation("     Add Keycloak SSO? [y/N]: ", false)) {
                    try {
                        $manager->addKeycloak($appName);
                        echo self::ansiFormat('SUCCESS', "     ✓ Keycloak added successfully!");
                        $addedFeatures[] = 'keycloak';
                    } catch (\Exception $e) {
                        echo self::ansiFormat('ERROR', '     ✗ Failed to add Keycloak: ' . $e->getMessage());
                    }
                }
            }
            
            echo PHP_EOL;
        }
        
        $allFeatures = array_unique(array_merge($features, $addedFeatures));

        echo PHP_EOL;
        echo "  2. Generate container configuration" . PHP_EOL;

        $installChoice = $io->ask("     Run 'composer nimbus:install $appName' now? [Y/n/edit] ('edit' reviews config first): ", 'y');
        $installChoice = strtolower(trim($installChoice));

        if ($installChoice === 'edit' || $installChoice === 'e') {
            echo PHP_EOL;
            $this->showConfigurationPreview($appName, $manager);

            echo self::ansiFormat('INFO', "📝 To edit configuration:");
            echo "  1. Edit: .installer/apps/$appName/app.nimbus.json" . PHP_EOL;
            echo "  2. Run: composer nimbus:install $appName" . PHP_EOL;
            echo "  3. Then: composer nimbus:up $appName" . PHP_EOL;
            echo PHP_EOL;
            
            $editor = getenv('EDITOR') ?: 'vim';
            $configPath = ".installer/apps/$appName/app.nimbus.json";
            if ($io->askConfirmation("     Open configuration in $editor? [Y/n]: ", true)) {
                system("$editor $configPath");
                echo PHP_EOL;
                if ($io->askConfirmation("     Configuration edited. Install now? [Y/n]: ", true)) {
                    try {
                        AppManagerFactory::forApp($appName)->install($appName);
                        echo self::ansiFormat('SUCCESS', "✓ App '$appName' installed successfully!");
                        echo self::ansiFormat('INFO', "  Container config generated: $appName-compose.yml");
                    } catch (\Exception $e) {
                        echo self::ansiFormat('ERROR', '✗ Failed to install app: ' . $e->getMessage());
                        return;
                    }
                } else {
                    echo self::ansiFormat('INFO', "  Skipped - run 'composer nimbus:install $appName' later");
                    $this->showRemainingSteps($appName, $allFeatures);
                    return;
                }
            } else {
                $this->showRemainingSteps($appName, $allFeatures);
                return;
            }
        } elseif ($installChoice === 'y' || $installChoice === 'yes' || $installChoice === '') {
            echo PHP_EOL;
            try {
                AppManagerFactory::forApp($appName)->install($appName);
                echo self::ansiFormat('SUCCESS', "✓ App '$appName' installed successfully!");
                echo self::ansiFormat('INFO', "  Container config generated: $appName-compose.yml");
            } catch (\Exception $e) {
                echo self::ansiFormat('ERROR', '✗ Failed to install app: ' . $e->getMessage());
                return;
            }
        } else {
            echo self::ansiFormat('INFO', "  Skipped - run 'composer nimbus:install $appName' later");
            $this->showRemainingSteps($appName, $allFeatures);
            return;
        }
        
        echo PHP_EOL;
        
        $actionVerb = ($wasRunning && !empty($addedFeatures)) ? "Restart" : "Start";
        echo "  3. $actionVerb containers" . PHP_EOL;
        
        if ($wasRunning && !empty($addedFeatures)) {
            echo self::ansiFormat('INFO', "     App needs restart to activate new features");
            if ($io->askConfirmation("     Restart app now? [Y/n]: ", true)) {
                // Decide dev mode BEFORE touching containers, so we restart once.
                $devInfo = $this->askDevMode($appName, $io, $manager);

                echo PHP_EOL;
                echo self::ansiFormat('INFO', "Stopping app...");

                try {
                    $manager->stopApp($appName, ['remove_volumes' => false, 'remove_containers' => false]);
                    echo self::ansiFormat('SUCCESS', "✓ App stopped");

                    echo self::ansiFormat('INFO', "Starting app with new configuration...");
                    $apps = $manager->getStartableApps();
                    $app = array_filter($apps, fn($a) => $a['name'] === $appName);

                    if (!empty($app)) {
                        $this->startApp(array_values($app)[0], $devInfo['file'] ?? null);
                        $this->showStartupSummary($appName, $allFeatures, $devInfo);
                    }
                } catch (\Exception $e) {
                    echo self::ansiFormat('ERROR', '✗ Failed to restart app: ' . $e->getMessage());
                }
            } else {
                echo self::ansiFormat('INFO', "  Skipped - restart manually with:");
                echo "     composer nimbus:down $appName && composer nimbus:up $appName" . PHP_EOL;
            }
        } else {
            if ($io->askConfirmation("     Run 'composer nimbus:up $appName' now? [Y/n]: ", true)) {
                // Decide dev mode BEFORE the (single) build.
                $devInfo = $this->askDevMode($appName, $io, $manager);

                echo PHP_EOL;

                $apps = $manager->getStartableApps();
                $app = array_filter($apps, fn($a) => $a['name'] === $appName);

                if (!empty($app)) {
                    $this->startApp(array_values($app)[0], $devInfo['file'] ?? null);
                    $this->showStartupSummary($appName, $allFeatures, $devInfo);
                } else {
                    echo self::ansiFormat('ERROR', '✗ Failed to find app details');
                }
            } else {
                echo self::ansiFormat('INFO', "  Skipped - run 'composer nimbus:up $appName' later");
                $this->showRemainingSteps($appName, $allFeatures);
                return;
            }
        }

        echo PHP_EOL;
        $this->showUsefulCommands($appName);
    }
    
    private function showRemainingSteps(string $appName, array $features): void
    {
        echo PHP_EOL;
        echo self::ansiFormat('INFO', "📋 Remaining steps:");
        echo "  • composer nimbus:install $appName   # Generate container configuration" . PHP_EOL;
        echo "  • composer nimbus:up $appName        # Start containers" . PHP_EOL;
        
        if (!in_array('eda', $features)) {
            echo "  • composer nimbus:add-eda $appName      # (Optional) Add Event-Driven Ansible" . PHP_EOL;
        }
        if (!in_array('keycloak', $features)) {
            echo "  • composer nimbus:add-keycloak $appName # (Optional) Add Keycloak SSO" . PHP_EOL;
        }
        
        echo PHP_EOL;
        $this->showUsefulCommands($appName);
    }
    
    /**
     * One command menu for an app, with commands that don't apply to its
     * current state struck out (dim + strikethrough) instead of hidden —
     * the user sees the whole lifecycle and where they are in it.
     * Used by nimbus:status <app>.
     */
    public function showCommandMenu(string $appName, bool $installed, bool $running, array $features): void
    {
        echo PHP_EOL;
        echo self::ansiFormat('INFO', "📋 Commands for '$appName':");

        $rows = [
            ["composer nimbus:install $appName", 'Generate container configuration', !$installed],
            ["composer nimbus:up $appName", 'Start containers', !$running],
            ["composer nimbus:down $appName", 'Stop containers', $running],
            ["composer nimbus:dev $appName", 'Live-edit dev mode + code-server (VS Code in browser)', !($features['dev'] ?? false)],
            ["composer nimbus:add-eda $appName", '(Optional) Add Event-Driven Ansible', !($features['eda'] ?? false)],
            ["composer nimbus:add-keycloak $appName", '(Optional) Add Keycloak SSO', !($features['keycloak'] ?? false)],
            ["composer nimbus:view $appName", 'Show URLs, credentials + container info', true],
            ["composer nimbus:delete $appName", 'Delete app', true],
        ];

        foreach ($rows as [$cmd, $desc, $available]) {
            $line = sprintf('  • %-42s # %s', $cmd, $desc);
            echo ($available ? $line : "\033[9;2m" . $line . "\033[0m") . PHP_EOL;
        }
    }

    private function showUsefulCommands(string $appName): void
    {
        echo self::ansiFormat('INFO', "💡 Other useful commands:");
        echo "  • composer nimbus:status            # Check app status" . PHP_EOL;
        echo "  • composer nimbus:down $appName     # Stop containers" . PHP_EOL;
        echo "  • composer nimbus:delete $appName   # Delete app" . PHP_EOL;
        echo "  • composer nimbus:dev $appName      # Live-edit dev mode + code-server (VS Code in browser)" . PHP_EOL;

        $setupHostsPath = ".installer/apps/$appName/dns-setup-$appName-hosts.sh";
        if (file_exists($setupHostsPath) && PHP_OS === 'Darwin') {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🌐 Setup local hostnames (macOS):");
            echo "  • chmod +x $setupHostsPath      # Make script executable" . PHP_EOL;
            echo "  • sudo ./$setupHostsPath         # Add .test hostnames to /etc/hosts" . PHP_EOL;
            echo "  • View network info: cat .installer/apps/$appName/podman-network.md" . PHP_EOL;
        }
    }
    
    /**
     * Ask whether to start in live-edit dev mode — BEFORE any containers run,
     * so the stack is built exactly once (with or without the dev overlay).
     *
     * Returns generateDevCompose()'s ['file', 'port', 'password'] when dev
     * mode was chosen and the overlay is ready, or null (declined, or
     * podman-compose unavailable — e.g. inside the nimbus-tools container,
     * where we print the host command instead of failing).
     */
    private function askDevMode(string $appName, IOInterface $io, AppManager $manager): ?array
    {
        echo "     Dev mode serves this app's own .installer/apps/ dir live-mounted (isolated per app) and adds code-server (VS Code in browser)." . PHP_EOL;
        if (!$io->askConfirmation("     Start in live-edit dev mode? [y/N]: ", false)) {
            return null;
        }

        $composeCheck = AppManager::checkPodmanCompose();
        if (!($composeCheck['installed'] ?? false)) {
            echo self::ansiFormat('WARNING', 'podman-compose is not available here — starting without dev mode.');
            echo self::ansiFormat('INFO', "  Run this on your host later: composer nimbus:dev $appName");
            return null;
        }

        try {
            return $manager->generateDevCompose($appName);
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', '✗ Could not prepare dev overlay: ' . $e->getMessage());
            echo self::ansiFormat('INFO', "  Starting without dev mode. Try later: composer nimbus:dev $appName");
            return null;
        }
    }

    /**
     * One consolidated "everything about your running app" block, printed
     * AFTER the single podman-compose up — URLs and credentials are the last
     * thing on screen instead of scrolling away under build output.
     *
     * @param ?array $devInfo generateDevCompose() result when dev mode was
     *                        started with this up; null otherwise.
     */
    private function showStartupSummary(string $appName, array $features, ?array $devInfo): void
    {
        try {
            $manager = new AppManager();
            $config = $manager->loadAppConfig($appName);
        } catch (\Exception $e) {
            $config = [];
        }

        $appPort = $config['containers']['app']['port'] ?? '8080';

        echo PHP_EOL;
        echo self::ansiFormat('SUCCESS', "🎉 App '$appName' is running!");
        echo PHP_EOL;
        echo self::ansiFormat('INFO', "🌐 Application: http://localhost:$appPort");

        if (in_array('keycloak', $features)) {
            $this->displayKeycloakCredentials($appName);
        }

        if (in_array('eda', $features)) {
            $edaPort = $config['containers']['eda']['port'] ?? '5000';
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "📡 EDA:");
            echo "  Webhook: http://localhost:$edaPort/webhook" . PHP_EOL;
            echo "  Rulebooks: .installer/apps/$appName/rulebooks/" . PHP_EOL;
        }

        if ($config['features']['database'] ?? false) {
            $dbConfig = $config['database'] ?? [];
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🗄️  Database:");
            echo "  Name: " . ($dbConfig['name'] ?? "{$appName}_db") . PHP_EOL;
            echo "  User: " . ($dbConfig['user'] ?? "{$appName}_user") . PHP_EOL;
            echo "  Password: " . (isset($dbConfig['password']) ? substr($dbConfig['password'], 0, 8) . '...' : '[generated]') . PHP_EOL;
        }

        if ($devInfo !== null) {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🖥️  VS Code in browser (code-server):");
            echo "  URL: http://localhost:{$devInfo['port']}" . PHP_EOL;
            echo "  Password: {$devInfo['password']}" . PHP_EOL;
            echo "  Stop: composer nimbus:down $appName" . PHP_EOL;
            echo self::ansiFormat('INFO', "💡 Edit this app in .installer/apps/ (locally or via code-server) — changes apply live, isolated from other apps.");
        } else {
            $this->displayDevModeInfo($appName);
        }
    }

    /**
     * Show live-edit dev mode (code-server) info.
     *
     * Unlike Keycloak/EDA this isn't a per-app feature flag — dev mode is
     * available for every app. code-server only runs under the dev overlay
     * (bin/nimbus dev), so this reports how to start/stop it. The password
     * is generated and persisted into app.nimbus.json the first time the
     * overlay is built, so it's only shown once it actually exists rather
     * than printing a value that isn't real yet.
     */
    /**
     * The commands that answer "what is this app actually configured with,
     * and what do I do next".
     *
     * Shown after create and by nimbus:view, from one place, so the two can
     * never drift into suggesting different things.
     *
     * @param array<string, mixed> $config the app's app.nimbus.json
     */
    public function displayAppCommands(string $appName, array $config, ?AppManager $manager = null): void
    {
        // Read-only introspection. Anything that *does* something — scanning,
        // starting a sidecar — is a step below, where it can be gated.
        $inspect = [
            ["composer nimbus:config $appName", 'app.nimbus.json as written on disk'],
            ["composer nimbus:env $appName", 'resolved environment (secrets masked)'],
            ["composer nimbus:vault-view $appName", 'the credentials behind it'],
        ];

        $width = max(array_map(static fn (array $row): int => strlen($row[0]), $inspect));

        echo PHP_EOL;
        echo self::ansiFormat('INFO', '🔍 Inspect:');
        $this->printCommands($inspect, $width);

        // Where the app stands right now, so the list below reads as what is
        // left to do rather than as things that might already be done.
        if ($manager !== null) {
            $this->displayAppStatus($appName, $manager);
        }

        echo PHP_EOL;
        echo self::ansiFormat('INFO', 'Next steps:');
        echo $this->buildAppSteps($appName, $config, $manager)->render();
    }

    /**
     * The remaining work for an app, with each step knowing whether it is
     * already done.
     *
     * Steps that Nimbus cannot know are needed — adding a database to a repo
     * that never mentions one, or dev mode — are marked optional so they never
     * take the "run this next" marker away from something that is required.
     *
     * @param array<string, mixed> $config
     */
    private function buildAppSteps(string $appName, array $config, ?AppManager $manager): StepList
    {
        $isGit = ($config['source']['kind'] ?? null) === 'git';
        $hasDatabase = $config['features']['database'] ?? false;
        $declaresDatabase = $config['source']['declares_database'] ?? false;

        $installed = false;
        $running = false;

        if ($manager !== null) {
            try {
                $installed = $manager->isInstalled($appName);
                $running = in_array($manager->describeApp($appName)['state'] ?? '', ['running', 'partial'], true);
            } catch (\Throwable $e) {
                // podman unavailable; the commands are still worth printing
            }
        }

        $steps = new StepList();

        if (!$hasDatabase) {
            $steps->add(
                "composer nimbus:add-db $appName",
                false,
                $declaresDatabase
                    ? 'this repo declares DB_* in .env.example — it needs one'
                    : 'optional — add a database (default mariadb:12)',
                !$declaresDatabase
            );
        }

        $steps->add(
            "composer nimbus:install $appName",
            $installed,
            $installed
                ? 'already done — compose' . ($isGit ? ' and .env are' : ' is') . ' current'
                : 'write compose' . ($isGit ? ' and .env' : '')
        );

        $steps->add(
            "composer nimbus:up $appName",
            $running,
            $running ? 'already running' : 'start the stack'
        );

        if ($isGit) {
            $devEnabled = $config['features']['dev'] ?? false;

            $steps->add(
                "bin/nimbus dev $appName",
                $devEnabled,
                $devEnabled
                    ? 'dev mode set up — code-server editor is part of this stack'
                    : 'optional — adds a code-server editor and live-edits the repo',
                true
            );
        }

        // Repeatable, so never "done" — but always worth offering.
        $steps->add(
            "composer nimbus:scan $appName",
            false,
            'optional — security scan in a throwaway container',
            true
        );

        return $steps;
    }

    /**
     * One-line "is this thing up yet" summary, from the same state vocabulary
     * nimbus:status uses.
     */
    public function displayAppStatus(string $appName, AppManager $manager): void
    {
        try {
            $row = $manager->describeApp($appName);
        } catch (\Throwable $e) {
            return;  // podman unavailable; the command list is still useful
        }

        if ($row === null) {
            return;
        }

        [$icon, $label, $detail] = match ($row['state']) {
            'created' => ['⚪', 'not installed', "no $appName-compose.yml yet"],
            'installed' => ['⚪', 'installed, not started', 'no containers created yet'],
            'stopped' => ['🔴', 'stopped', "{$row['total']} container(s) exist, none running"],
            'partial' => ['🟡', 'partially running', "{$row['running']}/{$row['total']} containers up"],
            'running' => ['🟢', 'running', "{$row['running']}/{$row['total']} containers up"],
            default => ['⚪', $row['state'], ''],
        };

        echo PHP_EOL;
        echo self::ansiFormat('INFO', "📊 Status: $label");

        if ($detail !== '') {
            echo "  $icon $detail" . PHP_EOL;
        }

        if (!empty($row['port'])) {
            $isUp = in_array($row['state'], ['running', 'partial'], true);
            echo '  🌐 http://localhost:' . $row['port'] . ($isUp ? '' : '  (once started)') . PHP_EOL;
        }
    }

    /**
     * @param array<int, array{0: string, 1: ?string}> $rows
     */
    private function printCommands(array $rows, int $width): void
    {
        foreach ($rows as [$command, $comment]) {
            echo $comment === null
                ? '  ' . $command . PHP_EOL
                : sprintf("  %-{$width}s  # %s\n", $command, $comment);
        }
    }

    public function displayDevModeInfo(string $appName): void
    {
        try {
            $manager = AppManagerFactory::forApp($appName);
            $config = $manager->loadAppConfig($appName);
        } catch (\Throwable $e) {
            return;  // Non-fatal: dev mode info is advisory
        }

        // features.dev is written by generateDevCompose, so it already records
        // "this app has a code-server sidecar". Reporting on one for an app
        // that never entered dev mode announced a container that does not
        // exist, and sent people to nimbus:up, which does not start one.
        if (!($config['features']['dev'] ?? false)) {
            return;
        }

        $port = $config['containers']['codeserver']['port'] ?? null;

        $state = trim(shell_exec("podman inspect $appName-code-server --format '{{.State.Status}}' 2>/dev/null") ?? '');
        $running = $state === 'running';

        echo PHP_EOL;
        echo self::ansiFormat('INFO', '🖥️  VS Code in browser (code-server):');
        echo '  URL: ' . (!empty($port) ? "http://localhost:$port" : '(assigned on first dev start)') . PHP_EOL;
        echo '  Password: composer nimbus:'
            . (!empty($config['containers']['codeserver']['password']) ? 'config' : 'vault-view')
            . " $appName" . PHP_EOL;

        if ($running) {
            echo "  Stop: composer nimbus:down $appName" . PHP_EOL;
        } else {
            echo self::ansiFormat(
                'NOTICE',
                "code-server is not running — start dev mode with: bin/nimbus dev $appName"
            );
        }

        $served = ($config['source']['kind'] ?? null) === 'git'
            ? "the repository clone in .installer/repos/" . ($config['source']['repo'] ?? '')
            : "the app's own .installer/apps/$appName dir";

        echo self::ansiFormat('INFO', "💡 Dev mode serves $served — edits apply live, isolated per app.");
    }

    /**
     * What credentials this app has and which command reveals each.
     *
     * Values are deliberately never printed: `nimbus:view` is the command
     * people run while screen-sharing or paste into an issue.
     *
     * @param array<string, mixed> $config
     */
    public function displayCredentials(string $appName, array $config): void
    {
        $entry = null;

        try {
            $vault = new VaultManager();
            if ($vault->isInitialized()) {
                $entry = $vault->restoreAppCredentials($appName);
            }
        } catch (\Throwable $e) {
            $entry = null;  // an unreadable vault is not worth failing view over
        }

        // Gated on what the app actually uses, not on what the vault happens
        // to hold: passwords are generated for every service whether or not
        // the app enabled it, so presence in the vault says nothing about
        // whether a credential is live.
        $rows = [];

        if ($config['features']['database'] ?? false) {
            if (!empty($entry['database']['password'])) {
                $rows[] = ['database password', "composer nimbus:vault-view $appName"];
            }
            if (!empty($entry['database']['root_password'])) {
                $rows[] = ['database root password', "composer nimbus:vault-view $appName"];
            }
        }
        if (!empty($entry['nimbus'])) {
            $rows[] = ['environment secrets', "composer nimbus:env $appName --show-secrets"];
        }
        if (($config['features']['keycloak'] ?? false) && !empty($entry['keycloak'])) {
            $rows[] = ['keycloak admin + client secret', "composer nimbus:vault-view $appName"];
        }
        // Kept in the vault for apps that have one, and in app.nimbus.json for
        // those that do not — point at whichever actually holds it.
        if (!empty($config['containers']['codeserver']['password'])) {
            $rows[] = ['code-server password', "composer nimbus:config $appName"];
        } elseif ($config['features']['dev'] ?? false) {
            $rows[] = ['code-server password', "composer nimbus:vault-view $appName"];
        }

        if ($rows === []) {
            return;
        }

        $width = max(array_map(static fn (array $row): int => strlen($row[0]), $rows));

        echo PHP_EOL;
        echo self::ansiFormat('INFO', '🔐 Credentials (values are never printed here):');

        foreach ($rows as [$label, $command]) {
            printf("  %-{$width}s  %s\n", $label, $command);
        }
    }
    
    public function displayKeycloakCredentials(string $appName): void
    {
        try {
            $manager = new AppManager();
            $config = $manager->loadAppConfig($appName);
            
            if (!isset($config['features']['keycloak']) || !$config['features']['keycloak']) {
                return;
            }
            
            $containerName = $appName . '-keycloak';
            $inspectCmd = "podman inspect $containerName --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | grep KEYCLOAK_ADMIN_PASSWORD | cut -d'=' -f2";
            $adminPassword = trim(shell_exec($inspectCmd));
            
            echo PHP_EOL;
            $keycloakPort = $config['containers']['keycloak']['port'] ?? '8080';
            echo self::ansiFormat('INFO', "🔐 Keycloak Admin Console Access:");
            echo "  URL: http://localhost:$keycloakPort" . PHP_EOL;
            echo "  Username: admin" . PHP_EOL;
            
            if (!empty($adminPassword)) {
                echo "  Password: $adminPassword" . PHP_EOL;
            } else {
                echo "  Password: (use command below to retrieve)" . PHP_EOL;
            }
            
            echo "  Config UI: http://localhost:" . ($config['containers']['app']['port'] ?? '8080') . "/auth/configure" . PHP_EOL;
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "💡 To retrieve admin password later, run:");
            echo "  podman inspect $containerName --format '{{range .Config.Env}}{{println .}}{{end}}' | grep KEYCLOAK_ADMIN_PASSWORD | cut -d'=' -f2" . PHP_EOL;

        } catch (\Exception $e) {
            // Silently fail
        }
    }
    
    public function showConfigurationPreview(string $appName, AppManager $manager): void
    {
        echo self::ansiFormat('INFO', "📋 Configuration Preview for '$appName':");
        echo PHP_EOL;
        
        try {
            $config = $manager->loadAppConfig($appName);
            $appDir = ".installer/apps/$appName";
            
            echo self::ansiFormat('INFO', "🔧 Basic Configuration:");
            echo "  • App Name: $appName" . PHP_EOL;
            $templateConfig = TemplateConfig::getInstance();
            echo "  • Template: " . ($config['type'] ?? $templateConfig->getDefaultTemplate()) . PHP_EOL;
            echo "  • Version: " . ($config['version'] ?? '1.0.0') . PHP_EOL;
            echo "  • Location: $appDir" . PHP_EOL;
            echo PHP_EOL;
            
            echo self::ansiFormat('INFO', "🐳 Containers to be created:");
            $containers = [];
            
            $appPort = $config['containers']['app']['port'] ?? '8080';
            $containers[] = ['name' => "$appName-app", 'type' => 'PHP/Apache', 'port' => $appPort];
            
            if (isset($config['features']['database']) && $config['features']['database']) {
                $dbEngine = $config['containers']['db']['engine'] ?? 'postgres';
                $dbVersion = $config['containers']['db']['version'] ?? '14';
                $containers[] = ['name' => "$appName-db", 'type' => "$dbEngine:$dbVersion", 'port' => '5432 (internal)'];
            }
            
            if (isset($config['features']['eda']) && $config['features']['eda']) {
                $edaPort = $config['containers']['eda']['port'] ?? '5000';
                $containers[] = ['name' => "$appName-eda", 'type' => 'Event-Driven Ansible', 'port' => $edaPort];
            }
            
            if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
                $keycloakPort = $config['containers']['keycloak']['port'] ?? '8080';
                $containers[] = ['name' => "$appName-keycloak", 'type' => 'Keycloak SSO', 'port' => $keycloakPort];
                $containers[] = ['name' => "$appName-keycloak-db", 'type' => 'postgres:14', 'port' => '5433 (internal)'];
            }
            
            $maxNameLen = max(array_map(fn($c) => strlen($c['name']), $containers));
            $maxTypeLen = max(array_map(fn($c) => strlen($c['type']), $containers));
            
            foreach ($containers as $container) {
                $name = str_pad($container['name'], $maxNameLen);
                $type = str_pad($container['type'], $maxTypeLen);
                echo "  • $name  │  $type  │  Port: {$container['port']}" . PHP_EOL;
            }
            echo PHP_EOL;
            
            if (isset($config['features']['database']) && $config['features']['database']) {
                echo self::ansiFormat('INFO', "🗄️  Database Configuration:");
                $dbConfig = $config['database'] ?? [];
                echo "  • Database Name: " . ($dbConfig['name'] ?? "{$appName}_db") . PHP_EOL;
                echo "  • Database User: " . ($dbConfig['user'] ?? "{$appName}_user") . PHP_EOL;
                echo "  • Password: " . (isset($dbConfig['password']) ? substr($dbConfig['password'], 0, 8) . '...' : '[Generated]') . PHP_EOL;
                echo PHP_EOL;
            }
            
            echo self::ansiFormat('INFO', "✨ Features Enabled:");
            $features = $config['features'] ?? [];
            foreach ($features as $feature => $enabled) {
                if ($enabled) {
                    $icon = match($feature) {
                        'database' => '🗄️',
                        'eda' => '📡',
                        'keycloak' => '🔐',
                        'certbot' => '🔒',
                        default => '✓'
                    };
                    echo "  $icon " . ucfirst($feature) . PHP_EOL;
                }
            }
            echo PHP_EOL;
            
            echo self::ansiFormat('INFO', "🌐 URLs after startup:");
            echo "  • Application: http://localhost:$appPort" . PHP_EOL;
            if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
                $keycloakPort = $config['containers']['keycloak']['port'] ?? '8080';
                echo "  • Keycloak Admin: http://localhost:$keycloakPort" . PHP_EOL;
                echo "  • Keycloak Config: http://localhost:$appPort/auth/configure" . PHP_EOL;
            }
            if (isset($config['features']['eda']) && $config['features']['eda']) {
                $edaPort = $config['containers']['eda']['port'] ?? '5000';
                echo "  • EDA Webhook: http://localhost:$edaPort/webhook" . PHP_EOL;
            }
            echo PHP_EOL;
            
            echo self::ansiFormat('INFO', "📄 Docker Compose File:");
            echo "  • $appName-compose.yml" . PHP_EOL;
            echo PHP_EOL;
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to load configuration: ' . $e->getMessage());
        }
    }
    
    /**
     * Bring the app up with a single podman-compose invocation.
     *
     * @param ?string $devOverlayFile When set, appended as a second -f so the
     *                                dev overlay (live mounts + code-server)
     *                                is part of the same single build.
     */
    private function startApp(array $app, ?string $devOverlayFile = null): void
    {
        $appName = $app['name'];
        $composeFile = $app['compose_file'];

        // Already-running short-circuit only applies when we're not changing
        // the stack shape — applying a dev overlay requires the up to run.
        if ($app['is_running'] && $app['health_status'] === 'healthy' && $devOverlayFile === null) {
            echo self::ansiFormat('INFO', "App '$appName' is already running and healthy!");
            $this->showAppStatus($app);
            return;
        }

        $composeArgs = "-f $composeFile" . ($devOverlayFile !== null ? " -f $devOverlayFile" : '');

        echo self::ansiFormat('INFO', "Building app '$appName' image...");
        $buildCommand = "podman-compose $composeArgs up --build -d";

        echo self::ansiFormat('INFO', "Running: $buildCommand");
        $output = shell_exec($buildCommand . ' 2>&1');

        if ($output) {
            echo $output;
        }

        $statusOutput = shell_exec("podman-compose $composeArgs ps --format table 2>/dev/null");
        if ($statusOutput) {
            echo self::ansiFormat('SUCCESS', "App '$appName' started successfully!");
            echo $statusOutput;
        }

        $this->warnAboutMissingContainers($appName, $composeFile, $devOverlayFile);
    }

    /**
     * Warn about containers the compose file declares but that aren't running.
     *
     * podman-compose prints per-service failures mid-build (e.g. an image that
     * needs a registry login) and still exits 0, so a stack can come up
     * half-broken while reporting success. Compare declared vs. actual and say
     * so plainly, with the container's own error as evidence.
     */
    private function warnAboutMissingContainers(string $appName, string $composeFile, ?string $devOverlayFile = null): void
    {
        $expected = array_merge(
            $this->readComposeContainerNames($composeFile),
            $devOverlayFile !== null ? $this->readComposeContainerNames($devOverlayFile) : []
        );
        if (empty($expected)) {
            return;
        }

        $runningRaw = shell_exec("podman ps --format '{{.Names}}' 2>/dev/null") ?: '';
        $running = array_filter(array_map('trim', explode("\n", $runningRaw)));

        $missing = array_values(array_diff(array_unique($expected), $running));
        if (empty($missing)) {
            return;
        }

        echo PHP_EOL;
        echo self::ansiFormat('WARNING', '⚠️  Some containers are NOT running:');
        foreach ($missing as $container) {
            echo "  • $container" . PHP_EOL;
            $logs = trim(shell_exec("podman logs --tail 3 " . escapeshellarg($container) . " 2>&1") ?: '');
            if ($logs !== '' && stripos($logs, 'no such container') === false) {
                foreach (explode("\n", $logs) as $line) {
                    echo "      " . trim($line) . PHP_EOL;
                }
            } else {
                echo "      (container was never created — image pull or config error; scroll up for details)" . PHP_EOL;
            }
        }
        echo self::ansiFormat('INFO', "  Retry after fixing: composer nimbus:up $appName");
    }

    /**
     * Extract container_name values from a compose file.
     *
     * Deliberately reads the generated compose rather than app.nimbus.json:
     * the compose file is what `up` actually acted on, so it covers the dev
     * overlay's code-server too.
     *
     * @return string[]
     */
    private function readComposeContainerNames(string $composeFile): array
    {
        if (!is_file($composeFile)) {
            return [];
        }
        $names = [];
        foreach (explode("\n", file_get_contents($composeFile)) as $line) {
            if (preg_match('/^\s*container_name:\s*"?([^"\s]+)"?\s*$/', $line, $m)) {
                $names[] = $m[1];
            }
        }
        return $names;
    }
    
    private function showAppStatus(array $app): void
    {
        foreach ($app['containers'] as $containerName => $status) {
            $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
            $healthIcon = $this->getHealthIcon($status['health']);
            echo "  └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
        }
    }
    
    private function getHealthIcon(string $health): string
    {
        switch ($health) {
            case 'healthy':
                return '✅';
            case 'unhealthy':
                return '❌';
            case 'starting':
                return '🔄';
            case 'none':
                return '➖';
            default:
                return '❓';
        }
    }
}