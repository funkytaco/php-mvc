<?php

namespace Test\Nimbus\App;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Nimbus\App\AppManager;
use Nimbus\Password\PasswordManager;
use Nimbus\Password\PasswordSet;
use Nimbus\Password\PasswordStrategy;
use Nimbus\Vault\VaultManager;
use Nimbus\Template\TemplateConfig;

class AppManagerTest extends TestCase
{
    private AppManager $appManager;
    private string $baseDir;
    private string $installerDir;
    private string $templatesDir;
    
    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_' . uniqid();
        $this->installerDir = $this->baseDir . '/.installer/apps';
        $this->templatesDir = $this->baseDir . '/.installer/_templates';
        
        // Create required directories
        mkdir($this->installerDir, 0777, true);
        mkdir($this->templatesDir, 0777, true);
        
        $this->appManager = new AppManager($this->baseDir);
    }
    
    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }
    
    private function getDefaultTemplate(): string
    {
        return TemplateConfig::getInstance()->getDefaultTemplate();
    }
    
    /**
     * Test constructor and directory initialization
     */
    public function testConstructor(): void
    {
        $appManager = new AppManager($this->baseDir);
        
        // Use reflection to check private properties
        $reflection = new \ReflectionClass($appManager);
        
        $baseDirProp = $reflection->getProperty('baseDir');
        $baseDirProp->setAccessible(true);
        $this->assertEquals($this->baseDir, $baseDirProp->getValue($appManager));
        
        $installerDirProp = $reflection->getProperty('installerDir');
        $installerDirProp->setAccessible(true);
        $this->assertEquals($this->installerDir, $installerDirProp->getValue($appManager));
        
        $templatesDirProp = $reflection->getProperty('templatesDir');
        $templatesDirProp->setAccessible(true);
        $this->assertEquals($this->templatesDir, $templatesDirProp->getValue($appManager));
    }
    
    /**
     * Test creating app from template with missing template
     */
    public function testCreateFromTemplateMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Template 'missing-template' not found");
        
        $this->appManager->createFromTemplate('test-app', 'missing-template');
    }
    
    /**
     * Test creating app with invalid name
     */
    public function testCreateFromTemplateInvalidAppName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("App name must contain only lowercase letters, numbers, and hyphens");
        
        $this->appManager->createFromTemplate('Test_App!', $this->getDefaultTemplate());
    }
    
    /**
     * Test creating app when it already exists
     */
    public function testCreateFromTemplateAppAlreadyExists(): void
    {
        // Create template
        $this->createMockTemplate($this->getDefaultTemplate());
        
        // Create app directory
        mkdir($this->installerDir . '/test-app', 0777, true);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("App 'test-app' already exists");
        
        $this->appManager->createFromTemplate('test-app', $this->getDefaultTemplate());
    }
    
    /**
     * Test successful app creation with mocked dependencies
     */
    public function testCreateFromTemplateSuccess(): void
    {
        // Create mock template
        $this->createMockTemplate($this->getDefaultTemplate());
        
        // Use reflection to create a testable AppManager
        $appManager = new class($this->baseDir) extends AppManager {
            public $mockVaultManager;
            
            protected function getVaultManager(): VaultManager
            {
                return $this->mockVaultManager;
            }
        };
        
        $vaultManager = $this->createMock(VaultManager::class);
        $vaultManager->method('isInitialized')->willReturn(false);
        
        $appManager->mockVaultManager = $vaultManager;
        
        $result = $appManager->createFromTemplate('test-app', $this->getDefaultTemplate());
        
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->installerDir . '/test-app');
        $this->assertFileExists($this->baseDir . '/.installer/apps.json');
        $this->assertFileExists($this->baseDir . '/composer.json');
    }
    
    /**
     * Test app installation with missing app
     */
    public function testInstallMissingApp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("App 'missing-app' not found");
        
        $this->appManager->install('missing-app');
    }
    
    /**
     * Test listing apps with no apps
     */
    public function testListAppsEmpty(): void
    {
        $apps = $this->appManager->listApps();
        $this->assertEquals([], $apps);
    }
    
    /**
     * Test listing apps with registered apps
     */
    public function testListAppsWithApps(): void
    {
        // Create apps.json
        $appsData = [
            'apps' => [
                'app1' => ['name' => 'app1', 'template' => $this->getDefaultTemplate()],
                'app2' => ['name' => 'app2', 'template' => $this->getDefaultTemplate()]
            ]
        ];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));
        
        $apps = $this->appManager->listApps();
        $this->assertCount(2, $apps);
        $this->assertArrayHasKey('app1', $apps);
        $this->assertArrayHasKey('app2', $apps);
    }
    
    /**
     * Test checking if app exists
     */
    public function testAppExists(): void
    {
        // Create apps.json
        $appsData = [
            'apps' => [
                'existing-app' => ['name' => 'existing-app', 'template' => $this->getDefaultTemplate()]
            ]
        ];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));
        
        $this->assertTrue($this->appManager->appExists('existing-app'));
        $this->assertFalse($this->appManager->appExists('non-existing-app'));
    }
    
    /**
     * Test loading app configuration
     */
    public function testLoadAppConfig(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        $config = [
            'name' => $appName,
            'features' => ['database' => true, 'eda' => false],
            'containers' => ['app' => ['port' => '8080']]
        ];
        
        file_put_contents($appDir . '/app.nimbus.json', json_encode($config));
        
        $loadedConfig = $this->appManager->loadAppConfig($appName);
        $this->assertEquals($config, $loadedConfig);
    }
    
    /**
     * Test loading config for missing app
     */
    public function testLoadAppConfigMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Config file not found for app 'missing-app'");
        
        $this->appManager->loadAppConfig('missing-app');
    }
    
    /**
     * Test generating unique port
     */
    public function testGeneratePort(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->appManager);
        $method = $reflection->getMethod('generatePort');
        $method->setAccessible(true);
        
        $port1 = $method->invoke($this->appManager, 'app1');
        $port2 = $method->invoke($this->appManager, 'app2');
        $port3 = $method->invoke($this->appManager, 'app1'); // Same app name should give same port
        
        $this->assertIsInt($port1);
        $this->assertIsInt($port2);
        $this->assertGreaterThanOrEqual(8000, $port1);
        $this->assertLessThan(9000, $port1);
        $this->assertNotEquals($port1, $port2);
        $this->assertEquals($port1, $port3);
    }
    
    /**
     * Test generating EDA port
     */
    public function testGenerateEdaPort(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->appManager);
        $method = $reflection->getMethod('generateEdaPort');
        $method->setAccessible(true);
        
        $port1 = $method->invoke($this->appManager, 'app1');
        $port2 = $method->invoke($this->appManager, 'app2');
        
        $this->assertIsInt($port1);
        $this->assertIsInt($port2);
        $this->assertGreaterThanOrEqual(5000, $port1);
        $this->assertLessThan(6000, $port1);
        $this->assertNotEquals($port1, $port2);
    }
    
    /**
     * Test setting EDA on non-existent app
     */
    public function testSetEdaNonExistentApp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("App 'non-existent' not found");
        
        $this->appManager->setEda('non-existent', true);
    }
    
    /**
     * Test setting EDA successfully
     */
    public function testSetEdaSuccess(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        $config = [
            'name' => $appName,
            'features' => ['eda' => false]
        ];
        
        file_put_contents($appDir . '/app.nimbus.json', json_encode($config));
        
        $result = $this->appManager->setEda($appName, true);
        $this->assertTrue($result);
        
        $updatedConfig = json_decode(file_get_contents($appDir . '/app.nimbus.json'), true);
        $this->assertTrue($updatedConfig['features']['eda']);
    }
    
    /**
     * Test adding EDA when already enabled
     */
    public function testAddEdaAlreadyEnabled(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        $config = [
            'name' => $appName,
            'features' => ['eda' => true]
        ];
        
        file_put_contents($appDir . '/app.nimbus.json', json_encode($config));
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("EDA is already enabled for app 'test-app'");
        
        $this->appManager->addEda($appName);
    }
    
    /**
     * Test deleting non-existent app
     */
    public function testDeleteAppNonExistent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("App 'non-existent' not found");
        
        $this->appManager->deleteApp('non-existent');
    }
    
    /**
     * Test deleting app successfully
     */
    public function testDeleteAppSuccess(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        // Create app files
        file_put_contents($appDir . '/test.txt', 'test');
        
        // Create apps.json
        $appsData = ['apps' => [$appName => ['name' => $appName]]];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));
        
        // Create compose file
        file_put_contents($this->baseDir . '/' . $appName . '-compose.yml', 'version: 3.8');
        
        $result = $this->appManager->deleteApp($appName);
        $this->assertTrue($result);
        
        $this->assertDirectoryDoesNotExist($appDir);
        $this->assertFileDoesNotExist($this->baseDir . '/' . $appName . '-compose.yml');
        
        $appsData = json_decode(file_get_contents($this->baseDir . '/.installer/apps.json'), true);
        $this->assertArrayNotHasKey($appName, $appsData['apps']);
    }
    
    /**
     * Test checking podman-compose installation
     */
    public function testCheckPodmanCompose(): void
    {
        $result = AppManager::checkPodmanCompose();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('installed', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('error', $result);
    }
    
    /**
     * Test YAML validation with valid YAML
     */
    public function testValidateYamlValid(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->appManager);
        $method = $reflection->getMethod('validateYaml');
        $method->setAccessible(true);
        
        $validYaml = "version: '3.8'\nservices:\n  app:\n    image: nginx\n    ports:\n      - '80:80'";
        
        $result = $method->invoke($this->appManager, $validYaml);
        $this->assertTrue($result);
    }
    
    /**
     * Test YAML validation with tabs
     */
    public function testValidateYamlWithTabs(): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->appManager);
        $method = $reflection->getMethod('validateYaml');
        $method->setAccessible(true);
        
        $invalidYaml = "version: '3.8'\nservices:\n\tapp:\n\t\timage: nginx";
        
        $result = $method->invoke($this->appManager, $invalidYaml);
        $this->assertFalse($result);
    }
    
    /**
     * Test generating containers with password resolution
     */
    public function testGenerateContainers(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        $config = [
            'name' => $appName,
            'features' => ['database' => true],
            'containers' => [
                'app' => ['port' => '8080'],
                'database' => ['type' => 'postgres']
            ],
            'database' => [
                'name' => $appName . '_db',
                'user' => $appName . '_user',
                'password' => 'test_password'
            ]
        ];
        
        file_put_contents($appDir . '/app.nimbus.json', json_encode($config));
        
        // Use anonymous class to override protected method
        $appManager = new class($this->baseDir) extends AppManager {
            public $mockVaultManager;
            
            protected function getVaultManager(): VaultManager
            {
                return $this->mockVaultManager;
            }
        };
        
        $vaultManager = $this->createMock(VaultManager::class);
        $vaultManager->method('isInitialized')->willReturn(false);
        
        $appManager->mockVaultManager = $vaultManager;
        
        $filename = $appManager->generateContainers($appName);
        
        $this->assertFileExists($filename);
        $this->assertEquals($this->baseDir . '/' . $appName . '-compose.yml', $filename);
        
        $content = file_get_contents($filename);
        $this->assertStringContainsString('version:', $content);
        $this->assertStringContainsString($appName . '-app', $content);
        $this->assertStringContainsString($appName . '-postgres', $content);
    }
    
    /**
     * Test adding Keycloak to existing app
     */
    public function testAddKeycloakSuccess(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        // Create template keycloak files
        $this->createMockTemplate($this->getDefaultTemplate(), true);
        
        $config = [
            'name' => $appName,
            'features' => ['keycloak' => false],
            'containers' => [
                'app' => ['port' => '8080']
            ]
        ];
        
        file_put_contents($appDir . '/app.nimbus.json', json_encode($config));
        file_put_contents($appDir . '/app.config.php', "<?php\nreturn ['keycloak' => ['enabled' => 'false']];");
        
        // Use anonymous class to override protected methods
        $appManager = new class($this->baseDir) extends AppManager {
            public $mockVaultManager;
            public $skipComposeRegeneration = true;
            
            protected function getVaultManager(): VaultManager
            {
                return $this->mockVaultManager;
            }
            
            private function regenerateComposeFile(string $appName, array $config): void
            {
                if (!$this->skipComposeRegeneration) {
                    parent::regenerateComposeFile($appName, $config);
                }
            }
        };
        
        $vaultManager = $this->createMock(VaultManager::class);
        $vaultManager->method('isInitialized')->willReturn(false);
        
        $appManager->mockVaultManager = $vaultManager;
        
        $result = $appManager->addKeycloak($appName);
        $this->assertTrue($result);
        
        $updatedConfig = json_decode(file_get_contents($appDir . '/app.nimbus.json'), true);
        $this->assertTrue($updatedConfig['features']['keycloak']);
        $this->assertArrayHasKey('keycloak', $updatedConfig);
        $this->assertArrayHasKey('keycloak', $updatedConfig['containers']);
        $this->assertArrayHasKey('keycloak-db', $updatedConfig['containers']);
    }
    
    /**
     * Test getting startable apps
     */
    public function testGetStartableApps(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);
        
        // Create compose file
        file_put_contents($this->baseDir . '/' . $appName . '-compose.yml', 'version: 3.8');
        
        $apps = $this->appManager->getStartableApps();
        
        $this->assertIsArray($apps);
        $this->assertCount(1, $apps);
        $this->assertEquals($appName, $apps[0]['name']);
        $this->assertArrayHasKey('compose_file', $apps[0]);
        $this->assertArrayHasKey('has_image', $apps[0]);
        $this->assertArrayHasKey('is_running', $apps[0]);
    }
    
    /**
     * Helper method to create mock template
     */
    private function createMockTemplate(string $templateName, bool $withKeycloak = false): void
    {
        $templateDir = $this->templatesDir . '/' . $templateName;
        mkdir($templateDir, 0777, true);
        
        // Create basic template files
        file_put_contents($templateDir . '/app.nimbus.json', json_encode([
            'name' => '{{APP_NAME}}',
            'features' => ['database' => true],
            'containers' => ['app' => ['port' => '{{APP_PORT}}']],
            'database' => [
                'name' => '{{DB_NAME}}',
                'user' => '{{DB_USER}}',
                'password' => '{{DB_PASSWORD}}'
            ]
        ]));
        
        file_put_contents($templateDir . '/app.config.php', '<?php return [];');
        
        if ($withKeycloak) {
            mkdir($templateDir . '/Controllers', 0777, true);
            mkdir($templateDir . '/Views/auth', 0777, true);
            mkdir($templateDir . '/Views/partials', 0777, true);
            
            file_put_contents($templateDir . '/Controllers/AuthController.php', '<?php // Auth controller');
            file_put_contents($templateDir . '/Views/auth/configure.mustache', '{{APP_NAME}}');
            file_put_contents($templateDir . '/Views/partials/keycloak-section.mustache', '{{APP_NAME}}');
            file_put_contents($templateDir . '/keycloak-init.sh', '#!/bin/sh');
        }
        
        // Create composer.json in base directory
        file_put_contents($this->baseDir . '/composer.json', json_encode([
            'name' => 'test/project',
            'scripts' => []
        ]));
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