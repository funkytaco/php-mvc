<?php

declare(strict_types=1);

namespace Test\Nimbus\Tasks;

use Nimbus\Tasks\ScanTask;
use PHPUnit\Framework\TestCase;

/**
 * The scanner is an ephemeral tool, not part of any app. These tests pin both
 * halves of that: the container it runs is hardened and disposable, and
 * nothing about the app changes because it ran.
 */
class ScanTaskTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_scan_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps/blog', 0777, true);
        mkdir($this->baseDir . '/.installer/repos/bedrock', 0777, true);

        file_put_contents(
            $this->baseDir . '/.installer/apps/blog/app.nimbus.json',
            json_encode([
                'name' => 'blog',
                'source' => ['kind' => 'git', 'repo' => 'bedrock'],
                'features' => ['database' => true],
                'database' => ['engine' => 'mariadb', 'image' => 'mariadb:12'],
            ])
        );
        file_put_contents($this->baseDir . '/blog-compose.yml', "version: 3.8\n");
        file_put_contents($this->baseDir . '/.installer/repos/bedrock/Containerfile', "FROM php:8.3-apache\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    /**
     * @param array<int, string> $commands captures every shell-out
     */
    private function makeTask(array &$commands, bool $imagePresent = true): ScanTask
    {
        $task = new class ($this->baseDir) extends ScanTask {
            /** @var array<int, string> */
            public array $commands = [];
            public bool $imagePresent = true;

            protected function runCommand(string $command): ?string
            {
                $this->commands[] = $command;

                if (str_contains($command, 'podman images')) {
                    return $this->imagePresent ? 'sha256:abc123' : '';
                }

                return 'Report Summary: no misconfigurations found';
            }
        };

        $task->imagePresent = $imagePresent;
        $commands = &$task->commands;

        return $task;
    }

    private function scanCommand(ScanTask $task): string
    {
        ob_start();
        $task->scanApp('blog');
        ob_get_clean();

        foreach ($task->commands as $command) {
            if (str_contains($command, 'podman run')) {
                return $command;
            }
        }

        $this->fail('no podman run command was issued');
    }

    public function testScannerContainerIsNamedForTheAppAndRemovesItself(): void
    {
        $commands = [];
        $command = $this->scanCommand($this->makeTask($commands));

        $this->assertStringContainsString("'blog-scanner-tool'", $command);
        $this->assertStringContainsString('--rm', $command);
        $this->assertSame('-scanner-tool', ScanTask::CONTAINER_SUFFIX);
    }

    /**
     * A scanner holding the container runtime socket is a root escalation
     * path — and mounting it would break the very rules it checks for.
     */
    public function testScannerNeverMountsTheContainerRuntimeSocket(): void
    {
        $commands = [];
        $command = $this->scanCommand($this->makeTask($commands));

        foreach (['docker.sock', 'podman.sock', '/run/podman'] as $needle) {
            $this->assertStringNotContainsString($needle, $command);
        }
    }

    public function testScannerRunsWithoutNetworkCapabilitiesOrPrivilegeEscalation(): void
    {
        $commands = [];
        $command = $this->scanCommand($this->makeTask($commands));

        $this->assertStringContainsString('--network=none', $command);
        $this->assertStringContainsString('--cap-drop=ALL', $command);
        $this->assertStringContainsString('--security-opt=no-new-privileges', $command);
        $this->assertStringNotContainsString('--privileged', $command);
    }

    public function testScannerMountsWhatItInspectsReadOnly(): void
    {
        $commands = [];
        $command = $this->scanCommand($this->makeTask($commands));

        $this->assertStringContainsString('.installer/repos/bedrock:/scan/context:ro', $command);
        $this->assertStringContainsString('blog-compose.yml:/scan/compose:ro', $command);

        // No writable mount anywhere
        $this->assertDoesNotMatchRegularExpression('/-v [^ ]+:rw/', $command);
    }

    public function testScannerImageIsPinned(): void
    {
        $commands = [];
        $command = $this->scanCommand($this->makeTask($commands));

        $this->assertStringContainsString('aquasec/trivy:0.58.2', $command);
        $this->assertStringNotContainsString('trivy:latest', $command);
    }

    /**
     * A missing scanner image must never be allowed to slow down or break
     * `nimbus:up` — it degrades to a hint instead.
     */
    public function testMissingScannerImageDegradesToAHintAndRunsNothing(): void
    {
        $commands = [];
        $task = $this->makeTask($commands, false);

        ob_start();
        $result = $task->scanApp('blog');
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('podman pull', $output);

        foreach ($task->commands as $command) {
            $this->assertStringNotContainsString('podman run', $command);
        }
    }

    public function testQuietModeSaysNothingWhenTheScannerIsUnavailable(): void
    {
        $commands = [];

        ob_start();
        $this->makeTask($commands, false)->scanApp('blog', true);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    /**
     * The scanner is not part of the app: running it must leave no trace in
     * the app's config, its compose file, or the registry.
     */
    public function testScanningLeavesTheAppUntouched(): void
    {
        $configFile = $this->baseDir . '/.installer/apps/blog/app.nimbus.json';
        $composeFile = $this->baseDir . '/blog-compose.yml';

        $configBefore = file_get_contents($configFile);
        $composeBefore = file_get_contents($composeFile);

        $commands = [];
        ob_start();
        $this->makeTask($commands)->scanApp('blog');
        ob_get_clean();

        $this->assertSame($configBefore, file_get_contents($configFile));
        $this->assertSame($composeBefore, file_get_contents($composeFile));
        $this->assertStringNotContainsString('scanner', $configBefore);
        $this->assertStringNotContainsString('scanner', $composeBefore);
        $this->assertFileDoesNotExist($this->baseDir . '/.installer/apps.json');
    }

    public function testUnknownAppIsReportedRatherThanScanned(): void
    {
        $commands = [];
        $task = $this->makeTask($commands);

        ob_start();
        $result = $task->scanApp('nope');
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('not found', $output);
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
