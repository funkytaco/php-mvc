<?php

declare(strict_types=1);

namespace Test\Nimbus\App;

use Nimbus\App\GitAppManager;
use Nimbus\App\MVCAppManager;
use Nimbus\Env\EnvManager;
use Nimbus\Vault\VaultManager;
use PHPUnit\Framework\TestCase;

/**
 * describeEnvironment() is what `nimbus:env` renders, so it has to say where
 * every value came from — that grouping is the whole point of the command.
 */
class DescribeEnvironmentTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_desc_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps', 0777, true);
        mkdir($this->baseDir . '/.installer/repos', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function makeVault(): VaultManager
    {
        return new class ($this->baseDir) extends VaultManager {
            /** @var array<string, array<string, mixed>> */
            public array $credentials = [];
            /** @var array<string, array<string, mixed>> */
            public array $nimbus = [];

            public function isInitialized(): bool
            {
                return true;
            }

            public function restoreAppCredentials(string $appName): ?array
            {
                return $this->credentials[$appName] ?? null;
            }

            public function backupAppCredentials(string $appName, array $credentials): bool
            {
                $this->credentials[$appName] = array_merge($this->credentials[$appName] ?? [], $credentials);

                return true;
            }

            public function getNimbusData(string $appName): array
            {
                return $this->nimbus[$appName] ?? [];
            }

            public function setNimbusData(string $appName, array $data): bool
            {
                $this->nimbus[$appName] = $data;

                return true;
            }
        };
    }

    private function makeManager(VaultManager $vault): GitAppManager
    {
        $manager = new class ($this->baseDir) extends GitAppManager {
            public ?VaultManager $vault = null;

            protected function cloneRepository(string $url, ?string $ref, string $targetDir): void
            {
                mkdir($targetDir . '/web', 0777, true);
                file_put_contents($targetDir . '/composer.json', '{}');
                file_put_contents($targetDir . '/web/index.php', '<?php');
                file_put_contents($targetDir . '/.env.example', "WP_ENV='development'\nAUTH_KEY='generateme'\n");
                mkdir($targetDir . '/.git', 0777, true);
            }

            protected function getVaultManager(): VaultManager
            {
                return $this->vault;
            }

            protected function passwordManager(): \Nimbus\Password\PasswordManager
            {
                return new class ($this->getVaultManager(), $this->baseDir) extends \Nimbus\Password\PasswordManager {
                    protected function runCommand(string $command): ?string
                    {
                        return '';
                    }
                };
            }

            protected function runCommand(string $command): ?string
            {
                return '';
            }
        };

        $manager->vault = $vault;

        return $manager;
    }

    public function testEachValueIsAttributedToWhereItCameFrom(): void
    {
        $vault = $this->makeVault();
        $manager = $this->makeManager($vault);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);

        $described = $manager->describeEnvironment('blog');

        // Computed from the app's own config — never written down
        $this->assertSame('blog-db', $described['derived']['DB_HOST']);
        $this->assertSame('3306', $described['derived']['DB_PORT']);
        $this->assertSame('blog_db', $described['derived']['DB_NAME']);
        $this->assertNotEmpty($described['derived']['DB_PASSWORD']);

        // Plain config the user can edit
        $this->assertSame('development', $described['stored']['WP_ENV']);

        // Vault-held
        $this->assertArrayHasKey('AUTH_KEY', $described['secrets']);
        $this->assertSame(64, strlen($described['secrets']['AUTH_KEY']));

        // No value is reported in two places at once
        $this->assertSame(
            [],
            array_intersect_key($described['stored'], $described['secrets']),
            'a value must be attributed to exactly one source'
        );
        $this->assertSame([], array_intersect_key($described['derived'], $described['secrets']));

        $this->assertSame(
            $this->baseDir . '/.installer/apps/blog/.env',
            $described['dotenv']
        );
    }

    public function testAppWithoutADatabaseReportsNoDerivedValues(): void
    {
        $manager = $this->makeManager($this->makeVault());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git');

        $described = $manager->describeEnvironment('blog');

        $this->assertSame([], $described['derived']);
        $this->assertSame('development', $described['stored']['WP_ENV']);
    }

    /**
     * Template apps read app.config.php at request time; reporting a
     * half-populated environment for them would be misleading.
     */
    public function testTemplateAppsReportNoNimbusManagedEnvironment(): void
    {
        $described = (new MVCAppManager($this->baseDir))->describeEnvironment('demo');

        $this->assertSame(['derived' => [], 'stored' => [], 'secrets' => [], 'dotenv' => null], $described);
    }

    /**
     * Whatever describeEnvironment reports must be exactly what lands in the
     * container, or the command is lying about the running app.
     */
    public function testDescribedEnvironmentMatchesTheGeneratedDotEnv(): void
    {
        $manager = $this->makeManager($this->makeVault());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);
        $manager->install('blog');

        $described = $manager->describeEnvironment('blog');
        $merged = array_merge($described['derived'], $described['stored'], $described['secrets']);

        $rendered = (string) file_get_contents($described['dotenv']);

        foreach ($merged as $key => $value) {
            $this->assertStringContainsString("$key='$value'", $rendered, "$key must reach the container");
        }
    }

    /**
     * The masking rule nimbus:env applies has to actually cover the database
     * password, which lives in the derived group rather than the vault one.
     */
    public function testDatabasePasswordIsClassifiedAsSecret(): void
    {
        $envManager = new EnvManager($this->baseDir);

        $this->assertTrue($envManager->isSecretKey('DB_PASSWORD'));
        $this->assertFalse($envManager->isSecretKey('DB_HOST'));
        $this->assertFalse($envManager->isSecretKey('DB_NAME'));
        $this->assertFalse($envManager->isSecretKey('DB_USER'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
