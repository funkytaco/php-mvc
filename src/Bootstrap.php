<?php /*** Bootstrap file ***/

    namespace Main;
    use FastRoute\RouteCollector;
    use FastRoute\Dispatcher;
    use function FastRoute\simpleDispatcher;
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    define('ENV', 'development');

    // if (extension_loaded('pdo_pgsql')) {
    //     echo "PDO PostgreSQL driver is loaded";
    // } else {
    //     echo "PDO PostgreSQL driver is NOT loaded";
    // }exit;

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
        // $injector->share('\PDO');
        // $injector->define('\PDO', [
        //     ':dsn' => $config['pdo']['dsn'] ?? '', //':dsn' => 'mysql:dbname=testdb;host=127.0.0.1',
        //     ':username' => $config['pdo']['username'],
        //     ':passwd' => $config['pdo']['password'] ?? 'lkui_secure_password_2024',
        //     ':options' => $config['pdo']['options'],
        //     ':host' => $config['pdo']['host'] ?? 'db',
        //     ':port' => $config['pdo']['port'] ?? 5432
        // ]);
    }
    $injector->share('PDO');
    //$injector->define('PDO', ['postgresql://lkui:lkui_secure_password_2024@db:5432/lkui;host=db', 'lkui', 'lkui_secure_password_2024']);
    // $injector->define('PDO', [
    //     ':dsn' => 'pgsql:host=db;port=5432;dbname=lkui',
    //     ':username' => 'lkui', 
    //     ':passwd' => 'lkui_secure_password_2024'
    // ]);
    $injector->define('PDO', [
        'pgsql:host=db;port=5432;dbname=lkui',  // $dsn (position 0)
        'lkui',                                  // $username (position 1) 
        'lkui_secure_password_2024'             // $password (position 2)
    ]);
    // $injector->define('PDO', [
    //     ':dsn' => 'pgsql:host=db;port=5432;dbname=lkui',
    //     ':username' => 'lkui',
    //     ':passwd' => 'lkui_secure_password_2024'
    // ]);

    /**
    * Mock Database PDO
    * $conn
    */
    //$conn = $injector->make('Main\Mock\PDO'); //comment out to use PDO $conn below

    /**
    *
    * Or Use a Real Database via PDO
    * $conn
    *   - app.config.php holds PDO settings
    *   - "use \Main\PDO" in your controller
    */

    $conn = $injector->make('PDO');

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

    // Create dispatcher: builds internal routing table. On HTTP request, dispatcher matches method + URI to handler.
    $dispatcher = simpleDispatcher(function (RouteCollector $r) use ($injector, $renderer, $conn) {
        if (is_file(CUSTOM_ROUTES_FILE)) {
            $routes = include CUSTOM_ROUTES_FILE;
            foreach ($routes as [$method, $path, $handler]) {
                if (is_callable($handler)) {
                    $r->addRoute($method, $path, $handler);
                }
            }
        }
    });

    // Dispatch
    $httpMethod = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];
    if (false !== $pos = strpos($uri, '?')) {
        $uri = substr($uri, 0, $pos);
    }
    $uri = rawurldecode($uri);

    $routeInfo = $dispatcher->dispatch($httpMethod, $uri);

    // Simple middleware pipeline: add callables to this array as needed
    $middleware = [
        function($httpMethod, $uri) {
            // Example logger
            // error_log("Request: $httpMethod $uri");
            return true;
        },
        // Add more middleware callables here
    ];

    // Run middleware, allow short-circuit
    foreach ($middleware as $mw) {
        if ($mw($httpMethod, $uri) === false) {
            // Middleware returned false: stop further handling
            return;
        }
    }

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
            [$controller, $method] = $routeInfo[1];
            $vars = $routeInfo[2]; // FastRoute path parameters: e.g. for /order/{id}, $vars['id'] = value

            // Parse HTTP body for POST/PUT/PATCH and merge into $vars
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

            // CRUD example usage:
            // GET    /order/1         => show($id)
            // POST   /order           => create($field1, $field2)
            // PUT    /order/1         => update($id, $field1, $field2)
            // DELETE /order/1         => delete($id)
            // These $vars are automatically passed into call_user_func_array.

            if (is_string($controller)) {
                $instance = $injector->make($controller);
                call_user_func_array([$instance, $method], $vars);
            } else {
                call_user_func_array([$controller, $method], $vars);
            }
            break;
    }   
