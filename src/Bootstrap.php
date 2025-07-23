<?php
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
        define('ENV', 'development');
        define('MODELS_DIR', __DIR__ . '/../app/Models');
        define('VIEWS_DIR', __DIR__ . '/../app/Views');
        define('CONTROLLERS_DIR', __DIR__ . '/../app/Controllers');
        define('SOURCE_DIR', __DIR__);
        define('VENDOR_DIR', '/../vendor');
        define('PUBLIC_DIR', 'public');
        define('CUSTOM_ROUTES_FILE', __DIR__ .'/../app/CustomRoutes.php');
        define('CONFIG_FILE', __DIR__ . '/../app/app.config.php');
        define('DEPENDENCIES_FILE', SOURCE_DIR . '/Dependencies.php');
        define('MIMETYPES_FILE', SOURCE_DIR . '/MimeTypes.php');

        $autoload_vendor_files = __DIR__ . VENDOR_DIR .'/autoload.php';
        if (is_file($autoload_vendor_files)) {
            require $autoload_vendor_files;
        } else {
            exit('<b>vendor</b> directory not found. Please see README.md for install instructions, or simply try running <b>composer install</b>.');
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
            exit('App config file not found: '. CONFIG_FILE);
        }

        if (isset($config['pdo'])) {
            $this->injector->share('PDO');
            $this->injector->define('PDO', [
                $config['pdo']['dsn'],
                $config['pdo']['username'],
                $config['pdo']['password']
            ]);
        } else {
            // Fallback to default config for backward compatibility
            $this->injector->share('PDO');
            $this->injector->define('PDO', [
                'pgsql:host=db;port=5432;dbname=lkui',
                'lkui',
                'lkui_secure_password_2024'
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
        $this->routeCollector = $this->injector->make('Main\Router\RouteCollector');

        $routes = include('Routes.php');
        if (is_callable($routes)) {
            $routes = $routes($this->injector, $this->renderer, $this->conn);
        }
        if (is_array($routes)) {
            foreach ($routes as $route) {
                if (is_callable($route[2])) {
                    $this->routeCollector->addRoute($route[0], $route[1], $route[2]);
                }
            }
        }

        if (is_file(CUSTOM_ROUTES_FILE)) {
            $customRouteFactory = include CUSTOM_ROUTES_FILE;
            if (is_callable($customRouteFactory)) {
                $custom_routes = $customRouteFactory($this->injector, $this->renderer, $this->conn);
                if (is_array($custom_routes)) {
                    foreach ($custom_routes as $route) {
                        if (is_callable($route[2])) {
                            $this->routeCollector->addRoute($route[0], $route[1], $route[2]);
                        }
                    }
                }
            }
        }
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
                    $rawInput = file_get_contents('php://input');
                    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                    if (strpos($contentType, 'application/json') !== false) {
                        $bodyVars = json_decode($rawInput, true) ?? [];
                    } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                        parse_str($rawInput, $bodyVars);
                    }
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
