<?php

namespace Test\Nimbus\Password;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Nimbus\Password\PasswordManager;
use Nimbus\Password\PasswordSet;
use Nimbus\Password\PasswordStrategy;
use Nimbus\Vault\VaultManager;

class PasswordManagerTest extends TestCase
{
    private PasswordManager $passwordManager;
    private MockObject $vaultManager;
    private string $baseDir;
    
    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $this->vaultManager = $this->createMock(VaultManager::class);
        $this->passwordManager = new PasswordManager($this->vaultManager, $this->baseDir);
    }
    
    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }
    
    /**
     * Test resolve passwords with vault restore strategy
     */
    public function testResolvePasswordsWithVaultRestore(): void
    {
        $appName = 'test-app';
        $expectedCredentials = [
            'database' => ['password' => 'vault_db_pass'],
            'keycloak' => [
                'admin_password' => 'vault_admin_pass',
                'db_password' => 'vault_kc_db_pass',
                'client_secret' => 'vault_client_secret'
            ]
        ];
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(true);
            
        $this->vaultManager->expects($this->exactly(2))
            ->method('restoreAppCredentials')
            ->with($appName)
            ->willReturn($expectedCredentials);
        
        $passwordSet = $this->passwordManager->resolvePasswords($appName);
        
        $this->assertInstanceOf(PasswordSet::class, $passwordSet);
        $this->assertEquals('vault_db_pass', $passwordSet->databasePassword);
        $this->assertEquals('vault_admin_pass', $passwordSet->keycloakAdminPassword);
        $this->assertEquals('vault_kc_db_pass', $passwordSet->keycloakDbPassword);
        $this->assertEquals('vault_client_secret', $passwordSet->keycloakClientSecret);
        $this->assertEquals(PasswordStrategy::VAULT_RESTORE, $passwordSet->strategy);
    }
    
    /**
     * Test resolve passwords with existing data strategy
     */
    public function testResolvePasswordsWithExistingData(): void
    {
        $appName = 'test-app';
        
        // Create mock data directory
        $dataDir = $this->baseDir . '/data/' . $appName;
        mkdir($dataDir, 0777, true);
        touch($dataDir . '/PG_VERSION');
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(false);
        
        $passwordSet = $this->passwordManager->resolvePasswords($appName);
        
        $this->assertInstanceOf(PasswordSet::class, $passwordSet);
        $this->assertEquals(PasswordStrategy::EXISTING_DATA, $passwordSet->strategy);
        // Since we can't mock shell_exec, we expect generated passwords
        $this->assertNotEmpty($passwordSet->databasePassword);
        $this->assertNotEmpty($passwordSet->keycloakAdminPassword);
        $this->assertNotEmpty($passwordSet->keycloakDbPassword);
        $this->assertNotEmpty($passwordSet->keycloakClientSecret);
    }
    
    /**
     * Test resolve passwords with generate new strategy
     */
    public function testResolvePasswordsWithGenerateNew(): void
    {
        $appName = 'test-app';
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(false);
        
        $passwordSet = $this->passwordManager->resolvePasswords($appName);
        
        $this->assertInstanceOf(PasswordSet::class, $passwordSet);
        $this->assertEquals(PasswordStrategy::GENERATE_NEW, $passwordSet->strategy);
        $this->assertNotEmpty($passwordSet->databasePassword);
        $this->assertEquals(32, strlen($passwordSet->databasePassword));
        $this->assertEquals(32, strlen($passwordSet->keycloakAdminPassword));
        $this->assertEquals(32, strlen($passwordSet->keycloakDbPassword));
        $this->assertEquals(32, strlen($passwordSet->keycloakClientSecret));
    }
    
    /**
     * Test vault credentials check with exception
     */
    public function testVaultCredentialsCheckWithException(): void
    {
        $appName = 'test-app';
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(true);
            
        $this->vaultManager->expects($this->once())
            ->method('restoreAppCredentials')
            ->with($appName)
            ->willThrowException(new \Exception('Vault error'));
        
        $passwordSet = $this->passwordManager->resolvePasswords($appName);
        
        // Should fall back to generate new
        $this->assertEquals(PasswordStrategy::GENERATE_NEW, $passwordSet->strategy);
    }
    
    /**
     * Test backup to vault success
     */
    public function testBackupToVaultSuccess(): void
    {
        $appName = 'test-app';
        $passwordSet = new PasswordSet(
            databasePassword: 'db_pass',
            keycloakAdminPassword: 'admin_pass',
            keycloakDbPassword: 'kc_db_pass',
            keycloakClientSecret: 'client_secret'
        );
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(true);
            
        $this->vaultManager->expects($this->once())
            ->method('backupAppCredentials')
            ->with($appName, $passwordSet->toArray())
            ->willReturn(true);
        
        $result = $this->passwordManager->backupToVault($appName, $passwordSet);
        
        $this->assertTrue($result);
    }
    
    /**
     * Test backup to vault when not initialized
     */
    public function testBackupToVaultNotInitialized(): void
    {
        $appName = 'test-app';
        $passwordSet = new PasswordSet(databasePassword: 'db_pass');
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(false);
            
        $this->vaultManager->expects($this->never())
            ->method('backupAppCredentials');
        
        $result = $this->passwordManager->backupToVault($appName, $passwordSet);
        
        $this->assertFalse($result);
    }
    
    /**
     * Test backup to vault with exception
     */
    public function testBackupToVaultWithException(): void
    {
        $appName = 'test-app';
        $passwordSet = new PasswordSet(databasePassword: 'db_pass');
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(true);
            
        $this->vaultManager->expects($this->once())
            ->method('backupAppCredentials')
            ->willThrowException(new \Exception('Backup failed'));
        
        $result = $this->passwordManager->backupToVault($appName, $passwordSet);
        
        $this->assertFalse($result);
    }
    
    /**
     * Test password generation
     */
    public function testPasswordGeneration(): void
    {
        $appName = 'test-app';
        
        $this->vaultManager->expects($this->once())
            ->method('isInitialized')
            ->willReturn(false);
        
        $passwordSet = $this->passwordManager->resolvePasswords($appName);
        
        // Test password characteristics
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $passwordSet->databasePassword);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $passwordSet->keycloakAdminPassword);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $passwordSet->keycloakDbPassword);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $passwordSet->keycloakClientSecret);
    }
    
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