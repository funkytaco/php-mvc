<?php
require_once('ControllerInterface.php');

use Main\Renderer\Renderer;
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
            'appName' => 'LKUI - License Key UI',
            'title' => 'License Key Management System',
            'myDateModule' => $mod_date->getDate()
        ];
    }

    public function get() {
        // Add GET parameters to data if needed
        $this->data['getVar'] = $_GET;
        
        $html = $this->renderer->render('index.html', $this->data);
        echo $html;
    }


    /**
     * Show homepage
     */
    public function showHomepage()
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
    public function showHosts() {
        //$hosts = $this->listHostsData();
        $hostsRaw = $this->listHostsData();
        // Inject `labelClass` into each host
        $hosts = array_map(function ($host) {
            switch ($host['status']) {
                case 'CSR_GENERATED':
                    $host['labelClass'] = 'label-success';
                    break;
                case 'ORDER_PENDING':
                    $host['labelClass'] = 'label-warning';
                    break;
                case 'ORDER_COMPLETED':
                    $host['labelClass'] = 'label-info';
                    break;
                default:
                    $host['labelClass'] = 'label-default';
            }
            return $host;
        }, $hostsRaw);

        $data = [
            'hosts' => $hosts
        ];
        
        echo $this->renderer->render('hosts.html', $data);
    }

    /**
     * Show individual host detail page
     */
    public function showHostDetail($host_id) {
        $this->data['host'] = $this->getHostData($host_id);
        $this->data['host']['csr'] = $this->data['host']['csr_content'];
        if (!$this->data['host']['csr']) $this->data['host']['can_generate_csr'] = true;

        // Add certificate authority options
        $authorities = [
            'certbot' => 'Certbot',
            'letsencrypt' => 'Let\'s Encrypt',
            'self-signed' => 'Self-Signed'
        ];
        $this->data['certificate_authorities'] = array_map(function($key, $value) {
            return ['key' => $key, 'value' => $value];
        }, array_keys($authorities), $authorities);

        if (!$this->data['host']) {
            // Return 404 or error page
            $this->data = ['error' => 'Host not found'];
            echo $this->renderer->render('error.html', $this->data);
            return;
        }

        echo $this->renderer->render('host-detail.html', $this->data);
        

    }

    /**
     * API: List all hosts
     */
    public function listHosts() {
        $hosts = $this->listHostsData();

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'data' => $hosts
        ]);
    }

    /**
     * API: Create a new host
     */
    public function createHost($template_id, $common_name) {
        // $body = file_get_contents('php://input');
        // $data = json_decode($body, true);

        if (!isset($template_id)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'template_id is required'
            ]);
            return;
        }

        // $template_id = $data['template_id'];
        // $common_name = $data['common_name'] ?? '*.example.com';

        $csrData = $this->generateCSR($common_name);
        $host_id = $this->saveHost($template_id, $common_name, $csrData);

        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => $host_id,
                'template_id' => $template_id,
                'common_name' => $common_name,
                'status' => 'CSR_GENERATED',
                'csr' => $csrData['csr']
            ]
        ]);
    }

    /**
     * API: Get specific host
     */
    public function getHost($request, $response, $args)
    {
        $host_id = $args['id'];
        $host = $this->getHostData($host_id);
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
    private function listHostsData() {
        // return [
        //         [
        //             'id' => 3,
        //             'template_id' => 1,
        //             'common_name' => 'web3.example.com',
        //             'csr_content' => '-----BEGIN CERTIFICATE REQUEST-----\nMIIBVTCB+wIBADBQMQswCQYDVQQGEwJVUzELMAkGA1UECAwCU1QxEDAOBgNVBAcM\nB0NpdHkxEDAOBgNVBAoMB0V4YW1wbGUxEjAQBgNVBAMMCWxvY2FsaG9zdDCBnzAN\nBgkqhkiG9w0BAQEFAAOBjQAwgYkCgYEAyZogNLknD0RMeF6RjK2Dd6KklXzAeCjv\nnDO78Qn9Mffv9BvWGe+9IjBY7kdB9Zy5TUnlRZIt3oOZkSGxUExTfI2pKk98gYpH\nLZKKs3MQ4a/F3FlWn7EbB8AlMxU4HYgB0ubTCvS3UepA9jIRKo8Kt2l0Z+/bGVUm\nVoYXz+9Mi6gk/sECAwEAAaAAMA0GCSqGSIb3DQEBCwUAA4GBAJDeA3UnpguKgjcI\npV2qWz3Mg0AJyJhFbOee5uztFKCPr0INiXbGxB7QNNnQSyLr9vcSDR97Zsbr+Ptn\n9tyNVPPgqlD7IMYcUlESxAQ7yy6c4h5ofXruFHEzPVzM1ODVAlhZDzRPaN4Z8nCx\nFg4U9gTZHkn2Om5LHYyBiBt5ZYkz\n-----END CERTIFICATE REQUEST-----',
        //             'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDJmiA0uScPREwQ\n...\n-----END PRIVATE KEY-----',
        //             'status' => 'CSR_GENERATED',
        //             'created_at' => '2025-06-24 10:00:00',
        //             'updated_at' => '2025-06-24 10:00:00',
        //             'template_name' => 'RHEL6',
        //         ],
        //         [
        //             'id' => 2,
        //             'template_id' => 2,
        //             'common_name' => 'web2.example.com',
        //             'csr_content' => '-----BEGIN CERTIFICATE REQUEST-----\n...\n-----END CERTIFICATE REQUEST-----',
        //             'private_key' => '-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----',
        //             'status' => 'ORDER_PENDING',
        //             'created_at' => '2025-06-23 14:30:00',
        //             'updated_at' => '2025-06-23 14:30:00',
        //             'template_name' => 'RHEL7',
        //         ],
        //         [
        //             'id' => 1,
        //             'template_id' => 3,
        //             'common_name' => 'web1.example.com',
        //             'csr_content' => '-----BEGIN CERTIFICATE REQUEST-----\n...\n-----END CERTIFICATE REQUEST-----',
        //             'private_key' => '-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----',
        //             'status' => 'ORDER_COMPLETED',
        //             'created_at' => '2025-06-22 09:15:00',
        //             'updated_at' => '2025-06-22 09:15:00',
        //             'template_name' => 'RHEL8',
        //         ],
        //     ];
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
    public function getHostData($host_id)
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT h.*, t.name as template_name, t.csr_options 
                FROM hosts h 
                LEFT JOIN templates t ON h.template_id = t.id 
                WHERE h.id = ?
            ");
            $stmt->execute([$host_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Private helper: Generate CSR
     */
    private function generateCSR($common_name)
    {
        // Generate private key
        $privateKey = openssl_pkey_new([
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        // Generate CSR
        $csr = openssl_csr_new([
            "commonName" => "$common_name",
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
    private function saveHost($template_id, $common_name, $csrData)
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO hosts (template_id, common_name, csr_content, private_key, status, created_at) 
                VALUES (?, ?, ?, ?, 'CSR_GENERATED', NOW())
            ");
            $stmt->execute([$template_id, $common_name, $csrData['csr'], $csrData['private_key']]);
            return $this->conn->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to save host: " . $e->getMessage());
        }
    }
}
