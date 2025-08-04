<?php

use PHPUnit\Framework\TestCase;

class PDOTest extends TestCase
{
    private $conn;
    private $mockGetUserData;

    protected function setUp(): void {
        $this->conn = new Main\Mock\PDO();
        $this->mockGetUserData = [array("name" => "@funkytaco"), array("name" => "@Foo"), array("name" => "@Bar")];
    }

    protected function tearDown(): void {
    }

    /**
    * @small
    */
    public function testInstanceOfMockPDO() {
        $this->assertInstanceOf('Main\Mock\PDO', $this->conn);
    }
    
    /**
    * @small
    */
    public function testMockGetUsers() {
        $this->assertEquals($this->mockGetUserData, $this->conn->getUsers());
    }
}
