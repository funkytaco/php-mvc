<?php

declare(strict_types=1);

namespace Test\Nimbus\Env;

use Nimbus\Env\EnvManager;
use Nimbus\Env\SecretsManager;
use Nimbus\Vault\VaultManager;
use PHPUnit\Framework\TestCase;

class EnvManagerTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_env_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function makeApp(string $appName, array $config = []): void
    {
        $dir = $this->baseDir . '/.installer/apps/' . $appName;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/app.nimbus.json', json_encode($config + ['name' => $appName]));
    }

    public function testStoredEnvRoundTripsThroughAppConfig(): void
    {
        $this->makeApp('blog');
        $manager = new EnvManager($this->baseDir);

        $manager->set('blog', 'WP_ENV', 'development');

        $this->assertSame('development', $manager->get('blog', 'WP_ENV'));
        $this->assertSame(['WP_ENV' => 'development'], $manager->all('blog'));

        $config = json_decode(file_get_contents($this->baseDir . '/.installer/apps/blog/app.nimbus.json'), true);
        $this->assertSame('development', $config['env']['WP_ENV']);
        $this->assertSame('blog', $config['name'], 'existing config keys must survive');
    }

    public function testOneAppsEnvIsNeverVisibleToAnother(): void
    {
        $this->makeApp('blog');
        $this->makeApp('shop');
        $manager = new EnvManager($this->baseDir);

        $manager->set('blog', 'WP_HOME', 'http://localhost:8531');

        $this->assertSame([], $manager->all('shop'));
        $this->assertNull($manager->get('shop', 'WP_HOME'));
    }

    public function testResolveLayersDerivedThenStoredThenSecrets(): void
    {
        $this->makeApp('blog');
        $manager = new EnvManager($this->baseDir);
        $manager->setMany('blog', ['WP_ENV' => 'development', 'DB_HOST' => 'overridden']);

        $secrets = $this->makeSecrets(['AUTH_KEY' => 'sekret']);

        $resolved = $manager->resolve('blog', ['DB_HOST' => 'blog-db', 'DB_NAME' => 'blog_db'], $secrets);

        $this->assertSame('blog_db', $resolved['DB_NAME'], 'derived value with no override survives');
        $this->assertSame('overridden', $resolved['DB_HOST'], 'stored env overrides a derived value');
        $this->assertSame('development', $resolved['WP_ENV']);
        $this->assertSame('sekret', $resolved['AUTH_KEY'], 'secrets are layered in');
    }

    public function testSecretKeyClassification(): void
    {
        $manager = new EnvManager($this->baseDir);

        // Every salt Bedrock requires
        foreach ([
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ] as $key) {
            $this->assertTrue($manager->isSecretKey($key), "$key should be secret");
        }

        $this->assertTrue($manager->isSecretKey('DB_PASSWORD'));
        $this->assertTrue($manager->isSecretKey('API_TOKEN'));
        $this->assertTrue($manager->isSecretKey('STRIPE_SECRET'));

        foreach (['WP_ENV', 'WP_HOME', 'DB_PREFIX', 'KEYCLOAK_URL', 'MONKEYS'] as $key) {
            $this->assertFalse($manager->isSecretKey($key), "$key should not be secret");
        }
    }

    public function testParseEnvExampleReadsBedrockShapedFiles(): void
    {
        $repo = $this->baseDir . '/repo';
        mkdir($repo, 0777, true);
        file_put_contents($repo . '/.env.example', <<<'ENV'
            DB_NAME='database_name'
            DB_USER='database_user'

            # Optionally, a DSN
            # DATABASE_URL='mysql://u:p@h:3306/n'

            # Optional database variables
            # DB_HOST='localhost'

            WP_ENV='development'
            WP_HOME='http://example.com'
            WP_SITEURL="${WP_HOME}/wp"

            AUTH_KEY='generateme'
            ENV);

        $parsed = (new EnvManager($this->baseDir))->parseEnvExample($repo);

        $this->assertSame('database_name', $parsed['DB_NAME']);
        $this->assertSame('development', $parsed['WP_ENV']);
        $this->assertSame('http://example.com', $parsed['WP_HOME']);
        $this->assertSame('${WP_HOME}/wp', $parsed['WP_SITEURL']);

        // Commented-out optional settings stay unset rather than being adopted
        $this->assertArrayNotHasKey('DB_HOST', $parsed);
        $this->assertArrayNotHasKey('DATABASE_URL', $parsed);
    }

    public function testParseEnvExampleReturnsNothingWhenTheRepoHasNone(): void
    {
        $this->assertSame([], (new EnvManager($this->baseDir))->parseEnvExample($this->baseDir));
    }

    public function testRenderedDotEnvQuotesValuesLiterally(): void
    {
        $rendered = (new EnvManager($this->baseDir))->renderDotEnv([
            'WP_HOME' => 'http://localhost:8531',
            'DB_PASSWORD' => 'a$b`c',
        ]);

        $this->assertStringContainsString("WP_HOME='http://localhost:8531'", $rendered);
        // Single quotes keep $ and ` from being interpreted
        $this->assertStringContainsString("DB_PASSWORD='a\$b`c'", $rendered);
    }

    public function testValueContainingASingleQuoteFallsBackToEscapedDoubleQuotes(): void
    {
        // A single quote is the one character that cannot be escaped inside
        // single quotes, so it forces the double-quoted path — where $ has to
        // be escaped in turn or dotenv would interpolate it.
        $rendered = (new EnvManager($this->baseDir))->renderDotEnv(['SITE_TAGLINE' => "it's $10 a month"]);

        $this->assertStringContainsString('SITE_TAGLINE="it\'s \\$10 a month"', $rendered);
    }

    /**
     * The file holds resolved credentials, so it is an authenticator store
     * and must not be world- or group-readable (NIST 800-53 IA-5).
     */
    public function testWrittenDotEnvIsOwnerReadableOnly(): void
    {
        $this->makeApp('blog');

        $path = (new EnvManager($this->baseDir))->writeDotEnv('blog', ['DB_PASSWORD' => 'hunter2']);

        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertStringContainsString("DB_PASSWORD='hunter2'", file_get_contents($path));
    }

    /**
     * It lives in the Nimbus-owned instance dir, never in the git working
     * tree the user commits from.
     */
    public function testDotEnvLivesInTheInstanceDirectory(): void
    {
        $this->assertSame(
            $this->baseDir . '/.installer/apps/blog/.env',
            (new EnvManager($this->baseDir))->dotEnvPath('blog')
        );
    }

    private function makeSecrets(array $values): SecretsManager
    {
        $vault = new class ($this->baseDir, $values) extends VaultManager {
            public function __construct(string $baseDir, private array $values)
            {
                parent::__construct($baseDir);
            }

            public function isInitialized(): bool
            {
                return true;
            }

            public function getNimbusData(string $appName): array
            {
                return ['secrets' => $this->values];
            }
        };

        return new SecretsManager($this->baseDir, $vault);
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
