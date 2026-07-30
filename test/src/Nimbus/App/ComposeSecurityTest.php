<?php

declare(strict_types=1);

namespace Test\Nimbus\App;

use Nimbus\App\GitAppManager;
use Nimbus\App\MVCAppManager;
use Nimbus\Password\PasswordSet;
use Nimbus\Password\PasswordStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Container-policy invariants for everything Nimbus generates.
 *
 * These are asserted in one place, over both app kinds, so that adding a
 * service later cannot quietly regress the baseline. Nothing here reaches
 * podman: the compose structure is built in memory and inspected.
 *
 * Covers NIST 800-53 IA-5 (authenticator management), IA-9 (service
 * identification), CM-2 (baseline configuration) and the CIS/STIG
 * Docker-Podman container rules that apply to generated artifacts.
 */
class ComposeSecurityTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_sec_' . uniqid();
        mkdir($this->baseDir . '/.installer/apps', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    /**
     * @return array<string, array<string, mixed>> label => compose structure
     */
    private function composeStructures(): array
    {
        return [
            'template app' => $this->buildCompose(new MVCAppManager($this->baseDir), 'demo', [
                'name' => 'demo',
                'features' => ['database' => true],
                'containers' => ['app' => ['port' => '8080']],
            ]),
            'git app' => $this->buildCompose(new GitAppManager($this->baseDir), 'blog', [
                'name' => 'blog',
                'source' => [
                    'kind' => 'git',
                    'runtime' => 'php',
                    'repo' => 'bedrock',
                    'docroot' => 'web',
                    'webroot' => '/var/www/html',
                    'container_port' => 80,
                    'containerfile' => 'Containerfile',
                ],
                'features' => ['database' => true],
                'database' => [
                    'engine' => 'mariadb',
                    'image' => 'mariadb:12',
                    'name' => 'blog_db',
                    'user' => 'blog_user',
                ],
                'containers' => ['app' => ['port' => '8531']],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildCompose(object $manager, string $appName, array $config): array
    {
        $passwords = new PasswordSet(
            databasePassword: 'Kx82hfQm19bTzLw04ynRcVeGpAsDjU7o',
            strategy: PasswordStrategy::GENERATE_NEW,
            baseDir: $this->baseDir,
            appName: $appName,
            databaseRootPassword: 'Rt55vbNq83xZcKl21mwEyHgFpOiJdU6a',
            databaseEngine: $config['database']['engine'] ?? 'postgres'
        );

        $method = new \ReflectionMethod($manager, 'buildComposeConfig');
        $method->setAccessible(true);

        return $method->invoke($manager, $appName, $config, $passwords);
    }

    /**
     * @return array<int, array{service: string, spec: array<string, mixed>, label: string}>
     */
    private function allServices(): array
    {
        $services = [];

        foreach ($this->composeStructures() as $label => $compose) {
            foreach ($compose['services'] ?? [] as $name => $spec) {
                $services[] = ['service' => $name, 'spec' => $spec, 'label' => $label];
            }
        }

        $this->assertNotEmpty($services);

        return $services;
    }

    public function testNoServiceRunsPrivileged(): void
    {
        foreach ($this->allServices() as $entry) {
            $this->assertArrayNotHasKey(
                'privileged',
                $entry['spec'],
                "{$entry['label']}: {$entry['service']} must not be privileged"
            );
            $this->assertArrayNotHasKey('cap_add', $entry['spec'], "{$entry['label']}: {$entry['service']}");
        }
    }

    public function testNoServiceSharesTheHostNetworkOrPidNamespace(): void
    {
        foreach ($this->allServices() as $entry) {
            foreach (['network_mode', 'pid', 'ipc', 'userns_mode'] as $key) {
                $value = $entry['spec'][$key] ?? null;
                $this->assertNotSame('host', $value, "{$entry['label']}: {$entry['service']} $key");
            }
        }
    }

    /**
     * A container with the runtime socket is a root escalation path off the
     * host — the single most consequential thing to get wrong here.
     */
    public function testNoServiceMountsTheContainerRuntimeSocket(): void
    {
        foreach ($this->allServices() as $entry) {
            foreach ($entry['spec']['volumes'] ?? [] as $volume) {
                foreach (['docker.sock', 'podman.sock', '/run/podman', '/var/run/docker'] as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        (string) $volume,
                        "{$entry['label']}: {$entry['service']} must not mount the container runtime socket"
                    );
                }
            }
        }
    }

    /**
     * Bind mounts need an SELinux relabel to be readable by the container on
     * an enforcing host; named volumes do not.
     */
    public function testEveryBindMountCarriesAnSelinuxLabel(): void
    {
        foreach ($this->allServices() as $entry) {
            foreach ($entry['spec']['volumes'] ?? [] as $volume) {
                $volume = (string) $volume;

                if (!str_starts_with($volume, '.') && !str_starts_with($volume, '/')) {
                    continue;  // named volume
                }

                $this->assertMatchesRegularExpression(
                    '/:(Z|z|ro|rw,Z|ro,Z)$/',
                    $volume,
                    "{$entry['label']}: {$entry['service']} bind mount '$volume' has no SELinux label"
                );
            }
        }
    }

    public function testDatabaseServicesDeclareAHealthcheck(): void
    {
        foreach ($this->composeStructures() as $label => $compose) {
            foreach ($compose['services'] as $name => $spec) {
                if (!str_ends_with((string) $name, '-db')) {
                    continue;
                }

                $this->assertArrayHasKey('healthcheck', $spec, "$label: $name");
                $this->assertNotEmpty($spec['healthcheck']['test'], "$label: $name");
            }
        }
    }

    /**
     * Build arguments are recorded in image metadata and readable by anyone
     * who can pull the image, so a credential must never travel that way.
     */
    public function testNoCredentialsArePassedAsBuildArguments(): void
    {
        foreach ($this->allServices() as $entry) {
            foreach ($entry['spec']['build']['args'] ?? [] as $key => $value) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(^|_)(KEY|SECRET|SALT|PASSWORD|TOKEN)(_|$)/i',
                    (string) $key,
                    "{$entry['label']}: {$entry['service']} build arg '$key' looks like a credential"
                );
            }
        }
    }

    /**
     * Nothing generated may ship a guessable credential (NIST 800-53 IA-5,
     * CIS/STIG default-account policy).
     */
    public function testNoWellKnownDefaultCredentials(): void
    {
        $banned = ['password', 'changeme', 'admin', 'secret', 'root', 'test', '123456', ''];

        foreach ($this->allServices() as $entry) {
            foreach ($entry['spec']['environment'] ?? [] as $key => $value) {
                if (preg_match('/(^|_)(KEY|SECRET|SALT|PASSWORD|TOKEN)(_|$)/i', (string) $key) !== 1) {
                    continue;
                }

                $this->assertNotContains(
                    strtolower((string) $value),
                    $banned,
                    "{$entry['label']}: {$entry['service']} $key is a well-known default"
                );
                $this->assertGreaterThanOrEqual(
                    16,
                    strlen((string) $value),
                    "{$entry['label']}: {$entry['service']} $key is too short"
                );
            }
        }
    }

    /**
     * Every image reference is pinned, so the recorded baseline actually
     * describes what runs (NIST 800-53 CM-2).
     */
    public function testEveryImageReferenceIsPinned(): void
    {
        $unpinned = [];

        foreach ($this->allServices() as $entry) {
            $image = $entry['spec']['image'] ?? null;

            if ($image === null) {
                continue;  // built from a Containerfile instead
            }

            $image = (string) $image;

            if (str_contains($image, ':latest') || preg_match('/:[^\/:]+$|@sha256:/', $image) !== 1) {
                $unpinned[] = "{$entry['service']} ($image)";
            }
        }

        $this->assertSame(
            [],
            $unpinned,
            'every image in a generated stack must be pinned (NIST 800-53 CM-2)'
        );
    }

    /**
     * The dev overlay adds a code-server sidecar, which Nimbus has always
     * shipped on :latest. That is a real CM-2 gap, so it is asserted as a
     * *known* exception rather than skipped silently — this fails the moment
     * it is fixed (tighten the expectation) or joined by another unpinned
     * image (fix that one).
     */
    public function testDevOverlayImagesAreTrackedForPinning(): void
    {
        $manager = new GitAppManager($this->baseDir);
        $method = new \ReflectionMethod($manager, 'buildDevOverlay');
        $method->setAccessible(true);

        $overlay = $method->invoke($manager, 'blog', [
            'name' => 'blog',
            'source' => ['kind' => 'git', 'runtime' => 'php', 'repo' => 'bedrock'],
            'features' => ['database' => false],
            'containers' => ['app' => ['port' => '8531'], 'codeserver' => ['port' => '11435', 'password' => 'x']],
        ]);

        $unpinned = [];
        foreach ($overlay['services'] as $name => $spec) {
            $image = (string) ($spec['image'] ?? '');

            if ($image !== '' && str_contains($image, ':latest')) {
                $unpinned[] = $name;
            }
        }

        $this->assertSame(['blog-code-server'], $unpinned);
    }

    /**
     * The generated compose file carries database passwords in the clear, so
     * it is an authenticator store (NIST 800-53 IA-5).
     */
    public function testGeneratedComposeFileIsOwnerReadableOnly(): void
    {
        $appDir = $this->baseDir . '/.installer/apps/demo';
        mkdir($appDir, 0777, true);
        file_put_contents($appDir . '/app.nimbus.json', json_encode([
            'name' => 'demo',
            'features' => ['database' => true],
            'containers' => ['app' => ['port' => '8080']],
        ]));

        $manager = new MVCAppManager($this->baseDir);
        $method = new \ReflectionMethod($manager, 'generatePodmanCompose');
        $method->setAccessible(true);
        $method->invoke($manager, 'demo', ['features' => ['database' => true], 'containers' => ['app' => ['port' => '8080']]]);

        $file = $this->baseDir . '/demo-compose.yml';

        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
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
