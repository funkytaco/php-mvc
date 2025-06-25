<?php
include('ControllerInterface.php');

use \Main\Renderer\Renderer;
use \Main\Mock\PDO;
use Main\Modules\Date_Module;

    /**
    *   NOTE that the following are injected into your controller
    *   Renderer $renderer - Template Engine
    *   PDO $conn - PDO
    *   Dependency Injecting makes testing easier!
    ***/

    class IndexController implements ControllerInterface {

        private $data;
        //use DemoData;

        public function __construct(
            Renderer $renderer,
            PDO $conn, Date_Module $mod_date
        ) {

            $this->renderer = $renderer;
            $this->conn = $conn;

            $this->data = [
                    'appName' => "PHP-MVC Template",
                    'date' => $mod_date->getDate(),
                    'projectList' => self::getLegacyProjects()
                ];
        }

        public function getLegacyProjects() {
            $projPaths = array();
            if (is_dir('Legacy')) {
                $paths = scandir('Legacy');
                foreach ($paths as $path) {
                    if (is_dir('Legacy' . $path) && $path != '.' && $path != '..') {
                        $projPaths[] = $path;
                    }
                }
            }
            return $projPaths;
        }

        public function get() {
            $this->data['getVar'] = $_GET;
            $html = $this->renderer->render('index', $this->data);
            echo $html;
        }

        public function getAbout() {
            return $this->get();
        }
        
        public function getContact() {
            return $this->get();
        }


    }
