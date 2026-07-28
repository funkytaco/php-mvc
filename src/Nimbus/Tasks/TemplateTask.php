<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Composer\Script\Event;

/**
 * @phpstan-type Finding array{msg: string, locs: list<string>}
 */
class TemplateTask extends BaseTask
{
    /**
     * Placeholders AppManager::generateAppConfigWithPasswords() substitutes at
     * create time. A {{TOKEN}} outside this list is never replaced and ships
     * verbatim into the generated app.
     *
     * @var array<string, string> token => dummy value used for syntax checking
     */
    private const PLACEHOLDERS = [
        'APP_NAME' => 'checkapp',
        'APP_NAME_UPPER' => 'CHECKAPP',
        'APP_NAME_LOWER' => 'checkapp',
        'APP_PORT' => '8080',
        'EDA_PORT' => '5000',
        'DB_NAME' => 'checkapp_db',
        'DB_USER' => 'checkapp_user',
        'DB_PASSWORD' => 'checkpass',
        'HAS_EDA' => 'true',
        'KEYCLOAK_ENABLED' => 'true',
        'KEYCLOAK_PORT' => '8081',
        'KEYCLOAK_REALM' => 'checkapp-realm',
        'KEYCLOAK_CLIENT_ID' => 'checkapp-client',
        'KEYCLOAK_CLIENT_SECRET' => 'checksecret',
        'KEYCLOAK_ADMIN_PASSWORD' => 'checkpass',
        'KEYCLOAK_DB_PASSWORD' => 'checkpass',
    ];

    /** The only files allowed to carry {{PLACEHOLDER}} tokens (see CLAUDE.md). */
    private const PLACEHOLDER_FILES = ['app.config.php', 'app.nimbus.json'];

    private string $templatesDir;

    public function __construct()
    {
        $this->templatesDir = getcwd() . '/.installer/_templates';
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
        
        // Validate template name
        if (!preg_match('/^[a-z0-9-]+$/', $templateName)) {
            echo self::ansiFormat('ERROR', 'Template name must contain only lowercase letters, numbers, and hyphens.');
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
            echo "  3. Run 'composer nimbus:template-check $templateName' to validate" . PHP_EOL;
            echo "  4. Test with 'composer nimbus:create test-app' and select your template" . PHP_EOL;
            
        } catch (\Exception $e) {
            echo self::ansiFormat('ERROR', 'Failed to scaffold template: ' . $e->getMessage());
        }
    }
    
    /**
     * Check/validate a template
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
    
    /**
     * Validate one template against what nimbus:create / nimbus:install
     * actually do with it: read app.nimbus.json, copy every asset source,
     * substitute placeholders, then include app.config.php at request time.
     *
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
                $severity = $key === 'assets' ? 'error' : 'warning';
                $msg = "app.nimbus.json has no \"$key\"";
                if ($severity === 'error') {
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

        $substituted = self::substitutePlaceholders((string) file_get_contents($file));
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
            $error = self::lintPhp(self::substitutePlaceholders((string) file_get_contents($templatePath . '/' . $rel)));
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

                if (!isset(self::PLACEHOLDERS[$token])) {
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
     * @param Finding $finding
     */
    private static function renderFinding(string $color, string $mark, array $finding): string
    {
        $line = '  ' . self::paint($color, $mark) . '  ' . $finding['msg'] . PHP_EOL;

        if ($finding['locs']) {
            $shown = array_slice($finding['locs'], 0, 3);
            $extra = count($finding['locs']) - count($shown);
            $line .= '     ' . self::paint('dark_gray', implode(', ', $shown) . ($extra > 0 ? " (+$extra more)" : '')) . PHP_EOL;
        }

        return $line;
    }

    /**
     * @param list<Finding> $errors
     * @param list<Finding> $warnings
     */
    private static function countsLabel(array $errors, array $warnings): string
    {
        $parts = [];
        if ($errors) {
            $parts[] = count($errors) . ' error' . (count($errors) === 1 ? '' : 's');
        }
        if ($warnings) {
            $parts[] = count($warnings) . ' warning' . (count($warnings) === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }

    /**
     * Append a finding, grouping repeat occurrences of the same message under
     * one entry so a template-wide problem prints once with its locations.
     *
     * @param list<Finding> $findings
     */
    private static function addFinding(array &$findings, string $msg, ?string $loc = null): void
    {
        foreach ($findings as &$existing) {
            if ($existing['msg'] === $msg) {
                if ($loc !== null && !in_array($loc, $existing['locs'], true)) {
                    $existing['locs'][] = $loc;
                }
                return;
            }
        }
        unset($existing);

        $findings[] = ['msg' => $msg, 'locs' => $loc === null ? [] : [$loc]];
    }

    private static function paint(string $color, string $str): string
    {
        return "\033[" . self::$foreground[$color] . 'm' . $str . "\033[0m";
    }

    /**
     * Replace every known token with a stand-in of the right shape, so that
     * unquoted tokens ('eda_port' => {{EDA_PORT}}) stay syntactically valid.
     */
    private static function substitutePlaceholders(string $content): string
    {
        foreach (self::PLACEHOLDERS as $token => $value) {
            $content = str_replace('{{' . $token . '}}', $value, $content);
        }

        return $content;
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

    /**
     * @return string[] every file in the template, as template-relative paths
     */
    private function templateFiles(string $templatePath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($templatePath) + 1);
            if (str_starts_with($rel, '.git/') || str_contains($rel, '/node_modules/')) {
                continue;
            }
            $files[] = $rel;
        }

        sort($files);

        return $files;
    }

    /**
     * Get available templates
     *
     * @return string[]
     */
    private function getAvailableTemplates(): array
    {
        if (!is_dir($this->templatesDir)) {
            return [];
        }
        
        $templates = [];
        $dirs = scandir($this->templatesDir);
        
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($this->templatesDir . '/' . $dir)) {
                $templates[] = $dir;
            }
        }
        
        return $templates;
    }
}