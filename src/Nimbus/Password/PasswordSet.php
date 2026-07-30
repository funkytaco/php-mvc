<?php

namespace Nimbus\Password;

use Nimbus\Database\DatabaseEngine;

/**
 * Value object containing all passwords for a Nimbus app
 */
class PasswordSet
{
    public readonly string $databasePassword;
    public readonly string $databaseRootPassword;
    public readonly string $databaseEngine;
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
        private string $appName = '',
        string $databaseRootPassword = '',
        string $databaseEngine = DatabaseEngine::DEFAULT_ENGINE
    ) {
        $this->databasePassword = $databasePassword;
        $this->databaseRootPassword = $databaseRootPassword;
        $this->databaseEngine = $databaseEngine;
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

        // force-init.sh is a Postgres entrypoint script shipped by the MVC
        // templates; no other engine has one to run, and no other engine
        // writes the data directory this looks for.
        if ($this->databaseEngine !== DatabaseEngine::DEFAULT_ENGINE) {
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
        $database = ['password' => $this->databasePassword];

        // Only recorded when they carry information, so a Postgres app's vault
        // entry keeps exactly the shape it has always had.
        if ($this->databaseRootPassword !== '') {
            $database['root_password'] = $this->databaseRootPassword;
        }
        if ($this->databaseEngine !== DatabaseEngine::DEFAULT_ENGINE) {
            $database['engine'] = $this->databaseEngine;
        }

        return [
            'database' => $database,
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