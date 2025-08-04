<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\UI\InteractiveHelper;
use Composer\Script\Event;

class FeatureTask extends BaseTask
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
        // Not used directly
    }

    public function addEda(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $appName = $args[0] ?? null;
        
        if (!$appName) {
            $apps = $this->appManager->listApps();
            $nonEdaApps = [];
            
            foreach ($apps as $name => $info) {
                $configFile = getcwd() . '/.installer/apps/' . $name . '/app.nimbus.json';
                if (file_exists($configFile)) {
                    $config = json_decode(file_get_contents($configFile), true);
                    if (!($config['features']['eda'] ?? false)) {
                        $nonEdaApps[] = $name;
                    }
                }
            }
            
            if (empty($nonEdaApps)) {
                echo self::ansiFormat('INFO', 'No apps found that can have EDA added.');
                echo self::ansiFormat('INFO', 'All existing apps already have EDA enabled.');
                return;
            }
            
            echo self::ansiFormat('INFO', 'Apps available for EDA:');
            foreach ($nonEdaApps as $name) {
                echo "  - $name" . PHP_EOL;
            }
            
            $appName = $io->ask('Select app to add EDA to: ');
            
            if (!$appName || !in_array($appName, $nonEdaApps)) {
                echo self::ansiFormat('ERROR', 'Invalid app selection.');
                return;
            }
        }
        
        if (!$appName) {
            echo self::ansiFormat('ERROR', 'App name is required.');
            return;
        }
        
        try {
            $this->appManager->addEda($appName);
            
            echo self::ansiFormat('SUCCESS', "EDA functionality added to '$appName' successfully!");
            echo self::ansiFormat('INFO', "Changes made:");
            echo "  ✓ Enabled EDA in app configuration" . PHP_EOL;
            echo "  ✓ Created rulebooks directory with demo files" . PHP_EOL;
            echo "  ✓ Regenerated compose file with EDA container" . PHP_EOL;
            echo "  ✓ Validated YAML syntax" . PHP_EOL;
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['eda'], false);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to add EDA: ' . $e->getMessage());
        }
    }

    public function addKeycloak(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $appName = null;
        $force = false;
        
        foreach ($args as $arg) {
            if ($arg === '--force' || $arg === '-f' || $arg === 'force') {
                $force = true;
            } elseif (!$appName && substr($arg, 0, 1) !== '-') {
                $appName = $arg;
            }
        }
        
        if (!$appName) {
            $apps = $this->appManager->listApps();
            
            if (empty($apps)) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first with: composer nimbus:create');
                return;
            }
            
            $appNames = array_keys($apps);
            $choice = $io->select('Select app to add Keycloak to:', $appNames);
            $appName = $appNames[$choice];
        }
        
        try {
            if (!$this->appManager->appExists($appName)) {
                echo self::ansiFormat('ERROR', "App '$appName' not found!");
                return;
            }
            
            $this->appManager->addKeycloak($appName, $force);
            
            $action = $force ? 'updated' : 'added';
            echo self::ansiFormat('SUCCESS', "Keycloak $action to app '$appName' successfully!");
            echo self::ansiFormat('INFO', "Keycloak containers configured:");
            echo "  🔐 Keycloak server on port 8080" . PHP_EOL;
            echo "  💾 Keycloak database (PostgreSQL)" . PHP_EOL;
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['keycloak'], false);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to add Keycloak: ' . $e->getMessage());
        }
    }

    public function addEdaKeycloak(Event $event): void
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
            $choice = $io->select('Select app to add EDA and Keycloak to:', $appNames);
            $appName = $appNames[$choice];
        }
        
        try {
            if (!$this->appManager->appExists($appName)) {
                echo self::ansiFormat('ERROR', "App '$appName' not found!");
                return;
            }
            
            echo self::ansiFormat('INFO', "Adding EDA and Keycloak to app '$appName'...");
            
            try {
                $this->appManager->addEda($appName);
                echo self::ansiFormat('SUCCESS', "✓ EDA added successfully!");
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'already enabled') === false) {
                    throw $e;
                }
                echo self::ansiFormat('INFO', "✓ EDA already enabled");
            }
            
            try {
                $this->appManager->addKeycloak($appName);
                echo self::ansiFormat('SUCCESS', "✓ Keycloak added successfully!");
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'already enabled') === false) {
                    throw $e;
                }
                echo self::ansiFormat('INFO', "✓ Keycloak already enabled");
            }
            
            echo PHP_EOL;
            echo self::ansiFormat('SUCCESS', "Both EDA and Keycloak have been added to app '$appName'!");
            echo self::ansiFormat('INFO', "Features enabled:");
            echo "  • Event-Driven Ansible (EDA) on port 5000" . PHP_EOL;
            echo "  • Keycloak SSO Integration on port 8080" . PHP_EOL;
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['eda', 'keycloak'], false);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to add features: ' . $e->getMessage());
        }
    }
}