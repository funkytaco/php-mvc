<?php

declare(strict_types=1);

namespace Nimbus\App;

use Nimbus\Database\DatabaseEngine;
use Nimbus\Env\EnvManager;
use Nimbus\Env\SecretsManager;
use Nimbus\Password\PasswordSet;

/**
 * Manages apps whose code comes from a git repository rather than a local
 * template.
 *
 * The repository is cloned once into .installer/repos/<repo>/ — the git
 * analogue of .installer/_templates/ — and the app's own instance dir under
 * .installer/apps/<app>/ holds only Nimbus-owned files (app.nimbus.json and
 * friends). Keeping them apart means the working tree stays clean: no
 * generated config, no resolved passwords, nothing to accidentally commit
 * upstream, and one clone can back several app instances.
 *
 * Defaults assume a PHP app (see RUNTIME_DEFAULTS). Supporting another
 * runtime should mean adding an entry there, not another class.
 */
class GitAppManager extends AppManager
{
    /**
     * Per-runtime defaults.
     *
     * image          base image used when the repo ships no Containerfile
     * webroot        path inside the container the code is served from
     * container_port port the image listens on
     *
     * @var array<string, array{image: string, webroot: string, container_port: int}>
     */
    protected const RUNTIME_DEFAULTS = [
        'php' => [
            'image' => 'php:8.3-apache',
            'webroot' => '/var/www/html',
            'container_port' => 80,
            // Tried in order by the dev entrypoint when the repo does not
            // declare a `command`. php-fpm needs -F to stay in the foreground.
            'serve_candidates' => ['apache2-foreground', 'php-fpm -F'],
            'dependency_manifest' => 'composer.json',
            'dependency_dir' => 'vendor',
            'install_command' => 'COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress',
            'installer_binary' => 'composer',
        ],
    ];

    protected const DEFAULT_RUNTIME = 'php';

    /**
     * Optional manifest a repository can ship at its root to declare how
     * Nimbus should containerize it, instead of relying on detection:
     *
     *   { "runtime": "php", "docroot": "web",
     *     "containerfile": "Containerfile", "container_port": 80 }
     *
     * This is the contract a repo opts into. Anything it does not declare is
     * detected; anything passed on the command line wins over both.
     */
    public const REPO_MANIFEST = '.nimbus.json';

    /** Settings a repo manifest is allowed to declare. */
    protected const MANIFEST_KEYS = ['runtime', 'docroot', 'containerfile', 'container_port', 'webroot', 'command', 'database'];

    private ?EnvManager $envManager = null;

    private ?SecretsManager $secretsManager = null;

    /**
     * The app's non-secret environment, stored in its app.nimbus.json.
     */
    protected function getEnvManager(): EnvManager
    {
        return $this->envManager ??= new EnvManager($this->baseDir);
    }

    /**
     * The app's secret environment, stored only in the vault.
     */
    protected function getSecretsManager(): SecretsManager
    {
        return $this->secretsManager ??= new SecretsManager($this->baseDir, $this->getVaultManager());
    }

    /**
     * Git apps keep credentials and environment secrets in the vault and
     * nowhere else, so anything with a secret to store requires one.
     *
     * The CLI initializes the vault automatically before creating a git app;
     * this is the backstop for every other entry point.
     */
    protected function assertVaultAvailable(string $appName, string $because): void
    {
        if ($this->getVaultManager()->isInitialized()) {
            return;
        }

        throw new \RuntimeException(
            "App '$appName' needs the Nimbus vault because $because, but the vault is not "
            . "initialized. Run 'composer nimbus:vault-init' first."
        );
    }

    /**
     * Where a servable image definition is looked for, in order.
     *
     * Deliberately the repo root only. A .devcontainer/ definition is a
     * different contract — an IDE development environment — and is often not
     * servable on its own: Bedrock's is php-fpm with no web server, paired
     * with a separate nginx service in its own compose file. Building that
     * as the app container yields something that starts and serves nothing,
     * so we generate a real image instead and just point the definition out.
     */
    protected const CONTAINERFILE_CANDIDATES = [
        'Containerfile',
        'Dockerfile',
    ];

    /** Looked at only to tell the user they exist. */
    protected const DEVCONTAINER_HINTS = [
        '.devcontainer/Containerfile',
        '.devcontainer/Dockerfile',
    ];

    /**
     * Repo-relative directories that are served directly when present —
     * Bedrock uses web/, Laravel and Slim-style apps use public/.
     */
    protected const DOCROOT_CANDIDATES = ['web', 'public', 'html'];

    /**
     * Messages describing anything the user should follow up on.
     *
     * @var string[]
     */
    private array $notices = [];

    protected function reposDir(): string
    {
        return $this->baseDir . '/.installer/repos';
    }

    protected function repoPath(string $repo): string
    {
        return $this->reposDir() . '/' . $repo;
    }

    /**
     * Notices raised by the last createFromRepo() call (e.g. "a default
     * Containerfile was generated, review it"). CLI tasks print these.
     *
     * @return string[]
     */
    public function getNotices(): array
    {
        return $this->notices;
    }

    /**
     * Git-sourced apps have no template to commit back to. Left as a no-op
     * (inherited from the base) rather than an error, so `nimbus:commit`
     * stays safe to run; committing changes is `git commit` in the clone.
     */
    public function supportsCommit(): bool
    {
        return false;
    }

    /**
     * EDA and Keycloak scaffolding come out of a Nimbus template — a plain
     * repository has none of those files, and enabling the feature anyway
     * would generate a compose file mounting paths that do not exist.
     */
    protected function assertFeatureSupported(string $appName, string $feature): void
    {
        if (in_array($feature, ['eda', 'keycloak'], true)) {
            throw new \RuntimeException(
                "Feature '$feature' is not supported for git-sourced apps yet — "
                . "it needs template scaffolding the repository does not provide."
            );
        }
    }

    /**
     * Create an app backed by a git repository.
     *
     * $repoUrl may be a clone URL or the name of a repo already sitting in
     * .installer/repos/ (so a clone made by hand can be adopted as-is).
     *
     * @param array{ref?: ?string, repo?: ?string, runtime?: ?string, docroot?: ?string,
     *              webroot?: ?string, container_port?: ?int, containerfile?: ?string} $options
     */
    public function createFromRepo(string $appName, string $repoUrl, array $options = []): bool
    {
        $this->notices = [];

        $repo = $options['repo'] ?? $this->deriveRepoName($repoUrl);
        $repoPath = $this->repoPath($repo);

        return $this->createAppInstance($appName, 'git', function (string $targetPath) use (
            $appName,
            $repo,
            $repoUrl,
            $repoPath,
            $options
        ) {
            // Reuse an existing clone; only fetch when we have to. The clone
            // lives outside the instance dir, so it deliberately survives
            // rollback of a failed create.
            if (is_dir($repoPath)) {
                $this->notices[] = "Using existing repository at .installer/repos/$repo";
            } else {
                $this->cloneRepository($repoUrl, $options['ref'] ?? null, $repoPath);
            }

            if (!is_dir($repoPath)) {
                throw new \RuntimeException("Repository directory not found after clone: $repoPath");
            }

            $settings = $this->resolveRepoSettings($repoPath, $repo, $options);

            // Fail before anything is written when the app will need somewhere
            // to keep a credential and has none.
            if (($settings['database'] ?? null) !== null) {
                $this->assertVaultAvailable($appName, 'it has a database password to store');
            }

            mkdir($targetPath, 0755, true);

            $this->writeGitAppConfig($appName, $targetPath, array_merge($settings, [
                'repo' => $repo,
                'url' => $repoUrl,
                'ref' => $options['ref'] ?? null,
            ]));

            // Both of these read the config that was just written — the engine
            // decides which credentials exist, and the app's port decides what
            // its URLs resolve to.
            if (($settings['database'] ?? null) !== null) {
                $passwords = $this->resolveAppPasswords($appName);
                $this->backupPasswordsToVault($appName, $passwords);
            }

            $this->seedAppEnvironment($appName, $repoPath);
        });
    }

    /**
     * Give the app the environment its repository says it needs.
     *
     * The repo's .env.example is the starting point. Values Nimbus computes
     * itself are skipped, anything naming a secret is generated fresh into the
     * vault — never copied from the example, since a value shipped in a public
     * repository is a published default (NIST 800-53 IA-5) — and the rest is
     * recorded as plain, editable app config.
     *
     * Vault entries deliberately survive a failed create: like the clone, they
     * live outside the instance directory, so retrying restores the app's
     * existing credentials instead of orphaning its data behind new ones.
     */
    protected function seedAppEnvironment(string $appName, string $repoPath): void
    {
        $envManager = $this->getEnvManager();
        $example = $envManager->parseEnvExample($repoPath);

        if ($example === []) {
            return;
        }

        $plain = [];
        $secretKeys = [];

        foreach ($example as $key => $value) {
            if (in_array($key, EnvManager::DERIVED_KEYS, true)) {
                continue;
            }

            if ($envManager->isSecretKey($key)) {
                $secretKeys[] = $key;
                continue;
            }

            $plain[$key] = $value;
        }

        $plain = $this->expandEnvReferences(
            $this->localizeExampleUrls($plain, (string) $this->generatePort($appName))
        );

        if ($plain !== []) {
            $envManager->setMany($appName, $plain);
            $this->notices[] = count($plain) . ' environment value(s) seeded from .env.example into app.nimbus.json.';
        }

        if ($secretKeys !== []) {
            $this->assertVaultAvailable($appName, 'its repository declares secret environment values');
            $this->getSecretsManager()->generateMissing($appName, $secretKeys);
            $this->notices[] = count($secretKeys) . ' secret value(s) generated and stored in the vault.';
        }
    }

    /**
     * Point example URLs at the address this app is actually served on.
     *
     * A .env.example necessarily hardcodes a placeholder host (Bedrock ships
     * http://example.com). Left alone, WordPress builds every link, redirect
     * and asset URL against it.
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    protected function localizeExampleUrls(array $env, string $port): array
    {
        foreach ($env as $key => $value) {
            // The path group is written to always participate, so there is no
            // trailing-unmatched-group case to guard against.
            if (preg_match('#^https?://[^/]*(.*)$#i', $value, $matches) !== 1) {
                continue;
            }

            $env[$key] = 'http://localhost:' . $port . $matches[1];
        }

        return $env;
    }

    /**
     * Flatten ${VAR} / $VAR references between seeded values.
     *
     * Compose environment blocks are not interpolated by the container engine,
     * and podman-compose would try to resolve an unknown ${VAR} against the
     * host shell — so references are resolved here, while the values they
     * point at are still in hand. Two passes, which covers the one level of
     * indirection these files actually use (Bedrock's WP_SITEURL => WP_HOME).
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    protected function expandEnvReferences(array $env): array
    {
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($env as $key => $value) {
                $env[$key] = preg_replace_callback(
                    '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
                    static function (array $matches) use ($env): string {
                        $name = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');

                        return $env[$name] ?? $matches[0];
                    },
                    $value
                ) ?? $value;
            }
        }

        return $env;
    }

    /**
     * Settle every setting Nimbus needs to containerize a repository.
     *
     * Precedence is explicit: a command-line option beats the repo's own
     * .nimbus.json manifest, which beats detection, which beats the runtime
     * default. Each resolved value records where it came from so the CLI can
     * show the user what was assumed versus declared.
     *
     * @param array<string, mixed> $options
     * @return array{runtime: string, docroot: string, webroot: string,
     *               container_port: int, containerfile: string,
     *               database: array{engine: string, image: string}|null}
     */
    protected function resolveRepoSettings(string $repoPath, string $repo, array $options): array
    {
        $manifest = $this->readRepoManifest($repoPath);

        $pick = function (string $key, $detected, $default) use ($options, $manifest) {
            if (isset($options[$key]) && $options[$key] !== '' && $options[$key] !== null) {
                return [$options[$key], 'command line'];
            }
            if (isset($manifest[$key]) && $manifest[$key] !== '' && $manifest[$key] !== null) {
                return [$manifest[$key], self::REPO_MANIFEST];
            }
            if ($detected !== null) {
                return [$detected, 'detected'];
            }

            return [$default, 'default'];
        };

        [$runtime, $runtimeFrom] = $pick('runtime', null, self::DEFAULT_RUNTIME);
        if (!isset(self::RUNTIME_DEFAULTS[$runtime])) {
            throw new \InvalidArgumentException(
                "Unsupported runtime '$runtime' (supported: " . implode(', ', array_keys(self::RUNTIME_DEFAULTS)) . ')'
            );
        }
        $defaults = self::RUNTIME_DEFAULTS[$runtime];

        [$docroot, $docrootFrom] = $pick('docroot', $this->detectDocroot($repoPath), '');
        [$webroot] = $pick('webroot', null, $defaults['webroot']);
        [$containerPort] = $pick('container_port', null, $defaults['container_port']);
        [$command] = $pick('command', null, null);

        // Normalize a docroot given as './web' or 'web/'
        $docroot = trim(str_replace('\\', '/', (string) $docroot), './');

        if ($docroot !== '' && !is_dir($repoPath . '/' . $docroot)) {
            throw new \RuntimeException(
                "Document root '$docroot' does not exist in repository '$repo'."
            );
        }

        $this->notices[] = $docroot === ''
            ? "Document root: repository root ($docrootFrom)."
            : "Document root: $docroot/ ($docrootFrom).";

        $containerfile = $this->resolveContainerfile(
            $repoPath,
            $repo,
            $options['containerfile'] ?? $manifest['containerfile'] ?? null,
            $runtime,
            $docroot
        );

        // Opt-in, unlike template apps: a bare repository is not assumed to
        // want a database. A repo can ask for one in its manifest, and the
        // command line wins either way — so --no-db turns off what a manifest
        // requested, and --db overrides the manifest's choice of engine.
        [$database, $databaseFrom] = $pick('database', null, null);

        return [
            'runtime' => $runtime,
            'docroot' => $docroot,
            'webroot' => $webroot,
            'container_port' => (int) $containerPort,
            'containerfile' => $containerfile,
            'command' => $command === null ? null : (string) $command,
            'database' => $this->resolveDatabaseSpec($database, $databaseFrom),
        ];
    }

    /**
     * Turn a --db value (or a manifest's "database") into the engine the app
     * will run, or null when it is not getting one.
     *
     * @return array{engine: string, image: string}|null
     */
    protected function resolveDatabaseSpec(mixed $value, string $from): ?array
    {
        $disabled = $value === null
            || $value === false
            || (is_string($value) && in_array(strtolower(trim($value)), ['', '0', 'no', 'off', 'none', 'false'], true));

        if ($disabled) {
            $this->notices[] = 'Database: none. Pass --db to add one (default '
                . DatabaseEngine::named(DatabaseEngine::DEFAULT_GIT_ENGINE)->image . ').';

            return null;
        }

        $engine = DatabaseEngine::fromSpec(is_bool($value) ? true : (string) $value);

        $this->notices[] = "Database: {$engine->image} ($from).";

        return ['engine' => $engine->name, 'image' => $engine->image];
    }

    /**
     * App name as a SQL identifier. MySQL and MariaDB accept a hyphen in a
     * database name only when it is quoted everywhere it is referenced, which
     * neither the image's entrypoint nor most apps do.
     */
    protected function databaseIdentifier(string $appName): string
    {
        return str_replace('-', '_', $appName);
    }

    /**
     * Read the repository's opt-in Nimbus manifest, if it ships one.
     *
     * @return array<string, mixed>
     */
    protected function readRepoManifest(string $repoPath): array
    {
        $file = $repoPath . '/' . self::REPO_MANIFEST;
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'Repository manifest ' . self::REPO_MANIFEST . ' is not valid JSON.'
            );
        }

        $unknown = array_diff(array_keys($decoded), self::MANIFEST_KEYS);
        if (!empty($unknown)) {
            $this->notices[] = 'Ignoring unknown key(s) in ' . self::REPO_MANIFEST . ': ' . implode(', ', $unknown);
        }

        $this->notices[] = 'Using settings declared in the repository\'s ' . self::REPO_MANIFEST . '.';

        return array_intersect_key($decoded, array_flip(self::MANIFEST_KEYS));
    }

    /**
     * Clone a repository. Shallow by default — Nimbus only needs a working
     * tree, and full history on a WordPress/Laravel repo is a slow download.
     */
    protected function cloneRepository(string $url, ?string $ref, string $targetDir): void
    {
        $parent = dirname($targetDir);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        $command = 'git clone --depth 1'
            . ($ref !== null && $ref !== '' ? ' --branch ' . escapeshellarg($ref) : '')
            . ' ' . escapeshellarg($url)
            . ' ' . escapeshellarg($targetDir)
            . ' 2>&1';

        $output = $this->runCommand($command);

        if (!is_dir($targetDir . '/.git')) {
            throw new \RuntimeException("git clone failed for '$url': " . trim((string) $output));
        }
    }

    /**
     * Repo directory name for a clone URL: the last path segment, minus .git.
     */
    protected function deriveRepoName(string $repoUrl): string
    {
        $name = basename(rtrim(preg_replace('#[?\#].*$#', '', $repoUrl) ?? $repoUrl, '/'));
        $name = preg_replace('/\.git$/i', '', $name) ?? $name;

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException("Could not derive a repository name from '$repoUrl'");
        }

        return $name;
    }

    /**
     * The repo-relative directory the web server should serve.
     *
     * Framework layouts put the front controller in a subdirectory (Bedrock
     * web/, Laravel public/); pointing the document root at the repo root
     * for those would expose composer.json, .env and vendor/ to the web.
     */
    protected function detectDocroot(string $repoPath): string
    {
        foreach (self::DOCROOT_CANDIDATES as $candidate) {
            if (file_exists($repoPath . '/' . $candidate . '/index.php')) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Find the repo's Containerfile, or generate a default one.
     *
     * Real-world PHP repos frequently ship none at all (Laravel) or hide one
     * under .devcontainer/ (Bedrock), so a hard requirement would reject most
     * of them. When nothing is found we write a runtime-appropriate default
     * into the repo and tell the user to review it.
     *
     * @return string path relative to the repo root
     */
    protected function resolveContainerfile(
        string $repoPath,
        string $repo,
        ?string $preferred,
        string $runtime,
        string $docroot
    ): string {
        if ($preferred !== null && $preferred !== '') {
            if (!file_exists($repoPath . '/' . $preferred)) {
                throw new \RuntimeException("Containerfile '$preferred' not found in repository '$repo'");
            }

            return $preferred;
        }

        foreach (self::CONTAINERFILE_CANDIDATES as $candidate) {
            if (file_exists($repoPath . '/' . $candidate)) {
                $this->notices[] = "Using $candidate from the repository.";

                return $candidate;
            }
        }

        foreach (self::DEVCONTAINER_HINTS as $hint) {
            if (file_exists($repoPath . '/' . $hint)) {
                $this->notices[] = "Note: '$repo' ships a $hint, but a devcontainer image is "
                    . "usually not servable on its own. Generating one instead — pass "
                    . "--containerfile=$hint to use it anyway.";
                break;
            }
        }

        $generated = 'Containerfile';
        file_put_contents(
            $repoPath . '/' . $generated,
            $this->generateContainerfile($repoPath, $runtime, $docroot)
        );

        $this->notices[] = "No Containerfile found in '$repo' — generated a default one at "
            . ".installer/repos/$repo/$generated. Review it before running nimbus:up; "
            . "it is a starting point, not a production image.";

        return $generated;
    }

    /**
     * Build a default Containerfile for a repository.
     *
     * Derived from what the repo actually declares: the PHP version and
     * extensions from composer.json, and the detected document root. pdo_mysql
     * and mysqli are always included because the CMS/framework apps this
     * targets (WordPress, Laravel) do not boot without a database driver.
     */
    protected function generateContainerfile(string $repoPath, string $runtime, string $docroot): string
    {
        $composer = $this->readComposerJson($repoPath);
        $phpVersion = $this->detectPhpVersion($composer);
        $extensions = $this->detectPhpExtensions($composer);
        $documentRoot = rtrim(self::RUNTIME_DEFAULTS[$runtime]['webroot'] . '/' . $docroot, '/');
        $hasComposerJson = $composer !== null;

        $lines = [
            '# Generated by Nimbus because this repository ships no Containerfile.',
            '# Review and adapt it — then commit it to the repo to keep it.',
            'FROM php:' . $phpVersion . '-apache',
            '',
            'RUN apt-get update && apt-get install -y --no-install-recommends \\',
            '        git unzip zip libzip-dev libicu-dev \\',
            '    && docker-php-ext-install -j"$(nproc)" ' . implode(' ', $extensions) . ' \\',
            '    && apt-get clean && rm -rf /var/lib/apt/lists/*',
            '',
        ];

        if ($hasComposerJson) {
            $lines[] = 'COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer';
            $lines[] = '';
        }

        // Apache serves /var/www/html by default; repoint it when the app
        // keeps its front controller in a subdirectory.
        if ($documentRoot !== self::RUNTIME_DEFAULTS[$runtime]['webroot']) {
            $lines[] = 'ENV APACHE_DOCUMENT_ROOT=' . $documentRoot;
            $lines[] = 'RUN sed -ri -e \'s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g\' /etc/apache2/sites-available/*.conf \\';
            $lines[] = '    && sed -ri -e \'s!/var/www/!${APACHE_DOCUMENT_ROOT}!g\' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf';
            $lines[] = '';
        }

        $lines[] = 'RUN a2enmod rewrite';
        $lines[] = '';
        $lines[] = 'WORKDIR /var/www/html';
        $lines[] = 'COPY . /var/www/html';

        if ($hasComposerJson) {
            $lines[] = '';
            // --no-scripts: framework post-install hooks routinely need a .env
            // or a database that does not exist yet at image build time.
            $lines[] = 'RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts';
        }

        $lines[] = '';
        $lines[] = 'RUN chown -R www-data:www-data /var/www/html';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @return array<string, mixed>|null */
    protected function readComposerJson(string $repoPath): ?array
    {
        $file = $repoPath . '/composer.json';
        if (!file_exists($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lowest PHP minor the repo declares support for — that is the version
     * its constraint guarantees to work on.
     *
     * @param array<string, mixed>|null $composer
     */
    protected function detectPhpVersion(?array $composer): string
    {
        $constraint = $composer['require']['php'] ?? null;

        if (is_string($constraint) && preg_match('/(\d+)\.(\d+)/', $constraint, $m)) {
            return $m[1] . '.' . $m[2];
        }

        return '8.3';
    }

    /**
     * PHP extensions to build into the image: the database drivers these apps
     * need, plus any ext-* the repo declares that is not already bundled.
     *
     * @param array<string, mixed>|null $composer
     * @return string[]
     */
    protected function detectPhpExtensions(?array $composer): array
    {
        $extensions = ['pdo_mysql', 'mysqli', 'zip', 'intl'];

        // Shipped with the official php images; asking for them fails the build.
        $builtIn = ['json', 'ctype', 'tokenizer', 'filter', 'session', 'pcre', 'spl', 'openssl', 'curl', 'fileinfo'];

        foreach (array_keys($composer['require'] ?? []) as $requirement) {
            if (!is_string($requirement) || !str_starts_with($requirement, 'ext-')) {
                continue;
            }

            $ext = substr($requirement, 4);
            if (!in_array($ext, $builtIn, true) && !in_array($ext, $extensions, true)) {
                $extensions[] = $ext;
            }
        }

        return $extensions;
    }

    /**
     * Write the app's app.nimbus.json.
     *
     * Deliberately no "type" key: that means "template directory name"
     * elsewhere in Nimbus (commitAppToTemplate resolves it against
     * .installer/_templates). source.kind is the discriminator instead.
     *
     * @param array<string, mixed> $source
     */
    protected function writeGitAppConfig(string $appName, string $targetPath, array $source): void
    {
        // The database describes the app's own stack, not where its code came
        // from, so it does not belong under `source`.
        $database = $source['database'] ?? null;
        unset($source['database']);

        $config = [
            'name' => $appName,
            'version' => '1.0.0',
            'description' => 'Git-sourced app from ' . $source['url'],
            'source' => array_merge(['kind' => 'git'], $source),
            'features' => [
                'database' => $database !== null,
                'eda' => false,
                'certbot' => false,
                'keycloak' => false,
            ],
            'containers' => [
                'app' => ['port' => (string) $this->generatePort($appName)],
            ],
        ];

        if ($database !== null) {
            // Note what is absent: no password. For a git app the credential
            // exists only in the vault, and is resolved when the compose file
            // is generated. This file is read, copied and mounted far more
            // freely than the vault is (NIST 800-53 IA-5).
            $config['database'] = [
                'engine' => $database['engine'],
                'image' => $database['image'],
                'name' => $this->databaseIdentifier($appName) . '_db',
                'user' => $this->databaseIdentifier($appName) . '_user',
            ];
        }

        file_put_contents(
            $targetPath . '/app.nimbus.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Build the app service from the cloned repository instead of the
     * framework's own image.
     */
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function buildAppService(string $appName, array $config, ?PasswordSet $passwords = null): array
    {
        $source = $config['source'] ?? [];
        $runtime = $source['runtime'] ?? self::DEFAULT_RUNTIME;
        $defaults = self::RUNTIME_DEFAULTS[$runtime] ?? self::RUNTIME_DEFAULTS[self::DEFAULT_RUNTIME];
        $repo = $source['repo'] ?? $appName;
        $webroot = $source['webroot'] ?? $defaults['webroot'];

        $service = [
            'build' => [
                'context' => './.installer/repos/' . $repo,
                'dockerfile' => $source['containerfile'] ?? 'Containerfile',
            ],
            'container_name' => $appName . '-app',
            'ports' => [
                ($config['containers']['app']['port'] ?? '8080')
                    . ':' . ($source['container_port'] ?? $defaults['container_port']),
            ],
        ];

        $environment = $this->resolveAppEnvironment($appName, $config, $passwords);

        if ($environment !== []) {
            $service['environment'] = $environment;

            // Delivered twice on purpose. Real environment variables cover
            // anything reading getenv(), and the .env file covers tooling that
            // insists on the file itself (wp-cli, composer scripts, dotenv's
            // required() check). Bedrock's dotenv repository is immutable, so
            // the environment wins and the file only fills gaps — the two can
            // never fight.
            $service['volumes'] = [$this->dotEnvMount($appName, $webroot)];
        }

        $service['networks'] = [$appName . '-net'];

        return $service;
    }

    /**
     * Bind mount putting the app's generated .env at the root of its code.
     *
     * A single-file mount, not a directory: the file is generated and holds
     * resolved credentials, so it stays in the Nimbus-owned instance directory
     * rather than being written into the git working tree the user commits
     * from.
     */
    protected function dotEnvMount(string $appName, string $webroot): string
    {
        return './.installer/apps/' . $appName . '/.env:' . rtrim($webroot, '/') . '/.env:Z';
    }

    /**
     * Everything the app container should see in its environment: what Nimbus
     * derives from the app's own config, overlaid with the app's stored env
     * and then its vault-held secrets.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    protected function resolveAppEnvironment(string $appName, array $config, ?PasswordSet $passwords): array
    {
        $derived = [];

        if (($config['features']['database'] ?? false) && $passwords !== null) {
            $engine = DatabaseEngine::fromConfig($config);

            $derived = [
                // Reachable under this name on the app's compose network
                'DB_HOST' => $engine->containerName($appName),
                'DB_PORT' => (string) $engine->port(),
                'DB_NAME' => $config['database']['name'] ?? $this->databaseIdentifier($appName) . '_db',
                'DB_USER' => $config['database']['user'] ?? $this->databaseIdentifier($appName) . '_user',
                'DB_PASSWORD' => $passwords->databasePassword,
            ];
        }

        return $this->getEnvManager()->resolve($appName, $derived, $this->getSecretsManager());
    }

    /**
     * Whether this app has an environment to deliver at all — a database to
     * connect to, stored config, or secrets in the vault.
     *
     * @param array<string, mixed> $config
     */
    protected function hasAppEnvironment(string $appName, array $config): bool
    {
        return ($config['features']['database'] ?? false)
            || $this->getEnvManager()->all($appName) !== []
            || $this->getSecretsManager()->all($appName) !== [];
    }

    /**
     * Write the app's .env before the compose file that mounts it.
     *
     * It has to exist first: podman creates a *directory* at a bind-mount
     * source that is missing, and the app would then find a directory where
     * its .env belongs.
     *
     * @param array<string, mixed> $config
     */
    protected function generatePodmanCompose(string $appName, array $config): void
    {
        $environment = $this->resolveAppEnvironment(
            $appName,
            $config,
            $this->extractPasswordsFromConfig($appName)
        );

        if ($environment !== []) {
            $this->getEnvManager()->writeDotEnv($appName, $environment);
        }

        parent::generatePodmanCompose($appName, $config);
    }

    /**
     * Git apps keep no password in app.nimbus.json — the vault holds the only
     * copy — so re-resolve rather than reading it back out of the config.
     */
    protected function extractPasswordsFromConfig(string $appName): ?PasswordSet
    {
        $config = $this->loadAppConfig($appName);

        if (!($config['features']['database'] ?? false)) {
            return parent::extractPasswordsFromConfig($appName);
        }

        $this->assertVaultAvailable($appName, 'its database password is stored there');

        return $this->resolveAppPasswords($appName);
    }

    /**
     * Wait for the database to be *healthy*, not merely started.
     *
     * The thing being started here is someone else's application rather than
     * the framework: WordPress and Laravel both fail their first request
     * outright when the database is still initializing, and a plain depends_on
     * only waits for the container to exist.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function buildComposeConfig(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $compose = parent::buildComposeConfig($appName, $config, $passwords);

        $appService = $appName . '-app';
        $dependsOn = $compose['services'][$appService]['depends_on'] ?? [];
        $database = $appName . '-db';

        if (in_array($database, $dependsOn, true)) {
            $conditions = [];
            foreach ($dependsOn as $dependency) {
                $conditions[$dependency] = [
                    'condition' => $dependency === $database ? 'service_healthy' : 'service_started',
                ];
            }

            $compose['services'][$appService]['depends_on'] = $conditions;
        }

        return $compose;
    }

    /**
     * Dev mode for a git app: bind-mount the clone over the image's web root
     * so edits on the host are live in the container, and hand the same tree
     * to the code-server sidecar for in-browser editing.
     */
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function buildDevOverlay(string $appName, array $config): array
    {
        $source = $config['source'] ?? [];
        $runtime = $source['runtime'] ?? self::DEFAULT_RUNTIME;
        $defaults = self::RUNTIME_DEFAULTS[$runtime] ?? self::RUNTIME_DEFAULTS[self::DEFAULT_RUNTIME];
        $webroot = $source['webroot'] ?? $defaults['webroot'];
        $repoHostDir = './.installer/repos/' . ($source['repo'] ?? $appName);

        $volumes = [$repoHostDir . ':' . $webroot . ':Z'];

        // Listed after the repo mount so the file lands on top of the tree
        // that was just mounted over the webroot, not underneath it. Only
        // whether there is an environment matters here, not what is in it —
        // resolving the values would mean reading credentials this method has
        // no use for.
        if ($this->hasAppEnvironment($appName, $config)) {
            $volumes[] = $this->dotEnvMount($appName, $webroot);
        }

        return [
            'version' => '3.8',
            'services' => [
                $appName . '-app' => [
                    'volumes' => $volumes,
                    'entrypoint' => [
                        '/bin/sh',
                        '-c',
                        $this->buildDevBootstrap($webroot, $defaults, $source['command'] ?? null),
                    ],
                ],
                $appName . '-code-server' => $this->buildCodeServerService($appName, $config, $repoHostDir),
            ],
        ];
    }

    /**
     * Dev mode serves the clone directly, and `bin/nimbus dev` can be the
     * first command run after create with no install in between — so the .env
     * the overlay mounts is written here as well, not only at install time.
     *
     * @return array<string, mixed>
     */
    public function generateDevCompose(string $appName): array
    {
        $result = parent::generateDevCompose($appName);

        $environment = $this->resolveAppEnvironment(
            $appName,
            $this->loadAppConfig($appName),
            $this->extractPasswordsFromConfig($appName)
        );

        if ($environment !== []) {
            $this->getEnvManager()->writeDotEnv($appName, $environment);
        }

        return $result;
    }

    /**
     * Startup script for the dev container.
     *
     * Bind-mounting the clone over the web root REPLACES whatever the image
     * built there — including dependencies the image installed at build time.
     * Every PHP project gitignores those (Bedrock keeps WordPress core itself
     * under web/wp), so without this the first dev run serves a tree with no
     * vendor/ and dies on a missing require. Installing on start puts them on
     * the host side of the mount, where they persist and stay editable.
     *
     * Only runs when the dependency dir is genuinely absent, so restarts stay
     * fast. A failed install still starts the server — a debuggable app beats
     * a container that exits.
     *
     * @param array<string, mixed> $defaults runtime defaults
     */
    protected function buildDevBootstrap(string $webroot, array $defaults, ?string $command): string
    {
        $manifest = $defaults['dependency_manifest'] ?? 'composer.json';
        $depDir = $defaults['dependency_dir'] ?? 'vendor';
        $installer = $defaults['installer_binary'] ?? 'composer';
        $install = $defaults['install_command'] ?? 'composer install';

        // The YAML emitter writes list items raw, so this must contain no
        // colon-space and no leading indicator character.
        $script = 'cd ' . $webroot . ' || exit 1; '
            . 'if [ -f ' . $manifest . ' ] && [ ! -d ' . $depDir . ' ] && command -v ' . $installer . ' >/dev/null 2>&1; then '
            . 'echo [nimbus] first dev run - installing dependencies into the mounted repo; '
            . $install . ' || echo [nimbus] dependency install failed - starting server anyway; '
            . 'fi; ';

        if ($command !== null && $command !== '') {
            return $script . 'exec ' . $command;
        }

        // No declared command: try the runtime's known servers, then say so
        // clearly rather than exiting with a bare 127.
        $candidates = $defaults['serve_candidates'] ?? [];
        foreach ($candidates as $candidate) {
            $binary = strtok($candidate, ' ');
            $script .= 'if command -v ' . $binary . ' >/dev/null 2>&1; then exec ' . $candidate . '; fi; ';
        }

        return $script
            . 'echo [nimbus] cannot tell how to start this image - declare command in .nimbus.json; exit 1';
    }
}
