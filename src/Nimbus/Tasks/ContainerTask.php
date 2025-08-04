<?php

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
            
            if (empty($startableApps)) {
                echo self::ansiFormat('INFO', 'No apps found with compose files.');
                echo self::ansiFormat('INFO', 'Create and install an app first:');
                echo "  1. composer nimbus:create my-app" . PHP_EOL;
                echo "  2. composer nimbus:install my-app" . PHP_EOL;
                return;
            }
            
            $targetApp = $args[0] ?? null;
            
            if ($targetApp) {
                $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);
                if (empty($app)) {
                    echo self::ansiFormat('ERROR', "App '$targetApp' not found or not installed.");
                    return;
                }
                $app = array_values($app)[0];
                $this->startApp($app);
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
        
        if (empty($startableApps)) {
            echo self::ansiFormat('INFO', 'No apps found with compose files.');
            echo self::ansiFormat('INFO', 'Create and install an app first:');
            echo "  1. composer nimbus:create my-app" . PHP_EOL;
            echo "  2. composer nimbus:install my-app" . PHP_EOL;
            return;
        }
        
        $targetApp = $args[0] ?? null;
        
        if ($targetApp) {
            $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);
            if (empty($app)) {
                echo self::ansiFormat('ERROR', "App '$targetApp' not found or not installed.");
                return;
            }
            $app = array_values($app)[0];
            $this->startApp($app);
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
        $composeFile = $app['compose_file'];
        
        if ($app['is_running'] && $app['health_status'] === 'healthy') {
            echo self::ansiFormat('INFO', "App '$appName' is already running and healthy!");
            $this->showAppStatus($app);
            return;
        }
        
        echo self::ansiFormat('INFO', "Building app '$appName' image...");
        $buildCommand = "podman-compose -f $composeFile up --build -d";
        
        echo self::ansiFormat('INFO', "Running: $buildCommand");
        $output = shell_exec($buildCommand . ' 2>&1');
        
        if ($output) {
            echo $output;
        }
        
        $statusOutput = shell_exec("podman-compose -f $composeFile ps --format table 2>/dev/null");
        if ($statusOutput) {
            echo self::ansiFormat('SUCCESS', "App '$appName' started successfully!");
            echo $statusOutput;
            
            $this->interactiveHelper->displayKeycloakCredentials($appName);
        }
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