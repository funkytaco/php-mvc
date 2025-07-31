<?php

use Nimbus\Core\Application;

/**
 * Legacy bootstrap wrapper for backward compatibility
 * @deprecated Use Nimbus\Core\Application instead
 */
class Nimbus {
    public $injector;
    public $conn;
    public $renderer;
    public $dispatcher;
    public $routeCollector;
    public function __construct() {
        $this->setupAutoload();
        $this->setupErrorHandling();
        $this->setupDependencies();
        $this->setupDatabase();
        $this->setupRenderer();
        $this->setupRoutes();
        $this->setupDispatcher();
    }

    private function setupAutoload() {
        // ENV is always 'development' for containerized apps
        // Production settings should be in app.config.php
        if (!defined('ENV')) define('ENV', 'development');
        if (!defined('MODELS_DIR')) define('MODELS_DIR', __DIR__ . '/../app/Models');
        if (!defined('VIEWS_DIR')) define('VIEWS_DIR', __DIR__ . '/../app/Views');
        if (!defined('CONTROLLERS_DIR')) define('CONTROLLERS_DIR', __DIR__ . '/../app/Controllers');
        if (!defined('SOURCE_DIR')) define('SOURCE_DIR', __DIR__);
        if (!defined('VENDOR_DIR')) define('VENDOR_DIR', __DIR__ . '/../vendor');
        if (!defined('PUBLIC_DIR')) define('PUBLIC_DIR', 'public');
        if (!defined('CUSTOM_ROUTES_FILE')) define('CUSTOM_ROUTES_FILE', __DIR__ .'/../app/CustomRoutes.php');
        if (!defined('CONFIG_FILE')) define('CONFIG_FILE', __DIR__ . '/../app/app.config.php');
        if (!defined('DEPENDENCIES_FILE')) define('DEPENDENCIES_FILE', SOURCE_DIR . '/Dependencies.php');
        if (!defined('MIMETYPES_FILE')) define('MIMETYPES_FILE', SOURCE_DIR . '/MimeTypes.php');

        $autoload_vendor_files = VENDOR_DIR .'/autoload.php';
        if (is_file($autoload_vendor_files)) {
            require $autoload_vendor_files;
        } else {
            throw new \RuntimeException('Vendor directory not found. Please run composer install.');
        }
    }

    private function setupErrorHandling() {
        $whoops = new \Whoops\Run;
        if (ENV !== 'production') {
            $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
        } else {
            $whoops->pushHandler(function($e){
                include(SOURCE_DIR . '/Static/Error.php');
            });
        }
        $whoops->register();
    }

    private function setupDependencies() {
        $this->injector = include(DEPENDENCIES_FILE);

        if (is_file(CONFIG_FILE)) {
            $config = include(CONFIG_FILE);
        } else {
            throw new \RuntimeException('App config file not found: '. CONFIG_FILE);
        }

        if (isset($config['pdo'])) {
            $this->injector->share('PDO');
            $this->injector->define('PDO', [
                $config['pdo']['dsn'],
                $config['pdo']['username'],
                $config['pdo']['password']
            ]);
        } else {
            // Generate default PDO config for containerized apps
            // This supports apps created with composer nimbus:create
            $appName = $config['installer-name'] ?? 'app';
            $this->injector->share('PDO');
            $this->injector->define('PDO', [
                sprintf('pgsql:host=%s-db;port=5432;dbname=%s_db', $appName, $appName),
                sprintf('%s_user', $appName),
                'changeme' // Default password, should be overridden in app.config.php
            ]);
        }
    }

    private function setupDatabase() {
        $this->conn = $this->injector->make('PDO');
    }

    private function setupRenderer() {
        $this->renderer = $this->injector->make('Main\Renderer\Renderer');
    }

    private function setupRoutes() {
        // Routes are now handled in setupDispatcher to avoid duplication
    }

    private function setupDispatcher() {
        $this->dispatcher = \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
            if (is_file(CUSTOM_ROUTES_FILE)) {
                $customRouteFactory = include CUSTOM_ROUTES_FILE;
                if (is_callable($customRouteFactory)) {
                    $routes = $customRouteFactory($this->injector, $this->renderer, $this->conn);
                    if (is_array($routes)) {
                        foreach ($routes as [$method, $path, $handler]) {
                            if (is_callable($handler)) {
                                $r->addRoute($method, $path, $handler);
                            }
                        }
                    }
                }
            }
        });
    }

    public function run() {

        // header("Access-Control-Allow-Origin: http://localhost:8080");
        // header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        // header("Access-Control-Allow-Headers: Content-Type, Authorization");

        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(204);
            exit();
        }


        $httpMethod = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

        $middleware = [
            function($httpMethod, $uri) {
                return true;
            },
        ];
        foreach ($middleware as $mw) {
            if ($mw($httpMethod, $uri) === false) return;
        }

        switch ($routeInfo[0]) {
            case \FastRoute\Dispatcher::NOT_FOUND:
                http_response_code(404);
                echo json_encode(['error' => 'Not Found']);
                break;
            case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                break;
            case \FastRoute\Dispatcher::FOUND:
                [$controller, $method] = $routeInfo[1];
                $vars = $routeInfo[2];
                $bodyVars = [];
                if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'])) {
                    // Use $_POST for form data as per named_vars requirement
                    if (!empty($_POST)) {
                        $bodyVars = $_POST;
                    } else {
                        // Only parse raw input for JSON content
                        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                        if (strpos($contentType, 'application/json') !== false) {
                            $rawInput = file_get_contents('php://input');
                            $bodyVars = json_decode($rawInput, true) ?? [];
                        }
                    }
                    // Set global named_vars for backward compatibility
                    $GLOBALS['named_vars'] = $bodyVars;
                }
                $vars = array_merge($vars, $bodyVars);
                if (is_string($controller)) {
                    $instance = $this->injector->make($controller);
                    $instance->$method(...$vars);
                } else {
                    $controller->$method(...$vars);
                }
                break;
        }
    }
}

$nimbus = new Nimbus();
$nimbus->run();
