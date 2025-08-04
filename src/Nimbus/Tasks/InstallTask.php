<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Composer\Script\Event;

class InstallTask extends BaseTask
{
    private AppManager $appManager;

    public function __construct()
    {
        $this->appManager = new AppManager();
    }

    public function execute(Event $event): void
    {
        $this->install($event);
    }

    public function install(Event $event): void
    {
        $args = $event->getArguments();
        $appName = $args[0] ?? null;
        
        if (!$appName) {
            $apps = $this->appManager->listApps();
            
            if (empty($apps)) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first with: composer nimbus:create');
                return;
            }
            
            $io = $event->getIO();
            $appNames = array_keys($apps);
            $appName = $io->select('Select app to install:', $appNames);
        }
        
        try {
            $this->appManager->install($appName);
            
            echo self::ansiFormat('SUCCESS', "App '$appName' installed successfully!");
            echo self::ansiFormat('INFO', "Container config generated: $appName-compose.yml");
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to install app: ' . $e->getMessage());
        }
    }

    public function list(Event $event): void
    {
        try {
            $apps = $this->appManager->listApps();
            
            if (empty($apps)) {
                echo self::ansiFormat('INFO', 'No apps created yet.');
                echo self::ansiFormat('INFO', 'Create one with: composer nimbus:create my-app');
                return;
            }
            
            echo self::ansiFormat('INFO', 'Available apps:');
            foreach ($apps as $name => $info) {
                $status = $info['installed'] ? 'installed' : 'created';
                echo "  $name ($status) - {$info['template']}" . PHP_EOL;
            }
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to list apps: ' . $e->getMessage());
        }
    }
}