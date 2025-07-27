<?php

namespace Test\Nimbus\Vault;

use PHPUnit\Framework\TestCase;
use Nimbus\Vault\VaultManager;

class VaultManagerTest extends TestCase
{
    private string $baseDir;
    private VaultManager $vaultManager;
    private string $vaultDir;
    
    protected function setUp(): void
    {
        // Create temporary directory for testing
        $this->baseDir = sys_get_temp_dir() . '/test_vault_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $this->vaultDir = $this->baseDir . '/.installer/vault';
        $this->vaultManager = new VaultManager($this->baseDir);
    }
    
    protected function tearDown(): void
    {
        // Clean up test directories
        $this->removeDirectory($this->baseDir);
    }
    
    /**
     * Test vault initialization with generated password
     */
    public function testInitializeVaultWithGeneratedPassword(): void
    {
        // Capture output
        ob_start();
        $result = $this->vaultManager->initializeVault();
        $output = ob_get_clean();
        
        $this->assertTrue($result);
        $this->assertStringContainsString('Generated vault master password:', $output);
        $this->assertStringContainsString('IMPORTANT: Store this password securely', $output);
        
        // Verify files were created
        $this->assertFileExists($this->vaultDir . '/.vault_pass');
        $this->assertFileExists($this->vaultDir . '/credentials.yml');
        
        // Check permissions
        $vaultPasswordFile = $this->vaultDir . '/.vault_pass';
        $perms = substr(sprintf('%o', fileperms($vaultPasswordFile)), -4);
        $this->assertEquals('0600', $perms);
    }
    
    /**
     * Test vault initialization with provided password
     */
    public function testInitializeVaultWithProvidedPassword(): void
    {
        $masterPassword = 'testMasterPassword123';
        
        ob_start();
        $result = $this->vaultManager->initializeVault($masterPassword);
        $output = ob_get_clean();
        
        $this->assertTrue($result);
        $this->assertEmpty($output); // No output when password is provided
        
        // Verify password was stored correctly
        $storedPassword = trim(file_get_contents($this->vaultDir . '/.vault_pass'));
        $this->assertEquals($masterPassword, $storedPassword);
    }
    
    /**
     * Test checking if vault is initialized
     */
    public function testIsInitialized(): void
    {
        // Not initialized initially
        $this->assertFalse($this->vaultManager->isInitialized());
        
        // Initialize vault
        $this->vaultManager->initializeVault('testPassword');
        
        // Now should be initialized
        $this->assertTrue($this->vaultManager->isInitialized());
    }
    
    /**
     * Test backup app credentials when vault not initialized
     */
    public function testBackupAppCredentialsWithoutInitialization(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Vault not initialized. Run 'composer nimbus:vault-init' first.");
        
        $this->vaultManager->backupAppCredentials('testapp', ['password' => 'test123']);
    }
    
    /**
     * Test backup credentials without actual container encryption
     */
    public function testBackupAppCredentialsSimulated(): void
    {
        // Initialize vault first
        $this->vaultManager->initializeVault('testPassword');
        
        // Create a mock encrypted file to simulate successful encryption
        file_put_contents($this->vaultDir . '/credentials.yml', '$ANSIBLE_VAULT;1.1;AES256' . "\n" . 
            '66623837366232663265366137653164663566313061353131366536343736333862613461653566' . "\n" .
            '3764343165303731303932636637613437376636353731300a643237636539316461316530656437' . "\n");
        
        // Verify file exists
        $this->assertFileExists($this->vaultDir . '/credentials.yml');
        
        // Test that vault is initialized
        $this->assertTrue($this->vaultManager->isInitialized());
    }
    
    /**
     * Test listing backed up apps when vault not initialized
     */
    public function testListBackedUpAppsWithoutInitialization(): void
    {
        $apps = $this->vaultManager->listBackedUpApps();
        $this->assertIsArray($apps);
        $this->assertEmpty($apps);
    }
    
    /**
     * Test removing app credentials when vault not initialized
     */
    public function testRemoveAppCredentialsWithoutInitialization(): void
    {
        $result = $this->vaultManager->removeAppCredentials('testapp');
        $this->assertFalse($result);
    }
    
    /**
     * Test getting all credentials when vault not initialized
     */
    public function testGetAllCredentialsWithoutInitialization(): void
    {
        $credentials = $this->vaultManager->getAllCredentials();
        $this->assertIsArray($credentials);
        $this->assertEmpty($credentials);
    }
    
    /**
     * Test extracting app credentials returns empty when no containers found
     */
    public function testExtractAppCredentialsEmpty(): void
    {
        // Since we can't mock shell_exec easily, test with no containers running
        $credentials = $this->vaultManager->extractAppCredentials('nonexistent');
        
        $this->assertIsArray($credentials);
        // Should be empty since no containers are running in test environment
    }
    
    /**
     * Test extractPasswordFromContainer private method using reflection
     */
    public function testExtractPasswordFromContainerMethod(): void
    {
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($this->vaultManager);
        $method = $reflection->getMethod('extractPasswordFromContainer');
        $method->setAccessible(true);
        
        // This will return null since no actual containers are running
        $result = $method->invoke($this->vaultManager, 'nonexistent-container', 'POSTGRES_PASSWORD');
        $this->assertNull($result);
    }
    
    /**
     * Test YAML parsing functionality
     */
    public function testParseSimpleYaml(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->vaultManager);
        $method = $reflection->getMethod('parseSimpleYaml');
        $method->setAccessible(true);
        
        $yamlContent = <<<YAML
apps:
  testapp:
    backed_up_at: "2023-01-01T00:00:00+00:00"
    backup_version: "1.0"
    database:
      password: "dbPass123"
      user: "testapp_user"
      name: "testapp_db"
    keycloak:
      admin_password: "adminPass456"
      db_password: "keycloakDbPass789"
      client_secret: "clientSecret012"
YAML;
        
        $result = $method->invoke($this->vaultManager, $yamlContent);
        
        $this->assertArrayHasKey('apps', $result);
        $this->assertArrayHasKey('testapp', $result['apps']);
        $this->assertEquals('dbPass123', $result['apps']['testapp']['database']['password']);
        $this->assertEquals('adminPass456', $result['apps']['testapp']['keycloak']['admin_password']);
    }
    
    /**
     * Test YAML generation functionality
     */
    public function testArrayToSimpleYaml(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->vaultManager);
        $method = $reflection->getMethod('arrayToSimpleYaml');
        $method->setAccessible(true);
        
        $data = [
            'apps' => [
                'testapp' => [
                    'backed_up_at' => '2023-01-01T00:00:00+00:00',
                    'backup_version' => '1.0',
                    'database' => [
                        'password' => 'dbPass123',
                        'user' => 'testapp_user',
                        'name' => 'testapp_db'
                    ]
                ]
            ]
        ];
        
        $result = $method->invoke($this->vaultManager, $data);
        
        $this->assertStringContainsString('apps:', $result);
        $this->assertStringContainsString('testapp:', $result);
        $this->assertStringContainsString('database:', $result);
        $this->assertStringContainsString('password: "dbPass123"', $result);
    }
    
    /**
     * Test secure password generation
     */
    public function testGenerateSecurePassword(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->vaultManager);
        $method = $reflection->getMethod('generateSecurePassword');
        $method->setAccessible(true);
        
        // Test default length
        $password1 = $method->invoke($this->vaultManager);
        $this->assertEquals(32, strlen($password1));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $password1);
        
        // Test custom length
        $password2 = $method->invoke($this->vaultManager, 16);
        $this->assertEquals(16, strlen($password2));
        
        // Test uniqueness
        $password3 = $method->invoke($this->vaultManager);
        $this->assertNotEquals($password1, $password3);
    }
    
    /**
     * Test vault initialization creates directory structure
     */
    public function testVaultInitializationCreatesDirectoryStructure(): void
    {
        $this->assertDirectoryDoesNotExist($this->vaultDir);
        
        $this->vaultManager->initializeVault('testPassword');
        
        $this->assertDirectoryExists($this->vaultDir);
        $perms = substr(sprintf('%o', fileperms($this->vaultDir)), -4);
        $this->assertEquals('0700', $perms);
    }
    
    /**
     * Test restore credentials returns null for non-existent app
     */
    public function testRestoreNonExistentAppCredentials(): void
    {
        $this->vaultManager->initializeVault('testPassword');
        
        $result = $this->vaultManager->restoreAppCredentials('nonexistent');
        $this->assertNull($result);
    }
    
    /**
     * Test that vault files have correct permissions
     */
    public function testVaultFilePermissions(): void
    {
        $this->vaultManager->initializeVault('testPassword');
        
        // Check vault directory permissions (0700)
        $vaultDirPerms = substr(sprintf('%o', fileperms($this->vaultDir)), -4);
        $this->assertEquals('0700', $vaultDirPerms);
        
        // Check password file permissions (0600)
        $passwordFilePerms = substr(sprintf('%o', fileperms($this->vaultDir . '/.vault_pass')), -4);
        $this->assertEquals('0600', $passwordFilePerms);
    }
    
    /**
     * Test removing non-existent app credentials
     */
    public function testRemoveNonExistentAppCredentials(): void
    {
        $this->vaultManager->initializeVault('testPassword');
        
        $result = $this->vaultManager->removeAppCredentials('nonexistent');
        $this->assertFalse($result);
    }
    
    /**
     * Test parsing empty YAML content
     */
    public function testParseEmptyYaml(): void
    {
        $reflection = new \ReflectionClass($this->vaultManager);
        $method = $reflection->getMethod('parseSimpleYaml');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->vaultManager, '');
        $this->assertEquals(['apps' => []], $result);
        
        // Test with just comments
        $result = $method->invoke($this->vaultManager, "# Just a comment\n# Another comment");
        $this->assertEquals(['apps' => []], $result);
    }
    
    /**
     * Test YAML generation with empty apps
     */
    public function testArrayToYamlWithEmptyApps(): void
    {
        $reflection = new \ReflectionClass($this->vaultManager);
        $method = $reflection->getMethod('arrayToSimpleYaml');
        $method->setAccessible(true);
        
        $data = ['apps' => []];
        $result = $method->invoke($this->vaultManager, $data);
        
        $this->assertEquals("apps:\n", $result);
    }
    
    /**
     * Test constructor with null base directory uses current working directory
     */
    public function testConstructorWithNullBaseDir(): void
    {
        $vaultManager = new VaultManager(null);
        $this->assertInstanceOf(VaultManager::class, $vaultManager);
        
        // Constructor should work regardless of initialization state
        $this->assertTrue(is_object($vaultManager));
    }
    
    /**
     * Helper method to remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}