<?php

require_once('ControllerInterface.php');

use Main\Router\RouteCollector;
use Main\Renderer\Renderer;
use Main\Mock\PDO;

class HostController implements \App\Controllers\ControllerInterface
{
    protected $renderer;
    protected $conn;

    public function __construct(Renderer $renderer, PDO $conn)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
    }
    public function createHost($templateId, $commonName = null)
    {
        // TODO: Implement CSR generation
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'CSR_GENERATED',
            'template_id' => $templateId,
            'common_name' => $commonName ?? '*.example.com'
        ]);
    }

    public function listHosts()
    {
        // TODO: Implement host listing
        header('Content-Type: application/json');
        echo json_encode([]);
    }

    public function get(RouteCollector $router)
    {
        $router->get('/lkui/api/hosts', [$this, 'listHosts']);
        $router->get('/lkui/api/hosts/{id:\d+}', [$this, 'getHost']);
    }

    public function post(RouteCollector $router)
    {
        $router->post('/lkui/api/hosts', [$this, 'createHost']);
    }

    public function getHost($id)
    {
        // TODO: Implement host retrieval
        header('Content-Type: application/json');
        echo json_encode([
            'id' => $id,
            'status' => 'CSR_GENERATED'
        ]);
    }

    public function showHomepage()
    {
        echo $this->renderer->render('index.html', [
            'appName' => 'License Key UI',
            'hosts_count' => 0, // TODO: Implement count
            'pending_orders_count' => 0, // TODO: Implement count
            'templates_count' => 0 // TODO: Implement count
        ]);
    }

    public function showHosts()
    {
        // TODO: Fetch hosts from database
        $hosts = [
            // Mock data for now
            [
                'id' => 1,
                'common_name' => '*.example.com',
                'status' => 'CSR Generated',
                'is_active' => true,
                'created_at' => '2025-01-15 10:30:00'
            ],
            [
                'id' => 2,
                'common_name' => 'api.example.com',
                'status' => 'Certificate Issued',
                'is_active' => true,
                'created_at' => '2025-01-14 15:45:00'
            ]
        ];

        echo $this->renderer->render('hosts.html', [
            'appName' => 'License Key UI',
            'hosts' => $hosts
        ]);
    }

    public function showHostDetail($id)
    {
        // TODO: Fetch host from database
        $host = [
            'id' => $id,
            'common_name' => '*.example.com',
            'status' => 'CSR Generated',
            'is_active' => true,
            'created_at' => '2025-01-15 10:30:00',
            'csr' => '-----BEGIN CERTIFICATE REQUEST-----
MIICijCCAXICAQAwRTELMAkGA1UEBhMCQVUxEzARBgNVBAgTClNvbWUtU3RhdGUx
ITAfBgNVBAoTGEludGVybmV0IFdpZGdpdHMgUHR5IEx0ZDCCASIwDQYJKoZIhvcN
...
-----END CERTIFICATE REQUEST-----',
            'certificate' => null,
            'can_generate_csr' => false,
            'can_download_cert' => false
        ];

        echo $this->renderer->render('host-detail.html', [
            'appName' => 'License Key UI',
            'host' => $host
        ]);
    }
}
