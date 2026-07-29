<?php

declare(strict_types=1);

namespace Nimbus\App;

/**
 * Picks the manager that knows how a given app was made.
 *
 * The discriminator is source.kind in the app's app.nimbus.json. Apps
 * created before git support existed have no source block at all, so an
 * absent kind means "scaffolded from a template" — the historical default.
 *
 * Kept separate from AppManager so the base class carries no knowledge of
 * its own subclasses.
 */
final class AppManagerFactory
{
    /**
     * Manager for an existing app.
     */
    public static function forApp(string $appName, ?string $baseDir = null): AppManager
    {
        $baseDir ??= getcwd();

        return match (self::sourceKind($appName, $baseDir)) {
            'git' => new GitAppManager($baseDir),
            default => new MVCAppManager($baseDir),
        };
    }

    /**
     * How an app's code got here: 'git', or null for template-scaffolded
     * (including every app created before source blocks existed).
     */
    public static function sourceKind(string $appName, ?string $baseDir = null): ?string
    {
        $baseDir ??= getcwd();
        $configFile = $baseDir . '/.installer/apps/' . $appName . '/app.nimbus.json';

        if (!is_file($configFile)) {
            return null;
        }

        $config = json_decode((string) file_get_contents($configFile), true);

        return is_array($config) ? ($config['source']['kind'] ?? null) : null;
    }
}
