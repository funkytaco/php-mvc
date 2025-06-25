<?php

use Main\Renderer\Renderer;
use Main\Mock\PDO;
use Main\Modules\Date_Module;

class ContactController
{
    protected $renderer;
    protected $conn;
    protected $mod_date;

    public function __construct(Renderer $renderer, PDO $conn, Date_Module $mod_date)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->mod_date = $mod_date;
    }

    public function get()
    {
        echo $this->renderer->render('contact.html', [
            'appName' => 'Semantic UI',
            'current_date' => $this->mod_date->getCurrentDate()
        ]);
    }
}
