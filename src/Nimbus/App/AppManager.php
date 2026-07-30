<?php

declare(strict_types=1);

namespace Nimbus\App;

/**
 * Generic lifecycle for a containerized app: compose generation, podman
 * up/down/status, deterministic ports, password/vault handling, the
 * apps.json registry, install and delete.
 *
 * Knows nothing about where an app's code came from. Subclasses supply
 * that: MVCAppManager scaffolds from a local template, GitAppManager
 * builds on a cloned repository.
 */
class AppManager
{
    /**
     * Default Event-Driven Ansible image.
     *
     * Publicly pullable (no registry login). Red Hat's
     * registry.redhat.io/ansible-automation-platform-24/de-minimal-rhel9
     * requires Customer Portal credentials — apps that want it can set
     * containers.eda.image in their app.nimbus.json to override this.
     * Must provide both ansible-rulebook and ansible-galaxy, which
     * init-entrypoint.sh invokes under `set -e`.
     */
    public const DEFAULT_EDA_IMAGE = 'quay.io/ansible/ansible-rulebook:latest';

    protected string $baseDir;
    protected string $installerDir;

    public function __construct(string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? getcwd();
        $this->installerDir = $this->baseDir . '/.installer/apps';
    }

    /**
     * Single seam for every shell invocation in the manager hierarchy
     * (podman, podman-compose, git). Tests override this to assert on the
     * commands that would run without touching the host.
     *
     * The static checkPodmanCompose() is the one exception - it has no $this.
     */
    protected function runCommand(string $command): ?string
    {
        return shell_exec($command);
    }

    /**
     * Host port for one of an app's services.
     *
     * Ports are derived from the app name hash so they are stable across
     * recreates and never collide between apps. Public so callers (e.g.
     * ContainerTask) read them here instead of re-deriving the hash.
     */
    public function getServicePort(string $appName, string $service = 'app'): int
    {
        return match ($service) {
            'app' => $this->generatePort($appName),
            'eda' => $this->generateEdaPort($appName),
            'keycloak' => $this->generateKeycloakPort($appName),
            'codeserver' => $this->generateCodeServerPort($appName),
            default => throw new \InvalidArgumentException("Unknown service '$service'"),
        };
    }

    /**
     * Shared spine for every way of creating an app.
     *
     * Owns the ordering that must not diverge between app sources: validate
     * the name, refuse a name whose containers already exist, materialize
     * the instance dir, register it — and on any failure remove the
     * half-built directory and the registry entry before rethrowing.
     *
     * $materialize receives the (not-yet-created) instance path and is
     * responsible for populating it; how it does so is the subclass's
     * business. $registrySource is recorded in apps.json as the app's
     * origin (a template name, or 'git').
     *
     * @param callable(string): void $materialize
     */
    protected function createAppInstance(string $appName, string $registrySource, callable $materialize): bool
    {
        $this->validateAppName($appName);

        $targetPath = $this->installerDir . '/' . $appName;
        if (is_dir($targetPath)) {
            throw new \RuntimeException("App '$appName' already exists");
        }

        $this->assertNoContainerNameCollision($appName);

        try {
            $materialize($targetPath);
            $this->registerApp($appName, $registrySource);
        } catch (\Throwable $e) {
            if (is_dir($targetPath)) {
                $this->deleteDirectory($targetPath);
            }
            $this->unregisterApp($appName);

            throw new \RuntimeException("Failed to create app: " . $e->getMessage(), 0, $e);
        }

        return true;
    }

    /**
     * Resolve this app's passwords (vault → running container → existing
     * data/compose → generate), so re-creating an app does not orphan an
     * existing database volume.
     */
    protected function resolveAppPasswords(string $appName): \Nimbus\Password\PasswordSet
    {
        return $this->passwordManager()->resolvePasswords($appName);
    }

    /**
     * Back newly generated passwords up to the vault. No-op for any strategy
     * that reused existing credentials — they are already stored.
     */
    protected function backupPasswordsToVault(string $appName, \Nimbus\Password\PasswordSet $passwords): void
    {
        if ($passwords->strategy === \Nimbus\Password\PasswordStrategy::GENERATE_NEW) {
            $this->passwordManager()->backupToVault($appName, $passwords);
        }
    }

    protected function passwordManager(): \Nimbus\Password\PasswordManager
    {
        return new \Nimbus\Password\PasswordManager($this->getVaultManager(), $this->baseDir);
    }

    /**
     * Update app.nimbus.json with features and password strategy
     */
    protected function updateAppConfigJson(
        string $targetPath,
        string $appName,
        \Nimbus\Password\PasswordSet $passwords,
        array $placeholders,
        array $config
    ): void {
        $appConfigPath = $targetPath . '/app.nimbus.json';
        
        if (!file_exists($appConfigPath)) {
            return;
        }
        
        $appConfig = json_decode(file_get_contents($appConfigPath), true);
        
        // Store password strategy information
        $appConfig['password_strategy'] = $passwords->strategy->value;
        if ($passwords->requiresForceInit) {
            $appConfig['force_init'] = true;
        }
        
        // Update database password with resolved password
        if (!isset($appConfig['database'])) {
            $appConfig['database'] = [];
        }
        $appConfig['database']['password'] = $passwords->databasePassword;
        
        // Merge features
        if (!empty($config['features'])) {
            foreach ($config['features'] as $feature => $enabled) {
                $appConfig['features'][$feature] = $enabled;
            }
        }
        
        // Add Keycloak configuration if enabled
        if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            $appConfig['keycloak'] = [
                'realm' => $placeholders['{{KEYCLOAK_REALM}}'],
                'client_id' => $placeholders['{{KEYCLOAK_CLIENT_ID}}'],
                'client_secret' => $placeholders['{{KEYCLOAK_CLIENT_SECRET}}'],
                'auth_url' => "http://{$appName}-keycloak:8080",
                'redirect_uri' => "http://localhost:" . $placeholders['{{APP_PORT}}'] . "/auth/callback"
            ];
            
            // Add Keycloak container configuration
            $appConfig['containers']['keycloak'] = [
                'image' => 'quay.io/keycloak/keycloak:latest',
                'port' => (string)$placeholders['{{KEYCLOAK_PORT}}'],
                'admin_user' => 'admin',
                'admin_password' => $placeholders['{{KEYCLOAK_ADMIN_PASSWORD}}'],
                'database' => 'keycloak_db'
            ];
            
            $appConfig['containers']['keycloak-db'] = [
                'image' => 'postgres:14',
                'database' => 'keycloak_db',
                'user' => 'keycloak',
                'password' => $placeholders['{{KEYCLOAK_DB_PASSWORD}}']
            ];
        }
        
        file_put_contents($appConfigPath, json_encode($appConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Install an app (copy files to active directories)
     */
    public function install(string $appName): bool
    {
        $appPath = $this->installerDir . '/' . $appName;
        
        if (!is_dir($appPath)) {
            throw new \RuntimeException("App '$appName' not found");
        }
        
        $config = $this->loadAppConfig($appName);
        
        // Copy assets based on config. Apps that ship their own build context
        // (git-sourced) declare no asset map — install is then compose-only.
        foreach (($config['assets'] ?? []) as $asset => $paths) {
            $source = $appPath . '/' . $paths['source'];
            $target = $this->baseDir . '/' . $paths['target'];
            
            if (isset($paths['isFile']) && $paths['isFile']) {
                $this->copyFile($source, $target);
            } else {
                $this->copyDirectory($source, $target);
            }
        }
        
        // Generate podman-compose file (passwords will be resolved from config)
        $this->generatePodmanCompose($appName, $config);
        
        return true;
    }
    
    /**
     * List available apps
     *
     * Self-healing: apps.json is a registry that only stays in sync with
     * .installer/apps/ on the happy create/delete path — anything else that
     * removes an app directory (manual rm -rf, an interrupted delete, etc.)
     * leaves a stale "ghost" entry behind. Rather than let every caller
     * (nimbus:list, nimbus:delete, ...) trust a registry that might be lying,
     * prune ghosts here and persist the cleanup, so the fix applies once and
     * every caller of listApps() automatically sees only real apps.
     */
    public function listApps(): array
    {
        $appsFile = $this->baseDir . '/.installer/apps.json';

        if (!file_exists($appsFile)) {
            return [];
        }

        $registry = json_decode(file_get_contents($appsFile), true);
        $apps = $registry['apps'] ?? [];

        $ghosts = array_filter(
            array_keys($apps),
            fn (string $name) => !is_dir($this->installerDir . '/' . $name)
        );

        foreach ($ghosts as $name) {
            unset($apps[$name]);
        }

        // Drop the retired 'installed' flag from registries written by older
        // versions. Nothing ever set it to true, so it always claimed every
        // app was uninstalled; state is derived now. One-time, idempotent.
        $stale = false;
        foreach ($apps as $name => $entry) {
            if (array_key_exists('installed', $entry)) {
                unset($apps[$name]['installed']);
                $stale = true;
            }
        }

        if (!empty($ghosts) || $stale) {
            $registry['apps'] = $apps;
            file_put_contents($appsFile, json_encode($registry, JSON_PRETTY_PRINT));
        }

        return $apps;
    }

    /**
     * Every registered app with its current state, for listings.
     *
     * State is derived, never stored. Container state comes from a single
     * podman call shared across all apps rather than one per app, so a long
     * list costs the same as a short one. Matching is by compose project
     * label, not name prefix — an app called "yo" must not pick up "yo-sup"'s
     * containers.
     *
     * @return list<array{name: string, source: string, state: string,
     *                    running: int, total: int, port: string|null}>
     */
    public function describeApps(): array
    {
        $apps = $this->listApps();
        if (empty($apps)) {
            return [];
        }

        $byProject = $this->containerStatesByProject();

        $rows = [];
        foreach ($apps as $name => $info) {
            $containers = $byProject[$name] ?? [];
            $total = count($containers);
            $running = count(array_filter($containers, fn ($state) => $state === 'running'));

            if ($total > 0) {
                $state = $running === 0
                    ? 'stopped'
                    : ($running === $total ? 'running' : 'partial');
            } else {
                $state = file_exists($this->baseDir . '/' . $name . '-compose.yml')
                    ? 'installed'
                    : 'created';
            }

            $rows[] = [
                'name' => $name,
                'source' => $info['template'] ?? 'unknown',
                'state' => $state,
                'running' => $running,
                'total' => $total,
                'port' => $this->appPort($name),
            ];
        }

        return $rows;
    }

    /**
     * Whether the generated runtime files this app needs to start are on disk.
     */
    public function isInstalled(string $appName): bool
    {
        return is_file($this->baseDir . '/' . $appName . '-compose.yml');
    }

    /**
     * The row describeApps() produces, for a single app.
     *
     * @return array{name: string, source: string, state: string, running: int, total: int, port: ?string}|null
     */
    public function describeApp(string $appName): ?array
    {
        foreach ($this->describeApps() as $row) {
            if ($row['name'] === $appName) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Container state for every compose project on the host, in one call.
     *
     * @return array<string, array<string, string>> project => [container => state]
     */
    protected function containerStatesByProject(): array
    {
        $format = '{{ index .Labels "io.podman.compose.project"}}|{{.Names}}|{{.State}}';
        $output = $this->runCommand('podman ps -a --format ' . escapeshellarg($format) . ' 2>/dev/null');

        $map = [];
        foreach (explode("\n", trim((string) $output)) as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) < 3) {
                continue;
            }

            [$project, $container, $state] = $parts;
            // podman prints <no value> for containers with no such label
            if ($project === '' || $project === '<no value>') {
                continue;
            }

            $map[$project][$container] = $state;
        }

        return $map;
    }

    /**
     * The app's published host port, read from its config. Null when the app
     * has no config yet or does not declare one.
     */
    protected function appPort(string $appName): ?string
    {
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        if (!is_file($configFile)) {
            return null;
        }

        $config = json_decode((string) file_get_contents($configFile), true);
        $port = is_array($config) ? ($config['containers']['app']['port'] ?? null) : null;

        return $port === null ? null : (string) $port;
    }
    
    /**
     * Check if an app exists
     */
    public function appExists(string $appName): bool
    {
        $apps = $this->listApps();
        return isset($apps[$appName]);
    }
    
    
    
    /**
     * Generate container configuration
     */
    public function generateContainers(string $appName): string
    {
        $config = $this->loadAppConfig($appName);
        
        // Extract passwords from config to avoid re-resolving
        $passwords = $this->extractPasswordsFromConfig($appName);
        
        $compose = $this->buildComposeConfig($appName, $config, $passwords);
        
        $yamlContent = $this->arrayToYaml($compose);
        $filename = $this->baseDir . '/' . $appName . '-compose.yml';
        
        file_put_contents($filename, $yamlContent);
        
        return $filename;
    }
    
    /**
     * Load app configuration
     */
    public function loadAppConfig(string $appName): array
    {
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config file not found for app '$appName'");
        }
        
        return json_decode(file_get_contents($configFile), true);
    }
    
    /**
     * Build compose configuration
     */
    protected function buildComposeConfig(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $compose = [
            'version' => '3.8',
            'name' => $appName,
            'networks' => [
                $appName . '-net' => ['driver' => 'bridge']
            ],
            'services' => []
        ];
        
        // App container. depends_on is built up per enabled feature below;
        // an app with no database must not reference the missing -db service.
        $compose['services'][$appName . '-app'] = $this->buildAppService($appName, $config, $passwords);

        // Database container
        $hasDatabase = $config['features']['database'] ?? true;
        if ($hasDatabase) {
            $compose['services'][$appName . '-app']['depends_on'][] = $appName . '-db';
            $compose['services'][$appName . '-db'] = $this->buildDatabaseService($appName, $config, $passwords);

            // Engines that keep a named data volume need it declared at the
            // top level (see buildDatabaseService for why Postgres does not).
            $engine = \Nimbus\Database\DatabaseEngine::fromConfig($config);
            if (!$engine->isPostgres()) {
                $compose['volumes'] = [$this->databaseVolumeName($appName) => []];
            }
        }
        
        // EDA container
        if ($config['features']['eda'] ?? false) {
            $edaImage = $config['containers']['eda']['image'] ?? self::DEFAULT_EDA_IMAGE;
            $rulebooksDir = $config['containers']['eda']['rulebooks_dir'] ?? 'rulebooks';
            $edaPort = $this->generateEdaPort($appName);
            
            $compose['services'][$appName . '-eda'] = [
                'image' => $edaImage,
                'container_name' => $appName . '-eda',
                'ports' => [$edaPort . ':5000'],
                'volumes' => [
                    './.installer/apps/' . $appName . '/eda/rulebooks:/rulebooks:Z',
                    './.installer/apps/' . $appName . '/eda/playbooks:/playbooks:Z',
                    './.installer/apps/' . $appName . '/inventory:/inventory:Z',
                    './.installer/apps/' . $appName . '/logs:/logs:Z',
                    './.installer/apps/' . $appName . '/init-entrypoint.sh:/init-entrypoint.sh:Z'
                ],
                'working_dir' => '/rulebooks',
                'entrypoint' => ['sh', '/init-entrypoint.sh'],
                'restart' => 'unless-stopped',
                'networks' => [$appName . '-net']
            ];

            // The configure-keycloak playbook authenticates against the
            // Keycloak admin API using KEYCLOAK_ADMIN_PASSWORD from the EDA
            // container's environment (lookup('env', ...)).
            if ($config['features']['keycloak'] ?? false) {
                $compose['services'][$appName . '-eda']['environment'] = [
                    'KEYCLOAK_ADMIN_PASSWORD' => $passwords
                        ? $passwords->keycloakAdminPassword
                        : ($config['containers']['keycloak']['admin_password'] ?? 'admin')
                ];
            }

            // EDA waits for the database only when the app actually has one
            if ($hasDatabase) {
                $compose['services'][$appName . '-eda']['depends_on'] = [
                    $appName . '-db' => [
                        'condition' => 'service_healthy'
                    ]
                ];
            }
        }
        
        // Keycloak containers
        if ($config['features']['keycloak'] ?? false) {
            $keycloakServices = $this->buildKeycloakServices($appName, $config, $passwords);
            $compose['services'] = array_merge($compose['services'], $keycloakServices);

            // App waits for Keycloak regardless of whether a database is present
            $compose['services'][$appName . '-app']['depends_on'][] = $appName . '-keycloak';
        }
        
        return $compose;
    }
    
    /**
     * The app's own service block, minus the depends_on entries
     * buildComposeConfig() adds per enabled feature.
     *
     * Default: build the framework image from the repo root, which bakes the
     * app in at build time. Managers whose code lives elsewhere (a git clone)
     * override this to point the build somewhere else.
     *
     * $passwords is passed for managers that inject resolved credentials into
     * the app's own environment; the framework image reads its configuration
     * from app.config.php instead, so this implementation ignores it.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function buildAppService(string $appName, array $config, ?\Nimbus\Password\PasswordSet $passwords = null): array
    {
        return [
            'build' => [
                'context' => '.',
                'args' => [
                    'APP_NAME' => $appName
                ]
            ],
            'container_name' => $appName . '-app',
            'ports' => [$config['containers']['app']['port'] . ':8080'],
            // No volumes: nothing reads /var/www/.installer at runtime, and
            // mounting the instance dir exposed app.nimbus.json (plaintext
            // passwords) inside the container. Dev mode adds its own mounts
            // via the compose.dev.yml overlay.
            'networks' => [$appName . '-net']
        ];
    }

    /**
     * Browser-based editor sidecar, sharing whichever host directory holds
     * the app's editable source.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function buildCodeServerService(string $appName, array $config, string $hostDir): array
    {
        return [
            'image' => 'codercom/code-server:latest',
            'container_name' => $appName . '-code-server',
            'ports' => [
                ($config['containers']['codeserver']['port'] ?? $this->generateCodeServerPort($appName)) . ':8080'
            ],
            'volumes' => [
                $hostDir . ':/home/coder/workspace:Z'
            ],
            'environment' => [
                'PASSWORD' => $config['containers']['codeserver']['password'] ?? ''
            ],
            'command' => ['--bind-addr', '0.0.0.0:8080', '/home/coder/workspace'],
            'networks' => [$appName . '-net']
        ];
    }

    /**
     * Build database service configuration with PasswordSet
     *
     * Everything engine-specific — image, container name, environment variable
     * names, health check — comes from DatabaseEngine. Apps that declare no
     * engine resolve to Postgres, which is what this method used to hardcode.
     */
    protected function buildDatabaseService(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $engine = \Nimbus\Database\DatabaseEngine::fromConfig($config);

        $dbName = $config['database']['name'] ?? $appName . '_db';
        $dbUser = $config['database']['user'] ?? $appName . '_user';

        $dbEnvironment = $engine->environment(
            $dbName,
            $dbUser,
            $passwords ? $passwords->databasePassword : ($config['database']['password'] ?? ''),
            $passwords ? $passwords->databaseRootPassword : ''
        );

        $dbVolumes = [];

        // Only mounted when the app actually ships a schema: the MVC templates
        // do, a bare git repository does not, and podman would silently create
        // a directory where the file was expected.
        if (is_file($this->installerDir . '/' . $appName . '/database/schema.sql')) {
            $dbVolumes[] = './.installer/apps/' . $appName . '/database/schema.sql:/docker-entrypoint-initdb.d/schema.sql:Z';
        }

        // Postgres keeps its historical layout, where the data volume is
        // commented out — adding one now would change the compose file of
        // every app that already exists. Engines added since get a named
        // volume so their data survives the container being recreated.
        if (!$engine->isPostgres()) {
            $dbVolumes[] = $this->databaseVolumeName($appName) . ':' . $engine->dataDir();
        }

        // Add force init for vault restore with existing data
        if ($passwords && $passwords->requiresForceInit) {
            $dbEnvironment['FORCE_INIT'] = 'true';
            $dbVolumes[] = './.installer/apps/' . $appName . '/database/force-init.sh:/docker-entrypoint-initdb.d/force-init.sh:Z';
        }

        $service = [
            'image' => $engine->image,
            'container_name' => $engine->containerName($appName),
            'environment' => $dbEnvironment,
        ];

        if ($dbVolumes !== []) {
            $service['volumes'] = $dbVolumes;
        }

        $service['networks'] = [$appName . '-net'];
        $service['healthcheck'] = [
            'test' => ['CMD-SHELL', $engine->healthcheckCmd($dbUser, $dbName)],
            'interval' => '5s',
            'timeout' => '5s',
            'retries' => 5
        ];

        return $service;
    }

    /**
     * Named volume backing the database's data directory.
     */
    protected function databaseVolumeName(string $appName): string
    {
        return $appName . '-db-data';
    }
    
    /**
     * Build Keycloak services with PasswordSet support
     */
    protected function buildKeycloakServices(string $appName, array $config, \Nimbus\Password\PasswordSet $passwords = null): array
    {
        $services = [];
        
        // Keycloak database container
        $services[$appName . '-keycloak-db'] = [
            'image' => $config['containers']['keycloak-db']['image'] ?? 'postgres:14',
            'container_name' => $appName . '-keycloak-db',
            'environment' => [
                'POSTGRES_DB' => $config['containers']['keycloak-db']['database'] ?? 'keycloak_db',
                'POSTGRES_USER' => $config['containers']['keycloak-db']['user'] ?? 'keycloak',
                'POSTGRES_PASSWORD' => $passwords ? $passwords->keycloakDbPassword : ($config['containers']['keycloak-db']['password'] ?? 'keycloak')
            ],
            'volumes' => [
                './data/' . $appName . '-keycloak:/var/lib/postgresql/data:Z'
            ],
            'networks' => [$appName . '-net'],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'pg_isready -U keycloak -d keycloak_db'],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 5
            ]
        ];
        
        // Keycloak container with auto-configuration
        $keycloakHostPort = $config['containers']['keycloak']['port'] ?? $this->generateKeycloakPort($appName);
        $services[$appName . '-keycloak'] = [
            'image' => $config['containers']['keycloak']['image'] ?? 'quay.io/keycloak/keycloak:latest',
            'container_name' => $appName . '-keycloak',
            'command' => ['start-dev'],
            'environment' => [
                'KC_DB' => 'postgres',
                'KC_DB_URL' => 'jdbc:postgresql://' . $appName . '-keycloak-db:5432/keycloak_db',
                'KC_DB_USERNAME' => $config['containers']['keycloak-db']['user'] ?? 'keycloak',
                'KC_DB_PASSWORD' => $passwords ? $passwords->keycloakDbPassword : ($config['containers']['keycloak-db']['password'] ?? 'keycloak'),
                'KEYCLOAK_ADMIN' => $config['containers']['keycloak']['admin_user'] ?? 'admin',
                'KEYCLOAK_ADMIN_PASSWORD' => $passwords ? $passwords->keycloakAdminPassword : ($config['containers']['keycloak']['admin_password'] ?? 'admin'),
                // Pin the frontend issuer to the host-published URL so tokens
                // authorized in the browser (localhost:<port>) validate on
                // server-side calls too. Without this, Keycloak derives the
                // issuer per-request: the app's userinfo call arriving via the
                // container hostname rejects browser-issued tokens with 401
                // ("Failed to get user information" at login).
                'KC_HOSTNAME' => 'http://localhost:' . $keycloakHostPort,
                // ...while still accepting server-side (backchannel) requests
                // on the container-network hostname.
                'KC_HOSTNAME_BACKCHANNEL_DYNAMIC' => '"true"',
                'KC_HTTP_ENABLED' => '"true"'
            ],
            'volumes' => [
                './.installer/apps/' . $appName . '/keycloak-init.sh:/opt/keycloak/keycloak-init.sh:Z'
            ],
            'ports' => [$keycloakHostPort . ':8080'],
            'depends_on' => [
                $appName . '-keycloak-db' => [
                    'condition' => 'service_healthy'
                ]
            ],
            'networks' => [$appName . '-net'],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'exec 3<>/dev/tcp/127.0.0.1/8080'],
                'interval' => '10s',
                'timeout' => '5s',
                'retries' => 10,
                'start_period' => '40s'
            ]
        ];
        
        // Keycloak auto-configurator container (runs once then exits)
        $services[$appName . '-keycloak-setup'] = [
            'image' => 'alpine:latest',
            'container_name' => $appName . '-keycloak-setup',
            'environment' => [
                'KEYCLOAK_URL' => 'http://' . $appName . '-keycloak:8080',
                'KEYCLOAK_ADMIN_USER' => $config['containers']['keycloak']['admin_user'] ?? 'admin',
                'KEYCLOAK_ADMIN_PASSWORD' => $passwords ? $passwords->keycloakAdminPassword : ($config['containers']['keycloak']['admin_password'] ?? 'admin'),
                'KEYCLOAK_REALM' => $config['keycloak']['realm'] ?? $appName . '-realm',
                'KEYCLOAK_CLIENT_ID' => $config['keycloak']['client_id'] ?? $appName . '-client',
                'KEYCLOAK_CLIENT_SECRET' => $config['keycloak']['client_secret'] ?? '',
                'APP_NAME' => $appName,
                'APP_PORT' => $config['containers']['app']['port']
            ],
            'volumes' => [
                './.installer/apps/' . $appName . '/keycloak-init.sh:/keycloak-init.sh:Z'
            ],
            'command' => ['sh', '-c', 'apk add --no-cache curl jq && sh /keycloak-init.sh'],
            'depends_on' => [
                $appName . '-keycloak' => [
                    'condition' => 'service_healthy'
                ]
            ],
            'networks' => [$appName . '-net'],
            'restart' => 'never'
        ];
        
        return $services;
    }
    
    /**
     * Copy directory recursively
     */
    protected function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $destPath = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }
    
    /**
     * Copy single file
     */
    protected function copyFile(string $source, string $dest): void
    {
        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        copy($source, $dest);
    }
    
    
    /**
     * Register app in apps.json
     */
    protected function registerApp(string $appName, string $template): void
    {
        $appsFile = $this->baseDir . '/.installer/apps.json';
        $apps = [];
        
        if (file_exists($appsFile)) {
            $apps = json_decode(file_get_contents($appsFile), true);
        }
        
        // No 'installed' flag: stored state goes stale the moment anything
        // happens outside Nimbus. describeApps() derives it instead.
        $apps['apps'][$appName] = [
            'name' => $appName,
            'template' => $template,
            'created' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($appsFile, json_encode($apps, JSON_PRETTY_PRINT));
    }
    
    /**
     * Refuse to create an app whose derived container names are already
     * taken. Container names are a flat, host-wide namespace shared with
     * every other podman workload — without this the collision only
     * surfaces at 'nimbus:up', after create and install have succeeded,
     * as "the container name X is already in use".
     */
    protected function assertNoContainerNameCollision(string $appName): void
    {
        $existing = $this->runCommand("podman ps -a --format '{{.Names}}' 2>/dev/null");
        if (empty($existing)) {
            return;  // podman unavailable or no containers — nothing to check
        }

        $existing = array_filter(array_map('trim', explode("\n", trim($existing))));

        // Every suffix this generator can ever append — features are added
        // after create (nimbus:add-eda / add-keycloak / dev), so checking
        // only the containers enabled today would let a later feature
        // collide with something that was already there.
        $wanted = array_map(
            fn ($suffix) => $appName . $suffix,
            ['-app', '-postgres', '-db', '-eda', '-keycloak', '-keycloak-db', '-keycloak-setup', '-code-server']
        );
        $conflicts = array_values(array_intersect($wanted, $existing));

        if (!empty($conflicts)) {
            throw new \RuntimeException(
                "App '$appName' would use container name(s) already in use: " . implode(', ', $conflicts) . '. '
                . "Choose a different app name, or remove the existing container(s) first."
            );
        }
    }

    /**
     * Names that would silently change what the build does rather than
     * failing loudly: the Dockerfile treats APP_NAME=lkui as "install the
     * default LKUI app instead of this one" (Dockerfile ARG APP_NAME).
     */
    private const RESERVED_APP_NAMES = ['lkui'];

    /**
     * Longest app name whose derived hostnames stay inside a 63-char DNS
     * label. The longest suffix this generator appends is '-keycloak-setup'
     * (15 chars); podman-compose resolves services by those names.
     */
    private const MAX_APP_NAME_LENGTH = 48;

    /**
     * Validate app name.
     *
     * Stricter than "lowercase, numbers, hyphens": every app name becomes
     * container names, a network name, a compose project name and an image
     * tag, so it must satisfy podman's own rule
     * ([a-zA-Z0-9][a-zA-Z0-9_.-]*) after suffixes are appended. The old
     * pattern accepted '-lead' and a bare '-', which create/install accept
     * and podman then rejects at up time ("names must match ...").
     */
    protected function validateAppName(string $name): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
            throw new \InvalidArgumentException(
                "App name must start with a letter or number and contain only lowercase letters, numbers, hyphens, dots, and underscores"
            );
        }

        if (str_ends_with($name, '-') || str_ends_with($name, '_') || str_ends_with($name, '.')) {
            throw new \InvalidArgumentException("App name must not end with a hyphen, underscore, or dot");
        }

        if (strlen($name) > self::MAX_APP_NAME_LENGTH) {
            throw new \InvalidArgumentException(
                'App name must be ' . self::MAX_APP_NAME_LENGTH . ' characters or fewer (derived container hostnames must stay within DNS limits)'
            );
        }

        if (in_array($name, self::RESERVED_APP_NAMES, true)) {
            throw new \InvalidArgumentException("App name '$name' is reserved by the framework — choose another name");
        }
    }
    
    /**
     * Generate unique port based on app name
     */
    protected function generatePort(string $appName): int
    {
        $hash = crc32($appName);
        return 8000 + ($hash % 1000);
    }
    
    /**
     * Generate unique EDA port based on app name
     */
    protected function generateEdaPort(string $appName): int
    {
        $hash = crc32($appName . '_eda');
        return 5000 + ($hash % 1000);
    }
    
    /**
     * Generate unique Keycloak port based on app name
     */
    protected function generateKeycloakPort(string $appName): int
    {
        $hash = crc32($appName . '_keycloak');
        return 9000 + ($hash % 1000);
    }

    /**
     * Generate unique code-server (dev mode) port based on app name
     */
    protected function generateCodeServerPort(string $appName): int
    {
        // 10500-11499: clear of app (8xxx), eda (5xxx) and keycloak (9xxx) bands
        $hash = crc32($appName . '_codeserver');
        return 10500 + ($hash % 1000);
    }
    
    /**
     * Generate secure password
     */
    protected function generatePassword(int $length = 16): string
    {
        // For compatibility, if length is 16, use the original hex method
        if ($length === 16) {
            return bin2hex(random_bytes(16));
        }
        
        // For other lengths, use character-based generation
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    /**
     * Get VaultManager instance
     */
    protected function getVaultManager(): \Nimbus\Vault\VaultManager
    {
        return new \Nimbus\Vault\VaultManager($this->baseDir);
    }

    /**
     * Whether this app has a template to commit live edits back to.
     * False here; MVCAppManager overrides it.
     */
    public function supportsCommit(): bool
    {
        return false;
    }

    /**
     * Copy an app's edits back to the source it was created from.
     *
     * A no-op by default rather than an error, so `nimbus:commit` is safe to
     * run against any app. Callers that want to say something useful about
     * apps with no template should check supportsCommit() first.
     *
     * @return array{committed: string[], skipped: string[]}
     */
    public function commitAppToTemplate(string $appName): array
    {
        return ['committed' => [], 'skipped' => []];
    }

    /**
     * Refuse a feature this kind of app cannot run. No-op here: the base
     * lifecycle supports every feature whose compose block it can build.
     * Subclasses whose apps lack the files a feature mounts override this
     * to fail loudly at the CLI instead of at container start.
     */
    protected function assertFeatureSupported(string $appName, string $feature): void
    {
    }

    /**
     * Fill an app's feature scaffolding after EDA has been enabled
     * (rulebooks, playbooks, inventory, entrypoint).
     *
     * No-op here — the base class knows the directory contract the compose
     * file mounts, not what belongs inside. Template-backed managers
     * override this. NOTE: a bare AppManager therefore enables EDA with
     * empty mounted dirs; that is why AppManagerFactory hands callers a
     * subclass rather than the base.
     */
    /** @param array<string, mixed> $config */
    protected function provisionEdaAssets(string $appName, string $appPath, array $config): void
    {
    }

    /**
     * Fill an app's Keycloak scaffolding after Keycloak has been enabled.
     * No-op here for the same reason as provisionEdaAssets().
     */
    /** @param array<string, mixed> $config */
    protected function provisionKeycloakAssets(string $appName, string $appPath, array $config): void
    {
    }

    /**
     * Keep an app's own runtime config in sync with a feature flip.
     *
     * app.nimbus.json is the machine-readable source of truth and is always
     * written by the caller; this hook exists for managers whose apps carry
     * a second, framework-specific config file. No-op here.
     */
    protected function syncAppRuntimeConfig(string $appPath, string $feature, bool $enabled): void
    {
    }

    /**
     * Create the EDA runtime directories that buildComposeConfig() mounts.
     *
     * Generic: the mount paths are a compose contract, so an app with EDA
     * enabled must have these regardless of where its contents come from.
     */
    protected function createEdaRuntimeDirectories(string $appPath): void
    {
        foreach (['eda/rulebooks', 'eda/playbooks', 'inventory', 'logs'] as $dir) {
            $dirPath = $appPath . '/' . $dir;
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0755, true);
            }
        }
    }


    /**
     * Enable or disable EDA for an existing app
     */
    public function setEda(string $appName, bool $enabled): bool
    {
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        
        if (!file_exists($configFile)) {
            throw new \RuntimeException("App '$appName' not found");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        $config['features']['eda'] = $enabled;
        
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        
        return true;
    }
    
    /**
     * Add EDA functionality to an existing app
     */
    public function addEda(string $appName): bool
    {
        $this->assertFeatureSupported($appName, 'eda');

        $appPath = $this->installerDir . '/' . $appName;
        $configFile = $appPath . '/app.nimbus.json';

        if (!is_dir($appPath)) {
            throw new \RuntimeException("App '$appName' not found");
        }

        if (!file_exists($configFile)) {
            throw new \RuntimeException("App config file not found for '$appName'");
        }

        // Load current config
        $config = json_decode(file_get_contents($configFile), true);

        // Check if EDA is already enabled
        if ($config['features']['eda'] ?? false) {
            throw new \RuntimeException("EDA is already enabled for app '$appName'");
        }

        // Enable EDA in config
        $config['features']['eda'] = true;
        
        // Add EDA container configuration if not present
        if (!isset($config['containers']['eda'])) {
            $config['containers']['eda'] = [
                'image' => self::DEFAULT_EDA_IMAGE,
                'rulebooks_dir' => 'rulebooks'
            ];
        }
        
        // Create the dirs compose mounts, then let the manager fill them
        $this->createEdaRuntimeDirectories($appPath);
        $this->provisionEdaAssets($appName, $appPath, $config);

        // Save updated config
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

        // Keep any framework-specific runtime config in sync
        $this->syncAppRuntimeConfig($appPath, 'eda', true);

        // Regenerate compose file with validation
        $this->regenerateComposeFile($appName, $config);

        return true;
    }

    /**
     * Enable or disable a feature on an existing app.
     *
     * Flips the flag in app.nimbus.json, keeps app.config.php in sync, and
     * regenerates the compose file. Files on disk (eda/, keycloak scripts)
     * are deliberately left untouched so re-enabling is cheap.
     *
     * Only 'eda' and 'keycloak' are supported: 'database' toggling after
     * creation is out of scope (data-loss questions), use --no-db at create.
     */
    /**
     * Give an app a database it was created without.
     *
     * $spec is an engine name or an image reference; null takes the manager's
     * own default. Refuses rather than re-provisioning an app that already has
     * one — re-running this must never roll a password the running database
     * still expects.
     */
    public function addDatabase(string $appName, ?string $spec = null): \Nimbus\Database\DatabaseEngine
    {
        if (!$this->appExists($appName)) {
            throw new \RuntimeException("App '$appName' not found");
        }

        $config = $this->loadAppConfig($appName);

        if ($config['features']['database'] ?? false) {
            throw new \RuntimeException(
                "App '$appName' already has a database ("
                . \Nimbus\Database\DatabaseEngine::fromConfig($config)->image . ').'
            );
        }

        $engine = \Nimbus\Database\DatabaseEngine::fromSpec($spec ?? $this->defaultDatabaseSpec());

        $this->assertDatabaseSupported($appName, $engine);

        $config['features']['database'] = true;
        $config['database'] = array_merge($config['database'] ?? [], [
            'engine' => $engine->name,
            'image' => $engine->image,
            'name' => $this->databaseIdentifier($appName) . '_db',
            'user' => $this->databaseIdentifier($appName) . '_user',
        ]);

        // Written before passwords are resolved: the engine decides which
        // credentials exist and where they are probed for.
        $this->saveAppConfig($appName, $config);

        $passwords = $this->resolveAppPasswords($appName);
        $this->backupPasswordsToVault($appName, $passwords);
        $this->persistDatabaseCredentials($appName, $passwords);

        $this->regenerateComposeFile($appName, $this->loadAppConfig($appName));

        return $engine;
    }

    /**
     * The environment an app's container runs with, grouped by where each
     * value comes from.
     *
     * Managers whose apps read their configuration some other way — the
     * framework image reads app.config.php at request time — report nothing.
     *
     * @return array{derived: array<string, string>, stored: array<string, string>, secrets: array<string, string>, dotenv: ?string}
     */
    public function describeEnvironment(string $appName): array
    {
        return ['derived' => [], 'stored' => [], 'secrets' => [], 'dotenv' => null];
    }

    /**
     * Engine an app gets when it asks for a database without naming one.
     */
    protected function defaultDatabaseSpec(): string
    {
        return \Nimbus\Database\DatabaseEngine::DEFAULT_ENGINE;
    }

    /**
     * Hook for managers with a precondition on having a database at all.
     */
    protected function assertDatabaseSupported(string $appName, \Nimbus\Database\DatabaseEngine $engine): void
    {
    }

    /**
     * Record the resolved database password wherever this manager's apps read
     * it from. Template apps read app.config.php / app.nimbus.json; managers
     * that keep credentials only in the vault override this to do nothing.
     */
    protected function persistDatabaseCredentials(string $appName, \Nimbus\Password\PasswordSet $passwords): void
    {
        $config = $this->loadAppConfig($appName);
        $config['database']['password'] = $passwords->databasePassword;

        $this->saveAppConfig($appName, $config);
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
     * @param array<string, mixed> $config
     */
    protected function saveAppConfig(string $appName, array $config): void
    {
        file_put_contents(
            $this->installerDir . '/' . $appName . '/app.nimbus.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function setFeature(string $appName, string $feature, bool $enabled): bool
    {
        if (!in_array($feature, ['eda', 'keycloak'], true)) {
            throw new \InvalidArgumentException("Unsupported feature '$feature' (supported: eda, keycloak)");
        }

        $this->assertFeatureSupported($appName, $feature);

        if (!$this->appExists($appName)) {
            throw new \RuntimeException("App '$appName' not found");
        }

        $appPath = $this->installerDir . '/' . $appName;
        $configFile = $appPath . '/app.nimbus.json';
        if (!file_exists($configFile)) {
            throw new \RuntimeException("App config file not found for '$appName'");
        }

        $config = json_decode(file_get_contents($configFile), true);

        if (($config['features'][$feature] ?? false) === $enabled) {
            $state = $enabled ? 'enabled' : 'disabled';
            throw new \RuntimeException(ucfirst($feature) . " is already $state for app '$appName'");
        }

        $config['features'][$feature] = $enabled;
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

        // Keep any framework-specific runtime config in sync
        $this->syncAppRuntimeConfig($appPath, $feature, $enabled);

        $this->regenerateComposeFile($appName, $config);

        return true;
    }





    
    
    
    /**
     * Regenerate compose file with YAML validation
     */
    protected function regenerateComposeFile(string $appName, array $config): void
    {
        // Keep the installed app config as the source of truth. Resolving from
        // an existing compose file can preserve stale credentials and leave the
        // mounted app.config.php unable to connect to its own database.
        $passwords = $this->extractPasswordsFromConfig($appName);
        
        $compose = $this->buildComposeConfig($appName, $config, $passwords);
        $yamlContent = $this->arrayToYaml($compose);
        
        // Validate YAML before writing
        if (!$this->validateYaml($yamlContent)) {
            throw new \RuntimeException("Generated YAML is invalid");
        }
        
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';
        file_put_contents($composeFile, $yamlContent);

        // Same reasoning as generatePodmanCompose(): this file carries
        // credentials in the clear (IA-5).
        chmod($composeFile, 0600);
    }
    
    /**
     * Validate YAML content
     */
    protected function validateYaml(string $yamlContent): bool
    {
        try {
            // Basic YAML validation - check for common syntax errors
            $lines = explode("\n", $yamlContent);
            $indentStack = [];
            
            foreach ($lines as $lineNum => $line) {
                $trimmed = trim($line);
                
                // Skip empty lines and comments
                if (empty($trimmed) || $trimmed[0] === '#') {
                    continue;
                }
                
                // Check for basic YAML syntax issues
                if (strpos($line, "\t") !== false) {
                    throw new \Exception("YAML cannot contain tabs (line " . ($lineNum + 1) . ")");
                }
                
                // Check for proper list formatting (double dash at start of line)
                if (preg_match('/^\s*-\s*-\s/', $line)) {
                    throw new \Exception("Invalid list formatting (line " . ($lineNum + 1) . ")");
                }
                
                // Check for colon placement
                if (preg_match('/^\s*[^:]+::/', $line)) {
                    throw new \Exception("Invalid colon usage (line " . ($lineNum + 1) . ")");
                }
            }
            
            // Additional validation: try parsing with a simple YAML-like parser
            return $this->basicYamlParse($yamlContent);
            
        } catch (\Exception $e) {
            error_log("YAML validation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Basic YAML structure validation
     */
    private function basicYamlParse(string $yamlContent): bool
    {
        $lines = explode("\n", $yamlContent);
        $bracketStack = [];
        
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }
            
            // Count brackets/braces for structure validation
            $openBrackets = substr_count($line, '[');
            $closeBrackets = substr_count($line, ']');
            $openBraces = substr_count($line, '{');
            $closeBraces = substr_count($line, '}');
            
            // Basic bracket matching
            if ($openBrackets !== $closeBrackets && 
                (strpos($line, '[') !== false || strpos($line, ']') !== false)) {
                return false;
            }
            
            if ($openBraces !== $closeBraces && 
                (strpos($line, '{') !== false || strpos($line, '}') !== false)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get available apps that can be started
     */
    public function getStartableApps(): array
    {
        $startableApps = [];
        $composeFiles = glob($this->baseDir . '/*-compose.yml');
        
        foreach ($composeFiles as $composeFile) {
            $basename = basename($composeFile, '-compose.yml');
            $appPath = $this->installerDir . '/' . $basename;
            
            if (is_dir($appPath)) {
                $runningStatus = $this->checkAppRunningStatus($basename);
                
                $startableApps[] = [
                    'name' => $basename,
                    'compose_file' => $composeFile,
                    'has_image' => $this->checkImageExists($basename),
                    'is_running' => $runningStatus['is_running'],
                    'containers' => $runningStatus['containers'],
                    'health_status' => $runningStatus['health_status']
                ];
            }
        }
        
        return $startableApps;
    }
    
    /**
     * Check if app image exists
     */
    private function checkImageExists(string $appName): bool
    {
        $imageName = $appName . '_' . $appName . '-app';
        $output = $this->runCommand("podman images -q $imageName 2>/dev/null");
        return !empty(trim($output ?? ''));
    }
    
    /**
     * Check if app is running and get container health status
     */
    private function checkAppRunningStatus(string $appName): array
    {
        $containers = $this->getAppContainers($appName);
        $databaseContainer = $this->databaseContainerName($appName);
        $runningContainers = 0;
        $healthyContainers = 0;
        $totalContainers = count($containers);
        $containerDetails = [];
        
        foreach ($containers as $containerName) {
            $status = $this->getContainerStatus($containerName);
            $containerDetails[$containerName] = $status;
            
            if ($status['state'] === 'running') {
                $runningContainers++;
                
                // Consider container healthy if it's running and either:
                // - Has no health check (health = 'none')
                // - Has a health check and is healthy
                // - Health check is starting up
                if ($status['health'] === 'healthy' ||
                    $status['health'] === 'none' ||
                    ($status['health'] === 'starting' && $containerName !== $databaseContainer)) {
                    $healthyContainers++;
                }
            }
        }
        
        $isRunning = $runningContainers > 0;
        $healthStatus = 'unknown';
        
        if ($totalContainers === 0) {
            $healthStatus = 'no-containers';
        } elseif ($runningContainers === 0) {
            $healthStatus = 'stopped';
        } elseif ($runningContainers === $totalContainers && $healthyContainers === $totalContainers) {
            $healthStatus = 'healthy';
        } elseif ($runningContainers === $totalContainers) {
            $healthStatus = 'running-unhealthy';
        } else {
            $healthStatus = 'partial';
        }
        
        return [
            'is_running' => $isRunning,
            'containers' => $containerDetails,
            'health_status' => $healthStatus,
            'running_count' => $runningContainers,
            'total_count' => $totalContainers,
            'healthy_count' => $healthyContainers
        ];
    }
    
    /**
     * Get expected container names for an app
     */
    protected function getAppContainers(string $appName): array
    {
        $containers = [
            $appName . '-app'
        ];

        // Feature-gated containers, read from app.nimbus.json
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = file_exists($configFile)
            ? json_decode(file_get_contents($configFile), true)
            : [];

        if ($config['features']['database'] ?? true) {
            // Engine-derived: '-postgres' for apps that declare no engine
            $containers[] = \Nimbus\Database\DatabaseEngine::fromConfig(is_array($config) ? $config : [])
                ->containerName($appName);
        }
        if ($config !== []) {
            if ($config['features']['eda'] ?? false) {
                $containers[] = $appName . '-eda';
            }
            // Check if Keycloak is enabled for this app
            if ($config['features']['keycloak'] ?? false) {
                $containers[] = $appName . '-keycloak';
                $containers[] = $appName . '-keycloak-db';
            }
            // Dev mode adds a code-server sidecar via the dev overlay
            if ($config['features']['dev'] ?? false) {
                $containers[] = $appName . '-code-server';
            }
        }
        
        return $containers;
    }
    
    /**
     * The database container name for an app, derived from the engine it
     * declares. Apps with no declared engine resolve to '<app>-postgres'.
     */
    protected function databaseContainerName(string $appName): string
    {
        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = file_exists($configFile)
            ? json_decode((string) file_get_contents($configFile), true)
            : [];

        return \Nimbus\Database\DatabaseEngine::fromConfig(is_array($config) ? $config : [])
            ->containerName($appName);
    }

    /**
     * Live container info for an app: the feature-derived expected list
     * (getAppContainers) merged with what podman actually has. One podman ps
     * call, keyed by the compose project label. Also surfaces orphans —
     * containers still in the project after their feature was disabled.
     * Adding a new feature container to the ecosystem means extending
     * getAppContainers() + the compose generation; view/status/up/down all
     * follow from those.
     *
     * @return array<int, array{name: string, expected: bool, exists: bool, image: string, status: string, ports: string}>
     */
    public function describeContainers(string $appName): array
    {
        $ps = $this->runCommand("podman ps -a --filter label=io.podman.compose.project=$appName --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null") ?? '';

        $live = [];
        foreach (array_filter(explode("\n", trim($ps))) as $line) {
            $parts = explode("\t", $line);
            $live[$parts[0]] = [
                'image' => $parts[1] ?? '',
                'status' => $parts[2] ?? '',
                'ports' => $parts[3] ?? ''
            ];
        }

        $out = [];
        foreach ($this->getAppContainers($appName) as $name) {
            $out[$name] = [
                'name' => $name,
                'expected' => true,
                'exists' => isset($live[$name])
            ] + ($live[$name] ?? ['image' => '', 'status' => 'not created', 'ports' => '']);
        }
        foreach ($live as $name => $info) {
            if (!isset($out[$name])) {
                $out[$name] = ['name' => $name, 'expected' => false, 'exists' => true] + $info;
            }
        }

        return array_values($out);
    }

    /**
     * Get detailed status of a container
     */
    private function getContainerStatus(string $containerName): array
    {
        $inspectOutput = $this->runCommand("podman inspect $containerName --format '{{.State.Status}}|{{.State.Health.Status}}' 2>/dev/null");
        
        if (!$inspectOutput) {
            return [
                'name' => $containerName,
                'state' => 'not-found',
                'health' => 'unknown'
            ];
        }
        
        $parts = explode('|', trim($inspectOutput));
        $state = $parts[0] ?? 'unknown';
        $health = $parts[1] ?? 'none';
        
        // Clean up health status
        if (empty($health) || $health === '<no value>') {
            $health = 'none';
        }
        
        return [
            'name' => $containerName,
            'state' => $state,
            'health' => $health
        ];
    }
    
    /**
     * The ordered podman-compose -f file list for an app, derived from its
     * features in app.nimbus.json (single source of truth). Base compose
     * always; the dev overlay when features.dev is enabled — regenerated if
     * missing, since overlay files are derived artifacts. Every lifecycle
     * path (up/down/status) must build its file list here so feature
     * containers like code-server are never started-but-not-stopped.
     */
    public function getComposeFiles(string $appName): array
    {
        $files = [$this->baseDir . '/' . $appName . '-compose.yml'];

        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = file_exists($configFile)
            ? json_decode(file_get_contents($configFile), true)
            : [];

        if ($config['features']['dev'] ?? false) {
            $devFile = $this->baseDir . '/' . $appName . '-compose.dev.yml';
            if (!file_exists($devFile)) {
                $this->generateDevCompose($appName);
            }
            $files[] = $devFile;
        }

        return $files;
    }

    /**
     * Stop an app and optionally remove containers/volumes
     */
    public function stopApp(string $appName, array $options = []): array
    {
        $composeFiles = $this->getComposeFiles($appName);

        if (!file_exists($composeFiles[0])) {
            throw new \RuntimeException("Compose file not found for app '$appName'");
        }

        $commands = [];
        $results = ['stopped' => false, 'removed' => false, 'cleaned' => false, 'output' => ''];

        // Build command based on options; include every feature overlay so
        // their containers (e.g. code-server) are brought down too
        $downCommand = 'podman-compose -f ' . implode(' -f ', $composeFiles) . ' down';
        
        if ($options['remove_volumes'] ?? false) {
            $downCommand .= ' --volumes';
        }
        
        if ($options['timeout'] ?? false) {
            $downCommand .= ' --timeout ' . (int)$options['timeout'];
        }
        
        $output = $this->runCommand($downCommand . ' 2>&1');
        $results['output'] = $output;
        $results['stopped'] = true;
        
        // Optional: Remove containers completely
        if ($options['remove_containers'] ?? false) {
            $containers = $this->getAppContainers($appName);
            foreach ($containers as $containerName) {
                $removeOutput = $this->runCommand("podman rm -f $containerName 2>&1");
                $results['output'] .= "\n" . $removeOutput;
            }
            $results['removed'] = true;
        }
        
        // Optional: Clean up images
        if ($options['remove_images'] ?? false) {
            $imageName = $appName . '_' . $appName . '-app';
            $imageOutput = $this->runCommand("podman rmi $imageName 2>&1");
            $results['output'] .= "\n" . $imageOutput;
            $results['cleaned'] = true;
        }
        
        return $results;
    }
    
    /**
     * Get running apps that can be stopped
     */
    public function getRunningApps(): array
    {
        $runningApps = [];
        $startableApps = $this->getStartableApps();
        
        foreach ($startableApps as $app) {
            if ($app['is_running']) {
                $runningApps[] = $app;
            }
        }
        
        return $runningApps;
    }
    
    /**
     * Check if podman-compose is installed
     */
    public static function checkPodmanCompose(): array
    {
        $result = ['installed' => false, 'version' => null, 'error' => null];
        
        $output = shell_exec('podman-compose --version 2>&1');
        
        if ($output && strpos($output, 'podman-compose') !== false) {
            $result['installed'] = true;
            $result['version'] = trim($output);
        } else {
            $result['error'] = 'podman-compose not found. Install it with: pip3 install podman-compose';
        }
        
        return $result;
    }
    
    /**
     * Generate podman-compose.yml file
     */
    protected function generatePodmanCompose(string $appName, array $config): void
    {
        // Extract passwords from the already-generated config instead of resolving again
        $passwords = $this->extractPasswordsFromConfig($appName);
        
        $compose = $this->buildComposeConfig($appName, $config, $passwords);
        $yamlContent = $this->arrayToYaml($compose);

        $file = $this->baseDir . '/' . $appName . '-compose.yml';
        file_put_contents($file, $yamlContent);

        // Database and admin passwords are written into this file in the clear;
        // it is an authenticator store and readable only by its owner (IA-5).
        chmod($file, 0600);
    }

    /**
     * Build the dev-mode compose overlay for an app.
     *
     * The overlay is applied as a second -f file on top of <app>-compose.yml:
     *  - bind-mounts THIS APP'S OWN instance dir (.installer/apps/<name>) as
     *    the served app code, so every dev-mode app is isolated: installing
     *    or editing another app can never swap this one's config/code out
     *    from under it (the old shared ./app mount did exactly that —
     *    "could not translate host name <other-app>-db")
     *  - bind-mounts framework src/ + index.php (correctly shared: identical
     *    for all apps)
     *  - mounts a dev php.ini enabling opcache timestamp revalidation
     *  - overrides the entrypoint to re-dump a non-optimized autoloader so
     *    classes added mid-session resolve via PSR-4
     *  - adds a code-server sidecar editing the same host tree in the browser
     *
     * The instance dir keeps routes at routes/CustomRoutes.php (not the baked
     * layout's app/CustomRoutes.php) — Application::setupRoutes() falls back
     * to that path.
     */
    protected function buildDevOverlay(string $appName, array $config): array
    {
        $instanceDir = './.installer/apps/' . $appName;

        return [
            'version' => '3.8',
            'services' => [
                $appName . '-app' => [
                    'volumes' => [
                        $instanceDir . ':/var/www/app:Z',
                        './src:/var/www/src:Z',
                        './public/index.php:/var/www/html/index.php:Z',
                        './html/assets:/var/www/html/assets:Z',
                        './docker/dev/opcache-dev.ini:/usr/local/etc/php/conf.d/zz-opcache-dev.ini:Z'
                    ],
                    'entrypoint' => ['/bin/sh', '-c', 'composer dump-autoload -d /var/www && apache2-foreground']
                ],
                $appName . '-code-server' => $this->buildCodeServerService($appName, $config, $instanceDir)
            ]
        ];
    }

    /**
     * Generate <app>-compose.dev.yml and persist code-server settings.
     *
     * Returns ['file' => ..., 'port' => ..., 'password' => ...] so callers
     * can print connection details.
     */
    public function generateDevCompose(string $appName): array
    {
        if (!$this->appExists($appName)) {
            throw new \RuntimeException("App '$appName' not found. Create it first with nimbus:create");
        }

        $configFile = $this->installerDir . '/' . $appName . '/app.nimbus.json';
        $config = $this->loadAppConfig($appName);

        // Persist code-server port + password once so they survive regeneration,
        // and flag dev as an app feature so up/down/status include the sidecar
        $dirty = false;
        if (empty($config['containers']['codeserver']['password'])) {
            $config['containers']['codeserver'] = [
                'port' => (string) $this->generateCodeServerPort($appName),
                'password' => $this->generatePassword()
            ];
            $dirty = true;
        }
        if (!($config['features']['dev'] ?? false)) {
            $config['features']['dev'] = true;
            $dirty = true;
        }
        if ($dirty) {
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        }

        $overlay = $this->buildDevOverlay($appName, $config);
        $file = $this->baseDir . '/' . $appName . '-compose.dev.yml';
        file_put_contents($file, $this->arrayToYaml($overlay));

        // Carries the code-server password (and, for git apps, the app's
        // resolved environment) — owner-readable only (IA-5).
        chmod($file, 0600);

        return [
            'file' => $file,
            'port' => $config['containers']['codeserver']['port'],
            'password' => $config['containers']['codeserver']['password']
        ];
    }
    
    /**
     * Extract passwords from already-generated app config to avoid re-resolving
     */
    protected function extractPasswordsFromConfig(string $appName): ?\Nimbus\Password\PasswordSet
    {
        $config = $this->loadAppConfig($appName);
        
        return new \Nimbus\Password\PasswordSet(
            databasePassword: $config['database']['password'] ?? '',
            keycloakAdminPassword: $config['containers']['keycloak']['admin_password'] ?? '',
            keycloakDbPassword: $config['containers']['keycloak-db']['password'] ?? '',
            keycloakClientSecret: $config['keycloak']['client_secret'] ?? '',
            strategy: \Nimbus\Password\PasswordStrategy::from($config['password_strategy'] ?? 'generate_new'),
            baseDir: $this->baseDir,
            appName: $appName
        );
    }
    
    /**
     * Simple array to YAML converter
     */
    protected function arrayToYaml(array $array, int $indent = 0): string
    {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // Check if this is a numeric indexed array (for YAML lists)
                if (array_keys($value) === range(0, count($value) - 1)) {
                    $yaml .= $prefix . $key . ":\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= $prefix . "  - " . trim($this->arrayToYaml($item, $indent + 2)) . "\n";
                        } else {
                            $yaml .= $prefix . "  - " . $item . "\n";
                        }
                    }
                } else {
                    $yaml .= $prefix . $key . ":\n";
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                }
            } else {
                // Handle null values
                if ($value === null) {
                    $yaml .= $prefix . $key . ": null\n";
                } elseif ($this->needsQuoting($value)) {
                    // Quote values that contain special YAML characters
                    $yaml .= $prefix . $key . ': "' . $value . "\"\n";
                } else {
                    $yaml .= $prefix . $key . ': ' . $value . "\n";
                }
            }
        }
        
        return $yaml;
    }
    
    /**
     * Check if a value needs to be quoted in YAML
     */
    private function needsQuoting(mixed $value): bool
    {
        // Numbers and booleans don't need quoting
        if (is_numeric($value) || is_bool($value)) {
            return false;
        }
        
        // Convert to string for checking
        $stringValue = (string) $value;
        
        // Quote if it contains special YAML characters that could cause parsing issues
        return preg_match('/[!@#$%^&*()+=\[\]{}|;:,.<>?~`]/', $stringValue) === 1;
    }
    
    /**
     * Add Keycloak support to an existing app
     */
    public function deleteApp(string $appName, array $options = []): bool
    {
        $appDir = $this->installerDir . '/' . $appName;
        $composeFile = $this->baseDir . '/' . $appName . '-compose.yml';

        if (!is_dir($appDir)) {
            // Directory is already gone (manual cleanup, interrupted prior
            // delete, etc.) — the end state the caller wants (app gone) is
            // already true. Clean up any stragglers (stale registry entry,
            // orphaned compose file, data dir) and report success rather
            // than erroring on a delete that's effectively already done.
            $appsFile = $this->baseDir . '/.installer/apps.json';
            if (file_exists($appsFile)) {
                $registry = json_decode(file_get_contents($appsFile), true);
                if (isset($registry['apps'][$appName])) {
                    unset($registry['apps'][$appName]);
                    file_put_contents($appsFile, json_encode($registry, JSON_PRETTY_PRINT));
                }
            }
            if (file_exists($composeFile)) {
                unlink($composeFile);
            }
            $dataDir = $this->baseDir . '/data/' . $appName;
            if (is_dir($dataDir)) {
                $this->deleteDirectory($dataDir);
            }
            return true;
        }

        // Stop and remove containers first
        if (file_exists($composeFile)) {
            $downCommand = "podman-compose -f $composeFile down";
            if ($options['remove_volumes'] ?? false) {
                $downCommand .= ' --volumes';
            }
            $this->runCommand($downCommand . ' 2>&1');
        }

        // Remove images if requested
        if ($options['remove_images'] ?? false) {
            // Remove the main app image
            $appImageName = $appName . '_' . $appName . '-app';
            $imageOutput = $this->runCommand("podman rmi $appImageName 2>&1");
            
            // Also try to remove any other app-specific images (in case of naming variations)
            $allImages = $this->runCommand("podman images --format '{{.Repository}}' 2>/dev/null");
            if ($allImages) {
                $imageLines = explode("\n", trim($allImages));
                foreach ($imageLines as $image) {
                    // Match only images belonging to THIS app. A bare prefix
                    // match ("$appName-") also matches longer app names —
                    // deleting 'yo' would delete 'yo-sup's images — so the
                    // name must be followed by a compose/tag separator and
                    // then the app's own service suffix.
                    if (preg_match('/^' . preg_quote($appName, '/') . '[_-]' . preg_quote($appName, '/') . '\b/', $image)
                        || $image === $appName) {
                        echo "Deleting image $image...\n";
                        $this->runCommand('podman rmi ' . escapeshellarg($image) . ' 2>&1');
                    }
                }
            }
        }

        // Remove app directory
        $this->deleteDirectory($appDir);

        // Remove from apps registry
        $appsFile = $this->baseDir . '/.installer/apps.json';
        if (file_exists($appsFile)) {
            $apps = json_decode(file_get_contents($appsFile), true);
            unset($apps['apps'][$appName]);
            file_put_contents($appsFile, json_encode($apps, JSON_PRETTY_PRINT));
        }

        // Remove compose file
        if (file_exists($composeFile)) {
            unlink($composeFile);
        }

        // Remove data volumes
        $dataDir = $this->baseDir . '/data/' . $appName;
        if (is_dir($dataDir)) {
            $this->deleteDirectory($dataDir);
        }

        // Remove Keycloak data if exists
        $keycloakDataDir = $this->baseDir . '/data/' . $appName . '-keycloak';
        if (is_dir($keycloakDataDir)) {
            $this->deleteDirectory($keycloakDataDir);
        }

        return true;
    }

    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }

    public function addKeycloak(string $appName, bool $force = false): bool
    {
        $this->assertFeatureSupported($appName, 'keycloak');

        $appDir = $this->installerDir . '/' . $appName;
        if (!is_dir($appDir)) {
            throw new \Exception("App directory not found: $appDir");
        }
        
        // Load app configuration
        $configFile = $appDir . '/app.nimbus.json';
        if (!file_exists($configFile)) {
            throw new \Exception("App configuration not found");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        
        // Check if Keycloak is already enabled (unless force is true)
        if (!$force && isset($config['features']['keycloak']) && $config['features']['keycloak']) {
            throw new \Exception("Keycloak is already enabled for this app");
        }
        
        // Enable Keycloak feature
        $config['features']['keycloak'] = true;
        
        // Use PasswordManager to resolve passwords for Keycloak using NO_MODIFICATIONS strategy
        $passwordManager = new \Nimbus\Password\PasswordManager($this->getVaultManager(), $this->baseDir);
        $passwords = $passwordManager->resolvePasswordsForAddOperation($appName);
        
        // Add Keycloak containers configuration using PasswordSet
        $config['containers']['keycloak'] = [
            'image' => 'quay.io/keycloak/keycloak:latest',
            'port' => (string)$this->generateKeycloakPort($appName),
            'admin_user' => 'admin',
            'admin_password' => $passwords->keycloakAdminPassword,
            'database' => 'keycloak_db'
        ];
        
        $config['containers']['keycloak-db'] = [
            'image' => 'postgres:14',
            'database' => 'keycloak_db',
            'user' => 'keycloak',
            'password' => $passwords->keycloakDbPassword
        ];
        
        // Add Keycloak configuration using PasswordSet
        $config['keycloak'] = [
            'realm' => $appName . '-realm',
            'client_id' => $appName . '-client',
            'client_secret' => $passwords->keycloakClientSecret,
            'auth_url' => "http://{$appName}-keycloak:8080",
            'redirect_uri' => "http://localhost:" . ($config['containers']['app']['port'] ?? '8080') . "/auth/callback"
        ];
        
        // Save updated configuration
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Auto-backup to vault if new passwords were generated
        if ($passwords->strategy === \Nimbus\Password\PasswordStrategy::GENERATE_NEW) {
            $passwordManager->backupToVault($appName, $passwords);
        }
        
        // Update compose file with PasswordSet
        $this->regenerateComposeFile($appName, $config);

        // Copy Keycloak scaffolding (manager-specific)
        $this->provisionKeycloakAssets($appName, $appDir, $config);

        // Keep any framework-specific runtime config in sync
        $this->syncAppRuntimeConfig($appDir, 'keycloak', true);

        return true;
    }
    
    
    
    
    /**
     * Remove app from apps.json registry for cleanup on failed creation.
     *
     * Must read the same file registerApp()/listApps()/deleteApp() write:
     * .installer/apps.json, NOT .installer/apps/apps.json.
     */
    protected function unregisterApp(string $appName): void
    {
        $appsFile = $this->baseDir . '/.installer/apps.json';

        if (!file_exists($appsFile)) {
            return;
        }
        
        $apps = json_decode(file_get_contents($appsFile), true);
        if (isset($apps['apps'][$appName])) {
            unset($apps['apps'][$appName]);
            file_put_contents($appsFile, json_encode($apps, JSON_PRETTY_PRINT));
        }
    }
}
