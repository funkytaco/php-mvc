<?php

namespace Nimbus\Tasks;

use Nimbus\Core\BaseTask;
use Composer\Script\Event;

class TemplateTask extends BaseTask
{
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
        $io = $event->getIO();
        $args = $event->getArguments();
        
        // Get template name or check all
        $templateName = $args[0] ?? null;
        
        if ($templateName) {
            // Check specific template
            $this->checkTemplate($templateName);
        } else {
            // Check all templates
            echo self::ansiFormat('INFO', 'Checking all templates...');
            echo PHP_EOL;
            
            $templates = $this->getAvailableTemplates();
            if (empty($templates)) {
                echo self::ansiFormat('WARNING', 'No templates found.');
                return;
            }
            
            foreach ($templates as $template) {
                $this->checkTemplate($template);
                echo PHP_EOL;
            }
        }
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
                    'image' => 'registry.redhat.io/ansible-automation-platform-24/de-minimal-rhel9:latest',
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
     * Check a specific template
     */
    private function checkTemplate(string $templateName): void
    {
        echo self::ansiFormat('INFO', "Checking template: $templateName");
        
        $templatePath = $this->templatesDir . '/' . $templateName;
        
        if (!is_dir($templatePath)) {
            echo self::ansiFormat('ERROR', "Template '$templateName' not found.");
            return;
        }
        
        $errors = [];
        $warnings = [];
        $success = [];
        
        // Check required files
        $requiredFiles = [
            'app.config.php' => 'Application configuration',
            'app.nimbus.json' => 'Nimbus app configuration',
            'Controllers/IndexController.php' => 'Main controller',
            'Views/index.mustache' => 'Index view template',
            'routes/CustomRoutes.php' => 'Route definitions',
            'database/schema.sql' => 'Database schema'
        ];
        
        foreach ($requiredFiles as $file => $description) {
            if (file_exists($templatePath . '/' . $file)) {
                $success[] = "✓ $description ($file)";
                
                // Additional validation for specific files
                $content = file_get_contents($templatePath . '/' . $file);
                
                // Check for required placeholders
                if (!strpos($content, '{{APP_NAME}}') && !strpos($content, '{{app_name}}') && !strpos($content, '{{APP_NAME_LOWER}}') && !strpos($content, '{{APP_NAME_UPPER}}')) {
                    $warnings[] = "$file missing app name placeholders";
                }
                
                if ($file === 'app.config.php' && !strpos($content, '{{DB_PASSWORD}}')) {
                    $warnings[] = "$file missing database password placeholder";
                }
                
            } else {
                $errors[] = "✗ Missing $description ($file)";
            }
        }
        
        // Check optional but recommended files
        $optionalFiles = [
            'template.json' => 'Template metadata',
            'Views/layout.mustache' => 'Layout template',
            'public/assets/css/style.css' => 'Stylesheet'
        ];
        
        foreach ($optionalFiles as $file => $description) {
            if (file_exists($templatePath . '/' . $file)) {
                $success[] = "✓ $description ($file)";
            } else {
                $warnings[] = "⚠ Missing optional $description ($file)";
            }
        }
        
        // Check template.json if it exists
        $metadataFile = $templatePath . '/template.json';
        if (file_exists($metadataFile)) {
            $metadata = json_decode(file_get_contents($metadataFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "✗ Invalid JSON in template.json";
            } else {
                if (empty($metadata['name'])) {
                    $warnings[] = "⚠ template.json missing 'name' field";
                }
                if (empty($metadata['description'])) {
                    $warnings[] = "⚠ template.json missing 'description' field";
                }
            }
        }
        
        // Check for PHP syntax errors
        $phpFiles = glob($templatePath . '/**/*.php');
        foreach ($phpFiles as $phpFile) {
            $result = shell_exec("php -l '$phpFile' 2>&1");
            if (strpos($result, 'No syntax errors') === false) {
                $relPath = str_replace($templatePath . '/', '', $phpFile);
                $errors[] = "✗ PHP syntax error in $relPath";
            }
        }
        
        // Display results
        echo PHP_EOL;
        
        if (!empty($success)) {
            echo self::ansiFormat('SUCCESS', 'Valid components:');
            foreach ($success as $item) {
                echo "  $item" . PHP_EOL;
            }
        }
        
        if (!empty($warnings)) {
            echo PHP_EOL;
            echo self::ansiFormat('WARNING', 'Warnings:');
            foreach ($warnings as $warning) {
                echo "  $warning" . PHP_EOL;
            }
        }
        
        if (!empty($errors)) {
            echo PHP_EOL;
            echo self::ansiFormat('ERROR', 'Errors:');
            foreach ($errors as $error) {
                echo "  $error" . PHP_EOL;
            }
        }
        
        // Overall status
        echo PHP_EOL;
        if (empty($errors)) {
            if (empty($warnings)) {
                echo self::ansiFormat('SUCCESS', "✓ Template '$templateName' is valid and complete!");
            } else {
                echo self::ansiFormat('SUCCESS', "✓ Template '$templateName' is valid with warnings.");
            }
        } else {
            echo self::ansiFormat('ERROR', "✗ Template '$templateName' has errors that need to be fixed.");
        }
    }
    
    /**
     * Get available templates
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