<?php

declare(strict_types=1);

namespace Test\Nimbus\Tasks;

use Nimbus\Tasks\DevTask;
use PHPUnit\Framework\TestCase;

/**
 * The code-server password grants a browser shell over the app's source, so
 * dev-mode output names the command that reveals it rather than the value.
 *
 * It also has to name the *right* command: once the password moved into the
 * vault, output that still read app.nimbus.json rendered it as "password: ?".
 */
class DevTaskTest extends TestCase
{
    public function testAppsWithAVaultArePointedAtVaultView(): void
    {
        $config = [
            'source' => ['kind' => 'git'],
            'features' => ['dev' => true],
            'containers' => ['codeserver' => ['port' => '10805']],
        ];

        $this->assertSame(
            'composer nimbus:vault-view foolio',
            DevTask::codeServerPasswordCommand('foolio', $config)
        );
    }

    public function testAppsKeepingItInConfigArePointedAtConfig(): void
    {
        $config = [
            'features' => ['dev' => true],
            'containers' => ['codeserver' => ['port' => '10805', 'password' => 'plaintextpw']],
        ];

        $this->assertSame(
            'composer nimbus:config demo',
            DevTask::codeServerPasswordCommand('demo', $config)
        );
    }

    /**
     * Whatever it returns, it is never the credential itself.
     */
    public function testTheCommandNeverContainsThePassword(): void
    {
        $config = ['containers' => ['codeserver' => ['port' => '1', 'password' => 'supersecretpw']]];

        $this->assertStringNotContainsString(
            'supersecretpw',
            DevTask::codeServerPasswordCommand('demo', $config)
        );
    }
}
