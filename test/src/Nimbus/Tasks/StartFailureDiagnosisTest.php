<?php

declare(strict_types=1);

namespace Test\Nimbus\Tasks;

use Nimbus\Tasks\ContainerTask;
use PHPUnit\Framework\TestCase;

/**
 * nimbus:up failure diagnosis.
 *
 * The trap being covered: podman-compose does not stop after a failed build,
 * so the log ends with podman being denied a pull of the app's own image from
 * docker.io — which reads as "registry access is blocked" when the actual
 * cause is at the top of the log. The diagnosis must lead with the cause and
 * only blame the registry when an image the stack genuinely pulls is refused.
 */
class StartFailureDiagnosisTest extends TestCase
{
    /** Condensed from a real failed `nimbus:up budweiser` (gitea clone). */
    private function buildFailureCascade(): string
    {
        return <<<'LOG'
            [1/3] STEP 1/7: FROM --platform=linux/arm64/v8 docker.io/library/golang:1.26-alpine3.24 AS frontend-build
            Trying to pull docker.io/library/golang:1.26-alpine3.24...
            Writing manifest to image destination
            [1/3] STEP 6/7: COPY --exclude=.git/ . .
            Error: building at STEP "COPY --exclude=.git/ . .": COPY --excludes is not supported
            exit code: 125
            podman run --name=budweiser-app -d --net budweiser_budweiser-net budweiser_budweiser-app
            Resolving "budweiser_budweiser-app" using unqualified-search registries
            Trying to pull docker.io/library/budweiser_budweiser-app:latest...
            Error: initializing source docker://budweiser_budweiser-app:latest: reading manifest latest in docker.io/library/budweiser_budweiser-app: requested access to the resource is denied
            exit code: 125
            LOG;
    }

    public function testBuildFailureLeadsAndTheRegistryDenialIsExplainedAsASymptom(): void
    {
        $diagnosis = ContainerTask::diagnoseStartFailure('budweiser', $this->buildFailureCascade());
        $text = implode("\n", $diagnosis);

        // Leads with the cause
        $this->assertStringContainsString('build failed at: COPY --exclude=.git/ . .', $diagnosis[0]);
        $this->assertStringContainsString('does not support `COPY --exclude`', $text);

        // Explains the trailing registry error instead of blaming the registry
        $this->assertStringContainsString('symptom', $text);
        $this->assertStringNotContainsString('registry refused access while pulling an image the stack needs', $text);
    }

    public function testGenuineRegistryDenialOnABaseImageIsCalledOut(): void
    {
        $log = <<<'LOG'
            [1/1] STEP 1/5: FROM docker.io/acme/private-base:1.2
            Trying to pull docker.io/acme/private-base:1.2...
            Error: initializing source docker://acme/private-base:1.2: reading manifest 1.2 in docker.io/acme/private-base: requested access to the resource is denied
            exit code: 125
            LOG;

        $text = implode("\n", ContainerTask::diagnoseStartFailure('shop', $log));

        $this->assertStringContainsString('registry refused access', $text);
        $this->assertStringContainsString('podman login', $text);
    }

    public function testUnreachableRegistryIsReportedAsANetworkProblem(): void
    {
        $log = 'Trying to pull docker.io/library/mariadb:12...' . "\n"
            . 'Error: initializing source docker://mariadb:12: pinging container registry registry-1.docker.io: '
            . 'Get "https://registry-1.docker.io/v2/": dial tcp 3.94.224.37:443: i/o timeout';

        $text = implode("\n", ContainerTask::diagnoseStartFailure('shop', $log));

        $this->assertStringContainsString('could not be reached', $text);
        $this->assertStringContainsString('network, VPN or proxy', $text);
    }

    /**
     * The app image being pulled with NO build failure above it still means
     * something is wrong — the image is only ever built locally.
     */
    public function testAppImagePullWithoutABuildFailureIsStillExplained(): void
    {
        $log = 'Trying to pull docker.io/library/shop_shop-app:latest...' . "\n"
            . 'Error: reading manifest latest in docker.io/library/shop_shop-app: requested access to the resource is denied';

        $text = implode("\n", ContainerTask::diagnoseStartFailure('shop', $log));

        $this->assertStringContainsString('only ever built locally', $text);
        $this->assertStringNotContainsString('registry refused access while pulling an image the stack needs', $text);
    }

    public function testCleanOutputYieldsNoDiagnosis(): void
    {
        $log = "STEP 1/2: FROM php:8.3-apache\nCOMMIT shop_shop-app\nexit code: 0";

        $this->assertSame([], ContainerTask::diagnoseStartFailure('shop', $log));
    }
}
