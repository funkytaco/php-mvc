<?php

class OrderController
{
    private $renderer;
    private $conn;

    public function __construct($renderer, $conn)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
    }

    /**
     * Show orders list page
     */
    public function showOrders($request, $response, $args)
    {
        $orders = $this->listOrdersData();
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Orders Management',
            'orders' => $orders
        ];
        
        return $this->renderer->render($response, 'orders.html', $data);
    }

    /**
     * Show individual order detail page
     */
    public function showOrderDetail($request, $response, $args)
    {
        $orderId = $args['id'];
        $order = $this->getOrderData($orderId);
        
        if (!$order) {
            $data = ['error' => 'Order not found'];
            return $this->renderer->render($response, 'error.html', $data);
        }
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Order Details',
            'order' => $order
        ];
        
        return $this->renderer->render($response, 'order-detail.html', $data);
    }

    /**
     * API: Create a new order
     */
    public function createOrder($request, $response, $args)
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        // Validate required fields
        if (!isset($data['host_id'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'host_id is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $hostId = $data['host_id'];
        
        // Verify host exists
        $host = $this->getHostById($hostId);
        if (!$host) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Host not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Create order
        $orderId = $this->saveOrder($hostId);
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => [
                'id' => $orderId,
                'host_id' => $hostId,
                'status' => 'ORDER_PENDING',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Get specific order
     */
    public function getOrder($request, $response, $args)
    {
        $orderId = $args['id'];
        $order = $this->getOrderData($orderId);
        
        if (!$order) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $order
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Update order with certificate
     */
    public function updateOrder($request, $response, $args)
    {
        $orderId = $args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        // Validate required fields
        if (!isset($data['cert_content'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'cert_content is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $certContent = $data['cert_content'];
        
        // Validate certificate
        if (!$this->validateCertificate($certContent)) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Invalid certificate format'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Update order
        $success = $this->updateOrderCertificate($orderId, $certContent);
        
        if (!$success) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Failed to update order'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Order updated successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
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
