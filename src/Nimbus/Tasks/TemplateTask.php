<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Nimbus\Template\TemplateApp;
use Composer\Script\Event;

/**
 * nimbus:template-scaffold — generate a vanilla template.
 * nimbus:template-check    — verify a template's MVC would render. Read-only:
 * reports findings, fixes NOTHING — fixing is the human's job.
 *
 * template-check builds each template into a TemplateApp (the MVC object) and
 * verifies: the MVC scaffolding is present (the vanilla scaffold is the source
 * of truth, see STRUCTURE), mustache views parse and smoke-render, referenced
 * partials exist, and the variables views expect are provided by controllers.
 * Linting is a separate concern — composer nimbus:lint-check (LintTask).
 *
 * @phpstan-import-type Finding from FindingReporting
 * @phpstan-type Section array{label: string, summary: string, errors: list<Finding>, warnings: list<Finding>, notes: list<string>}
 */
class TemplateTask extends BaseTask
{
    use FindingReporting;

    /**
     * Expected MVC scaffolding, derived from what nimbus:template-scaffold
     * generates (the vanilla scaffold is the source of truth). Directory
     * entries assert "contains at least one match", not exact filenames, so
     * real templates with their own naming stay green.
     *
     * @var array<string, array{required: bool, expect: string}> expect: 'file' | 'dir' | glob suffix
     */
    private const STRUCTURE = [
        'app.config.php'          => ['required' => true,  'expect' => 'file'],
        'app.nimbus.json'         => ['required' => true,  'expect' => 'file'],
        'routes/CustomRoutes.php' => ['required' => true,  'expect' => 'file'],
        'Controllers'             => ['required' => true,  'expect' => '.php'],
        'Views'                   => ['required' => true,  'expect' => '.mustache'],
        'Models'                  => ['required' => false, 'expect' => '.php'],
        'template.json'           => ['required' => false, 'expect' => 'file'],
        'database/schema.sql'     => ['required' => false, 'expect' => 'file'],
        'public'                  => ['required' => false, 'expect' => 'dir'],
    ];

    public function __construct(?string $templatesDir = null)
    {
        $this->templatesDir = $templatesDir ?? getcwd() . '/.installer/_templates';
    }
    
    public function execute(Event $event): void
    {
        // Not used directly
    }
    
    /**
     * Scaffold a new template
     */
    public static function scaffold(Event $event): void
    {
        $task = new self();
        $task->handleScaffold($event);
    }
    
    /**
     * Clone an existing template under a new name.
     */
    public static function cloneTemplate(Event $event): void
    {
        $task = new self();
        $task->handleClone($event);
    }

    private function handleClone(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();

        $source = $args[0] ?? $io->ask('Source template to clone: ');
        $newName = $args[1] ?? $io->ask('Name for the new template: ');

        if (ContainerTask::isCancelChoice($source) || ContainerTask::isCancelChoice($newName)) {
            echo self::ansiFormat('INFO', 'Cancelled — nothing was cloned.');
            return;
        }

        // Aliases resolve so `nimbus:template-clone myalias new-name` works;
        // outside a repo root (tests) the config may be unreadable — then the
        // literal name is simply used as-is.
        try {
            $source = \Nimbus\Template\TemplateConfig::getInstance()->resolveTemplate($source);
            $aliases = \Nimbus\Template\TemplateConfig::getInstance()->getTemplateAliases();
        } catch (\Throwable $e) {
            $aliases = [];
        }

        if (isset($aliases[$newName])) {
            echo self::ansiFormat('ERROR', "'$newName' is already an alias for '{$aliases[$newName]}' — pick another name or remove the alias first.");
            return;
        }

        if (!$this->performClone((string) $source, (string) $newName)) {
            return;
        }

        echo self::ansiFormat('SUCCESS', "Template '$newName' created from '$source'.");
        echo self::ansiFormat('INFO', '📁 ' . $this->templatesDir . '/' . $newName);
        echo PHP_EOL;
        echo self::ansiFormat('INFO', 'Next steps:');
        echo "  composer nimbus:create <app> $newName   # create an app from it" . PHP_EOL;
        echo "  composer nimbus:template-check $newName   # verify it renders" . PHP_EOL;
        echo "  composer nimbus:lint-check $newName" . PHP_EOL;
    }

    /**
     * The clone itself, free of the Composer event so tests can drive it.
     * Prints the reason and returns false on refusal; every refusal happens
     * before anything is written.
     */
    public function performClone(string $source, string $newName): bool
    {
        $sourcePath = $this->templatesDir . '/' . $source;
        $targetPath = $this->templatesDir . '/' . $newName;

        if (!is_dir($sourcePath)) {
            echo self::ansiFormat('ERROR', "Template '$source' not found in " . $this->templatesDir);
            return false;
        }
        if (($error = \Nimbus\Template\TemplateManager::templateNameError($newName)) !== null) {
            echo self::ansiFormat('ERROR', $error . " (got '$newName')");
            echo self::ansiFormat('INFO', 'Closest valid name: ' . \Nimbus\Template\TemplateManager::suggestTemplateName($newName));
            return false;
        }
        if (is_dir($targetPath)) {
            echo self::ansiFormat('ERROR', "Template '$newName' already exists.");
            return false;
        }
        // The mirror of app creation refusing template names: without this,
        // the same ambiguity is simply re-creatable from the other side.
        if (is_dir(dirname($this->templatesDir) . '/apps/' . $newName)) {
            echo self::ansiFormat('ERROR', "'$newName' is the name of an existing app — apps and templates must not share names.");
            return false;
        }

        (new \Nimbus\Template\TemplateManager($this->templatesDir))->copyTemplate($source, $targetPath);

        // The one load-bearing self-reference: created apps carry this `type`
        // in their own app.nimbus.json, and nimbus:commit / feature scaffolding
        // resolve the template directory from it. Left as the source's name,
        // every app cloned from this template would commit back to the parent.
        $configFile = $targetPath . '/app.nimbus.json';
        if (is_file($configFile)) {
            $config = json_decode((string) file_get_contents($configFile), true);
            if (is_array($config)) {
                $config['type'] = $newName;
                file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        // Metadata for nimbus:template-check; recorded provenance either way.
        $metaFile = $targetPath . '/template.json';
        $meta = is_file($metaFile)
            ? (array) json_decode((string) file_get_contents($metaFile), true)
            : ['description' => "Cloned from $source", 'version' => '1.0.0'];
        $meta['name'] = $newName;
        $meta['cloned_from'] = $source;
        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true;
    }

    private function handleScaffold(Event $event): void
    {
        $io = $event->getIO();
        $args = $event->getArguments();
        
        // Get template name from arguments or ask
        $templateName = $args[0] ?? null;
        if (!$templateName) {
            $templateName = $io->ask('Template name (e.g., my-custom-app): ');
        }
        
        if (!$templateName) {
            echo self::ansiFormat('ERROR', 'Template name is required.');
            return;
        }
        
        // Same rule clone enforces — one vocabulary, one place
        if (($error = \Nimbus\Template\TemplateManager::templateNameError($templateName)) !== null) {
            echo self::ansiFormat('ERROR', $error . " (got '$templateName')");
            echo self::ansiFormat('INFO', 'Closest valid name: ' . \Nimbus\Template\TemplateManager::suggestTemplateName($templateName));
            return;
        }
        
        if (is_dir(dirname($this->templatesDir) . '/apps/' . $templateName)) {
            echo self::ansiFormat('ERROR', "'$templateName' is the name of an existing app — apps and templates must not share names.");
            return;
        }

        $templatePath = $this->templatesDir . '/' . $templateName;

        // Check if template already exists
        if (is_dir($templatePath)) {
            echo self::ansiFormat('ERROR', "Template '$templateName' already exists.");
            return;
        }
        
        try {
            // Create template directory structure
            $this->createTemplateStructure($templatePath, $templateName);
            
            echo self::ansiFormat('SUCCESS', "Template '$templateName' scaffolded successfully!");
            echo self::ansiFormat('INFO', "Template location: .installer/_templates/$templateName");
            echo PHP_EOL;
            echo self::ansiFormat('INFO', 'Template structure created:');
            echo "  ✓ Controllers/IndexController.php" . PHP_EOL;
            echo "  ✓ Models/ExampleModel.php" . PHP_EOL;
            echo "  ✓ Views/index.mustache" . PHP_EOL;
            echo "  ✓ Views/layout.mustache" . PHP_EOL;
            echo "  ✓ public/assets/css/style.css" . PHP_EOL;
            echo "  ✓ routes/CustomRoutes.php" . PHP_EOL;
            echo "  ✓ database/schema.sql" . PHP_EOL;
            echo "  ✓ app.config.php" . PHP_EOL;
            echo "  ✓ app.nimbus.json (framework config)" . PHP_EOL;
            echo "  ✓ template.json (metadata)" . PHP_EOL;
            echo PHP_EOL;
            echo self::ansiFormat('INFO', 'Next steps:');
            echo "  1. Customize the template files in .installer/_templates/$templateName" . PHP_EOL;
            echo "  2. Update template.json with your template description" . PHP_EOL;
            echo "  3. Run 'composer nimbus:lint-check $templateName' to validate" . PHP_EOL;
            echo "  4. Test with 'composer nimbus:create test-app' and select your template" . PHP_EOL;
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to scaffold template: ' . $e->getMessage());
        }
    }
    
    /**
     * nimbus:template-check [<name>] — verify MVC scaffolding + mustache
     * rendering. Read-only; exits 1 when any template has errors.
     */
    public static function check(Event $event): void
    {
        $task = new self();
        $task->handleCheck($event);
    }

    private function handleCheck(Event $event): void
    {
        $args = $event->getArguments();
        $templateName = $args[0] ?? null;

        $templates = $templateName ? [$templateName] : $this->getAvailableTemplates();
        if (empty($templates)) {
            echo self::ansiFormat('WARNING', 'No templates found in .installer/_templates');
            return;
        }

        $ok = $warned = $failed = 0;

        foreach ($templates as $template) {
            $sections = $this->checkTemplateMvc($template);
            $errorCount = array_sum(array_map(fn (array $s): int => count($s['errors']), $sections));
            $warningCount = array_sum(array_map(fn (array $s): int => count($s['warnings']), $sections));

            $this->renderTemplateReport($template, $sections, $errorCount, $warningCount);

            if ($errorCount) {
                $failed++;
            } elseif ($warningCount) {
                $warned++;
            } else {
                $ok++;
            }
        }

        echo self::hr();
        $total = count($templates);
        echo sprintf(
            '%d template%s — %d ok, %d with warnings, %d failed',
            $total,
            $total === 1 ? '' : 's',
            $ok,
            $warned,
            $failed
        ) . PHP_EOL;

        if ($failed > 0) {
            exit(1);
        }
    }

    /**
     * Build the MVC object and run every check against it.
     *
     * @return list<Section>
     */
    private function checkTemplateMvc(string $templateName): array
    {
        $templatePath = $this->templatesDir . '/' . $templateName;

        if (!is_dir($templatePath)) {
            $errors = [];
            self::addFinding($errors, 'template not found in .installer/_templates');
            return [['label' => 'structure', 'summary' => '', 'errors' => $errors, 'warnings' => [], 'notes' => []]];
        }

        $app = TemplateApp::fromDirectory($templatePath);

        return [
            $this->checkStructure($templatePath, $app),
            $this->checkViews($templatePath, $app),
            $this->checkVariables($app),
        ];
    }

    /**
     * MVC scaffolding present? The vanilla scaffold is the source of truth.
     *
     * @return Section
     */
    private function checkStructure(string $templatePath, TemplateApp $app): array
    {
        $errors = [];
        $warnings = [];
        $marks = [];

        foreach (self::STRUCTURE as $entry => $rule) {
            $full = $templatePath . '/' . $entry;
            $present = match ($rule['expect']) {
                'file' => is_file($full),
                'dir' => is_dir($full),
                default => self::countFilesWithSuffix($full, $rule['expect']) > 0,
            };

            if ($present) {
                if ($rule['expect'] === 'file' || $rule['expect'] === 'dir') {
                    $marks[] = basename($entry, '.php') . ' ✓';
                } else {
                    $marks[] = strtolower($entry) . ' ' . self::countFilesWithSuffix($full, $rule['expect']);
                }
            } elseif ($rule['required']) {
                self::addFinding($errors, "missing required $entry (scaffold provides it — app cannot boot without it)");
            } else {
                self::addFinding($warnings, "missing optional $entry (vanilla scaffold provides it)");
            }
        }

        $marks[] = 'routes ' . count($app->routes);

        return [
            'label' => 'structure',
            'summary' => implode(' · ', $marks),
            'errors' => $errors,
            'warnings' => $warnings,
            'notes' => [],
        ];
    }

    /**
     * Mustache rendering works: config loads (controllers read it), every view
     * parses, every referenced partial exists (missing partials render as
     * SILENT blanks at request time), and each page view smoke-renders through
     * the same engine configuration the live app uses (Dependencies.php).
     *
     * @return Section
     */
    private function checkViews(string $templatePath, TemplateApp $app): array
    {
        $errors = [];
        $warnings = [];

        if ($app->configError !== null) {
            self::addFinding($errors, $app->configError . ' — controllers read this at request time');
        }

        $partialCount = 0;
        foreach ($app->views as $viewName => $info) {
            if (str_starts_with($viewName, 'partials/')) {
                $partialCount++;
            }
            if ($info['syntaxError'] !== null) {
                self::addFinding($errors, 'view does not parse: ' . $info['syntaxError'], $info['path']);
            }
        }

        foreach ($app->missingPartials() as $partial => $locations) {
            foreach ($locations as $location) {
                self::addFinding($errors, "partial not found: $partial — renders blank at runtime", $location);
            }
        }

        $rendered = $this->smokeRenderViews($templatePath, $app, $errors);

        return [
            'label' => 'views',
            'summary' => sprintf(
                '%d views · %d partials · %d smoke-rendered',
                count($app->views) - $partialCount,
                $partialCount,
                $rendered
            ),
            'errors' => $errors,
            'warnings' => $warnings,
            'notes' => [],
        ];
    }

    /**
     * Render every page view through the live engine configuration with the
     * controller-provided keys as context. Output is discarded — this only
     * proves the render call would not throw.
     *
     * @param list<Finding> $errors
     * @return int views rendered
     */
    private function smokeRenderViews(string $templatePath, TemplateApp $app, array &$errors): int
    {
        $viewsPath = $templatePath . '/' . $app->viewsDir;
        if (!is_dir($viewsPath)) {
            return 0;
        }

        // Mirror src/Dependencies.php: same loader for views and partials.
        $options = ['extension' => '.mustache'];
        $engine = new \Mustache_Engine([
            'loader' => new \Mustache_Loader_FilesystemLoader($viewsPath, $options),
            'partials_loader' => new \Mustache_Loader_FilesystemLoader($viewsPath, $options),
        ]);

        $rendered = 0;
        foreach ($app->views as $viewName => $info) {
            if (str_starts_with($viewName, 'partials/') || $info['syntaxError'] !== null) {
                continue;
            }

            $context = array_fill_keys($app->dataFor($viewName)['keys'], '');
            try {
                $engine->render($viewName, $context);
                $rendered++;
            } catch (\Throwable $e) {
                self::addFinding($errors, 'view failed to render: ' . $e->getMessage(), $info['path']);
            }
        }

        return $rendered;
    }

    /**
     * Every root-level variable a view uses should be provided by some
     * controller render call. Mustache renders missing variables as blank
     * (no error), so these are warnings for the human to judge.
     *
     * @return Section
     */
    private function checkVariables(TemplateApp $app): array
    {
        $warnings = [];
        $notes = [];
        $covered = 0;
        $skipped = 0;

        foreach ($app->views as $viewName => $info) {
            if (str_starts_with($viewName, 'partials/')) {
                continue; // partials inherit the including view's context
            }

            $data = $app->dataFor($viewName);
            if ($data['unresolved']) {
                $skipped++;
                continue;
            }

            $covered++;
            foreach ($info['vars'] as $var => $lines) {
                if (in_array($var, $data['keys'], true)) {
                    continue;
                }
                foreach ($lines as $line) {
                    self::addFinding(
                        $warnings,
                        "$viewName: {{{$var}}} not provided by any controller",
                        $info['path'] . ':' . $line
                    );
                }
            }
        }

        foreach ($app->unrenderedViews() as $viewName) {
            self::addFinding($warnings, "$viewName is never rendered by any controller or included as a partial");
        }

        if ($skipped > 0) {
            $notes[] = "coverage skipped for $skipped view" . ($skipped === 1 ? '' : 's')
                . ' — controller data not statically resolvable';
        }

        return [
            'label' => 'variables',
            'summary' => sprintf('%d views checked · %d skipped', $covered, $skipped),
            'errors' => [],
            'warnings' => $warnings,
            'notes' => $notes,
        ];
    }

    /**
     * One block per template: labeled ─ rule, one [TAG] line per section
     * (passing sections stay one line), findings expanded beneath.
     *
     * @param list<Section> $sections
     */
    private function renderTemplateReport(string $name, array $sections, int $errorCount, int $warningCount): void
    {
        echo self::hr($name);

        foreach ($sections as $section) {
            $status = $section['errors'] ? 'ERROR' : ($section['warnings'] ? 'WARNING' : 'SUCCESS');
            $label = str_pad($section['label'], 11);
            $counts = self::countsLabel($section['errors'], $section['warnings']);
            $summary = implode('  ·  ', array_filter([$counts, $section['summary']]));

            echo self::ansiFormat($status, $label . $summary);

            foreach ($section['errors'] as $finding) {
                echo self::renderFinding('bold_red', 'E', $finding);
            }
            foreach ($section['warnings'] as $finding) {
                echo self::renderFinding('yellow', 'W', $finding);
            }
            foreach ($section['notes'] as $note) {
                echo '  ' . self::paint('dark_gray', 'i  ' . $note) . PHP_EOL;
            }
        }

        if ($errorCount) {
            $verdict = self::paint('bold_red', 'FAIL');
        } elseif ($warningCount) {
            $verdict = self::paint('yellow', 'WARN');
        } else {
            $verdict = self::paint('bold_green', 'OK');
        }
        echo $name . ' — ' . $verdict
            . self::paint('dark_gray', sprintf(' · %d errors, %d warnings', $errorCount, $warningCount))
            . PHP_EOL;
    }

    /**
     * Recursive count of files with a suffix under a directory.
     */
    private static function countFilesWithSuffix(string $dir, string $suffix): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $count++;
            }
        }

        return $count;
    }
    
    /**
     * Create template directory structure
     */
    private function createTemplateStructure(string $templatePath, string $templateName): void
    {
        // Create directories
        mkdir($templatePath, 0755, true);
        mkdir($templatePath . '/Controllers', 0755, true);
        mkdir($templatePath . '/Models', 0755, true);
        mkdir($templatePath . '/Views', 0755, true);
        mkdir($templatePath . '/public/assets/css', 0755, true);
        mkdir($templatePath . '/public/assets/js', 0755, true);
        mkdir($templatePath . '/routes', 0755, true);
        mkdir($templatePath . '/database', 0755, true);
        
        // Create template.json metadata
        $metadata = [
            'name' => $templateName,
            'description' => 'Custom template generated by scaffold',
            'version' => '1.0.0',
            'author' => 'Generated',
            'features' => [
                'database' => true,
                'eda' => false,
                'keycloak' => false
            ],
            'created' => date('Y-m-d H:i:s')
        ];
        file_put_contents($templatePath . '/template.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Create app.nimbus.json template (required by the framework)
        $nimbusConfig = [
            'name' => '{{APP_NAME}}',
            'version' => '1.0.0',
            'type' => $templateName,
            'description' => 'Custom template generated by scaffold',
            'features' => [
                'database' => true,
                'eda' => false,
                'certbot' => false,
                'keycloak' => false
            ],
            'containers' => [
                'app' => [
                    'port' => '{{APP_PORT}}'
                ],
                'db' => [
                    'engine' => 'postgres',
                    'version' => '14'
                ],
                'eda' => [
                    'image' => \Nimbus\App\AppManager::DEFAULT_EDA_IMAGE,
                    'rulebooks_dir' => 'rulebooks'
                ],
                'keycloak' => [
                    'image' => 'quay.io/keycloak/keycloak:latest',
                    'port' => '8080',
                    'admin_user' => 'admin',
                    'admin_password' => '{{KEYCLOAK_ADMIN_PASSWORD}}',
                    'database' => 'keycloak_db'
                ],
                'keycloak-db' => [
                    'image' => 'postgres:14',
                    'database' => 'keycloak_db',
                    'user' => 'keycloak',
                    'password' => '{{KEYCLOAK_DB_PASSWORD}}'
                ]
            ],
            'database' => [
                'name' => '{{DB_NAME}}',
                'user' => '{{DB_USER}}',
                'password' => '{{DB_PASSWORD}}'
            ],
            'keycloak' => [
                'realm' => '{{KEYCLOAK_REALM}}',
                'client_id' => '{{KEYCLOAK_CLIENT_ID}}',
                'client_secret' => '{{KEYCLOAK_CLIENT_SECRET}}',
                'auth_url' => 'http://{{APP_NAME}}-keycloak:8080',
                'redirect_uri' => 'http://localhost:{{APP_PORT}}/auth/callback'
            ],
            'assets' => [
                'controllers' => [
                    'source' => 'Controllers',
                    'target' => 'app/Controllers',
                    'isFile' => false
                ],
                'models' => [
                    'source' => 'Models',
                    'target' => 'app/Models',
                    'isFile' => false
                ],
                'views' => [
                    'source' => 'Views',
                    'target' => 'app/Views',
                    'isFile' => false
                ],
                'routes' => [
                    'source' => 'routes/CustomRoutes.php',
                    'target' => 'app/CustomRoutes.php',
                    'isFile' => true
                ],
                'config' => [
                    'source' => 'app.config.php',
                    'target' => 'app/app.config.php',
                    'isFile' => true
                ]
            ]
        ];
        file_put_contents($templatePath . '/app.nimbus.json', json_encode($nimbusConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Create Controllers/IndexController.php
        $controllerContent = <<<'PHP'
<?php

namespace App\Controllers;

use Main\Controllers\BaseController;

class IndexController extends BaseController
{
    public function indexAction($request, $response, $args)
    {
        $this->logger->info('Index page accessed');
        
        $data = [
            'title' => '{{APP_NAME}} Application',
            'app_name' => '{{APP_NAME}}',
            'message' => 'Welcome to your {{APP_NAME}} application!',
            'features' => [
                'Database ready',
                'MVC architecture',
                'Mustache templates',
                'PSR-7 compliant'
            ]
        ];
        
        return $this->renderTemplate($response, 'index', $data);
    }
    
    public function aboutAction($request, $response, $args)
    {
        $data = [
            'title' => 'About {{APP_NAME}}',
            'app_name' => '{{APP_NAME}}',
            'description' => 'This is a Nimbus MVC application.'
        ];
        
        return $this->renderTemplate($response, 'about', $data);
    }
}
PHP;
        file_put_contents($templatePath . '/Controllers/IndexController.php', $controllerContent);
        
        // Create Models/ExampleModel.php
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Main\Models\BaseModel;

class ExampleModel extends BaseModel
{
    protected string $table = '{{APP_NAME_LOWER}}_data';
    
    public function getAllData(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} (name, value, created_at) VALUES (?, ?, NOW())"
        );
        $stmt->execute([$data['name'], $data['value'] ?? null]);
        return (int)$this->db->lastInsertId();
    }
    
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET name = ?, value = ?, updated_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$data['name'], $data['value'] ?? null, $id]);
    }
    
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
PHP;
        file_put_contents($templatePath . '/Models/ExampleModel.php', $modelContent);
        
        // Create Views/layout.mustache
        $layoutContent = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}}</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header>
        <nav class="navbar">
            <div class="container">
                <h1>{{app_name}}</h1>
                <ul class="nav-menu">
                    <li><a href="/">Home</a></li>
                    <li><a href="/about">About</a></li>
                </ul>
            </div>
        </nav>
    </header>
    
    <main class="container">
        {{{content}}}
    </main>
    
    <footer>
        <div class="container">
            <p>&copy; 2024 {{app_name}}. Built with Nimbus MVC.</p>
        </div>
    </footer>
</body>
</html>
HTML;
        file_put_contents($templatePath . '/Views/layout.mustache', $layoutContent);
        
        // Create Views/index.mustache
        $indexContent = <<<'HTML'
<div class="hero">
    <h2>{{message}}</h2>
    <p>Your {{app_name}} application is up and running!</p>
</div>

<div class="features">
    <h3>Features:</h3>
    <ul>
        {{#features}}
        <li>{{.}}</li>
        {{/features}}
    </ul>
</div>

<div class="info">
    <p>Start building your {{app_name}} application by editing the files in:</p>
    <code>.installer/apps/{{app_name}}/</code>
</div>
HTML;
        file_put_contents($templatePath . '/Views/index.mustache', $indexContent);
        
        // Create Views/about.mustache
        $aboutContent = <<<'HTML'
<div class="page">
    <h2>About</h2>
    <p>{{description}}</p>
    
    <h3>Built with Nimbus MVC Framework</h3>
    <p>This application uses:</p>
    <ul>
        <li>PHP 8.3+</li>
        <li>PostgreSQL Database</li>
        <li>Mustache Templates</li>
        <li>PSR-7 HTTP Messages</li>
        <li>Containerized with Podman</li>
    </ul>
</div>
HTML;
        file_put_contents($templatePath . '/Views/about.mustache', $aboutContent);
        
        // Create public/assets/css/style.css
        $cssContent = <<<'CSS'
/* Reset and Base Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    line-height: 1.6;
    color: #333;
    background-color: #f5f5f5;
}

/* Container */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Header */
header {
    background-color: #2c3e50;
    color: white;
    padding: 1rem 0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.navbar h1 {
    display: inline-block;
    font-size: 1.5rem;
}

.nav-menu {
    display: inline-block;
    list-style: none;
    float: right;
    margin-top: 5px;
}

.nav-menu li {
    display: inline;
    margin-left: 20px;
}

.nav-menu a {
    color: white;
    text-decoration: none;
    transition: color 0.3s;
}

.nav-menu a:hover {
    color: #3498db;
}

/* Main Content */
main {
    min-height: calc(100vh - 120px);
    padding: 2rem 0;
}

/* Hero Section */
.hero {
    background-color: white;
    padding: 3rem;
    border-radius: 8px;
    text-align: center;
    margin-bottom: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.hero h2 {
    color: #2c3e50;
    margin-bottom: 1rem;
    font-size: 2.5rem;
}

.hero p {
    color: #7f8c8d;
    font-size: 1.2rem;
}

/* Features */
.features {
    background-color: white;
    padding: 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.features h3 {
    color: #2c3e50;
    margin-bottom: 1rem;
}

.features ul {
    list-style: none;
    padding-left: 0;
}

.features li {
    padding: 0.5rem 0;
    border-bottom: 1px solid #ecf0f1;
}

.features li:before {
    content: "✓ ";
    color: #27ae60;
    font-weight: bold;
    margin-right: 0.5rem;
}

/* Info Box */
.info {
    background-color: #ecf0f1;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #3498db;
}

.info code {
    background-color: #2c3e50;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
}

/* Page */
.page {
    background-color: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.page h2 {
    color: #2c3e50;
    margin-bottom: 1rem;
}

.page h3 {
    color: #34495e;
    margin-top: 1.5rem;
    margin-bottom: 0.5rem;
}

.page ul {
    margin-left: 2rem;
    margin-top: 0.5rem;
}

/* Footer */
footer {
    background-color: #34495e;
    color: #ecf0f1;
    text-align: center;
    padding: 1rem 0;
    margin-top: 2rem;
}

footer p {
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .navbar h1 {
        display: block;
        text-align: center;
        margin-bottom: 1rem;
    }
    
    .nav-menu {
        float: none;
        text-align: center;
        margin-top: 0;
    }
    
    .hero h2 {
        font-size: 2rem;
    }
}
CSS;
        file_put_contents($templatePath . '/public/assets/css/style.css', $cssContent);
        
        // Create routes/CustomRoutes.php
        $routesContent = <<<'PHP'
<?php

namespace App\Routes;

class CustomRoutes
{
    public static function defineRoutes($app)
    {
        // Home page
        $app->get('/', '\App\Controllers\IndexController:indexAction')
            ->setName('home');
        
        // About page
        $app->get('/about', '\App\Controllers\IndexController:aboutAction')
            ->setName('about');
        
        // API endpoints
        $app->group('/api', function ($group) {
            // Health check
            $group->get('/health', function ($request, $response, $args) {
                $data = [
                    'status' => 'healthy',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'app' => '{{APP_NAME}}'
                ];
                $response->getBody()->write(json_encode($data));
                return $response->withHeader('Content-Type', 'application/json');
            });
            
            // Version info
            $group->get('/version', function ($request, $response, $args) {
                $data = [
                    'version' => '1.0.0',
                    'app' => '{{APP_NAME}}'
                ];
                $response->getBody()->write(json_encode($data));
                return $response->withHeader('Content-Type', 'application/json');
            });
        });
        
        // Add your custom routes here
    }
}
PHP;
        file_put_contents($templatePath . '/routes/CustomRoutes.php', $routesContent);
        
        // Create database/schema.sql
        $schemaContent = <<<'SQL'
-- {{APP_NAME}} Database Schema
-- Generated by Nimbus Template Scaffold

-- Create main application table
CREATE TABLE IF NOT EXISTS {{APP_NAME_LOWER}}_data (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create users table
CREATE TABLE IF NOT EXISTS {{APP_NAME_LOWER}}_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP
);

-- Create sessions table
CREATE TABLE IF NOT EXISTS {{APP_NAME_LOWER}}_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER REFERENCES {{APP_NAME_LOWER}}_users(id) ON DELETE CASCADE,
    data TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL
);

-- Create audit log table
CREATE TABLE IF NOT EXISTS {{APP_NAME_LOWER}}_audit_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES {{APP_NAME_LOWER}}_users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    details JSONB,
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes
CREATE INDEX idx_{{APP_NAME_LOWER}}_users_email ON {{APP_NAME_LOWER}}_users(email);
CREATE INDEX idx_{{APP_NAME_LOWER}}_users_username ON {{APP_NAME_LOWER}}_users(username);
CREATE INDEX idx_{{APP_NAME_LOWER}}_sessions_user_id ON {{APP_NAME_LOWER}}_sessions(user_id);
CREATE INDEX idx_{{APP_NAME_LOWER}}_sessions_expires_at ON {{APP_NAME_LOWER}}_sessions(expires_at);
CREATE INDEX idx_{{APP_NAME_LOWER}}_audit_log_user_id ON {{APP_NAME_LOWER}}_audit_log(user_id);
CREATE INDEX idx_{{APP_NAME_LOWER}}_audit_log_created_at ON {{APP_NAME_LOWER}}_audit_log(created_at);

-- Sample data (optional, remove in production)
INSERT INTO {{APP_NAME_LOWER}}_data (name, value) VALUES 
    ('app_version', '1.0.0'),
    ('app_name', '{{APP_NAME}}'),
    ('initialized', 'true')
ON CONFLICT DO NOTHING;
SQL;
        file_put_contents($templatePath . '/database/schema.sql', $schemaContent);
        
        // Create app.config.php
        $configContent = <<<'PHP'
<?php

return [
    'app_name' => '{{APP_NAME}}',
    'database' => [
        'host' => '{{APP_NAME_LOWER}}-db',
        'port' => 5432,
        'dbname' => '{{DB_NAME}}',
        'user' => '{{DB_USER}}',
        'password' => '{{DB_PASSWORD}}'
    ],
    'features' => [
        'has_database' => true,
        'has_eda' => false,
        'has_keycloak' => false
    ],
    'keycloak' => [
        'enabled' => '{{KEYCLOAK_ENABLED}}',
        'realm' => '{{KEYCLOAK_REALM}}',
        'client_id' => '{{KEYCLOAK_CLIENT_ID}}',
        'client_secret' => '{{KEYCLOAK_CLIENT_SECRET}}',
        'auth_url' => 'http://{{APP_NAME_LOWER}}-keycloak:8080',
        'redirect_uri' => 'http://localhost:{{APP_PORT}}/auth/callback'
    ],
    'settings' => [
        'displayErrorDetails' => false,
        'debug' => false,
        'cache_dir' => '/tmp/cache',
        'log_dir' => '/var/www/logs'
    ]
];
PHP;
        file_put_contents($templatePath . '/app.config.php', $configContent);
    }
    
}
