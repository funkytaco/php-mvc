<?php
require_once('ControllerInterface.php');

use \Main\Renderer\Renderer;
use \Main\Mock\PDO;
use Main\Modules\Date_Module;

    /**
    *   NOTE that the following are injected into your controller
    *   Renderer $renderer - Template Engine
    *   PDO $conn - PDO
    *   Dependency Injecting makes testing easier!
    ***/

class HostController implements ControllerInterface {
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
            'appName' => "PHP-MVC Template",
            'myDateModule' => $mod_date->getDate(),
            'projectList' => $this->getLegacyProjects()
        ];
    }

    /**
     * Show homepage
     */
    public function showHomepage($request, $response, $args)
    {
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'License Key Management System'
        ];
        
        return $this->renderer->render($response, 'index.html', $data);
    }

    /**
     * Show hosts list page
     */
    public function showHosts($request, $response, $args)
    {
        $hosts = $this->listHostsData();
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Hosts Management',
            'hosts' => $hosts
        ];
        
        return $this->renderer->render($response, 'hosts.html', $data);
    }

    /**
     * Show individual host detail page
     */
    public function showHostDetail($request, $response, $args)
    {
        $hostId = $args['id'];
        $host = $this->getHostData($hostId);
        
        if (!$host) {
            // Return 404 or error page
            $data = ['error' => 'Host not found'];
            return $this->renderer->render($response, 'error.html', $data);
        }
        
        $data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'Host Details',
            'host' => $host
        ];
        
        return $this->renderer->render($response, 'host-detail.html', $data);
    }

    /**
     * API: List all hosts
     */
    public function listHosts($request, $response, $args)
    {
        $hosts = $this->listHostsData();
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $hosts
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Create a new host
     */
    public function createHost($request, $response, $args)
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        // Validate required fields
        if (!isset($data['template_id'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'template_id is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $templateId = $data['template_id'];
        $commonName = $data['common_name'] ?? '*.example.com';
        
        // Generate CSR
        $csrData = $this->generateCSR($commonName);
        
        // Save to database
        $hostId = $this->saveHost($templateId, $commonName, $csrData);
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => [
                'id' => $hostId,
                'template_id' => $templateId,
                'common_name' => $commonName,
                'status' => 'CSR_GENERATED',
                'csr_content' => $csrData['csr']
            ]
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Get specific host
     */
    public function getHost($request, $response, $args)
    {
        $hostId = $args['id'];
        $host = $this->getHostData($hostId);
        
        if (!$host) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Host not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $host
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Private helper: Get hosts data from database
     */
    private function listHostsData()
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT h.*, t.name as template_name 
                FROM hosts h 
                LEFT JOIN templates t ON h.template_id = t.id 
                ORDER BY h.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Private helper: Get single host data
     */
    private function getHostData($hostId)
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT h.*, t.name as template_name, t.csr_options 
                FROM hosts h 
                LEFT JOIN templates t ON h.template_id = t.id 
                WHERE h.id = ?
            ");
            $stmt->execute([$hostId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Private helper: Generate CSR
     */
    private function generateCSR($commonName)
    {
        // Generate private key
        $privateKey = openssl_pkey_new([
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        // Generate CSR
        $csr = openssl_csr_new([
            "commonName" => $commonName,
            "organizationName" => "Example Organization",
            "organizationalUnitName" => "IT Department",
            "localityName" => "City",
            "stateOrProvinceName" => "State",
            "countryName" => "US"
        ], $privateKey, [
            "digest_alg" => "sha256"
        ]);

        // Export CSR and private key
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($privateKey, $privateKeyOut);

        return [
            'csr' => $csrOut,
            'private_key' => $privateKeyOut
        ];
    }

    /**
     * Private helper: Save host to database
     */
    private function saveHost($templateId, $commonName, $csrData)
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO hosts (template_id, common_name, csr_content, private_key, status, created_at) 
                VALUES (?, ?, ?, ?, 'CSR_GENERATED', NOW())
            ");
            $stmt->execute([$templateId, $commonName, $csrData['csr'], $csrData['private_key']]);
            return $this->conn->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to save host: " . $e->getMessage());
        }
    }
}
