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
        $html = $this->renderer->render('expiry.html', $this->data);
        echo $html;
    }

    public function listCertificates() {
        try {
            $stmt = $this->conn->query("SELECT * FROM certificate_expiry ORDER BY expiry_date ASC");
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
        // In a real implementation, this would trigger the EDA rulebook
        // For now just return mock response
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Certificate refresh initiated'
        ]);
    }

    public function processExpiryData($data) {
        // Process data from EDA webhook
        try {
            $jsonData = json_decode($data, true);
            
            // Clear existing data
            $this->conn->exec("TRUNCATE TABLE certificate_expiry");
            
            // Insert new data
            $stmt = $this->conn->prepare(
                "INSERT INTO certificate_expiry 
                (domain, expiry_date, days_remaining, status) 
                VALUES (:domain, :expiry_date, :days_remaining, :status)"
            );
            
            foreach ($jsonData['certificates'] as $cert) {
                $stmt->execute([
                    ':domain' => $cert['domain'],
                    ':expiry_date' => $cert['expiry_date'],
                    ':days_remaining' => $cert['days_remaining'],
                    ':status' => $cert['status']
                ]);
            }
            
            return ['status' => 'success'];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
