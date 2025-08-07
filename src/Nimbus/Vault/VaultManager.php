<?php

namespace Nimbus\Vault;

/**
 * VaultManager handles encrypted credential storage using Ansible Vault
 */
class VaultManager
{
    private string $baseDir;
    private string $vaultDir;
    private string $credentialsFile;
    private string $vaultPasswordFile;
    
    public function __construct(string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? getcwd();
        $this->vaultDir = $this->baseDir . '/.installer/vault';
        $this->credentialsFile = $this->vaultDir . '/credentials.yml';
        $this->vaultPasswordFile = $this->vaultDir . '/.vault_pass';
    }
    
    /**
     * Initialize vault with master password
     */
    public function initializeVault(string $masterPassword = null): bool
    {
        if (!is_dir($this->vaultDir)) {
            mkdir($this->vaultDir, 0700, true);
        }
        
        // Generate or use provided master password
        if (!$masterPassword) {
            $masterPassword = $this->generateSecurePassword(32);
            echo "Generated vault master password: $masterPassword\n";
            echo "IMPORTANT: Store this password securely - you'll need it to access credentials!\n";
        }
        
        // Store master password (in production, this should be more secure)
        file_put_contents($this->vaultPasswordFile, $masterPassword);
        chmod($this->vaultPasswordFile, 0600);
        
        // Create initial empty credentials file
        $initialData = ['apps' => []];
        return $this->encryptAndSave($initialData);
    }
    
    /**
     * Check if vault is initialized
     */
    public function isInitialized(): bool
    {
        return file_exists($this->vaultPasswordFile) && file_exists($this->credentialsFile);
    }
    
    /**
     * Backup app credentials to vault
     */
    public function backupAppCredentials(string $appName, array $credentials): bool
    {
        if (!$this->isInitialized()) {
            throw new \RuntimeException("Vault not initialized. Run 'composer nimbus:vault-init' first.");
        }
        
        $data = $this->loadCredentials();
        
        $data['apps'][$appName] = array_merge($credentials, [
            'backed_up_at' => date('c'),
            'backup_version' => '1.0'
        ]);
        
        return $this->encryptAndSave($data);
    }
    
    /**
     * Restore app credentials from vault
     */
    public function restoreAppCredentials(string $appName): ?array
    {
        if (!$this->isInitialized()) {
            return null;
        }
        
        $data = $this->loadCredentials();
        return $data['apps'][$appName] ?? null;
    }
    
    /**
     * List apps with backed up credentials
     */
    public function listBackedUpApps(): array
    {
        if (!$this->isInitialized()) {
            return [];
        }
        
        $data = $this->loadCredentials();
        $apps = [];
        
        foreach ($data['apps'] as $appName => $credentials) {
            $apps[] = [
                'name' => $appName,
                'backed_up_at' => $credentials['backed_up_at'] ?? 'unknown',
                'has_database' => isset($credentials['database']),
                'has_keycloak' => isset($credentials['keycloak'])
            ];
        }
        
        return $apps;
    }
    
    /**
     * Remove app credentials from vault
     */
    public function removeAppCredentials(string $appName): bool
    {
        if (!$this->isInitialized()) {
            return false;
        }
        
        $data = $this->loadCredentials();
        
        if (!isset($data['apps'][$appName])) {
            return false;
        }
        
        unset($data['apps'][$appName]);
        return $this->encryptAndSave($data);
    }
    
    /**
     * Get all credentials from vault
     */
    public function getAllCredentials(): array
    {
        if (!$this->isInitialized()) {
            return [];
        }
        
        return $this->loadCredentials();
    }
    
    /**
     * Extract credentials from running app
     */
    public function extractAppCredentials(string $appName): array
    {
        $credentials = [];
        
        // Extract database credentials
        $dbContainer = $appName . '-postgres';
        $dbPassword = $this->extractPasswordFromContainer($dbContainer, 'POSTGRES_PASSWORD');
        if ($dbPassword) {
            $credentials['database'] = [
                'password' => $dbPassword,
                'user' => $appName . '_user',
                'name' => $appName . '_db'
            ];
        }
        
        // Extract Keycloak credentials if present
        $keycloakContainer = $appName . '-keycloak';
        $keycloakAdminPassword = $this->extractPasswordFromContainer($keycloakContainer, 'KEYCLOAK_ADMIN_PASSWORD');
        if ($keycloakAdminPassword) {
            $keycloakDbPassword = $this->extractPasswordFromContainer($appName . '-keycloak-db', 'POSTGRES_PASSWORD');
            
            $credentials['keycloak'] = [
                'admin_password' => $keycloakAdminPassword,
                'db_password' => $keycloakDbPassword
            ];
            
            // Try to get client secret from app config
            $configFile = $this->baseDir . '/.installer/apps/' . $appName . '/app.nimbus.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true);
                if (isset($config['keycloak']['client_secret'])) {
                    $credentials['keycloak']['client_secret'] = $config['keycloak']['client_secret'];
                }
            }
        }
        
        return $credentials;
    }
    
    /**
     * Load and decrypt credentials from vault
     */
    private function loadCredentials(): array
    {
        if (!file_exists($this->credentialsFile)) {
            return ['apps' => []];
        }
        
        $masterPassword = trim(file_get_contents($this->vaultPasswordFile));
        
        // Create temporary password file in vault directory
        $tempPassFile = $this->vaultDir . '/temp.pass';
        file_put_contents($tempPassFile, $masterPassword);
        chmod($tempPassFile, 0600);
        
        // Use containerized ansible-vault to decrypt
        $decryptCmd = $this->runVaultContainer(
            "ansible-vault decrypt --vault-password-file temp.pass --output /tmp/decrypted.yml credentials.yml && cat /tmp/decrypted.yml"
        );
        
        // Clean up temp password file
        if (file_exists($tempPassFile)) {
            unlink($tempPassFile);
        }
        
        if (empty($decryptCmd)) {
            throw new \RuntimeException("Failed to decrypt vault credentials");
        }
        
        // Parse YAML manually since yaml_parse might not be available
        $data = $this->parseSimpleYaml($decryptCmd);
        
        return $data ?: ['apps' => []];
    }
    
    /**
     * Encrypt and save credentials to vault
     */
    private function encryptAndSave(array $data): bool
    {
        $masterPassword = trim(file_get_contents($this->vaultPasswordFile));
        
        // Create YAML content manually
        $yamlContent = $this->arrayToSimpleYaml($data);
        
        // Create temporary files in vault directory
        $tempFile = $this->vaultDir . '/temp.yml';
        $tempPassFile = $this->vaultDir . '/temp.pass';
        
        file_put_contents($tempFile, $yamlContent);
        file_put_contents($tempPassFile, $masterPassword);
        chmod($tempPassFile, 0600);
        
        // Use containerized ansible-vault to encrypt
        $encryptCmd = $this->runVaultContainer(
            "ansible-vault encrypt --vault-password-file temp.pass --output credentials.yml temp.yml"
        );
        
        // Clean up temp files
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        if (file_exists($tempPassFile)) {
            unlink($tempPassFile);
        }
        
        return file_exists($this->credentialsFile);
    }
    
    /**
     * Simple YAML parser for our credential structure
     */
    private function parseSimpleYaml(string $yamlContent): array
    {
        $data = ['apps' => []];
        $lines = explode("\n", $yamlContent);
        $currentApp = null;
        $currentSection = null;
        
        foreach ($lines as $line) {
            if (empty($line) || strpos(trim($line), '#') === 0) {
                continue;
            }
            
            if (trim($line) === 'apps:') {
                continue;
            }
            
            // Match app name (2 spaces)
            if (preg_match('/^  (\w+):$/', $line, $matches)) {
                $currentApp = $matches[1];
                $data['apps'][$currentApp] = [];
                $currentSection = null;
            } 
            // Match section (database/keycloak) with 4 spaces
            elseif (preg_match('/^    (database|keycloak):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $data['apps'][$currentApp][$currentSection] = [];
            } 
            // Match properties with 6 spaces (inside sections)
            elseif (preg_match('/^      (\w+):\s*"?([^"]*)"?$/', $line, $matches)) {
                if ($currentSection) {
                    $data['apps'][$currentApp][$currentSection][$matches[1]] = $matches[2];
                }
            }
            // Match properties with 4 spaces (app level)
            elseif (preg_match('/^    (\w+):\s*"?([^"]*)"?$/', $line, $matches)) {
                $data['apps'][$currentApp][$matches[1]] = $matches[2];
                $currentSection = null; // Reset section for app-level properties
            }
        }
        
        return $data;
    }
    
    /**
     * Simple YAML generator for our credential structure
     */
    private function arrayToSimpleYaml(array $data): string
    {
        $yaml = "apps:\n";
        
        foreach ($data['apps'] as $appName => $appData) {
            // Skip empty app entries
            if (empty($appData)) {
                continue;
            }
            
            $yaml .= "  $appName:\n";
            
            foreach ($appData as $key => $value) {
                if (is_array($value)) {
                    $yaml .= "    $key:\n";
                    foreach ($value as $subKey => $subValue) {
                        $yaml .= "      $subKey: \"$subValue\"\n";
                    }
                } else {
                    $yaml .= "    $key: \"$value\"\n";
                }
            }
        }
        
        return $yaml;
    }
    
    /**
     * Extract password from container environment
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
     * Generate secure password (shell-safe characters only)
     */
    private function generateSecurePassword(int $length = 32): string
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
     * Run vault utility container for operations requiring ansible-vault
     */
    private function runVaultContainer(string $command): string
    {
        $containerCmd = sprintf(
            "podman run --rm -v %s:/vault:Z -w /vault quay.io/ansible/ansible-runner:latest sh -c %s",
            escapeshellarg($this->vaultDir),
            escapeshellarg($command)
        );
        
        return shell_exec($containerCmd) ?: '';
    }
}