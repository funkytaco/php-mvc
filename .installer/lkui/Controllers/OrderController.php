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
    public function showCreateOrder($host_id) {

        //$host_id = $request->getQueryParams()['host_id'] ?? null;
        http_response_code(200);
        header('Content-Type: application/json');
            echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => 123,
                'host_id' => $host_id,
                'status' => 'ORDER_PENDING',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        return;  

        //$this->data['host'] = $this->HostCtrl->getHostData($host_id);
        
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
    public function createOrder($host_id, $certificate_authority) {


        // Validate required fields
        if (!isset($host_id)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'host_id is required'
            ]);
            return;
        }
                
        // Verify host exists
        $host = $this->getHostById($host_id);
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
        $order_type = $certificate_authority;
        if (!isset($order_type)) { $order_type = 'certbot'; }
        $orderId = $this->saveOrder($host_id, $order_type);
        
        http_response_code(200);
        header('Content-Type: application/json');
            echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => $orderId,
                'host_id' => $host_id,
                'status' => 'ORDER_PENDING',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        return;        


    }

    /**
     * API: Get specific order
     */
    public function getOrder($id) {
        $orderId = $id;
        $order = $this->getOrderData($orderId);
        
        if (!$order) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Order ID '. $orderId .' not found'
            ]);
            return;
        } else {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'data' => $order
            ]);
            return;
        }
        
    }

    /**
     * API: Update order with certificate
     */
    public function updateOrder(int $orderId, string $status, string $error_message = null) {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        header('Content-Type: application/json');

        // Validate required fields based on status
        if ($status === 'ORDER_COMPLETED') {
            // For successful orders, cert_content is required
            if (!isset($data['cert_content'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'validation_error',
                    'message' => 'cert_content is required for completed orders'
                ]);
                return;
            }

            $certContent = $data['cert_content'];

            // Validate certificate
            if (!$this->validateCertificate($certContent)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'validation_error', 
                    'message' => 'Invalid certificate format'
                ]);
                return;
            }

            // Update order with certificate
            $success = $this->updateOrderCertificate($orderId, $certContent);

            if (!$success) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'database_error',
                    'message' => 'Failed to update order certificate'
                ]);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'order_id' => $orderId,
                    'status' => $status
                ]
            ]);

        } elseif ($status === 'ORDER_FAILED') {
            // For failed orders, handle the failure
            $errorMessage = $error_message ?? $data['error_message'] ?? 'Unknown error';
            
            // Update order status to failed
            $success = $this->updateOrderStatus($orderId, $status, $errorMessage);

            if (!$success) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'database_error',
                    'message' => 'Failed to update order status'
                ]);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'order_id' => $orderId,
                    'status' => $status
                ]
            ]);

        } else {
            // Handle other status updates
            $success = $this->updateOrderStatus($orderId, $status);

            if (!$success) {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'database_error',
                    'message' => 'Failed to update order status'
                ]);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'order_id' => $orderId,
                    'status' => $status
                ]
            ]);
        }
    }

    private function addOrderUpdate(int $orderId, string $status, string $message = null): bool
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO order_updates (order_id, status, message)
                VALUES (:order_id, :status, :message)
            ");
            return $stmt->execute([
                ':order_id' => $orderId,
                ':status' => $status,
                ':message' => $message
            ]);
        } catch (Exception $e) {
            error_log("Error adding order update: " . $e->getMessage());
            return false;
        }
    }

    private function updateOrderStatus(int $orderId, string $status, string $errorMessage = null): bool
    {
        try {
            // Update order status
            $stmt = $this->conn->prepare("
                UPDATE orders 
                SET status = :status, updated_at = NOW()
                WHERE id = :order_id
            ");
            $success = $stmt->execute([
                ':status' => $status,
                ':order_id' => $orderId
            ]);
            
            // Log update
            if ($success && $errorMessage) {
                $this->addOrderUpdate($orderId, $status, $errorMessage);
            }
            
            return $success;
        } catch (Exception $e) {
            error_log("Error updating order status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Private helper: Get orders data from database
     */
    private function listOrdersData()
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT o.*, h.common_name, o.status as status, t.name as template_name
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
                SELECT o.*, h.common_name, h.csr_content, h.private_key, t.name as template_name,
                (SELECT json_agg(ou) FROM order_updates ou WHERE ou.order_id = o.id) as updates
                FROM orders o 
                LEFT JOIN hosts h ON o.host_id = h.id 
                LEFT JOIN templates t ON h.template_id = t.id 
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order && isset($order['updates'])) {
                $order['updates'] = json_decode($order['updates'], true);
            }
            return $order;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Private helper: Get host by ID
     */
    private function getHostById($host_id)
    {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM hosts WHERE id = ?");
            $stmt->execute([$host_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Private helper: Save order to database
     */
    private function saveOrder($host_id, $orderType) {

        try {
            $stmt = $this->conn->prepare("
                INSERT INTO orders (host_id, order_type, status, created_at) 
                VALUES (?,?, 'ORDER_PENDING', NOW())
            ");
            $stmt->execute([$host_id, $orderType]);
            return $this->conn->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to save order: " . $e->getMessage());
        }
    }

    /**
     * Private helper: Update order with certificate
     */
    private function updateOrderCertificate($orderId, $certContent) {
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


    //EDA API methods
    public function submitSslOrder($action, $csr, $domain, $email, $order_id, $timestamp, $certificate_authority, $validation_method) {
        // Build payload
        $payload = json_encode([
            "action" => "order_ssl_" . $certificate_authority,
            "csr" => $csr,
            "domain" => $domain,
            "email" => $email,
            "order_id" => $order_id,
            "timestamp" => $timestamp,
            "certificate_authority" => $certificate_authority,
            "validation_method" => $validation_method,
            "acme_version" => 1
        ]);

        // Only handle certbot, letsencrypt, self-signed for now
        if (!in_array($certificate_authority, ['certbot', 'letsencrypt', 'self-signed'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Unsupported certificate authority type'
            ]);
            return;
        }

        // POST to EDA API
        $ch = curl_init('http://lkui-eda:5000/ssl-order');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $result = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to reach EDA: ' . $curlErr
            ]);
            return;
        }

        $edaResponse = json_decode($result, true);
        //FIXME $updateOrderResponse = $this->updateOrder($order_id, 'ORDER_QUEUED', '');
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'data' => $payload
        ]);
    }



}
