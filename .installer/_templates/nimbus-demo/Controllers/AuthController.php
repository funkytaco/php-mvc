<?php

namespace App\Controllers;

use Nimbus\Controller\AbstractController;

/**
 * AuthController - Handles Keycloak authentication with zero-config auto-setup
 */
class AuthController extends AbstractController
{
    private array $keycloakConfig;
    
    protected function initialize(): void
    {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Load Keycloak configuration
        $config = $this->getConfig();
        $this->keycloakConfig = $config['keycloak'] ?? [];
    }
    
    /**
     * Initiate login with Keycloak
     */
    public function login()
    {
        if (!$this->isKeycloakEnabled()) {
            $this->error('Keycloak is not enabled', 404);
            return;
        }
        
        $redirectUri = $_GET['redirect'] ?? '/';
        $_SESSION['login_redirect'] = $redirectUri;
        
        $authUrl = $this->buildAuthUrl();
        header('Location: ' . $authUrl);
        exit;
    }
    
    /**
     * Handle callback from Keycloak
     */
    public function callback()
    {
        if (!$this->isKeycloakEnabled()) {
            $this->error('Keycloak is not enabled', 404);
            return;
        }
        
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        
        if (!$code) {
            $this->error('Authorization code not provided', 400);
            return;
        }
        
        try {
            // Exchange code for tokens
            $tokenData = $this->exchangeCodeForToken($code);
            
            if (!$tokenData) {
                $this->error('Failed to get access token', 400);
                return;
            }
            
            // Get user info
            $userInfo = $this->getUserInfo($tokenData['access_token']);
            
            if (!$userInfo) {
                $this->error('Failed to get user information', 400);
                return;
            }
            
            // Store in session
            $_SESSION['keycloak_token'] = $tokenData['access_token'];
            $_SESSION['refresh_token'] = $tokenData['refresh_token'] ?? null;
            $_SESSION['token_expires_at'] = time() + ($tokenData['expires_in'] ?? 300) - 60; // 60 second buffer
            $_SESSION['user'] = [
                'id' => $userInfo['sub'],
                'username' => $userInfo['preferred_username'] ?? $userInfo['sub'],
                'email' => $userInfo['email'] ?? '',
                'name' => $userInfo['name'] ?? $userInfo['preferred_username'] ?? '',
                'roles' => $this->extractRoles($tokenData['access_token'])
            ];
            
            // Redirect to originally requested page or home
            $redirectUri = $_SESSION['login_redirect'] ?? '/';
            unset($_SESSION['login_redirect']);
            
            header('Location: ' . $redirectUri);
            exit;
            
        } catch (\Exception $e) {
            error_log('Keycloak callback error: ' . $e->getMessage());
            $this->error('Authentication failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Logout from Keycloak
     */
    public function logout()
    {
        if (!$this->isKeycloakEnabled()) {
            session_destroy();
            header('Location: /');
            exit;
        }
        
        $logoutUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/logout';
        $logoutUrl .= '?redirect_uri=' . urlencode($this->getBaseUrl());
        
        // Clear session
        session_destroy();
        
        // Redirect to Keycloak logout
        header('Location: ' . $logoutUrl);
        exit;
    }
    
    /**
     * Show Keycloak configuration page
     */
    public function configure()
    {
        $data = [
            'title' => 'Keycloak Configuration',
            'keycloak_config' => $this->keycloakConfig,
            'keycloak_admin_url' => $this->keycloakConfig['auth_url'] . '/admin',
            'app_name' => '{{APP_NAME}}',
            'realm_name' => $this->keycloakConfig['realm'] ?? '{{APP_NAME}}-realm',
            'client_id' => $this->keycloakConfig['client_id'] ?? '{{APP_NAME}}-client',
            'keycloak_enabled' => $this->isKeycloakEnabled(),
            'setup_complete' => $this->isKeycloakSetupComplete()
        ];
        
        $html = $this->render('auth/configure', $data);
        echo $html;
    }
    
    /**
     * Save Keycloak configuration (for runtime updates)
     */
    public function saveConfiguration()
    {
        $data = $this->getRequestData();
        
        // Validate required fields
        $requiredFields = ['realm', 'client_id', 'client_secret'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $this->error("Field '$field' is required", 400);
                return;
            }
        }
        
        try {
            // Update configuration file
            $configFile = dirname(__DIR__, 2) . '/app/app.config.php';
            if (file_exists($configFile)) {
                $config = include $configFile;
                $config['keycloak']['realm'] = $data['realm'];
                $config['keycloak']['client_id'] = $data['client_id'];
                $config['keycloak']['client_secret'] = $data['client_secret'];
                $config['keycloak']['enabled'] = 'true';
                
                // Write back to file (this is a simplified approach)
                $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";
                file_put_contents($configFile, $configContent);
                
                $this->json([
                    'success' => true,
                    'message' => 'Configuration saved successfully'
                ]);
            } else {
                $this->error('Configuration file not found', 500);
            }
        } catch (\Exception $e) {
            $this->error('Failed to save configuration: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Build authorization URL
     */
    private function buildAuthUrl(): string
    {
        $params = [
            'client_id' => $this->keycloakConfig['client_id'],
            'redirect_uri' => $this->keycloakConfig['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => bin2hex(random_bytes(16))
        ];
        
        $_SESSION['oauth_state'] = $params['state'];
        
        $authUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/auth';
        return $authUrl . '?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for access token
     */
    private function exchangeCodeForToken(string $code): ?array
    {
        $tokenUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/token';
        
        $data = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->keycloakConfig['redirect_uri'],
            'client_id' => $this->keycloakConfig['client_id'],
            'client_secret' => $this->keycloakConfig['client_secret']
        ];
        
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        error_log('Token exchange failed: ' . $response);
        return null;
    }
    
    /**
     * Get user info from Keycloak
     */
    private function getUserInfo(string $accessToken): ?array
    {
        $userInfoUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'] . '/protocol/openid-connect/userinfo';
        
        $ch = curl_init($userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        return null;
    }
    
    /**
     * Extract roles from JWT token
     */
    private function extractRoles(string $accessToken): array
    {
        // Simple JWT parsing (not secure, but sufficient for role extraction)
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            return [];
        }
        
        $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
        
        $roles = [];
        
        // Extract realm roles
        if (isset($payload['realm_access']['roles'])) {
            $roles = array_merge($roles, $payload['realm_access']['roles']);
        }
        
        // Extract client roles
        if (isset($payload['resource_access'][$this->keycloakConfig['client_id']]['roles'])) {
            $roles = array_merge($roles, $payload['resource_access'][$this->keycloakConfig['client_id']]['roles']);
        }
        
        return array_unique($roles);
    }
    
    /**
     * Check if Keycloak is enabled
     */
    private function isKeycloakEnabled(): bool
    {
        return !empty($this->keycloakConfig) && 
               isset($this->keycloakConfig['enabled']) && 
               ($this->keycloakConfig['enabled'] === true || $this->keycloakConfig['enabled'] === 'true');
    }
    
    /**
     * Check if Keycloak setup is complete (realm and client exist)
     */
    private function isKeycloakSetupComplete(): bool
    {
        if (!$this->isKeycloakEnabled()) {
            return false;
        }
        
        // Simple check by trying to access the realm
        $realmUrl = $this->keycloakConfig['auth_url'] . '/realms/' . $this->keycloakConfig['realm'];
        
        $ch = curl_init($realmUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
    
    /**
     * Get base URL for redirects
     */
    private function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
}