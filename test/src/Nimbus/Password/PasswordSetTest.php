<?php

namespace Test\Nimbus\Password;

use PHPUnit\Framework\TestCase;
use Nimbus\Password\PasswordSet;
use Nimbus\Password\PasswordStrategy;

class PasswordSetTest extends TestCase
{
    /**
     * Test PasswordSet construction and properties
     */
    public function testPasswordSetConstruction(): void
    {
        $passwordSet = new PasswordSet(
            databasePassword: 'db_pass123',
            keycloakAdminPassword: 'admin_pass456',
            keycloakDbPassword: 'kc_db_pass789',
            keycloakClientSecret: 'client_secret000',
            strategy: PasswordStrategy::GENERATE_NEW
        );
        
        $this->assertEquals('db_pass123', $passwordSet->databasePassword);
        $this->assertEquals('admin_pass456', $passwordSet->keycloakAdminPassword);
        $this->assertEquals('kc_db_pass789', $passwordSet->keycloakDbPassword);
        $this->assertEquals('client_secret000', $passwordSet->keycloakClientSecret);
        $this->assertEquals(PasswordStrategy::GENERATE_NEW, $passwordSet->strategy);
        $this->assertFalse($passwordSet->requiresForceInit);
    }
    
    /**
     * Test PasswordSet with minimal parameters
     */
    public function testPasswordSetMinimalConstruction(): void
    {
        $passwordSet = new PasswordSet(databasePassword: 'db_pass_only');
        
        $this->assertEquals('db_pass_only', $passwordSet->databasePassword);
        $this->assertEquals('', $passwordSet->keycloakAdminPassword);
        $this->assertEquals('', $passwordSet->keycloakDbPassword);
        $this->assertEquals('', $passwordSet->keycloakClientSecret);
        $this->assertEquals(PasswordStrategy::GENERATE_NEW, $passwordSet->strategy);
    }
    
    /**
     * Test force init determination for vault restore without existing data
     */
    public function testForceInitFalseForVaultRestoreWithoutData(): void
    {
        $baseDir = sys_get_temp_dir() . '/test_nimbus_' . uniqid();
        $appName = 'test-app';
        
        $passwordSet = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::VAULT_RESTORE,
            baseDir: $baseDir,
            appName: $appName
        );
        
        $this->assertFalse($passwordSet->requiresForceInit);
    }
    
    /**
     * Test force init determination for vault restore with existing data
     */
    public function testForceInitTrueForVaultRestoreWithData(): void
    {
        $baseDir = sys_get_temp_dir() . '/test_nimbus_' . uniqid();
        $appName = 'test-app';
        $dataDir = $baseDir . '/data/' . $appName;
        
        // Create mock PostgreSQL data directory
        mkdir($dataDir, 0777, true);
        touch($dataDir . '/PG_VERSION');
        
        $passwordSet = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::VAULT_RESTORE,
            baseDir: $baseDir,
            appName: $appName
        );
        
        $this->assertTrue($passwordSet->requiresForceInit);
        
        // Cleanup
        unlink($dataDir . '/PG_VERSION');
        rmdir($dataDir);
        rmdir($baseDir . '/data');
        rmdir($baseDir);
    }
    
    /**
     * Test force init false for non-vault restore strategies
     */
    public function testForceInitFalseForNonVaultRestore(): void
    {
        $baseDir = sys_get_temp_dir() . '/test_nimbus_' . uniqid();
        $appName = 'test-app';
        $dataDir = $baseDir . '/data/' . $appName;
        
        // Create mock PostgreSQL data directory
        mkdir($dataDir, 0777, true);
        touch($dataDir . '/PG_VERSION');
        
        // Test with EXISTING_DATA strategy
        $passwordSet = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::EXISTING_DATA,
            baseDir: $baseDir,
            appName: $appName
        );
        
        $this->assertFalse($passwordSet->requiresForceInit);
        
        // Test with GENERATE_NEW strategy
        $passwordSet2 = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::GENERATE_NEW,
            baseDir: $baseDir,
            appName: $appName
        );
        
        $this->assertFalse($passwordSet2->requiresForceInit);
        
        // Cleanup
        unlink($dataDir . '/PG_VERSION');
        rmdir($dataDir);
        rmdir($baseDir . '/data');
        rmdir($baseDir);
    }
    
    /**
     * Test toArray method
     */
    public function testToArray(): void
    {
        $passwordSet = new PasswordSet(
            databasePassword: 'db_pass',
            keycloakAdminPassword: 'admin_pass',
            keycloakDbPassword: 'kc_db_pass',
            keycloakClientSecret: 'client_secret'
        );
        
        $expected = [
            'database' => [
                'password' => 'db_pass'
            ],
            'keycloak' => [
                'admin_password' => 'admin_pass',
                'db_password' => 'kc_db_pass',
                'client_secret' => 'client_secret'
            ]
        ];
        
        $this->assertEquals($expected, $passwordSet->toArray());
    }
    
    /**
     * Test hasKeycloakPasswords method
     */
    public function testHasKeycloakPasswords(): void
    {
        // Test with all Keycloak passwords
        $passwordSet1 = new PasswordSet(
            databasePassword: 'db_pass',
            keycloakAdminPassword: 'admin_pass',
            keycloakDbPassword: 'kc_db_pass',
            keycloakClientSecret: 'client_secret'
        );
        $this->assertTrue($passwordSet1->hasKeycloakPasswords());
        
        // Test with only admin password
        $passwordSet2 = new PasswordSet(
            databasePassword: 'db_pass',
            keycloakAdminPassword: 'admin_pass'
        );
        $this->assertTrue($passwordSet2->hasKeycloakPasswords());
        
        // Test with no Keycloak passwords
        $passwordSet3 = new PasswordSet(databasePassword: 'db_pass');
        $this->assertFalse($passwordSet3->hasKeycloakPasswords());
    }
    
    /**
     * Test getStrategyDescription method
     */
    public function testGetStrategyDescription(): void
    {
        $passwordSet1 = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::VAULT_RESTORE
        );
        $this->assertEquals('Restore passwords from vault', $passwordSet1->getStrategyDescription());
        
        $passwordSet2 = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::EXISTING_DATA
        );
        $this->assertEquals('Extract passwords from existing data', $passwordSet2->getStrategyDescription());
        
        $passwordSet3 = new PasswordSet(
            databasePassword: 'db_pass',
            strategy: PasswordStrategy::GENERATE_NEW
        );
        $this->assertEquals('Generate new random passwords', $passwordSet3->getStrategyDescription());
    }
}