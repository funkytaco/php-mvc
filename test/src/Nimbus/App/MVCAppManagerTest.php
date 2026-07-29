<?php

namespace Test\Nimbus\App;

use PHPUnit\Framework\TestCase;
use Nimbus\App\MVCAppManager;
use Nimbus\Template\TemplateManager;
use Nimbus\Vault\VaultManager;

/**
 * Covers the template-scaffolding half of the manager hierarchy.
 *
 * Every manager here is built with an explicit TemplateManager pointed at
 * the test's temp templates dir — without it, template resolution falls back
 * to getcwd() and the tests silently exercise the real repo's templates.
 */
class MVCAppManagerTest extends TestCase
{
    private MVCAppManager $appManager;
    private string $baseDir;
    private string $installerDir;
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_mvc_' . uniqid();
        $this->installerDir = $this->baseDir . '/.installer/apps';
        $this->templatesDir = $this->baseDir . '/.installer/_templates';

        mkdir($this->installerDir, 0777, true);
        mkdir($this->templatesDir, 0777, true);

        // A template must exist before TemplateManager will scan the dir
        $this->createMockTemplate('mvc-test-template');

        $this->appManager = $this->makeManager();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function makeManager(): MVCAppManager
    {
        return new MVCAppManager($this->baseDir, new TemplateManager($this->templatesDir));
    }

    public function testSupportsCommit(): void
    {
        $this->assertTrue($this->appManager->supportsCommit());
    }

    public function testCreateFromTemplateMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Template 'missing-template' not found");

        $this->appManager->createFromTemplate('test-app', 'missing-template');
    }

    public function testCreateFromTemplateInvalidAppName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('App name must start with a letter or number');

        $this->appManager->createFromTemplate('Test_App!', 'mvc-test-template');
    }

    /**
     * Names that pass a naive [a-z0-9-]+ check but produce container names
     * podman rejects, or that collide with framework behavior.
     *
     * @dataProvider invalidAppNameProvider
     */
    public function testCreateFromTemplateRejectsPodmanIncompatibleNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->appManager->createFromTemplate($name, 'mvc-test-template');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAppNameProvider(): array
    {
        return [
            'leading hyphen' => ['-lead'],       // podman: names must match [a-zA-Z0-9]...
            'bare hyphen' => ['-'],
            'trailing hyphen' => ['trail-'],     // yields "trail--app", collides with prefix matching
            'reserved lkui' => ['lkui'],         // Dockerfile builds the default app instead
            'too long' => [str_repeat('a', 49)], // derived hostnames exceed the DNS label limit
        ];
    }

    public function testCreateFromTemplateAppAlreadyExists(): void
    {
        mkdir($this->installerDir . '/test-app', 0777, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("App 'test-app' already exists");

        $this->appManager->createFromTemplate('test-app', 'mvc-test-template');
    }

    public function testCreateFromTemplateSuccess(): void
    {
        $appManager = $this->makeVaultlessManager();

        $result = $appManager->createFromTemplate('test-app', 'mvc-test-template');

        $this->assertTrue($result);
        $this->assertDirectoryExists($this->installerDir . '/test-app');
        $this->assertFileExists($this->baseDir . '/.installer/apps.json');

        // The app records the template it came from - commit and feature
        // scaffolding both resolve the template through it.
        $config = json_decode(file_get_contents($this->installerDir . '/test-app/app.nimbus.json'), true);
        $this->assertSame('test-app', $config['name']);
    }

    /**
     * A failed create must leave neither a half-built instance dir nor a
     * registry entry behind (the rollback path in createAppInstance).
     */
    public function testFailedCreateRollsBackInstanceDirAndRegistry(): void
    {
        // Fail at the last step of the spine, once the instance dir is fully
        // materialized - that is the case where rollback has real work to do.
        $appManager = new class ($this->baseDir, new TemplateManager($this->templatesDir)) extends MVCAppManager {
            public $mockVaultManager;

            protected function getVaultManager(): VaultManager
            {
                return $this->mockVaultManager;
            }

            protected function registerApp(string $appName, string $template): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $appManager->mockVaultManager = $this->vaultManagerDouble();

        // Pre-seed a registry entry so unregisterApp() has something to remove
        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode([
            'apps' => ['doomed-app' => ['name' => 'doomed-app', 'template' => 'mvc-test-template']],
        ]));

        try {
            $appManager->createFromTemplate('doomed-app', 'mvc-test-template');
            $this->fail('expected create to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to create app', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->installerDir . '/doomed-app');

        $registry = json_decode((string) @file_get_contents($this->baseDir . '/.installer/apps.json'), true);
        $this->assertArrayNotHasKey('doomed-app', $registry['apps'] ?? []);
    }

    public function testAddKeycloakSuccess(): void
    {
        $appName = 'test-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);

        $this->createMockTemplate('kc-template', true);

        $config = [
            'name' => $appName,
            'type' => 'kc-template',
            'features' => ['keycloak' => false],
            'containers' => ['app' => ['port' => '8080']],
        ];

        file_put_contents($appDir . '/app.nimbus.json', json_encode($config));
        file_put_contents($appDir . '/app.config.php', "<?php\nreturn ['keycloak' => ['enabled' => 'false']];");

        $appManager = $this->makeVaultlessManager(true);

        $result = $appManager->addKeycloak($appName);
        $this->assertTrue($result);

        $updatedConfig = json_decode(file_get_contents($appDir . '/app.nimbus.json'), true);
        $this->assertTrue($updatedConfig['features']['keycloak']);
        $this->assertArrayHasKey('keycloak', $updatedConfig);
        $this->assertArrayHasKey('keycloak', $updatedConfig['containers']);
        $this->assertArrayHasKey('keycloak-db', $updatedConfig['containers']);
    }

    /**
     * Feature scaffolding must come from the template the app was created
     * from, not from whatever the configured default template happens to be.
     */
    public function testKeycloakScaffoldingUsesTheAppsOwnTemplate(): void
    {
        $appName = 'own-template-app';
        $appDir = $this->installerDir . '/' . $appName;
        mkdir($appDir, 0777, true);

        // Two templates with distinguishable AuthController contents
        $this->createMockTemplate('the-apps-template', true);
        file_put_contents(
            $this->templatesDir . '/the-apps-template/Controllers/AuthController.php',
            '<?php // FROM-THE-APPS-OWN-TEMPLATE'
        );

        file_put_contents($appDir . '/app.nimbus.json', json_encode([
            'name' => $appName,
            'type' => 'the-apps-template',
            'features' => ['keycloak' => false],
            'containers' => ['app' => ['port' => '8080']],
        ]));
        file_put_contents($appDir . '/app.config.php', "<?php\nreturn ['keycloak' => ['enabled' => 'false']];");

        $this->makeVaultlessManager(true)->addKeycloak($appName);

        $this->assertStringContainsString(
            'FROM-THE-APPS-OWN-TEMPLATE',
            file_get_contents($appDir . '/Controllers/AuthController.php')
        );
    }

    private function vaultManagerDouble(): VaultManager
    {
        $vaultManager = $this->createMock(VaultManager::class);
        $vaultManager->method('isInitialized')->willReturn(false);

        return $vaultManager;
    }

    /**
     * MVCAppManager with the vault stubbed out (and optionally compose
     * regeneration suppressed, which needs podman-shaped config).
     */
    private function makeVaultlessManager(bool $skipCompose = false): MVCAppManager
    {
        $appManager = new class ($this->baseDir, new TemplateManager($this->templatesDir)) extends MVCAppManager {
            public $mockVaultManager;
            public $skipComposeRegeneration = false;

            protected function getVaultManager(): VaultManager
            {
                return $this->mockVaultManager;
            }

            protected function regenerateComposeFile(string $appName, array $config): void
            {
                if (!$this->skipComposeRegeneration) {
                    parent::regenerateComposeFile($appName, $config);
                }
            }
        };

        $appManager->mockVaultManager = $this->vaultManagerDouble();
        $appManager->skipComposeRegeneration = $skipCompose;

        return $appManager;
    }

    private function createMockTemplate(string $templateName, bool $withKeycloak = false): void
    {
        $templateDir = $this->templatesDir . '/' . $templateName;
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0777, true);
        }

        file_put_contents($templateDir . '/app.nimbus.json', json_encode([
            'name' => '{{APP_NAME}}',
            'type' => $templateName,
            'features' => ['database' => true],
            'containers' => ['app' => ['port' => '{{APP_PORT}}']],
            'database' => [
                'name' => '{{DB_NAME}}',
                'user' => '{{DB_USER}}',
                'password' => '{{DB_PASSWORD}}',
            ],
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
