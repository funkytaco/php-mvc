<?php

declare(strict_types=1);

namespace Test\Nimbus\Vault;

use Nimbus\Vault\VaultManager;
use PHPUnit\Framework\TestCase;

/**
 * The `nimbus` namespace is stored as one opaque scalar precisely so that the
 * hand-rolled YAML (de)serializer never had to change to support it. These
 * tests pin that: the encoded value must survive a full serialize/parse round
 * trip through the existing code, and existing credential shapes must be
 * untouched.
 */
class VaultNimbusNamespaceTest extends TestCase
{
    private string $baseDir;
    private VaultManager $vault;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_vault_ns_' . uniqid();
        mkdir($this->baseDir, 0777, true);

        $this->vault = new VaultManager($this->baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->vault);
        $handle = $reflection->getMethod($method);
        $handle->setAccessible(true);

        return $handle->invoke($this->vault, ...$args);
    }

    public function testEncodedNamespaceRoundTripsThroughEncodeAndDecode(): void
    {
        $data = [
            'version' => 1,
            'secrets' => [
                'AUTH_KEY' => 'aB3xY9zQ',
                'NONCE_SALT' => 'kL8mN2pR',
            ],
        ];

        $encoded = $this->invoke('encodeNimbus', $data);

        $this->assertStringStartsWith('base64:', $encoded);
        $this->assertSame($data, $this->invoke('decodeNimbus', $encoded));
    }

    /**
     * The parser understands two levels and two section names. The encoded
     * value has to come back byte-identical through it, or an app's secrets
     * are silently lost on the next vault write.
     */
    public function testEncodedNamespaceSurvivesTheYamlSerializerAndParser(): void
    {
        $encoded = $this->invoke('encodeNimbus', [
            'version' => 1,
            'secrets' => ['AUTH_KEY' => str_repeat('a', 64), 'NONCE_SALT' => 'x+y/z=='],
        ]);

        $original = [
            'apps' => [
                'blog' => [
                    'database' => ['password' => 'dbpass'],
                    'nimbus' => $encoded,
                    'backed_up_at' => '2026-07-30T12:00:00+00:00',
                ],
            ],
        ];

        $yaml = $this->invoke('arrayToSimpleYaml', $original);
        $parsed = $this->invoke('parseSimpleYaml', $yaml);

        $this->assertSame($encoded, $parsed['apps']['blog']['nimbus']);
        $this->assertSame('dbpass', $parsed['apps']['blog']['database']['password']);
    }

    /**
     * Base64 output must contain nothing the emitter quotes with or the
     * parser splits on.
     */
    public function testEncodedNamespaceContainsNoYamlHostileCharacters(): void
    {
        $encoded = $this->invoke('encodeNimbus', ['secrets' => ['A' => 'b"c\'d: e']]);

        $payload = substr($encoded, strlen('base64:'));

        $this->assertMatchesRegularExpression('#^[A-Za-z0-9+/=]+$#', $payload);
    }

    public function testDecodingGarbageYieldsNothingRatherThanThrowing(): void
    {
        $this->assertSame([], $this->invoke('decodeNimbus', 'not-encoded-at-all'));
        $this->assertSame([], $this->invoke('decodeNimbus', 'base64:!!!not-base64!!!'));
        $this->assertSame([], $this->invoke('decodeNimbus', 'base64:' . base64_encode('not json')));
    }

    public function testReadingTheNamespaceOfAnUninitializedVaultIsEmpty(): void
    {
        $this->assertFalse($this->vault->isInitialized());
        $this->assertSame([], $this->vault->getNimbusData('blog'));
    }

    public function testWritingToAnUninitializedVaultIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/');

        $this->vault->setNimbusData('blog', ['secrets' => ['A' => 'b']]);
    }

    /**
     * A vault whose encrypted store is swapped for an in-memory one, so the
     * real read/merge/write methods run without ansible-vault.
     */
    private function inMemoryVault(array $seed = ['apps' => []]): VaultManager
    {
        $vault = new class ($this->baseDir) extends VaultManager {
            /** @var array<string, mixed> */
            public array $store = ['apps' => []];

            public function isInitialized(): bool
            {
                return true;
            }

            protected function loadCredentials(): array
            {
                return $this->store;
            }

            protected function encryptAndSave(array $data): bool
            {
                $this->store = $data;

                return true;
            }
        };

        $vault->store = $seed;

        return $vault;
    }

    /**
     * Credentials and the nimbus namespace are written by different callers at
     * different times; a plain assignment in backupAppCredentials() dropped
     * whichever had been written first.
     */
    public function testBackingUpCredentialsPreservesAnExistingNamespace(): void
    {
        $vault = $this->inMemoryVault([
            'apps' => [
                'blog' => [
                    'nimbus' => 'base64:existing',
                    'database' => ['password' => 'old'],
                ],
            ],
        ]);

        $vault->backupAppCredentials('blog', ['database' => ['password' => 'new']]);

        $this->assertSame('base64:existing', $vault->store['apps']['blog']['nimbus']);
        $this->assertSame('new', $vault->store['apps']['blog']['database']['password']);
        $this->assertArrayHasKey('backed_up_at', $vault->store['apps']['blog']);
    }

    public function testNamespaceRoundTripsThroughTheRealReadAndWritePath(): void
    {
        $vault = $this->inMemoryVault();

        $vault->setNimbusData('blog', ['version' => 1, 'secrets' => ['AUTH_KEY' => 'abc123']]);

        $this->assertSame(
            ['version' => 1, 'secrets' => ['AUTH_KEY' => 'abc123']],
            $vault->getNimbusData('blog')
        );

        // Stored encoded, not as nested YAML the parser could not round-trip
        $this->assertStringStartsWith('base64:', $vault->store['apps']['blog']['nimbus']);
    }

    public function testWritingTheNamespacePreservesExistingCredentials(): void
    {
        $vault = $this->inMemoryVault([
            'apps' => ['blog' => ['database' => ['password' => 'keepme']]],
        ]);

        $vault->setNimbusData('blog', ['secrets' => ['AUTH_KEY' => 'abc']]);

        $this->assertSame('keepme', $vault->store['apps']['blog']['database']['password']);
        $this->assertSame(['AUTH_KEY' => 'abc'], $vault->getNimbusData('blog')['secrets']);
    }

    public function testOneAppsNamespaceIsNotVisibleToAnother(): void
    {
        $vault = $this->inMemoryVault();

        $vault->setNimbusData('blog', ['secrets' => ['AUTH_KEY' => 'blog-only']]);

        $this->assertSame([], $vault->getNimbusData('shop'));
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
