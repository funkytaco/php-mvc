<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\App\AppManagerFactory;
use Nimbus\UI\InteractiveHelper;
use Nimbus\UI\StepList;
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

    /**
     * nimbus:add-db <app> [engine|image] — give an app created without a
     * database one, without recreating it.
     */
    public function addDatabase(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $assumeYes = (bool) array_filter($args, fn ($a) => in_array($a, ['--yes', '-y', '--force'], true));
        $positional = array_values(array_filter($args, fn ($a) => !str_starts_with((string) $a, '-')));

        $appName = $positional[0] ?? null;
        $spec = $positional[1] ?? null;

        if (!$appName) {
            $candidates = [];

            foreach (array_keys($this->appManager->listApps()) as $name) {
                $configFile = getcwd() . '/.installer/apps/' . $name . '/app.nimbus.json';
                if (!file_exists($configFile)) {
                    continue;
                }

                $config = json_decode((string) file_get_contents($configFile), true);
                if (!(is_array($config) && ($config['features']['database'] ?? false))) {
                    $candidates[] = $name;
                }
            }

            if ($candidates === []) {
                echo self::ansiFormat('INFO', 'Every app already has a database.');
                return;
            }

            echo self::ansiFormat('INFO', 'Apps without a database:');
            foreach ($candidates as $name) {
                echo "  - $name" . PHP_EOL;
            }

            $appName = $io->ask('Select app to add a database to: ');

            if (!$appName || !in_array($appName, $candidates, true)) {
                echo self::ansiFormat('ERROR', 'Invalid app selection.');
                return;
            }
        }

        try {
            $manager = AppManagerFactory::forApp($appName);

            if (!$assumeYes && !$this->confirmDatabaseChanges($io, $manager, (string) $appName, $spec)) {
                echo self::ansiFormat('INFO', 'Cancelled — nothing was changed.');
                return;
            }

            $engine = $manager->addDatabase($appName, $spec);

            echo self::ansiFormat('SUCCESS', "Database added to '$appName'!");
            echo self::ansiFormat('INFO', 'Changes made:');
            echo "  ✓ Engine: {$engine->image}" . PHP_EOL;
            echo "  ✓ Credentials resolved and stored in the vault" . PHP_EOL;
            echo "  ✓ Regenerated $appName-compose.yml with the database service" . PHP_EOL;

            $this->displayDatabaseSteps($manager, (string) $appName);
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', 'Failed to add database: ' . $e->getMessage());
        }
    }

    /**
     * What is actually left to do after adding a database.
     *
     * Adding one already regenerates the compose file and the .env, so install
     * is normally satisfied by the time this prints and `up` is the one thing
     * left — which is exactly the question the old flat list left open.
     */
    private function displayDatabaseSteps(\Nimbus\App\AppManager $manager, string $appName): void
    {
        $installed = $manager->isInstalled($appName);

        $state = null;
        try {
            $state = $manager->describeApp($appName)['state'] ?? null;
        } catch (\Throwable $e) {
            // podman unavailable; treat the app as not running
        }

        $running = in_array($state, ['running', 'partial'], true);

        $steps = new StepList();
        $steps->add(
            "composer nimbus:install $appName",
            $installed,
            $installed ? 'already done — compose and .env are current' : 'write compose and .env'
        );
        $steps->add(
            "composer nimbus:up $appName",
            $running,
            $running ? 'already running — restart to pick up the database' : 'start the stack'
        );

        echo PHP_EOL;
        echo self::ansiFormat('INFO', 'Next steps:');
        echo $steps->render();

        if ($running) {
            echo PHP_EOL;
            echo self::ansiFormat(
                'WARNING',
                "'$appName' is already running with the old configuration. "
                . "Run nimbus:down then nimbus:up to pick up the database."
            );
        }
    }

    /**
     * Spell out what adding a database rewrites, and get a yes first.
     *
     * The compose file is regenerated wholesale, so any hand edits to it are
     * lost — worth saying out loud rather than discovering afterwards. A
     * running app also needs recreating before it sees the new service.
     */
    private function confirmDatabaseChanges(
        \Composer\IO\IOInterface $io,
        \Nimbus\App\AppManager $manager,
        string $appName,
        ?string $spec
    ): bool {
        $engine = \Nimbus\Database\DatabaseEngine::fromSpec($spec ?? 'mariadb');

        echo self::ansiFormat('INFO', "This will change app '$appName':");
        echo "  • {$engine->image} added as {$engine->containerName($appName)}" . PHP_EOL;
        echo "  • .installer/apps/$appName/app.nimbus.json — database settings added" . PHP_EOL;
        echo "  • $appName-compose.yml — regenerated, overwriting any manual edits" . PHP_EOL;
        echo "  • a database password generated and stored in the vault" . PHP_EOL;

        $composeFile = getcwd() . '/' . $appName . '-compose.yml';
        if (!is_file($composeFile)) {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "No $appName-compose.yml yet — nothing generated will be overwritten.");
        }

        try {
            $running = array_filter(
                $manager->describeContainers($appName),
                static fn (array $c): bool => $c['exists'] && str_starts_with($c['status'], 'Up')
            );

            if ($running !== []) {
                echo PHP_EOL;
                echo self::ansiFormat(
                    'WARNING',
                    "'$appName' is running (" . count($running) . ' container(s)). They keep the old '
                    . 'configuration until you run nimbus:install and nimbus:up again.'
                );
            }
        } catch (\Throwable $e) {
            // Runtime state is a nicety here, not a precondition
        }

        echo PHP_EOL;

        return $io->askConfirmation("Add the database? [y/N]: ", false);
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
            AppManagerFactory::forApp($appName)->addEda($appName);
            
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
            $choice = $io->select('Select app to add Keycloak to:', $appNames, 0);
            $appName = $appNames[$choice];
        }
        
        try {
            if (!$this->appManager->appExists($appName)) {
                echo self::ansiFormat('ERROR', "App '$appName' not found!");
                return;
            }
            
            AppManagerFactory::forApp($appName)->addKeycloak($appName, $force);
            
            $action = $force ? 'updated' : 'added';
            echo self::ansiFormat('SUCCESS', "Keycloak $action to app '$appName' successfully!");
            
            // Get the dynamically assigned Keycloak port
            $configFile = getcwd() . '/.installer/apps/' . $appName . '/app.nimbus.json';
            $config = json_decode(file_get_contents($configFile), true);
            $keycloakPort = $config['containers']['keycloak']['port'] ?? '9080';
            
            echo self::ansiFormat('INFO', "Keycloak containers configured:");
            echo "  🔐 Keycloak server on port $keycloakPort" . PHP_EOL;
            echo "  💾 Keycloak database (PostgreSQL)" . PHP_EOL;
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['keycloak'], false);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to add Keycloak: ' . $e->getMessage());
        }
    }

    public function removeEda(Event $event): void
    {
        $this->removeFeature($event, 'eda', 'EDA');
    }

    public function removeKeycloak(Event $event): void
    {
        $this->removeFeature($event, 'keycloak', 'Keycloak');
    }

    /**
     * Shared remove flow: pick an app that has the feature enabled,
     * flip it off via AppManager::setFeature, report what happened.
     */
    private function removeFeature(Event $event, string $feature, string $label): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $appName = $args[0] ?? null;

        if (!$appName) {
            $apps = $this->appManager->listApps();
            $enabledApps = [];

            foreach ($apps as $name => $info) {
                $configFile = getcwd() . '/.installer/apps/' . $name . '/app.nimbus.json';
                if (file_exists($configFile)) {
                    $config = json_decode(file_get_contents($configFile), true);
                    if ($config['features'][$feature] ?? false) {
                        $enabledApps[] = $name;
                    }
                }
            }

            if (empty($enabledApps)) {
                echo self::ansiFormat('INFO', "No apps found with $label enabled.");
                return;
            }

            $choice = $io->select("Select app to remove $label from:", $enabledApps, 0);
            $appName = $enabledApps[$choice];
        }

        try {
            AppManagerFactory::forApp($appName)->setFeature($appName, $feature, false);

            echo self::ansiFormat('SUCCESS', "$label removed from '$appName' successfully!");
            echo self::ansiFormat('INFO', 'Changes made:');
            echo "  ✓ Disabled $label in app configuration" . PHP_EOL;
            echo "  ✓ Regenerated compose file without the $label container(s)" . PHP_EOL;
            echo self::ansiFormat('INFO', "Note: $label files under .installer/apps/$appName/ were left on disk — re-enabling is cheap; delete manually if unwanted.");
            echo self::ansiFormat('INFO', "Apply with: composer nimbus:up $appName (or bin/nimbus rebuild $appName)");
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', "Failed to remove $label: " . $e->getMessage());
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
            $choice = $io->select('Select app to add EDA and Keycloak to:', $appNames, 0);
            $appName = $appNames[$choice];
        }
        
        try {
            if (!$this->appManager->appExists($appName)) {
                echo self::ansiFormat('ERROR', "App '$appName' not found!");
                return;
            }
            
            echo self::ansiFormat('INFO', "Adding EDA and Keycloak to app '$appName'...");
            
            try {
                AppManagerFactory::forApp($appName)->addEda($appName);
                echo self::ansiFormat('SUCCESS', "✓ EDA added successfully!");
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'already enabled') === false) {
                    throw $e;
                }
                echo self::ansiFormat('INFO', "✓ EDA already enabled");
            }
            
            try {
                AppManagerFactory::forApp($appName)->addKeycloak($appName);
                echo self::ansiFormat('SUCCESS', "✓ Keycloak added successfully!");
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'already enabled') === false) {
                    throw $e;
                }
                echo self::ansiFormat('INFO', "✓ Keycloak already enabled");
            }
            
            echo PHP_EOL;
            echo self::ansiFormat('SUCCESS', "Both EDA and Keycloak have been added to app '$appName'!");
            
            // Get the dynamically assigned ports
            $configFile = getcwd() . '/.installer/apps/' . $appName . '/app.nimbus.json';
            $config = json_decode(file_get_contents($configFile), true);
            $edaPort = $config['containers']['eda']['port'] ?? '5000';
            $keycloakPort = $config['containers']['keycloak']['port'] ?? '9080';
            
            echo self::ansiFormat('INFO', "Features enabled:");
            echo "  • Event-Driven Ansible (EDA) on port $edaPort" . PHP_EOL;
            echo "  • Keycloak SSO Integration on port $keycloakPort" . PHP_EOL;
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['eda', 'keycloak'], false);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to add features: ' . $e->getMessage());
        }
    }
}