<?php

//namespace App\Controllers;
require_once('ControllerInterface.php');

use Main\Router\RouteCollector;
//use App\Controllers\ControllerInterface;
use Main\Renderer\Renderer;
use Main\Database\Connection;

class HostController implements App\Controllers\ControllerInterface
{
    protected $renderer;
    protected $conn;

    public function __construct(Renderer $renderer, Connection $conn)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
    }
    public function createHost($templateId, $commonName = null)
    {
        // TODO: Implement CSR generation
        return [
            'status' => 'CSR_GENERATED',
            'template_id' => $templateId,
            'common_name' => $commonName ?? '*.example.com'
        ];
    }

    public function listHosts()
    {
        // TODO: Implement host listing
        return [];
    }

    public function get(RouteCollector $router)
    {
        $router->get('/lkui/api/hosts', [$this, 'listHosts']);
        $router->get('/lkui/api/hosts/{id:\d+}', [$this, 'getHost']);
    }

    public function post(RouteCollector $router)
    {
        $router->post('/lkui/api/hosts', [$this, 'createHost']);
    }

    public function getHost($id)
    {
        // TODO: Implement host retrieval
        return [
            'id' => $id,
            'status' => 'CSR_GENERATED'
        ];
    }
}
