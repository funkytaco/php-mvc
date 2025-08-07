<?php

declare(strict_types=1);

namespace Nimbus\Controller;

use Auryn\Injector;
use Main\Renderer\Renderer;
use PDO;

/**
 * AbstractController provides common functionality for all controllers
 * 
 * @package Nimbus\Controller
 * @author Nimbus Framework
 * @license Apache-2.0
 * @copyright 2025 SmallCloud, LLC
 * 
 * @property-read Renderer $view The view renderer instance
 * @property-read PDO $db The database connection instance
 * @property-read array<string, mixed> $config Application configuration
 */
abstract class AbstractController implements ControllerInterface
{
    /** @var Injector Dependency injection container */
    protected Injector $container;
    
    /** @var Renderer|null View renderer instance */
    protected ?Renderer $view = null;
    
    /** @var PDO|null Database connection */
    protected ?PDO $db = null;
    
    /** @var array<string, mixed> Application configuration */
    protected array $config = [];
    
    /**
     * Constructor receives the dependency injection container
     * 
     * @param Injector $container The dependency injection container
     */
    public function __construct(Injector $container)
    {
        $this->container = $container;
        $this->initialize();
    }
    
    /**
     * Initialize controller dependencies
     * 
     * Override this method in child classes to initialize specific dependencies
     * 
     * @return void
     */
    protected function initialize(): void
    {
        // Lazy load dependencies as needed
    }
    
    /**
     * Get the view renderer
     * 
     * Lazy loads the renderer on first access
     * 
     * @return Renderer The view renderer instance
     */
    protected function getView(): Renderer
    {
        if (!$this->view) {
            /** @var Renderer $renderer */
            $renderer = $this->container->make('Main\Renderer\Renderer');
            $this->view = $renderer;
        }
        return $this->view;
    }
    
    /**
     * Get the database connection
     * 
     * Lazy loads the PDO connection on first access
     * 
     * @return PDO The database connection
     */
    protected function getDb(): PDO
    {
        if (!$this->db) {
            $this->db = $this->container->make('PDO');
        }
        return $this->db;
    }
    
    /**
     * Get configuration
     * 
     * Loads configuration from CONFIG_FILE constant if defined
     * 
     * @return array<string, mixed> The application configuration
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
     * 
     * @param string $template The template name/path
     * @param array<string, mixed> $data Data to pass to the template
     * @return string The rendered HTML output
     */
    protected function render(string $template, array $data = []): string
    {
        return $this->getView()->render($template, $data);
    }
    
    /**
     * Send JSON response
     * 
     * @param array<string, mixed> $data The data to encode as JSON
     * @param int $status HTTP status code (default: 200)
     * @return void
     */
    protected function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
    }
    
    /**
     * Send error response
     * 
     * @param string $message The error message
     * @param int $status HTTP status code (default: 400)
     * @return void
     */
    protected function error(string $message, int $status = 400): void
    {
        $this->json(['error' => $message], $status);
    }
    
    /**
     * Send success response
     * 
     * @param mixed $data Optional data to include in response
     * @param string $message Success message (default: 'Success')
     * @return void
     */
    protected function success(mixed $data = null, string $message = 'Success'): void
    {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        $this->json($response);
    }
    
    /**
     * Redirect to URL
     * 
     * @param string $url The URL to redirect to
     * @param int $status HTTP status code (default: 302)
     * @return void
     */
    protected function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header("Location: $url");
        exit;
    }
    
    /**
     * Get request data from various sources
     * 
     * Merges data from $_POST, named_vars, and JSON body
     * 
     * @return array<string, mixed> The request data
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
     * 
     * @param array<string, mixed> $data The data to validate
     * @param array<int, string> $required List of required field names
     * @return bool True if all required fields are present and non-empty
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
     * Default implementation for GET requests
     * 
     * @param mixed ...$params Route parameters
     * @return void
     */
    public function get(...$params): void
    {
        $this->error('Method not allowed', 405);
    }
    
    /**
     * Default implementation for POST requests
     * 
     * @param mixed ...$params Route parameters
     * @return void
     */
    public function post(...$params): void
    {
        $this->error('Method not allowed', 405);
    }
    
    /**
     * Default implementation for PUT requests
     * 
     * @param mixed ...$params Route parameters
     * @return void
     */
    public function put(...$params): void
    {
        $this->error('Method not allowed', 405);
    }
    
    /**
     * Default implementation for DELETE requests
     * 
     * @param mixed ...$params Route parameters
     * @return void
     */
    public function delete(...$params): void
    {
        $this->error('Method not allowed', 405);
    }
    
    /**
     * Default implementation for PATCH requests
     * 
     * @param mixed ...$params Route parameters
     * @return void
     */
    public function patch(...$params): void
    {
        $this->error('Method not allowed', 405);
    }
}