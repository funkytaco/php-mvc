<?php

use Main\Renderer\Renderer;
use Main\Modules\Date_Module;

class OrderController implements ControllerInterface {

    protected $renderer;
    protected $conn;
    protected $mod_date;
    private $data;
    public $HostCtrl;


    public function __construct(
        Renderer $renderer,
        PDO $conn, 
        Date_Module $mod_date,
        HostController $HostCtrl
    ) {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->mod_date = $mod_date;
        $this->HostCtrl = $HostCtrl;

        $this->data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'License Key Management System',
            'myDateModule' => $mod_date->getDate()
        ];
    }

    public function get() {
        // Add GET parameters to data if needed
        $this->data['getVar'] = $_GET;
        
        $html = $this->renderer->render('order-detail.html', $this->data);
        echo $html;
    }



    /**
     * Show orders list page
     */
    public function showOrders()
    {
        $orders = $this->listOrdersData();
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Orders Management',
            'orders' => $orders
        ];
        
        echo $this->renderer->render('orders.html', $data);
    }

    /**
     * Show individual order detail page
     */
    public function showOrderDetail($orderId) {


        $order = $this->getOrderData($orderId);
        
        if (!$order) {
            $data = ['error' => 'Order not found'];
            echo $this->renderer->render('error.html', $data);
            return;
        }
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Order Details',
            'order' => $order
        ];
        
        $html = $this->renderer->render('order-detail.html', $data);
        echo $html;
    }

    /**
     * Show individual host detail page
     */
    public function showCreateOrder() {

        $hostId = $request->getQueryParams()['hostId'] ?? null;
        http_response_code(200);
        header('Content-Type: application/json');
            echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => 123,
                'host_id' => $data['host_id'],
                'status' => 'ORDER_PENDING',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        return;  

        //$this->data['host'] = $this->HostCtrl->getHostData($hostId);
        
        //$this->data['host']['csr'] = $this->data['host']['csr_content'];
        // if (!$this->data['host']['csr']) $this->data['host']['can_generate_csr'] = true;

        // if (!$this->data['host'] ) {
        //     // Return 404 or error page
        //     $this->data = ['error' => 'Host not found'];

            
        //     echo $this->renderer->render('error.html', $this->data);
        //     return;
        // } 
        
        echo $this->renderer->render('order-create.html', $this->data);


    }



    /**
     * API: Create a new order
     */
    public function createOrder() {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        // Validate required fields
        if (!is_array($data) || !isset($data['host_id'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'host_id is required'
            ]);
            return;
        }
                
        // Verify host exists
        $host = $this->getHostById($data['host_id']);
        if (!$host) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Host not found'
            ]);
            return;
        }
        
        // Create order
        $orderId = $this->saveOrder($data['host_id']);
        
        http_response_code(200);
        header('Content-Type: application/json');
            echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => $orderId,
                'host_id' => $data['host_id'],
                'status' => 'ORDER_PENDING',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        return;        


    }

    /**
     * API: Get specific order
     */
    public function getOrder($request, $response, $args) {
        $orderId = $args['id'];
        $order = $this->getOrderData($orderId);
        
        if (!$order) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]));
            $json = $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        } else {
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $order
        ]));
        
        $json = $response->withHeader('Content-Type', 'application/json');

        }
        
        echo $json;
    }

    /**
     * API: Update order with certificate
     */
    public function updateOrder(int $orderId, string $status, string $error_message = null) {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        header('Content-Type: application/json');

        // Validate required fields
        if (!isset($data['cert_content'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'cert_content is required'
            ]);
            return;
        }

        $certContent = $data['cert_content'];

        // Validate certificate
        if (!$this->validateCertificate($certContent)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid certificate format'
            ]);
            return;
        }

        // Update order
        $success = $this->updateOrderCertificate($orderId, $certContent);

        if (!$success) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to update order'
            ]);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Order updated successfully'
        ]);
    }

    /**
     * Private helper: Get orders data from database
     */
    private function listOrdersData()
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT o.*, h.common_name, h.status as host_status, t.name as template_name
                FROM orders o 
                LEFT JOIN hosts h ON o.host_id = h.id 
                LEFT JOIN templates t ON h.template_id = t.id 
                ORDER BY o.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Private helper: Get single order data
     */
    private function getOrderData($orderId)
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT o.*, h.common_name, h.csr_content, h.private_key, t.name as template_name
                FROM orders o 
                LEFT JOIN hosts h ON o.host_id = h.id 
                LEFT JOIN templates t ON h.template_id = t.id 
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Private helper: Get host by ID
     */
    private function getHostById($hostId)
    {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM hosts WHERE id = ?");
            $stmt->execute([$hostId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Private helper: Save order to database
     */
    private function saveOrder($hostId)
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO orders (host_id, status, created_at) 
                VALUES (?, 'ORDER_PENDING', NOW())
            ");
            $stmt->execute([$hostId]);
            return $this->conn->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to save order: " . $e->getMessage());
        }
    }

    /**
     * Private helper: Update order with certificate
     */
    private function updateOrderCertificate($orderId, $certContent)
    {
        try {
            $stmt = $this->conn->prepare("
                UPDATE orders 
                SET cert_content = ?, status = 'ORDER_COMPLETED', issued_at = NOW() 
                WHERE id = ?
            ");
            return $stmt->execute([$certContent, $orderId]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Private helper: Validate certificate format
     */
    private function validateCertificate($certContent)
    {
        // Basic validation - check if it looks like a PEM certificate
        if (strpos($certContent, '-----BEGIN CERTIFICATE-----') === false) {
            return false;
        }
        
        if (strpos($certContent, '-----END CERTIFICATE-----') === false) {
            return false;
        }
        
        // Try to parse the certificate
        $cert = openssl_x509_parse($certContent);
        return $cert !== false;
    }
}
