<?php

declare(strict_types=1);

namespace Test\Nimbus\Env;

use Nimbus\Env\SecretsManager;
use Nimbus\Vault\VaultManager;
use PHPUnit\Framework\TestCase;

/**
 * The vault is faked in memory here — the real one shells out to
 * ansible-vault, which these tests must never do.
 */
class SecretsManagerTest extends TestCase
{
    private string $baseDir;
    private VaultManager $vault;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_secrets_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps', 0777, true);

        $this->vault = new class ($this->baseDir) extends VaultManager {
            /** @var array<string, array<string, mixed>> */
            public array $store = [];
            public bool $initialized = true;

            public function isInitialized(): bool
            {
                return $this->initialized;
            }

            public function getNimbusData(string $appName): array
            {
                return $this->store[$appName] ?? [];
            }

            public function setNimbusData(string $appName, array $data): bool
            {
                if (!$this->initialized) {
                    throw new \RuntimeException('Vault not initialized.');
                }

                $this->store[$appName] = $data;

                return true;
            }
        };
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->baseDir);
        }
    }

    private function manager(): SecretsManager
    {
        return new SecretsManager($this->baseDir, $this->vault);
    }

    public function testSecretsRoundTripThroughTheVault(): void
    {
        $manager = $this->manager();

        $manager->set('blog', 'AUTH_KEY', 'abc123');

        $this->assertSame('abc123', $manager->get('blog', 'AUTH_KEY'));
        $this->assertSame(['AUTH_KEY' => 'abc123'], $manager->all('blog'));
        $this->assertTrue($manager->has('blog', 'AUTH_KEY'));
    }

    /**
     * The point of the whole namespace: one app's generation run can never
     * reach another app's secrets (NIST 800-53 IA-9).
     */
    public function testOneAppsSecretsAreInvisibleToAnother(): void
    {
        $manager = $this->manager();

        $manager->set('blog', 'AUTH_KEY', 'blog-only');

        $this->assertSame([], $manager->all('shop'));
        $this->assertNull($manager->get('shop', 'AUTH_KEY'));
        $this->assertFalse($manager->has('shop', 'AUTH_KEY'));
    }

    public function testSecretsAreNeverWrittenToAppConfig(): void
    {
        $appDir = $this->baseDir . '/.installer/apps/blog';
        mkdir($appDir, 0777, true);
        file_put_contents($appDir . '/app.nimbus.json', json_encode(['name' => 'blog']));

        $this->manager()->set('blog', 'AUTH_KEY', 'must-not-leak');

        $this->assertStringNotContainsString(
            'must-not-leak',
            file_get_contents($appDir . '/app.nimbus.json')
        );
    }

    public function testGenerateMissingFillsOnlyWhatIsAbsent(): void
    {
        $manager = $this->manager();
        $manager->set('blog', 'AUTH_KEY', 'already-set');

        $secrets = $manager->generateMissing('blog', ['AUTH_KEY', 'NONCE_SALT']);

        $this->assertSame('already-set', $secrets['AUTH_KEY'], 'existing values are never rolled');
        $this->assertNotEmpty($secrets['NONCE_SALT']);
    }

    /**
     * Re-running create or install must not invalidate every session signed
     * with the previous salts.
     */
    public function testGenerateMissingIsIdempotent(): void
    {
        $manager = $this->manager();

        $first = $manager->generateMissing('blog', ['AUTH_KEY', 'NONCE_SALT']);
        $second = $manager->generateMissing('blog', ['AUTH_KEY', 'NONCE_SALT']);

        $this->assertSame($first, $second);
    }

    public function testGeneratedSecretsAreLongUniqueAndShellSafe(): void
    {
        $secrets = $this->manager()->generateMissing('blog', [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ]);

        $this->assertCount(8, $secrets);
        $this->assertCount(8, array_unique($secrets), 'every salt must be distinct');

        foreach ($secrets as $key => $value) {
            $this->assertSame(64, strlen($value), "$key length");
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $value, "$key charset");
        }
    }

    public function testWritingSecretsPreservesOtherNimbusData(): void
    {
        $this->vault->store['blog'] = ['annotations' => ['managed_by' => 'nimbus']];

        $this->manager()->set('blog', 'AUTH_KEY', 'abc');

        $this->assertSame(['managed_by' => 'nimbus'], $this->vault->store['blog']['annotations']);
        $this->assertSame(['AUTH_KEY' => 'abc'], $this->vault->store['blog']['secrets']);
        $this->assertSame(1, $this->vault->store['blog']['version']);
    }

    public function testUninitializedVaultRefusesToStoreSecrets(): void
    {
        $this->vault->initialized = false;

        $this->expectException(\RuntimeException::class);

        $this->manager()->set('blog', 'AUTH_KEY', 'abc');
    }
}
