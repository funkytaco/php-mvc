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


}
