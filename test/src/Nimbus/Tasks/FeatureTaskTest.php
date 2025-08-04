<?php

namespace Test\Nimbus\Tasks;

use PHPUnit\Framework\TestCase;
use Nimbus\Tasks\FeatureTask;

class FeatureTaskTest extends TestCase
{
    private FeatureTask $featureTask;
    
    protected function setUp(): void
    {
        $this->featureTask = new FeatureTask();
    }
    
    /**
     * Test FeatureTask can be instantiated
     */
    public function testInstantiation(): void
    {
        $featureTask = new FeatureTask();
        $this->assertInstanceOf(FeatureTask::class, $featureTask);
    }
    
    /**
     * Test that FeatureTask extends BaseTask
     */
    public function testExtendsBaseTask(): void
    {
        $this->assertInstanceOf(\Nimbus\Core\BaseTask::class, $this->featureTask);
    }
    
    /**
     * Test that required methods exist
     */
    public function testRequiredMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->featureTask, 'execute'));
        $this->assertTrue(method_exists($this->featureTask, 'addEda'));
        $this->assertTrue(method_exists($this->featureTask, 'addKeycloak'));
        $this->assertTrue(method_exists($this->featureTask, 'addEdaKeycloak'));
    }
}