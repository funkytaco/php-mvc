<?php

declare(strict_types=1);

namespace Nimbus\App;

use Nimbus\Template\TemplateManager;
use Nimbus\Template\TemplateConfig;

/**
 * Manages apps scaffolded from a local template in .installer/_templates/.
 *
 * This is the classic Nimbus flow: copy a template tree into the app's
 * instance dir, substitute {{PLACEHOLDER}} tokens with the app's resolved
 * name/ports/passwords, run any generator templates the template declares,
 * and (via nimbus:commit) copy live edits back to the template.
 *
 * Everything here assumes a template on disk. The generic container
 * lifecycle — compose, podman, ports, vault, registry — lives in the base
 * AppManager and is shared with git-sourced apps.
 */
class MVCAppManager extends AppManager
{
    protected string $templatesDir;
    protected TemplateConfig $templateConfig;
    private ?TemplateManager $templateManager;

    /**
     * @param TemplateManager|null $templateManager injectable so tests can
     *        point template resolution at a temp dir instead of getcwd()
     */
    public function __construct(string $baseDir = null, ?TemplateManager $templateManager = null)
    {
        parent::__construct($baseDir);
        $this->templatesDir = $this->baseDir . '/.installer/_templates';
        $this->templateConfig = TemplateConfig::getInstance();
        $this->templateManager = $templateManager;
    }

    /**
     * Lazily resolve the TemplateManager: its constructor probes the
     * templates dir, so building one eagerly would make every MVCAppManager
     * require .installer/_templates to exist.
     */
    protected function templates(): TemplateManager
    {
        return $this->templateManager ??= new TemplateManager($this->templatesDir);
    }

    /**
     * The template an app was created from, which is where its feature
     * scaffolding must come from.
     *
     * Previously every scaffolding copy read the *default* template, so
     * adding EDA or Keycloak to an app created from a non-default template
     * silently copied the wrong template's files.
     */
    protected function templatePathForApp(string $appName): string
    {
        $type = null;
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        if (file_exists($configFile)) {
            $config = json_decode((string) file_get_contents($configFile), true);
            $type = $config['type'] ?? null;
        }

        $type ??= $this->templateConfig->getDefaultTemplate();

        return $this->templatesDir . '/' . $type;
    }

    /**
     * Template-backed apps can be committed back to their template.
     */
    public function supportsCommit(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $config */
    protected function provisionEdaAssets(string $appName, string $appPath, array $config): void
    {
        $this->createEdaDirectories($appPath, $appName);
    }

    /** @param array<string, mixed> $config */
    protected function provisionKeycloakAssets(string $appName, string $appPath, array $config): void
    {
        $this->copyKeycloakFiles($appName);
        $this->copyKeycloakInitScript($appName);
    }

    /**
     * MVC apps carry a second config file, app.config.php, read by the
     * framework at request time; keep its feature flags in step with
     * app.nimbus.json.
     */
    protected function syncAppRuntimeConfig(string $appPath, string $feature, bool $enabled): void
    {
        if ($feature === 'eda') {
            $this->updateAppConfigForEda($appPath, $enabled);
        } else {
            $this->updateAppConfig($appPath, null, $enabled);
        }
    }

    /**
     * Create a new app from template
     */
    public function createFromTemplate(string $appName, string $template = null, array $config = []): bool
    {
        // Use default template if none specified
        if ($template === null) {
            $template = $this->templateConfig->getDefaultTemplate();
        }

        $templateManager = $this->templates();
        if (!$templateManager->templateExists($template)) {
            throw new \RuntimeException("Template '$template' not found");
        }

        $templatePath = $templateManager->getTemplatePath($template);

        return $this->createAppInstance($appName, $template, function (string $targetPath) use ($appName, $template, $templatePath, $config) {
            // 1. FIRST: resolve the password strategy, before anything is copied
            $passwords = $this->resolveAppPasswords($appName);

            // 2. Copy template with password-aware setup
            $this->copyTemplateWithPasswordStrategy($templatePath, $targetPath, $passwords);

            // 3. Generate configuration with resolved passwords
            $this->generateAppConfigWithPasswords($appName, $targetPath, $passwords, $config, $template);

            // 4. Auto-backup to vault if new passwords generated
            $this->backupPasswordsToVault($appName, $passwords);

            // 5. Populate EDA runtime dirs when EDA is enabled at create time.
            // The compose file mounts eda/rulebooks and eda/playbooks; without
            // this the create-with-eda path mounted EMPTY dirs and the EDA
            // container crash-looped behind an "Up" status (only addEda()
            // used to do this).
            if (!empty($config['features']['eda'])) {
                $this->createEdaRuntimeDirectories($targetPath);
                $this->createEdaDirectories($targetPath, $appName);
            }
        });
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
     * List available templates
     */
    public function listTemplates(): array
    {
        return $this->templates()->getAvailableTemplates();
    }

    /**
     * Get template info
     */
    public function getTemplateInfo(string $templateName): ?array
    {
        return $this->templates()->getTemplateInfo($templateName);
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
        $templatePath = $this->templatePathForApp($appName);

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
        $templateRulebooksDir = $this->templatePathForApp($appName) . '/eda/rulebooks';
        
        if (!is_dir($templateRulebooksDir)) {
            // Try old location for backward compatibility
            $templateRulebooksDir = $this->templatePathForApp($appName) . '/rulebooks';
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
     * Copy Keycloak-specific files from template
     */
    private function copyKeycloakFiles(string $appName): void
    {
        $appDir = $this->installerDir . '/' . $appName;
        $templateDir = $this->templatePathForApp($appName);
        
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
        $templateScript = $this->templatePathForApp($appName) . '/keycloak-init.sh';
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

}
