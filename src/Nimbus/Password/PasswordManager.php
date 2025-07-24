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
            PasswordStrategy::GENERATE_NEW => $this->generateNewPasswords($appName)
        };
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
     * Check if app has existing data directory
     */
    private function hasExistingData(string $appName): bool
    {
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
     * Extract passwords from existing running containers
     */
    private function extractFromExistingData(string $appName): PasswordSet
    {
        $dbPassword = $this->extractPasswordFromContainer($appName . '-postgres', 'POSTGRES_PASSWORD');
        $keycloakAdminPassword = $this->extractPasswordFromContainer($appName . '-keycloak', 'KEYCLOAK_ADMIN_PASSWORD');
        $keycloakDbPassword = $this->extractPasswordFromContainer($appName . '-keycloak-db', 'POSTGRES_PASSWORD');
        
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