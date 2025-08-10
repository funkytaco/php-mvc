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
        $this->showConfigurationPreview($appName, $manager);
        
        echo "  2. Generate container configuration" . PHP_EOL;
        
        $installChoice = $io->ask("     Run 'composer nimbus:install $appName' now? [Y/n/edit]: ", 'y');
        $installChoice = strtolower(trim($installChoice));
        
        if ($installChoice === 'edit' || $installChoice === 'e') {
            echo PHP_EOL;
            
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
                echo PHP_EOL;
                echo self::ansiFormat('INFO', "Stopping app...");
                
                try {
                    $manager->stopApp($appName, ['remove_volumes' => false, 'remove_containers' => false]);
                    echo self::ansiFormat('SUCCESS', "✓ App stopped");
                    
                    echo self::ansiFormat('INFO', "Starting app with new configuration...");
                    $apps = $manager->getStartableApps();
                    $app = array_filter($apps, fn($a) => $a['name'] === $appName);
                    
                    if (!empty($app)) {
                        $this->startApp(array_values($app)[0]);
                        $this->showFeatureInfo($appName, $allFeatures);
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
                echo PHP_EOL;
                
                $apps = $manager->getStartableApps();
                $app = array_filter($apps, fn($a) => $a['name'] === $appName);
                
                if (!empty($app)) {
                    $this->startApp(array_values($app)[0]);
                    $this->showFeatureInfo($appName, $allFeatures);
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
        
        $setupHostsPath = ".installer/apps/$appName/dns-setup-$appName-hosts.sh";
        if (file_exists($setupHostsPath) && PHP_OS === 'Darwin') {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🌐 Setup local hostnames (macOS):");
            echo "  • chmod +x $setupHostsPath      # Make script executable" . PHP_EOL;
            echo "  • sudo ./$setupHostsPath         # Add .test hostnames to /etc/hosts" . PHP_EOL;
            echo "  • View network info: cat .installer/apps/$appName/podman-network.md" . PHP_EOL;
        }
    }
    
    private function showFeatureInfo(string $appName, array $features): void
    {
        echo PHP_EOL;
        if (in_array('keycloak', $features)) {
            $this->displayKeycloakCredentials($appName);
        }
        
        if (in_array('eda', $features)) {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "📡 EDA is running:");
            echo "  • Webhook endpoint: http://localhost:<app-port>/eda/webhook" . PHP_EOL;
            echo "  • Rulebooks: .installer/apps/$appName/rulebooks/" . PHP_EOL;
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
            
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "💡 To retrieve admin password later, run:");
            echo "  podman inspect $containerName --format '{{range .Config.Env}}{{println .}}{{end}}' | grep KEYCLOAK_ADMIN_PASSWORD | cut -d'=' -f2" . PHP_EOL;
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🚀 Next steps:");
            echo "  1. Access Keycloak admin console (URL above)" . PHP_EOL;
            echo "  2. Configure realm and client at http://localhost:" . ($config['containers']['app']['port'] ?? '8080') . "/auth/configure" . PHP_EOL;
            echo "  3. Test SSO integration in your app" . PHP_EOL;
            
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
            
            $this->displayKeycloakCredentials($appName);
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