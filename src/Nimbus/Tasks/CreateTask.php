<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\Template\TemplateManager;
use Nimbus\Template\TemplateConfig;
use Nimbus\Vault\VaultManager;
use Nimbus\UI\InteractiveHelper;
use Composer\Script\Event;

class CreateTask extends BaseTask
{
    private AppManager $appManager;
    private TemplateManager $templateManager;
    private TemplateConfig $templateConfig;
    private VaultManager $vaultManager;
    private InteractiveHelper $interactiveHelper;

    public function __construct()
    {
        $this->appManager = new AppManager();
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
            
            $this->appManager->createFromTemplate($appName, $template);
            
            if ($resolvedTemplate !== $template) {
                echo self::ansiFormat('SUCCESS', "App '$appName' created successfully using alias '$template' → template '$resolvedTemplate'!");
            } else {
                echo self::ansiFormat('SUCCESS', "App '$appName' created successfully from template '$template'!");
            }
            echo self::ansiFormat('INFO', "📁 App created at: .installer/apps/$appName");
            echo PHP_EOL;
            
            $this->interactiveHelper->interactiveNextSteps($appName, $io, $this->appManager);
            
        } catch (\Exception $e) {
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
            
        } catch (\Exception $e) {
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
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to create app: ' . $e->getMessage());
        }
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