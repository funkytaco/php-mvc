<?php

declare(strict_types=1);

namespace Nimbus\Env;

/**
 * Per-app environment values that are not secret.
 *
 * These live in the app's app.nimbus.json under an "env" object: they are
 * meant to be read and edited by a human (WP_ENV, WP_HOME, DB_PREFIX), they
 * are worth diffing, and there is nothing in them to protect. Anything that
 * *is* worth protecting goes through SecretsManager instead, which keeps the
 * same API but persists to the vault.
 *
 * Rendering is deliberately separate from storage: the same resolved map is
 * emitted twice, once as a compose `environment:` block and once as a literal
 * .env file, because apps read their configuration through either path
 * (Bedrock's dotenv repository is immutable, so real environment variables win
 * and the .env file fills the gaps).
 */
class EnvManager
{
    /**
     * Values Nimbus computes at generation time from the app's own config, so
     * they are never seeded into stored env — recording them would let a stale
     * copy shadow the real database host or password.
     *
     * @var list<string>
     */
    public const DERIVED_KEYS = [
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_HOST',
        'DB_PORT',
        'DATABASE_URL',
    ];

    public function __construct(protected string $baseDir)
    {
    }

    /**
     * This app's stored non-secret environment.
     *
     * @return array<string, string>
     */
    public function all(string $appName): array
    {
        $config = $this->loadAppConfig($appName);

        if (!is_array($config['env'] ?? null)) {
            return [];
        }

        $env = [];
        foreach ($config['env'] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $env[$key] = (string) $value;
            }
        }

        return $env;
    }

    public function get(string $appName, string $key): ?string
    {
        return $this->all($appName)[$key] ?? null;
    }

    public function set(string $appName, string $key, string $value): void
    {
        $this->setMany($appName, [$key => $value]);
    }

    /**
     * @param array<string, string> $values
     */
    public function setMany(string $appName, array $values): void
    {
        if ($values === []) {
            return;
        }

        $config = $this->loadAppConfig($appName);
        $existing = is_array($config['env'] ?? null) ? $config['env'] : [];
        $config['env'] = array_merge($existing, $values);

        $this->saveAppConfig($appName, $config);
    }

    /**
     * The full environment an app runs with.
     *
     * Later sources win: values Nimbus derives are the baseline, stored env
     * can override them (the escape hatch for anything Nimbus guesses wrong),
     * and secrets override both so a credential is never shadowed by a
     * plaintext copy that drifted.
     *
     * @param array<string, string> $derived
     * @return array<string, string>
     */
    public function resolve(string $appName, array $derived, ?SecretsManager $secrets = null): array
    {
        $env = array_merge($derived, $this->all($appName));

        if ($secrets !== null) {
            $env = array_merge($env, $secrets->all($appName));
        }

        return $env;
    }

    /**
     * Keys that name something worth keeping in the vault rather than in
     * plaintext config. Matched on whole underscore-separated words so
     * KEYCLOAK_URL is not mistaken for a key and AUTH_KEY is.
     */
    public function isSecretKey(string $key): bool
    {
        return preg_match('/(^|_)(KEY|SECRET|SALT|PASSWORD|PASSWD|TOKEN)(_|$)/i', $key) === 1;
    }

    /**
     * Parse a repository's .env.example into key => example value.
     *
     * Commented-out lines are skipped, so optional settings a repo documents
     * but does not enable (Bedrock's `# DB_HOST=...`) stay unset rather than
     * being adopted as if the repo had asked for them.
     *
     * @return array<string, string>
     */
    public function parseEnvExample(string $repoPath): array
    {
        $file = rtrim($repoPath, '/') . '/.env.example';

        if (!is_file($file)) {
            return [];
        }

        $env = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $env[$key] = $this->unquote(trim(substr($line, $eq + 1)));
        }

        return $env;
    }

    /**
     * Render a .env file body.
     *
     * @param array<string, string> $env
     */
    public function renderDotEnv(array $env): string
    {
        $lines = [
            '# Generated by Nimbus - do not edit, this file is rewritten on install.',
            '# Non-secret values come from "env" in app.nimbus.json; secrets come',
            '# from the vault (composer nimbus:vault-view).',
            '',
        ];

        foreach ($env as $key => $value) {
            $lines[] = $key . '=' . $this->quoteDotEnvValue((string) $value);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Write the app's .env into its Nimbus-owned instance directory.
     *
     * Kept out of the repository clone on purpose: the clone is a git working
     * tree the user may commit from, and this file holds resolved credentials.
     * It is mounted into the container as a single file instead.
     *
     * Mode 0600 — it is an authenticator store (NIST 800-53 IA-5).
     *
     * @param array<string, string> $env
     */
    public function writeDotEnv(string $appName, array $env): string
    {
        $path = $this->dotEnvPath($appName);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->renderDotEnv($env));
        chmod($path, 0600);

        return $path;
    }

    public function dotEnvPath(string $appName): string
    {
        return $this->baseDir . '/.installer/apps/' . $appName . '/.env';
    }

    /**
     * Single quotes keep a value literal, which is what almost every generated
     * value wants. A value containing one falls back to double quotes, where
     * $, " and \ are escaped so it still round-trips unchanged.
     */
    private function quoteDotEnvValue(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }

    private function unquote(string $value): string
    {
        // Trailing inline comment on an unquoted value
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $hash = strpos($value, ' #');
            if ($hash !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
        }

        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && $value[$length - 1] === $first) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadAppConfig(string $appName): array
    {
        $file = $this->appConfigPath($appName);

        if (!is_file($file)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($file), true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function saveAppConfig(string $appName, array $config): void
    {
        $file = $this->appConfigPath($appName);

        if (!is_dir(dirname($file))) {
            throw new \RuntimeException("App '$appName' not found at " . dirname($file));
        }

        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function appConfigPath(string $appName): string
    {
        return $this->baseDir . '/.installer/apps/' . $appName . '/app.nimbus.json';
    }

    /**
     * Shell-safe alphanumeric secret from a CSPRNG.
     *
     * Alphanumeric only because these values travel through generated YAML,
     * .env files and shell entrypoints; 64 characters of it is ~381 bits, far
     * past what the character set costs.
     */
    protected function generateSecret(int $length = 64): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, $max)];
        }

        return $secret;
    }
}
