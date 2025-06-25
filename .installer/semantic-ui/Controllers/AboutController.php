<?php

use Main\Renderer\Renderer;
use Main\Mock\PDO;
use Main\Modules\Date_Module;

    /**
    *   NOTE that the following are injected into your controller
    *   Renderer $renderer - Template Engine
    *   PDO $conn - PDO
    *   Dependency Injecting makes testing easier!
    ***/

class AboutController implements ControllerInterface
{
    protected $renderer;
    protected $conn;
    protected $mod_date;
    private $data;

    public function __construct(Renderer $renderer, PDO $conn, Date_Module $mod_date)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->mod_date = $mod_date;

        $this->data = [
            'appName' => "SemanticUI PHP-MVC Template",
            'date' => $mod_date->getDate()
        ];
    }

    public function get()
    {
        echo $this->renderer->render('index.html', $this->data);
    }
}
