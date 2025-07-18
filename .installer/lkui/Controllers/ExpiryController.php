<?php

require_once('ControllerInterface.php');

use Main\Renderer\Renderer;
use Main\Modules\Date_Module;


class ExpiryController
{
    protected $renderer;
    protected $conn;
    protected $mod_date;
    private $data;


    public function __construct(
        Renderer $renderer,
        PDO $conn, 
        Date_Module $mod_date
    ) {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->mod_date = $mod_date;

        $this->data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'License Key Management System',
            'myDateModule' => $mod_date->getDate()
        ];
    }

    public function get() {
        // Get current certificates for initial page load
        try {
            $stmt = $this->conn->query("SELECT * FROM certificate_expiry ORDER BY expiry_date ASC");
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add labelClass based on status
            foreach ($certificates as &$cert) {
                switch ($cert['status']) {
                    case 'Expired':
                        $cert['labelClass'] = 'bg-danger';
                        break;
                    case 'Expiring Soon':
                        $cert['labelClass'] = 'bg-warning';
                        break;
                    case 'Valid':
                        $cert['labelClass'] = 'bg-success';
                        break;
                    default:
                        $cert['labelClass'] = 'bg-secondary';
                }
            
            }
            
            $this->data['expiries'] = $certificates;
        } catch (PDOException $e) {
            $this->data['expiries'] = [];
            //error_log("Failed to fetch certificates: " . $e->getMessage());
        }
        
        // Get expiry_updates logs
        try {
            $stmt = $this->conn->query("SELECT * FROM expiry_updates ORDER BY created_at DESC");
            $this->data['expiry_updates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->data['expiry_updates'] = [];
            //error_log("Failed to fetch expiry updates: " . $e->getMessage());
        }
        
        $this->data['title'] = 'SSL Certificate Expiry Monitor';
        $html = $this->renderer->render('expiry', $this->data);
        echo $html;
    }

    public function listCertificates() {
        try {
            $stmt = $this->conn->query("SELECT * FROM certificate_expiry ORDER BY expiry_date ASC");
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add labelClass based on status
            foreach ($certificates as &$cert) {
                switch ($cert['status']) {
                    case 'Expired':
                        $cert['labelClass'] = 'bg-danger';
                        break;
                    case 'Expiring Soon':
                        $cert['labelClass'] = 'bg-warning';
                        break;
                    case 'Valid':
                        $cert['labelClass'] = 'bg-success';
                        break;
                    default:
                        $cert['labelClass'] = 'bg-secondary';
                }
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'data' => $certificates
            ]);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch certificates: ' . $e->getMessage()
            ]);
        }
    }

    public function refreshCertificates() {
        try {
            // Trigger EDA webhook for SSL certificate expiry check
            $webhook_url = 'http://lkui-eda:5001/ssl-expiry';
            $callback_url = 'http://lkui-app:8080/eda/api/ssl-expiry';
            
            $payload = [
                'action' => 'refresh',
                'callback_url' => $callback_url
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhook_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($payload))
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception('Failed to trigger EDA webhook. If the app just loaded, please wait a few more seconds before trying again.');
            }
            
            curl_close($ch);
            
            if ($http_code !== 200) {
                throw new Exception('EDA webhook returned HTTP ' . $http_code);
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'message' => 'Certificate refresh initiated'
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to refresh certificates: ' . $e->getMessage()
            ]);
        }
    }


    public function processExpiryData($callback_url = '', $certificates = []) {
        // Process data from EDA webhook
        try {
            // Get JSON data from request body
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            // Use certificates from JSON data if available
            if (isset($data['certificates']) && is_array($data['certificates'])) {
                $certificates = $data['certificates'];
            }
            
            // Clear existing data
            $this->conn->exec("TRUNCATE TABLE certificate_expiry");            
            // Check if we have certificates data
            if (isset($certificates) && is_array($certificates)) {
                // Insert new data
                $stmt = $this->conn->prepare(
                    "INSERT INTO certificate_expiry 
                    (domain, expiry_date, days_remaining, status) 
                    VALUES (:domain, :expiry_date, :days_remaining, :status)"
                );
                
                foreach ($certificates as $cert) {
                    if (isset($cert['domain']) && isset($cert['expiry_date'])) {
                        $stmt->execute([
                            ':domain' => $cert['domain'],
                            ':expiry_date' => $cert['expiry_date'],
                            ':days_remaining' => $cert['days_remaining'] ?? 0,
                            ':status' => $cert['status'] ?? 'Unknown'
                        ]);
                    }
                }
            }
            
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            return ['status' => 'success'];
        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function logExpiryUpdate($message = '') {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO expiry_updates (message) VALUES (:message)"
            );
            
            $stmt->execute([':message' => $message]);
            
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
}
