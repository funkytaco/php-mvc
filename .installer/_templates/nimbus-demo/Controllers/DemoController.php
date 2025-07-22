<?php

namespace App\Controllers;

use Nimbus\Controller\AbstractController;
use App\Models\DemoModel;

/**
 * DemoController - A simple demonstration controller
 */
class DemoController extends AbstractController
{
    private DemoModel $demoModel;
    
    protected function initialize(): void
    {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->demoModel = new DemoModel($this->getDb());
    }
    
    /**
     * Home page
     */
    public function index()
    {
        $config = $this->getConfig();
        $hasEda = $config['has_eda'] ?? false;
        $keycloakConfig = $config['keycloak'] ?? null;
        $hasKeycloak = $keycloakConfig && ($keycloakConfig['enabled'] ?? false);
        
        $features = [
            'MVC Architecture',
            'Database Integration',
            'Container Ready',
            'RESTful API Support'
        ];
        
        if ($hasEda) {
            $features[] = 'Event-Driven Ansible (EDA)';
        } else {
            $features[] = 'Event-Driven Ansible (EDA) - Not enabled';
        }
        
        if ($hasKeycloak) {
            $features[] = 'Keycloak SSO Integration';
        }
        
        // Get Keycloak details from app config if available
        $keycloakAdminPassword = '';
        $keycloakRealm = '{{APP_NAME}}-realm';
        
        // Try to read from app.nimbus.json for runtime values
        $appConfigFile = dirname(__DIR__, 2) . '/.installer/{{APP_NAME}}/app.nimbus.json';
        if (file_exists($appConfigFile)) {
            $appConfig = json_decode(file_get_contents($appConfigFile), true);
            if (isset($appConfig['containers']['keycloak']['admin_password'])) {
                $keycloakAdminPassword = $appConfig['containers']['keycloak']['admin_password'];
            }
            if (isset($appConfig['keycloak']['realm'])) {
                $keycloakRealm = $appConfig['keycloak']['realm'];
            }
        }
        
        $data = [
            'title' => '{{APP_NAME_UPPER}} Demo',
            'message' => 'Welcome to your Nimbus application!',
            'features' => $features,
            'stats' => $this->demoModel->getStats(),
            'has_eda' => $hasEda,
            'eda_port' => $config['eda_port'] ?? 5000,
            'has_keycloak' => $hasKeycloak,
            'user' => $_SESSION['user'] ?? null,
            'app_name' => '{{APP_NAME}}',
            'KEYCLOAK_ADMIN_PASSWORD' => $keycloakAdminPassword,
            'KEYCLOAK_REALM' => $keycloakRealm
        ];
        
        $html = $this->render('demo/index', $data);
        echo $html;
    }
    
    /**
     * API endpoint - Get all items
     */
    public function apiList()
    {
        try {
            $items = $this->demoModel->getAllItems();
            $this->json([
                'success' => true,
                'data' => $items,
                'count' => count($items)
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Get single item
     */
    public function apiGet($id)
    {
        try {
            $item = $this->demoModel->getItem($id);
            if (!$item) {
                $this->error('Item not found', 404);
                return;
            }
            $this->json(['success' => true, 'data' => $item]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Create item
     */
    public function apiCreate()
    {
        $data = $this->getRequestData();
        
        if (!$this->validate($data, ['name', 'description'])) {
            $this->error('Name and description are required');
            return;
        }
        
        try {
            $id = $this->demoModel->createItem($data);
            $this->json([
                'success' => true,
                'message' => 'Item created successfully',
                'id' => $id
            ], 201);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Update item
     */
    public function apiUpdate($id)
    {
        $data = $this->getRequestData();
        
        try {
            $updated = $this->demoModel->updateItem($id, $data);
            if (!$updated) {
                $this->error('Item not found', 404);
                return;
            }
            $this->success(null, 'Item updated successfully');
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * API endpoint - Delete item
     */
    public function apiDelete($id)
    {
        try {
            $deleted = $this->demoModel->deleteItem($id);
            if (!$deleted) {
                $this->error('Item not found', 404);
                return;
            }
            $this->success(null, 'Item deleted successfully');
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * EDA webhook proxy to avoid CORS issues
     */
    public function edaWebhook()
    {
        $config = $this->getConfig();
        $hasEda = $config['has_eda'] ?? false;
        
        if (!$hasEda) {
            $this->error('EDA is not enabled for this app', 404);
            return;
        }
        
        $edaPort = $config['eda_port'] ?? 5000;
        $requestData = $this->getRequestData();
        
        // Forward the request to the EDA container
        $edaUrl = "http://{{APP_NAME}}-eda:5000/endpoint";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $edaUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->error('Failed to reach EDA container: ' . $curlError, 502);
            return;
        }
        
        // Return success regardless of EDA response
        $this->json([
            'success' => true,
            'message' => 'Webhook forwarded to EDA',
            'eda_response_code' => $httpCode
        ]);
    }
}