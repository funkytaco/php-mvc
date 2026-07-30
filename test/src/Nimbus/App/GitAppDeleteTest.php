<?php

declare(strict_types=1);

namespace Test\Nimbus\App;

use Nimbus\App\GitAppManager;
use Nimbus\Vault\VaultManager;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a git app has to release every container name it owns.
 *
 * The bug this covers: teardown ran `podman-compose -f <app>-compose.yml down`,
 * but the code-server sidecar is declared in the *dev overlay*, so compose
 * never saw it. The container survived, and because create refuses a name that
 * is already taken, the app could no longer be recreated — with nothing left
 * to delete, since the app directory was gone.
 *
 * Every podman call is captured rather than executed.
 */
class GitAppDeleteTest extends TestCase
{
    private string $baseDir;
    private string $installerDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_del_' . uniqid();
        $this->installerDir = $this->baseDir . '/.installer/apps';

        mkdir($this->installerDir, 0777, true);
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

    /**
     * @param array<int, string> $existingContainers what `podman ps -a` reports
     */
    private function makeManager(array $existingContainers = []): GitAppManager
    {
        $manager = new class ($this->baseDir) extends GitAppManager {
            /** @var array<int, string> */
            public array $commands = [];
            /** @var array<int, string> */
            public array $existingContainers = [];
            public ?VaultManager $vault = null;

            protected function cloneRepository(string $url, ?string $ref, string $targetDir): void
            {
                mkdir($targetDir . '/web', 0777, true);
                mkdir($targetDir . '/.git', 0777, true);
                file_put_contents($targetDir . '/composer.json', '{}');
                file_put_contents($targetDir . '/web/index.php', '<?php');
                file_put_contents($targetDir . '/.env.example', "WP_ENV='development'\nAUTH_KEY='generateme'\n");
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
                $this->commands[] = $command;

                if (str_contains($command, 'podman ps -a')) {
                    return implode("\n", $this->existingContainers);
                }

                // Behave like podman: a removed container stops being reported,
                // so a later collision check sees the name as free.
                if (preg_match("/podman rm -f '([^']+)'/", $command, $m) === 1) {
                    $this->existingContainers = array_values(
                        array_diff($this->existingContainers, [$m[1]])
                    );
                }

                return '';
            }
        };

        $manager->vault = $this->makeVault();
        $manager->existingContainers = $existingContainers;

        return $manager;
    }

    /**
     * @param array<int, string> $commands
     */
    private function removalsIn(array $commands): array
    {
        $removed = [];

        foreach ($commands as $command) {
            if (preg_match("/podman rm -f '([^']+)'/", $command, $m) === 1) {
                $removed[] = $m[1];
            }
        }

        return $removed;
    }

    /**
     * Create an app in dev mode, then report its containers as running — the
     * order matters, because create refuses names that are already taken.
     *
     * @param array<int, string> $running containers that exist afterwards
     */
    private function createDevApp(GitAppManager $manager, array $running, string $appName = 'foolio'): void
    {
        $manager->createFromRepo($appName, 'https://github.com/roots/bedrock.git', ['database' => true]);
        $manager->install($appName);
        $manager->generateDevCompose($appName);

        $this->assertFileExists($this->baseDir . "/$appName-compose.dev.yml");

        $manager->existingContainers = $running;
        $manager->commands = [];
    }

    /**
     * The reported bug, end to end.
     */
    public function testDeleteRemovesTheCodeServerSidecar(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'foolio-db', 'foolio-code-server']);

        $manager->deleteApp('foolio');

        $this->assertContains(
            'foolio-code-server',
            $this->removalsIn($manager->commands),
            'the code-server sidecar must not survive delete'
        );
    }

    public function testDeleteTearsDownTheDevOverlayNotJustTheBaseComposeFile(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'foolio-db', 'foolio-code-server']);

        $manager->deleteApp('foolio');

        $down = array_values(array_filter(
            $manager->commands,
            static fn (string $c): bool => str_contains($c, 'podman-compose') && str_contains($c, 'down')
        ));

        $this->assertCount(1, $down);
        $this->assertStringContainsString('foolio-compose.yml', $down[0]);
        $this->assertStringContainsString('foolio-compose.dev.yml', $down[0]);
    }

    public function testDeleteRemovesEveryContainerTheAppOwns(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'foolio-db', 'foolio-code-server']);

        $manager->deleteApp('foolio');

        $removed = $this->removalsIn($manager->commands);

        foreach (['foolio-app', 'foolio-db', 'foolio-code-server'] as $container) {
            $this->assertContains($container, $removed);
        }
    }

    /**
     * Delete must release exactly the names create refuses to reuse, or the
     * app becomes impossible to recreate — which is how this was reported.
     */
    public function testTheAppCanBeRecreatedAfterDelete(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'foolio-db', 'foolio-code-server']);

        $manager->deleteApp('foolio');

        // The stub drops removed containers, so this exercises the real
        // collision check against what delete actually left behind.
        $manager->createFromRepo('foolio', 'https://github.com/roots/bedrock.git', ['database' => true]);

        $this->assertDirectoryExists($this->installerDir . '/foolio');
    }

    public function testDeleteDoesNotTouchAnotherAppsContainers(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'foolio-code-server', 'shop-app', 'shop-code-server']);

        $manager->deleteApp('foolio');

        $removed = $this->removalsIn($manager->commands);

        $this->assertContains('foolio-code-server', $removed);
        $this->assertNotContains('shop-app', $removed);
        $this->assertNotContains('shop-code-server', $removed);
    }

    public function testDeleteRemovesBothGeneratedComposeFiles(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'foolio-code-server']);

        $manager->deleteApp('foolio');

        $this->assertFileDoesNotExist($this->baseDir . '/foolio-compose.yml');
        $this->assertFileDoesNotExist($this->baseDir . '/foolio-compose.dev.yml');
        $this->assertDirectoryDoesNotExist($this->installerDir . '/foolio');
    }

    /**
     * The state the reporter was stuck in: the app directory was already gone,
     * so delete reported "No apps found to delete", while the container kept
     * the name reserved with nothing left able to reclaim it.
     */
    public function testDeleteReclaimsContainersOrphanedByAnEarlierPartialDelete(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-code-server']);

        // Simulate the old buggy delete: everything gone except the sidecar
        $this->removeDirectory($this->installerDir . '/foolio');
        unlink($this->baseDir . '/foolio-compose.yml');
        unlink($this->baseDir . '/foolio-compose.dev.yml');

        $manager->commands = [];
        $this->assertTrue($manager->deleteApp('foolio'));

        $this->assertContains(
            'foolio-code-server',
            $this->removalsIn($manager->commands),
            'an orphaned container must still be reclaimable'
        );
    }

    /**
     * What the CLI needs in order to offer cleanup for an app it no longer has
     * a registry entry for.
     */
    public function testOrphansAreDiscoverableForAnUnregisteredApp(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-code-server']);

        $this->removeDirectory($this->installerDir . '/foolio');
        unlink($this->baseDir . '/foolio-compose.yml');

        $orphans = $manager->findOrphans('foolio');

        $this->assertContains('foolio-code-server', $orphans);
        $this->assertContains($this->baseDir . '/foolio-compose.dev.yml', $orphans);
    }

    public function testNoOrphansReportedForANameNimbusNeverUsed(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, ['foolio-app', 'shop-app']);

        $this->assertSame([], $manager->findOrphans('neverexisted'));
    }

    public function testDeletingAnAppWithNoContainersIssuesNoRemovals(): void
    {
        $manager = $this->makeManager();
        $this->createDevApp($manager, []);

        $manager->deleteApp('foolio');

        $this->assertSame([], $this->removalsIn($manager->commands));
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
