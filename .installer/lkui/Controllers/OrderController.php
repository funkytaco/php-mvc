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
        header('Content-Type: application/json');
        echo json_encode([
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function updateOrder($hostId, $certContent)
    {
        header('Content-Type: application/json');
        echo json_encode([
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_COMPLETED',
            'cert_content' => $certContent,
            'issued_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getOrder($hostId)
    {
        header('Content-Type: application/json');
        echo json_encode([
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function showOrders()
    {
        // TODO: Fetch orders from database
        $orders = [
            // Mock data for now
            [
                'id' => 1,
                'host_name' => '*.example.com',
                'status' => 'Pending',
                'is_pending' => true,
                'is_completed' => false,
                'is_failed' => false,
                'order_type' => 'New Certificate',
                'created_at' => '2025-01-15 10:30:00'
            ],
            [
                'id' => 2,
                'host_name' => 'api.example.com',
                'status' => 'Completed',
                'is_pending' => false,
                'is_completed' => true,
                'is_failed' => false,
                'order_type' => 'Renewal',
                'created_at' => '2025-01-14 15:45:00'
            ]
        ];

        echo $this->renderer->render('orders.html', [
            'appName' => 'License Key UI',
            'orders' => $orders
        ]);
    }

    public function showOrderDetail($id)
    {
        // TODO: Fetch order from database
        $order = [
            'id' => $id,
            'host_id' => 1,
            'host_name' => '*.example.com',
            'status' => 'Pending',
            'is_pending' => true,
            'is_completed' => false,
            'is_failed' => false,
            'order_type' => 'New Certificate',
            'created_at' => '2025-01-15 10:30:00',
            'updated_at' => '2025-01-15 10:30:00',
            'license_key' => 'LK-2025-EXAMPLE-001',
            'certificate' => null,
            'error_message' => null,
            'can_retry' => false,
            'can_download' => false,
            'can_cancel' => true
        ];

        echo $this->renderer->render('order-detail.html', [
            'appName' => 'License Key UI',
            'order' => $order
        ]);
    }
}
