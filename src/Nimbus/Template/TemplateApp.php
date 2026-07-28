<?php

declare(strict_types=1);

namespace Nimbus\Template;

/**
 * A template rendered into a lightweight MVC object — enough structure to
 * verify offline that the app's MVC would work at request time. Read-only:
 * built entirely by reading the template directory, never mutating it.
 *
 * Views and partials share one map because the live engine (Dependencies.php)
 * uses the same FilesystemLoader(VIEWS_DIR) for both: a partial is just a
 * view referenced via {{> name}}.
 *
 * @phpstan-import-type RenderCall from ControllerScanner
 * @phpstan-type ViewInfo array{path: string, vars: array<string, list<int>>, partials: array<string, list<int>>, syntaxError: string|null}
 */
final class TemplateApp
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, ViewInfo> $views view name ('demo/index') => info
     * @param list<RenderCall> $renderCalls
     * @param list<array{method: string, path: string}> $routes
     */
    private function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $config,
        public readonly ?string $configError,
        public readonly array $views,
        public readonly array $renderCalls,
        public readonly array $routes,
        public readonly string $viewsDir,
    ) {
    }

    public static function fromDirectory(string $templatePath): self
    {
        $name = basename($templatePath);
        $viewsDir = self::viewsDirFor($templatePath);

        [$config, $configError] = self::loadConfig($templatePath);

        $views = [];
        $viewsPath = $templatePath . '/' . $viewsDir;
        if (is_dir($viewsPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($viewsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.mustache')) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($viewsPath) + 1);
                $viewName = substr($rel, 0, -strlen('.mustache'));
                $views[$viewName] = MustacheAnalyzer::analyze((string) file_get_contents($file->getPathname()))
                    + ['path' => $viewsDir . '/' . $rel];
            }
            ksort($views);
        }

        $renderCalls = [];
        foreach (self::controllerFiles($templatePath) as $rel) {
            $source = (string) file_get_contents($templatePath . '/' . $rel);
            foreach (ControllerScanner::scan($source, $rel) as $call) {
                $renderCalls[] = $call;
            }
        }

        return new self(
            $name,
            $templatePath,
            $config,
            $configError,
            $views,
            $renderCalls,
            self::extractRoutes($templatePath),
            $viewsDir
        );
    }

    /**
     * Union of data keys across every render call targeting this view.
     * Config keys count too: getConfig() is how views get keycloak/eda flags.
     *
     * @return array{keys: list<string>, unresolved: bool}
     */
    public function dataFor(string $viewName): array
    {
        $keys = [];
        $unresolved = false;
        $found = false;

        foreach ($this->renderCalls as $call) {
            if ($call['view'] !== $viewName) {
                continue;
            }
            $found = true;
            if ($call['resolvable']) {
                $keys = array_merge($keys, $call['keys']);
            } else {
                $unresolved = true;
            }
        }

        return [
            'keys' => array_values(array_unique($keys)),
            'unresolved' => $unresolved || !$found,
        ];
    }

    /**
     * Partials referenced by any view that have no backing .mustache file.
     * These render as silent blanks at request time.
     *
     * @return array<string, list<string>> partial name => "viewPath:line" locations
     */
    public function missingPartials(): array
    {
        $missing = [];

        foreach ($this->views as $info) {
            foreach ($info['partials'] as $partial => $lines) {
                if (isset($this->views[$partial])) {
                    continue;
                }
                foreach ($lines as $line) {
                    $missing[$partial][] = $info['path'] . ':' . $line;
                }
            }
        }

        return $missing;
    }

    /**
     * Views not under partials/ that no controller ever renders.
     *
     * @return list<string>
     */
    public function unrenderedViews(): array
    {
        $rendered = array_column($this->renderCalls, 'view');
        $referenced = [];
        foreach ($this->views as $info) {
            foreach (array_keys($info['partials']) as $partial) {
                $referenced[] = $partial;
            }
        }

        $unrendered = [];
        foreach (array_keys($this->views) as $viewName) {
            if (str_starts_with($viewName, 'partials/')) {
                continue;
            }
            if (!in_array($viewName, $rendered, true) && !in_array($viewName, $referenced, true)) {
                $unrendered[] = $viewName;
            }
        }

        return $unrendered;
    }

    private static function viewsDirFor(string $templatePath): string
    {
        $nimbus = json_decode((string) @file_get_contents($templatePath . '/app.nimbus.json'), true);
        $source = $nimbus['assets']['views']['source'] ?? null;

        return is_string($source) && $source !== '' ? $source : 'Views';
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function loadConfig(string $templatePath): array
    {
        $file = $templatePath . '/app.config.php';
        if (!is_file($file)) {
            return [[], 'app.config.php not found'];
        }

        $substituted = Placeholders::substitute((string) file_get_contents($file));
        $tmp = tempnam(sys_get_temp_dir(), 'nimbus_mvc_') . '.php';
        file_put_contents($tmp, $substituted);
        try {
            $config = include $tmp;
        } catch (\Throwable $e) {
            return [[], 'app.config.php failed to load: ' . $e->getMessage()];
        } finally {
            @unlink($tmp);
        }

        if (!is_array($config)) {
            return [[], 'app.config.php must return an array'];
        }

        return [$config, null];
    }

    /**
     * @return string[] template-relative controller + routes files
     */
    private static function controllerFiles(string $templatePath): array
    {
        $files = [];

        $controllersDir = $templatePath . '/Controllers';
        if (is_dir($controllersDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($controllersDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = 'Controllers/' . substr($file->getPathname(), strlen($controllersDir) + 1);
                }
            }
        }

        // Routes files can render inline (closures) or instantiate controllers.
        if (is_file($templatePath . '/routes/CustomRoutes.php')) {
            $files[] = 'routes/CustomRoutes.php';
        }

        sort($files);

        return $files;
    }

    /**
     * Best-effort static route extraction — the factory is NEVER executed
     * (legacy factories need a live DB connection).
     *
     * @return list<array{method: string, path: string}>
     */
    private static function extractRoutes(string $templatePath): array
    {
        $file = $templatePath . '/routes/CustomRoutes.php';
        if (!is_file($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);
        preg_match_all(
            "/\[\s*['\"](GET|POST|PUT|DELETE|PATCH|OPTIONS)['\"]\s*,\s*['\"]([^'\"]*)['\"]/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $routes = [];
        foreach ($matches as $match) {
            $routes[] = ['method' => $match[1], 'path' => $match[2]];
        }

        return $routes;
    }
}
