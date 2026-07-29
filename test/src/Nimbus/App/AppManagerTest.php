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

        // The base manager is source-agnostic: template paths belong to
        // MVCAppManager, not here.
        $this->assertFalse($reflection->hasProperty('templatesDir'));
    }

    /**
     * A failed create must not leave a registry entry behind. unregisterApp()
     * used to read .installer/apps/apps.json while every writer used
     * .installer/apps.json, so rollback silently did nothing.
     */
    public function testUnregisterAppUsesTheSameRegistryAsRegisterApp(): void
    {
        $registryFile = $this->baseDir . '/.installer/apps.json';
        file_put_contents($registryFile, json_encode([
            'apps' => ['doomed' => ['name' => 'doomed', 'template' => 'whatever']],
        ]));

        $unregister = new \ReflectionMethod($this->appManager, 'unregisterApp');
        $unregister->setAccessible(true);
        $unregister->invoke($this->appManager, 'doomed');

        $registry = json_decode(file_get_contents($registryFile), true);
        $this->assertArrayNotHasKey('doomed', $registry['apps']);
    }

    /**
     * Apps that ship their own build context declare no asset map; install
     * must still generate their compose file instead of fataling.
     */
    public function testInstallWithoutAssetMapGeneratesCompose(): void
    {
        $appName = 'no-assets-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);

        file_put_contents($appDir . '/app.nimbus.json', json_encode([
            'name' => $appName,
            'features' => ['database' => false],
            'containers' => ['app' => ['port' => '8123']],
        ]));

        $this->assertTrue($this->appManager->install($appName));
        $this->assertFileExists($this->baseDir . '/' . $appName . '-compose.yml');
    }

    /**
     * Ports are exposed publicly so callers stop re-deriving the hash.
     */
    public function testGetServicePort(): void
    {
        $this->assertSame(
            $this->appManager->getServicePort('demo', 'app'),
            $this->appManager->getServicePort('demo', 'app')
        );
        $this->assertGreaterThanOrEqual(8000, $this->appManager->getServicePort('demo', 'app'));
        $this->assertLessThan(9000, $this->appManager->getServicePort('demo', 'app'));
        $this->assertGreaterThanOrEqual(5000, $this->appManager->getServicePort('demo', 'eda'));
        $this->assertLessThan(6000, $this->appManager->getServicePort('demo', 'eda'));

        $this->expectException(\InvalidArgumentException::class);
        $this->appManager->getServicePort('demo', 'nope');
    }
    
    /**
     * Hyphenated names are the documented convention and must keep working.
     */
    public function testValidateAppNameAcceptsHyphenatedNames(): void
    {
        $validate = new \ReflectionMethod($this->appManager, 'validateAppName');
        $validate->setAccessible(true);

        foreach (['yo-sup', 'my-app', 'a-b-c', 'app123', 'a'] as $name) {
            $validate->invoke($this->appManager, $name);
        }

        $this->addToAssertionCount(1);
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
     * Test listing apps with registered apps.
     *
     * listApps() is self-healing: a registry entry only counts when its
     * .installer/apps/<name>/ directory exists on disk, so the fixture must
     * create both.
     */
    public function testListAppsWithApps(): void
    {
        // Create apps.json AND the backing app directories
        $appsData = [
            'apps' => [
                'app1' => ['name' => 'app1', 'template' => $this->getDefaultTemplate()],
                'app2' => ['name' => 'app2', 'template' => $this->getDefaultTemplate()]
            ]
        ];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));
        mkdir($this->installerDir . '/app1', 0777, true);
        mkdir($this->installerDir . '/app2', 0777, true);

        $apps = $this->appManager->listApps();
        $this->assertCount(2, $apps);
        $this->assertArrayHasKey('app1', $apps);
        $this->assertArrayHasKey('app2', $apps);
    }

    /**
     * Test that listApps() prunes "ghost" registry entries — apps whose
     * directory disappeared (manual rm -rf, interrupted delete) — and
     * persists the cleaned registry back to apps.json.
     */
    public function testListAppsPrunesGhostEntries(): void
    {
        $appsData = [
            'apps' => [
                'real-app' => ['name' => 'real-app', 'template' => $this->getDefaultTemplate()],
                'ghost-app' => ['name' => 'ghost-app', 'template' => $this->getDefaultTemplate()]
            ]
        ];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));
        mkdir($this->installerDir . '/real-app', 0777, true);
        // ghost-app deliberately gets no directory

        $apps = $this->appManager->listApps();
        $this->assertCount(1, $apps);
        $this->assertArrayHasKey('real-app', $apps);
        $this->assertArrayNotHasKey('ghost-app', $apps);

        // The prune must be persisted, not just filtered from this call
        $registry = json_decode(file_get_contents($this->baseDir . '/.installer/apps.json'), true);
        $this->assertArrayNotHasKey('ghost-app', $registry['apps']);
        $this->assertArrayHasKey('real-app', $registry['apps']);
    }

    /**
     * Test checking if app exists
     */
    public function testAppExists(): void
    {
        // Create apps.json AND the backing app directory (see testListAppsWithApps)
        $appsData = [
            'apps' => [
                'existing-app' => ['name' => 'existing-app', 'template' => $this->getDefaultTemplate()]
            ]
        ];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));
        mkdir($this->installerDir . '/existing-app', 0777, true);

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
        // Deleting an app whose directory is already gone is a SUCCESS, not
        // an error: the end state the caller wants (app gone) is already
        // true. deleteApp() cleans up stragglers — here, a stale "ghost"
        // registry entry — instead of throwing.
        $appsData = [
            'apps' => [
                'non-existent' => ['name' => 'non-existent', 'template' => $this->getDefaultTemplate()]
            ]
        ];
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode($appsData));

        $result = $this->appManager->deleteApp('non-existent');

        $this->assertTrue($result);
        $registry = json_decode(file_get_contents($this->baseDir . '/.installer/apps.json'), true);
        $this->assertArrayNotHasKey('non-existent', $registry['apps'], 'stale registry entry must be cleaned up');
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