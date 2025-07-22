<?php

namespace Nimbus\Core;

use Auryn\Injector;
use FastRoute\Dispatcher;
use Whoops\Run as Whoops;
use Whoops\Handler\PrettyPageHandler;

/**
 * Application is the main entry point for Nimbus framework
 */
class Application
{
    private Injector $injector;
    private $conn;
    private $renderer;
    private Dispatcher $dispatcher;
    private array $config;
    private array $settings;
    
    public function __construct(array $settings = [])
    {
        $this->settings = array_merge([
            'env' => 'development',
            'app_dir' => realpath(__DIR__ . '/../../../app'),
            'src_dir' => realpath(__DIR__ . '/../../'),
            'vendor_dir' => realpath(__DIR__ . '/../../../vendor'),
            'public_dir' => 'public',
            'config_file' => null,
        ], $settings);
        
        $this->bootstrap();
    }
    
    /**
     * Bootstrap the application
     */
    private function bootstrap(): void
    {
        $this->setupConstants();
        $this->setupAutoload();
        $this->setupErrorHandling();
        $this->setupDependencies();
        $this->setupDatabase();
        $this->setupRenderer();
        $this->setupRoutes();
    }
    
    /**
     * Define application constants
     */
    private function setupConstants(): void
    {
        define('ENV', $this->settings['env']);
        define('MODELS_DIR', $this->settings['app_dir'] . '/Models');
        define('VIEWS_DIR', $this->settings['app_dir'] . '/Views');
        define('CONTROLLERS_DIR', $this->settings['app_dir'] . '/Controllers');
        define('SOURCE_DIR', $this->settings['src_dir']);
        define('VENDOR_DIR', $this->settings['vendor_dir']);
        define('PUBLIC_DIR', $this->settings['public_dir']);
        define('CUSTOM_ROUTES_FILE', $this->settings['app_dir'] . '/CustomRoutes.php');
        define('CONFIG_FILE', $this->settings['config_file'] ?? $this->settings['app_dir'] . '/app.config.php');
        define('DEPENDENCIES_FILE', SOURCE_DIR . '/Dependencies.php');
        define('MIMETYPES_FILE', SOURCE_DIR . '/MimeTypes.php');
    }
    
    /**
     * Setup autoloading
     */
    private function setupAutoload(): void
    {
        $autoloadFile = VENDOR_DIR . '/autoload.php';
        if (is_file($autoloadFile)) {
            require $autoloadFile;
        } else {
            throw new \RuntimeException('Vendor directory not found. Please run composer install.');
        }
    }
    
    /**
     * Setup error handling
     */
    private function setupErrorHandling(): void
    {
        $whoops = new Whoops();
        
        if (ENV !== 'production') {
            $whoops->pushHandler(new PrettyPageHandler());
        } else {
            $whoops->pushHandler(function($e) {
                if (is_file(SOURCE_DIR . '/Static/Error.php')) {
                    include(SOURCE_DIR . '/Static/Error.php');
                } else {
                    http_response_code(500);
                    echo 'Internal Server Error';
                }
            });
        }
        
        $whoops->register();
    }
    
    /**
     * Setup dependency injection
     */
    private function setupDependencies(): void
    {
        if (is_file(DEPENDENCIES_FILE)) {
            $this->injector = include(DEPENDENCIES_FILE);
        } else {
            $this->injector = new Injector();
        }
        
        // Load configuration
        if (is_file(CONFIG_FILE)) {
            $this->config = include(CONFIG_FILE);
        } else {
            $this->config = [];
        }
        
        // Share PDO instance
        $this->injector->share('PDO');
        
        // Define PDO with configuration
        $pdoConfig = $this->config['pdo'] ?? [
            'dsn' => 'pgsql:host=db;port=5432;dbname=lkui',
            'username' => 'lkui',
            'password' => 'lkui_secure_password_2024'
        ];
        
        $this->injector->define('PDO', [
            $pdoConfig['dsn'],
            $pdoConfig['username'],
            $pdoConfig['password']
        ]);
        
        // Share the injector itself for controllers
        $this->injector->share($this->injector);
    }
    
    /**
     * Setup database connection
     */
    private function setupDatabase(): void
    {
        $this->conn = $this->injector->make('PDO');
    }
    
    /**
     * Setup renderer
     */
    private function setupRenderer(): void
    {
        $this->renderer = $this->injector->make('Main\Renderer\Renderer');
    }
    
    /**
     * Setup routes and dispatcher
     */
    private function setupRoutes(): void
    {
        $this->dispatcher = \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
            // Load default routes
            if (is_file(SOURCE_DIR . '/Routes.php')) {
                $routes = include(SOURCE_DIR . '/Routes.php');
                if (is_callable($routes)) {
                    $routes = $routes($this->injector, $this->renderer, $this->conn);
                }
                if (is_array($routes)) {
                    foreach ($routes as $route) {
                        if (isset($route[0], $route[1], $route[2]) && is_callable($route[2])) {
                            $r->addRoute($route[0], $route[1], $route[2]);
                        }
                    }
                }
            }
            
            // Load custom routes
            if (is_file(CUSTOM_ROUTES_FILE)) {
                $customRouteFactory = include CUSTOM_ROUTES_FILE;
                if (is_callable($customRouteFactory)) {
                    $routes = $customRouteFactory($this->injector, $this->renderer, $this->conn);
                    if (is_array($routes)) {
                        foreach ($routes as [$method, $path, $handler]) {
                            if (is_callable($handler) || (is_array($handler) && count($handler) === 2)) {
                                $r->addRoute($method, $path, $handler);
                            }
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Run the application
     */
    public function run(): void
    {
        // Start session for authentication
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Handle CORS if needed
        $this->handleCors();
        
        // Get request details
        $httpMethod = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        
        // Remove query string
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);
        
        // Dispatch the request
        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);
        
        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                http_response_code(404);
                echo json_encode(['error' => 'Not Found']);
                break;
                
            case Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                echo json_encode(['error' => 'Method Not Allowed']);
                break;
                
            case Dispatcher::FOUND:
                $this->handleFoundRoute($routeInfo, $httpMethod);
                break;
        }
    }
    
    /**
     * Handle CORS headers
     */
    private function handleCors(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // Handle preflight requests
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH");
            }
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: Content-Type, Authorization");
            }
            http_response_code(204);
            exit();
        }
    }
    
    /**
     * Handle a found route
     */
    private function handleFoundRoute(array $routeInfo, string $httpMethod): void
    {
        [$controller, $method] = $routeInfo[1];
        $vars = $routeInfo[2];
        
        // Merge body variables for POST/PUT/PATCH
        if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'])) {
            $bodyVars = $this->getRequestBody();
            $vars = array_merge($vars, $bodyVars);
            
            // Set global named_vars for backward compatibility
            $GLOBALS['named_vars'] = $bodyVars;
        }
        
        // Execute the controller action
        if (is_string($controller)) {
            $instance = $this->injector->make($controller);
            $instance->$method(...array_values($vars));
        } else {
            $controller->$method(...array_values($vars));
        }
    }
    
    /**
     * Get request body data
     */
    private function getRequestBody(): array
    {
        $rawInput = file_get_contents('php://input');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            return json_decode($rawInput, true) ?? [];
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($rawInput, $bodyVars);
            return $bodyVars;
        }
        
        return [];
    }
    
    /**
     * Get the injector instance
     */
    public function getInjector(): Injector
    {
        return $this->injector;
    }
    
    /**
     * Get the database connection
     */
    public function getDatabase(): \PDO
    {
        return $this->conn;
    }
    
    /**
     * Get the renderer
     */
    public function getRenderer()
    {
        return $this->renderer;
    }
}