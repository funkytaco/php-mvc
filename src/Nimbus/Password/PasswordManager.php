<?php

namespace Nimbus\Password;

use Nimbus\Vault\VaultManager;

/**
 * Centralized password management for Nimbus apps
 */
class PasswordManager
{
    private VaultManager $vault;
    private string $baseDir;
    
    public function __construct(VaultManager $vault, string $baseDir)
    {
        $this->vault = $vault;
        $this->baseDir = $baseDir;
    }
    
    /**
     * Main entry point - determines password strategy for app creation
     */
    public function resolvePasswords(string $appName): PasswordSet
    {
        $strategy = $this->determineStrategy($appName);
        
        return match($strategy) {
            PasswordStrategy::VAULT_RESTORE => $this->restoreFromVault($appName),
            PasswordStrategy::EXISTING_DATA => $this->extractFromExistingData($appName),
            PasswordStrategy::GENERATE_NEW => $this->generateNewPasswords($appName),
            PasswordStrategy::NO_MODIFICATIONS => $this->noModificationsStrategy($appName)
        };
    }
    
    /**
     * Resolve passwords for add operations - preserves existing passwords, generates only for new services
     */
    public function resolvePasswordsForAddOperation(string $appName): PasswordSet
    {
        return $this->noModificationsStrategy($appName);
    }
    
    /**
     * Determine the best password strategy for this app
     */
    private function determineStrategy(string $appName): PasswordStrategy
    {
        // 1. Vault has highest priority
        if ($this->vault->isInitialized() && $this->hasVaultCredentials($appName)) {
            return PasswordStrategy::VAULT_RESTORE;
        }
        
        // 2. Existing data directory
        if ($this->hasExistingData($appName)) {
            return PasswordStrategy::EXISTING_DATA;
        }
        
        // 3. Generate new passwords
        return PasswordStrategy::GENERATE_NEW;
    }
    
    /**
     * Check if vault has credentials for this app
     */
    private function hasVaultCredentials(string $appName): bool
    {
        try {
            $credentials = $this->vault->restoreAppCredentials($appName);
            return !empty($credentials['database']['password']);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if app has existing data directory, running containers, or compose file
     */
    private function hasExistingData(string $appName): bool
    {
        // First check for running containers - highest priority
        if ($this->hasRunningContainers($appName)) {
            return true;
        }
        
        // Second check for existing compose file with passwords
        if ($this->hasExistingComposeFile($appName)) {
            return true;
        }
        
        // Then check for data directory files
        $dataDir = $this->baseDir . '/data/' . $appName;
        
        if (!is_dir($dataDir)) {
            return false;
        }
        
        // Check for PostgreSQL-specific files
        $pgFiles = ['PG_VERSION', 'postgresql.conf', 'pg_hba.conf'];
        foreach ($pgFiles as $file) {
            if (file_exists($dataDir . '/' . $file)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if app has running containers with existing passwords
     */
    private function hasRunningContainers(string $appName): bool
    {
        $containersToCheck = [
            $appName . '-postgres',
            $appName . '-app'
        ];
        
        foreach ($containersToCheck as $containerName) {
            $inspectCmd = "podman inspect $containerName --format '{{.State.Status}}' 2>/dev/null";
            $status = trim(shell_exec($inspectCmd) ?: '');

            if ($status === 'running') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if app has existing compose file with passwords
     */
    private function hasExistingComposeFile(string $appName): bool
    {
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        
        if (!file_exists($composeFile)) {
            return false;
        }
        
        // Check if compose file contains database password
        $content = file_get_contents($composeFile);
        return strpos($content, 'POSTGRES_PASSWORD:') !== false;
    }
    
    /**
     * Restore passwords from vault
     */
    private function restoreFromVault(string $appName): PasswordSet
    {
        $credentials = $this->vault->restoreAppCredentials($appName);
        
        return new PasswordSet(
            databasePassword: $credentials['database']['password'] ?? '',
            keycloakAdminPassword: $credentials['keycloak']['admin_password'] ?? '',
            keycloakDbPassword: $credentials['keycloak']['db_password'] ?? '',
            keycloakClientSecret: $credentials['keycloak']['client_secret'] ?? '',
            strategy: PasswordStrategy::VAULT_RESTORE,
            baseDir: $this->baseDir,
            appName: $appName
        );
    }
    
    /**
     * Extract passwords from existing running containers or compose files
     */
    private function extractFromExistingData(string $appName): PasswordSet
    {
        // First try to extract from running containers
        $dbPassword = $this->extractPasswordFromContainer($appName . '-postgres', 'POSTGRES_PASSWORD');
        $keycloakAdminPassword = $this->extractPasswordFromContainer($appName . '-keycloak', 'KEYCLOAK_ADMIN_PASSWORD');
        $keycloakDbPassword = $this->extractPasswordFromContainer($appName . '-keycloak-db', 'POSTGRES_PASSWORD');
        
        // If containers aren't running, try to extract from compose file
        if (!$dbPassword) {
            $dbPassword = $this->extractPasswordFromComposeFile($appName, 'POSTGRES_PASSWORD');
        }
        if (!$keycloakAdminPassword) {
            $keycloakAdminPassword = $this->extractPasswordFromComposeFile($appName, 'KEYCLOAK_ADMIN_PASSWORD');
        }
        if (!$keycloakDbPassword) {
            $keycloakDbPassword = $this->extractPasswordFromComposeFile($appName, 'KC_DB_PASSWORD');
        }
        
        // Try to get client secret from app config
        $keycloakClientSecret = $this->extractClientSecretFromConfig($appName);
        
        return new PasswordSet(
            databasePassword: $dbPassword ?: $this->generatePassword(),
            keycloakAdminPassword: $keycloakAdminPassword ?: $this->generatePassword(),
            keycloakDbPassword: $keycloakDbPassword ?: $this->generatePassword(),
            keycloakClientSecret: $keycloakClientSecret ?: $this->generatePassword(32),
            strategy: PasswordStrategy::EXISTING_DATA,
            baseDir: $this->baseDir,
            appName: $appName
        );
    }
    
    /**
     * Generate new passwords for app
     */
    private function generateNewPasswords(string $appName): PasswordSet
    {
        return new PasswordSet(
            databasePassword: $this->generatePassword(),
            keycloakAdminPassword: $this->generatePassword(),
            keycloakDbPassword: $this->generatePassword(),
            keycloakClientSecret: $this->generatePassword(32),
            strategy: PasswordStrategy::GENERATE_NEW,
            baseDir: $this->baseDir,
            appName: $appName
        );
    }
    
    /**
     * No modifications strategy - preserve existing passwords, generate only for new services
     */
    private function noModificationsStrategy(string $appName): PasswordSet
    {
        // First try to extract existing passwords from current data
        $existingPasswords = $this->extractFromExistingData($appName);
        
        // For NO_MODIFICATIONS, if we can't find existing passwords, we check if there's
        // an existing app config that already has passwords defined
        $appConfigPath = $this->baseDir . '/.installer/apps/' . $appName . '/app.nimbus.json';
        if (file_exists($appConfigPath)) {
            $config = json_decode(file_get_contents($appConfigPath), true);
            
            // Use existing database password from config if available
            $dbPassword = $existingPasswords->databasePassword;
            if (empty($dbPassword) && isset($config['database']['password'])) {
                $dbPassword = $config['database']['password'];
            }
            
            // For Keycloak passwords, if they don't exist yet, generate new ones
            // (this handles the case where we're adding Keycloak to an existing app)
            $keycloakAdminPassword = $existingPasswords->keycloakAdminPassword;
            $keycloakDbPassword = $existingPasswords->keycloakDbPassword;
            $keycloakClientSecret = $existingPasswords->keycloakClientSecret;
            
            // Generate new Keycloak passwords only if they don't exist
            if (empty($keycloakAdminPassword)) {
                $keycloakAdminPassword = $this->generatePassword();
            }
            if (empty($keycloakDbPassword)) {
                $keycloakDbPassword = $this->generatePassword();
            }
            if (empty($keycloakClientSecret)) {
                $keycloakClientSecret = $this->generatePassword(32);
            }
            
            return new PasswordSet(
                databasePassword: $dbPassword ?: $this->generatePassword(),
                keycloakAdminPassword: $keycloakAdminPassword,
                keycloakDbPassword: $keycloakDbPassword,
                keycloakClientSecret: $keycloakClientSecret,
                strategy: PasswordStrategy::NO_MODIFICATIONS,
                baseDir: $this->baseDir,
                appName: $appName
            );
        }
        
        // If no config exists, fall back to existing data extraction
        return new PasswordSet(
            databasePassword: $existingPasswords->databasePassword,
            keycloakAdminPassword: $existingPasswords->keycloakAdminPassword,
            keycloakDbPassword: $existingPasswords->keycloakDbPassword,
            keycloakClientSecret: $existingPasswords->keycloakClientSecret,
            strategy: PasswordStrategy::NO_MODIFICATIONS,
            baseDir: $this->baseDir,
            appName: $appName
        );
    }
    
    /**
     * Extract password from running container
     */
    private function extractPasswordFromContainer(string $containerName, string $envVar): ?string
    {
        $inspectCmd = "podman inspect $containerName --format '{{json .Config.Env}}' 2>/dev/null";
        $output = shell_exec($inspectCmd);
        
        if (!$output) {
            return null;
        }
        
        $envVars = json_decode(trim($output), true);
        if (!is_array($envVars)) {
            return null;
        }
        
        foreach ($envVars as $env) {
            if (strpos($env, $envVar . '=') === 0) {
                return substr($env, strlen($envVar) + 1);
            }
        }
        
        return null;
    }
    
    /**
     * Extract password from compose file
     */
    private function extractPasswordFromComposeFile(string $appName, string $envVar): ?string
    {
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        
        if (!file_exists($composeFile)) {
            return null;
        }
        
        $content = file_get_contents($composeFile);
        
        // Look for the environment variable in the compose file
        // Pattern: POSTGRES_PASSWORD: somepassword
        $pattern = '/^\s*' . preg_quote($envVar) . ':\s*(.+)$/m';
        
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Extract client secret from app config
     */
    private function extractClientSecretFromConfig(string $appName): ?string
    {
        $configFile = $this->baseDir . '/.installer/apps/' . $appName . '/app.nimbus.json';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        return $config['keycloak']['client_secret'] ?? null;
    }
    
    /**
     * Generate secure password
     */
    private function generatePassword(int $length = 32): string
    {
        // Use shell-safe characters only to avoid escaping issues
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    /**
     * Backup passwords to vault automatically
     */
    public function backupToVault(string $appName, PasswordSet $passwords): bool
    {
        try {
            if (!$this->vault->isInitialized()) {
                return false;
            }
            
            return $this->vault->backupAppCredentials($appName, $passwords->toArray());
        } catch (\Exception $e) {
            // Log error but don't fail app creation
            error_log("Failed to backup passwords to vault for $appName: " . $e->getMessage());
            return false;
        }
    }
}