<?php

declare(strict_types=1);

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\Template\Placeholders;
use Composer\Script\Event;

/**
 * nimbus:lint-check — template linter. Read-only: reports findings, fixes
 * nothing. Validates what nimbus:create / nimbus:install actually do with a
 * template: read app.nimbus.json, copy every asset source, substitute
 * placeholders, then include app.config.php at request time.
 *
 * Distinct from nimbus:template-check (TemplateTask), which verifies MVC
 * scaffolding and mustache rendering.
 *
 * @phpstan-import-type Finding from FindingReporting
 */
class LintTask extends BaseTask
{
    use FindingReporting;

    /** The only files allowed to carry {{PLACEHOLDER}} tokens (see CLAUDE.md). */
    private const PLACEHOLDER_FILES = ['app.config.php', 'app.nimbus.json'];

    public function __construct(?string $templatesDir = null)
    {
        $this->templatesDir = $templatesDir ?? getcwd() . '/.installer/_templates';
    }

    public function execute(Event $event): void
    {
        // Not used directly
    }

    public static function lint(Event $event): void
    {
        $task = new self();
        $task->handleLint($event);
    }

    private function handleLint(Event $event): void
    {
        $args = $event->getArguments();
        $templateName = $args[0] ?? null;

        if ($templateName) {
            $templates = [$templateName];
        } else {
            $templates = $this->getAvailableTemplates();
            if (empty($templates)) {
                echo self::ansiFormat('WARNING', 'No templates found in .installer/_templates');
                return;
            }
        }

        $results = [];
        foreach ($templates as $template) {
            $results[] = $this->checkTemplate($template);
        }

        $this->renderResults($results);
    }

    /**
     * @return array{name: string, summary: string, errors: list<Finding>, warnings: list<Finding>}
     */
    private function checkTemplate(string $templateName): array
    {
        $templatePath = $this->templatesDir . '/' . $templateName;
        $errors = [];
        $warnings = [];

        if (!is_dir($templatePath)) {
            self::addFinding($errors, 'template not found in .installer/_templates');
            return ['name' => $templateName, 'summary' => '', 'errors' => $errors, 'warnings' => $warnings];
        }

        $nimbus = $this->checkNimbusJson($templatePath, $errors, $warnings);
        $assetSources = $this->checkAssets($templatePath, $nimbus['assets'] ?? [], $errors, $warnings);
        $appConfig = $this->checkAppConfig($templatePath, $errors);

        $files = $this->templateFiles($templatePath);
        $phpCount = $this->checkPhpSyntax($templatePath, $files, $errors);
        $this->checkPlaceholders($templatePath, $files, $assetSources, $errors, $warnings);
        $this->checkFeatures($templatePath, $nimbus, $warnings);
        $this->checkGeneratorTemplates($templatePath, $appConfig, $errors);

        return [
            'name' => $templateName,
            'summary' => sprintf(
                '%d assets · %d php · %d files',
                count($nimbus['assets'] ?? []),
                $phpCount,
                count($files)
            ),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * app.nimbus.json drives AppManager::install() — without it nothing is copied.
     *
     * @param list<Finding> $errors
     * @param list<Finding> $warnings
     * @return array<string, mixed>
     */
    private function checkNimbusJson(string $templatePath, array &$errors, array &$warnings): array
    {
        $file = $templatePath . '/app.nimbus.json';

        if (!is_file($file)) {
            self::addFinding($errors, 'missing app.nimbus.json — declares features, containers and the asset map');
            return [];
        }

        $config = json_decode((string) file_get_contents($file), true);
        if (!is_array($config)) {
            self::addFinding($errors, 'app.nimbus.json is not valid JSON: ' . json_last_error_msg());
            return [];
        }

        foreach (['name', 'features', 'assets'] as $key) {
            if (!isset($config[$key])) {
                $msg = "app.nimbus.json has no \"$key\"";
                if ($key === 'assets') {
                    self::addFinding($errors, $msg . ' — nimbus:install would copy nothing');
                } else {
                    self::addFinding($warnings, $msg);
                }
            }
        }

        return $config;
    }

    /**
     * Every asset source must exist, and match the kind isFile claims it is —
     * AppManager::install() copies these paths verbatim.
     *
     * @param array<string, mixed> $assets
     * @param list<Finding> $errors
     * @param list<Finding> $warnings
     * @return string[] template-relative sources that exist
     */
    private function checkAssets(string $templatePath, array $assets, array &$errors, array &$warnings): array
    {
        $found = [];

        foreach ($assets as $key => $asset) {
            $source = is_array($asset) ? ($asset['source'] ?? null) : null;
            if (!is_string($source) || $source === '') {
                self::addFinding($errors, "asset '$key' has no \"source\"");
                continue;
            }

            if (empty($asset['target'])) {
                self::addFinding($warnings, "asset '$key' has no \"target\" — install would copy it to the project root");
            }

            $full = $templatePath . '/' . $source;
            $isFile = !empty($asset['isFile']);

            if ($isFile ? is_file($full) : is_dir($full)) {
                $found[] = $source;
            } elseif (file_exists($full)) {
                self::addFinding($errors, sprintf(
                    "asset '%s' is marked isFile=%s but %s is a %s",
                    $key,
                    $isFile ? 'true' : 'false',
                    $source,
                    is_dir($full) ? 'directory' : 'file'
                ));
            } else {
                self::addFinding($errors, "asset '$key' source does not exist: $source");
            }
        }

        return $found;
    }

    /**
     * app.config.php is included as a plain array at request time, but only
     * parses once placeholders are substituted (it holds bare tokens such as
     * 'has_eda' => {{HAS_EDA}}), so check the substituted form.
     *
     * @param list<Finding> $errors
     * @return array<string, mixed>
     */
    private function checkAppConfig(string $templatePath, array &$errors): array
    {
        $file = $templatePath . '/app.config.php';

        if (!is_file($file)) {
            self::addFinding($errors, 'missing app.config.php — runtime config every controller reads');
            return [];
        }

        $substituted = Placeholders::substitute((string) file_get_contents($file));
        $error = self::lintPhp($substituted);
        if ($error !== null) {
            self::addFinding($errors, 'app.config.php does not parse after placeholder substitution: ' . $error);
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nimbus_check_') . '.php';
        file_put_contents($tmp, $substituted);
        try {
            $config = include $tmp;
        } catch (\Throwable $e) {
            self::addFinding($errors, 'app.config.php threw on include: ' . $e->getMessage());
            return [];
        } finally {
            @unlink($tmp);
        }

        if (!is_array($config)) {
            self::addFinding($errors, 'app.config.php must return an array');
            return [];
        }

        return $config;
    }

    /**
     * Lint every PHP file the way it will exist at runtime: after substitution.
     *
     * @param string[] $files template-relative paths
     * @param list<Finding> $errors
     * @return int files linted
     */
    private function checkPhpSyntax(string $templatePath, array $files, array &$errors): int
    {
        $count = 0;

        foreach ($files as $rel) {
            if (!str_ends_with($rel, '.php') || $rel === 'app.config.php') {
                continue; // app.config.php is checked separately, including its include
            }

            $count++;
            $error = self::lintPhp(Placeholders::substitute((string) file_get_contents($templatePath . '/' . $rel)));
            if ($error !== null) {
                self::addFinding($errors, 'PHP syntax error: ' . $error, $rel);
            }
        }

        return $count;
    }

    /**
     * Two distinct problems:
     *  - an unknown {{TOKEN}} is never substituted and ships verbatim;
     *  - a known token inside a copied asset resolves to one app's identity,
     *    which nimbus:commit would then bake back into the shared template
     *    (CLAUDE.md: read from $appConfig at runtime instead).
     *
     * @param string[] $files template-relative paths
     * @param string[] $assetSources
     * @param list<Finding> $errors
     * @param list<Finding> $warnings
     */
    private function checkPlaceholders(
        string $templatePath,
        array $files,
        array $assetSources,
        array &$errors,
        array &$warnings
    ): void {
        foreach ($files as $rel) {
            if (in_array($rel, self::PLACEHOLDER_FILES, true)) {
                continue;
            }

            $content = self::readText($templatePath . '/' . $rel);
            if ($content === null || !preg_match_all('/\{\{([A-Z][A-Z0-9_]*)\}\}/', $content, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // Only PHP and views can read $appConfig; shell/yml assets have no
            // other way to learn the app name, so placeholders are fine there.
            $isCode = str_ends_with($rel, '.php') || str_ends_with($rel, '.mustache');
            $inAsset = $isCode && self::isUnderAsset($rel, $assetSources);

            foreach ($m[1] as $i => $match) {
                $token = $match[0];
                $line = substr_count($content, "\n", 0, (int) $m[0][$i][1]) + 1;

                if (!Placeholders::isToken($token)) {
                    self::addFinding($errors, "unknown placeholder {{{$token}}} is never substituted", "$rel:$line");
                } elseif ($inAsset) {
                    self::addFinding(
                        $warnings,
                        'app-specific {{PLACEHOLDER}} in a copied asset — read the value from $appConfig at runtime',
                        "$rel:$line ({{{$token}}})"
                    );
                }
            }
        }
    }

    /**
     * A declared feature needs the files that back it.
     *
     * @param array<string, mixed> $nimbus
     * @param list<Finding> $warnings
     */
    private function checkFeatures(string $templatePath, array $nimbus, array &$warnings): void
    {
        $features = $nimbus['features'] ?? [];

        if (!empty($features['database']) && !is_file($templatePath . '/database/schema.sql')) {
            self::addFinding($warnings, 'features.database is on but database/schema.sql is missing');
        }

        if (!empty($features['eda'])
            && !is_dir($templatePath . '/rulebooks')
            && !is_dir($templatePath . '/eda/rulebooks')
        ) {
            self::addFinding($warnings, 'features.eda is on but there is no rulebooks/ or eda/rulebooks/ directory');
        }

        if (!empty($features['keycloak']) && empty($nimbus['keycloak'])) {
            self::addFinding($warnings, 'features.keycloak is on but app.nimbus.json has no "keycloak" section');
        }
    }

    /**
     * FileGenerator failures are only error_log()'d during create, so a missing
     * generator template source silently produces no output file.
     *
     * @param array<string, mixed> $appConfig
     * @param list<Finding> $errors
     */
    private function checkGeneratorTemplates(string $templatePath, array $appConfig, array &$errors): void
    {
        foreach (($appConfig['generator_templates'] ?? []) as $source => $spec) {
            if (!is_file($templatePath . '/' . $source)) {
                self::addFinding($errors, "generator_templates source does not exist: $source");
            }
            if (empty($spec['output_path'])) {
                self::addFinding($errors, "generator_templates entry has no output_path: $source");
            }
        }
    }

    /**
     * Print one line per template, then only what is wrong with it.
     *
     * @param list<array{name: string, summary: string, errors: list<Finding>, warnings: list<Finding>}> $results
     */
    private function renderResults(array $results): void
    {
        $width = max(array_map(fn (array $r): int => strlen($r['name']), $results)) + 2;
        $failed = 0;
        $warned = 0;

        foreach ($results as $result) {
            $errors = $result['errors'];
            $warnings = $result['warnings'];

            if ($errors) {
                $failed++;
                $status = self::paint('bold_red', 'FAIL');
            } elseif ($warnings) {
                $warned++;
                $status = self::paint('yellow', 'WARN');
            } else {
                $status = self::paint('bold_green', ' OK ');
            }

            $meta = array_filter([self::countsLabel($errors, $warnings), $result['summary']]);
            echo str_pad($result['name'], $width) . $status . '  '
                . self::paint('dark_gray', implode('  ·  ', $meta)) . PHP_EOL;

            foreach ($errors as $finding) {
                echo self::renderFinding('bold_red', 'E', $finding);
            }
            foreach ($warnings as $finding) {
                echo self::renderFinding('yellow', 'W', $finding);
            }
        }

        $total = count($results);
        echo PHP_EOL . sprintf(
            "%d template%s — %d ok, %d warning, %d failed" . PHP_EOL,
            $total,
            $total === 1 ? '' : 's',
            $total - $failed - $warned,
            $warned,
            $failed
        );

        if ($failed > 0) {
            exit(1);
        }
    }

    /**
     * @return string|null the parse error, or null when the code is valid
     */
    private static function lintPhp(string $code): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nimbus_lint_');
        file_put_contents($tmp, $code);
        $output = (string) shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        if (str_contains($output, 'No syntax errors')) {
            return null;
        }

        $first = strtok(trim($output), "\n");
        return trim(preg_replace('/ in \/.*$/', '', (string) $first) ?? '');
    }

    /**
     * @param string[] $assetSources
     */
    private static function isUnderAsset(string $rel, array $assetSources): bool
    {
        foreach ($assetSources as $source) {
            if ($rel === $source || str_starts_with($rel, rtrim($source, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null file contents, or null when binary or oversized
     */
    private static function readText(string $path): ?string
    {
        if (filesize($path) > 1048576) {
            return null;
        }

        $content = (string) file_get_contents($path);

        return str_contains($content, "\0") ? null : $content;
    }
}
