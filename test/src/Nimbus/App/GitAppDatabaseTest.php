<?php

declare(strict_types=1);

namespace Test\Nimbus\App;

use Nimbus\App\GitAppManager;
use Nimbus\Vault\VaultManager;
use PHPUnit\Framework\TestCase;

/**
 * Database opt-in and environment delivery for git-sourced apps.
 *
 * The clone, the vault and every shell call are faked, so nothing here
 * touches the network, ansible-vault or the container runtime.
 */
class GitAppDatabaseTest extends TestCase
{
    private string $baseDir;
    private string $installerDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_gitdb_' . uniqid();
        $this->installerDir = $this->baseDir . '/.installer/apps';

        mkdir($this->installerDir, 0777, true);
        mkdir($this->baseDir . '/.installer/repos', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    /** In-memory stand-in for the ansible-vault backed store. */
    private function makeVault(bool $initialized = true): VaultManager
    {
        return new class ($this->baseDir, $initialized) extends VaultManager {
            /** @var array<string, array<string, mixed>> */
            public array $credentials = [];
            /** @var array<string, array<string, mixed>> */
            public array $nimbus = [];

            public function __construct(string $baseDir, private bool $initialized)
            {
                parent::__construct($baseDir);
            }

            public function isInitialized(): bool
            {
                return $this->initialized;
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
                if (!$this->initialized) {
                    throw new \RuntimeException('Vault not initialized.');
                }

                $this->nimbus[$appName] = $data;

                return true;
            }
        };
    }

    /**
     * @param array<string, string> $files repo-relative path => contents
     */
    private function makeManager(array $files = [], ?VaultManager $vault = null): GitAppManager
    {
        $vault ??= $this->makeVault();

        $manager = new class ($this->baseDir) extends GitAppManager {
            /** @var array<string, string> */
            public array $repoFiles = [];
            public ?VaultManager $vault = null;

            protected function cloneRepository(string $url, ?string $ref, string $targetDir): void
            {
                mkdir($targetDir . '/.git', 0777, true);

                foreach ($this->repoFiles as $path => $contents) {
                    $full = $targetDir . '/' . $path;
                    if (!is_dir(dirname($full))) {
                        mkdir(dirname($full), 0777, true);
                    }
                    file_put_contents($full, $contents);
                }
            }

            protected function getVaultManager(): VaultManager
            {
                return $this->vault;
            }

            /**
             * Stubbed too, so strategy selection never depends on which
             * containers happen to be running on the machine.
             */
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

        $manager->repoFiles = $files;
        $manager->vault = $vault;

        return $manager;
    }

    /** A Bedrock-shaped repo: WordPress docroot in web/, salts in .env.example. */
    private function bedrockish(): array
    {
        return [
            'composer.json' => json_encode(['require' => ['php' => '>=8.3']]),
            'web/index.php' => '<?php // front controller',
            '.env.example' => <<<'ENV'
                DB_NAME='database_name'
                DB_USER='database_user'
                DB_PASSWORD='database_password'

                # DB_HOST='localhost'

                WP_ENV='development'
                WP_HOME='http://example.com'
                WP_SITEURL="${WP_HOME}/wp"

                AUTH_KEY='generateme'
                NONCE_SALT='generateme'
                ENV,
        ];
    }

    private function configFor(string $appName): array
    {
        return json_decode(
            file_get_contents($this->installerDir . '/' . $appName . '/app.nimbus.json'),
            true
        );
    }

    public function testDatabaseIsOffUnlessAskedFor(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git');

        $config = $this->configFor('blog');

        $this->assertFalse($config['features']['database']);
        $this->assertArrayNotHasKey('database', $config);
    }

    public function testBareOptInGetsMariadbAndNeverStoresThePassword(): void
    {
        $vault = $this->makeVault();
        $manager = $this->makeManager($this->bedrockish(), $vault);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);

        $config = $this->configFor('blog');

        $this->assertTrue($config['features']['database']);
        $this->assertSame('mariadb', $config['database']['engine']);
        $this->assertSame('mariadb:12', $config['database']['image']);
        $this->assertSame('blog_db', $config['database']['name']);
        $this->assertSame('blog_user', $config['database']['user']);

        // The credential belongs in the vault and nowhere else
        $this->assertArrayNotHasKey('password', $config['database']);
        $this->assertNotEmpty($vault->credentials['blog']['database']['password']);
        $this->assertStringNotContainsString(
            $vault->credentials['blog']['database']['password'],
            file_get_contents($this->installerDir . '/blog/app.nimbus.json')
        );
    }

    public function testExplicitEngineAndImageAreHonoured(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git', ['database' => 'mysql']);

        $this->assertSame('mysql', $this->configFor('shop')['database']['engine']);
        $this->assertSame('mysql:8.4', $this->configFor('shop')['database']['image']);
    }

    public function testHyphenatedAppNameBecomesASafeSqlIdentifier(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('my-blog', 'https://example.com/roots/bedrock.git', ['database' => true]);

        $config = $this->configFor('my-blog');

        $this->assertSame('my_blog_db', $config['database']['name']);
        $this->assertSame('my_blog_user', $config['database']['user']);
    }

    public function testRepoManifestCanRequestADatabaseAndTheCommandLineWins(): void
    {
        $files = $this->bedrockish();

        $files['.nimbus.json'] = json_encode(['docroot' => 'web', 'database' => 'mariadb']);
        $manager = $this->makeManager($files);
        $manager->createFromRepo('from-manifest', 'https://example.com/roots/bedrock.git');
        $this->assertSame('mariadb', $this->configFor('from-manifest')['database']['engine']);

        $manager = $this->makeManager($files);
        $manager->createFromRepo('cli-wins', 'https://example.com/roots/bedrock.git', ['database' => 'mysql']);
        $this->assertSame('mysql', $this->configFor('cli-wins')['database']['engine']);

        // --no-db turns off what the manifest asked for
        $manager = $this->makeManager($files);
        $manager->createFromRepo('cli-off', 'https://example.com/roots/bedrock.git', ['database' => false]);
        $this->assertFalse($this->configFor('cli-off')['features']['database']);
        $this->assertArrayNotHasKey('database', $this->configFor('cli-off'));
    }

    public function testCreateRefusesADatabaseWhenTheVaultIsMissing(): void
    {
        $manager = $this->makeManager($this->bedrockish(), $this->makeVault(false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vault/i');

        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);
    }

    public function testFailedCreateLeavesNoAppButKeepsVaultCredentials(): void
    {
        $vault = $this->makeVault();
        $vault->credentials['blog'] = ['database' => ['password' => 'previously-generated']];

        $manager = $this->makeManager($this->bedrockish(), $vault);

        // A docroot that does not exist fails after the clone succeeds
        try {
            $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', [
                'database' => true,
                'docroot' => 'nope',
            ]);
            $this->fail('expected creation to fail');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertDirectoryDoesNotExist($this->installerDir . '/blog');
        $this->assertSame('previously-generated', $vault->credentials['blog']['database']['password']);
    }

    public function testRecreatingAnAppReusesItsStoredPassword(): void
    {
        $vault = $this->makeVault();
        $vault->credentials['blog'] = ['database' => ['password' => 'the-original-password']];

        $manager = $this->makeManager($this->bedrockish(), $vault);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);

        $manager->install('blog');

        $this->assertStringContainsString(
            'the-original-password',
            file_get_contents($this->baseDir . '/blog-compose.yml')
        );
    }

    public function testEnvSeedingSplitsSecretsFromPlainValues(): void
    {
        $vault = $this->makeVault();
        $manager = $this->makeManager($this->bedrockish(), $vault);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);

        $config = $this->configFor('blog');

        // Plain values are recorded as editable app config
        $this->assertSame('development', $config['env']['WP_ENV']);

        // Values Nimbus computes are never seeded
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $derived) {
            $this->assertArrayNotHasKey($derived, $config['env']);
        }

        // Secrets go to the vault, are never the example value, and never
        // appear in app config
        $secrets = $vault->nimbus['blog']['secrets'];
        $this->assertArrayHasKey('AUTH_KEY', $secrets);
        $this->assertArrayHasKey('NONCE_SALT', $secrets);
        $this->assertNotSame('generateme', $secrets['AUTH_KEY']);
        $this->assertSame(64, strlen($secrets['AUTH_KEY']));
        $this->assertArrayNotHasKey('AUTH_KEY', $config['env']);
    }

    public function testExampleUrlsArePointedAtTheAppsOwnPortAndFlattened(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);

        $config = $this->configFor('blog');
        $port = $config['containers']['app']['port'];

        $this->assertSame("http://localhost:$port", $config['env']['WP_HOME']);

        // Bedrock requires WP_SITEURL, and a compose environment block is not
        // interpolated — the reference has to be resolved here.
        $this->assertSame("http://localhost:$port/wp", $config['env']['WP_SITEURL']);
        $this->assertStringNotContainsString('$', $config['env']['WP_SITEURL']);
    }

    public function testComposeCarriesTheResolvedEnvironmentAndDotEnvMount(): void
    {
        $vault = $this->makeVault();
        $manager = $this->makeManager($this->bedrockish(), $vault);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);
        $manager->install('blog');

        $compose = file_get_contents($this->baseDir . '/blog-compose.yml');

        // Delivery path 1: real environment variables on the app service
        $this->assertStringContainsString('DB_HOST: blog-db', $compose);
        $this->assertStringContainsString('DB_NAME: blog_db', $compose);
        $this->assertStringContainsString('DB_USER: blog_user', $compose);
        $this->assertStringContainsString('AUTH_KEY:', $compose);

        // Delivery path 2: the generated .env, mounted as a single file
        $this->assertStringContainsString(
            './.installer/apps/blog/.env:/var/www/html/.env:Z',
            $compose
        );

        // The database itself
        $this->assertStringContainsString('image: "mariadb:12"', $compose);
        $this->assertStringContainsString('container_name: blog-db', $compose);
        $this->assertStringContainsString('MYSQL_DATABASE: blog_db', $compose);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD:', $compose);
        $this->assertStringContainsString('blog-db-data:/var/lib/mysql', $compose);

        // WordPress must not take its first request before the database is up
        $this->assertStringContainsString('condition: service_healthy', $compose);
    }

    public function testInstallWritesAnOwnerReadableDotEnv(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);
        $manager->install('blog');

        $dotEnv = $this->installerDir . '/blog/.env';

        $this->assertFileExists($dotEnv);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dotEnv)), -4));

        $contents = file_get_contents($dotEnv);
        $this->assertStringContainsString("DB_HOST='blog-db'", $contents);
        $this->assertStringContainsString("WP_ENV='development'", $contents);
        $this->assertStringContainsString('AUTH_KEY=', $contents);
    }

    public function testDevOverlayMountsTheDotEnvOverTheClone(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);
        $manager->install('blog');
        $manager->generateDevCompose('blog');

        $overlay = file_get_contents($this->baseDir . '/blog-compose.dev.yml');

        $repoMount = strpos($overlay, '.installer/repos/bedrock:/var/www/html:Z');
        $envMount = strpos($overlay, '.installer/apps/blog/.env:/var/www/html/.env:Z');

        $this->assertNotFalse($repoMount);
        $this->assertNotFalse($envMount);
        $this->assertGreaterThan(
            $repoMount,
            $envMount,
            'the .env mount must come after the repo mount so it lands on top'
        );
    }

    public function testAppWithoutADatabaseGetsNoEnvironmentBlockOrMount(): void
    {
        $manager = $this->makeManager(['composer.json' => '{}', 'web/index.php' => '<?php']);
        $manager->createFromRepo('static', 'https://example.com/acme/static.git');
        $manager->install('static');

        $compose = file_get_contents($this->baseDir . '/static-compose.yml');

        $this->assertStringNotContainsString('environment:', $compose);
        $this->assertStringNotContainsString('/.env:', $compose);
        $this->assertStringNotContainsString('-db', $compose);
    }

    /**
     * A bare repository ships no schema.sql, and mounting one that is not
     * there makes podman create a directory in its place.
     */
    public function testNoSchemaMountWhenTheAppShipsNoSchema(): void
    {
        $manager = $this->makeManager($this->bedrockish());
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', ['database' => true]);
        $manager->install('blog');

        $this->assertStringNotContainsString(
            'schema.sql',
            file_get_contents($this->baseDir . '/blog-compose.yml')
        );
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
