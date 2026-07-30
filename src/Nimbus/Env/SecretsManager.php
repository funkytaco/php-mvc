<?php

declare(strict_types=1);

namespace Nimbus\Env;

use Nimbus\Vault\VaultManager;

/**
 * The secret half of an app's environment.
 *
 * Same shape as EnvManager — an app asks for values by name and gets strings
 * back — but nothing here ever reaches app.nimbus.json. Values are read from
 * and written to the encrypted vault, under the app's own `nimbus.secrets`
 * namespace.
 *
 * Isolation is structural rather than conventional: every method takes an app
 * name and reaches exactly one app's slice through VaultManager, and there is
 * no accessor that returns more than one app. One app's generation run cannot
 * see another app's secrets even by accident (NIST 800-53 IA-9).
 */
class SecretsManager extends EnvManager
{
    private const NAMESPACE_KEY = 'secrets';

    public function __construct(string $baseDir, private VaultManager $vault)
    {
        parent::__construct($baseDir);
    }

    /**
     * @return array<string, string>
     */
    public function all(string $appName): array
    {
        $data = $this->vault->getNimbusData($appName);

        if (!is_array($data[self::NAMESPACE_KEY] ?? null)) {
            return [];
        }

        $secrets = [];
        foreach ($data[self::NAMESPACE_KEY] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $secrets[$key] = (string) $value;
            }
        }

        return $secrets;
    }

    /**
     * @param array<string, string> $values
     */
    public function setMany(string $appName, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->writeSecrets($appName, array_merge($this->all($appName), $values));
    }

    /**
     * Fill in any of these keys the app does not already have.
     *
     * Idempotent by design: re-running create or install for an app must not
     * roll its salts, or every existing session and cookie signed with the old
     * ones is invalidated.
     *
     * @param list<string> $keys
     * @return array<string, string> every secret the app has afterwards
     */
    public function generateMissing(string $appName, array $keys): array
    {
        $secrets = $this->all($appName);
        $generated = false;

        foreach ($keys as $key) {
            if (($secrets[$key] ?? '') === '') {
                $secrets[$key] = $this->generateSecret();
                $generated = true;
            }
        }

        if ($generated) {
            $this->writeSecrets($appName, $secrets);
        }

        return $secrets;
    }

    public function has(string $appName, string $key): bool
    {
        return ($this->all($appName)[$key] ?? '') !== '';
    }

    /**
     * @param array<string, string> $secrets
     */
    private function writeSecrets(string $appName, array $secrets): void
    {
        $data = $this->vault->getNimbusData($appName);
        $data[self::NAMESPACE_KEY] = $secrets;
        $data['version'] = 1;

        $this->vault->setNimbusData($appName, $data);
    }
}
