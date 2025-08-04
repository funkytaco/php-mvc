<?php

namespace Test\Nimbus\Tasks;

use PHPUnit\Framework\TestCase;
use Nimbus\Tasks\ContainerTask;

class ContainerTaskTest extends TestCase
{
    private ContainerTask $containerTask;
    
    protected function setUp(): void
    {
        $this->containerTask = new ContainerTask();
    }
    
    /**
     * Test ContainerTask can be instantiated
     */
    public function testInstantiation(): void
    {
        $containerTask = new ContainerTask();
        $this->assertInstanceOf(ContainerTask::class, $containerTask);
    }
    
    /**
     * Test that ContainerTask extends BaseTask
     */
    public function testExtendsBaseTask(): void
    {
        $this->assertInstanceOf(\Nimbus\Core\BaseTask::class, $this->containerTask);
    }
    
    /**
     * Test that required methods exist
     */
    public function testRequiredMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->containerTask, 'execute'));
        $this->assertTrue(method_exists($this->containerTask, 'up'));
        $this->assertTrue(method_exists($this->containerTask, 'down'));
        $this->assertTrue(method_exists($this->containerTask, 'status'));
    }
}