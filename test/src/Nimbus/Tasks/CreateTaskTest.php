<?php

namespace Test\Nimbus\Tasks;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Nimbus\Tasks\CreateTask;

class CreateTaskTest extends TestCase
{
    private CreateTask $createTask;
    private string $baseDir;
    
    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/test_nimbus_create_' . uniqid();
        mkdir($this->baseDir, 0777, true);
        
        $this->createTask = new CreateTask();
    }
    
    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }
    
    /**
     * Test CreateTask can be instantiated
     */
    public function testInstantiation(): void
    {
        $createTask = new CreateTask();
        $this->assertInstanceOf(CreateTask::class, $createTask);
    }
    
    /**
     * Test that CreateTask extends BaseTask
     */
    public function testExtendsBaseTask(): void
    {
        $this->assertInstanceOf(\Nimbus\Core\BaseTask::class, $this->createTask);
    }
    
    /**
     * Test that required methods exist
     */
    public function testRequiredMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->createTask, 'execute'));
        $this->assertTrue(method_exists($this->createTask, 'create'));
        $this->assertTrue(method_exists($this->createTask, 'createWithEda'));
        $this->assertTrue(method_exists($this->createTask, 'createEdaKeycloak'));
    }
    
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}