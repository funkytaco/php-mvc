<?php

namespace Nimbus\Controller;

use Auryn\Injector;

/**
 * AbstractController provides common functionality for all controllers
 */
abstract class AbstractController implements ControllerInterface
{
    protected Injector $container;
    protected $view;
    protected $db;
    protected array $config;
    
    /**
     * Constructor receives the dependency injection container
     */
    public function __construct(Injector $container)
    {
        $this->container = $container;
        $this->initialize();
    }
    
    /**
     * Initialize controller dependencies
     */
    protected function initialize(): void
    {
        // Lazy load dependencies as needed
    }
    
    /**
     * Get the view renderer
     */
    protected function getView()
    {
        if (!$this->view) {
            $this->view = $this->container->make('Main\Renderer\Renderer');
        }
        return $this->view;
    }
    
    /**
     * Get the database connection
     */
    protected function getDb(): \PDO
    {
        if (!$this->db) {
            $this->db = $this->container->make('PDO');
        }
        return $this->db;
    }
    
    /**
     * Get configuration
     */
    protected function getConfig(): array
    {
        if (empty($this->config)) {
            if (defined('CONFIG_FILE') && is_file(CONFIG_FILE)) {
                $this->config = include(CONFIG_FILE);
            } else {
                $this->config = [];
            }
        }
        return $this->config;
    }
    
    /**
     * Render a view template
     */
    protected function render(string $template, array $data = []): string
    {
        return $this->getView()->render($template, $data);
    }
    
    /**
     * Send JSON response
     */
    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
    }
    
    /**
     * Send error response
     */
    protected function error(string $message, int $status = 400): void
    {
        $this->json(['error' => $message], $status);
    }
    
    /**
     * Send success response
     */
    protected function success($data = null, string $message = 'Success'): void
    {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        $this->json($response);
    }
    
    /**
     * Redirect to URL
     */
    protected function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header("Location: $url");
        exit;
    }
    
    /**
     * Get request data from various sources
     */
    protected function getRequestData(): array
    {
        $data = [];
        
        // Get from $_POST
        if (!empty($_POST)) {
            $data = array_merge($data, $_POST);
        }
        
        // Get from named_vars if available
        if (isset($GLOBALS['named_vars']) && is_array($GLOBALS['named_vars'])) {
            $data = array_merge($data, $GLOBALS['named_vars']);
        }
        
        // Get from JSON body if content-type is JSON
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $jsonData = json_decode($rawInput, true);
            if (is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        }
        
        return $data;
    }
    
    /**
     * Validate required fields
     */
    protected function validate(array $data, array $required): bool
    {
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Default implementations for HTTP methods
     */
    public function get(...$params)
    {
        $this->error('Method not allowed', 405);
    }
    
    public function post(...$params)
    {
        $this->error('Method not allowed', 405);
    }
    
    public function put(...$params)
    {
        $this->error('Method not allowed', 405);
    }
    
    public function delete(...$params)
    {
        $this->error('Method not allowed', 405);
    }
    
    public function patch(...$params)
    {
        $this->error('Method not allowed', 405);
    }
}