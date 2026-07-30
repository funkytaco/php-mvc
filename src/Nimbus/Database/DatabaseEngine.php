<?php

declare(strict_types=1);

namespace Nimbus\Database;

/**
 * A database engine Nimbus knows how to provision as a container.
 *
 * Everything engine-specific about a generated database service lives here:
 * the image, the container name suffix, the environment variable names the
 * official image expects, how to health-check it, and which files on disk
 * prove a data directory already belongs to that engine.
 *
 * Postgres is the default so that apps predating this class — every template
 * app, whose app.nimbus.json has no `database.engine` key — resolve to exactly
 * the values that used to be hardcoded in AppManager::buildDatabaseService().
 * Its container name suffix stays '-postgres' for the same reason: existing
 * containers are found by that name by status checks and password extraction.
 */
final class DatabaseEngine
{
    /** Apps with no declared engine (i.e. every template app). */
    public const DEFAULT_ENGINE = 'postgres';

    /** What a git app gets when it opts into a database without naming one. */
    public const DEFAULT_GIT_ENGINE = 'mariadb';

    /**
     * image        pinned default image when the caller names no explicit one
     * suffix       appended to the app name to form the container name
     * env          config role => env var the official image reads ('' = unsupported)
     * healthcheck  shell command; %user%/%db% are substituted
     * data_files   files that mark a data dir as belonging to this engine
     * data_dir     path inside the container holding the data directory
     * port         port the server listens on inside the container
     *
     * @var array<string, array{image: string, suffix: string, env: array<string, string>, healthcheck: string, data_files: list<string>, data_dir: string, port: int}>
     */
    private const ENGINES = [
        'postgres' => [
            'image' => 'postgres:14',
            'suffix' => '-postgres',
            'env' => [
                'database' => 'POSTGRES_DB',
                'user' => 'POSTGRES_USER',
                'password' => 'POSTGRES_PASSWORD',
                'root_password' => '',
            ],
            'healthcheck' => 'pg_isready -U %user% -d %db%',
            'data_files' => ['PG_VERSION', 'postgresql.conf', 'pg_hba.conf'],
            'data_dir' => '/var/lib/postgresql/data',
            'port' => 5432,
        ],
        'mariadb' => [
            'image' => 'mariadb:12',
            'suffix' => '-db',
            'env' => [
                'database' => 'MYSQL_DATABASE',
                'user' => 'MYSQL_USER',
                'password' => 'MYSQL_PASSWORD',
                'root_password' => 'MYSQL_ROOT_PASSWORD',
            ],
            // Ships with the image since mariadb:11 and is what MariaDB's own
            // compose examples use. Must contain no ": " and no leading YAML
            // indicator character — arrayToYaml() emits list items raw.
            'healthcheck' => 'healthcheck.sh --connect --innodb_initialized',
            'data_files' => ['ibdata1', 'mysql', 'ib_logfile0'],
            'data_dir' => '/var/lib/mysql',
            'port' => 3306,
        ],
        'mysql' => [
            'image' => 'mysql:8.4',
            'suffix' => '-db',
            'env' => [
                'database' => 'MYSQL_DATABASE',
                'user' => 'MYSQL_USER',
                'password' => 'MYSQL_PASSWORD',
                'root_password' => 'MYSQL_ROOT_PASSWORD',
            ],
            'healthcheck' => 'mysqladmin ping -h 127.0.0.1 --silent',
            'data_files' => ['ibdata1', 'mysql', 'ib_logfile0'],
            'data_dir' => '/var/lib/mysql',
            'port' => 3306,
        ],
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'postgresql' => 'postgres',
        'pgsql' => 'postgres',
        'pg' => 'postgres',
        'maria' => 'mariadb',
    ];

    /** Engine key: one of the ENGINES keys. */
    public readonly string $name;

    /** Fully resolved image reference actually written into the compose file. */
    public readonly string $image;

    private function __construct(string $name, string $image)
    {
        $this->name = $name;
        $this->image = $image;
    }

    /**
     * The engine an existing app is already using.
     *
     * Deliberately permissive: this reads config that was validated when the
     * app was created, so it never rejects an image (an app pinned to a tag we
     * would refuse today must still be startable and inspectable).
     *
     * @param array<string, mixed> $config app.nimbus.json contents
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $database */
        $database = is_array($config['database'] ?? null) ? $config['database'] : [];

        $engine = is_string($database['engine'] ?? null) ? $database['engine'] : self::DEFAULT_ENGINE;
        $engine = self::canonical($engine);

        if (!isset(self::ENGINES[$engine])) {
            $engine = self::DEFAULT_ENGINE;
        }

        $image = is_string($database['image'] ?? null) && $database['image'] !== ''
            ? $database['image']
            : self::ENGINES[$engine]['image'];

        return new self($engine, $image);
    }

    /**
     * The engine a user asked for on the command line or in a repo manifest.
     *
     * Accepts a bare opt-in ('' / true / '1' => the git default), an engine
     * name ('mariadb'), or a full image reference ('docker.io/library/mysql:8.4').
     * Unlike fromConfig() this validates, because it is the point where a new
     * app's baseline is chosen.
     */
    public static function fromSpec(null|string|bool $spec): self
    {
        if ($spec === true || $spec === null) {
            return self::named(self::DEFAULT_GIT_ENGINE);
        }
        if ($spec === false) {
            throw new \InvalidArgumentException('No database engine requested.');
        }

        $spec = trim($spec);
        if ($spec === '' || $spec === '1' || strtolower($spec) === 'true' || strtolower($spec) === 'yes') {
            return self::named(self::DEFAULT_GIT_ENGINE);
        }

        $canonical = self::canonical($spec);
        if (isset(self::ENGINES[$canonical])) {
            return self::named($canonical);
        }

        return self::fromImageRef($spec);
    }

    /**
     * Build from an engine name, optionally overriding the pinned image.
     */
    public static function named(string $engine, ?string $image = null): self
    {
        $canonical = self::canonical($engine);

        if (!isset(self::ENGINES[$canonical])) {
            throw new \InvalidArgumentException(
                "Unsupported database engine '$engine' (supported: " . implode(', ', self::supported()) . ')'
            );
        }

        return new self(
            $canonical,
            $image !== null && $image !== '' ? $image : self::ENGINES[$canonical]['image']
        );
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::ENGINES);
    }

    /**
     * Derive the engine from an image reference, refusing anything that is not
     * pinned to a specific version.
     *
     * An unpinned image silently changes what the stack is built from between
     * one `nimbus:up` and the next, which defeats having a recorded baseline
     * configuration at all (NIST 800-53 CM-2; CIS Docker/Podman image policy).
     * A digest-pinned reference (name@sha256:...) is accepted as-is — it is the
     * strongest form of pinning there is.
     */
    private static function fromImageRef(string $image): self
    {
        $digestPos = strpos($image, '@');
        $namePart = $digestPos === false ? $image : substr($image, 0, $digestPos);

        $slash = strrpos($namePart, '/');
        $basename = $slash === false ? $namePart : substr($namePart, $slash + 1);

        $colon = strpos($basename, ':');
        $name = $colon === false ? $basename : substr($basename, 0, $colon);
        $tag = $colon === false ? '' : substr($basename, $colon + 1);

        if ($digestPos === false) {
            if ($tag === '') {
                throw new \InvalidArgumentException(
                    "Database image '$image' has no version tag. Pin one (for example 'mariadb:12') — "
                    . 'an untagged image resolves to :latest and changes underneath the app.'
                );
            }
            if (strtolower($tag) === 'latest') {
                throw new \InvalidArgumentException(
                    "Database image '$image' uses the ':latest' tag. Pin an explicit version "
                    . "(for example 'mariadb:12') so the stack stays reproducible."
                );
            }
        }

        $engine = self::canonical($name);
        if (!isset(self::ENGINES[$engine])) {
            throw new \InvalidArgumentException(
                "Cannot tell which database engine '$image' is. Supported engines: "
                . implode(', ', self::supported())
                . ". Name one of those, or use an image whose name matches one (for example 'docker.io/library/mariadb:12')."
            );
        }

        return new self($engine, $image);
    }

    private static function canonical(string $engine): string
    {
        $engine = strtolower(trim($engine));

        return self::ALIASES[$engine] ?? $engine;
    }

    /**
     * Container name for this app's database.
     *
     * Postgres keeps the historical '-postgres' name; engines added later use
     * '-db', matching the compose service key.
     */
    public function containerName(string $appName): string
    {
        return $appName . self::ENGINES[$this->name]['suffix'];
    }

    /**
     * Environment block for the database service, in the order the official
     * image documents. Root password is only emitted by engines that have one.
     *
     * @return array<string, string>
     */
    public function environment(string $database, string $user, string $password, string $rootPassword = ''): array
    {
        $vars = self::ENGINES[$this->name]['env'];

        $environment = [
            $vars['database'] => $database,
            $vars['user'] => $user,
            $vars['password'] => $password,
        ];

        if ($vars['root_password'] !== '') {
            // The official MySQL/MariaDB images refuse to initialize without
            // one of MYSQL_ROOT_PASSWORD / MYSQL_ALLOW_EMPTY_ROOT_PASSWORD /
            // MYSQL_RANDOM_ROOT_PASSWORD being set.
            $environment[$vars['root_password']] = $rootPassword !== '' ? $rootPassword : $password;
        }

        return $environment;
    }

    /** Name of the env var holding the user password, e.g. POSTGRES_PASSWORD. */
    public function passwordVar(): string
    {
        return self::ENGINES[$this->name]['env']['password'];
    }

    /** Name of the root password env var, or '' when the engine has none. */
    public function rootPasswordVar(): string
    {
        return self::ENGINES[$this->name]['env']['root_password'];
    }

    public function usesRootPassword(): bool
    {
        return $this->rootPasswordVar() !== '';
    }

    public function healthcheckCmd(string $user, string $database): string
    {
        return str_replace(
            ['%user%', '%db%'],
            [$user, $database],
            self::ENGINES[$this->name]['healthcheck']
        );
    }

    /**
     * Files whose presence means a data directory already holds this engine's
     * data — the signal that re-creating the app must reuse its password
     * rather than generate a new one it could never authenticate with.
     *
     * @return list<string>
     */
    public function dataFiles(): array
    {
        return self::ENGINES[$this->name]['data_files'];
    }

    /** Path inside the container where the engine stores its data. */
    public function dataDir(): string
    {
        return self::ENGINES[$this->name]['data_dir'];
    }

    public function port(): int
    {
        return self::ENGINES[$this->name]['port'];
    }

    public function isPostgres(): bool
    {
        return $this->name === 'postgres';
    }

    /** PDO driver name, matching Nimbus\Database\Connection::buildDsn(). */
    public function pdoDriver(): string
    {
        return $this->isPostgres() ? 'pgsql' : 'mysql';
    }
}
