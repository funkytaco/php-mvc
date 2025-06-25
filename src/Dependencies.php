<?php
//echo VIEWS_DIR;exit;
$injector = new \Auryn\Injector;
$mustache_options =  array('extension' => '.html');

$injector->alias('Main\Renderer\Renderer', 'Main\Renderer\MustacheRenderer');
$injector->alias('Main\Router\RouteCollector', 'FastRoute\RouteCollector');
$injector->alias('Main\Router\RouteParser', 'FastRoute\RouteParser\Std');
$injector->define('FastRoute\RouteParser\Std', []);
$injector->define('FastRoute\RouteCollector', [
    ':routeParser' => $injector->make('FastRoute\RouteParser\Std'),
    ':dataGenerator' => new \FastRoute\DataGenerator\GroupCountBased()
]);
$injector->delegate('Main\Router\Dispatcher', function() use ($injector) {
    $routeCollector = $injector->make('Main\Router\RouteCollector');
    return new \FastRoute\Dispatcher\GroupCountBased($routeCollector->getData());
});


try {

    $injector->define('Mustache_Engine', [
        ':options' => [
            'loader' => new Mustache_Loader_FilesystemLoader(VIEWS_DIR, $mustache_options),
            'partials_loader' => new Mustache_Loader_FilesystemLoader(VIEWS_DIR, $mustache_options),

        ],
    ]);

} catch (Exception $e) {

    if (stristr($e->getMessage(),"FilesystemLoader baseDir must be a directory") == TRUE) {
        throw new Exception("VIEWS_DIR does not exist. To install a template run:\n
        composer install-mvc OR composer install-lkui\n");
    }
}









return $injector;
