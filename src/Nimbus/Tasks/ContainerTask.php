<?php

declare(strict_types=1);

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\App\AppManager;
use Nimbus\App\AppManagerFactory;
use Nimbus\UI\InteractiveHelper;
use Composer\Script\Event;

class ContainerTask extends BaseTask
{
    private AppManager $appManager;
    private InteractiveHelper $interactiveHelper;

    public function __construct()
    {
        $this->appManager = new AppManager();
        $this->interactiveHelper = new InteractiveHelper();
    }

    public function execute(Event $event): void
    {
        // Determine which operation to perform based on context
        $this->status($event);
    }

    public function up(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $composeCheck = AppManager::checkPodmanCompose();
        if (!$composeCheck['installed']) {
            echo self::ansiFormat('ERROR', $composeCheck['error']);
            return;
        }
        
        echo self::ansiFormat('INFO', "Using {$composeCheck['version']}");
        
        try {
            $startableApps = $this->appManager->getStartableApps();
            $targetApp = $args[0] ?? null;

            if ($targetApp) {
                $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);

                // App was created but never installed — offer to install now
                if (empty($app) && $this->appManager->appExists($targetApp)) {
                    if (!$io->askConfirmation("App '$targetApp' exists but is not installed. Install it now? [Y/n] ", true)) {
                        echo self::ansiFormat('INFO', "Skipped - run 'composer nimbus:install $targetApp' later");
                        return;
                    }
                    AppManagerFactory::forApp($targetApp)->install($targetApp);
                    echo self::ansiFormat('SUCCESS', "App '$targetApp' installed successfully!");
                    echo self::ansiFormat('INFO', "Container config generated: $targetApp-compose.yml");
                    $startableApps = $this->appManager->getStartableApps();
                    $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);
                }

                if (empty($app)) {
                    echo self::ansiFormat('ERROR', "App '$targetApp' not found or not installed.");
                    return;
                }
                $app = array_values($app)[0];
                $this->startApp($app);
                return;
            }

            if (empty($startableApps)) {
                echo self::ansiFormat('INFO', 'No apps found with compose files.');
                echo self::ansiFormat('INFO', 'Create and install an app first:');
                echo "  1. composer nimbus:create my-app" . PHP_EOL;
                echo "  2. composer nimbus:install my-app" . PHP_EOL;
                return;
            }
            
            echo self::ansiFormat('INFO', 'Available apps to start:');
            $choices = [];
            $index = 1;
            
            foreach ($startableApps as $app) {
                $imageStatus = $app['has_image'] ? '✓ built' : '✗ not built';
                $runningStatus = $this->formatRunningStatus($app);
                $healthStatus = $this->formatHealthStatus($app);
                
                echo "  [$index] {$app['name']} ($imageStatus, $runningStatus, $healthStatus)" . PHP_EOL;
                
                if ($app['is_running']) {
                    foreach ($app['containers'] as $containerName => $status) {
                        $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                        $healthIcon = $this->getHealthIcon($status['health']);
                        echo "      └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
                    }
                }
                
                $choices[$index] = $app;
                $index++;
            }
            
            $choice = $io->ask('Select app to start (number): ');
            
            if (!isset($choices[(int)$choice])) {
                echo self::ansiFormat('ERROR', 'Invalid selection.');
                return;
            }
            
            $selectedApp = $choices[(int)$choice];
            $this->startApp($selectedApp);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to start app: ' . $e->getMessage());
        }
    }

    public function down(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $composeCheck = AppManager::checkPodmanCompose();
        if (!$composeCheck['installed']) {
            echo self::ansiFormat('ERROR', $composeCheck['error']);
            return;
        }
        
        echo self::ansiFormat('INFO', "Using {$composeCheck['version']}");
        
        try {
            $runningApps = $this->appManager->getRunningApps();
            $targetApp = $args[0] ?? null;

            if (empty($runningApps)) {
                echo self::ansiFormat('INFO', 'No running apps found.');
                $this->reportNothingToStop($targetApp);
                return;
            }

            if ($targetApp) {
                $app = array_filter($runningApps, fn($a) => $a['name'] === $targetApp);
                if (empty($app)) {
                    $this->reportNothingToStop($targetApp);
                    return;
                }
                $app = array_values($app)[0];
                $this->stopApp($this->appManager, $app, $io);
                return;
            }
            
            echo self::ansiFormat('INFO', 'Running apps:');
            $choices = [];
            $index = 1;
            
            foreach ($runningApps as $app) {
                $runningStatus = $this->formatRunningStatus($app);
                $healthStatus = $this->formatHealthStatus($app);
                
                echo "  [$index] {$app['name']} ($runningStatus, $healthStatus)" . PHP_EOL;
                
                foreach ($app['containers'] as $containerName => $status) {
                    $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                    $healthIcon = $this->getHealthIcon($status['health']);
                    echo "      └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
                }
                
                $choices[$index] = $app;
                $index++;
            }
            
            echo "  [all] Stop all running apps" . PHP_EOL;
            
            $choice = $io->ask('Select app to stop (number or "all"): ');
            
            if (strtolower($choice ?? '') === 'all') {
                $this->stopAllApps($this->appManager, $runningApps, $io);
                return;
            }
            
            if (!isset($choices[(int)$choice])) {
                echo self::ansiFormat('ERROR', 'Invalid selection.');
                return;
            }
            
            $selectedApp = $choices[(int)$choice];
            $this->stopApp($this->appManager, $selectedApp, $io);
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to stop app: ' . $e->getMessage());
        }
    }

    public function status(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        $composeCheck = AppManager::checkPodmanCompose();
        if (!$composeCheck['installed']) {
            echo self::ansiFormat('ERROR', $composeCheck['error']);
            return;
        }
        
        echo self::ansiFormat('INFO', "Using {$composeCheck['version']}");
        
        $startableApps = $this->appManager->getStartableApps();
        $targetApp = $args[0] ?? null;

        if ($targetApp) {
            $app = array_filter($startableApps, fn($a) => $a['name'] === $targetApp);

            if (empty($app) && !$this->appManager->appExists($targetApp)) {
                echo self::ansiFormat('ERROR', "App '$targetApp' not found.");
                return;
            }

            $installed = !empty($app);
            $running = false;

            echo self::ansiFormat('INFO', 'App Status:');
            if ($installed) {
                $app = array_values($app)[0];
                $running = $app['is_running'];
                $imageStatus = $app['has_image'] ? '✓ built' : '✗ not built';
                $runningStatus = $this->formatRunningStatus($app);
                $healthStatus = $this->formatHealthStatus($app);
                echo "  • {$app['name']} ($imageStatus, $runningStatus, $healthStatus)" . PHP_EOL;

                if ($app['is_running']) {
                    foreach ($app['containers'] as $containerName => $status) {
                        $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                        $healthIcon = $this->getHealthIcon($status['health']);
                        echo "      └─ $stateIcon $containerName: {$status['state']} $healthIcon" . PHP_EOL;
                    }
                }
            } else {
                echo "  • $targetApp (created, not installed)" . PHP_EOL;
            }

            try {
                $features = $this->appManager->loadAppConfig($targetApp)['features'] ?? [];
            } catch (\Exception $e) {
                $features = [];
            }

            $this->interactiveHelper->showCommandMenu($targetApp, $installed, $running, $features);
            return;
        }

        if (empty($startableApps)) {
            echo self::ansiFormat('INFO', 'No apps found with compose files.');
            echo self::ansiFormat('INFO', 'Create and install an app first:');
            echo "  1. composer nimbus:create my-app" . PHP_EOL;
            echo "  2. composer nimbus:install my-app" . PHP_EOL;
            return;
        }

        echo self::ansiFormat('INFO', 'App Status:');
        
        foreach ($startableApps as $app) {
            $imageStatus = $app['has_image'] ? '✓ built' : '✗ not built';
            $runningStatus = $this->formatRunningStatus($app);
            $healthStatus = $this->formatHealthStatus($app);
            
            echo "  • {$app['name']} ($imageStatus, $runningStatus, $healthStatus)" . PHP_EOL;
            
            if ($app['is_running']) {
                foreach ($app['containers'] as $containerName => $status) {
                    $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
                    $healthIcon = $this->getHealthIcon($status['health']);
                    echo "      └─ $stateIcon $containerName: {$status['state']} $healthIcon" . PHP_EOL;
                }
            }
        }
    }

    private function startApp(array $app): void
    {
        $appName = $app['name'];
        // Feature-derived file list (base + overlays); a feature container
        // missing from a running app means health is 'partial', so the
        // already-running shortcut below won't skip starting it
        $composeFlags = '-f ' . implode(' -f ', $this->appManager->getComposeFiles($appName));

        if ($app['is_running'] && $app['health_status'] === 'healthy') {
            echo self::ansiFormat('INFO', "App '$appName' is already running and healthy!");
            $this->showAppView($appName);
            return;
        }

        echo self::ansiFormat('INFO', "Building app '$appName' image...");
        $buildCommand = "podman-compose $composeFlags up --build -d";

        echo self::ansiFormat('INFO', "Running: $buildCommand");

        // Streamed live (a build runs for minutes) AND captured, because the
        // captured text is what failure diagnosis reads. Success used to be
        // inferred from `podman-compose ps --format table` having output —
        // but that command prints nothing here, so a perfectly good build fell
        // straight through and told the user nothing.
        [$exitCode, $output] = $this->runStreamingCommand($buildCommand . ' 2>&1');

        if ($exitCode !== 0) {
            echo PHP_EOL;
            echo self::ansiFormat('ERROR', "Failed to start '$appName' — podman-compose exited $exitCode.");
            $this->printDiagnosis(self::diagnoseStartFailure($appName, $output));
            echo self::ansiFormat('INFO', "Inspect the stack with: composer nimbus:view $appName");

            return;
        }

        if (!$this->waitForContainers($appName)) {
            // podman-compose's exit code is not reliable (1.0.6 keeps going
            // after a failed build), so "it said 0" is not proof of life —
            // the containers actually running is.
            $diagnosis = self::diagnoseStartFailure($appName, $output);

            if ($diagnosis !== []) {
                echo PHP_EOL;
                echo self::ansiFormat('ERROR', "'$appName' did not come up, although podman-compose reported success.");
                $this->printDiagnosis($diagnosis);
                echo self::ansiFormat('INFO', "Inspect the stack with: composer nimbus:view $appName");

                return;
            }

            echo self::ansiFormat('INFO', 'Containers are still starting — the view below may catch them warming up.');
        }

        echo PHP_EOL;
        echo self::ansiFormat('SUCCESS', "App '$appName' started successfully!");
        echo PHP_EOL;

        $this->showAppView($appName);

        // Advisory security pass over the generated stack. Runs as its own
        // throwaway container that is not part of the app, and stays quiet
        // when the scanner image has not been pulled, so it can never turn a
        // working `up` into a slow or failing one.
        (new ScanTask())->scanApp($appName, true);
    }

    /**
     * There was nothing to stop — say where things actually stand instead of
     * leaving a dead end.
     *
     * A named app that exists gets its full view (status, next steps), since
     * "already stopped" begs exactly the questions the view answers; a name
     * that matches nothing gets pointed at the app list.
     */
    private function reportNothingToStop(?string $targetApp): void
    {
        if ($targetApp === null) {
            echo self::ansiFormat('INFO', 'See what exists with: composer nimbus:list');
            return;
        }

        if (!$this->appManager->appExists($targetApp)) {
            echo self::ansiFormat('ERROR', "App '$targetApp' not found.");
            echo self::ansiFormat('INFO', 'See what exists with: composer nimbus:list');
            return;
        }

        echo self::ansiFormat('INFO', "'$targetApp' is already stopped.");
        echo PHP_EOL;
        $this->showAppView($targetApp);
    }

    /**
     * Give the stack a moment to actually be up before reporting on it.
     *
     * `podman-compose up -d` returns once containers are created, not once
     * they are running, so a view printed immediately catches the stack mid
     * wake-up. Polling the same state nimbus:status reads beats a fixed pause:
     * it returns the instant everything is running, and gives up after the
     * timeout instead of hanging on a genuinely wedged container.
     */
    private function waitForContainers(string $appName, int $timeoutSeconds = 15): bool
    {
        $deadline = time() + $timeoutSeconds;

        do {
            if (($this->appManager->describeApp($appName)['state'] ?? null) === 'running') {
                return true;
            }

            sleep(1);
        } while (time() < $deadline);

        return false;
    }

    /**
     * Run a command echoing its output as it arrives while also keeping it,
     * so a long build stays visible live and its text stays available for
     * failure diagnosis.
     *
     * @return array{0: int, 1: string} exit code, combined output
     */
    private function runStreamingCommand(string $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);

        if (!is_resource($process)) {
            return [1, ''];
        }

        $output = '';
        while (($line = fgets($pipes[1])) !== false) {
            echo $line;
            $output .= $line;
        }
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /**
     * @param list<string> $lines
     */
    private function printDiagnosis(array $lines): void
    {
        foreach ($lines as $line) {
            echo '  ' . $line . PHP_EOL;
        }
    }

    /**
     * Translate podman-compose failure output into what actually went wrong.
     *
     * podman-compose (1.0.6) does not stop after a failed build: it carries on
     * to `podman run`, which — having no locally built image to use — asks
     * docker.io for the app's image and is denied, because that image only
     * ever exists locally. Read bottom-up that looks like a registry problem;
     * the cause is at the top of the log. This walks the known signatures in
     * cause order so the report leads with the truth, and only blames the
     * registry when an image Nimbus genuinely needed to pull was refused.
     *
     * Public and static so the signature table is testable against real
     * captured output.
     *
     * @return list<string> explanation lines, empty when nothing is recognized
     */
    public static function diagnoseStartFailure(string $appName, string $output): array
    {
        $lines = [];
        $appImage = $appName . '_' . $appName . '-app';

        $buildFailed = preg_match('/Error: building at STEP "([^"]+)"/', $output, $step) === 1;

        if ($buildFailed) {
            $lines[] = 'The image build failed at: ' . $step[1];

            if (str_contains($output, 'COPY --excludes is not supported')) {
                $lines[] = "This podman does not support `COPY --exclude`, which the repository's Containerfile uses.";
                $lines[] = 'Upgrade podman/buildah, or pass --containerfile= naming one that avoids that flag.';
            }
        }

        // Registry trouble that is NOT about the app's own image — a base
        // image pull being refused or unreachable is a real registry problem.
        $foreign = implode("\n", array_filter(
            explode("\n", $output),
            static fn (string $line): bool => !str_contains($line, $appImage)
        ));

        if (preg_match('/requested access to the resource is denied|unauthorized|authentication required/i', $foreign) === 1) {
            $lines[] = 'A registry refused access while pulling an image the stack needs.';
            $lines[] = 'If the image is private: podman login <registry>. Otherwise the registry may be blocked by policy or a proxy.';
        } elseif (preg_match('/dial tcp|i\/o timeout|connection refused|no such host|x509|TLS handshake/i', $foreign) === 1) {
            $lines[] = 'The container registry could not be reached — check network, VPN or proxy settings.';
        }

        // The cascade artifact, explained last: podman trying to *pull* the
        // app's own image means the build never produced one.
        if (str_contains($output, 'Trying to pull') && str_contains($output, $appImage)) {
            $lines[] = $buildFailed
                ? "The '$appImage' registry error below the build failure is a symptom: with no locally built image, podman asked docker.io for it, and it does not exist there."
                : "podman tried to pull '$appImage' from a registry. That image is only ever built locally — the build appears not to have produced it.";
        }

        return $lines;
    }

    /**
     * nimbus:view <app> — everything nimbus:up dumps after startup (URLs,
     * credentials, feature endpoints) plus a dev-centric container listing
     * (state, image URI, ports), on demand. Data comes from
     * AppManager::describeContainers() and the shared display helpers, so a
     * new feature container added to the ecosystem shows up here without
     * this method changing.
     */
    public function view(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $appName = $args[0] ?? null;
        if (!$appName) {
            $apps = $this->appManager->listApps();
            if (empty($apps)) {
                echo self::ansiFormat('ERROR', 'No apps found. Create one first with: composer nimbus:create');
                return;
            }
            $appNames = array_keys($apps);
            $choice = $io->select('Select app to view:', $appNames, 0);
            $appName = $appNames[$choice];
        }

        if (!$this->appManager->appExists($appName)) {
            echo self::ansiFormat('ERROR', "App '$appName' not found.");
            return;
        }

        $this->showAppView($appName);
    }

    /**
     * Everything nimbus:view reports about one app.
     *
     * Shared with nimbus:up so a successful start ends with the app's URLs,
     * credentials and remaining steps rather than a bare container table —
     * and so the two can never drift into showing different things.
     */
    private function showAppView(string $appName): void
    {
        echo self::ansiFormat('INFO', "📦 Containers for '$appName':");
        foreach ($this->appManager->describeContainers($appName) as $c) {
            if (!$c['exists']) {
                $icon = '⚪';
            } elseif (strpos($c['status'], 'Up') === 0) {
                $icon = '🟢';
            } else {
                $icon = '🔴';
            }
            $note = $c['expected'] ? '' : '  (orphan: feature disabled, still in project)';
            echo "  $icon {$c['name']}: {$c['status']}$note" . PHP_EOL;
            if ($c['exists']) {
                echo "       image: {$c['image']}" . PHP_EOL;
                if ($c['ports'] !== '') {
                    echo "       ports: {$c['ports']}" . PHP_EOL;
                }
            }
        }

        $this->displayAppDetails($appName);
        $this->interactiveHelper->displayDevModeInfo($appName);
        $this->interactiveHelper->displayKeycloakCredentials($appName);

        try {
            $config = $this->appManager->loadAppConfig($appName);

            $this->interactiveHelper->displayCredentials($appName, $config);
            $this->interactiveHelper->displayAppCommands($appName, $config, $this->appManager);
        } catch (\Throwable $e) {
            // A viewable app with an unreadable config is worth not crashing over
        }
    }

    /**
     * Display comprehensive app details after successful startup
     * Generic implementation - reads app config to determine what to show
     */
    private function displayAppDetails(string $appName): void
    {
        echo PHP_EOL;
        echo self::ansiFormat('INFO', "📋 App Details:");
        
        try {
            // Load app configuration to get details
            $appConfig = $this->appManager->loadAppConfig($appName);
            $appConfigPhp = $this->loadAppConfigPhp($appName);
            
            // Show app URL
            $appPort = $appConfig['containers']['app']['port'] ?? '8080';
            echo "  🌐 App URL: http://localhost:$appPort" . PHP_EOL;
            
            // Show database connection info
            if ($appConfig['features']['database'] ?? true) {
                $engine = \Nimbus\Database\DatabaseEngine::fromConfig($appConfig);
                $dbName = $appConfig['database']['name'] ?? ($appName . '_db');
                $dbUser = $appConfig['database']['user'] ?? ($appName . '_user');
                echo "  📊 Database: $dbName (user: $dbUser)" . PHP_EOL;
                echo "  🗄️  {$engine->image} container: {$engine->containerName($appName)}" . PHP_EOL;
            }
            
            // Show EDA info if enabled
            if ($appConfig['features']['eda'] ?? false) {
                $edaPort = $this->appManager->getServicePort($appName, 'eda');
                echo "  🔄 EDA endpoint: http://localhost:$edaPort" . PHP_EOL;
                echo "  📂 EDA container: $appName-eda" . PHP_EOL;
            }
            
            // Show enabled features
            $features = [];
            foreach ($appConfig['features'] ?? [] as $feature => $enabled) {
                if ($enabled) {
                    $features[] = $feature;
                }
            }
            if (!empty($features)) {
                echo "  ✅ Features: " . implode(', ', $features) . PHP_EOL;
            }
            
            // Show DNS setup instructions if DNS script exists
            $this->displayDnsInstructions($appName);
            
        } catch (\Exception $e) {
            echo "  ⚠️  Could not load app details: " . $e->getMessage() . PHP_EOL;
        }
    }
    
    /**
     * Display DNS setup instructions if DNS script exists
     */
    private function displayDnsInstructions(string $appName): void
    {
        $dnsScriptPath = getcwd() . "/dns-setup-$appName-hosts.sh";
        
        if (file_exists($dnsScriptPath)) {
            echo "  🌍 DNS Setup: sudo ./dns-setup-$appName-hosts.sh" . PHP_EOL;
        }
    }
    
    /**
     * Load app.config.php (separate from app.nimbus.json)
     */
    private function loadAppConfigPhp(string $appName): ?array
    {
        $configFile = getcwd() . "/.installer/apps/$appName/app.config.php";
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        return include $configFile;
    }
    
    private function stopApp($manager, array $app, $io): void
    {
        $appName = $app['name'];
        
        $options = [
            'timeout' => 10
        ];
        
        echo self::ansiFormat('INFO', "Stopping app '$appName'...");
        
        $results = $manager->stopApp($appName, $options);
        
        if ($results['stopped']) {
            echo self::ansiFormat('SUCCESS', "App '$appName' stopped successfully!");

            if ($results['removed']) {
                echo self::ansiFormat('INFO', "✓ Containers removed");
            }
            if ($results['cleaned']) {
                echo self::ansiFormat('INFO', "✓ Images removed");
            }

            // Same report `up` ends with — here it shows the stopped state and
            // gates `up` as the next step, so "how do I get it back" is
            // answered on the spot.
            echo PHP_EOL;
            $this->showAppView($appName);
        }

        if ($results['output'] && (strpos($results['output'], 'Error') !== false || strpos($results['output'], 'error') !== false)) {
            echo self::ansiFormat('WARNING', "Output:");
            echo $results['output'];
        }
    }

    private function stopAllApps($manager, array $runningApps, $io): void
    {
        $confirmed = $io->askConfirmation("Stop all " . count($runningApps) . " running apps? [y/N]: ", false);
        
        if (!$confirmed) {
            echo self::ansiFormat('INFO', 'Operation cancelled.');
            return;
        }
        
        $removeVolumes = $io->askConfirmation('Remove volumes for all apps? [y/N]: ', false);
        $removeContainers = $io->askConfirmation('Remove containers for all apps? [y/N]: ', false);
        $removeImages = $io->askConfirmation('Remove images for all apps? [y/N]: ', false);
        
        $options = [
            'remove_volumes' => $removeVolumes,
            'remove_containers' => $removeContainers,
            'remove_images' => $removeImages,
            'timeout' => 10
        ];
        
        $stopped = 0;
        $failed = 0;
        
        foreach ($runningApps as $app) {
            try {
                echo self::ansiFormat('INFO', "Stopping {$app['name']}...");
                $results = $manager->stopApp($app['name'], $options);
                
                if ($results['stopped']) {
                    $stopped++;
                    echo self::ansiFormat('SUCCESS', "✓ {$app['name']} stopped");
                } else {
                    $failed++;
                    echo self::ansiFormat('ERROR', "✗ Failed to stop {$app['name']}");
                }
            } catch (\Exception $e) {
                $failed++;
                echo self::ansiFormat('ERROR', "✗ Error stopping {$app['name']}: " . $e->getMessage());
            }
        }
        
        echo self::ansiFormat('SUCCESS', "Stopped $stopped apps" . ($failed > 0 ? ", $failed failed" : ""));
    }

    private function formatRunningStatus(array $app): string
    {
        if (!$app['is_running']) {
            return '⏹️ stopped';
        }
        
        $running = $app['running_count'] ?? 0;
        $total = $app['total_count'] ?? 0;
        
        if ($total === 0 && !empty($app['containers'])) {
            $total = count($app['containers']);
            $running = 0;
            foreach ($app['containers'] as $container) {
                if ($container['state'] === 'running') {
                    $running++;
                }
            }
        }
        
        if ($running === $total) {
            return "▶️ running ($running/$total)";
        } else {
            return "⚠️ partial ($running/$total)";
        }
    }

    private function formatHealthStatus(array $app): string
    {
        switch ($app['health_status']) {
            case 'healthy':
                return '✅ healthy';
            case 'running-unhealthy':
                return '⚠️ unhealthy';
            case 'partial':
                return '🔄 partial';
            case 'stopped':
                return '⏸️ stopped';
            default:
                return '❓ unknown';
        }
    }

    private function getHealthIcon(string $health): string
    {
        switch ($health) {
            case 'healthy':
                return '✅';
            case 'unhealthy':
                return '❌';
            case 'starting':
                return '🔄';
            case 'none':
                return '➖';
            default:
                return '❓';
        }
    }

    private function showAppStatus(array $app): void
    {
        foreach ($app['containers'] as $containerName => $status) {
            $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
            $healthIcon = $this->getHealthIcon($status['health']);
            echo "  └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
        }
    }
}