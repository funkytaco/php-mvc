<?php

namespace Nimbus\Core;

use Composer\Script\Event;

abstract class BaseTask
{
    protected static $foreground = [
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
    ];

    protected static $background = [
        'black' => '40',
        'red' => '41',
        'magenta' => '45',
        'yellow' => '43',
        'green' => '42',
        'blue' => '44',
        'cyan' => '46',
        'light_gray' => '47',
    ];

    protected static function ansiFormat($type, $str = ''): string
    {
        $types = [
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
        ];
        
        $emojiBullets = [
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
        ];
        
        if (isset($emojiBullets[$type])) {
            return $emojiBullets[$type] . ' ' . $str . PHP_EOL;
        }

        $ansi_start = "\033[". $types[$type] ."m";
        $ansi_end = "\033[0m";
        $ansi_type_start = "\033[". $types['INFO'] ."m";

        return $ansi_type_start . "[$type] " . $ansi_end . $ansi_start .  $str . $ansi_end . PHP_EOL;
    }

    protected static function copyAssetsRecursive($source, $destination, $event): bool
    {
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
                    self::deleteAssetsRecursive($destination);
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

    protected static function deleteAssetsRecursive($dir): bool
    {
        $files = array_diff(scandir($dir), array('.','..'));

        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::deleteAssetsRecursive("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }

    protected static function copyAssets($source, $destination, $isFile, $event): void
    {
        if ($isFile == TRUE) {
            echo self::ansiFormat('RUNNING>', 'copy Assets for: '. $source);
            touch($destination);

            if(!is_file($destination)) {
                self::copyAssetsRecursive($source, $destination, $event);
            } else {
                copy($source, $destination);
            }
        } else {
            try {
                if (self::copyAssetsRecursive($source, $destination, $event) == true) {
                    echo self::ansiFormat('SUCCESS', 'Copied assets from "'.realpath($source).'" to "'.realpath($destination).'".'.PHP_EOL);
                } else {
                    echo self::ansiFormat('ERROR', 'Copy failed! Unable to copy assets from "'.realpath($source)
                    .'" to "'.realpath($destination).'"');
                }
            } catch(\Exception $e) {
                echo self::ansiFormat('ERROR', 'Copy failed! Unable to copy assets from "'.realpath($source)
                .'" to "'.realpath($destination).'"');
            }
        }
    }

    protected static function areComposerPackagesInstalled(Event $event): bool
    {
        return is_file('vendor/autoload.php');
    }

    abstract public function execute(Event $event): void;
}