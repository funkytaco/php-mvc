<?php
require_once('ControllerInterface.php');

use \Main\Renderer\Renderer;
use \Main\Mock\PDO;
use Main\Modules\Date_Module;

    /**
    *   NOTE that the following are injected into your controller
    *   Renderer $renderer - Template Engine
    *   PDO $conn - PDO
    *   Dependency Injecting makes testing easier!
    ***/

    class AboutController implements ControllerInterface {

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
                'appName' => "PHP-MVC Template",
                'myDateModule' => $mod_date->getDate(),
                'projectList' => $this->getLegacyProjects()
            ];
        }

        public function getLegacyProjects() {
            $projPaths = array();
            if (is_dir('Legacy')) {
                $paths = scandir('Legacy');
                foreach ($paths as $path) {
                    if (is_dir('Legacy/' . $path) && $path != '.' && $path != '..') {
                        $projPaths[] = $path;
                    }
                }
            }
            return $projPaths;
        }

        public function get() {
            // Add GET parameters to data if needed
            $this->data['getVar'] = $_GET;
            
            $html = $this->renderer->render('about', $this->data);
            echo $html;
        }
    }
