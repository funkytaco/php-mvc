<?php /*** Bootstrap file ***/

    namespace Main;
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
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


    /**
    * Error Handler
    */
    $whoops = new \Whoops\Run;
    if (ENV !== 'production') {
        $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
    } else {
        $whoops->pushHandler(function($e){
            //notify devs/log error here;
            include(SOURCE_DIR . '/Static/Error.php');
        });
    }
    $whoops->register();



    /**
    * Dependency Injector
    * $injector
    */
    $injector = include(DEPENDENCIES_FILE);

    /**
    * App Configuration - these are imported via the .installer directory
    */
    if (is_file(CONFIG_FILE)) {
        $config = include(CONFIG_FILE);
    } else {
        exit('App config file not found: '. CONFIG_FILE);
    }
    /**
    * Pass $injector PDO configuration
    *
    */
    // Only configure PDO if settings exist
    if (isset($config['pdo'])) {
        $injector->define('\Main\PDO', [
            ':dsn' => $config['pdo']['dsn'] ?? '',
            ':username' => $config['pdo']['username'] ?? '',
            ':passwd' => $config['pdo']['password'] ?? '',
            ':options' => $config['pdo']['options'] ?? []
        ]);
    }

    /**
    * Mock Database PDO
    * $conn
    */
    $conn = $injector->make('\Main\Mock\PDO'); //comment out to use PDO $conn below

    /**
    *
    * Or Use a Real Database via PDO
    * $conn
    *   - app.config.php holds PDO settings
    *   - "use \Main\PDO" in your controller
    */

    // $conn = $injector->make('\Main\PDO'); //uncomment to use PDO!

    /**
    * Templating Engine
    * $renderer
    */
    $renderer = $injector->make('Main\Renderer\Renderer');

    /**
    * Router Setup
    */
    $routeCollector = $injector->make('Main\Router\RouteCollector');

    /**** end injector includes ***/

    // Load and process base routes
    $routes = include('Routes.php');
    if (is_array($routes)) {
        foreach ($routes as $route) {
            if (is_callable($route[2])) {
                $routeCollector->addRoute($route[0], $route[1], $route[2]);
            }
        }
    }

    // Process custom routes
    if (is_file(CUSTOM_ROUTES_FILE)) {
        $custom_routes = include(CUSTOM_ROUTES_FILE);
        if (is_array($custom_routes)) {
            foreach ($custom_routes as $route) {
                if (is_callable($route[2])) {
                    $routeCollector->addRoute($route[0], $route[1], $route[2]);
                }
            }
        }
    }

    // Create dispatcher after routes are loaded
    $dispatcher = new \FastRoute\Dispatcher\GroupCountBased($routeCollector->getData());



    // Handle the request using FastRoute
    $httpMethod = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    // Strip query string (?foo=bar) and decode URI
    if (false !== $pos = strpos($uri, '?')) {
        $uri = substr($uri, 0, $pos);
    }
    $uri = rawurldecode($uri);

    $routeInfo = $dispatcher->dispatch($httpMethod, $uri);
    switch ($routeInfo[0]) {
        case \FastRoute\Dispatcher::NOT_FOUND:
            http_response_code(404);
            break;
        case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
            http_response_code(405);
            break;
        case \FastRoute\Dispatcher::FOUND:
            $handler = $routeInfo[1];
            $vars = $routeInfo[2];
            call_user_func_array($handler, $vars);
            break;
    }
