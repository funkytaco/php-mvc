<?php
namespace App\Controllers;

use \Main\Router\RouteCollector;

Interface ControllerInterface
{
    public function get(RouteCollector $router);
    public function post(RouteCollector $router);
}
