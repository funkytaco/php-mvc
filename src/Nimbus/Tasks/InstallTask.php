<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\App\AppManagerFactory;
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
            $choice = $io->select('Select app to install:', $appNames, 0);
            $appName = $appNames[$choice];
        }
        
        try {
            AppManagerFactory::forApp($appName)->install($appName);
            
            echo self::ansiFormat('SUCCESS', "App '$appName' installed successfully!");
            echo self::ansiFormat('INFO', "Container config generated: $appName-compose.yml");
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to install app: ' . $e->getMessage());
        }
    }

    public function list(Event $event): void
    {
        // `nimbus:list <app>` reads as "list this one"; ignoring the argument
        // silently left people thinking the filter had worked.
        $this->listApps($event->getArguments()[0] ?? null);
    }

    /**
     * The body of nimbus:list, free of the Composer event so it can be driven
     * directly.
     */
    public function listApps(?string $filter = null): void
    {
        try {
            $apps = $this->appManager->describeApps();

            if (empty($apps)) {
                echo self::ansiFormat('INFO', 'No apps created yet.');
                echo self::ansiFormat('INFO', 'Create one with: composer nimbus:create my-app');
                return;
            }

            if ($filter !== null) {
                $matched = array_values(array_filter($apps, fn (array $a) => $a['name'] === $filter));

                if ($matched === []) {
                    echo self::ansiFormat('ERROR', "App '$filter' not found.");
                    echo self::ansiFormat('INFO', 'Run without an app name to see them all.');
                    return;
                }

                $apps = $matched;
            }

            $nameWidth = max(array_map(fn (array $a) => strlen($a['name']), $apps));
            $nameWidth = max($nameWidth, 4);

            echo self::ansiFormat('INFO', 'Available apps:');
            foreach ($apps as $app) {
                // Containers exist -> show how many of them are up, so a
                // partially-started stack is visible at a glance.
                $state = in_array($app['state'], ['running', 'partial', 'stopped'], true)
                    ? sprintf('%s (%d/%d)', $app['state'], $app['running'], $app['total'])
                    : $app['state'];

                echo sprintf(
                    "  %-{$nameWidth}s  %-16s  %-12s  %s" . PHP_EOL,
                    $app['name'],
                    $state,
                    $app['source'],
                    $app['port'] !== null ? 'http://localhost:' . $app['port'] : ''
                );
            }

            // A concrete name rather than a placeholder, so it is copy-pastable
            echo PHP_EOL;
            echo self::ansiFormat('INFO', 'Related commands:');
            echo '  composer nimbus:view ' . $apps[0]['name'] . PHP_EOL;

        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to list apps: ' . $e->getMessage());
        }
    }
}