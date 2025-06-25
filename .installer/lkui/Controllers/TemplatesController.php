<?php

use Main\Renderer\Renderer;
use Main\Mock\PDO;
use App\Controllers\ControllerInterface;
use Main\Router\RouteCollector;

class TemplatesController implements ControllerInterface
{
    protected $renderer;
    protected $conn;

    public function __construct(Renderer $renderer, PDO $conn)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
    }

    public function get(RouteCollector $router)
    {
        $router->get('/lkui/api/templates', [$this, 'listTemplates']);
        $router->get('/lkui/api/templates/{name}', [$this, 'getTemplate']);
    }

    public function post(RouteCollector $router)
    {
        // Currently no POST routes for templates
    }
    public function listTemplates()
    {
        // TODO: Implement template listing
        return [];
    }

    public function getTemplate($templateName)
    {
        // TODO: Implement template retrieval
        return [
            'common_name' => '*.example.com',
            'csr_options' => []
        ];
    }
}
