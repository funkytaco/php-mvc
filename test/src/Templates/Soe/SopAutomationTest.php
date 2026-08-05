<?php

declare(strict_types=1);

namespace Test\Templates\Soe;

use PHPUnit\Framework\TestCase;

/**
 * SOP rulebook automation: the run lifecycle, the callback token, and the
 * Golden Rule 1 boundary on what shipped playbooks may do.
 */
final class SopAutomationTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../.installer/_templates/soe';

    public static function setUpBeforeClass(): void
    {
        // Load every App\ class this test touches straight from the TEMPLATE.
        // Composer maps App\ to the shared root app/ directory, which holds
        // whichever app was installed last — so without these explicit
        // requires the autoloader can quietly hand us a stale copy of the
        // very code under test. testLoadedClassesComeFromTheTemplate() proves
        // it worked.
        require_once self::TEMPLATE . '/Persistence/AuditLog.php';
        require_once self::TEMPLATE . '/Persistence/SopRunRepository.php';
        require_once self::TEMPLATE . '/Services/SopRunnerService.php';
    }

    /**
     * Guards against the shared-app/ hazard above: if PSR-4 resolved any of
     * these to the installed copy instead of the template, every other
     * assertion in this file would be testing the wrong file.
     */
    public function testLoadedClassesComeFromTheTemplate(): void
    {
        $classes = [
            \App\Persistence\AuditLog::class,
            \App\Persistence\SopRunRepository::class,
            \App\Services\SopRunnerService::class,
        ];

        foreach ($classes as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $this->assertStringStartsWith(
                realpath(self::TEMPLATE) ?: self::TEMPLATE,
                (string) $file,
                "$class was loaded from $file, not the soe template. The shared app/ "
                . 'directory shadowed it.'
            );
        }
    }

    /**
     * GOLDEN RULE 1 — "track, don't touch".
     *
     * The shipped playbook must not perform infrastructure work. If someone
     * swaps the demo body for a real one, this fails and forces the spec
     * conversation (amend AGENTS.md Rule 1 and SPEC-DD NG1) rather than
     * letting it land quietly.
     */
    public function testShippedPlaybookTouchesNoInfrastructure(): void
    {
        $playbook = (string) file_get_contents(self::TEMPLATE . '/playbooks/sop-demo.yml');

        $mutatingModules = [
            'community.vmware', 'vmware_guest',      // provisioning
            'ansible.windows', 'win_domain',          // AD join
            'community.crypto.openssl',               // cert issuance
            'ansible.posix.firewalld', 'iptables',    // firewall changes
            'ansible.builtin.package', 'yum:', 'apt:', 'dnf:',
            'ansible.builtin.service',
            'ansible.builtin.command', 'ansible.builtin.shell',
        ];

        foreach ($mutatingModules as $module) {
            $this->assertStringNotContainsStringIgnoringCase(
                $module,
                $playbook,
                "Golden Rule 1: playbooks/sop-demo.yml uses `$module`, which changes or "
                . 'executes against infrastructure. That is a spec decision — amend '
                . 'AGENTS.md Golden Rule 1 and SPEC-DD NG1 first.'
            );
        }

        // It must still do the one thing it exists to do.
        $this->assertStringContainsString('callback_url', $playbook);
        $this->assertStringContainsString('callback_token', $playbook);
    }

    /** The rulebook must only react to the app's own event, on the EDA webhook port. */
    public function testRulebookListensForTheSopRunEventOnly(): void
    {
        $rulebook = (string) file_get_contents(self::TEMPLATE . '/rulebooks/sop-rules.yml');

        $this->assertStringContainsString('event.payload.event == "sop.run"', $rulebook);
        $this->assertStringContainsString('port: 5000', $rulebook);
        $this->assertStringContainsString('/playbooks/sop-demo.yml', $rulebook);
    }

    /**
     * The entrypoint must guard every rulebook launch with a file test.
     * An unguarded launch of a missing rulebook spams errors for the life of
     * the container — which is what the inherited order-entry version did
     * after its demo rulebook was removed.
     */
    public function testEntrypointGuardsEveryRulebookLaunch(): void
    {
        $entrypoint = (string) file_get_contents(self::TEMPLATE . '/init-entrypoint.sh');

        preg_match_all('/ansible-rulebook --rulebook (\S+)/', $entrypoint, $matches);
        $this->assertNotEmpty($matches[1], 'The entrypoint launches no rulebooks at all.');

        foreach ($matches[1] as $path) {
            $this->assertStringContainsString(
                '[ -f ' . $path . ' ]',
                $entrypoint,
                "init-entrypoint.sh launches $path without a `[ -f ... ]` guard."
            );
        }

        // The order-entry demo rulebook targeted /api/items, which SOE does not have.
        $this->assertStringNotContainsString('demo-rules.yml', $entrypoint);
    }

    /** Only allow-listed rulebooks may be bound to a step. */
    public function testOnlyKnownRulebooksCanBeBound(): void
    {
        $runner = $this->runner($this->repoStub());

        $this->assertTrue($runner->isKnownRulebook('sop-demo.yml'));
        $this->assertFalse($runner->isKnownRulebook('../../etc/passwd'));
        $this->assertFalse($runner->isKnownRulebook('anything-else.yml'));
        $this->assertFalse($runner->bind('virt', 0, 'anything-else.yml', 'teamlead'));
    }

    /** Running with EDA absent must fail cleanly and say how to fix it. */
    public function testRunWithoutEdaExplainsItself(): void
    {
        $runner = $this->runner($this->repoStub(), edaEnabled: false);
        $result = $runner->run('virt', 2, 'ORD-1042', 'teamlead');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['run_id']);
        $this->assertStringContainsString('nimbus:add-eda', $result['message']);
    }

    /** A step with no bound rulebook cannot be run. */
    public function testRunWithoutBindingIsRejected(): void
    {
        $repo = $this->repoStub();          // binding() returns null by default
        $result = $this->runner($repo)->run('virt', 0, 'ORD-1042', 'teamlead');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No rulebook', $result['message']);
    }

    /**
     * The completion callback is authenticated by the per-run token, because
     * it arrives from EDA with no browser session. A wrong token must change
     * nothing.
     */
    public function testCallbackRejectsAWrongToken(): void
    {
        $repo = $this->repoStub();
        $repo->run = [
            'id' => 7, 'team' => 'virt', 'step_index' => 2, 'order_ref' => 'ORD-1042',
            'rulebook' => 'sop-demo.yml', 'status' => 'running', 'callback_token' => 'correct-token',
        ];

        $runner = $this->runner($repo);

        $bad = $runner->complete(7, 'wrong-token', 'completed', 'x');
        $this->assertFalse($bad['ok']);
        $this->assertSame('Invalid callback token.', $bad['message']);
        $this->assertNull($repo->finishedStatus, 'A bad token must not close the run.');

        $good = $runner->complete(7, 'correct-token', 'completed', 'done');
        $this->assertTrue($good['ok']);
        $this->assertSame('completed', $repo->finishedStatus);
    }

    /** An unknown run and a bad token answer alike, so ids cannot be enumerated. */
    public function testUnknownRunIsRejected(): void
    {
        $repo = $this->repoStub();
        $repo->run = null;

        $result = $this->runner($repo)->complete(999, 'anything', 'completed', null);
        $this->assertFalse($result['ok']);
    }

    /** Any status other than `completed` is recorded as a failure, never invented. */
    public function testNonCompletedStatusBecomesFailed(): void
    {
        $repo = $this->repoStub();
        $repo->run = ['id' => 1, 'team' => 'virt', 'step_index' => 0, 'order_ref' => 'ORD-1',
                      'rulebook' => 'sop-demo.yml', 'status' => 'running', 'callback_token' => 't'];

        $this->runner($repo)->complete(1, 't', 'something-weird', null);
        $this->assertSame('failed', $repo->finishedStatus);
    }

    // ---- doubles ----------------------------------------------------------

    private function runner(object $repo, bool $edaEnabled = true): \App\Services\SopRunnerService
    {
        // AuditLog is only ever written to here, so a do-nothing double suffices.
        // Constructors are bypassed, so neither double needs a PDO.
        $audit = $this->getMockBuilder(\App\Persistence\AuditLog::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new \App\Services\SopRunnerService($repo, $audit, 'soe-demo', $edaEnabled);
    }

    /** A SopRunRepository double that records what the service asked it to do. */
    private function repoStub(): object
    {
        return new class extends \App\Persistence\SopRunRepository {
            /** @var array<string,mixed>|null */
            public ?array $run = null;
            public ?string $finishedStatus = null;
            /** @var array<int,array<int,mixed>> */
            public array $bound = [];

            // Deliberately no parent::__construct() — there is no database here.
            public function __construct()
            {
            }

            public function binding(string $team, int $stepIndex): ?string
            {
                return null;
            }

            public function bind(string $team, int $stepIndex, string $rulebook, string $actor): void
            {
                $this->bound[] = [$team, $stepIndex, $rulebook, $actor];
            }

            public function unbind(string $team, int $stepIndex): void
            {
            }

            public function startRun(string $team, int $stepIndex, string $orderRef, string $rulebook, string $actor, string $token): int
            {
                return 1;
            }

            public function markRunning(int $runId): void
            {
            }

            public function finishRun(int $runId, string $status, ?string $result): bool
            {
                $this->finishedStatus = $status;

                return true;
            }

            /** @return array<string,mixed>|null */
            public function findRun(int $runId): ?array
            {
                return $this->run;
            }
        };
    }
}
