<?php

declare(strict_types=1);

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\UI\InteractiveHelper;
use Composer\Script\Event;

class ContainerTask extends BaseTask
{
    private AppManager $appManager;
    private InteractiveHelper $interactiveHelper;

    public function __construct()
    {
        $this->appManager = new AppManager();
        $this->interactiveHelper = new InteractiveHelper();
    }

    public function execute(Event $event): void
    {
        // Determine which operation to perform based on context
        $this->status($event);
    }

    public function up(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $composeCheck = AppManager::checkPodmanCompose();
        if (!$composeCheck['installed']) {
            echo self::ansiFormat('ERROR', $composeCheck['error']);
            return;
        }
        
        echo self::ansiFormat('INFO', "Using {$composeCheck['version']}");
        
        try {
            $startableApps = $this->appManager->getStartableApps();
            $targetApp = $args[0] ?? null;

            if ($targetApp) {
                $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);

                // App was created but never installed — offer to install now
                if (empty($app) && $this->appManager->appExists($targetApp)) {
                    if (!$io->askConfirmation("App '$targetApp' exists but is not installed. Install it now? [Y/n] ", true)) {
                        echo self::ansiFormat('INFO', "Skipped - run 'composer nimbus:install $targetApp' later");
                        return;
                    }
                    $this->appManager->install($targetApp);
                    echo self::ansiFormat('SUCCESS', "App '$targetApp' installed successfully!");
                    echo self::ansiFormat('INFO', "Container config generated: $targetApp-compose.yml");
                    $startableApps = $this->appManager->getStartableApps();
                    $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);
                }

                if (empty($app)) {
                    echo self::ansiFormat('ERROR', "App '$targetApp' not found or not installed.");
                    return;
                }
                $app = array_values($app)[0];
                $this->startApp($app);
                return;
            }

            if (empty($startableApps)) {
                echo self::ansiFormat('INFO', 'No apps found with compose files.');
                echo self::ansiFormat('INFO', 'Create and install an app first:');
                echo "  1. composer nimbus:create my-app" . PHP_EOL;
                echo "  2. composer nimbus:install my-app" . PHP_EOL;
                return;
            }
            
            echo self::ansiFormat('INFO', 'Available apps to start:');
            $choices = [];
            $index = 1;
            
            foreach ($startableApps as $app) {
                $imageStatus = $app['has_image'] ? '✓ built' : '✗ not built';
                $runningStatus = $this->formatRunningStatus($app);
                $healthStatus = $this->formatHealthStatus($app);
                
                echo "  [$index] {$app['name']} ($imageStatus, $runningStatus, $healthStatus)" . PHP_EOL;
                
                if ($app['is_running']) {
                    foreach ($app['containers'] as $containerName => $status) {
                        $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                        $healthIcon = $this->getHealthIcon($status['health']);
                        echo "      └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
                    }
                }
                
                $choices[$index] = $app;
                $index++;
            }
            
            $choice = $io->ask('Select app to start (number): ');
            
            if (!isset($choices[(int)$choice])) {
                echo self::ansiFormat('ERROR', 'Invalid selection.');
                return;
            }
            
            $selectedApp = $choices[(int)$choice];
            $this->startApp($selectedApp);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to start app: ' . $e->getMessage());
        }
    }

    public function down(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $composeCheck = AppManager::checkPodmanCompose();
        if (!$composeCheck['installed']) {
            echo self::ansiFormat('ERROR', $composeCheck['error']);
            return;
        }
        
        echo self::ansiFormat('INFO', "Using {$composeCheck['version']}");
        
        try {
            $runningApps = $this->appManager->getRunningApps();
            
            if (empty($runningApps)) {
                echo self::ansiFormat('INFO', 'No running apps found.');
                return;
            }
            
            $targetApp = $args[0] ?? null;
            
            if ($targetApp) {
                $app = array_filter($runningApps, fn($a) => $a['name'] === $targetApp);
                if (empty($app)) {
                    echo self::ansiFormat('ERROR', "App '$targetApp' is not running or not found.");
                    return;
                }
                $app = array_values($app)[0];
                $this->stopApp($this->appManager, $app, $io);
                return;
            }
            
            echo self::ansiFormat('INFO', 'Running apps:');
            $choices = [];
            $index = 1;
            
            foreach ($runningApps as $app) {
                $runningStatus = $this->formatRunningStatus($app);
                $healthStatus = $this->formatHealthStatus($app);
                
                echo "  [$index] {$app['name']} ($runningStatus, $healthStatus)" . PHP_EOL;
                
                foreach ($app['containers'] as $containerName => $status) {
                    $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                    $healthIcon = $this->getHealthIcon($status['health']);
                    echo "      └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
                }
                
                $choices[$index] = $app;
                $index++;
            }
            
            echo "  [all] Stop all running apps" . PHP_EOL;
            
            $choice = $io->ask('Select app to stop (number or "all"): ');
            
            if (strtolower($choice ?? '') === 'all') {
                $this->stopAllApps($this->appManager, $runningApps, $io);
                return;
            }
            
            if (!isset($choices[(int)$choice])) {
                echo self::ansiFormat('ERROR', 'Invalid selection.');
                return;
            }
            
            $selectedApp = $choices[(int)$choice];
            $this->stopApp($this->appManager, $selectedApp, $io);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to stop app: ' . $e->getMessage());
        }
    }

    public function status(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $composeCheck = AppManager::checkPodmanCompose();
        if (!$composeCheck['installed']) {
            echo self::ansiFormat('ERROR', $composeCheck['error']);
            return;
        }
        
        echo self::ansiFormat('INFO', "Using {$composeCheck['version']}");
        
        $startableApps = $this->appManager->getStartableApps();
        $targetApp = $args[0] ?? null;

        if ($targetApp) {
            $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);

            if (empty($app) && !$this->appManager->appExists($targetApp)) {
                echo self::ansiFormat('ERROR', "App '$targetApp' not found.");
                return;
            }

            $installed = !empty($app);
            $running = false;

            echo self::ansiFormat('INFO', 'App Status:');
            if ($installed) {
                $app = array_values($app)[0];
                $running = $app['is_running'];
                $imageStatus = $app['has_image'] ? '✓ built' : '✗ not built';
                $runningStatus = $this->formatRunningStatus($app);
                $healthStatus = $this->formatHealthStatus($app);
                echo "  • {$app['name']} ($imageStatus, $runningStatus, $healthStatus)" . PHP_EOL;

                if ($app['is_running']) {
                    foreach ($app['containers'] as $containerName => $status) {
                        $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                        $healthIcon = $this->getHealthIcon($status['health']);
                        echo "      └─ $stateIcon $containerName: {$status['state']} $healthIcon" . PHP_EOL;
                    }
                }
            } else {
                echo "  • $targetApp (created, not installed)" . PHP_EOL;
            }

            try {
                $features = $this->appManager->loadAppConfig($targetApp)['features'] ?? [];
            } catch (\Exception $e) {
                $features = [];
            }

            $this->interactiveHelper->showCommandMenu($targetApp, $installed, $running, $features);
            return;
        }

        if (empty($startableApps)) {
            echo self::ansiFormat('INFO', 'No apps found with compose files.');
            echo self::ansiFormat('INFO', 'Create and install an app first:');
            echo "  1. composer nimbus:create my-app" . PHP_EOL;
            echo "  2. composer nimbus:install my-app" . PHP_EOL;
            return;
        }

        echo self::ansiFormat('INFO', 'App Status:');
        
        foreach ($startableApps as $app) {
            $imageStatus = $app['has_image'] ? '✓ built' : '✗ not built';
            $runningStatus = $this->formatRunningStatus($app);
            $healthStatus = $this->formatHealthStatus($app);
            
            echo "  • {$app['name']} ($imageStatus, $runningStatus, $healthStatus)" . PHP_EOL;
            
            if ($app['is_running']) {
                foreach ($app['containers'] as $containerName => $status) {
                    $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                    $healthIcon = $this->getHealthIcon($status['health']);
                    echo "      └─ $stateIcon $containerName: {$status['state']} $healthIcon" . PHP_EOL;
                }
            }
        }
    }

    private function startApp(array $app): void
    {
        $appName = $app['name'];
        // Feature-derived file list (base + overlays); a feature container
        // missing from a running app means health is 'partial', so the
        // already-running shortcut below won't skip starting it
        $composeFlags = '-f ' . implode(' -f ', $this->appManager->getComposeFiles($appName));

        if ($app['is_running'] && $app['health_status'] === 'healthy') {
            echo self::ansiFormat('INFO', "App '$appName' is already running and healthy!");
            $this->showAppStatus($app);
            return;
        }

        echo self::ansiFormat('INFO', "Building app '$appName' image...");
        $buildCommand = "podman-compose $composeFlags up --build -d";

        echo self::ansiFormat('INFO', "Running: $buildCommand");
        $output = shell_exec($buildCommand . ' 2>&1');

        if ($output) {
            echo $output;
        }

        $statusOutput = shell_exec("podman-compose $composeFlags ps --format table 2>/dev/null");
        if ($statusOutput) {
            echo self::ansiFormat('SUCCESS', "App '$appName' started successfully!");
            echo $statusOutput;
            
            // Display comprehensive app information
            $this->displayAppDetails($appName);
            
            $this->interactiveHelper->displayKeycloakCredentials($appName);
        }
    }

    /**
     * nimbus:view <app> — everything nimbus:up dumps after startup (URLs,
     * credentials, feature endpoints) plus a dev-centric container listing
     * (state, image URI, ports), on demand. Data comes from
     * AppManager::describeContainers() and the shared display helpers, so a
     * new feature container added to the ecosystem shows up here without
     * this method changing.
     */
    public function view(Event $event): void
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
            $choice = $io->select('Select app to view:', $appNames, 0);
            $appName = $appNames[$choice];
        }

        if (!$this->appManager->appExists($appName)) {
            echo self::ansiFormat('ERROR', "App '$appName' not found.");
            return;
        }

        echo self::ansiFormat('INFO', "📦 Containers for '$appName':");
        foreach ($this->appManager->describeContainers($appName) as $c) {
            if (!$c['exists']) {
                $icon = '⚪';
            } elseif (strpos($c['status'], 'Up') === 0) {
                $icon = '🟢';
            } else {
                $icon = '🔴';
            }
            $note = $c['expected'] ? '' : '  (orphan: feature disabled, still in project)';
            echo "  $icon {$c['name']}: {$c['status']}$note" . PHP_EOL;
            if ($c['exists']) {
                echo "       image: {$c['image']}" . PHP_EOL;
                if ($c['ports'] !== '') {
                    echo "       ports: {$c['ports']}" . PHP_EOL;
                }
            }
        }

        $this->displayAppDetails($appName);
        $this->interactiveHelper->displayDevModeInfo($appName);
        $this->interactiveHelper->displayKeycloakCredentials($appName);
    }

    /**
     * Display comprehensive app details after successful startup
     * Generic implementation - reads app config to determine what to show
     */
    private function displayAppDetails(string $appName): void
    {
        echo PHP_EOL;
        echo self::ansiFormat('INFO', "📋 App Details:");
        
        try {
            // Load app configuration to get details
            $appConfig = $this->appManager->loadAppConfig($appName);
            $appConfigPhp = $this->loadAppConfigPhp($appName);
            
            // Show app URL
            $appPort = $appConfig['containers']['app']['port'] ?? '8080';
            echo "  🌐 App URL: http://localhost:$appPort" . PHP_EOL;
            
            // Show database connection info
            if ($appConfig['features']['database'] ?? true) {
                $dbName = $appConfig['database']['name'] ?? ($appName . '_db');
                $dbUser = $appConfig['database']['user'] ?? ($appName . '_user');
                echo "  📊 Database: $dbName (user: $dbUser)" . PHP_EOL;
                echo "  🐘 Postgres container: $appName-postgres" . PHP_EOL;
            }
            
            // Show EDA info if enabled
            if ($appConfig['features']['eda'] ?? false) {
                $edaPort = $this->appManager->getServicePort($appName, 'eda');
                echo "  🔄 EDA endpoint: http://localhost:$edaPort" . PHP_EOL;
                echo "  📂 EDA container: $appName-eda" . PHP_EOL;
            }
            
            // Show enabled features
            $features = [];
            foreach ($appConfig['features'] ?? [] as $feature => $enabled) {
                if ($enabled) {
                    $features[] = $feature;
                }
            }
            if (!empty($features)) {
                echo "  ✅ Features: " . implode(', ', $features) . PHP_EOL;
            }
            
            // Show DNS setup instructions if DNS script exists
            $this->displayDnsInstructions($appName);
            
        } catch (\Exception $e) {
            echo "  ⚠️  Could not load app details: " . $e->getMessage() . PHP_EOL;
        }
    }
    
    /**
     * Display DNS setup instructions if DNS script exists
     */
    private function displayDnsInstructions(string $appName): void
    {
        $dnsScriptPath = getcwd() . "/dns-setup-$appName-hosts.sh";
        
        if (file_exists($dnsScriptPath)) {
            echo "  🌍 DNS Setup: sudo ./dns-setup-$appName-hosts.sh" . PHP_EOL;
        }
    }
    
    /**
     * Load app.config.php (separate from app.nimbus.json)
     */
    private function loadAppConfigPhp(string $appName): ?array
    {
        $configFile = getcwd() . "/.installer/apps/$appName/app.config.php";
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        return include $configFile;
    }
    
    private function stopApp($manager, array $app, $io): void
    {
        $appName = $app['name'];
        
        $options = [
            'timeout' => 10
        ];
        
        echo self::ansiFormat('INFO', "Stopping app '$appName'...");
        
        $results = $manager->stopApp($appName, $options);
        
        if ($results['stopped']) {
            echo self::ansiFormat('SUCCESS', "App '$appName' stopped successfully!");
            
            if ($results['removed']) {
                echo self::ansiFormat('INFO', "✓ Containers removed");
            }
            if ($results['cleaned']) {
                echo self::ansiFormat('INFO', "✓ Images removed");
            }
        }
        
        if ($results['output'] && (strpos($results['output'], 'Error') !== false || strpos($results['output'], 'error') !== false)) {
            echo self::ansiFormat('WARNING', "Output:");
            echo $results['output'];
        }
    }

    private function stopAllApps($manager, array $runningApps, $io): void
    {
        $confirmed = $io->askConfirmation("Stop all " . count($runningApps) . " running apps? [y/N]: ", false);
        
        if (!$confirmed) {
            echo self::ansiFormat('INFO', 'Operation cancelled.');
            return;
        }
        
        $removeVolumes = $io->askConfirmation('Remove volumes for all apps? [y/N]: ', false);
        $removeContainers = $io->askConfirmation('Remove containers for all apps? [y/N]: ', false);
        $removeImages = $io->askConfirmation('Remove images for all apps? [y/N]: ', false);
        
        $options = [
            'remove_volumes' => $removeVolumes,
            'remove_containers' => $removeContainers,
            'remove_images' => $removeImages,
            'timeout' => 10
        ];
        
        $stopped = 0;
        $failed = 0;
        
        foreach ($runningApps as $app) {
            try {
                echo self::ansiFormat('INFO', "Stopping {$app['name']}...");
                $results = $manager->stopApp($app['name'], $options);
                
                if ($results['stopped']) {
                    $stopped++;
                    echo self::ansiFormat('SUCCESS', "✓ {$app['name']} stopped");
                } else {
                    $failed++;
                    echo self::ansiFormat('ERROR', "✗ Failed to stop {$app['name']}");
                }
            } catch (\Exception $e) {
                $failed++;
                echo self::ansiFormat('ERROR', "✗ Error stopping {$app['name']}: " . $e->getMessage());
            }
        }
        
        echo self::ansiFormat('SUCCESS', "Stopped $stopped apps" . ($failed > 0 ? ", $failed failed" : ""));
    }

    private function formatRunningStatus(array $app): string
    {
        if (!$app['is_running']) {
            return '⏹️ stopped';
        }
        
        $running = $app['running_count'] ?? 0;
        $total = $app['total_count'] ?? 0;
        
        if ($total === 0 && !empty($app['containers'])) {
            $total = count($app['containers']);
            $running = 0;
            foreach ($app['containers'] as $container) {
                if ($container['state'] === 'running') {
                    $running++;
                }
            }
        }
        
        if ($running === $total) {
            return "▶️ running ($running/$total)";
        } else {
            return "⚠️ partial ($running/$total)";
        }
    }

    private function formatHealthStatus(array $app): string
    {
        switch ($app['health_status']) {
            case 'healthy':
                return '✅ healthy';
            case 'running-unhealthy':
                return '⚠️ unhealthy';
            case 'partial':
                return '🔄 partial';
            case 'stopped':
                return '⏸️ stopped';
            default:
                return '❓ unknown';
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

    private function showAppStatus(array $app): void
    {
        foreach ($app['containers'] as $containerName => $status) {
            $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
            $healthIcon = $this->getHealthIcon($status['health']);
            echo "  └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
        }
    }
}