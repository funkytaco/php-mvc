<?php

use PHPUnit\Framework\TestCase;

class IndexControllerTest extends TestCase
{
    private $IndexCtrl;

    protected function setUp(): void {
        $renderer = new Main\Renderer\MustacheRenderer(new Mustache_Engine);
        $conn = new \Main\Mock\PDO;
        $this->IndexCtrl = new Main\Controllers\IndexController($renderer, $conn);
    }

    protected function tearDown(): void {}

    /**
    * @small
    */
    public function testInstanceOfIController() {
        $this->assertInstanceOf('Main\Controllers\IController', $this->IndexCtrl);
    }
}