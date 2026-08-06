<?php

declare(strict_types=1);

namespace Test\Nimbus\App;

use Nimbus\App\AppManager;
use PHPUnit\Framework\TestCase;

/**
 * The podman liveness preflight.
 *
 * checkPodmanCompose() only proves the binary exists — podman-compose reports
 * its version happily while the machine is stopped — so nimbus:up used to walk
 * straight into a podman-compose traceback. The decision logic lives in a pure
 * static so the whole signature table is testable without a host.
 */
class PodmanPreflightTest extends TestCase
{
    /** Real `podman machine list --format json` output, machine stopped. */
    private function stoppedMachine(): string
    {
        return <<<'JSON'
            [
                {
                    "Name": "podman-machine-default",
                    "Default": false,
                    "Running": false,
                    "Starting": false,
                    "LastUp": "2026-08-06T02:26:03.787428-04:00",
                    "VMType": "libkrun",
                    "Port": 61768
                }
            ]
            JSON;
    }

    public function testASuccessfulProbeNeedsNoExplanation(): void
    {
        $result = AppManager::interpretPodmanProbe(0, '', true);

        $this->assertTrue($result['running']);
        $this->assertNull($result['error']);
        $this->assertNull($result['fixCommand']);
        $this->assertSame([], $result['hints']);
    }

    /**
     * The case that prompted this: the machine exists, it is simply off. The
     * fix has to name it — podman does not always flag one as Default, so a
     * bare `podman machine start` is not reliably correct.
     */
    public function testAStoppedMachineIsNamedInTheFixCommand(): void
    {
        $result = AppManager::interpretPodmanProbe(125, $this->stoppedMachine(), true);

        $this->assertFalse($result['running']);
        $this->assertStringContainsString('podman-machine-default', (string) $result['error']);
        $this->assertStringContainsString('not running', (string) $result['error']);
        $this->assertSame('podman machine start podman-machine-default', $result['fixCommand']);
    }

    public function testAStartingMachineIsNotRestarted(): void
    {
        $json = '[{"Name":"podman-machine-default","Default":true,"Running":false,"Starting":true}]';

        $result = AppManager::interpretPodmanProbe(125, $json, true);

        $this->assertFalse($result['running']);
        $this->assertStringContainsString('still starting', (string) $result['error']);
        $this->assertNull($result['fixCommand'], 'a machine mid-boot must not be started again');
    }

    public function testNoMachineAtAllOnMacOsSaysInitFirst(): void
    {
        $result = AppManager::interpretPodmanProbe(125, '[]', true);

        $this->assertFalse($result['running']);
        $this->assertStringContainsString('podman machine init', (string) $result['fixCommand']);
        $this->assertStringContainsString('podman machine start', (string) $result['fixCommand']);
    }

    /** Linux has no VM, so machine vocabulary would just mislead. */
    public function testLinuxIsPointedAtTheServiceNotAMachine(): void
    {
        $result = AppManager::interpretPodmanProbe(125, '[]', false);

        $this->assertFalse($result['running']);
        $this->assertNull($result['fixCommand']);
        $this->assertStringNotContainsString('machine', (string) $result['error']);
        $this->assertStringContainsString('podman.socket', implode("\n", $result['hints']));
    }

    /**
     * Machine up but podman still unreachable is a connection problem —
     * offering to start something already running would be a dead end.
     */
    public function testARunningMachineThatStillWillNotAnswerIsAConnectionProblem(): void
    {
        $json = '[{"Name":"podman-machine-default","Default":true,"Running":true,"Starting":false}]';

        $result = AppManager::interpretPodmanProbe(125, $json, true);

        $this->assertFalse($result['running']);
        $this->assertStringContainsString('socket is not reachable', (string) $result['error']);
        $this->assertNull($result['fixCommand']);
        $this->assertStringContainsString('podman system connection list', implode("\n", $result['hints']));
    }

    public function testTheDefaultMachineWinsWhenSeveralExist(): void
    {
        $json = '[{"Name":"scratch","Default":false,"Running":false,"Starting":false},'
            . '{"Name":"primary","Default":true,"Running":false,"Starting":false}]';

        $result = AppManager::interpretPodmanProbe(125, $json, true);

        $this->assertSame('podman machine start primary', $result['fixCommand']);
    }

    public function testUnreadableMachineListStillProducesAdvice(): void
    {
        $result = AppManager::interpretPodmanProbe(125, 'not json at all', true);

        $this->assertFalse($result['running']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('podman machine init', (string) $result['fixCommand']);
    }
}
