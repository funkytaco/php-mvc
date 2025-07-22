<?php

namespace App\Controllers;

use Auryn\Injector;
use Nimbus\Controller\AbstractController;

class AuthController extends AbstractController
{
    private $keycloakConfig;
    
    protected function initialize(): void
    {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $config = $this->getConfig();
        $keycloakConfig = $config['keycloak'] ?? null;
        
        if ($keycloakConfig && ($keycloakConfig['enabled'] ?? false)) {
            $this->keycloakConfig = $keycloakConfig;
        }
    }
    
    public function login()
    {
        if (!$this->keycloakConfig) {
            return $this->redirect('/');
        }
        
        $authUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/auth';
        $params = [
            'client_id' => $this->keycloakConfig['client_id'],
            'redirect_uri' => $this->keycloakConfig['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid profile email'
        ];
        
        $this->redirect($authUrl . '?' . http_build_query($params));
    }
    
    public function callback()
    {
        if (!$this->keycloakConfig || !isset($_GET['code'])) {
            return $this->redirect('/');
        }
        
        $tokenUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/token';
        
        $data = [
            'grant_type' => 'authorization_code',
            'code' => $_GET['code'],
            'redirect_uri' => $this->keycloakConfig['redirect_uri'],
            'client_id' => $this->keycloakConfig['client_id'],
            'client_secret' => $this->keycloakConfig['client_secret']
        ];
        
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $tokens = json_decode($response, true);
        
        if (isset($tokens['access_token'])) {
            $_SESSION['access_token'] = $tokens['access_token'];
            $_SESSION['id_token'] = $tokens['id_token'];
            $_SESSION['refresh_token'] = $tokens['refresh_token'];
            
            // Decode JWT to get user info
            $idTokenPayload = $this->decodeJWT($tokens['id_token']);
            $_SESSION['user'] = [
                'email' => $idTokenPayload['email'] ?? '',
                'name' => $idTokenPayload['name'] ?? '',
                'username' => $idTokenPayload['preferred_username'] ?? ''
            ];
            
            return $this->redirect('/');
        }
        
        return $this->redirect('/auth/login');
    }
    
    public function logout()
    {
        if (!$this->keycloakConfig) {
            session_destroy();
            return $this->redirect('/');
        }
        
        $logoutUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/logout';
        $params = [
            'client_id' => $this->keycloakConfig['client_id'],
            'post_logout_redirect_uri' => 'http://localhost:' . $_SERVER['SERVER_PORT'] . '/'
        ];
        
        session_destroy();
        $this->redirect($logoutUrl . '?' . http_build_query($params));
    }
    
    public function configure()
    {
        $data = [
            'title' => 'Keycloak Configuration',
            'keycloak_enabled' => $this->keycloakConfig ? true : false
        ];
        
        return $this->json($data);
    }
    
    public function saveConfiguration()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->redirect('/auth/configure');
        }
        
        $config = [
            'realm' => $_POST['realm'] ?? '',
            'client_id' => $_POST['client_id'] ?? '',
            'client_secret' => $_POST['client_secret'] ?? '',
            'auth_url' => $_POST['auth_url'] ?? ''
        ];
        
        // Send to EDA for processing
        $this->sendToEDA($config);
        
        echo $this->json(['success' => true, 'message' => 'Configuration sent to EDA for processing']);
    }
    
    private function sendToEDA($config)
    {
        $webhook_url = 'http://' . getenv('APP_NAME') . '-eda:5000/keycloak-config';
        
        $ch = curl_init($webhook_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($config));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function decodeJWT($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }
        
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        return json_decode($payload, true);
    }
}