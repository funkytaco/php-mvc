<?php

require_once('ControllerInterface.php');

use Main\Renderer\Renderer;
use Main\Modules\Date_Module;


class ExpiryController
{
    protected $renderer;
    protected $conn;
    protected $mod_date;
    private $data;


    public function __construct(
        Renderer $renderer,
        PDO $conn, 
        Date_Module $mod_date
    ) {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->mod_date = $mod_date;

        $this->data = [
            'appName' => 'LKUI - License Key UI',
            'title' => 'License Key Management System',
            'myDateModule' => $mod_date->getDate()
        ];
    }

    public function get() {
        
        $html = $this->renderer->render('expiry.html', $this->data);
        echo $html;
    }


    
}
