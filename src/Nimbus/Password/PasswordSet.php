<?php

namespace Nimbus\Password;

/**
 * Value object containing all passwords for a Nimbus app
 */
class PasswordSet
{
    public readonly string $databasePassword;
    public readonly string $keycloakAdminPassword;
    public readonly string $keycloakDbPassword;
    public readonly string $keycloakClientSecret;
    public readonly PasswordStrategy $strategy;
    public readonly bool $requiresForceInit;
    
    public function __construct(
        string $databasePassword,
        string $keycloakAdminPassword = '',
        string $keycloakDbPassword = '',
        string $keycloakClientSecret = '',
        PasswordStrategy $strategy = PasswordStrategy::GENERATE_NEW,
        private string $baseDir = '',
        private string $appName = ''
    ) {
        $this->databasePassword = $databasePassword;
        $this->keycloakAdminPassword = $keycloakAdminPassword;
        $this->keycloakDbPassword = $keycloakDbPassword;
        $this->keycloakClientSecret = $keycloakClientSecret;
        $this->strategy = $strategy;
        $this->requiresForceInit = $this->determineForceInit();
    }
    
    /**
     * Determine if force init is required
     */
    private function determineForceInit(): bool
    {
        // Force init only needed for vault restore with existing data
        if ($this->strategy !== PasswordStrategy::VAULT_RESTORE) {
            return false;
        }
        
        if (empty($this->baseDir) || empty($this->appName)) {
            return false;
        }
        
        return $this->hasExistingDataDirectory();
    }
    
    /**
     * Check if data directory exists with PostgreSQL data
     */
    private function hasExistingDataDirectory(): bool
    {
        $dataDir = $this->baseDir . '/data/' . $this->appName;
        
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
     * Get passwords as array for backward compatibility
     */
    public function toArray(): array
    {
        return [
            'database' => [
                'password' => $this->databasePassword
            ],
            'keycloak' => [
                'admin_password' => $this->keycloakAdminPassword,
                'db_password' => $this->keycloakDbPassword,
                'client_secret' => $this->keycloakClientSecret
            ]
        ];
    }
    
    /**
     * Check if Keycloak passwords are available
     */
    public function hasKeycloakPasswords(): bool
    {
        return !empty($this->keycloakAdminPassword) || 
               !empty($this->keycloakDbPassword) || 
               !empty($this->keycloakClientSecret);
    }
    
    /**
     * Get strategy display name
     */
    public function getStrategyDescription(): string
    {
        return $this->strategy->getDescription();
    }
}