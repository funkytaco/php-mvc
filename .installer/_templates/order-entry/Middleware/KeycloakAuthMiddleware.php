<?php

namespace App\Middleware;

use Exception;

class KeycloakAuthMiddleware
{
    private array $config;
    private array $publicRoutes;
    
    public function __construct()
    {
        // Load config
        $appConfig = include(CONFIG_FILE);
        $this->config = $appConfig['keycloak'] ?? [];
        
        // Define public routes that don't require authentication
        $this->publicRoutes = [
            '/',
            '/api/items',
            '/auth/login',
            '/auth/callback',
            '/auth/logout',
            '/auth/configure',
            '/auth/save-config',
            '/health',
            '/api/eda/webhook'
        ];
    }
    
    /**
     * Main middleware handler
     */
    public function __invoke($httpMethod, $uri, $routeInfo, $next)
    {
        // Check if Keycloak is enabled
        if (!$this->isKeycloakEnabled()) {
            return $next($httpMethod, $uri, $routeInfo);
        }
        
        // Allow public routes without authentication
        if ($this->isPublicRoute($uri)) {
            return $next($httpMethod, $uri, $routeInfo);
        }
        
        // Check if user is authenticated
        if (!$this->isAuthenticated()) {
            $this->redirectToLogin();
            return false;
        }
        
        // Check route-specific permissions
        if (!$this->hasRoutePermission($uri)) {
            $this->sendForbidden();
            return false;
        }
        
        // Continue to next middleware or route handler
        return $next($httpMethod, $uri, $routeInfo);
    }
    
    /**
     * Check if Keycloak is enabled
     */
    private function isKeycloakEnabled(): bool
    {
        return isset($this->config['enabled']) && 
               ($this->config['enabled'] === true || $this->config['enabled'] === 'true');
    }
    
    /**
     * Check if route is public
     */
    private function isPublicRoute(string $uri): bool
    {
        // Exact match
        if (in_array($uri, $this->publicRoutes)) {
            return true;
        }
        
        // Pattern matching for dynamic routes
        $patterns = [
            '/^\/api\/items\/\d+$/',  // /api/items/{id}
            '/^\/assets\/.*$/',        // Static assets
            '/^\/public\/.*$/'         // Public files
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user is authenticated
     */
    private function isAuthenticated(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if user session exists
        if (!isset($_SESSION['user']) || !isset($_SESSION['keycloak_token'])) {
            return false;
        }
        
        // Validate token if needed
        if ($this->isTokenExpired()) {
            return $this->refreshToken();
        }
        
        return true;
    }
    
    /**
     * Check if token is expired
     */
    private function isTokenExpired(): bool
    {
        if (!isset($_SESSION['token_expires_at'])) {
            return true;
        }
        
        return time() >= $_SESSION['token_expires_at'];
    }
    
    /**
     * Refresh token
     */
    private function refreshToken(): bool
    {
        if (!isset($_SESSION['refresh_token'])) {
            return false;
        }
        
        $tokenUrl = $this->config['auth_url'] . '/realms/' . $this->config['realm'] . '/protocol/openid-connect/token';
        
        $data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $_SESSION['refresh_token'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret']
        ];
        
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $tokenData = json_decode($response, true);
            $_SESSION['keycloak_token'] = $tokenData['access_token'];
            $_SESSION['refresh_token'] = $tokenData['refresh_token'];
            $_SESSION['token_expires_at'] = time() + $tokenData['expires_in'] - 60; // 60 second buffer
            return true;
        }
        
        // Clear session on refresh failure
        $this->clearSession();
        return false;
    }
    
    /**
     * Check route-specific permissions
     */
    private function hasRoutePermission(string $uri): bool
    {
        // Admin routes require admin role
        if (strpos($uri, '/admin') === 0) {
            return $this->hasRole('admin');
        }
        
        // API write operations might require specific permissions
        if (strpos($uri, '/api/') === 0 && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
            return $this->hasRole('api_write') || $this->hasRole('admin');
        }
        
        // Default: authenticated users can access
        return true;
    }
    
    /**
     * Check if user has a specific role
     */
    private function hasRole(string $role): bool
    {
        if (!isset($_SESSION['user']['roles'])) {
            return false;
        }
        
        return in_array($role, $_SESSION['user']['roles']);
    }
    
    /**
     * Redirect to Keycloak login
     */
    private function redirectToLogin(): void
    {
        $loginUrl = '/auth/login?redirect=' . urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . $loginUrl);
        exit;
    }
    
    /**
     * Send 403 Forbidden response
     */
    private function sendForbidden(): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'You do not have permission to access this resource'
        ]);
        exit;
    }
    
    /**
     * Clear session
     */
    private function clearSession(): void
    {
        unset($_SESSION['user']);
        unset($_SESSION['keycloak_token']);
        unset($_SESSION['refresh_token']);
        unset($_SESSION['token_expires_at']);
    }
    
    /**
     * Get current user from session
     */
    public static function getCurrentUser(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return $_SESSION['user'] ?? null;
    }
    
    /**
     * Check if current user is authenticated (static helper)
     */
    public static function isUserAuthenticated(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['user']) && isset($_SESSION['keycloak_token']);
    }
}