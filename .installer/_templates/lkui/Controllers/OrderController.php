<?php

use Main\Renderer\Renderer;
use Main\Modules\Date_Module;

class OrderController implements ControllerInterface {

    protected $renderer;
    protected $conn;
    protected $mod_date;
    protected $config;
    private $data;
    public $HostCtrl;


    public function __construct(
        Renderer $renderer,
        PDO $conn, 
        Date_Module $mod_date,
        HostController $HostCtrl,
        array $config = []
    ) {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->mod_date = $mod_date;
        $this->HostCtrl = $HostCtrl;
        $this->config = $config;

        $this->data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'License Key Management System',
            'myDateModule' => $mod_date->getDate()
        ];
    }

    public function get() {
        // Add GET parameters to data if needed
        $this->data['getVar'] = $_GET;
        
        $html = $this->renderer->render('order-detail', $this->data);
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
        
        echo $this->renderer->render('orders', $data);
    }

    /**
     * Show individual order detail page
     */
    public function showOrderDetail($order_id) {


        $order = $this->getOrderData($order_id);
        
        if (!$order) {
            $data = ['error' => 'Order not found'];
            echo $this->renderer->render('error', $data);
            return;
        }
        
        // Add status-specific flags for template conditionals
        $order['is_pending'] = ($order['status'] === 'ORDER_PENDING');
        $order['is_processing'] = ($order['status'] === 'ORDER_PROCESSING');
        $order['is_completed'] = ($order['status'] === 'ORDER_COMPLETED');
        $order['is_failed'] = ($order['status'] === 'ORDER_FAILED');
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Order Details',
            'order' => $order
        ];
        
        $html = $this->renderer->render('order-detail', $data);
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

            
        //     echo $this->renderer->render('error', $this->data);
        //     return;
        // } 
        
        echo $this->renderer->render('order-create', $this->data);


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
        $order_id = $this->saveOrder($host_id, $order_type);
        
        http_response_code(200);
        header('Content-Type: application/json');
            echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => $order_id,
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
    public function getOrder($order_id) {
        $order = $this->getOrderData($order_id);
        
        if (!$order) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Order ID '. $order_id .' not found'
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
     * API: Update order
     */
    public function updateOrder(int $order_id, string $status, string $error_message = null) {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        header('Content-Type: application/json');

        // Handle status updates (certificate content is handled separately via updateOrderCertificate)
        if ($status === 'ORDER_COMPLETED') {
            // Update order status to completed
            $success = $this->updateOrderStatus($order_id, $status);

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
                    'order_id' => $order_id,
                    'status' => $status
                ]
            ]);

        } elseif ($status === 'ORDER_FAILED') {
            // For failed orders, handle the failure
            $errorMessage = $error_message ?? $data['error_message'] ?? 'Unknown error';
            
            // Update order status to failed
            $success = $this->updateOrderStatus($order_id, $status, $errorMessage);

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
                    'order_id' => $order_id,
                    'status' => $status
                ]
            ]);

        } else {
            // Handle other status updates
            $success = $this->updateOrderStatus($order_id, $status);

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
                    'order_id' => $order_id,
                    'status' => $status
                ]
            ]);
        }
    }

    private function addOrderUpdate(int $order_id, string $status, string $message = null): bool
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO order_updates (order_id, status, message)
                VALUES (:order_id, :status, :message)
            ");
            return $stmt->execute([
                ':order_id' => $order_id,
                ':status' => $status,
                ':message' => $message
            ]);
        } catch (Exception $e) {
            error_log("Error adding order update: " . $e->getMessage());
            return false;
        }
    }

    private function updateOrderStatus(int $order_id, string $status, string $errorMessage = null): bool
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
                ':order_id' => $order_id
            ]);
            
            // Log update
            if ($success && $errorMessage) {
                $this->addOrderUpdate($order_id, $status, $errorMessage);
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
    private function getOrderData($order_id)
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
            $stmt->execute([$order_id]);
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
    public function updateOrderCertificate($order_id, $cert_content) {
        // print_r($cert_content);exit;
        // $body = file_get_contents('php://input');
        // $data = json_decode($body, true);

        header('Content-Type: application/json');

        // Extract cert_content from POST body
        // $cert_content = $data['cert_content'] ?? null;

        if (!$cert_content) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'cert_content is required in request body'
            ]);
            return;
        }

        // Validate certificate
        if (!$this->validateCertificate($cert_content)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'error' => 'validation_error', 
                'message' => 'Invalid certificate format'
            ]);
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                UPDATE orders 
                SET cert_content = ?, issued_at = NOW() 
                WHERE id = ?
            ");
            $success = $stmt->execute([$cert_content, $order_id]);

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
                    'order_id' => $order_id,
                    'message' => 'Certificate updated successfully'
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'error' => 'database_error',
                'message' => 'Failed to update certificate: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * API: Download certificate for an order
     */
    public function downloadCertificate($order_id) {
        $order = $this->getOrderData($order_id);
        
        if (!$order) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]);
            return;
        }

        if (!$order['cert_content']) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Certificate not available for this order'
            ]);
            return;
        }

        if ($order['status'] !== 'ORDER_COMPLETED') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Order is not completed'
            ]);
            return;
        }

        // Set headers for .crt file download 
        $filename = $order['common_name'] . 's.crt';
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($order['cert_content']));
        header('Cache-Control: no-cache, must-revalidate');
        
        echo $order['cert_content'];
    }

    /**
     * Private helper: Validate certificate format
     */
    private function validateCertificate($cert_content)
    {
        // Basic validation - check if it looks like a PEM certificate
        if (strpos($cert_content, '-----BEGIN CERTIFICATE-----') === false) {
            return false;
        }
        
        if (strpos($cert_content, '-----END CERTIFICATE-----') === false) {
            return false;
        }
        
        // Try to parse the certificate
        $cert = openssl_x509_parse($cert_content);
        return $cert !== false;
    }

    /**
     * API: Decode CSR content
     */
    public function decodeCSR($csr_content) {
        header('Content-Type: application/json');
        
        // Validate input
        if (!isset($csr_content) || empty($csr_content)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'CSR content is required'
            ]);
            return;
        }
        
        $csrContent = $csr_content;
        
        // Convert literal \n to actual newlines
        $csrContent = str_replace('\\n', "\n", $csrContent);
        
        // Sanitize CSR content - ensure it's a valid PEM format
        $csrContent = trim($csrContent);
        
        // Validate PEM format
        if (!preg_match('/^-----BEGIN CERTIFICATE REQUEST-----[\s\S]*-----END CERTIFICATE REQUEST-----$/', $csrContent)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid CSR format. Must be PEM encoded.'
            ]);
            return;
        }
        
        // Additional safety check - limit CSR size
        if (strlen($csrContent) > 10000) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'CSR content too large'
            ]);
            return;
        }
        
        // Decode CSR using OpenSSL
        $csrInfo = $this->parseCSRContent($csrContent);
        
        if ($csrInfo === false) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to parse CSR. Invalid or corrupted CSR data.'
            ]);
            return;
        }
        
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => $csrInfo
        ]);
    }

    /**
     * Private helper: Parse CSR content using OpenSSL
     */
    private function parseCSRContent($csrContent) {
        try {
            // Parse CSR using OpenSSL
            $csrData = openssl_csr_get_subject($csrContent);
            $csrDetails = openssl_csr_get_public_key($csrContent);
            
            if ($csrData === false) {
                return false;
            }
            
            $result = [];
            
            // Extract subject information
            if (isset($csrData['CN'])) {
                $result['commonName'] = $csrData['CN'];
            }
            if (isset($csrData['O'])) {
                $result['organization'] = $csrData['O'];
            }
            if (isset($csrData['OU'])) {
                $result['organizationalUnit'] = $csrData['OU'];
            }
            if (isset($csrData['C'])) {
                $result['country'] = $csrData['C'];
            }
            if (isset($csrData['ST'])) {
                $result['state'] = $csrData['ST'];
            }
            if (isset($csrData['L'])) {
                $result['locality'] = $csrData['L'];
            }
            if (isset($csrData['emailAddress'])) {
                $result['email'] = $csrData['emailAddress'];
            }
            
            // Build subject string
            $subjectParts = [];
            foreach ($csrData as $key => $value) {
                $subjectParts[] = "$key=$value";
            }
            $result['subject'] = implode(', ', $subjectParts);
            
            // Extract key information
            if ($csrDetails !== false) {
                $keyDetails = openssl_pkey_get_details($csrDetails);
                if ($keyDetails !== false) {
                    $result['keySize'] = $keyDetails['bits'] ?? 'Unknown';
                    $result['keyType'] = $keyDetails['type'] ?? 'Unknown';
                    
                    // Convert key type number to string
                    switch ($result['keyType']) {
                        case OPENSSL_KEYTYPE_RSA:
                            $result['algorithm'] = 'RSA';
                            break;
                        case OPENSSL_KEYTYPE_DSA:
                            $result['algorithm'] = 'DSA';
                            break;
                        case OPENSSL_KEYTYPE_DH:
                            $result['algorithm'] = 'DH';
                            break;
                        case OPENSSL_KEYTYPE_EC:
                            $result['algorithm'] = 'EC';
                            break;
                        default:
                            $result['algorithm'] = 'Unknown';
                    }
                }
                
                // Clean up resource
                openssl_pkey_free($csrDetails);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("CSR parsing error: " . $e->getMessage());
            return false;
        }
    }


    //EDA API methods
    public function submitSslOrder($order_id) {
        // Get order details from database
        $order = $this->getOrderData($order_id);
        if (!$order) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]);
            return;
        }
        
        // Extract order details
        $action = "order_ssl_" . $order['order_type'];
        $csr = $order['csr_content'];
        $domain = $order['common_name'];
        $email = 'admin@example.com'; // Default email
        $timestamp = date('c');
        $certificate_authority = $order['order_type'] ?? 'certbot';
        $validation_method = 'dns'; // Default validation method
        
        // Build payload
        $payload = json_encode([
            "action" => $action,
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
        $edaUrl = $this->config['eda']['ssl_order_url'] ?? 'http://localhost:5000/ssl-order';
        $ch = curl_init($edaUrl);
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
