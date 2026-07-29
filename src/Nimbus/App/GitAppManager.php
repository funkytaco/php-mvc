<?php

declare(strict_types=1);

namespace Nimbus\App;

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
    protected const MANIFEST_KEYS = ['runtime', 'docroot', 'containerfile', 'container_port', 'webroot', 'command'];

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

            mkdir($targetPath, 0755, true);

            $this->writeGitAppConfig($appName, $targetPath, array_merge($settings, [
                'repo' => $repo,
                'url' => $repoUrl,
                'ref' => $options['ref'] ?? null,
            ]));
        });
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
     *               container_port: int, containerfile: string}
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

        return [
            'runtime' => $runtime,
            'docroot' => $docroot,
            'webroot' => $webroot,
            'container_port' => (int) $containerPort,
            'containerfile' => $containerfile,
            'command' => $command === null ? null : (string) $command,
        ];
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
        $config = [
            'name' => $appName,
            'version' => '1.0.0',
            'description' => 'Git-sourced app from ' . $source['url'],
            'source' => array_merge(['kind' => 'git'], $source),
            'features' => [
                // Postgres is the only database service the compose builder
                // knows how to emit, and these apps generally want MySQL —
                // so an app brings its own database until that is addressed.
                'database' => false,
                'eda' => false,
                'certbot' => false,
                'keycloak' => false,
            ],
            'containers' => [
                'app' => ['port' => (string) $this->generatePort($appName)],
            ],
        ];

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
    protected function buildAppService(string $appName, array $config): array
    {
        $source = $config['source'] ?? [];
        $runtime = $source['runtime'] ?? self::DEFAULT_RUNTIME;
        $defaults = self::RUNTIME_DEFAULTS[$runtime] ?? self::RUNTIME_DEFAULTS[self::DEFAULT_RUNTIME];
        $repo = $source['repo'] ?? $appName;

        return [
            'build' => [
                'context' => './.installer/repos/' . $repo,
                'dockerfile' => $source['containerfile'] ?? 'Containerfile',
            ],
            'container_name' => $appName . '-app',
            'ports' => [
                ($config['containers']['app']['port'] ?? '8080')
                    . ':' . ($source['container_port'] ?? $defaults['container_port']),
            ],
            'networks' => [$appName . '-net'],
        ];
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

        return [
            'version' => '3.8',
            'services' => [
                $appName . '-app' => [
                    'volumes' => [$repoHostDir . ':' . $webroot . ':Z'],
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
