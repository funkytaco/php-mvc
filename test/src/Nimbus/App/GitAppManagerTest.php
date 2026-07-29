<?php

namespace Test\Nimbus\App;

use PHPUnit\Framework\TestCase;
use Nimbus\App\AppManagerFactory;
use Nimbus\App\GitAppManager;
use Nimbus\App\MVCAppManager;

/**
 * Shape-only coverage for git-sourced apps: the clone and every podman call
 * are faked, so nothing here touches the network or the container runtime.
 */
class GitAppManagerTest extends TestCase
{
    private string $baseDir;
    private string $installerDir;
    private string $reposDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_git_' . uniqid();
        $this->installerDir = $this->baseDir . '/.installer/apps';
        $this->reposDir = $this->baseDir . '/.installer/repos';

        mkdir($this->installerDir, 0777, true);
        mkdir($this->reposDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    /**
     * A GitAppManager whose clone step drops a fixture on disk instead of
     * reaching the network, and whose shell calls all no-op.
     *
     * @param array<string, string> $files repo-relative path => contents
     */
    private function makeManager(array $files = [], bool $failClone = false): GitAppManager
    {
        $manager = new class ($this->baseDir) extends GitAppManager {
            /** @var array<string, string> */
            public array $repoFiles = [];
            public bool $failClone = false;
            public array $clonedWith = [];

            protected function cloneRepository(string $url, ?string $ref, string $targetDir): void
            {
                $this->clonedWith[] = ['url' => $url, 'ref' => $ref, 'target' => $targetDir];

                if ($this->failClone) {
                    throw new \RuntimeException("git clone failed for '$url': network is a lie");
                }

                mkdir($targetDir . '/.git', 0777, true);
                foreach ($this->repoFiles as $path => $contents) {
                    $full = $targetDir . '/' . $path;
                    if (!is_dir(dirname($full))) {
                        mkdir(dirname($full), 0777, true);
                    }
                    file_put_contents($full, $contents);
                }
            }

            protected function runCommand(string $command): ?string
            {
                return '';
            }
        };

        $manager->repoFiles = $files;
        $manager->failClone = $failClone;

        return $manager;
    }

    /** A Laravel-shaped repo: composer project, front controller in public/. */
    private function laravelish(): array
    {
        return [
            'composer.json' => json_encode([
                'type' => 'project',
                'require' => ['php' => '^8.3'],
            ]),
            'public/index.php' => '<?php // front controller',
            'artisan' => '#!/usr/bin/env php',
        ];
    }

    public function testCreateFromRepoWritesGitSourceConfig(): void
    {
        $manager = $this->makeManager($this->laravelish());

        $this->assertTrue($manager->createFromRepo('shop', 'https://example.com/acme/shop.git'));

        $config = json_decode(
            file_get_contents($this->installerDir . '/shop/app.nimbus.json'),
            true
        );

        $this->assertSame('git', $config['source']['kind']);
        $this->assertSame('shop', $config['source']['repo']);
        $this->assertSame('https://example.com/acme/shop.git', $config['source']['url']);
        $this->assertSame('php', $config['source']['runtime']);
        $this->assertSame('public', $config['source']['docroot']);
        $this->assertSame('/var/www/html', $config['source']['webroot']);
        $this->assertSame(80, $config['source']['container_port']);

        // 'type' means "template directory" elsewhere in Nimbus and must not
        // be repurposed for git apps.
        $this->assertArrayNotHasKey('type', $config);

        // The compose builder only knows how to emit Postgres today.
        $this->assertFalse($config['features']['database']);

        $this->assertArrayHasKey('shop', json_decode(
            file_get_contents($this->baseDir . '/.installer/apps.json'),
            true
        )['apps']);
    }

    public function testCloneTargetsTheSharedReposDirectory(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');

        $this->assertDirectoryExists($this->reposDir . '/shop/.git');

        // The instance dir holds Nimbus files only - the working tree stays clean
        $this->assertFileExists($this->installerDir . '/shop/app.nimbus.json');
        $this->assertDirectoryDoesNotExist($this->installerDir . '/shop/.git');
    }

    public function testRepoNameDerivedFromUrlAndRefIsPassedThrough(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('site', 'git@github.com:roots/bedrock.git', ['ref' => 'v1.2.3']);

        $this->assertDirectoryExists($this->reposDir . '/bedrock');
        $this->assertSame('v1.2.3', $manager->clonedWith[0]['ref']);

        $config = json_decode(file_get_contents($this->installerDir . '/site/app.nimbus.json'), true);
        $this->assertSame('bedrock', $config['source']['repo']);
        $this->assertSame('v1.2.3', $config['source']['ref']);
    }

    /**
     * A clone already sitting in .installer/repos/ is adopted, not re-fetched.
     */
    public function testExistingCloneIsAdoptedWithoutCloning(): void
    {
        mkdir($this->reposDir . '/bedrock/web', 0777, true);
        file_put_contents($this->reposDir . '/bedrock/web/index.php', '<?php');
        file_put_contents($this->reposDir . '/bedrock/Containerfile', "FROM php:8.3-apache\n");

        $manager = $this->makeManager();
        $manager->createFromRepo('blog', 'bedrock');

        $this->assertSame([], $manager->clonedWith, 'should not clone over an existing repo');

        $config = json_decode(file_get_contents($this->installerDir . '/blog/app.nimbus.json'), true);
        $this->assertSame('web', $config['source']['docroot']);
        $this->assertSame('Containerfile', $config['source']['containerfile']);
    }

    /**
     * A .devcontainer image is an IDE environment, not necessarily a servable
     * one (Bedrock's is php-fpm with no web server). It must not be adopted
     * silently — generate a real image and mention the alternative.
     */
    public function testDevcontainerDefinitionIsNotAdoptedSilently(): void
    {
        $manager = $this->makeManager([
            'composer.json' => json_encode(['require' => ['php' => '>=8.3']]),
            'web/index.php' => '<?php',
            '.devcontainer/Dockerfile' => "FROM php:8.3-fpm\n",
        ]);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git');

        $config = json_decode(file_get_contents($this->installerDir . '/blog/app.nimbus.json'), true);
        $this->assertSame('Containerfile', $config['source']['containerfile']);
        $this->assertFileExists($this->reposDir . '/bedrock/Containerfile');

        $this->assertNotEmpty(array_filter(
            $manager->getNotices(),
            fn (string $n) => str_contains($n, '.devcontainer/Dockerfile')
        ));
    }

    /** ...but it can still be selected explicitly. */
    public function testDevcontainerDefinitionCanBeChosenExplicitly(): void
    {
        $manager = $this->makeManager([
            'web/index.php' => '<?php',
            '.devcontainer/Dockerfile' => "FROM php:8.3-fpm\n",
        ]);
        $manager->createFromRepo('blog', 'https://example.com/roots/bedrock.git', [
            'containerfile' => '.devcontainer/Dockerfile',
        ]);

        $config = json_decode(file_get_contents($this->installerDir . '/blog/app.nimbus.json'), true);
        $this->assertSame('.devcontainer/Dockerfile', $config['source']['containerfile']);
    }

    /**
     * Most real PHP repos ship no Containerfile at all; rather than refusing
     * them, Nimbus writes a runtime-appropriate default and says so.
     */
    public function testContainerfileIsGeneratedWhenTheRepoHasNone(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');

        $generated = $this->reposDir . '/shop/Containerfile';
        $this->assertFileExists($generated);

        $contents = file_get_contents($generated);
        $this->assertStringContainsString('FROM php:8.3-apache', $contents);
        $this->assertStringContainsString('composer install', $contents);
        // Front controller lives in public/, so the document root must move
        $this->assertStringContainsString('APACHE_DOCUMENT_ROOT=/var/www/html/public', $contents);

        $this->assertNotEmpty(array_filter(
            $manager->getNotices(),
            fn (string $n) => str_contains($n, 'generated a default one')
        ));
    }

    /** The generated image should honour the PHP version the repo declares. */
    public function testGeneratedContainerfileUsesTheRepoDeclaredPhpVersion(): void
    {
        $manager = $this->makeManager([
            'composer.json' => json_encode(['require' => ['php' => '^8.1', 'ext-gd' => '*']]),
            'index.php' => '<?php',
        ]);
        $manager->createFromRepo('legacy', 'https://example.com/acme/legacy.git');

        $contents = file_get_contents($this->reposDir . '/legacy/Containerfile');
        $this->assertStringContainsString('FROM php:8.1-apache', $contents);
        $this->assertStringContainsString('gd', $contents);
    }

    /**
     * A repo can declare its own settings instead of relying on detection.
     */
    public function testRepoManifestOverridesDetection(): void
    {
        $manager = $this->makeManager([
            'composer.json' => json_encode(['require' => ['php' => '^8.3']]),
            'public/index.php' => '<?php',
            'httpdocs/index.php' => '<?php',
            'Containerfile' => "FROM php:8.3-apache\n",
            GitAppManager::REPO_MANIFEST => json_encode([
                'docroot' => 'httpdocs',
                'container_port' => 8080,
            ]),
        ]);
        $manager->createFromRepo('site', 'https://example.com/acme/site.git');

        $config = json_decode(file_get_contents($this->installerDir . '/site/app.nimbus.json'), true);
        $this->assertSame('httpdocs', $config['source']['docroot']);
        $this->assertSame(8080, $config['source']['container_port']);
    }

    /** An explicit command-line option beats the repo's manifest. */
    public function testExplicitOptionsBeatTheManifest(): void
    {
        $manager = $this->makeManager([
            'public/index.php' => '<?php',
            'httpdocs/index.php' => '<?php',
            'Containerfile' => "FROM php:8.3-apache\n",
            GitAppManager::REPO_MANIFEST => json_encode(['docroot' => 'httpdocs']),
        ]);
        $manager->createFromRepo('site', 'https://example.com/acme/site.git', ['docroot' => 'public']);

        $config = json_decode(file_get_contents($this->installerDir . '/site/app.nimbus.json'), true);
        $this->assertSame('public', $config['source']['docroot']);
    }

    public function testDeclaredDocrootMustExist(): void
    {
        $manager = $this->makeManager(['index.php' => '<?php']);

        $this->expectException(\RuntimeException::class);
        $manager->createFromRepo('site', 'https://example.com/acme/site.git', ['docroot' => 'nope']);
    }

    /** A failed clone must leave no instance dir and no registry entry. */
    public function testFailedCloneRollsBack(): void
    {
        $manager = $this->makeManager([], true);

        try {
            $manager->createFromRepo('doomed', 'https://example.com/acme/doomed.git');
            $this->fail('expected the create to fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to create app', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->installerDir . '/doomed');

        $registry = json_decode((string) @file_get_contents($this->baseDir . '/.installer/apps.json'), true);
        $this->assertArrayNotHasKey('doomed', $registry['apps'] ?? []);
    }

    public function testInstallGeneratesComposeBuildingFromTheClone(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');
        $manager->install('shop');

        $compose = file_get_contents($this->baseDir . '/shop-compose.yml');

        $this->assertStringContainsString('context: "./.installer/repos/shop"', $compose);
        $this->assertStringContainsString('dockerfile: Containerfile', $compose);
        // Default runtime image listens on 80, not the framework's 8080
        $this->assertMatchesRegularExpression('/- \d+:80\b/', $compose);
        // No database service: git apps bring their own for now
        $this->assertStringNotContainsString('shop-postgres', $compose);
    }

    public function testDevOverlayMountsTheCloneAtTheWebroot(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');
        $manager->install('shop');

        $result = $manager->generateDevCompose('shop');
        $overlay = file_get_contents($result['file']);

        $this->assertStringContainsString('./.installer/repos/shop:/var/www/html:Z', $overlay);
        $this->assertStringContainsString('shop-code-server', $overlay);
        $this->assertStringContainsString('/home/coder/workspace', $overlay);

        // The MVC-only mounts must not leak into a git app's overlay
        $this->assertStringNotContainsString('/var/www/src', $overlay);
        $this->assertStringNotContainsString('composer dump-autoload', $overlay);
    }

    /**
     * Regression: bind-mounting the clone over the web root hides whatever the
     * image installed at build time. Every PHP repo gitignores vendor/ (Bedrock
     * keeps WordPress core itself under web/wp), so the first dev run served a
     * tree with no dependencies and died on a missing require.
     */
    public function testDevOverlayInstallsDependenciesIntoTheMountedRepo(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');
        $manager->install('shop');

        $overlay = file_get_contents($manager->generateDevCompose('shop')['file']);

        // Guarded so restarts stay fast, and non-fatal so a failed install
        // still leaves a debuggable container.
        $this->assertStringContainsString('[ ! -d vendor ]', $overlay);
        $this->assertStringContainsString('composer install', $overlay);
        $this->assertStringContainsString('starting server anyway', $overlay);
        $this->assertStringContainsString('exec apache2-foreground', $overlay);
    }

    /** A repo that knows how it starts can say so, instead of being guessed at. */
    public function testDeclaredCommandOverridesServerDetection(): void
    {
        $manager = $this->makeManager([
            'composer.json' => json_encode(['require' => ['php' => '^8.3']]),
            'public/index.php' => '<?php',
            'Containerfile' => "FROM php:8.3-fpm\n",
            GitAppManager::REPO_MANIFEST => json_encode(['command' => 'php-fpm -F']),
        ]);
        $manager->createFromRepo('api', 'https://example.com/acme/api.git');
        $manager->install('api');

        $config = json_decode(file_get_contents($this->installerDir . '/api/app.nimbus.json'), true);
        $this->assertSame('php-fpm -F', $config['source']['command']);

        $overlay = file_get_contents($manager->generateDevCompose('api')['file']);
        $this->assertStringContainsString('exec php-fpm -F', $overlay);
        $this->assertStringNotContainsString('exec apache2-foreground', $overlay);
    }

    /**
     * The YAML emitter writes list items raw and unquoted, so the bootstrap
     * must not contain a colon-space or it silently becomes a mapping.
     */
    public function testDevBootstrapSurvivesTheRawYamlEmitter(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');
        $manager->install('shop');

        $overlay = file_get_contents($manager->generateDevCompose('shop')['file']);

        $script = null;
        foreach (explode("\n", $overlay) as $line) {
            if (str_contains($line, 'cd /var/www/html')) {
                $script = $line;
            }
        }

        $this->assertNotNull($script, 'bootstrap line not found');
        $this->assertStringNotContainsString(': ', $script, 'colon-space would parse as a mapping');
        $this->assertStringNotContainsString(' #', $script, 'hash would start a comment');
        $this->assertStringStartsWith('      - cd ', $script, 'must stay one list item');
    }

    public function testTemplateOnlyFeaturesAreRefused(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not supported for git-sourced apps');
        $manager->addEda('shop');
    }

    public function testCommitIsAHarmlessNoOp(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');

        $this->assertFalse($manager->supportsCommit());
        $this->assertSame(['committed' => [], 'skipped' => []], $manager->commitAppToTemplate('shop'));
    }

    public function testUnsupportedRuntimeIsRejected(): void
    {
        $manager = $this->makeManager($this->laravelish());

        $this->expectException(\RuntimeException::class);
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git', ['runtime' => 'cobol']);
    }

    /**
     * Regression: the CLI resolved a plain AppManager and called install() on
     * it, so a git app's compose was generated by the BASE buildAppService —
     * `context: "."` with the framework's own Dockerfile. The build then ran
     * the framework image against the wrong tree and died on a missing
     * /var/www/app. Anything that generates compose must go through the
     * factory, so assert the factory-resolved manager gets it right.
     */
    public function testFactoryResolvedManagerGeneratesGitCompose(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');

        // Exactly what InstallTask/ContainerTask now do
        AppManagerFactory::forApp('shop', $this->baseDir)->install('shop');

        $compose = file_get_contents($this->baseDir . '/shop-compose.yml');

        $this->assertStringContainsString('context: "./.installer/repos/shop"', $compose);
        $this->assertStringNotContainsString('context: "."', $compose);
        $this->assertStringNotContainsString('APP_NAME', $compose);
    }

    public function testFactoryDispatchesOnSourceKind(): void
    {
        $manager = $this->makeManager($this->laravelish());
        $manager->createFromRepo('shop', 'https://example.com/acme/shop.git');

        $this->assertInstanceOf(GitAppManager::class, AppManagerFactory::forApp('shop', $this->baseDir));

        // No source block at all: a template app from before git support
        mkdir($this->installerDir . '/legacy-app', 0777, true);
        file_put_contents(
            $this->installerDir . '/legacy-app/app.nimbus.json',
            json_encode(['name' => 'legacy-app', 'type' => 'nimbus-app-php'])
        );

        $this->assertInstanceOf(MVCAppManager::class, AppManagerFactory::forApp('legacy-app', $this->baseDir));
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
