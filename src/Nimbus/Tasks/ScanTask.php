<?php

declare(strict_types=1);

namespace Nimbus\Tasks;

use Composer\Script\Event;
use Nimbus\App\AppManagerFactory;
use Nimbus\Core\BaseTask;

/**
 * Scan an app's generated stack for container-policy violations.
 *
 * The scanner is deliberately **not part of the app**. It is never written
 * into app.nimbus.json, never added to a compose file, and never registered in
 * apps.json — it runs as a one-shot `podman run --rm` container named
 * `<app>-scanner-tool` and is gone the moment it exits. Nothing about the app
 * changes if it is never run, and removing it later means deleting this file.
 *
 * It reports; it does not fix. That matches the other Nimbus check commands
 * (template-check, lint-check), and keeps a scanner from silently rewriting a
 * user's repository.
 *
 * The scanner container is held to the same policy it enforces: no container
 * runtime socket is mounted (a scanner with the podman socket is a root
 * escalation path, and mounting it would violate the very CIS/STIG rule set
 * being checked), all capabilities dropped, no privilege escalation, no
 * network for the config scan, and read-only mounts of what it inspects.
 */
class ScanTask extends BaseTask
{
    /**
     * Ephemeral by construction: `--rm` removes it on exit, and the name is
     * outside the suffix set AppManager reserves for app containers, so it can
     * never be mistaken for part of the app.
     */
    public const CONTAINER_SUFFIX = '-scanner-tool';

    /**
     * Pinned, like every other image Nimbus generates (NIST 800-53 CM-2).
     * Trivy is Apache-2.0 and ships the CIS/STIG-aligned misconfiguration
     * policies used by `trivy config`.
     */
    private const SCANNER_IMAGE = 'docker.io/aquasec/trivy:0.58.2';

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? (getcwd() ?: '.');
    }

    public function execute(Event $event): void
    {
        $this->scan($event);
    }

    public function scan(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $appName = $args[0] ?? null;

        if (!$appName) {
            $manager = new \Nimbus\App\MVCAppManager($this->baseDir);
            $apps = array_keys($manager->listApps());

            if ($apps === []) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first.');
                return;
            }

            $appName = $io->select('Which app should be scanned? ', $apps, 0);
            $appName = $apps[$appName] ?? $apps[0];
        }

        $this->scanApp((string) $appName);
    }

    /**
     * Run the scan. Returns false when it could not run at all, which is never
     * treated as a failure of the app itself — a missing scanner must not stop
     * anyone from starting their stack.
     */
    public function scanApp(string $appName, bool $quiet = false): bool
    {
        $baseDir = $this->baseDir;

        $manager = AppManagerFactory::forApp($appName, $baseDir);

        try {
            $config = $manager->loadAppConfig($appName);
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', "App '$appName' not found.");
            return false;
        }

        $targets = $this->scanTargets($baseDir, $appName, $config);

        if ($targets === []) {
            echo self::ansiFormat('WARNING', "Nothing to scan for '$appName' yet — run nimbus:install first.");
            return false;
        }

        if (!$this->scannerImagePresent()) {
            if (!$quiet) {
                echo self::ansiFormat(
                    'INFO',
                    '🛡️  Security scan skipped: ' . self::SCANNER_IMAGE . ' is not pulled yet.'
                );
                echo '   Pull it once with: podman pull ' . self::SCANNER_IMAGE . PHP_EOL;
                echo "   Then: composer nimbus:scan $appName" . PHP_EOL;
            }

            return false;
        }

        echo self::ansiFormat('INFO', "🛡️  Scanning '$appName' for container policy issues...");

        $output = $this->runCommand($this->buildScanCommand($appName, $targets));

        if ($output === null || trim($output) === '') {
            echo self::ansiFormat('WARNING', 'Scanner produced no output.');
            return false;
        }

        // The scanner failing is not a scan result. Without this, trivy's own
        // usage dump and FATAL line were followed by our success footer.
        if (preg_match('/^\s*(?:\S+\s+)?FATAL\b/m', $output) === 1) {
            $fatal = null;
            foreach (explode("\n", $output) as $line) {
                if (str_contains($line, 'FATAL')) {
                    $fatal = trim($line);
                }
            }

            echo self::ansiFormat('ERROR', 'The scanner itself failed — no scan was performed.');
            echo '  ' . ($fatal ?? 'See full output below.') . PHP_EOL;
            echo self::ansiFormat('INFO', 'Nothing about the app was checked or changed.');

            return false;
        }

        echo $output . PHP_EOL;
        echo self::ansiFormat('INFO', 'Findings are advisory — Nimbus does not modify your repository.');
        echo self::ansiFormat('INFO', "The scanner container ($appName" . self::CONTAINER_SUFFIX . ') has already been removed.');

        return true;
    }

    /**
     * Host paths worth inspecting: the build context (which holds the
     * Containerfile) and the generated compose file.
     *
     * @param array<string, mixed> $config
     * @return array<string, string> mount label => host path
     */
    protected function scanTargets(string $baseDir, string $appName, array $config): array
    {
        $targets = [];

        $source = $config['source'] ?? [];
        if (($source['kind'] ?? null) === 'git' && !empty($source['repo'])) {
            // A clone holds only upstream code — Nimbus never writes secrets
            // into it — so the whole tree is safe to hand the scanner.
            $context = $baseDir . '/.installer/repos/' . $source['repo'];
            if (is_dir($context)) {
                $targets['context'] = $context;
            }
        } else {
            // Template apps build from the repo root, but that tree also holds
            // the vault, every app's generated .env and credential-bearing
            // compose files. A scanner must never be handed the directory the
            // secrets live in — mount the image definition alone. (Mounting
            // the root also broke outright: trivy's walk dies on the vault's
            // 0600 files.)
            foreach (['Containerfile', 'Dockerfile'] as $containerfile) {
                if (is_file($baseDir . '/' . $containerfile)) {
                    $targets[$containerfile] = $baseDir . '/' . $containerfile;
                    break;
                }
            }
        }

        $composeFile = $baseDir . '/' . $appName . '-compose.yml';
        if (is_file($composeFile)) {
            $targets['compose'] = $composeFile;
        }

        return $targets;
    }

    /**
     * @param array<string, string> $targets
     */
    protected function buildScanCommand(string $appName, array $targets): string
    {
        $mounts = '';
        foreach ($targets as $label => $path) {
            $mounts .= ' -v ' . escapeshellarg($path . ':/scan/' . $label . ':ro');
        }

        // --network=none: `trivy config` uses policies baked into the image,
        // so the scanner never needs to reach the network (pass
        // --skip-check-update or it tries anyway and wastes a timeout). No
        // podman socket is mounted, deliberately. `config` scans
        // misconfigurations only by its nature — it takes no --scanners flag.
        return 'podman run --rm'
            . ' --name ' . escapeshellarg($appName . self::CONTAINER_SUFFIX)
            . ' --network=none'
            . ' --cap-drop=ALL'
            . ' --security-opt=no-new-privileges'
            . $mounts
            . ' ' . escapeshellarg(self::SCANNER_IMAGE)
            . ' config --exit-code 0 --skip-check-update'
            // Third-party dependencies ship their own Dockerfiles and CI
            // configs; findings there are not actionable for this app.
            . " --skip-dirs '**/vendor' --skip-dirs '**/node_modules'"
            . ' /scan'
            . ' 2>&1';
    }

    protected function scannerImagePresent(): bool
    {
        $output = $this->runCommand('podman images -q ' . escapeshellarg(self::SCANNER_IMAGE) . ' 2>/dev/null');

        return trim((string) $output) !== '';
    }

    /**
     * Single seam every shell-out goes through, so tests never invoke podman.
     */
    protected function runCommand(string $command): ?string
    {
        return shell_exec($command);
    }
}
