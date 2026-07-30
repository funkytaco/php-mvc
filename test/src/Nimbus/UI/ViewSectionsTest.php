<?php

declare(strict_types=1);

namespace Test\Nimbus\UI;

use Nimbus\UI\InteractiveHelper;
use PHPUnit\Framework\TestCase;

/**
 * The credentials and dev-mode sections of `nimbus:view`.
 *
 * Both are report-only, so the properties worth pinning are what they refuse
 * to say: no credential values, and nothing about a code-server that does not
 * exist.
 */
class ViewSectionsTest extends TestCase
{
    private string $baseDir;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_view_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps/demo', 0777, true);
        chdir($this->baseDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->removeDirectory($this->baseDir);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->baseDir . '/.installer/apps/demo/app.nimbus.json',
            json_encode($config + ['name' => 'demo'])
        );
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return preg_replace('/\033\[[0-9;]*m/', '', (string) ob_get_clean()) ?? '';
    }

    /**
     * The bug: an app that never entered dev mode was told its code-server
     * was down and to run nimbus:up, which does not start one.
     */
    public function testNoCodeServerSectionWhenDevModeWasNeverEnabled(): void
    {
        $this->writeConfig(['features' => ['database' => true, 'dev' => false]]);

        $output = $this->capture(fn () => (new InteractiveHelper())->displayDevModeInfo('demo'));

        $this->assertSame('', trim($output));
    }

    public function testCodeServerSectionAppearsOnceDevModeIsEnabled(): void
    {
        $this->writeConfig([
            'features' => ['database' => true, 'dev' => true],
            'containers' => ['codeserver' => ['port' => '11123', 'password' => 'supersecretpw']],
        ]);

        $output = $this->capture(fn () => (new InteractiveHelper())->displayDevModeInfo('demo'));

        $this->assertStringContainsString('code-server', $output);
        $this->assertStringContainsString('http://localhost:11123', $output);

        // Points at the command that actually starts it
        $this->assertStringContainsString('bin/nimbus dev demo', $output);
        $this->assertStringNotContainsString('nimbus:up', $output);

        // and never prints the password itself
        $this->assertStringNotContainsString('supersecretpw', $output);
    }

    public function testCredentialsSectionNamesCommandsButNoValues(): void
    {
        $this->writeConfig([
            'features' => ['database' => true],
            'containers' => ['codeserver' => ['port' => '11123', 'password' => 'supersecretpw']],
        ]);

        $config = json_decode((string) file_get_contents($this->baseDir . '/.installer/apps/demo/app.nimbus.json'), true);
        $output = $this->capture(fn () => (new InteractiveHelper())->displayCredentials('demo', $config));

        $this->assertStringContainsString('code-server password', $output);
        $this->assertStringContainsString('composer nimbus:config demo', $output);
        $this->assertStringNotContainsString('supersecretpw', $output);
    }

    /**
     * Passwords are generated for every service whether or not the app uses
     * it, so a git app that refuses Keycloak must not be offered Keycloak
     * credentials just because the vault holds some.
     */
    public function testDisabledFeaturesAreNotListedAsCredentials(): void
    {
        $this->writeConfig([
            'source' => ['kind' => 'git'],
            'features' => ['database' => false, 'keycloak' => false],
        ]);

        $config = json_decode((string) file_get_contents($this->baseDir . '/.installer/apps/demo/app.nimbus.json'), true);
        $output = $this->capture(fn () => (new InteractiveHelper())->displayCredentials('demo', $config));

        $this->assertStringNotContainsString('keycloak', strtolower($output));
        $this->assertStringNotContainsString('database password', $output);
    }

    public function testNothingIsPrintedWhenThereAreNoCredentials(): void
    {
        $this->writeConfig(['features' => ['database' => false]]);

        $config = json_decode((string) file_get_contents($this->baseDir . '/.installer/apps/demo/app.nimbus.json'), true);
        $output = $this->capture(fn () => (new InteractiveHelper())->displayCredentials('demo', $config));

        $this->assertSame('', trim($output));
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
