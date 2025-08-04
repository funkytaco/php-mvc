<?php

namespace Test\Nimbus\Tasks;

use PHPUnit\Framework\TestCase;
use Nimbus\Tasks\InstallTask;

class InstallTaskTest extends TestCase
{
    private InstallTask $installTask;
    
    protected function setUp(): void
    {
        $this->installTask = new InstallTask();
    }
    
    /**
     * Test InstallTask can be instantiated
     */
    public function testInstantiation(): void
    {
        $installTask = new InstallTask();
        $this->assertInstanceOf(InstallTask::class, $installTask);
    }
    
    /**
     * Test that InstallTask extends BaseTask
     */
    public function testExtendsBaseTask(): void
    {
        $this->assertInstanceOf(\Nimbus\Core\BaseTask::class, $this->installTask);
    }
    
    /**
     * Test that required methods exist
     */
    public function testRequiredMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->installTask, 'execute'));
        $this->assertTrue(method_exists($this->installTask, 'install'));
        $this->assertTrue(method_exists($this->installTask, 'list'));
    }
}