<?php

declare(strict_types=1);

namespace Nimbus\Tasks;

use Composer\Script\Event;
use Nimbus\App\AppManagerFactory;
use Nimbus\Core\BaseTask;
use Nimbus\Env\EnvManager;

/**
 * Inspect what an app is actually configured with.
 *
 * `nimbus:env` answers "what will the container see", which is otherwise
 * spread across app.nimbus.json, the vault and generated files; `nimbus:config`
 * prints the app's own manifest.
 *
 * Secrets are masked unless explicitly asked for, so the common case — reading
 * config over someone's shoulder, or pasting output into an issue — does not
 * disclose credentials (NIST 800-53 IA-5).
 */
class EnvTask extends BaseTask
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? (getcwd() ?: '.');
    }

    public function execute(Event $event): void
    {
        $this->showEnv($event);
    }

    /**
     * nimbus:env <app> [--show-secrets]
     */
    public function showEnv(Event $event): void
    {
        $args = $event->getArguments();
        $reveal = in_array('--show-secrets', $args, true);
        $positional = array_values(array_filter($args, fn ($a) => !str_starts_with((string) $a, '--')));

        $appName = $positional[0] ?? null;
        if (!$appName) {
            echo self::ansiFormat('ERROR', 'Usage: composer nimbus:env <app> [--show-secrets]');
            return;
        }

        try {
            $manager = AppManagerFactory::forApp((string) $appName, $this->baseDir);
            $config = $manager->loadAppConfig((string) $appName);
            $environment = $manager->describeEnvironment((string) $appName);
        } catch (\Throwable $e) {
            echo self::ansiFormat('ERROR', $e->getMessage());
            return;
        }

        $total = count($environment['derived']) + count($environment['stored']) + count($environment['secrets']);

        if ($total === 0) {
            echo self::ansiFormat('INFO', "App '$appName' has no Nimbus-managed environment.");
            if (($config['source']['kind'] ?? null) !== 'git') {
                echo '  Template apps read their configuration from app/app.config.php at request time.' . PHP_EOL;
            }
            return;
        }

        echo self::ansiFormat('INFO', "🧬 Environment for '$appName'");
        echo PHP_EOL;

        $this->printGroup(
            'Derived by Nimbus (computed at generate time, not stored)',
            $environment['derived'],
            $reveal
        );
        $this->printGroup(
            'Stored in app.nimbus.json (plain, edit freely)',
            $environment['stored'],
            $reveal
        );
        $this->printGroup(
            'Stored in the vault (encrypted)',
            $environment['secrets'],
            $reveal
        );

        if (!empty($environment['dotenv'])) {
            $exists = is_file($environment['dotenv']);
            echo self::ansiFormat('INFO', 'Delivered to the container two ways:');
            echo '  • environment: block in ' . $appName . '-compose.yml' . PHP_EOL;
            echo '  • ' . $this->relative($environment['dotenv'])
                . ($exists ? '' : '  (not written yet — run nimbus:install)') . PHP_EOL;
        }

        if (!$reveal) {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "Secrets are masked. Re-run with --show-secrets to reveal them.");
        }
    }

    /**
     * nimbus:config <app> — the app's own manifest, as written on disk.
     */
    public function showConfig(Event $event): void
    {
        $args = $event->getArguments();
        $appName = $args[0] ?? null;

        if (!$appName) {
            echo self::ansiFormat('ERROR', 'Usage: composer nimbus:config <app>');
            return;
        }

        $file = $this->baseDir . '/.installer/apps/' . $appName . '/app.nimbus.json';

        if (!is_file($file)) {
            echo self::ansiFormat('ERROR', "App '$appName' not found (no $file).");
            return;
        }

        echo self::ansiFormat('INFO', "📁 .installer/apps/$appName/app.nimbus.json");
        echo PHP_EOL;
        echo rtrim((string) file_get_contents($file)) . PHP_EOL;
        echo PHP_EOL;

        $config = json_decode((string) file_get_contents($file), true);

        if (is_array($config) && ($config['source']['kind'] ?? null) === 'git') {
            echo self::ansiFormat(
                'INFO',
                'Note: no credentials appear above by design — a git app keeps them in the vault. '
                . "See composer nimbus:env $appName"
            );
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function printGroup(string $title, array $values, bool $reveal): void
    {
        if ($values === []) {
            return;
        }

        echo self::ansiFormat('SUCCESS', $title);

        $envManager = new EnvManager($this->baseDir);
        $width = max(array_map('strlen', array_keys($values)));

        foreach ($values as $key => $value) {
            $shown = (!$reveal && $envManager->isSecretKey($key))
                ? $this->mask($value)
                : $value;

            printf("  %-{$width}s  %s\n", $key, $shown);
        }

        echo PHP_EOL;
    }

    /**
     * Enough to recognise a value, not enough to use it.
     */
    private function mask(string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }

        return substr($value, 0, 4) . str_repeat('*', 8) . ' (' . strlen($value) . ' chars)';
    }

    private function relative(string $path): string
    {
        $cwd = $this->baseDir . '/';

        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
    }
}
