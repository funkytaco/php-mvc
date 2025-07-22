<?php

namespace Nimbus\App;

use Composer\Script\Event;

/**
 * AppManager handles app creation and installation
 */
class AppManager
{
    private string $baseDir;
    private string $installerDir;
    private string $templatesDir;
    
    public function __construct(string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? getcwd();
        $this->installerDir = $this->baseDir . '/.installer/apps';
        $this->templatesDir = $this->baseDir . '/.installer/_templates';
    }
    
    /**
     * Create a new app from template
     */
    public function createFromTemplate(string $appName, string $template = 'nimbus-demo', array $config = []): bool
    {
        $this->validateAppName($appName);
        
        $templatePath = $this->templatesDir . '/' . $template;
        $targetPath = $this->installerDir . '/' . $appName;
        
        if (!is_dir($templatePath)) {
            throw new \RuntimeException("Template '$template' not found");
        }
        
        if (is_dir($targetPath)) {
            throw new \RuntimeException("App '$appName' already exists");
        }
        
        // Copy template to new app
        $this->copyDirectory($templatePath, $targetPath);
        
        // Prepare placeholders
        $placeholders = [
            '{{APP_NAME}}' => $appName,
            '{{APP_NAME_UPPER}}' => strtoupper($appName),
            '{{APP_NAME_LOWER}}' => strtolower($appName),
            '{{APP_PORT}}' => $this->generatePort($appName),
            '{{EDA_PORT}}' => $this->generateEdaPort($appName),
            '{{DB_NAME}}' => $appName . '_db',
            '{{DB_USER}}' => $appName . '_user',
            '{{DB_PASSWORD}}' => $this->generatePassword()
        ];
        
        // Add EDA placeholder
        if (isset($config['features']['eda']) && $config['features']['eda']) {
            $placeholders['{{HAS_EDA}}'] = 'true';
        } else {
            $placeholders['{{HAS_EDA}}'] = 'false';
        }
        
        // Add Keycloak placeholders if enabled
        if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            $placeholders['{{KEYCLOAK_ENABLED}}'] = 'true';
            $placeholders['{{KEYCLOAK_ADMIN_PASSWORD}}'] = $this->generatePassword();
            $placeholders['{{KEYCLOAK_DB_PASSWORD}}'] = $this->generatePassword();
            $placeholders['{{KEYCLOAK_REALM}}'] = $appName . '-realm';
            $placeholders['{{KEYCLOAK_CLIENT_ID}}'] = $appName . '-client';
            $placeholders['{{KEYCLOAK_CLIENT_SECRET}}'] = $this->generatePassword(32);
        } else {
            $placeholders['{{KEYCLOAK_ENABLED}}'] = 'false';
        }
        
        // Replace placeholders in files
        $this->replacePlaceholders($targetPath, $placeholders);
        
        // Update app.nimbus.json with enabled features
        if (!empty($config['features'])) {
            $appConfigPath = $targetPath . '/app.nimbus.json';
            if (file_exists($appConfigPath)) {
                $appConfig = json_decode(file_get_contents($appConfigPath), true);
                
                // Merge features
                foreach ($config['features'] as $feature => $enabled) {
                    $appConfig['features'][$feature] = $enabled;
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
                }
                
                file_put_contents($appConfigPath, json_encode($appConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }
        
        // Register app in apps.json
        $this->registerApp($appName, $template);
        
        // Update composer.json
        $this->updateComposerJson($appName);
        
        return true;
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
        
        // Generate podman-compose file
        $this->generatePodmanCompose($appName, $config);
        
        return true;
    }
    
    /**
     * List available apps
     */
    public function listApps(): array
    {
        $appsFile = $this->baseDir . '/.installer/apps.json';
        
        if (!file_exists($appsFile)) {
            return [];
        }
        
        $apps = json_decode(file_get_contents($appsFile), true);
        return $apps['apps'] ?? [];
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
     * Generate container configuration
     */
    public function generateContainers(string $appName): string
    {
        $config = $this->loadAppConfig($appName);
        $compose = $this->buildComposeConfig($appName, $config);
        
        $yamlContent = $this->arrayToYaml($compose);
        $filename = $this->baseDir . '/' . $appName . '-compose.yml';
        
        file_put_contents($filename, $yamlContent);
        
        return $filename;
    }
    
    /**
     * Load app configuration
     */
    private function loadAppConfig(string $appName): array
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
    private function buildComposeConfig(string $appName, array $config): array
    {
        $compose = [
            'version' => '3.8',
            'name' => $appName,
            'networks' => [
                $appName . '-net' => ['driver' => 'bridge']
            ],
            'services' => []
        ];
        
        // App container
        $compose['services'][$appName . '-app'] = [
            'build' => [
                'context' => '.',
                'args' => [
                    'APP_NAME' => $appName
                ]
            ],
            'container_name' => $appName . '-app',
            'ports' => [$config['containers']['app']['port'] . ':8080'],
            'volumes' => [
                './.installer/apps/' . $appName . ':/var/www/.installer/' . $appName . ':Z'
            ],
            'depends_on' => [$appName . '-db'],
            'networks' => [$appName . '-net']
        ];
        
        // Database container
        if ($config['features']['database'] ?? true) {
            $compose['services'][$appName . '-db'] = [
                'image' => 'postgres:14',
                'container_name' => $appName . '-postgres',
                'environment' => [
                    'POSTGRES_DB' => $config['database']['name'],
                    'POSTGRES_USER' => $config['database']['user'],
                    'POSTGRES_PASSWORD' => $config['database']['password']
                ],
                'volumes' => [
                    './data/' . $appName . ':/var/lib/postgresql/data:Z',
                    './.installer/apps/' . $appName . '/database/schema.sql:/docker-entrypoint-initdb.d/schema.sql:Z'
                ],
                'networks' => [$appName . '-net'],
                'healthcheck' => [
                    'test' => ['CMD-SHELL', 'pg_isready -U ' . $config['database']['user'] . ' -d ' . $config['database']['name']],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 5
                ]
            ];
        }
        
        // EDA container
        if ($config['features']['eda'] ?? false) {
            $edaImage = $config['containers']['eda']['image'] ?? 'registry.redhat.io/ansible-automation-platform-24/de-minimal-rhel9:latest';
            $rulebooksDir = $config['containers']['eda']['rulebooks_dir'] ?? 'rulebooks';
            $edaPort = $this->generateEdaPort($appName);
            
            $compose['services'][$appName . '-eda'] = [
                'image' => $edaImage,
                'container_name' => $appName . '-eda',
                'ports' => [$edaPort . ':5000'],
                'volumes' => [
                    './.installer/apps/' . $appName . '/' . $rulebooksDir . ':/rulebooks:Z',
                    './.installer/apps/' . $appName . '/inventory:/inventory:Z',
                    './.installer/apps/' . $appName . '/playbooks:/playbooks:Z',
                    './.installer/apps/' . $appName . '/logs:/logs:Z',
                    './.installer/apps/' . $appName . '/init-entrypoint.sh:/init-entrypoint.sh:Z'
                ],
                'working_dir' => '/rulebooks',
                'entrypoint' => ['sh', '/init-entrypoint.sh'],
                'depends_on' => [
                    $appName . '-db' => [
                        'condition' => 'service_healthy'
                    ]
                ],
                'restart' => 'unless-stopped',
                'networks' => [$appName . '-net']
            ];
        }
        
        // Keycloak containers
        if ($config['features']['keycloak'] ?? false) {
            // Keycloak database container
            $compose['services'][$appName . '-keycloak-db'] = [
                'image' => $config['containers']['keycloak-db']['image'] ?? 'postgres:14',
                'container_name' => $appName . '-keycloak-db',
                'environment' => [
                    'POSTGRES_DB' => $config['containers']['keycloak-db']['database'] ?? 'keycloak_db',
                    'POSTGRES_USER' => $config['containers']['keycloak-db']['user'] ?? 'keycloak',
                    'POSTGRES_PASSWORD' => $config['containers']['keycloak-db']['password'] ?? 'keycloak'
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
            
            // Keycloak container
            $compose['services'][$appName . '-keycloak'] = [
                'image' => $config['containers']['keycloak']['image'] ?? 'quay.io/keycloak/keycloak:latest',
                'container_name' => $appName . '-keycloak',
                'command' => ['start-dev'],
                'environment' => [
                    'KC_DB' => 'postgres',
                    'KC_DB_URL' => 'jdbc:postgresql://' . $appName . '-keycloak-db:5432/keycloak_db',
                    'KC_DB_USERNAME' => $config['containers']['keycloak-db']['user'] ?? 'keycloak',
                    'KC_DB_PASSWORD' => $config['containers']['keycloak-db']['password'] ?? 'keycloak',
                    'KEYCLOAK_ADMIN' => $config['containers']['keycloak']['admin_user'] ?? 'admin',
                    'KEYCLOAK_ADMIN_PASSWORD' => $config['containers']['keycloak']['admin_password'] ?? 'admin',
                    'KC_HOSTNAME_STRICT' => '"false"',
                    'KC_HTTP_ENABLED' => '"true"'
                ],
                'ports' => ['8080:8080'],
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
            
            // Update app container dependencies to include Keycloak
            if (isset($compose['services'][$appName . '-app']['depends_on'])) {
                if (is_array($compose['services'][$appName . '-app']['depends_on'])) {
                    $compose['services'][$appName . '-app']['depends_on'][] = $appName . '-keycloak';
                }
            }
        }
        
        return $compose;
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
                copy($item, $destPath);
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
                    $content = file_get_contents($file);
                    $content = str_replace(array_keys($replacements), array_values($replacements), $content);
                    file_put_contents($file, $content);
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
                'image' => 'quay.io/ansible/eda-server:latest',
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
     * Create all EDA directories and files from template
     */
    private function createEdaDirectories(string $appPath, string $appName): void
    {
        $templatePath = $this->templatesDir . '/nimbus-demo';
        $edaFiles = [
            'init-entrypoint.sh',
            'inventory/inventory.yml',
            'playbooks/api-notification.yml'
        ];
        
        $edaDirs = ['rulebooks', 'inventory', 'playbooks', 'logs'];
        
        // Create directories
        foreach ($edaDirs as $dir) {
            $dirPath = $appPath . '/' . $dir;
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0755, true);
            }
        }
        
        // Copy template files with app name substitution
        foreach ($edaFiles as $file) {
            $sourcePath = $templatePath . '/' . $file;
            $targetPath = $appPath . '/' . $file;
            
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
        $this->copyEdaRulebooks($appName, $appPath . '/rulebooks');
    }
    
    /**
     * Copy EDA rulebooks from template
     */
    private function copyEdaRulebooks(string $appName, string $targetDir): void
    {
        $templateRulebooksDir = $this->templatesDir . '/nimbus-demo/rulebooks';
        
        if (!is_dir($templateRulebooksDir)) {
            throw new \RuntimeException("Template rulebooks not found");
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateRulebooksDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $targetFile = $targetDir . '/' . $iterator->getSubPathName();
            $targetFileDir = dirname($targetFile);
            
            if (!is_dir($targetFileDir)) {
                mkdir($targetFileDir, 0755, true);
            }
            
            $content = file_get_contents($file);
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
        
        // Update the has_eda value
        if ($hasEda) {
            $content = preg_replace(
                "/'has_eda'\s*=>\s*false/",
                "'has_eda' => true",
                $content
            );
        } else {
            $content = preg_replace(
                "/'has_eda'\s*=>\s*true/",
                "'has_eda' => false",
                $content
            );
        }
        
        file_put_contents($appConfigFile, $content);
    }
    
    /**
     * Regenerate compose file with YAML validation
     */
    private function regenerateComposeFile(string $appName, array $config): void
    {
        $compose = $this->buildComposeConfig($appName, $config);
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
                
                // Check for proper list formatting
                if (preg_match('/^\s*-\s*-/', $line)) {
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
            $appName . '-app',
            $appName . '-postgres'  // database container
        ];
        
        // Check if EDA is enabled for this app
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if ($config['features']['eda'] ?? false) {
                $containers[] = $appName . '-eda';
            }
            // Check if Keycloak is enabled for this app
            if ($config['features']['keycloak'] ?? false) {
                $containers[] = $appName . '-keycloak';
                $containers[] = $appName . '-keycloak-db';
            }
        }
        
        return $containers;
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
     * Stop an app and optionally remove containers/volumes
     */
    public function stopApp(string $appName, array $options = []): array
    {
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        
        if (!file_exists($composeFile)) {
            throw new \RuntimeException("Compose file not found for app '$appName'");
        }
        
        $commands = [];
        $results = ['stopped' => false, 'removed' => false, 'cleaned' => false, 'output' => ''];
        
        // Build command based on options
        $downCommand = "podman-compose -f $composeFile down";
        
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
        $compose = $this->buildComposeConfig($appName, $config);
        $yamlContent = $this->arrayToYaml($compose);
        
        file_put_contents($this->baseDir . '/' . $appName . '-compose.yml', $yamlContent);
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
                $yaml .= $prefix . $key . ': ' . $value . "\n";
            }
        }
        
        return $yaml;
    }
    
    /**
     * Add Keycloak support to an existing app
     */
    public function deleteApp(string $appName, array $options = []): bool
    {
        $appDir = $this->installerDir . '/' . $appName;
        if (!is_dir($appDir)) {
            throw new \RuntimeException("App '$appName' not found");
        }

        // Stop and remove containers first
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        if (file_exists($composeFile)) {
            $downCommand = "podman-compose -f $composeFile down";
            if ($options['remove_volumes'] ?? false) {
                $downCommand .= ' --volumes';
            }
            shell_exec($downCommand . ' 2>&1');
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

    public function addKeycloak(string $appName): bool
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
        
        // Check if Keycloak is already enabled
        if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            throw new \Exception("Keycloak is already enabled for this app");
        }
        
        // Enable Keycloak feature
        $config['features']['keycloak'] = true;
        
        // Add Keycloak containers configuration
        $config['containers']['keycloak'] = [
            'image' => 'quay.io/keycloak/keycloak:latest',
            'port' => '8080',
            'admin_user' => 'admin',
            'admin_password' => $this->generatePassword(),
            'database' => 'keycloak_db'
        ];
        
        $config['containers']['keycloak-db'] = [
            'image' => 'postgres:14',
            'database' => 'keycloak_db',
            'user' => 'keycloak',
            'password' => $this->generatePassword()
        ];
        
        // Add Keycloak configuration
        $config['keycloak'] = [
            'realm' => $appName . '-realm',
            'client_id' => $appName . '-client',
            'client_secret' => $this->generatePassword(32),
            'auth_url' => "http://{$appName}-keycloak:8080",
            'redirect_uri' => "http://localhost:" . ($config['containers']['app']['port'] ?? '8080') . "/auth/callback"
        ];
        
        // Save updated configuration
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Update compose file
        $this->regenerateComposeFile($appName, $config);
        
        // Copy Keycloak-specific files from template
        $this->copyKeycloakFiles($appName);
        
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
        $templateDir = $this->templatesDir . '/nimbus-demo';
        
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
                $content = $this->replacePlaceholders($content, [
                    'APP_NAME' => $appName,
                    'APP_NAME_UPPER' => strtoupper($appName),
                    'APP_PORT' => '8080',
                    'KEYCLOAK_ADMIN_PASSWORD' => 'admin',
                    'KEYCLOAK_REALM' => $appName . '-realm',
                    'KEYCLOAK_CLIENT_ID' => $appName . '-client'
                ]);
                
                file_put_contents($targetPath, $content);
            }
        }
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
        
        // Update has_eda if specified
        if ($hasEda !== null) {
            $edaValue = $hasEda ? 'true' : 'false';
            $content = preg_replace(
                "/'has_eda'\s*=>\s*(true|false)/",
                "'has_eda' => $edaValue",
                $content
            );
        }
        
        // Update Keycloak enabled status if specified
        if ($hasKeycloak !== null) {
            $keycloakValue = $hasKeycloak ? 'true' : 'false';
            $content = preg_replace(
                "/'enabled'\s*=>\s*\{\{KEYCLOAK_ENABLED\}\}/",
                "'enabled' => $keycloakValue",
                $content
            );
        }
        
        file_put_contents($appConfigFile, $content);
    }
}
