<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\MVCAppManager;
use Nimbus\App\GitAppManager;
use Nimbus\Template\TemplateManager;
use Nimbus\Template\TemplateConfig;
use Nimbus\Vault\VaultManager;
use Nimbus\UI\InteractiveHelper;
use Composer\Script\Event;

class CreateTask extends BaseTask
{
    private MVCAppManager $appManager;
    private TemplateManager $templateManager;
    private TemplateConfig $templateConfig;
    private VaultManager $vaultManager;
    private InteractiveHelper $interactiveHelper;

    public function __construct()
    {
        $this->appManager = new MVCAppManager();
        $this->templateManager = new TemplateManager();
        $this->templateConfig = TemplateConfig::getInstance();
        $this->vaultManager = new VaultManager();
        $this->interactiveHelper = new InteractiveHelper();
    }

    public function execute(Event $event): void
    {
        $this->create($event);
    }

    public function create(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        // --no-db: create the app without a database container/service
        $noDb = in_array('--no-db', $args, true);
        $args = array_values(array_filter($args, fn ($a) => $a !== '--no-db'));

        $appName = $args[0] ?? $io->ask('App name: ');
        
        if (!isset($args[1])) {
            $templates = $this->templateManager->getAvailableTemplates();
            $aliases = $this->templateManager->getAliases();
            
            echo self::ansiFormat('INFO', 'Available templates:');
            foreach ($templates as $name => $info) {
                echo "  - $name" . PHP_EOL;
            }
            
            if (!empty($aliases)) {
                echo PHP_EOL;
                echo self::ansiFormat('INFO', 'Template aliases:');
                foreach ($aliases as $alias => $templateName) {
                    echo "  - $alias → $templateName" . PHP_EOL;
                }
            }
            echo PHP_EOL;
            
            $defaultTemplate = $this->templateConfig->getDefaultTemplate();
            $template = $io->ask("Template name or alias [$defaultTemplate]: ", $defaultTemplate);
        } else {
            $template = $args[1];
        }
        
        try {
            $resolvedTemplate = $this->templateManager->resolveTemplate($template);
            
            echo self::ansiFormat('INFO', "📋 Creating app '$appName'");
            if ($resolvedTemplate !== $template) {
                echo self::ansiFormat('INFO', "Using template: '$template' → '$resolvedTemplate'");
            } else {
                echo self::ansiFormat('INFO', "Using template: '$template'");
            }
            echo PHP_EOL;
            
            $this->checkVaultCredentials($appName);

            $config = $noDb ? ['features' => ['database' => false]] : [];
            $this->appManager->createFromTemplate($appName, $template, $config);
            if ($noDb) {
                echo self::ansiFormat('INFO', '🚫 Database disabled for this app (--no-db)');
            }
            
            if ($resolvedTemplate !== $template) {
                echo self::ansiFormat('SUCCESS', "App '$appName' created successfully using alias '$template' → template '$resolvedTemplate'!");
            } else {
                echo self::ansiFormat('SUCCESS', "App '$appName' created successfully from template '$template'!");
            }
            echo self::ansiFormat('INFO', "📁 App created at: .installer/apps/$appName");
            echo PHP_EOL;
            
            // Check which features are already enabled in the created app
            $enabledFeatures = [];
            try {
                $appConfig = $this->appManager->loadAppConfig($appName);
                if (isset($appConfig['features'])) {
                    foreach ($appConfig['features'] as $feature => $enabled) {
                        if ($enabled) {
                            $enabledFeatures[] = $feature;
                        }
                    }
                }
            } catch (\Exception $e) {
                // If config can't be loaded, continue with empty features array
            }
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, $enabledFeatures);
            
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', 'Failed to create app: ' . $e->getMessage());
        }
    }

    /**
     * Create an app from a git repository instead of a template.
     *
     * Usage: composer nimbus:create-from-git <app> <repo-url|repo-name>
     *          [--ref=<branch|tag>] [--docroot=<dir>] [--containerfile=<path>]
     *
     * The second argument may be a clone URL, or the name of a repository
     * already present in .installer/repos/ (so a hand-made clone is adopted
     * rather than re-fetched).
     */
    public function createFromGit(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(ref|docroot|containerfile|runtime|webroot|repo|db)=(.*)$/', $arg, $m)) {
                $options[$m[1] === 'db' ? 'database' : $m[1]] = $m[2];
            }
        }

        // Bare forms: --db takes the default engine, --no-db refuses one even
        // if the repository's own manifest asks for it.
        if (in_array('--db', $args, true)) {
            $options['database'] = true;
        }
        if (in_array('--no-db', $args, true)) {
            $options['database'] = false;
        }

        $positional = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));

        $appName = $positional[0] ?? $io->ask('App name: ');
        $repoUrl = $positional[1] ?? $io->ask('Git repository URL (or a name under .installer/repos/): ');

        if (!$appName || !$repoUrl) {
            echo self::ansiFormat('ERROR', 'Both an app name and a repository are required.');
            return;
        }

        try {
            // Reject an unusable --db before anything is created, rather than
            // partway through the materializer.
            if (!empty($options['database'])) {
                \Nimbus\Database\DatabaseEngine::fromSpec(
                    is_bool($options['database']) ? true : (string) $options['database']
                );
            }

            $this->ensureVaultForGitApp();

            $manager = new GitAppManager();
            $manager->createFromRepo($appName, $repoUrl, $options);

            echo self::ansiFormat('SUCCESS', "App '$appName' created from repository '$repoUrl'!");
            echo self::ansiFormat('INFO', "📁 App config: .installer/apps/$appName/app.nimbus.json");

            foreach ($manager->getNotices() as $notice) {
                echo self::ansiFormat('INFO', $notice);
            }

            $config = $manager->loadAppConfig($appName);
            $source = $config['source'] ?? [];
            echo self::ansiFormat('INFO', "📦 Repository: .installer/repos/" . ($source['repo'] ?? '?'));
            if (!empty($source['docroot'])) {
                echo self::ansiFormat('INFO', "🌐 Document root: " . $source['docroot'] . '/');
            }
            if ($config['features']['database'] ?? false) {
                echo self::ansiFormat('INFO', "🗄️  Database: " . ($config['database']['image'] ?? '?')
                    . " (db '" . ($config['database']['name'] ?? '?') . "')");
                echo self::ansiFormat('INFO', "🔐 Credentials and secrets are stored in the vault, not in app config.");
                echo self::ansiFormat('INFO', "📄 A resolved .env is written to .installer/apps/$appName/.env on install.");
            }
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "Next steps:");
            echo "  composer nimbus:install $appName" . PHP_EOL;
            echo "  composer nimbus:up $appName" . PHP_EOL;
            echo "  bin/nimbus dev $appName   # live-edit the repo in a container" . PHP_EOL;
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', 'Failed to create app: ' . $e->getMessage());
        }
    }

    public function createWithEda(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $appName = $args[0] ?? $io->ask('App name: ');
        $template = $args[1] ?? $this->templateConfig->getDefaultTemplate();
        
        try {
            $this->appManager->createFromTemplate($appName, $template);
            $this->appManager->addEda($appName);
            
            echo self::ansiFormat('SUCCESS', "App '$appName' created successfully from template '$template' with EDA enabled!");
            echo self::ansiFormat('INFO', "📁 App created at: .installer/apps/$appName");
            echo self::ansiFormat('INFO', "✅ Features enabled: Event-Driven Ansible (EDA)");
            echo self::ansiFormat('INFO', "📡 EDA will run on port 5000 with rulebooks in .installer/apps/$appName/rulebooks/");
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['eda']);
            
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', 'Failed to create app: ' . $e->getMessage());
        }
    }

    public function createEdaKeycloak(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $appName = $args[0] ?? $io->ask('App name: ');
        
        try {
            $config = [
                'features' => [
                    'eda' => true,
                    'keycloak' => true
                ]
            ];
            
            $this->appManager->createFromTemplate($appName, $this->templateConfig->getDefaultTemplate(), $config);
            
            echo self::ansiFormat('SUCCESS', "App '$appName' created successfully with EDA and Keycloak!");
            echo self::ansiFormat('INFO', "📁 App created at: .installer/apps/$appName");
            echo self::ansiFormat('INFO', "✅ Features enabled:");
            echo "  • Event-Driven Ansible (EDA)" . PHP_EOL;
            echo "  • Keycloak SSO Integration" . PHP_EOL;
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager, ['eda', 'keycloak']);
            
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', 'Failed to create app: ' . $e->getMessage());
        }
    }

    /**
     * Git apps keep their database password and environment secrets in the
     * vault and nowhere else, so one has to exist before the app does.
     *
     * Initialized here rather than inside the manager on purpose: creating the
     * vault prints a master password the user has to keep, which is a decision
     * for the command line to own, and it must happen outside the create
     * transaction — a rolled-back app must not take the vault with it.
     */
    private function ensureVaultForGitApp(): void
    {
        if ($this->vaultManager->isInitialized()) {
            return;
        }

        echo self::ansiFormat('INFO', '🔐 Git apps keep credentials and secrets in the Nimbus vault. Initializing it now.');
        $this->vaultManager->initializeVault();
        echo PHP_EOL;
    }

    private function checkVaultCredentials(string $appName): void
    {
        if ($this->vaultManager->isInitialized()) {
            $vaultCredentials = $this->vaultManager->restoreAppCredentials($appName);
            if ($vaultCredentials) {
                echo self::ansiFormat('INFO', "🔐 Found backed up credentials for '$appName' in vault!");
                if (isset($vaultCredentials['database'])) {
                    echo "  📊 Database password: " . substr($vaultCredentials['database']['password'], 0, 8) . "..." . PHP_EOL;
                }
                if (isset($vaultCredentials['keycloak'])) {
                    echo "  🔐 Keycloak passwords: ✓" . PHP_EOL;
                }
                echo self::ansiFormat('INFO', '💡 These credentials will be restored automatically.');
                echo PHP_EOL;
            }
        }
    }
}