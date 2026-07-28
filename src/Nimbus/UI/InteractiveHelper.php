<?php

namespace Nimbus\UI;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\Template\TemplateConfig;
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
                        $manager->install($appName);
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
                $manager->install($appName);
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
    
    private function showUsefulCommands(string $appName): void
    {
        echo self::ansiFormat('INFO', "💡 Other useful commands:");
        echo "  • composer nimbus:status            # Check app status" . PHP_EOL;
        echo "  • composer nimbus:down $appName     # Stop containers" . PHP_EOL;
        echo "  • composer nimbus:delete $appName   # Delete app" . PHP_EOL;
        echo "  • bin/nimbus dev $appName           # Live-edit dev mode + code-server (VS Code in browser)" . PHP_EOL;

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
        echo "     Dev mode live-mounts app/ + src/ and adds code-server (VS Code in browser)." . PHP_EOL;
        if (!$io->askConfirmation("     Start in live-edit dev mode? [y/N]: ", false)) {
            return null;
        }

        $composeCheck = AppManager::checkPodmanCompose();
        if (!($composeCheck['installed'] ?? false)) {
            echo self::ansiFormat('WARNING', 'podman-compose is not available here — starting without dev mode.');
            echo self::ansiFormat('INFO', "  Run this on your host later: bin/nimbus dev $appName");
            return null;
        }

        try {
            return $manager->generateDevCompose($appName);
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', '✗ Could not prepare dev overlay: ' . $e->getMessage());
            echo self::ansiFormat('INFO', "  Starting without dev mode. Try later: bin/nimbus dev $appName");
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
            echo "  Stop: bin/nimbus down $appName" . PHP_EOL;
            echo self::ansiFormat('INFO', "💡 app/ and src/ are live-mounted — edits apply without a rebuild.");
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
    public function displayDevModeInfo(string $appName): void
    {
        try {
            $manager = new AppManager();
            $config = $manager->loadAppConfig($appName);

            $port = $config['containers']['codeserver']['port'] ?? null;
            $password = $config['containers']['codeserver']['password'] ?? null;

            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🖥️  VS Code in browser (code-server):");

            if (!empty($port) && !empty($password)) {
                echo "  URL: http://localhost:$port" . PHP_EOL;
                echo "  Password: $password" . PHP_EOL;
            } else {
                echo "  URL: assigned when dev mode first starts" . PHP_EOL;
                echo "  Password: generated when dev mode first starts" . PHP_EOL;
            }

            echo "  Start: bin/nimbus dev $appName" . PHP_EOL;
            echo "  Stop:  bin/nimbus down $appName" . PHP_EOL;
            echo self::ansiFormat('INFO', "💡 Dev mode live-mounts app/ and src/ — edits apply without a rebuild.");
        } catch (\Exception $e) {
            // Non-fatal: dev mode info is advisory
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