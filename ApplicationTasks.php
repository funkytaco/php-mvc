<?php

namespace Tasks;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;

class ApplicationTasks {

    private $composer;
    private $event;

    private static $foreground = array(
      'black' => '0;30',
      'dark_gray' => '1;30',
      'red' => '0;31',
      'bold_red' => '1;31',
      'green' => '0;32',
      'bold_green' => '1;32',
      'brown' => '0;33',
      'yellow' => '1;33',
      'blue' => '0;34',
      'bold_blue' => '1;34',
      'purple' => '0;35',
      'bold_purple' => '1;35',
      'cyan' => '0;36',
      'bold_cyan' => '1;36',
      'white' => '1;37',
      'bold_gray' => '0;37',
     );

     private static $background = array(
          'black' => '40',
          'red' => '41',
          'magenta' => '45',
          'yellow' => '43',
          'green' => '42',
          'blue' => '44',
          'cyan' => '46',
          'light_gray' => '47',
     );

    private static function ansiFormat($type, $str = '') {
        $types = array(

            'INFO' => self::$foreground['white'],
            'NOTICE' => self::$foreground['yellow'],
            'CONFIRM: Y/N' => self::$background['magenta'],
            'WARNING' => self::$background['red'],
            'ERROR' => self::$background['red'],
            'EXITING' => self::$background['yellow'],
            'DANGER' => self::$foreground['bold_red'],
            'SUCCESS' => self::$foreground['bold_blue'],
            'INSTALL' => self::$background['green'],
            'RUNNING>' => self::$foreground['white'],
            'COPYING>' => self::$foreground['white'],
            'MKDIR>' => self::$foreground['white']

        );
        
        // Emoji bullets - just return the string with emoji prefix, no color formatting
        $emojiBullets = array(
            'BULLETEMOJI' => '•',
            'ARROWEMOJI' => '→',
            'CHECKEMOJI' => '✓',
            'CROSSEMOJI' => '✗',
            'DASHEMOJI' => '—',
            'DOTEMOJI' => '·',
            'STAREMOJI' => '★',
            'TRIANGLEEMOJI' => '▶',
            'SQUAREEMOJI' => '■',
            'CIRCLEEMOJI' => '●',
            'DIAMONDEMOJI' => '◆'
        );
        
        // Check if it's an emoji bullet type
        if (isset($emojiBullets[$type])) {
            return $emojiBullets[$type] . ' ' . $str . PHP_EOL;
        }

        $ansi_start = "\033[". $types[$type] ."m";
        $ansi_end = "\033[0m";
        $ansi_type_start = "\033[". $types['INFO'] ."m";


        return $ansi_type_start . "[$type] " . $ansi_end . $ansi_start .  $str . $ansi_end . PHP_EOL;
    }

    public static function startDevelopmentWebServer($event) {

        //$timeout = $event->getComposer()->getConfig()->get('process-timeout');
        $port = 3000;
        echo self::ansiFormat('INFO','Starting webserver on port '. $port);
        echo exec('php -S localhost:'. $port .' public/index.php');

    }

    private static function lock_file_exists() {
        return is_file('src/.lock/app.lock') ? TRUE : FALSE;
    }

    public static function DeleteLockFile() {
        if (self::lock_file_exists() == TRUE) {
            if (unlink('src/.lock/app.lock')) {
                echo self::ansiFormat('INFO', 'Lock file deleted.');
            } else {
                echo self::ansiFormat('WARNING', 'Unable to delete src/.lock/app.lock file. Please remove manually.');
            }
        } else {
            echo self::ansiFormat('INFO', 'App is not locked.');
        }
    }

    private static function delete_assets_recursive($dir) {

        $files = array_diff(scandir($dir), array('.','..'));

        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::delete_assets_recursive("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }


    private static function copy_extra_assets($strAssetKey, Event $event) {
            $bAppIsLocked = self::lock_file_exists();

            switch($bAppIsLocked) {

                case TRUE:
                echo self::ansiFormat('ERROR', $strAssetKey . ' app.lock file exists. Please backup your code before deleting the app.lock file and running the installer.');
                break;
                case FALSE:
                echo self::ansiFormat('RUNNING>', $strAssetKey . ' Post-Install Tasks');
                $extra = $event->getComposer()->getPackage()->getExtra();

                if(is_array($extra)) {

                    if(array_key_exists($strAssetKey, $extra)) {

                        foreach($extra[$strAssetKey] as $key => $value) {

                            if ($key == 'copy-assets') {
                                $copy_assets = $value;
                                continue;
                            } else {
                                $arrAsset = $value;
                            }

                            if ($copy_assets == true && is_array($arrAsset)) {
                                self::copyAssets($arrAsset['source'] , $arrAsset['target'], $arrAsset['isFile'], $event);
                            }
                        }

                    } else {
                        echo self::ansiFormat('ERROR', 'Invalid asset key ('. $strAssetKey .'). Check the extras section of your composer.json file
                        for the correct key name.');
                    }
                }
            }
        }


    private static function copy_assets_recursive($source, $destination, $event) {
        echo self::ansiFormat('INFO', 'DESTINATION TYPE: ' . (is_dir($destination) ? "DIR" : "FILE"));
        echo self::ansiFormat('INFO', 'SOURCE DIR: '. $source);
        echo self::ansiFormat('INFO', 'DESTINATION DIR: '. $destination);

        if (!file_exists($source) || $destination == __DIR__ . '/public/assets/') {
            return false;
        }

        if (file_exists($destination)) {
            echo self::ansiFormat('NOTICE', 'Destination exists. ');

            $bTargetIsSystemDir = FALSE;
            $io = $event->getIO();

            echo self::ansiFormat('WARNING', 'DESTINATION EXISTS! BACKUP IF NECESSARY!: '. $destination);
            $arrSystemDirs = array('src','Mock','Modules','Renderer', 'Static','.installer');

            foreach ($arrSystemDirs as $dir) {
                if ($destination == $dir) {
                    $bTargetIsSystemDir = TRUE;
                }
            }

            if ($bTargetIsSystemDir == TRUE) {
                echo(self::ansiFormat('EXITING', 'Cowardly refusing to delete destination path: '. $destination));
                echo(self::ansiFormat('INFO', 'Attempting simple copy.'));
                copy($source, $destination);

            } else {
                if ($io->askConfirmation(self::ansiFormat('CONFIRM: Y/N', 'Delete target directory?'), false)) {
                    self::delete_assets_recursive($destination); //Destructive! Your destination must be correct!
                } else {
                        exit(self::ansiFormat('EXITING', 'Cancelled Bootstrap Post-Install Tasks'));
                }
            }

        }

        mkdir($destination, 0755, true);

        foreach (
        $directoryPath = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST) as $file
        ) {
            if ($file->isDir()) {
                echo self::ansiFormat('MKDIR>', $file);
                mkdir($destination . DIRECTORY_SEPARATOR . $directoryPath->getSubPathName(), 0755);
            } else {
                echo self::ansiFormat('COPYING>', $file);
                copy($file, $destination . DIRECTORY_SEPARATOR . $directoryPath->getSubPathName());
            }
        }

        return true;
    }

    public static function postUpdate(Event $event) {
        echo self::ansiFormat('RUNNING>', 'Post-Update Tasks');
    }
    public static function postInstall(Event $event) {
        echo self::ansiFormat('NOTICE', 'Available Post-Install Tasks:');
        //begin
        $extra = $event->getComposer()->getPackage()->getScripts();

        if(is_array($extra)) {
            foreach (array_keys($extra) as $installer) {
                echo "composer ". $installer .PHP_EOL;
            }
        }
        //end
    }

    public static function commitToInstallerDirectory(Event $event) {

        echo self::ansiFormat('RUNNING>', 'Commit To Installer Directory...');
        $settings = include('app/app.config.php');

        /** todo: add more sanity checks **/
        if ($settings['views']) {
            $source =  "app/". $settings['views'];
            $destination = '.installer/'. $settings['installer-name'] .'/'. $settings['views'];
            $isFile = FALSE;
            echo self::copyAssets($source, $destination, $isFile, $event);
        }

        if ($settings['controllers']) {
            $source =  "app/". $settings['controllers'];
            $destination = '.installer/'. $settings['installer-name'] .'/'. $settings['controllers'];
            $isFile = FALSE;
            echo self::copyAssets($source, $destination, $isFile, $event);
        }


    }

    private static function list_directory_files($path, $event) {
        echo self::ansiFormat('INFO', 'Listing Directory Files...');
        $fullPath = '.installer/'. $path;

        if (is_dir($fullPath)) {
            $arrFiles = array_diff(scandir($fullPath), array('..', '.'));
            return $arrFiles;
        } else {
            return [];
        }
    }

    private static function AreComposerPackagesInstalled(Event $event) {
        if (is_file('vendor/autoload.php')) {
            return true;
        } else {
            return false;
        }
    }

    public static function InstallMvc(Event $event) {
        if (!self::AreComposerPackagesInstalled($event)) exit('Please run composer install first.');
        echo self::ansiFormat('RUNNING>', 'Installing Bootstrap Template...');
        self::copy_extra_assets('mvc-assets', $event);
    }

    public static function InstallSemanticUi(Event $event) {
        if (!self::AreComposerPackagesInstalled($event)) exit('Please run composer install first.');
        echo self::ansiFormat('RUNNING>', 'Installing Semantic UI Template...');
        self::copy_extra_assets('semanticui-assets', $event);
    }

    public static function InstallLkui(Event $event) {
        if (!self::AreComposerPackagesInstalled($event)) exit('Please run composer install first.');
        echo self::ansiFormat('RUNNING>', 'Installing LKUI Template...');
        self::copy_extra_assets('lkui-assets', $event);
    }

    public static function InstallNagios(Event $event) {
        if (!self::AreComposerPackagesInstalled($event)) exit('Please run composer install first.');
        echo self::ansiFormat('RUNNING>', 'Installing Nagios Hook Template...');
        self::copy_extra_assets('nagios-assets', $event);
    }

    public static function nimbusCreate(Event $event) {
        $task = new \Nimbus\Tasks\CreateTask();
        $task->create($event);
    }

    public static function nimbusCreateWithEda(Event $event) {
        $task = new \Nimbus\Tasks\CreateTask();
        $task->createWithEda($event);
    }

    public static function nimbusInstall(Event $event) {
        $task = new \Nimbus\Tasks\InstallTask();
        $task->install($event);
    }

    public static function nimbusList(Event $event) {
        $task = new \Nimbus\Tasks\InstallTask();
        $task->list($event);
    }

    public static function nimbusAddEda(Event $event) {
        $task = new \Nimbus\Tasks\FeatureTask();
        $task->addEda($event);
    }

    public static function nimbusDown(Event $event) {
        $task = new \Nimbus\Tasks\ContainerTask();
        $task->down($event);
    }
    
    private static function stopApp($manager, array $app, $io) {
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

        }
        
        // Show output if there are any issues
        if ($results['output'] && (strpos($results['output'], 'Error') !== false || strpos($results['output'], 'error') !== false)) {
            echo self::ansiFormat('WARNING', "Output:");
            echo $results['output'];
        }
    }
    
    private static function stopAllApps($manager, array $runningApps, $io) {
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

    public static function nimbusStatus(Event $event) {
        $task = new \Nimbus\Tasks\ContainerTask();
        $task->status($event);
    }

    public static function nimbusUp(Event $event) {
        $task = new \Nimbus\Tasks\ContainerTask();
        $task->up($event);
    }
    
    private static function startApp(array $app) {
        $appName = $app['name'];
        $composeFile = $app['compose_file'];
        
        // Check if already running and healthy
        if ($app['is_running'] && $app['health_status'] === 'healthy') {
            echo self::ansiFormat('INFO', "App '$appName' is already running and healthy!");
            self::showAppStatus($app);
            return;
        }
        
        //if (!$app['has_image']) {
            echo self::ansiFormat('INFO', "Building app '$appName' image...");
            $buildCommand = "podman-compose -f $composeFile up --build -d";
        //} else {
           // echo self::ansiFormat('INFO', "Starting app '$appName'...");
            //$buildCommand = "podman-compose -f $composeFile up -d";
        //}
        
        echo self::ansiFormat('INFO', "Running: $buildCommand");
        $output = shell_exec($buildCommand . ' 2>&1');
        
        if ($output) {
            echo $output;
        }
        
        // Check if containers are running
        $statusOutput = shell_exec("podman-compose -f $composeFile ps --format table 2>/dev/null");
        if ($statusOutput) {
            echo self::ansiFormat('SUCCESS', "App '$appName' started successfully!");
            echo $statusOutput;
            
            // Display Keycloak admin credentials if Keycloak is enabled
            self::displayKeycloakCredentials($appName);
        }
    }
    
    private static function formatRunningStatus(array $app): string {
        if (!$app['is_running']) {
            return '⏹️ stopped';
        }
        
        $running = $app['running_count'] ?? 0;
        $total = $app['total_count'] ?? 0;
        
        // If counts are 0, calculate from containers directly
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
    
    private static function formatHealthStatus(array $app): string {
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
    
    private static function getHealthIcon(string $health): string {
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
    
    private static function showAppStatus(array $app): void {
        foreach ($app['containers'] as $containerName => $status) {
            $stateIcon = $status['state'] === 'running' ? '🟢' : '🔴';
            $healthIcon = self::getHealthIcon($status['health']);
            echo "  └─ $containerName: {$status['state']} $stateIcon $healthIcon" . PHP_EOL;
        }
    }

    public static function copyAssets($source, $destination, $isFile, $event) {


    if ($isFile == TRUE) {
        echo self::ansiFormat('RUNNING>', 'copy Assets for: '. $source);
        touch($destination);

            if(!is_file($destination)) {
                self::copy_assets_recursive($source, $destination, $event);
            } else {
                copy($source, $destination);
            }


    } else {
        try {

            if (self::copy_assets_recursive($source, $destination, $event) == true) {
                    echo self::ansiFormat('SUCCESS', 'Copied assets from "'.realpath($source).'" to "'.realpath($destination).'".'.PHP_EOL);
                } else {
                    echo self::ansiFormat('ERROR', 'Copy failed! Unable to copy assets from "'.realpath($source)
                    .'" to "'.realpath($destination).'"');
            }

        } catch(Exception $e) {
            echo self::ansiFormat('ERROR', 'Copy failed! Unable to copy assets from "'.realpath($source)
            .'" to "'.realpath($destination).'"');
        }
    }

    }

    public static function postPackageReinstallBootstrap(Event $event) {
        self::copy_extra_assets('mvc-assets', $event);
    }

    public static function postPackageReinstallSemanticUi(Event $event) {
        self::copy_extra_assets('semanticui-assets', $event);
    }

    private static function copy_assets_for_package(PackageEvent $event) {

        echo self::ansiFormat('RUNNING>', 'Bootstrap Post-Install Tasks');
        $extra = $event->getComposer()->getPackage()->getExtra();

        if(is_array($extra)) {
            if(array_key_exists('mvc-assets', $extra)) {

                foreach($extra['mvc-assets'] as $key => $value) {

                    if ($key == 'copy-assets') {
                        $copy_assets = $value;
                        continue;
                    } else {
                        $arrAsset = $value;
                    }

                    if ($copy_assets == true && is_array($arrAsset)) {
                        self::copyAssets($arrAsset['source'] , $arrAsset['target'], $event);
                    }
                }

            }
        }

        $css_dir = 'public/assets/css/themes/';
        if (is_dir($css_dir)) {
            self::delete_assets_recursive($css_dir);
        }

        mkdir($css_dir, 0755, true);

        copy('vendor/twbs/bootstrap/site/src/assets/examples/dashboard/dashboard.css', $css_dir . 'dashboard.css');
        copy('vendor/twbs/bootstrap/site/src/assets/examples/cover/cover.css', $css_dir .'cover.css');


    }

    public static function postPackageInstall(PackageEvent $event) {

        echo self::ansiFormat('RUNNING>', 'Post-Install Tasks');

        $installedPackage = $event->getOperation()->getPackage();

        echo self::ansiFormat('INSTALL', $installedPackage);

        if (strstr($installedPackage,'twbs/bootstrap') == true) {

          self::copy_assets_for_package($event);

        } else {

            echo self::ansiFormat('INFO', $installedPackage);

        }
    }


    public static function nimbusDelete(Event $event) {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        try {
            $manager = new \Nimbus\App\AppManager();
            $apps = $manager->listApps();
            
            if (empty($apps)) {
                echo self::ansiFormat('INFO', 'No apps found to delete.');
                return;
            }
            
            // If no argument provided, ask for app name or show list
            if (empty($args)) {
                echo self::ansiFormat('INFO', 'Available apps:');
                $index = 1;
                $appList = [];
                foreach ($apps as $name => $info) {
                    echo "  [$index] $name" . PHP_EOL;
                    $appList[$index] = $name;
                    $index++;
                }
                echo "  [all] Delete ALL apps" . PHP_EOL;
                
                $choice = $io->ask('Enter app number, name, or "all": ');
                
                if (strtolower($choice) === 'all') {
                    self::deleteAllApps($manager, $apps, $io);
                    return;
                } elseif (is_numeric($choice) && isset($appList[(int)$choice])) {
                    $appName = $appList[(int)$choice];
                } else {
                    $appName = $choice;
                }
            } else {
                $appName = $args[0];
                if (strtolower($appName) === 'all') {
                    self::deleteAllApps($manager, $apps, $io);
                    return;
                }
            }
            
            // Check if app exists
            if (!isset($apps[$appName])) {
                echo self::ansiFormat('ERROR', "App '$appName' not found!");
                return;
            }
            
            // Show what will be deleted
            $appPath = getcwd() . '/.installer/apps/' . $appName;
            $composeFile = getcwd() . '/' . $appName . '-compose.yml';
            
            echo self::ansiFormat('WARNING', "This will PERMANENTLY delete:");
            echo "  - App directory: $appPath" . PHP_EOL;
            echo "  - Compose file: $composeFile" . PHP_EOL;
            echo "  - Any associated containers, volumes, and images" . PHP_EOL;
            
            // Ask about backing up credentials to vault
            try {
                $vaultManager = new \Nimbus\Vault\VaultManager();
                if ($vaultManager->isInitialized()) {
                    if ($io->askConfirmation('🔐 Backup credentials to vault before deleting? [Y/n]: ', true)) {
                        echo self::ansiFormat('INFO', "Backing up credentials for '$appName'...");
                        $credentials = $vaultManager->extractAppCredentials($appName);
                        if (!empty($credentials)) {
                            $vaultManager->backupAppCredentials($appName, $credentials);
                            echo self::ansiFormat('INFO', '✅ Credentials backed up to vault!');
                        } else {
                            echo self::ansiFormat('WARNING', 'No credentials found to backup (app may not be running)');
                        }
                    }
                } else {
                    // Vault not initialized - suggest it
                    if ($io->askConfirmation('🔐 Initialize vault to backup credentials? [y/N]: ', false)) {
                        $vaultManager->initializeVault();
                        echo self::ansiFormat('INFO', 'Vault initialized! Now backing up credentials...');
                        $credentials = $vaultManager->extractAppCredentials($appName);
                        if (!empty($credentials)) {
                            $vaultManager->backupAppCredentials($appName, $credentials);
                            echo self::ansiFormat('INFO', '✅ Credentials backed up to vault!');
                        }
                    }
                }
            } catch (\Exception $e) {
                echo self::ansiFormat('WARNING', 'Could not backup credentials: ' . $e->getMessage());
            }
            
            echo PHP_EOL;
            if (!$io->askConfirmation(self::ansiFormat('CONFIRM: Y/N', 'Are you sure you want to delete this app?'), false)) {
                echo self::ansiFormat('INFO', 'Deletion cancelled');
                return;
            }
            
            // Perform deletion
            $manager->deleteApp($appName, [
                'remove_volumes' => $io->askConfirmation('Remove volumes? [y/N]: ', false),
                'remove_containers' => $io->askConfirmation('Remove containers? [y/N]: ', false),
                'remove_images' => $io->askConfirmation('Remove app images? [y/N]: ', false)
            ]);
            
            echo self::ansiFormat('SUCCESS', "App '$appName' deleted successfully!");
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to delete app: ' . $e->getMessage());
        }
    }
    
    private static function deleteAllApps($manager, array $apps, $io) {
        $count = count($apps);
        echo self::ansiFormat('WARNING', "This will PERMANENTLY delete ALL $count apps!");
        echo self::ansiFormat('WARNING', "This action cannot be undone!");
        
        if (!$io->askConfirmation(self::ansiFormat('CONFIRM: Y/N', 'Are you absolutely sure you want to delete ALL apps?'), false)) {
            echo self::ansiFormat('INFO', 'Deletion cancelled');
            return;
        }
        
        // Double confirmation for safety
        $confirmText = $io->ask('Type "DELETE ALL" to confirm: ');
        if ($confirmText !== 'DELETE ALL') {
            echo self::ansiFormat('INFO', 'Deletion cancelled - confirmation text did not match');
            return;
        }
        
        // Ask about backing up ALL credentials to vault before mass deletion
        $backupToVault = false;
        try {
            $vaultManager = new \Nimbus\Vault\VaultManager();
            if ($vaultManager->isInitialized()) {
                if ($io->askConfirmation('🔐 Backup all app credentials to vault before deleting? [Y/n]: ', true)) {
                    $backupToVault = true;
                }
            } else {
                // Vault not initialized - suggest it
                if ($io->askConfirmation('🔐 Initialize vault to backup all credentials? [y/N]: ', false)) {
                    $vaultManager->initializeVault();
                    echo self::ansiFormat('INFO', 'Vault initialized!');
                    $backupToVault = true;
                }
            }
        } catch (\Exception $e) {
            echo self::ansiFormat('WARNING', 'Could not initialize vault: ' . $e->getMessage());
        }
        
        $options = [
            'remove_volumes' => $io->askConfirmation('Remove all volumes? [y/N]: ', false),
            'remove_containers' => $io->askConfirmation('Remove all containers? [y/N]: ', false),
            'remove_images' => $io->askConfirmation('Remove all app images? [y/N]: ', false)
        ];
        
        $deleted = 0;
        $failed = 0;
        $backedUp = 0;
        
        foreach ($apps as $appName => $info) {
            try {
                echo self::ansiFormat('INFO', "Processing $appName...");
                
                // Backup credentials if requested
                if ($backupToVault) {
                    try {
                        $credentials = $vaultManager->extractAppCredentials($appName);
                        if (!empty($credentials)) {
                            $vaultManager->backupAppCredentials($appName, $credentials);
                            echo self::ansiFormat('INFO', "  ✅ Credentials backed up for '$appName'");
                            $backedUp++;
                        } else {
                            echo self::ansiFormat('INFO', "  ⚠️  No credentials found for '$appName' (app may not be running)");
                        }
                    } catch (\Exception $e) {
                        echo self::ansiFormat('WARNING', "  ⚠️  Could not backup '$appName' credentials: " . $e->getMessage());
                    }
                }
                
                // Delete the app
                echo self::ansiFormat('INFO', "  Deleting $appName...");
                $manager->deleteApp($appName, $options);
                $deleted++;
                echo self::ansiFormat('SUCCESS', "  ✓ $appName deleted");
            } catch (\Exception $e) {
                $failed++;
                echo self::ansiFormat('ERROR', "  ✗ Failed to delete $appName: " . $e->getMessage());
            }
        }
        
        echo PHP_EOL;
        $message = "Deleted $deleted apps";
        if ($backupToVault && $backedUp > 0) {
            $message .= ", backed up $backedUp credentials";
        }
        if ($failed > 0) {
            $message .= ", $failed failed";
        }
        echo self::ansiFormat('SUCCESS', $message);
    }
    
    /**
     * Interactive step-by-step walkthrough after app creation
     */
    private static function interactiveNextSteps(string $appName, $io, $manager, array $features = [], bool $isNewApp = true) {
        echo self::ansiFormat('INFO', "🚀 Next steps:");
        echo PHP_EOL;
        
        // Check if app is already running (for existing apps)
        $wasRunning = false;
        if (!$isNewApp) {
            $apps = $manager->getStartableApps();
            $app = array_filter($apps, fn($a) => $a['name'] === $appName);
            if (!empty($app)) {
                $appInfo = array_values($app)[0];
                $wasRunning = $appInfo['is_running'] ?? false;
            }
        }
        
        $needsReinstall = false;
        $addedFeatures = [];
        
        // Step 1: Optional enhancements (before install)
        if (!in_array('eda', $features) || !in_array('keycloak', $features)) {
            echo "  1. Optional enhancements" . PHP_EOL;
            
            if (!in_array('eda', $features)) {
                if ($io->askConfirmation("     Add Event-Driven Ansible (EDA)? [y/N]: ", false)) {
                    try {
                        $manager->addEda($appName);
                        echo self::ansiFormat('SUCCESS', "     ✓ EDA added successfully!");
                        $addedFeatures[] = 'eda';
                    } catch (\Exception $e) {
                        echo self::ansiFormat('ERROR', '     ✗ Failed to add EDA: ' . $e->getMessage());
                    }
                }
            }
            
            if (!in_array('keycloak', $features)) {
                if ($io->askConfirmation("     Add Keycloak SSO? [y/N]: ", false)) {
                    try {
                        $manager->addKeycloak($appName);
                        echo self::ansiFormat('SUCCESS', "     ✓ Keycloak added successfully!");
                        $addedFeatures[] = 'keycloak';
                    } catch (\Exception $e) {
                        echo self::ansiFormat('ERROR', '     ✗ Failed to add Keycloak: ' . $e->getMessage());
                    }
                }
            }
            
            echo PHP_EOL;
        }
        
        // Update features list with any newly added features
        $allFeatures = array_unique(array_merge($features, $addedFeatures));
        
        // Show configuration preview BEFORE the install prompt
        echo PHP_EOL;
        self::showConfigurationPreview($appName, $manager);
        
        // Step 2: Install
        echo "  2. Generate container configuration" . PHP_EOL;
        
        // Ask with edit option
        $installChoice = $io->ask("     Run 'composer nimbus:install $appName' now? [Y/n/edit]: ", 'y');
        $installChoice = strtolower(trim($installChoice));
        
        if ($installChoice === 'edit' || $installChoice === 'e') {
            echo PHP_EOL;
            
            // Provide edit instructions
            echo self::ansiFormat('INFO', "📝 To edit configuration:");
            echo "  1. Edit: .installer/apps/$appName/app.nimbus.json" . PHP_EOL;
            echo "  2. Run: composer nimbus:install $appName" . PHP_EOL;
            echo "  3. Then: composer nimbus:up $appName" . PHP_EOL;
            echo PHP_EOL;
            
            // Open editor if possible
            $editor = getenv('EDITOR') ?: 'vim';
            $configPath = ".installer/apps/$appName/app.nimbus.json";
            if ($io->askConfirmation("     Open configuration in $editor? [Y/n]: ", true)) {
                system("$editor $configPath");
                echo PHP_EOL;
                // After editing, ask again
                if ($io->askConfirmation("     Configuration edited. Install now? [Y/n]: ", true)) {
                    try {
                        $manager->install($appName);
                        echo self::ansiFormat('SUCCESS', "✓ App '$appName' installed successfully!");
                        echo self::ansiFormat('INFO', "  Container config generated: $appName-compose.yml");
                    } catch (\Exception $e) {
                        echo self::ansiFormat('ERROR', '✗ Failed to install app: ' . $e->getMessage());
                        return;
                    }
                } else {
                    echo self::ansiFormat('INFO', "  Skipped - run 'composer nimbus:install $appName' later");
                    self::showRemainingSteps($appName, $allFeatures);
                    return;
                }
            } else {
                self::showRemainingSteps($appName, $allFeatures);
                return;
            }
        } elseif ($installChoice === 'y' || $installChoice === 'yes' || $installChoice === '') {
            echo PHP_EOL;
            try {
                $manager->install($appName);
                echo self::ansiFormat('SUCCESS', "✓ App '$appName' installed successfully!");
                echo self::ansiFormat('INFO', "  Container config generated: $appName-compose.yml");
            } catch (\Exception $e) {
                echo self::ansiFormat('ERROR', '✗ Failed to install app: ' . $e->getMessage());
                return;
            }
        } else {
            echo self::ansiFormat('INFO', "  Skipped - run 'composer nimbus:install $appName' later");
            self::showRemainingSteps($appName, $allFeatures);
            return;
        }
        
        echo PHP_EOL;
        
        // Step 3: Start/Restart containers
        $actionVerb = ($wasRunning && !empty($addedFeatures)) ? "Restart" : "Start";
        echo "  3. $actionVerb containers" . PHP_EOL;
        
        // If app was running and we added features, we need to restart
        if ($wasRunning && !empty($addedFeatures)) {
            echo self::ansiFormat('INFO', "     App needs restart to activate new features");
            if ($io->askConfirmation("     Restart app now? [Y/n]: ", true)) {
                echo PHP_EOL;
                echo self::ansiFormat('INFO', "Stopping app...");
                
                try {
                    // Stop the app first
                    $manager->stopApp($appName, ['remove_volumes' => false, 'remove_containers' => false]);
                    echo self::ansiFormat('SUCCESS', "✓ App stopped");
                    
                    // Then start it again
                    echo self::ansiFormat('INFO', "Starting app with new configuration...");
                    $apps = $manager->getStartableApps();
                    $app = array_filter($apps, fn($a) => $a['name'] === $appName);
                    
                    if (!empty($app)) {
                        self::startApp(array_values($app)[0]);
                        self::showFeatureInfo($appName, $allFeatures);
                    }
                } catch (\Exception $e) {
                    echo self::ansiFormat('ERROR', '✗ Failed to restart app: ' . $e->getMessage());
                }
            } else {
                echo self::ansiFormat('INFO', "  Skipped - restart manually with:");
                echo "     composer nimbus:down $appName && composer nimbus:up $appName" . PHP_EOL;
            }
        } else {
            // Normal start for new apps or apps that weren't running
            if ($io->askConfirmation("     Run 'composer nimbus:up $appName' now? [Y/n]: ", true)) {
                echo PHP_EOL;
                
                // Get the app details to pass to startApp
                $apps = $manager->getStartableApps();
                $app = array_filter($apps, fn($a) => $a['name'] === $appName);
                
                if (!empty($app)) {
                    self::startApp(array_values($app)[0]);
                    self::showFeatureInfo($appName, $allFeatures);
                } else {
                    echo self::ansiFormat('ERROR', '✗ Failed to find app details');
                }
            } else {
                echo self::ansiFormat('INFO', "  Skipped - run 'composer nimbus:up $appName' later");
                self::showRemainingSteps($appName, $allFeatures);
                return;
            }
        }
        
        echo PHP_EOL;
        self::showUsefulCommands($appName);
    }
    
    private static function showRemainingSteps(string $appName, array $features) {
        echo PHP_EOL;
        echo self::ansiFormat('INFO', "📋 Remaining steps:");
        echo "  • composer nimbus:install $appName   # Generate container configuration" . PHP_EOL;
        echo "  • composer nimbus:up $appName        # Start containers" . PHP_EOL;
        
        // Only show add commands if features weren't already enabled
        if (!in_array('eda', $features)) {
            echo "  • composer nimbus:add-eda $appName      # (Optional) Add Event-Driven Ansible" . PHP_EOL;
        }
        if (!in_array('keycloak', $features)) {
            echo "  • composer nimbus:add-keycloak $appName # (Optional) Add Keycloak SSO" . PHP_EOL;
        }
        
        echo PHP_EOL;
        self::showUsefulCommands($appName);
    }
    
    private static function showUsefulCommands(string $appName) {
        echo self::ansiFormat('INFO', "💡 Other useful commands:");
        echo "  • composer nimbus:status            # Check app status" . PHP_EOL;
        echo "  • composer nimbus:down $appName     # Stop containers" . PHP_EOL;
        echo "  • composer nimbus:delete $appName   # Delete app" . PHP_EOL;
        
        // Check if setup-hosts.sh exists
        $setupHostsPath = ".installer/apps/$appName/setup-hosts.sh";
        if (file_exists($setupHostsPath) && PHP_OS === 'Darwin') {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🌐 Setup local hostnames (macOS):");
            echo "  • sudo ./$setupHostsPath         # Add .test hostnames to /etc/hosts" . PHP_EOL;
            echo "  • View network info: cat .installer/apps/$appName/podman-network.md" . PHP_EOL;
        }
    }
    
    private static function showFeatureInfo(string $appName, array $features) {
        // Show additional info based on features
        echo PHP_EOL;
        if (in_array('keycloak', $features)) {
            self::displayKeycloakCredentials($appName);
        }
        
        // Show feature-specific info
        if (in_array('eda', $features)) {
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "📡 EDA is running:");
            echo "  • Webhook endpoint: http://localhost:<app-port>/eda/webhook" . PHP_EOL;
            echo "  • Rulebooks: .installer/apps/$appName/rulebooks/" . PHP_EOL;
        }
    }

    public static function nimbusCreateEdaKeycloak(Event $event) {
        $task = new \Nimbus\Tasks\CreateTask();
        $task->createEdaKeycloak($event);
    }
    
    public static function nimbusAddEdaKeycloak(Event $event) {
        $task = new \Nimbus\Tasks\FeatureTask();
        $task->addEdaKeycloak($event);
    }
    
    public static function nimbusAddKeycloak(Event $event) {
        $task = new \Nimbus\Tasks\FeatureTask();
        $task->addKeycloak($event);
    }
    
    /**
     * Display Keycloak admin credentials if Keycloak is enabled for the app
     */
    private static function displayKeycloakCredentials(string $appName): void
    {
        try {
            $manager = new \Nimbus\App\AppManager();
            $config = $manager->loadAppConfig($appName);
            
            // Check if Keycloak is enabled
            if (!isset($config['features']['keycloak']) || !$config['features']['keycloak']) {
                return;
            }
            
            // Try to get the admin password from the container
            $containerName = $appName . '-keycloak';
            $inspectCmd = "podman inspect $containerName --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | grep KEYCLOAK_ADMIN_PASSWORD | cut -d'=' -f2";
            $adminPassword = trim(shell_exec($inspectCmd));
            
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🔐 Keycloak Admin Console Access:");
            echo "  URL: http://localhost:8080" . PHP_EOL;
            echo "  Username: admin" . PHP_EOL;
            
            if (!empty($adminPassword)) {
                echo "  Password: $adminPassword" . PHP_EOL;
            } else {
                echo "  Password: (use command below to retrieve)" . PHP_EOL;
            }
            
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "💡 To retrieve admin password later, run:");
            echo "  podman inspect $containerName --format '{{range .Config.Env}}{{println .}}{{end}}' | grep KEYCLOAK_ADMIN_PASSWORD | cut -d'=' -f2" . PHP_EOL;
            echo PHP_EOL;
            echo self::ansiFormat('INFO', "🚀 Next steps:");
            echo "  1. Access Keycloak admin console (URL above)" . PHP_EOL;
            echo "  2. Configure realm and client at http://localhost:" . ($config['containers']['app']['port'] ?? '8080') . "/auth/configure" . PHP_EOL;
            echo "  3. Test SSO integration in your app" . PHP_EOL;
            
        } catch (\Exception $e) {
            // Silently fail - don't disrupt the main app startup process
        }
    }

    /**
     * Initialize Ansible Vault for credential management
     */
    public static function nimbusVaultInit(Event $event): void
    {
        try {
            $vaultManager = new \Nimbus\Vault\VaultManager();
            
            echo self::ansiFormat('INFO', '🔐 Initializing Nimbus Credential Vault...');
            echo PHP_EOL;
            
            if ($vaultManager->isInitialized()) {
                echo self::ansiFormat('WARNING', 'Vault is already initialized.');
                return;
            }
            
            // Check if ansible-vault is available
            $ansibleCheck = shell_exec('which ansible-vault 2>/dev/null');
            if (empty($ansibleCheck)) {
                echo self::ansiFormat('ERROR', 'ansible-vault not found. Installing via container...');
                // We'll use containerized ansible-vault
            }
            
            $success = $vaultManager->initializeVault();
            
            if ($success) {
                echo self::ansiFormat('INFO', '✅ Vault initialized successfully!');
                echo PHP_EOL;
                echo self::ansiFormat('INFO', '💡 Usage:');
                echo "  composer nimbus:vault-backup <app>   - Backup app credentials" . PHP_EOL;
                echo "  composer nimbus:vault-restore <app>  - Restore app credentials" . PHP_EOL;
                echo "  composer nimbus:vault-list           - List backed up apps" . PHP_EOL;
            } else {
                echo self::ansiFormat('ERROR', '❌ Failed to initialize vault');
            }
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to initialize vault: ' . $e->getMessage());
        }
    }
    
    /**
     * Backup app credentials to vault
     */
    public static function nimbusVaultBackup(Event $event): void
    {
        $args = $event->getArguments();
        
        if (empty($args)) {
            echo self::ansiFormat('ERROR', 'App name required. Usage: composer nimbus:vault-backup <app-name>');
            return;
        }
        
        $appName = $args[0];
        
        try {
            $vaultManager = new \Nimbus\Vault\VaultManager();
            
            if (!$vaultManager->isInitialized()) {
                echo self::ansiFormat('ERROR', 'Vault not initialized. Run: composer nimbus:vault-init');
                return;
            }
            
            echo self::ansiFormat('INFO', "🔐 Backing up credentials for '$appName'...");
            echo PHP_EOL;
            
            // Extract credentials from running containers and config files
            $credentials = $vaultManager->extractAppCredentials($appName);
            
            if (empty($credentials)) {
                echo self::ansiFormat('WARNING', "No credentials found for '$appName'. App may not be running.");
                return;
            }
            
            $success = $vaultManager->backupAppCredentials($appName, $credentials);
            
            if ($success) {
                echo self::ansiFormat('INFO', '✅ Credentials backed up successfully!');
                echo PHP_EOL;
                
                // Show what was backed up
                if (isset($credentials['database'])) {
                    echo "  📊 Database password: ✓" . PHP_EOL;
                }
                if (isset($credentials['keycloak'])) {
                    echo "  🔐 Keycloak admin password: ✓" . PHP_EOL;
                    echo "  🔐 Keycloak DB password: ✓" . PHP_EOL;
                    if (isset($credentials['keycloak']['client_secret'])) {
                        echo "  🔐 Keycloak client secret: ✓" . PHP_EOL;
                    }
                }
            } else {
                echo self::ansiFormat('ERROR', '❌ Failed to backup credentials');
            }
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to backup credentials: ' . $e->getMessage());
        }
    }
    
    /**
     * Restore app credentials from vault
     */
    public static function nimbusVaultRestore(Event $event): void
    {
        $args = $event->getArguments();
        
        if (empty($args)) {
            echo self::ansiFormat('ERROR', 'App name required. Usage: composer nimbus:vault-restore <app-name>');
            return;
        }
        
        $appName = $args[0];
        
        try {
            $vaultManager = new \Nimbus\Vault\VaultManager();
            
            if (!$vaultManager->isInitialized()) {
                echo self::ansiFormat('ERROR', 'Vault not initialized. Run: composer nimbus:vault-init');
                return;
            }
            
            $credentials = $vaultManager->restoreAppCredentials($appName);
            
            if (!$credentials) {
                echo self::ansiFormat('WARNING', "No credentials found in vault for '$appName'");
                return;
            }
            
            echo self::ansiFormat('INFO', "🔐 Found credentials for '$appName' in vault:");
            echo PHP_EOL;
            
            if (isset($credentials['database'])) {
                echo "  📊 Database password: " . substr($credentials['database']['password'], 0, 8) . "..." . PHP_EOL;
            }
            if (isset($credentials['keycloak'])) {
                echo "  🔐 Keycloak admin password: " . substr($credentials['keycloak']['admin_password'], 0, 8) . "..." . PHP_EOL;
                echo "  🔐 Keycloak DB password: " . substr($credentials['keycloak']['db_password'], 0, 8) . "..." . PHP_EOL;
            }
            
            echo PHP_EOL;
            echo self::ansiFormat('INFO', '💡 These credentials will be used when creating the app with:');
            echo "  composer nimbus:create $appName" . PHP_EOL;
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to restore credentials: ' . $e->getMessage());
        }
    }
    
    /**
     * List apps with backed up credentials
     */
    public static function nimbusVaultList(Event $event): void
    {
        try {
            $vaultManager = new \Nimbus\Vault\VaultManager();
            
            if (!$vaultManager->isInitialized()) {
                echo self::ansiFormat('ERROR', 'Vault not initialized. Run: composer nimbus:vault-init');
                return;
            }
            
            $apps = $vaultManager->listBackedUpApps();
            
            if (empty($apps)) {
                echo self::ansiFormat('INFO', 'No app credentials found in vault.');
                echo PHP_EOL;
                echo self::ansiFormat('INFO', 'Back up app credentials with: composer nimbus:vault-backup <app-name>');
                return;
            }
            
            echo self::ansiFormat('INFO', '🔐 Apps with backed up credentials:');
            echo PHP_EOL;
            
            foreach ($apps as $app) {
                echo "  📱 {$app['name']}" . PHP_EOL;
                echo "     Backed up: {$app['backed_up_at']}" . PHP_EOL;
                echo "     Database: " . ($app['has_database'] ? '✓' : '✗') . PHP_EOL;
                echo "     Keycloak: " . ($app['has_keycloak'] ? '✓' : '✗') . PHP_EOL;
                echo PHP_EOL;
            }
            
            echo self::ansiFormat('INFO', '💡 Restore credentials with: composer nimbus:vault-restore <app-name>');
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to list vault contents: ' . $e->getMessage());
        }
    }
    
    /**
     * View passwords stored in vault for all apps
     */
    public static function nimbusVaultView(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        try {
            $vaultManager = new \Nimbus\Vault\VaultManager();
            
            if (!$vaultManager->isInitialized()) {
                echo self::ansiFormat('ERROR', 'Vault not initialized. Run: composer nimbus:vault-init');
                return;
            }
            
            // Get specific app name from arguments (optional)
            $specificApp = $args[0] ?? null;
            
            // Get all credentials
            $allCredentials = $vaultManager->getAllCredentials();
            
            if (empty($allCredentials)) {
                echo self::ansiFormat('INFO', 'No credentials found in vault.');
                return;
            }
            
            echo self::ansiFormat('INFO', '🔐 Vault Password Storage');
            echo PHP_EOL;
            
            // Filter by specific app if requested
            if ($specificApp) {
                if (!isset($allCredentials['apps'][$specificApp])) {
                    echo self::ansiFormat('ERROR', "No credentials found for app: $specificApp");
                    return;
                }
                $apps = [$specificApp => $allCredentials['apps'][$specificApp]];
            } else {
                $apps = $allCredentials['apps'] ?? [];
            }
            
            // Display credentials by app
            foreach ($apps as $appName => $credentials) {
                echo self::ansiFormat('SUCCESS', "📱 $appName");
                echo PHP_EOL;
                
                // Database credentials
                if (isset($credentials['database'])) {
                    echo "  📊 Database:" . PHP_EOL;
                    echo "     Password: " . $credentials['database']['password'] . PHP_EOL;
                }
                
                // Keycloak credentials
                if (isset($credentials['keycloak'])) {
                    echo "  🔐 Keycloak:" . PHP_EOL;
                    if (isset($credentials['keycloak']['admin_password'])) {
                        echo "     Admin Password: " . $credentials['keycloak']['admin_password'] . PHP_EOL;
                    }
                    if (isset($credentials['keycloak']['db_password'])) {
                        echo "     DB Password: " . $credentials['keycloak']['db_password'] . PHP_EOL;
                    }
                    if (isset($credentials['keycloak']['client_secret'])) {
                        echo "     Client Secret: " . $credentials['keycloak']['client_secret'] . PHP_EOL;
                    }
                }
                
                echo PHP_EOL;
            }
            
            if (!$specificApp) {
                echo self::ansiFormat('INFO', '💡 View specific app: composer nimbus:vault-view <app-name>');
            }
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to view vault contents: ' . $e->getMessage());
        }
    }

    public static function nimbusAliasTemplate(Event $event) {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        if (count($args) < 2) {
            echo self::ansiFormat('ERROR', 'Usage: composer nimbus:alias-template <alias> <template-name>');
            echo self::ansiFormat('INFO', 'Example: composer nimbus:alias-template dev nimbus-demo');
            echo PHP_EOL;
            
            // Show current aliases
            $templateManager = new \Nimbus\TemplateManager();
            $aliases = $templateManager->getAliases();
            
            if (!empty($aliases)) {
                echo self::ansiFormat('INFO', 'Current aliases:');
                foreach ($aliases as $alias => $template) {
                    echo "  $alias → $template" . PHP_EOL;
                }
            }
            
            return;
        }
        
        $alias = $args[0];
        $templateName = $args[1];
        
        try {
            $templateManager = new \Nimbus\TemplateManager();
            
            // Add the alias
            $templateManager->addAlias($alias, $templateName);
            
            echo self::ansiFormat('SUCCESS', "Alias '$alias' → '$templateName' created successfully!");
            echo self::ansiFormat('INFO', "You can now use: composer nimbus:create myapp $alias");
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to create alias: ' . $e->getMessage());
        }
    }

    public static function nimbusAliasRemove(Event $event) {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        if (empty($args)) {
            echo self::ansiFormat('ERROR', 'Usage: composer nimbus:alias-remove <alias>');
            echo self::ansiFormat('INFO', 'Example: composer nimbus:alias-remove dev');
            return;
        }
        
        $alias = $args[0];
        
        try {
            $templateManager = new \Nimbus\TemplateManager();
            $aliases = $templateManager->getAliases();
            
            if (!isset($aliases[$alias])) {
                echo self::ansiFormat('ERROR', "Alias '$alias' does not exist!");
                return;
            }
            
            $templateManager->removeAlias($alias);
            echo self::ansiFormat('SUCCESS', "Alias '$alias' removed successfully!");
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to remove alias: ' . $e->getMessage());
        }
    }

    public static function nimbusAliasList(Event $event) {
        try {
            $templateManager = new \Nimbus\TemplateManager();
            $aliases = $templateManager->getAliases();
            $templates = $templateManager->getAvailableTemplates();
            
            // Default template (hardcoded for now)
            $defaultTemplate = 'nimbus-demo';
            
            echo self::ansiFormat('INFO', '📋 Template Aliases:');
            echo PHP_EOL;
            
            if (empty($aliases)) {
                echo self::ansiFormat('WARNING', 'No aliases defined.');
                echo self::ansiFormat('INFO', 'Create one with: composer nimbus:alias-template <alias> <template-name>');
            } else {
                // Calculate column widths for better formatting
                $maxAliasLen = max(array_map('strlen', array_keys($aliases)));
                $maxTemplateLen = max(array_map('strlen', array_values($aliases)));
                
                // Header
                $header = sprintf("%-{$maxAliasLen}s  →  %-{$maxTemplateLen}s  Path", "Alias", "Template");
                echo $header . PHP_EOL;
                echo str_repeat('─', strlen($header) + 40) . PHP_EOL;
                
                foreach ($aliases as $alias => $templateName) {
                    $isDefault = ($templateName === $defaultTemplate);
                    $defaultFlag = $isDefault ? ' [DEFAULT]' : '';
                    
                    // Get the template path
                    $templatePath = isset($templates[$templateName]) 
                        ? '.installer/_templates/' . $templateName 
                        : '[Template not found]';
                    
                    $line = sprintf(
                        "%-{$maxAliasLen}s  →  %-{$maxTemplateLen}s  %s%s",
                        $alias,
                        $templateName,
                        $templatePath,
                        $defaultFlag
                    );
                    
                    if ($isDefault) {
                        echo self::ansiFormat('STAREMOJI', $line);
                    } else {
                        echo self::ansiFormat('DOTEMOJI', $line);
                    }
                }
            }
            
            echo PHP_EOL;
            echo self::ansiFormat('INFO', '📁 Available Templates:');
            echo PHP_EOL;
            
            foreach ($templates as $name => $info) {
                $isDefault = ($name === $defaultTemplate);
                $defaultFlag = $isDefault ? ' [DEFAULT]' : '';
                $path = '.installer/_templates/' . $name;
                
                $line = sprintf("  %-20s  %s%s", $name, $path, $defaultFlag);
                
                if ($isDefault) {
                    echo self::ansiFormat('STAREMOJI', $line);
                } else {
                    echo self::ansiFormat('DOTEMOJI', $line);
                }
            }
            
            echo PHP_EOL;
            echo self::ansiFormat('INFO', '💡 Commands:');
            echo "  • Create alias: composer nimbus:alias-template <alias> <template>" . PHP_EOL;
            echo "  • Remove alias: composer nimbus:alias-remove <alias>" . PHP_EOL;
            echo "  • Use template: composer nimbus:create <app-name> <alias-or-template>" . PHP_EOL;
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to list aliases: ' . $e->getMessage());
        }
    }

    /**
     * Show configuration preview before starting containers
     */
    private static function showConfigurationPreview(string $appName, $manager) {
        echo self::ansiFormat('INFO', "📋 Configuration Preview for '$appName':");
        echo PHP_EOL;
        
        try {
            // Load app configuration
            $config = $manager->loadAppConfig($appName);
            $appDir = ".installer/apps/$appName";
            
            // Basic App Info
            echo self::ansiFormat('INFO', "🔧 Basic Configuration:");
            echo "  • App Name: $appName" . PHP_EOL;
            echo "  • Template: " . ($config['type'] ?? 'nimbus-demo') . PHP_EOL;
            echo "  • Version: " . ($config['version'] ?? '1.0.0') . PHP_EOL;
            echo "  • Location: $appDir" . PHP_EOL;
            echo PHP_EOL;
            
            // Container Configuration
            echo self::ansiFormat('INFO', "🐳 Containers to be created:");
            $containers = [];
            
            // Always have app container
            $appPort = $config['containers']['app']['port'] ?? '8080';
            $containers[] = ['name' => "$appName-app", 'type' => 'PHP/Apache', 'port' => $appPort];
            
            // Database container
            if (isset($config['features']['database']) && $config['features']['database']) {
                $dbEngine = $config['containers']['db']['engine'] ?? 'postgres';
                $dbVersion = $config['containers']['db']['version'] ?? '14';
                $containers[] = ['name' => "$appName-db", 'type' => "$dbEngine:$dbVersion", 'port' => '5432 (internal)'];
            }
            
            // EDA container
            if (isset($config['features']['eda']) && $config['features']['eda']) {
                $edaPort = $config['containers']['eda']['port'] ?? '5000';
                $containers[] = ['name' => "$appName-eda", 'type' => 'Event-Driven Ansible', 'port' => $edaPort];
            }
            
            // Keycloak containers
            if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
                $keycloakPort = $config['containers']['keycloak']['port'] ?? '8080';
                $containers[] = ['name' => "$appName-keycloak", 'type' => 'Keycloak SSO', 'port' => $keycloakPort];
                $containers[] = ['name' => "$appName-keycloak-db", 'type' => 'postgres:14', 'port' => '5433 (internal)'];
            }
            
            // Display containers in a table-like format
            $maxNameLen = max(array_map(fn($c) => strlen($c['name']), $containers));
            $maxTypeLen = max(array_map(fn($c) => strlen($c['type']), $containers));
            
            foreach ($containers as $container) {
                $name = str_pad($container['name'], $maxNameLen);
                $type = str_pad($container['type'], $maxTypeLen);
                echo "  • $name  │  $type  │  Port: {$container['port']}" . PHP_EOL;
            }
            echo PHP_EOL;
            
            // Database Configuration
            if (isset($config['features']['database']) && $config['features']['database']) {
                echo self::ansiFormat('INFO', "🗄️  Database Configuration:");
                $dbConfig = $config['database'] ?? [];
                echo "  • Database Name: " . ($dbConfig['name'] ?? "{$appName}_db") . PHP_EOL;
                echo "  • Database User: " . ($dbConfig['user'] ?? "{$appName}_user") . PHP_EOL;
                echo "  • Password: " . (isset($dbConfig['password']) ? substr($dbConfig['password'], 0, 8) . '...' : '[Generated]') . PHP_EOL;
                echo PHP_EOL;
            }
            
            // Features Summary
            echo self::ansiFormat('INFO', "✨ Features Enabled:");
            $features = $config['features'] ?? [];
            foreach ($features as $feature => $enabled) {
                if ($enabled) {
                    $icon = match($feature) {
                        'database' => '🗄️',
                        'eda' => '📡',
                        'keycloak' => '🔐',
                        'certbot' => '🔒',
                        default => '✓'
                    };
                    echo "  $icon " . ucfirst($feature) . PHP_EOL;
                }
            }
            echo PHP_EOL;
            
            // URLs that will be available
            echo self::ansiFormat('INFO', "🌐 URLs after startup:");
            echo "  • Application: http://localhost:$appPort" . PHP_EOL;
            if (isset($config['features']['keycloak']) && $config['features']['keycloak']) {
                $keycloakPort = $config['containers']['keycloak']['port'] ?? '8080';
                echo "  • Keycloak Admin: http://localhost:$keycloakPort" . PHP_EOL;
                echo "  • Keycloak Config: http://localhost:$appPort/auth/configure" . PHP_EOL;
            }
            if (isset($config['features']['eda']) && $config['features']['eda']) {
                $edaPort = $config['containers']['eda']['port'] ?? '5000';
                echo "  • EDA Webhook: http://localhost:$edaPort/webhook" . PHP_EOL;
            }
            echo PHP_EOL;
            
            // Compose file location
            echo self::ansiFormat('INFO', "📄 Docker Compose File:");
            echo "  • $appName-compose.yml" . PHP_EOL;
            echo PHP_EOL;
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to load configuration: ' . $e->getMessage());
        }
    }

}
