<?php

use Main\Renderer\Renderer;
use Main\Mock\PDO;
use App\Controllers\ControllerInterface;
use Main\Router\RouteCollector;

class OrderController implements ControllerInterface
{
    protected $renderer;
    protected $conn;

    public function __construct(Renderer $renderer, PDO $conn)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
    }

    public function get(RouteCollector $router)
    {
        $router->get('/lkui/api/orders/{id:\d+}', [$this, 'getOrder']);
    }

    public function post(RouteCollector $router)
    {
        $router->post('/lkui/api/orders', [$this, 'createOrder']);
        $router->post('/lkui/api/orders/{id:\d+}/certificate', [$this, 'updateOrder']);
    }
    public function createOrder($hostId)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }

    public function updateOrder($hostId, $certContent)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_COMPLETED',
            'cert_content' => $certContent,
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }

    public function getOrder($hostId)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }
}
