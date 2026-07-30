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

    /**
     * @param array<string, mixed> $config
     */
    private function commandsOutput(array $config): string
    {
        $this->writeConfig($config);
        $stored = json_decode((string) file_get_contents($this->baseDir . '/.installer/apps/demo/app.nimbus.json'), true);

        return $this->capture(fn () => (new InteractiveHelper())->displayAppCommands('demo', $stored));
    }

    /**
     * Scanning runs a container and produces a report — an action, so it
     * belongs among the steps rather than in the read-only Inspect list.
     */
    public function testScanIsOfferedAsAnOptionalStepNotAsIntrospection(): void
    {
        $output = $this->commandsOutput(['source' => ['kind' => 'git'], 'features' => ['database' => true]]);

        [$inspect, $steps] = explode('Next steps:', $output, 2);

        $this->assertStringNotContainsString('nimbus:scan', $inspect);
        $this->assertStringContainsString('composer nimbus:scan demo', $steps);
        $this->assertStringContainsString('security scan', $steps);
    }

    public function testDevModeIsOfferedAsAddingACodeServerUntilItExists(): void
    {
        $before = $this->commandsOutput(['source' => ['kind' => 'git'], 'features' => ['database' => true, 'dev' => false]]);
        $this->assertStringContainsString('adds a code-server', $before);

        $after = $this->commandsOutput(['source' => ['kind' => 'git'], 'features' => ['database' => true, 'dev' => true]]);
        $this->assertStringContainsString('code-server editor is part of this stack', $after);
    }

    /**
     * Optional steps must never take the "run this next" marker from a
     * required one.
     */
    public function testOptionalStepsDoNotClaimTheNextAction(): void
    {
        $output = $this->commandsOutput(['source' => ['kind' => 'git'], 'features' => ['database' => true]]);

        $steps = explode('Next steps:', $output, 2)[1];
        $lines = array_values(array_filter(explode("\n", $steps)));

        // install is still the required next action, ahead of dev and scan
        $this->assertStringContainsString('nimbus:install demo', $lines[0]);
        $this->assertStringContainsString('← run this next', $lines[0]);
        $this->assertSame(1, substr_count($steps, '← run this next'));
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
