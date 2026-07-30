<?php

declare(strict_types=1);

namespace Test\Nimbus\Tasks;

use Nimbus\Tasks\InstallTask;
use PHPUnit\Framework\TestCase;

/**
 * `nimbus:list` output: the table itself, plus the pointer at the verbose
 * view and the app-name filter.
 */
class ListTaskTest extends TestCase
{
    private string $baseDir;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_list_' . uniqid();

        mkdir($this->baseDir . '/.installer/apps/foolio', 0777, true);
        mkdir($this->baseDir . '/.installer/apps/shop', 0777, true);

        file_put_contents($this->baseDir . '/.installer/apps.json', json_encode(['apps' => [
            'foolio' => ['name' => 'foolio', 'template' => 'git', 'created' => 'now'],
            'shop' => ['name' => 'shop', 'template' => 'lkui', 'created' => 'now'],
        ]]));

        foreach (['foolio', 'shop'] as $name) {
            file_put_contents(
                $this->baseDir . '/.installer/apps/' . $name . '/app.nimbus.json',
                json_encode(['name' => $name, 'containers' => ['app' => ['port' => '8046']]])
            );
        }

        chdir($this->baseDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->removeDirectory($this->baseDir);
    }

    private function runList(?string $filter = null): string
    {
        ob_start();

        try {
            (new InstallTask())->listApps($filter);
        } finally {
            $output = (string) ob_get_clean();
        }

        return preg_replace('/\033\[[0-9;]*m/', '', $output) ?? '';
    }

    public function testListPointsAtTheVerboseViewWithARealAppName(): void
    {
        $output = $this->runList();

        $this->assertStringContainsString('foolio', $output);
        $this->assertStringContainsString('shop', $output);
        $this->assertStringContainsString('composer nimbus:view foolio', $output);
    }

    public function testAnAppNameFiltersTheListRatherThanBeingIgnored(): void
    {
        $output = $this->runList('shop');

        $this->assertStringContainsString('shop', $output);
        $this->assertStringNotContainsString('foolio', $output);
        $this->assertStringContainsString('composer nimbus:view shop', $output);
    }

    public function testUnknownAppNameIsReported(): void
    {
        $output = $this->runList('nope');

        $this->assertStringContainsString("App 'nope' not found", $output);
        $this->assertStringNotContainsString('nimbus:view nope', $output);
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
