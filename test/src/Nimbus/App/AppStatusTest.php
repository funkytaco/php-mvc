<?php

declare(strict_types=1);

namespace Test\Nimbus\App;

use Nimbus\App\MVCAppManager;
use PHPUnit\Framework\TestCase;

/**
 * describeApp() drives the "is this thing up yet" line printed above every
 * Next steps block, so each lifecycle state has to be distinguishable.
 *
 * Container states are injected rather than read from podman.
 */
class AppStatusTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_status_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps/demo', 0777, true);

        file_put_contents(
            $this->baseDir . '/.installer/apps.json',
            json_encode(['apps' => ['demo' => ['name' => 'demo', 'template' => 'git', 'created' => 'now']]])
        );
        file_put_contents(
            $this->baseDir . '/.installer/apps/demo/app.nimbus.json',
            json_encode([
                'name' => 'demo',
                'features' => ['database' => true],
                'containers' => ['app' => ['port' => '8531']],
            ])
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    /**
     * @param array<string, string> $containers
     */
    private function makeManager(array $containers = []): MVCAppManager
    {
        $manager = new class ($this->baseDir) extends MVCAppManager {
            /** @var array<string, string> */
            public array $containers = [];

            protected function containerStatesByProject(): array
            {
                return $this->containers === [] ? [] : ['demo' => $this->containers];
            }

            protected function runCommand(string $command): ?string
            {
                return '';
            }
        };

        $manager->containers = $containers;

        return $manager;
    }

    private function install(): void
    {
        file_put_contents($this->baseDir . '/demo-compose.yml', "version: 3.8\n");
    }

    public function testCreatedButNotInstalled(): void
    {
        $row = $this->makeManager()->describeApp('demo');

        $this->assertSame('created', $row['state']);
        $this->assertSame(0, $row['total']);
        $this->assertSame('8531', (string) $row['port']);
    }

    public function testInstalledButNoContainersYet(): void
    {
        $this->install();

        $this->assertSame('installed', $this->makeManager()->describeApp('demo')['state']);
    }

    public function testContainersExistButNoneRunning(): void
    {
        $this->install();

        $row = $this->makeManager(['demo-app' => 'exited', 'demo-db' => 'created'])->describeApp('demo');

        $this->assertSame('stopped', $row['state']);
        $this->assertSame(0, $row['running']);
        $this->assertSame(2, $row['total']);
    }

    public function testPartiallyRunning(): void
    {
        $this->install();

        $row = $this->makeManager(['demo-app' => 'running', 'demo-db' => 'exited'])->describeApp('demo');

        $this->assertSame('partial', $row['state']);
        $this->assertSame(1, $row['running']);
        $this->assertSame(2, $row['total']);
    }

    public function testFullyRunning(): void
    {
        $this->install();

        $row = $this->makeManager(['demo-app' => 'running', 'demo-db' => 'running'])->describeApp('demo');

        $this->assertSame('running', $row['state']);
        $this->assertSame(2, $row['running']);
        $this->assertSame(2, $row['total']);
    }

    public function testUnknownAppHasNoRow(): void
    {
        $this->assertNull($this->makeManager()->describeApp('nope'));
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
