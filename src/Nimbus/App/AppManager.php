<?php

declare(strict_types=1);

namespace Nimbus\App;

use Composer\Script\Event;
use Nimbus\Template\TemplateManager;
use Nimbus\Template\TemplateConfig;
use Nimbus\Generator\FileGenerator;

/**
 * AppManager handles app creation and installation
 */
class AppManager
{
    /**
     * Default Event-Driven Ansible image.
     *
     * Publicly pullable (no registry login). Red Hat's
     * registry.redhat.io/ansible-automation-platform-24/de-minimal-rhel9
     * requires Customer Portal credentials — apps that want it can set
     * containers.eda.image in their app.nimbus.json to override this.
     * Must provide both ansible-rulebook and ansible-galaxy, which
     * init-entrypoint.sh invokes under `set -e`.
     */
    public const DEFAULT_EDA_IMAGE = 'quay.io/ansible/ansible-rulebook:latest';

    private string $baseDir;
    private string $installerDir;
    private string $templatesDir;
    private TemplateManager $templateManager;
    private TemplateConfig $templateConfig;
    
    public function __construct(string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? getcwd();
        $this->installerDir = $this->baseDir . '/.installer/apps';
        $this->templatesDir = $this->baseDir . '/.installer/_templates';
        $this->templateConfig = TemplateConfig::getInstance();
    }
    
    /**
     * Create a new app from template
     */
    public function createFromTemplate(string $appName, string $template = null, array $config = []): bool
    {
        $this->validateAppName($appName);
        
        // Use default template if none specified
        if ($template === null) {
            $template = $this->templateConfig->getDefaultTemplate();
        }
        
        // Use TemplateManager to validate and get template path
        $templateManager = new TemplateManager();
        if (!$templateManager->templateExists($template)) {
            throw new \RuntimeException("Template '$template' not found");
        }
        
        $templatePath = $templateManager->getTemplatePath($template);
        $targetPath = $this->installerDir . '/' . $appName;
        
        if (is_dir($targetPath)) {
            throw new \RuntimeException("App '$appName' already exists");
        }
        
        try {
            // 1. FIRST: Resolve password strategy using new PasswordManager
            $passwordManager = new \Nimbus\Password\PasswordManager($this->getVaultManager(), $this->baseDir);
            $passwords = $passwordManager->resolvePasswords($appName);
            
            // 2. Copy template with password-aware setup
            $this->copyTemplateWithPasswordStrategy($templatePath, $targetPath, $passwords);
            
            // 3. Generate configuration with resolved passwords
            $this->generateAppConfigWithPasswords($appName, $targetPath, $passwords, $config, $template);
            
            // 4. Auto-backup to vault if new passwords generated
            if ($passwords->strategy === \Nimbus\Password\PasswordStrategy::GENERATE_NEW) {
                $passwordManager->backupToVault($appName, $passwords);
            }

            // 5. Populate EDA runtime dirs when EDA is enabled at create time.
            // The compose file mounts eda/rulebooks and eda/playbooks; without
            // this the create-with-eda path mounted EMPTY dirs and the EDA
            // container crash-looped behind an "Up" status (only addEda()
            // used to do this).
            if (!empty($config['features']['eda'])) {
                $this->createEdaDirectories($targetPath, $appName);
            }

            // Register app in apps.json
            $this->registerApp($appName, $template);
            
            // Update composer.json
            $this->updateComposerJson($appName);
            
        } catch (\Throwable $e) {
            // Clean up failed app directory
            if (is_dir($targetPath)) {
                $this->removeDirectory($targetPath);
            }
            
            // Clean up from apps.json if it was registered
            $this->unregisterApp($appName);
            
            throw new \RuntimeException("Failed to create app: " . $e->getMessage(), 0, $e);
        }
        
        return true;
    }
    
    /**
     * Copy template with password-aware setup
     */
    private function copyTemplateWithPasswordStrategy(
        string $templatePath, 
        string $targetPath, 
        \Nimbus\Password\PasswordSet $passwords
    ): void {
        // Standard template copy
        $this->copyDirectory($templatePath, $targetPath);
        
        // Add force-init script if vault restore with existing data
        if ($passwords->requiresForceInit) {
            $this->setupForceInitScript($templatePath, $targetPath);
        }
    }
    
    /**
     * Setup force init script for vault restore with existing data
     */
    private function setupForceInitScript(string $templatePath, string $targetPath): void
    {
        $forceInitScript = $templatePath . '/database/force-init.sh';
        if (file_exists($forceInitScript)) {
            $targetScript = $targetPath . '/database/force-init.sh';
            copy($forceInitScript, $targetScript);
            chmod($targetScript, 0755);
        }
    }
    
    /**
     * Generate app configuration with resolved passwords
     */
    private function generateAppConfigWithPasswords(
        string $appName,
        string $targetPath,
        \Nimbus\Password\PasswordSet $passwords,
        array $config,
        string $template
    ): void {
        // Prepare placeholders with resolved passwords
        $placeholders = [
            '{{APP_NAME}}' => $appName,
            '{{APP_NAME_UPPER}}' => strtoupper($appName),
            '{{APP_NAME_LOWER}}' => strtolower($appName),
            '{{APP_PORT}}' => $this->generatePort($appName),
            '{{EDA_PORT}}' => $this->generateEdaPort($appName),
            '{{DB_NAME}}' => $appName . '_db',
            '{{DB_USER}}' => $appName . '_user',
            '{{DB_PASSWORD}}' => $passwords->databasePassword
        ];
        
        // Add EDA placeholder
        $placeholders['{{HAS_EDA}}'] = isset($config['features']['eda']) && $config['features']['eda'] ? 'true' : 'false';
        
        // Add Keycloak placeholders if enabled
        if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            $placeholders['{{KEYCLOAK_ENABLED}}'] = 'true';
            $placeholders['{{KEYCLOAK_ADMIN_PASSWORD}}'] = $passwords->keycloakAdminPassword;
            $placeholders['{{KEYCLOAK_DB_PASSWORD}}'] = $passwords->keycloakDbPassword;
            $placeholders['{{KEYCLOAK_REALM}}'] = $appName . '-realm';
            $placeholders['{{KEYCLOAK_CLIENT_ID}}'] = $appName . '-client';
            $placeholders['{{KEYCLOAK_CLIENT_SECRET}}'] = $passwords->keycloakClientSecret;
            $placeholders['{{KEYCLOAK_PORT}}'] = $this->generateKeycloakPort($appName);
        } else {
            $placeholders['{{KEYCLOAK_ENABLED}}'] = 'false';
            $placeholders['{{KEYCLOAK_ADMIN_PASSWORD}}'] = '';
            $placeholders['{{KEYCLOAK_DB_PASSWORD}}'] = '';
            $placeholders['{{KEYCLOAK_REALM}}'] = '';
            $placeholders['{{KEYCLOAK_CLIENT_ID}}'] = '';
            $placeholders['{{KEYCLOAK_CLIENT_SECRET}}'] = '';
            $placeholders['{{KEYCLOAK_PORT}}'] = $this->generateKeycloakPort($appName);
        }
        
        // Replace placeholders in files
        $this->replacePlaceholders($targetPath, $placeholders);
        
        // Process generator templates (completely generic, template-driven)
        $this->processGeneratorTemplates($appName, $targetPath, $template, $placeholders);
        
        // Update app.nimbus.json with features and password strategy
        $this->updateAppConfigJson($targetPath, $appName, $passwords, $placeholders, $config);
    }
    
    /**
     * Process generator templates defined in template's app.config.php
     * Completely generic - no hardcoded app types or template names
     */
    private function processGeneratorTemplates(string $appName, string $targetPath, string $template, array $placeholders): void
    {
        // Read the app's config to see if it defines generator_templates.
        // Use the already-substituted copy in $targetPath, NOT the raw template:
        // template sources contain bare {{PLACEHOLDER}} tokens (e.g. unquoted
        // booleans) and are not valid PHP until placeholders are replaced.
        $templateConfigPath = $targetPath . '/app.config.php';
        if (!file_exists($templateConfigPath)) {
            return; // No template config, no generation needed
        }

        try {
            $templateConfig = include $templateConfigPath;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Template config has syntax error in $templateConfigPath: " . $e->getMessage(), 0, $e);
        }
        $generatorTemplates = $templateConfig['generator_templates'] ?? [];
        
        if (empty($generatorTemplates)) {
            return; // No templates to generate
        }
        
        $fileGenerator = new \Nimbus\Generator\FileGenerator($this->baseDir);
        
        foreach ($generatorTemplates as $templatePath => $config) {
            $outputPath = $config['output_path'] ?? null;
            $templateVars = $config['variables'] ?? [];
            
            if (!$outputPath) continue;
            
            // Merge template variables with standard placeholders
            $allVars = array_merge($placeholders, $templateVars, [
                'APP_NAME' => $appName,
                'app_name' => $appName,
                'APP_NAME_LOWER' => strtolower($appName),
                'APP_NAME_UPPER' => strtoupper($appName)
            ]);
            
            // Generate the file
            $fullTemplatePath = $targetPath . '/' . $templatePath;
            $fullOutputPath = $targetPath . '/' . str_replace('{{APP_NAME}}', $appName, $outputPath);
            
            if (file_exists($fullTemplatePath)) {
                try {
                    $fileGenerator->generateFile($fullTemplatePath, $fullOutputPath, $allVars);
                } catch (\Throwable $e) {
                    // Log error but don't fail app creation for template generation issues
                    error_log("Failed to generate template file $templatePath: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Update app.nimbus.json with features and password strategy
     */
    private function updateAppConfigJson(
        string $targetPath,
        string $appName,
        \Nimbus\Password\PasswordSet $passwords,
        array $placeholders,
        array $config
    ): void {
        $appConfigPath = $targetPath . '/app.nimbus.json';
        
        if (!file_exists($appConfigPath)) {
            return;
        }
        
        $appConfig = json_decode(file_get_contents($appConfigPath), true);
        
        // Store password strategy information
        $appConfig['password_strategy'] = $passwords->strategy->value;
        if ($passwords->requiresForceInit) {
            $appConfig['force_init'] = true;
        }
        
        // Update database password with resolved password
        if (!isset($appConfig['database'])) {
            $appConfig['database'] = [];
        }
        $appConfig['database']['password'] = $passwords->databasePassword;
        
        // Merge features
        if (!empty($config['features'])) {
            foreach ($config['features'] as $feature => $enabled) {
                $appConfig['features'][$feature] = $enabled;
            }
        }
        
        // Add Keycloak configuration if enabled
        if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            $appConfig['keycloak'] = [
                'realm' => $placeholders['{{KEYCLOAK_REALM}}'],
                'client_id' => $placeholders['{{KEYCLOAK_CLIENT_ID}}'],
                'client_secret' => $placeholders['{{KEYCLOAK_CLIENT_SECRET}}'],
                'auth_url' => "http://{$appName}-keycloak:8080",
                'redirect_uri' => "http://localhost:" . $placeholders['{{APP_PORT}}'] . "/auth/callback"
            ];
            
            // Add Keycloak container configuration
            $appConfig['containers']['keycloak'] = [
                'image' => 'quay.io/keycloak/keycloak:latest',
                'port' => (string)$placeholders['{{KEYCLOAK_PORT}}'],
                'admin_user' => 'admin',
                'admin_password' => $placeholders['{{KEYCLOAK_ADMIN_PASSWORD}}'],
                'database' => 'keycloak_db'
            ];
            
            $appConfig['containers']['keycloak-db'] = [
                'image' => 'postgres:14',
                'database' => 'keycloak_db',
                'user' => 'keycloak',
                'password' => $placeholders['{{KEYCLOAK_DB_PASSWORD}}']
            ];
        }
        
        file_put_contents($appConfigPath, json_encode($appConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Install an app (copy files to active directories)
     */
    public function install(string $appName): bool
    {
        $appPath = $this->installerDir . '/' . $appName;
        
        if (!is_dir($appPath)) {
            throw new \RuntimeException("App '$appName' not found");
        }
        
        $config = $this->loadAppConfig($appName);
        
        // Copy assets based on config
        foreach ($config['assets'] as $asset => $paths) {
            $source = $appPath . '/' . $paths['source'];
            $target = $this->baseDir . '/' . $paths['target'];
            
            if (isset($paths['isFile']) && $paths['isFile']) {
                $this->copyFile($source, $target);
            } else {
                $this->copyDirectory($source, $target);
            }
        }
        
        // Generate podman-compose file (passwords will be resolved from config)
        $this->generatePodmanCompose($appName, $config);
        
        return true;
    }
    
    /**
     * List available apps
     *
     * Self-healing: apps.json is a registry that only stays in sync with
     * .installer/apps/ on the happy create/delete path — anything else that
     * removes an app directory (manual rm -rf, an interrupted delete, etc.)
     * leaves a stale "ghost" entry behind. Rather than let every caller
     * (nimbus:list, nimbus:delete, ...) trust a registry that might be lying,
     * prune ghosts here and persist the cleanup, so the fix applies once and
     * every caller of listApps() automatically sees only real apps.
     */
    public function listApps(): array
    {
        $appsFile = $this->baseDir . '/.installer/apps.json';

        if (!file_exists($appsFile)) {
            return [];
        }

        $registry = json_decode(file_get_contents($appsFile), true);
        $apps = $registry['apps'] ?? [];

        $ghosts = array_filter(
            array_keys($apps),
            fn (string $name) => !is_dir($this->installerDir . '/' . $name)
        );

        if (!empty($ghosts)) {
            foreach ($ghosts as $name) {
                unset($apps[$name]);
            }
            $registry['apps'] = $apps;
            file_put_contents($appsFile, json_encode($registry, JSON_PRETTY_PRINT));
        }

        return $apps;
    }
    
    /**
     * Check if an app exists
     */
    public function appExists(string $appName): bool
    {
        $apps = $this->listApps();
        return isset($apps[$appName]);
    }
    
    /**
     * List available templates
     */
    public function listTemplates(): array
    {
        $templateManager = new TemplateManager();
        return $templateManager->getAvailableTemplates();
    }
    
    /**
     * Get template info
     */
    public function getTemplateInfo(string $templateName): ?array
    {
        $templateManager = new TemplateManager();
        return $templateManager->getTemplateInfo($templateName);
    }
    
    /**
     * Generate container configuration
     */
    public function generateContainers(string $appName): string
    {
        $config = $this->loadAppConfig($appName);
        
        // Extract passwords from config to avoid re-resolving
        $passwords = $this->extractPasswordsFromConfig($appName);
        
        $compose = $this->buildComposeConfig($appName, $config, $passwords);
        
        $yamlContent = $this->arrayToYaml($compose);
        $filename = $this->baseDir . '/' . $appName . '-compose.yml';
        
        file_put_contents($filename, $yamlContent);
        
        return $filename;
    }
    
    /**
     * Load app configuration
     */
    public function loadAppConfig(string $appName): array
    {
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config file not found for app '$appName'");
        }
        
        return json_decode(file_get_contents($configFile), true);
    }
    
    /**
     * Build compose configuration
     */
    private function buildComposeConfig(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $compose = [
            'version' => '3.8',
            'name' => $appName,
            'networks' => [
                $appName . '-net' => ['driver' => 'bridge']
            ],
            'services' => []
        ];
        
        // App container. depends_on is built up per enabled feature below;
        // an app with no database must not reference the missing -db service.
        $compose['services'][$appName . '-app'] = [
            'build' => [
                'context' => '.',
                'args' => [
                    'APP_NAME' => $appName
                ]
            ],
            'container_name' => $appName . '-app',
            'ports' => [$config['containers']['app']['port'] . ':8080'],
            // No volumes: nothing reads /var/www/.installer at runtime, and
            // mounting the instance dir exposed app.nimbus.json (plaintext
            // passwords) inside the container. Dev mode adds its own mounts
            // via the compose.dev.yml overlay.
            'networks' => [$appName . '-net']
        ];

        // Database container
        $hasDatabase = $config['features']['database'] ?? true;
        if ($hasDatabase) {
            $compose['services'][$appName . '-app']['depends_on'][] = $appName . '-db';
            $compose['services'][$appName . '-db'] = $this->buildDatabaseService($appName, $config, $passwords);
        }
        
        // EDA container
        if ($config['features']['eda'] ?? false) {
            $edaImage = $config['containers']['eda']['image'] ?? self::DEFAULT_EDA_IMAGE;
            $rulebooksDir = $config['containers']['eda']['rulebooks_dir'] ?? 'rulebooks';
            $edaPort = $this->generateEdaPort($appName);
            
            $compose['services'][$appName . '-eda'] = [
                'image' => $edaImage,
                'container_name' => $appName . '-eda',
                'ports' => [$edaPort . ':5000'],
                'volumes' => [
                    './.installer/apps/' . $appName . '/eda/rulebooks:/rulebooks:Z',
                    './.installer/apps/' . $appName . '/eda/playbooks:/playbooks:Z',
                    './.installer/apps/' . $appName . '/inventory:/inventory:Z',
                    './.installer/apps/' . $appName . '/logs:/logs:Z',
                    './.installer/apps/' . $appName . '/init-entrypoint.sh:/init-entrypoint.sh:Z'
                ],
                'working_dir' => '/rulebooks',
                'entrypoint' => ['sh', '/init-entrypoint.sh'],
                'restart' => 'unless-stopped',
                'networks' => [$appName . '-net']
            ];

            // The configure-keycloak playbook authenticates against the
            // Keycloak admin API using KEYCLOAK_ADMIN_PASSWORD from the EDA
            // container's environment (lookup('env', ...)).
            if ($config['features']['keycloak'] ?? false) {
                $compose['services'][$appName . '-eda']['environment'] = [
                    'KEYCLOAK_ADMIN_PASSWORD' => $passwords
                        ? $passwords->keycloakAdminPassword
                        : ($config['containers']['keycloak']['admin_password'] ?? 'admin')
                ];
            }

            // EDA waits for the database only when the app actually has one
            if ($hasDatabase) {
                $compose['services'][$appName . '-eda']['depends_on'] = [
                    $appName . '-db' => [
                        'condition' => 'service_healthy'
                    ]
                ];
            }
        }
        
        // Keycloak containers
        if ($config['features']['keycloak'] ?? false) {
            $keycloakServices = $this->buildKeycloakServices($appName, $config, $passwords);
            $compose['services'] = array_merge($compose['services'], $keycloakServices);

            // App waits for Keycloak regardless of whether a database is present
            $compose['services'][$appName . '-app']['depends_on'][] = $appName . '-keycloak';
        }
        
        return $compose;
    }
    
    /**
     * Build database service configuration with PasswordSet
     */
    private function buildDatabaseService(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $dbEnvironment = [
            'POSTGRES_DB' => $config['database']['name'] ?? $appName . '_db',
            'POSTGRES_USER' => $config['database']['user'] ?? $appName . '_user',
            'POSTGRES_PASSWORD' => $passwords ? $passwords->databasePassword : ($config['database']['password'] ?? '')
        ];
        
        $dbVolumes = [
            //'./data/' . $appName . ':/var/lib/postgresql/data:Z',
            './.installer/apps/' . $appName . '/database/schema.sql:/docker-entrypoint-initdb.d/schema.sql:Z'
        ];
        
        // Add force init for vault restore with existing data
        if ($passwords && $passwords->requiresForceInit) {
            $dbEnvironment['FORCE_INIT'] = 'true';
            $dbVolumes[] = './.installer/apps/' . $appName . '/database/force-init.sh:/docker-entrypoint-initdb.d/force-init.sh:Z';
        }
        
        return [
            'image' => 'postgres:14',
            'container_name' => $appName . '-postgres',
            'environment' => $dbEnvironment,
            'volumes' => $dbVolumes,
            'networks' => [$appName . '-net'],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'pg_isready -U ' . ($config['database']['user'] ?? $appName . '_user') . ' -d ' . ($config['database']['name'] ?? $appName . '_db')],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5
            ]
        ];
    }
    
    /**
     * Build Keycloak services with PasswordSet support
     */
    private function buildKeycloakServices(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $services = [];
        
        // Keycloak database container
        $services[$appName . '-keycloak-db'] = [
            'image' => $config['containers']['keycloak-db']['image'] ?? 'postgres:14',
            'container_name' => $appName . '-keycloak-db',
            'environment' => [
                'POSTGRES_DB' => $config['containers']['keycloak-db']['database'] ?? 'keycloak_db',
                'POSTGRES_USER' => $config['containers']['keycloak-db']['user'] ?? 'keycloak',
                'POSTGRES_PASSWORD' => $passwords ? $passwords->keycloakDbPassword : ($config['containers']['keycloak-db']['password'] ?? 'keycloak')
            ],
            'volumes' => [
                './data/' . $appName . '-keycloak:/var/lib/postgresql/data:Z'
            ],
            'networks' => [$appName . '-net'],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'pg_isready -U keycloak -d keycloak_db'],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5
            ]
        ];
        
        // Keycloak container with auto-configuration
        $keycloakHostPort = $config['containers']['keycloak']['port'] ?? $this->generateKeycloakPort($appName);
        $services[$appName . '-keycloak'] = [
            'image' => $config['containers']['keycloak']['image'] ?? 'quay.io/keycloak/keycloak:latest',
            'container_name' => $appName . '-keycloak',
            'command' => ['start-dev'],
            'environment' => [
                'KC_DB' => 'postgres',
                'KC_DB_URL' => 'jdbc:postgresql://' . $appName . '-keycloak-db:5432/keycloak_db',
                'KC_DB_USERNAME' => $config['containers']['keycloak-db']['user'] ?? 'keycloak',
                'KC_DB_PASSWORD' => $passwords ? $passwords->keycloakDbPassword : ($config['containers']['keycloak-db']['password'] ?? 'keycloak'),
                'KEYCLOAK_ADMIN' => $config['containers']['keycloak']['admin_user'] ?? 'admin',
                'KEYCLOAK_ADMIN_PASSWORD' => $passwords ? $passwords->keycloakAdminPassword : ($config['containers']['keycloak']['admin_password'] ?? 'admin'),
                // Pin the frontend issuer to the host-published URL so tokens
                // authorized in the browser (localhost:<port>) validate on
                // server-side calls too. Without this, Keycloak derives the
                // issuer per-request: the app's userinfo call arriving via the
                // container hostname rejects browser-issued tokens with 401
                // ("Failed to get user information" at login).
                'KC_HOSTNAME' => 'http://localhost:' . $keycloakHostPort,
                // ...while still accepting server-side (backchannel) requests
                // on the container-network hostname.
                'KC_HOSTNAME_BACKCHANNEL_DYNAMIC' => '"true"',
                'KC_HTTP_ENABLED' => '"true"'
            ],
            'volumes' => [
                './.installer/apps/' . $appName . '/keycloak-init.sh:/opt/keycloak/keycloak-init.sh:Z'
            ],
            'ports' => [$keycloakHostPort . ':8080'],
            'depends_on' => [
                $appName . '-keycloak-db' => [
                    'condition' => 'service_healthy'
                ]
            ],
            'networks' => [$appName . '-net'],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'exec 3<>/dev/tcp/127.0.0.1/8080'],
                'interval' => '10s',
                'timeout' => '5s',
                'retries' => 10,
                'start_period' => '40s'
            ]
        ];
        
        // Keycloak auto-configurator container (runs once then exits)
        $services[$appName . '-keycloak-setup'] = [
            'image' => 'alpine:latest',
            'container_name' => $appName . '-keycloak-setup',
            'environment' => [
                'KEYCLOAK_URL' => 'http://' . $appName . '-keycloak:8080',
                'KEYCLOAK_ADMIN_USER' => $config['containers']['keycloak']['admin_user'] ?? 'admin',
                'KEYCLOAK_ADMIN_PASSWORD' => $passwords ? $passwords->keycloakAdminPassword : ($config['containers']['keycloak']['admin_password'] ?? 'admin'),
                'KEYCLOAK_REALM' => $config['keycloak']['realm'] ?? $appName . '-realm',
                'KEYCLOAK_CLIENT_ID' => $config['keycloak']['client_id'] ?? $appName . '-client',
                'KEYCLOAK_CLIENT_SECRET' => $config['keycloak']['client_secret'] ?? '',
                'APP_NAME' => $appName,
                'APP_PORT' => $config['containers']['app']['port']
            ],
            'volumes' => [
                './.installer/apps/' . $appName . '/keycloak-init.sh:/keycloak-init.sh:Z'
            ],
            'command' => ['sh', '-c', 'apk add --no-cache curl jq && sh /keycloak-init.sh'],
            'depends_on' => [
                $appName . '-keycloak' => [
                    'condition' => 'service_healthy'
                ]
            ],
            'networks' => [$appName . '-net'],
            'restart' => 'never'
        ];
        
        return $services;
    }
    
    /**
     * Copy directory recursively
     */
    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $destPath = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }
    
    /**
     * Copy single file
     */
    private function copyFile(string $source, string $dest): void
    {
        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        copy($source, $dest);
    }
    
    /**
     * Replace placeholders in files or content
     */
    private function replacePlaceholders($target, array $replacements): string
    {
        // If $target is a path (directory)
        if (is_dir($target)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($target, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    // Skip keycloak-init.sh as it uses environment variables at runtime
                    if (basename($file->getPathname()) === 'keycloak-init.sh') {
                        continue;
                    }
                    $content = file_get_contents($file->getPathname());
                    $content = str_replace(array_keys($replacements), array_values($replacements), $content);
                    file_put_contents($file->getPathname(), $content);
                }
            }
            return '';
        }
        // If $target is content (string)
        else {
            return str_replace(array_keys($replacements), array_values($replacements), $target);
        }
    }
    
    /**
     * Register app in apps.json
     */
    private function registerApp(string $appName, string $template): void
    {
        $appsFile = $this->baseDir . '/.installer/apps.json';
        $apps = [];
        
        if (file_exists($appsFile)) {
            $apps = json_decode(file_get_contents($appsFile), true);
        }
        
        $apps['apps'][$appName] = [
            'name' => $appName,
            'template' => $template,
            'created' => date('Y-m-d H:i:s'),
            'installed' => false
        ];
        
        file_put_contents($appsFile, json_encode($apps, JSON_PRETTY_PRINT));
    }
    
    /**
     * Update composer.json with new app registration
     */
    private function updateComposerJson(string $appName): void
    {
        $composerFile = $this->baseDir . '/composer.json';
        $composer = json_decode(file_get_contents($composerFile), true);
        
        // Note: No longer auto-generating install commands since composer nimbus:install works
        // Note: No longer auto-generating asset definitions since they're not used
        
        file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Validate app name
     */
    private function validateAppName(string $name): void
    {
        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException("App name must contain only lowercase letters, numbers, and hyphens");
        }
    }
    
    /**
     * Generate unique port based on app name
     */
    private function generatePort(string $appName): int
    {
        $hash = crc32($appName);
        return 8000 + ($hash % 1000);
    }
    
    /**
     * Generate unique EDA port based on app name
     */
    private function generateEdaPort(string $appName): int
    {
        $hash = crc32($appName . '_eda');
        return 5000 + ($hash % 1000);
    }
    
    /**
     * Generate unique Keycloak port based on app name
     */
    private function generateKeycloakPort(string $appName): int
    {
        $hash = crc32($appName . '_keycloak');
        return 9000 + ($hash % 1000);
    }

    /**
     * Generate unique code-server (dev mode) port based on app name
     */
    private function generateCodeServerPort(string $appName): int
    {
        // 10500-11499: clear of app (8xxx), eda (5xxx) and keycloak (9xxx) bands
        $hash = crc32($appName . '_codeserver');
        return 10500 + ($hash % 1000);
    }
    
    /**
     * Generate secure password
     */
    private function generatePassword(int $length = 16): string
    {
        // For compatibility, if length is 16, use the original hex method
        if ($length === 16) {
            return bin2hex(random_bytes(16));
        }
        
        // For other lengths, use character-based generation
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    /**
     * Get existing database password from previous app installation
     * This prevents password race conditions when recreating apps
     */
    private function getExistingDatabasePassword(string $appName): ?string
    {
        $dataDir = $this->baseDir . '/data/' . $appName;
        
        // If no data directory exists, there's no existing password
        if (!is_dir($dataDir)) {
            return null;
        }
        
        // Try to get password from existing compose file first
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        if (file_exists($composeFile)) {
            $password = $this->extractPasswordFromCompose($composeFile);
            if ($password) {
                return $password;
            }
        }
        
        // Try to get password from running container
        $containerName = $appName . '-postgres';
        $password = $this->extractPasswordFromContainer($containerName);
        if ($password) {
            return $password;
        }
        
        // If we can't determine the existing password, we'll need to reset the database
        // This is safer than guessing - we'll force removal of old data
        error_log("Warning: Found existing database data for '$appName' but couldn't determine password. Consider removing data directory manually.");
        
        return null;
    }
    
    /**
     * Extract database password from compose file
     */
    private function extractPasswordFromCompose(string $composeFile): ?string
    {
        $content = file_get_contents($composeFile);
        if (!$content) {
            return null;
        }
        
        // Look for POSTGRES_PASSWORD in the compose file
        if (preg_match('/POSTGRES_PASSWORD:\s*([a-f0-9]{32})/', $content, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Extract database password from running container
     */
    private function extractPasswordFromContainer(string $containerName): ?string
    {
        // Try to inspect the container to get environment variables
        $inspectCmd = "podman inspect $containerName --format '{{json .Config.Env}}' 2>/dev/null";
        $output = shell_exec($inspectCmd);
        
        if (!$output) {
            return null;
        }
        
        $envVars = json_decode(trim($output), true);
        if (!is_array($envVars)) {
            return null;
        }
        
        foreach ($envVars as $envVar) {
            if (strpos($envVar, 'POSTGRES_PASSWORD=') === 0) {
                return substr($envVar, 18); // Remove "POSTGRES_PASSWORD=" prefix
            }
        }
        
        return null;
    }
    
    /**
     * Get existing Keycloak database password from previous app installation
     */
    private function getExistingKeycloakPassword(string $appName): ?string
    {
        $keycloakDataDir = $this->baseDir . '/data/' . $appName . '-keycloak';
        
        // If no Keycloak data directory exists, there's no existing password
        if (!is_dir($keycloakDataDir)) {
            return null;
        }
        
        // Try to get password from existing compose file
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        if (file_exists($composeFile)) {
            $content = file_get_contents($composeFile);
            // Look for Keycloak database password (try both POSTGRES_PASSWORD and KC_DB_PASSWORD)
            if (preg_match('/' . $appName . '-keycloak-db:.*?POSTGRES_PASSWORD:\s*([a-f0-9]{32})/s', $content, $matches)) {
                return $matches[1];
            }
            if (preg_match('/KC_DB_PASSWORD:\s*([a-f0-9]{32})/', $content, $matches)) {
                return $matches[1];
            }
        }
        
        // Try to get password from running Keycloak database container
        $containerName = $appName . '-keycloak-db';
        return $this->extractPasswordFromContainer($containerName);
    }

    /**
     * Get existing Keycloak admin password from previous app installation
     */
    private function getExistingKeycloakAdminPassword(string $appName): ?string
    {
        // Try to get admin password from existing compose file
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        if (file_exists($composeFile)) {
            $content = file_get_contents($composeFile);
            // Look for Keycloak admin password
            if (preg_match('/KEYCLOAK_ADMIN_PASSWORD:\s*([a-f0-9]{32})/', $content, $matches)) {
                return $matches[1];
            }
        }
        
        // Try to get password from running Keycloak container
        $containerName = $appName . '-keycloak';
        $inspectCmd = "podman inspect $containerName --format '{{json .Config.Env}}' 2>/dev/null";
        $output = shell_exec($inspectCmd);
        
        if ($output) {
            $envVars = json_decode(trim($output), true);
            if (is_array($envVars)) {
                foreach ($envVars as $envVar) {
                    if (strpos($envVar, 'KEYCLOAK_ADMIN_PASSWORD=') === 0) {
                        return substr($envVar, 24); // Remove "KEYCLOAK_ADMIN_PASSWORD=" prefix
                    }
                }
            }
        }
        
        // Try to get from existing app.nimbus.json file
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (isset($config['containers']['keycloak']['admin_password'])) {
                return $config['containers']['keycloak']['admin_password'];
            }
        }
        
        return null;
    }

    /**
     * Get VaultManager instance
     */
    private function getVaultManager(): \Nimbus\Vault\VaultManager
    {
        return new \Nimbus\Vault\VaultManager($this->baseDir);
    }
    
    /**
     * Get credentials from vault if available (legacy method)
     */
    private function getVaultCredentials(string $appName): array
    {
        try {
            $vaultManager = $this->getVaultManager();
            
            if (!$vaultManager->isInitialized()) {
                return [];
            }
            
            return $vaultManager->restoreAppCredentials($appName) ?: [];
            
        } catch (\Exception $e) {
            // Silently fail - vault is optional
            return [];
        }
    }
    
    /**
     * Enable or disable EDA for an existing app
     */
    public function setEda(string $appName, bool $enabled): bool
    {
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        
        if (!file_exists($configFile)) {
            throw new \RuntimeException("App '$appName' not found");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        $config['features']['eda'] = $enabled;
        
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        
        return true;
    }
    
    /**
     * Add EDA functionality to an existing app
     */
    public function addEda(string $appName): bool
    {
        $appPath = $this->installerDir . '/' . $appName;
        $configFile = $appPath . '/app.nimbus.json';
        
        if (!is_dir($appPath)) {
            throw new \RuntimeException("App '$appName' not found");
        }
        
        if (!file_exists($configFile)) {
            throw new \RuntimeException("App config file not found for '$appName'");
        }
        
        // Load current config
        $config = json_decode(file_get_contents($configFile), true);
        
        // Check if EDA is already enabled
        if ($config['features']['eda'] ?? false) {
            throw new \RuntimeException("EDA is already enabled for app '$appName'");
        }
        
        // Enable EDA in config
        $config['features']['eda'] = true;
        
        // Add EDA container configuration if not present
        if (!isset($config['containers']['eda'])) {
            $config['containers']['eda'] = [
                'image' => self::DEFAULT_EDA_IMAGE,
                'rulebooks_dir' => 'rulebooks'
            ];
        }
        
        // Create EDA directories if they don't exist
        $this->createEdaDirectories($appPath, $appName);
        
        // Save updated config
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        
        // Update app.config.php to enable EDA in the app
        $this->updateAppConfigForEda($appPath, true);

        // Regenerate compose file with validation
        $this->regenerateComposeFile($appName, $config);

        return true;
    }

    /**
     * Enable or disable a feature on an existing app.
     *
     * Flips the flag in app.nimbus.json, keeps app.config.php in sync, and
     * regenerates the compose file. Files on disk (eda/, keycloak scripts)
     * are deliberately left untouched so re-enabling is cheap.
     *
     * Only 'eda' and 'keycloak' are supported: 'database' toggling after
     * creation is out of scope (data-loss questions), use --no-db at create.
     */
    public function setFeature(string $appName, string $feature, bool $enabled): bool
    {
        if (!in_array($feature, ['eda', 'keycloak'], true)) {
            throw new \InvalidArgumentException("Unsupported feature '$feature' (supported: eda, keycloak)");
        }

        if (!$this->appExists($appName)) {
            throw new \RuntimeException("App '$appName' not found");
        }

        $appPath = $this->installerDir . '/' . $appName;
        $configFile = $appPath . '/app.nimbus.json';
        if (!file_exists($configFile)) {
            throw new \RuntimeException("App config file not found for '$appName'");
        }

        $config = json_decode(file_get_contents($configFile), true);

        if (($config['features'][$feature] ?? false) === $enabled) {
            $state = $enabled ? 'enabled' : 'disabled';
            throw new \RuntimeException(ucfirst($feature) . " is already $state for app '$appName'");
        }

        $config['features'][$feature] = $enabled;
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

        // Keep app.config.php flags in sync
        if ($feature === 'eda') {
            $this->updateAppConfigForEda($appPath, $enabled);
        } else {
            $this->updateAppConfig($appPath, null, $enabled);
        }

        $this->regenerateComposeFile($appName, $config);

        return true;
    }

    /**
     * Asset keys that are create-time RESOLVED (placeholders substituted with
     * this app's concrete name/ports/passwords), not verbatim copies of the
     * template source. Committing these back to a shared template would bake
     * one app's identity and secrets into every future nimbus:create — never
     * do it. Safe only when committing to a single app's own instance dir,
     * where resolved values are exactly what belongs.
     */
    private const TEMPLATE_UNSAFE_ASSET_KEYS = ['config'];

    /**
     * Copy this app's edits from its instance dir back to the shared template.
     *
     * Dev mode serves .installer/apps/<name>/ directly (each app isolated),
     * so edits already persist per-app. This copies the app-agnostic assets
     * (Controllers/Models/Views/routes) to .installer/_templates/<type>/ so
     * future nimbus:create runs include them. Instance and template share the
     * same layout, so the asset map's 'source' paths apply verbatim.
     *
     * app.config.php is always skipped (TEMPLATE_UNSAFE_ASSET_KEYS): it holds
     * this app's resolved name/ports/passwords, never template material.
     *
     * @return array{committed: string[], skipped: string[]} asset source paths
     */
    public function commitAppToTemplate(string $appName): array
    {
        if (!$this->appExists($appName)) {
            throw new \RuntimeException("App '$appName' not found");
        }

        $config = $this->loadAppConfig($appName);
        $assets = $config['assets'] ?? [];
        if (empty($assets)) {
            throw new \RuntimeException("App '$appName' has no asset map in app.nimbus.json — nothing to commit");
        }

        $instanceRoot = $this->installerDir . '/' . $appName;
        $destRoot = $this->templatesDir . '/' . ($config['type'] ?? $appName);

        if (!is_dir($destRoot)) {
            throw new \RuntimeException(
                "Template directory not found: $destRoot (app.nimbus.json 'type' must match a directory under .installer/_templates/)"
            );
        }

        // This app's identity strings, to scan for AFTER copying. The instance
        // is EXPECTED to contain these — e.g. a template fallback
        // `?? '{{APP_NAME}}'` legitimately resolves to `?? 'demo-dev'` at
        // create time, which is correct and not contamination. What must never
        // happen is one of these strings surviving in the file that ends up in
        // the TEMPLATE — a clean template file can only contain the app's name
        // if someone hardcoded it as a literal (the exact bug this guards
        // against). So we scan the destination, not the source.
        $identityStrings = array_unique(array_filter([
            $appName,
            strtoupper($appName),
            strtolower($appName),
        ]));

        // Resolve which assets will actually be copied, and where from/to.
        $toCopy = [];
        $skipped = [];
        foreach ($assets as $assetKey => $asset) {
            $source = $asset['source'] ?? null;
            if ($source === null) {
                continue;
            }

            if (in_array($assetKey, self::TEMPLATE_UNSAFE_ASSET_KEYS, true)) {
                $skipped[] = $source;
                continue;
            }

            // Instance and template share the same layout — same relative path
            // on both sides.
            $liveSource = $instanceRoot . '/' . $source;
            $dest = $destRoot . '/' . $source;
            $isFile = !empty($asset['isFile']);

            if ($isFile ? !is_file($liveSource) : !is_dir($liveSource)) {
                continue; // nothing to commit for this asset
            }

            $toCopy[] = ['source' => $liveSource, 'dest' => $dest, 'target' => $source, 'isFile' => $isFile];
        }

        // Committing to the SHARED template: back up any destination we're about
        // to overwrite, copy, scan the result, and roll every asset back to its
        // backup if any single one leaks identity — the commit is all-or-nothing.
        $backups = [];
        $created = []; // assets that had no pre-existing dest — rollback deletes these entirely
        try {
            $committed = [];
            foreach ($toCopy as $item) {
                if (file_exists($item['dest'])) {
                    $backup = $item['dest'] . '.nimbus-commit-backup';
                    if ($item['isFile']) {
                        $this->copyFile($item['dest'], $backup);
                    } else {
                        $this->copyDirectory($item['dest'], $backup);
                    }
                    $backups[] = ['dest' => $item['dest'], 'backup' => $backup, 'isFile' => $item['isFile']];
                } else {
                    $created[] = $item;
                }

                if ($item['isFile']) {
                    $this->copyFile($item['source'], $item['dest']);
                    $this->assertNoAppIdentityLeak($item['dest'], $identityStrings);
                } else {
                    // Overwrite in place, not merge: clear the old tree first so a
                    // shrinking template (a file removed from app/) is reflected,
                    // and so restore-on-failure below starts from a clean slate.
                    $this->deleteDirectory($item['dest']);
                    $this->copyDirectory($item['source'], $item['dest']);
                    $this->assertDirectoryHasNoAppIdentityLeak($item['dest'], $identityStrings);
                }

                $committed[] = $item['target'];
            }
        } catch (\RuntimeException $e) {
            foreach ($backups as $b) {
                if ($b['isFile']) {
                    $this->copyFile($b['backup'], $b['dest']);
                    unlink($b['backup']);
                } else {
                    $this->deleteDirectory($b['dest']);
                    $this->copyDirectory($b['backup'], $b['dest']);
                    $this->deleteDirectory($b['backup']);
                }
            }
            foreach ($created as $item) {
                if (!file_exists($item['dest'])) {
                    continue; // never got copied before the failure — nothing to remove
                }
                if ($item['isFile']) {
                    unlink($item['dest']);
                } else {
                    $this->deleteDirectory($item['dest']);
                }
            }
            throw $e;
        }

        foreach ($backups as $b) {
            if ($b['isFile']) {
                unlink($b['backup']);
            } else {
                $this->deleteDirectory($b['backup']);
            }
        }

        return ['committed' => $committed, 'skipped' => $skipped];
    }

    /**
     * Throw if $file contains any of this app's identity strings — evidence
     * of resolved per-app content that must never reach a shared template.
     */
    private function assertNoAppIdentityLeak(string $file, array $identityStrings): void
    {
        $content = file_get_contents($file);
        foreach ($identityStrings as $needle) {
            if ($needle !== '' && str_contains($content, $needle)) {
                throw new \RuntimeException(
                    "Refusing to commit to template: '$file' contains the literal app identity '$needle'. " .
                    "Template source must read this from \$appConfig at runtime instead of hardcoding it " .
                    "(see CLAUDE.md: \"Runtime config: read from \$appConfig\"). Fix the source file, or use " .
                    "--app-only to write only to this app's own .installer/apps/ copy."
                );
            }
        }
    }

    /**
     * Recursively apply assertNoAppIdentityLeak() to every file in a directory.
     */
    private function assertDirectoryHasNoAppIdentityLeak(string $dir, array $identityStrings): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $this->assertNoAppIdentityLeak($file->getPathname(), $identityStrings);
            }
        }
    }

    /**
     * Create all EDA directories and files from template
     */
    private function createEdaDirectories(string $appPath, string $appName): void
    {
        $templatePath = $this->templatesDir . '/' . $this->templateConfig->getDefaultTemplate();

        // template-relative source => app-relative target. These differ for
        // playbooks: templates keep them at playbooks/, but the EDA container
        // mounts <app>/eda/playbooks at /playbooks (see buildComposeConfig),
        // so they must be copied into eda/ or the rulebook's run_playbook
        // action fails at runtime with "Could not find a playbook".
        $edaFiles = [
            'init-entrypoint.sh' => 'init-entrypoint.sh',
            'inventory/inventory.yml' => 'inventory/inventory.yml',
            'playbooks/api-notification.yml' => 'eda/playbooks/api-notification.yml',
            // Keycloak auto-configuration: run_playbook by the
            // keycloak-config.yml rulebook, so they must live in the
            // mounted eda/playbooks dir or the rules fail at runtime.
            'playbooks/configure-keycloak.yml' => 'eda/playbooks/configure-keycloak.yml',
            'playbooks/keycloak-health.yml' => 'eda/playbooks/keycloak-health.yml',
        ];

        $edaDirs = ['eda/rulebooks', 'eda/playbooks', 'inventory', 'logs'];

        // Create directories
        foreach ($edaDirs as $dir) {
            $dirPath = $appPath . '/' . $dir;
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0755, true);
            }
        }

        // Copy template files with app name substitution
        foreach ($edaFiles as $sourceRel => $targetRel) {
            $sourcePath = $templatePath . '/' . $sourceRel;
            $targetPath = $appPath . '/' . $targetRel;
            $file = $targetRel;

            if (file_exists($sourcePath)) {
                $content = file_get_contents($sourcePath);
                $content = str_replace('{{APP_NAME}}', $appName, $content);
                
                // Ensure target directory exists
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                file_put_contents($targetPath, $content);
                
                // Make executable if it's the entrypoint script
                if (basename($file) === 'init-entrypoint.sh') {
                    chmod($targetPath, 0755);
                }
            }
        }
        
        // Copy existing rulebooks
        $this->copyEdaRulebooks($appName, $appPath . '/eda/rulebooks');
    }
    
    /**
     * Copy EDA rulebooks from template
     */
    private function copyEdaRulebooks(string $appName, string $targetDir): void
    {
        $templateRulebooksDir = $this->templatesDir . '/' . $this->templateConfig->getDefaultTemplate() . '/eda/rulebooks';
        
        if (!is_dir($templateRulebooksDir)) {
            // Try old location for backward compatibility
            $templateRulebooksDir = $this->templatesDir . '/' . $this->templateConfig->getDefaultTemplate() . '/rulebooks';
            if (!is_dir($templateRulebooksDir)) {
                // Rulebooks are optional for some templates
                return;
            }
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateRulebooksDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $targetFile = $targetDir . '/' . $iterator->getSubPathName();
            $targetFileDir = dirname($targetFile);

            if (!is_dir($targetFileDir)) {
                mkdir($targetFileDir, 0755, true);
            }

            $content = file_get_contents($file->getPathname());
            // Replace placeholders
            $content = str_replace('{{APP_NAME}}', $appName, $content);
            $content = str_replace('{{APP_NAME_UPPER}}', strtoupper($appName), $content);
            $content = str_replace('{{APP_NAME_LOWER}}', strtolower($appName), $content);
            
            file_put_contents($targetFile, $content);
        }
    }
    
    /**
     * Update app.config.php to set has_eda flag
     */
    private function updateAppConfigForEda(string $appPath, bool $hasEda): void
    {
        $appConfigFile = $appPath . '/app.config.php';
        
        if (!file_exists($appConfigFile)) {
            throw new \RuntimeException("App config file not found: $appConfigFile");
        }
        
        $content = file_get_contents($appConfigFile);
        
        // Update the has_eda value.
        // Tolerate legacy quoted values ('false'/"false") and always write a real boolean.
        $edaValue = $hasEda ? 'true' : 'false';
        $content = preg_replace(
            "/'has_eda'\s*=>\s*['\"]?(true|false)['\"]?/",
            "'has_eda' => $edaValue",
            $content
        );
        
        file_put_contents($appConfigFile, $content);
    }
    
    /**
     * Regenerate compose file with YAML validation
     */
    private function regenerateComposeFile(string $appName, array $config): void
    {
        // Keep the installed app config as the source of truth. Resolving from
        // an existing compose file can preserve stale credentials and leave the
        // mounted app.config.php unable to connect to its own database.
        $passwords = $this->extractPasswordsFromConfig($appName);
        
        $compose = $this->buildComposeConfig($appName, $config, $passwords);
        $yamlContent = $this->arrayToYaml($compose);
        
        // Validate YAML before writing
        if (!$this->validateYaml($yamlContent)) {
            throw new \RuntimeException("Generated YAML is invalid");
        }
        
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        file_put_contents($composeFile, $yamlContent);
    }
    
    /**
     * Validate YAML content
     */
    private function validateYaml(string $yamlContent): bool
    {
        try {
            // Basic YAML validation - check for common syntax errors
            $lines = explode("\n", $yamlContent);
            $indentStack = [];
            
            foreach ($lines as $lineNum => $line) {
                $trimmed = trim($line);
                
                // Skip empty lines and comments
                if (empty($trimmed) || $trimmed[0] === '#') {
                    continue;
                }
                
                // Check for basic YAML syntax issues
                if (strpos($line, "\t") !== false) {
                    throw new \Exception("YAML cannot contain tabs (line " . ($lineNum + 1) . ")");
                }
                
                // Check for proper list formatting (double dash at start of line)
                if (preg_match('/^\s*-\s*-\s/', $line)) {
                    throw new \Exception("Invalid list formatting (line " . ($lineNum + 1) . ")");
                }
                
                // Check for colon placement
                if (preg_match('/^\s*[^:]+::/', $line)) {
                    throw new \Exception("Invalid colon usage (line " . ($lineNum + 1) . ")");
                }
            }
            
            // Additional validation: try parsing with a simple YAML-like parser
            return $this->basicYamlParse($yamlContent);
            
        } catch (\Exception $e) {
            error_log("YAML validation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Basic YAML structure validation
     */
    private function basicYamlParse(string $yamlContent): bool
    {
        $lines = explode("\n", $yamlContent);
        $bracketStack = [];
        
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }
            
            // Count brackets/braces for structure validation
            $openBrackets = substr_count($line, '[');
            $closeBrackets = substr_count($line, ']');
            $openBraces = substr_count($line, '{');
            $closeBraces = substr_count($line, '}');
            
            // Basic bracket matching
            if ($openBrackets !== $closeBrackets && 
                (strpos($line, '[') !== false || strpos($line, ']') !== false)) {
                return false;
            }
            
            if ($openBraces !== $closeBraces && 
                (strpos($line, '{') !== false || strpos($line, '}') !== false)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get available apps that can be started
     */
    public function getStartableApps(): array
    {
        $startableApps = [];
        $composeFiles = glob($this->baseDir . '/*-compose.yml');
        
        foreach ($composeFiles as $composeFile) {
            $basename = basename($composeFile, '-compose.yml');
            $appPath = $this->installerDir . '/' . $basename;
            
            if (is_dir($appPath)) {
                $runningStatus = $this->checkAppRunningStatus($basename);
                
                $startableApps[] = [
                    'name' => $basename,
                    'compose_file' => $composeFile,
                    'has_image' => $this->checkImageExists($basename),
                    'is_running' => $runningStatus['is_running'],
                    'containers' => $runningStatus['containers'],
                    'health_status' => $runningStatus['health_status']
                ];
            }
        }
        
        return $startableApps;
    }
    
    /**
     * Check if app image exists
     */
    private function checkImageExists(string $appName): bool
    {
        $imageName = $appName . '_' . $appName . '-app';
        $output = shell_exec("podman images -q $imageName 2>/dev/null");
        return !empty(trim($output ?? ''));
    }
    
    /**
     * Check if app is running and get container health status
     */
    private function checkAppRunningStatus(string $appName): array
    {
        $containers = $this->getAppContainers($appName);
        $runningContainers = 0;
        $healthyContainers = 0;
        $totalContainers = count($containers);
        $containerDetails = [];
        
        foreach ($containers as $containerName) {
            $status = $this->getContainerStatus($containerName);
            $containerDetails[$containerName] = $status;
            
            if ($status['state'] === 'running') {
                $runningContainers++;
                
                // Consider container healthy if it's running and either:
                // - Has no health check (health = 'none')
                // - Has a health check and is healthy
                // - Health check is starting up
                if ($status['health'] === 'healthy' || 
                    $status['health'] === 'none' || 
                    ($status['health'] === 'starting' && $containerName !== $appName . '-postgres')) {
                    $healthyContainers++;
                }
            }
        }
        
        $isRunning = $runningContainers > 0;
        $healthStatus = 'unknown';
        
        if ($totalContainers === 0) {
            $healthStatus = 'no-containers';
        } elseif ($runningContainers === 0) {
            $healthStatus = 'stopped';
        } elseif ($runningContainers === $totalContainers && $healthyContainers === $totalContainers) {
            $healthStatus = 'healthy';
        } elseif ($runningContainers === $totalContainers) {
            $healthStatus = 'running-unhealthy';
        } else {
            $healthStatus = 'partial';
        }
        
        return [
            'is_running' => $isRunning,
            'containers' => $containerDetails,
            'health_status' => $healthStatus,
            'running_count' => $runningContainers,
            'total_count' => $totalContainers,
            'healthy_count' => $healthyContainers
        ];
    }
    
    /**
     * Get expected container names for an app
     */
    private function getAppContainers(string $appName): array
    {
        $containers = [
            $appName . '-app'
        ];

        // Feature-gated containers, read from app.nimbus.json
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = file_exists($configFile)
            ? json_decode(file_get_contents($configFile), true)
            : [];

        if ($config['features']['database'] ?? true) {
            $containers[] = $appName . '-postgres';  // database container
        }
        if ($config !== []) {
            if ($config['features']['eda'] ?? false) {
                $containers[] = $appName . '-eda';
            }
            // Check if Keycloak is enabled for this app
            if ($config['features']['keycloak'] ?? false) {
                $containers[] = $appName . '-keycloak';
                $containers[] = $appName . '-keycloak-db';
            }
            // Dev mode adds a code-server sidecar via the dev overlay
            if ($config['features']['dev'] ?? false) {
                $containers[] = $appName . '-code-server';
            }
        }
        
        return $containers;
    }
    
    /**
     * Live container info for an app: the feature-derived expected list
     * (getAppContainers) merged with what podman actually has. One podman ps
     * call, keyed by the compose project label. Also surfaces orphans —
     * containers still in the project after their feature was disabled.
     * Adding a new feature container to the ecosystem means extending
     * getAppContainers() + the compose generation; view/status/up/down all
     * follow from those.
     *
     * @return array<int, array{name: string, expected: bool, exists: bool, image: string, status: string, ports: string}>
     */
    public function describeContainers(string $appName): array
    {
        $ps = shell_exec("podman ps -a --filter label=io.podman.compose.project=$appName --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null") ?? '';

        $live = [];
        foreach (array_filter(explode("\n", trim($ps))) as $line) {
            $parts = explode("\t", $line);
            $live[$parts[0]] = [
                'image' => $parts[1] ?? '',
                'status' => $parts[2] ?? '',
                'ports' => $parts[3] ?? ''
            ];
        }

        $out = [];
        foreach ($this->getAppContainers($appName) as $name) {
            $out[$name] = [
                'name' => $name,
                'expected' => true,
                'exists' => isset($live[$name])
            ] + ($live[$name] ?? ['image' => '', 'status' => 'not created', 'ports' => '']);
        }
        foreach ($live as $name => $info) {
            if (!isset($out[$name])) {
                $out[$name] = ['name' => $name, 'expected' => false, 'exists' => true] + $info;
            }
        }

        return array_values($out);
    }

    /**
     * Get detailed status of a container
     */
    private function getContainerStatus(string $containerName): array
    {
        $inspectOutput = shell_exec("podman inspect $containerName --format '{{.State.Status}}|{{.State.Health.Status}}' 2>/dev/null");
        
        if (!$inspectOutput) {
            return [
                'name' => $containerName,
                'state' => 'not-found',
                'health' => 'unknown'
            ];
        }
        
        $parts = explode('|', trim($inspectOutput));
        $state = $parts[0] ?? 'unknown';
        $health = $parts[1] ?? 'none';
        
        // Clean up health status
        if (empty($health) || $health === '<no value>') {
            $health = 'none';
        }
        
        return [
            'name' => $containerName,
            'state' => $state,
            'health' => $health
        ];
    }
    
    /**
     * The ordered podman-compose -f file list for an app, derived from its
     * features in app.nimbus.json (single source of truth). Base compose
     * always; the dev overlay when features.dev is enabled — regenerated if
     * missing, since overlay files are derived artifacts. Every lifecycle
     * path (up/down/status) must build its file list here so feature
     * containers like code-server are never started-but-not-stopped.
     */
    public function getComposeFiles(string $appName): array
    {
        $files = [$this->baseDir . '/' . $appName . '-compose.yml'];

        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = file_exists($configFile)
            ? json_decode(file_get_contents($configFile), true)
            : [];

        if ($config['features']['dev'] ?? false) {
            $devFile = $this->baseDir . '/' . $appName . '-compose.dev.yml';
            if (!file_exists($devFile)) {
                $this->generateDevCompose($appName);
            }
            $files[] = $devFile;
        }

        return $files;
    }

    /**
     * Stop an app and optionally remove containers/volumes
     */
    public function stopApp(string $appName, array $options = []): array
    {
        $composeFiles = $this->getComposeFiles($appName);

        if (!file_exists($composeFiles[0])) {
            throw new \RuntimeException("Compose file not found for app '$appName'");
        }

        $commands = [];
        $results = ['stopped' => false, 'removed' => false, 'cleaned' => false, 'output' => ''];

        // Build command based on options; include every feature overlay so
        // their containers (e.g. code-server) are brought down too
        $downCommand = 'podman-compose -f ' . implode(' -f ', $composeFiles) . ' down';
        
        if ($options['remove_volumes'] ?? false) {
            $downCommand .= ' --volumes';
        }
        
        if ($options['timeout'] ?? false) {
            $downCommand .= ' --timeout ' . (int)$options['timeout'];
        }
        
        $output = shell_exec($downCommand . ' 2>&1');
        $results['output'] = $output;
        $results['stopped'] = true;
        
        // Optional: Remove containers completely
        if ($options['remove_containers'] ?? false) {
            $containers = $this->getAppContainers($appName);
            foreach ($containers as $containerName) {
                $removeOutput = shell_exec("podman rm -f $containerName 2>&1");
                $results['output'] .= "\n" . $removeOutput;
            }
            $results['removed'] = true;
        }
        
        // Optional: Clean up images
        if ($options['remove_images'] ?? false) {
            $imageName = $appName . '_' . $appName . '-app';
            $imageOutput = shell_exec("podman rmi $imageName 2>&1");
            $results['output'] .= "\n" . $imageOutput;
            $results['cleaned'] = true;
        }
        
        return $results;
    }
    
    /**
     * Get running apps that can be stopped
     */
    public function getRunningApps(): array
    {
        $runningApps = [];
        $startableApps = $this->getStartableApps();
        
        foreach ($startableApps as $app) {
            if ($app['is_running']) {
                $runningApps[] = $app;
            }
        }
        
        return $runningApps;
    }
    
    /**
     * Check if podman-compose is installed
     */
    public static function checkPodmanCompose(): array
    {
        $result = ['installed' => false, 'version' => null, 'error' => null];
        
        $output = shell_exec('podman-compose --version 2>&1');
        
        if ($output && strpos($output, 'podman-compose') !== false) {
            $result['installed'] = true;
            $result['version'] = trim($output);
        } else {
            $result['error'] = 'podman-compose not found. Install it with: pip3 install podman-compose';
        }
        
        return $result;
    }
    
    /**
     * Generate podman-compose.yml file
     */
    private function generatePodmanCompose(string $appName, array $config): void
    {
        // Extract passwords from the already-generated config instead of resolving again
        $passwords = $this->extractPasswordsFromConfig($appName);
        
        $compose = $this->buildComposeConfig($appName, $config, $passwords);
        $yamlContent = $this->arrayToYaml($compose);

        file_put_contents($this->baseDir . '/' . $appName . '-compose.yml', $yamlContent);
    }

    /**
     * Build the dev-mode compose overlay for an app.
     *
     * The overlay is applied as a second -f file on top of <app>-compose.yml:
     *  - bind-mounts THIS APP'S OWN instance dir (.installer/apps/<name>) as
     *    the served app code, so every dev-mode app is isolated: installing
     *    or editing another app can never swap this one's config/code out
     *    from under it (the old shared ./app mount did exactly that —
     *    "could not translate host name <other-app>-db")
     *  - bind-mounts framework src/ + index.php (correctly shared: identical
     *    for all apps)
     *  - mounts a dev php.ini enabling opcache timestamp revalidation
     *  - overrides the entrypoint to re-dump a non-optimized autoloader so
     *    classes added mid-session resolve via PSR-4
     *  - adds a code-server sidecar editing the same host tree in the browser
     *
     * The instance dir keeps routes at routes/CustomRoutes.php (not the baked
     * layout's app/CustomRoutes.php) — Application::setupRoutes() falls back
     * to that path.
     */
    private function buildDevOverlay(string $appName, array $config): array
    {
        $codeServerPort = $config['containers']['codeserver']['port']
            ?? $this->generateCodeServerPort($appName);
        $codeServerPassword = $config['containers']['codeserver']['password'] ?? '';

        return [
            'version' => '3.8',
            'services' => [
                $appName . '-app' => [
                    'volumes' => [
                        './.installer/apps/' . $appName . ':/var/www/app:Z',
                        './src:/var/www/src:Z',
                        './public/index.php:/var/www/html/index.php:Z',
                        './html/assets:/var/www/html/assets:Z',
                        './docker/dev/opcache-dev.ini:/usr/local/etc/php/conf.d/zz-opcache-dev.ini:Z'
                    ],
                    'entrypoint' => ['/bin/sh', '-c', 'composer dump-autoload -d /var/www && apache2-foreground']
                ],
                $appName . '-code-server' => [
                    'image' => 'codercom/code-server:latest',
                    'container_name' => $appName . '-code-server',
                    'ports' => [$codeServerPort . ':8080'],
                    'volumes' => [
                        './.installer/apps/' . $appName . ':/home/coder/workspace:Z'
                    ],
                    'environment' => [
                        'PASSWORD' => $codeServerPassword
                    ],
                    'command' => ['--bind-addr', '0.0.0.0:8080', '/home/coder/workspace'],
                    'networks' => [$appName . '-net']
                ]
            ]
        ];
    }

    /**
     * Generate <app>-compose.dev.yml and persist code-server settings.
     *
     * Returns ['file' => ..., 'port' => ..., 'password' => ...] so callers
     * can print connection details.
     */
    public function generateDevCompose(string $appName): array
    {
        if (!$this->appExists($appName)) {
            throw new \RuntimeException("App '$appName' not found. Create it first with nimbus:create");
        }

        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = $this->loadAppConfig($appName);

        // Persist code-server port + password once so they survive regeneration,
        // and flag dev as an app feature so up/down/status include the sidecar
        $dirty = false;
        if (empty($config['containers']['codeserver']['password'])) {
            $config['containers']['codeserver'] = [
                'port' => (string) $this->generateCodeServerPort($appName),
                'password' => $this->generatePassword()
            ];
            $dirty = true;
        }
        if (!($config['features']['dev'] ?? false)) {
            $config['features']['dev'] = true;
            $dirty = true;
        }
        if ($dirty) {
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        }

        $overlay = $this->buildDevOverlay($appName, $config);
        $file = $this->baseDir . '/' . $appName . '-compose.dev.yml';
        file_put_contents($file, $this->arrayToYaml($overlay));

        return [
            'file' => $file,
            'port' => $config['containers']['codeserver']['port'],
            'password' => $config['containers']['codeserver']['password']
        ];
    }
    
    /**
     * Extract passwords from already-generated app config to avoid re-resolving
     */
    private function extractPasswordsFromConfig(string $appName): ?\Nimbus\Password\PasswordSet
    {
        $config = $this->loadAppConfig($appName);
        
        return new \Nimbus\Password\PasswordSet(
            databasePassword: $config['database']['password'] ?? '',
            keycloakAdminPassword: $config['containers']['keycloak']['admin_password'] ?? '',
            keycloakDbPassword: $config['containers']['keycloak-db']['password'] ?? '',
            keycloakClientSecret: $config['keycloak']['client_secret'] ?? '',
            strategy: \Nimbus\Password\PasswordStrategy::from($config['password_strategy'] ?? 'generate_new'),
            baseDir: $this->baseDir,
            appName: $appName
        );
    }
    
    /**
     * Simple array to YAML converter
     */
    private function arrayToYaml(array $array, int $indent = 0): string
    {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // Check if this is a numeric indexed array (for YAML lists)
                if (array_keys($value) === range(0, count($value) - 1)) {
                    $yaml .= $prefix . $key . ":\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= $prefix . "  - " . trim($this->arrayToYaml($item, $indent + 2)) . "\n";
                        } else {
                            $yaml .= $prefix . "  - " . $item . "\n";
                        }
                    }
                } else {
                    $yaml .= $prefix . $key . ":\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                }
            } else {
                // Handle null values
                if ($value === null) {
                    $yaml .= $prefix . $key . ": null\n";
                } elseif ($this->needsQuoting($value)) {
                    // Quote values that contain special YAML characters
                    $yaml .= $prefix . $key . ': "' . $value . "\"\n";
                } else {
                    $yaml .= $prefix . $key . ': ' . $value . "\n";
                }
            }
        }
        
        return $yaml;
    }
    
    /**
     * Check if a value needs to be quoted in YAML
     */
    private function needsQuoting(mixed $value): bool
    {
        // Numbers and booleans don't need quoting
        if (is_numeric($value) || is_bool($value)) {
            return false;
        }
        
        // Convert to string for checking
        $stringValue = (string) $value;
        
        // Quote if it contains special YAML characters that could cause parsing issues
        return preg_match('/[!@#$%^&*()+=\[\]{}|;:,.<>?~`]/', $stringValue) === 1;
    }
    
    /**
     * Add Keycloak support to an existing app
     */
    public function deleteApp(string $appName, array $options = []): bool
    {
        $appDir = $this->installerDir . '/' . $appName;
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';

        if (!is_dir($appDir)) {
            // Directory is already gone (manual cleanup, interrupted prior
            // delete, etc.) — the end state the caller wants (app gone) is
            // already true. Clean up any stragglers (stale registry entry,
            // orphaned compose file, data dir) and report success rather
            // than erroring on a delete that's effectively already done.
            $appsFile = $this->baseDir . '/.installer/apps.json';
            if (file_exists($appsFile)) {
                $registry = json_decode(file_get_contents($appsFile), true);
                if (isset($registry['apps'][$appName])) {
                    unset($registry['apps'][$appName]);
                    file_put_contents($appsFile, json_encode($registry, JSON_PRETTY_PRINT));
                }
            }
            if (file_exists($composeFile)) {
                unlink($composeFile);
            }
            $dataDir = $this->baseDir . '/data/' . $appName;
            if (is_dir($dataDir)) {
                $this->deleteDirectory($dataDir);
            }
            return true;
        }

        // Stop and remove containers first
        if (file_exists($composeFile)) {
            $downCommand = "podman-compose -f $composeFile down";
            if ($options['remove_volumes'] ?? false) {
                $downCommand .= ' --volumes';
            }
            shell_exec($downCommand . ' 2>&1');
        }

        // Remove images if requested
        if ($options['remove_images'] ?? false) {
            // Remove the main app image
            $appImageName = $appName . '_' . $appName . '-app';
            $imageOutput = shell_exec("podman rmi $appImageName 2>&1");
            
            // Also try to remove any other app-specific images (in case of naming variations)
            $allImages = shell_exec("podman images --format '{{.Repository}}' 2>/dev/null");
            if ($allImages) {
                $imageLines = explode("\n", trim($allImages));
                foreach ($imageLines as $image) {
                    // Remove any images that start with the app name
                    if (strpos($image, $appName . '_') === 0 || strpos($image, $appName . '-') === 0) {
                        echo "Deleting image $image...\n";
                        shell_exec("podman rmi $image 2>&1");
                    }
                }
            }
        }

        // Remove app directory
        $this->deleteDirectory($appDir);

        // Remove from apps registry
        $appsFile = $this->baseDir . '/.installer/apps.json';
        if (file_exists($appsFile)) {
            $apps = json_decode(file_get_contents($appsFile), true);
            unset($apps['apps'][$appName]);
            file_put_contents($appsFile, json_encode($apps, JSON_PRETTY_PRINT));
        }

        // Remove compose file
        if (file_exists($composeFile)) {
            unlink($composeFile);
        }

        // Remove data volumes
        $dataDir = $this->baseDir . '/data/' . $appName;
        if (is_dir($dataDir)) {
            $this->deleteDirectory($dataDir);
        }

        // Remove Keycloak data if exists
        $keycloakDataDir = $this->baseDir . '/data/' . $appName . '-keycloak';
        if (is_dir($keycloakDataDir)) {
            $this->deleteDirectory($keycloakDataDir);
        }

        return true;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }

    public function addKeycloak(string $appName, bool $force = false): bool
    {
        $appDir = $this->installerDir . '/' . $appName;
        if (!is_dir($appDir)) {
            throw new \Exception("App directory not found: $appDir");
        }
        
        // Load app configuration
        $configFile = $appDir . '/app.nimbus.json';
        if (!file_exists($configFile)) {
            throw new \Exception("App configuration not found");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        // Check if Keycloak is already enabled (unless force is true)
        if (!$force && isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            throw new \Exception("Keycloak is already enabled for this app");
        }
        
        // Enable Keycloak feature
        $config['features']['keycloak'] = true;
        
        // Use PasswordManager to resolve passwords for Keycloak using NO_MODIFICATIONS strategy
        $passwordManager = new \Nimbus\Password\PasswordManager($this->getVaultManager(), $this->baseDir);
        $passwords = $passwordManager->resolvePasswordsForAddOperation($appName);
        
        // Add Keycloak containers configuration using PasswordSet
        $config['containers']['keycloak'] = [
            'image' => 'quay.io/keycloak/keycloak:latest',
            'port' => (string)$this->generateKeycloakPort($appName),
            'admin_user' => 'admin',
            'admin_password' => $passwords->keycloakAdminPassword,
            'database' => 'keycloak_db'
        ];
        
        $config['containers']['keycloak-db'] = [
            'image' => 'postgres:14',
            'database' => 'keycloak_db',
            'user' => 'keycloak',
            'password' => $passwords->keycloakDbPassword
        ];
        
        // Add Keycloak configuration using PasswordSet
        $config['keycloak'] = [
            'realm' => $appName . '-realm',
            'client_id' => $appName . '-client',
            'client_secret' => $passwords->keycloakClientSecret,
            'auth_url' => "http://{$appName}-keycloak:8080",
            'redirect_uri' => "http://localhost:" . ($config['containers']['app']['port'] ?? '8080') . "/auth/callback"
        ];
        
        // Save updated configuration
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Auto-backup to vault if new passwords were generated
        if ($passwords->strategy === \Nimbus\Password\PasswordStrategy::GENERATE_NEW) {
            $passwordManager->backupToVault($appName, $passwords);
        }
        
        // Update compose file with PasswordSet
        $this->regenerateComposeFile($appName, $config);
        
        // Copy Keycloak-specific files from template
        $this->copyKeycloakFiles($appName);
        
        // Copy and prepare Keycloak initialization script
        $this->copyKeycloakInitScript($appName);
        
        // Update app.config.php to enable Keycloak
        $this->updateAppConfig($appDir, null, true);
        
        return true;
    }
    
    /**
     * Copy Keycloak-specific files from template
     */
    private function copyKeycloakFiles(string $appName): void
    {
        $appDir = $this->installerDir . '/' . $appName;
        $templateDir = $this->templatesDir . '/' . $this->templateConfig->getDefaultTemplate();
        
        // Files to copy for Keycloak
        $keycloakFiles = [
            'Controllers/AuthController.php',
            'Views/auth/configure.mustache',
            'Views/partials/keycloak-section.mustache',
            'rulebooks/keycloak-config.yml',
            'playbooks/configure-keycloak.yml',
            'playbooks/keycloak-health.yml'
        ];
        
        foreach ($keycloakFiles as $file) {
            $sourcePath = $templateDir . '/' . $file;
            $targetPath = $appDir . '/' . $file;
            
            if (file_exists($sourcePath)) {
                // Ensure target directory exists
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                // Read content and replace placeholders
                $content = file_get_contents($sourcePath);
                
                // Load the app config to get the actual values
                $config = $this->loadAppConfig($appName);
                
                // Use PasswordManager to get consistent passwords
                $passwordManager = new \Nimbus\Password\PasswordManager($this->getVaultManager(), $this->baseDir);
                $passwords = $passwordManager->resolvePasswords($appName);
                
                $content = $this->replacePlaceholders($content, [
                    '{{APP_NAME}}' => $appName,
                    '{{APP_NAME_UPPER}}' => strtoupper($appName),
                    '{{APP_PORT}}' => $config['containers']['app']['port'] ?? '8080',
                    '{{KEYCLOAK_ADMIN_PASSWORD}}' => $config['containers']['keycloak']['admin_password'] ?? $passwords->keycloakAdminPassword,
                    '{{KEYCLOAK_REALM}}' => $config['keycloak']['realm'] ?? $appName . '-realm',
                    '{{KEYCLOAK_CLIENT_ID}}' => $config['keycloak']['client_id'] ?? $appName . '-client'
                ]);
                
                file_put_contents($targetPath, $content);
            }
        }
    }
    
    /**
     * Copy and prepare Keycloak initialization script
     */
    private function copyKeycloakInitScript(string $appName): void
    {
        $appDir = $this->installerDir . '/' . $appName;
        $templateScript = $this->templatesDir . '/' . $this->templateConfig->getDefaultTemplate() . '/keycloak-init.sh';
        $targetScript = $appDir . '/keycloak-init.sh';
        
        if (!file_exists($templateScript)) {
            throw new \Exception("Keycloak init script template not found: $templateScript");
        }
        
        // Just copy the script without replacing placeholders
        // The script will use environment variables passed by the container
        copy($templateScript, $targetScript);
        
        // Make it executable
        chmod($targetScript, 0755);
    }
    
    /**
     * Update app.config.php to enable/disable features
     */
    private function updateAppConfig(string $appDir, bool $hasEda = null, bool $hasKeycloak = null): void
    {
        $appConfigFile = $appDir . '/app.config.php';
        if (!file_exists($appConfigFile)) {
            return;
        }
        
        $content = file_get_contents($appConfigFile);
        
        // Update has_eda if specified.
        // Tolerate legacy quoted values and always write a real boolean.
        if ($hasEda !== null) {
            $edaValue = $hasEda ? 'true' : 'false';
            $content = preg_replace(
                "/'has_eda'\s*=>\s*['\"]?(true|false)['\"]?/",
                "'has_eda' => $edaValue",
                $content
            );
        }

        // Update Keycloak enabled status if specified.
        // Anchor to the 'keycloak' block: templates may have other 'enabled' keys
        // (e.g. lkui's eda section), and write a real boolean, not a string.
        if ($hasKeycloak !== null) {
            $keycloakValue = $hasKeycloak ? 'true' : 'false';
            $content = preg_replace(
                "/('keycloak'\s*=>\s*\[\s*'enabled'\s*=>\s*)['\"]?(true|false)['\"]?/",
                "\${1}$keycloakValue",
                $content
            );
            
            // Also update other Keycloak config values if enabling
            if ($hasKeycloak) {
                // Get the app config to populate values
                $configFile = dirname($appDir) . '/' . basename($appDir) . '/app.nimbus.json';
                if (file_exists($configFile)) {
                    $config = json_decode(file_get_contents($configFile), true);
                    
                    // Update realm
                    $content = preg_replace(
                        "/'realm'\s*=>\s*'[^']*'/",
                        "'realm' => '" . ($config['keycloak']['realm'] ?? '') . "'",
                        $content
                    );
                    
                    // Update client_id
                    $content = preg_replace(
                        "/'client_id'\s*=>\s*'[^']*'/",
                        "'client_id' => '" . ($config['keycloak']['client_id'] ?? '') . "'",
                        $content
                    );
                    
                    // Update client_secret
                    $content = preg_replace(
                        "/'client_secret'\s*=>\s*'[^']*'/",
                        "'client_secret' => '" . ($config['keycloak']['client_secret'] ?? '') . "'",
                        $content
                    );
                }
            }
        }
        
        file_put_contents($appConfigFile, $content);
    }
    
    /**
     * Remove directory recursively for cleanup on failed app creation
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        
        return rmdir($dir);
    }
    
    /**
     * Remove app from apps.json registry for cleanup on failed creation
     */
    private function unregisterApp(string $appName): void
    {
        $appsFile = $this->installerDir . '/apps.json';
        
        if (!file_exists($appsFile)) {
            return;
        }
        
        $apps = json_decode(file_get_contents($appsFile), true);
        if (isset($apps['apps'][$appName])) {
            unset($apps['apps'][$appName]);
            file_put_contents($appsFile, json_encode($apps, JSON_PRETTY_PRINT));
        }
    }
}
