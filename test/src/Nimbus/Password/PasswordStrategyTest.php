<?php

namespace Test\Nimbus\Password;

use PHPUnit\Framework\TestCase;
use Nimbus\Password\PasswordStrategy;

class PasswordStrategyTest extends TestCase
{
    /**
     * Test enum values
     */
    public function testEnumValues(): void
    {
        $this->assertEquals('vault_restore', PasswordStrategy::VAULT_RESTORE->value);
        $this->assertEquals('existing_data', PasswordStrategy::EXISTING_DATA->value);
        $this->assertEquals('generate_new', PasswordStrategy::GENERATE_NEW->value);
        $this->assertEquals('no_modifications', PasswordStrategy::NO_MODIFICATIONS->value);
    }
    
    /**
     * Test getDescription method
     */
    public function testGetDescription(): void
    {
        $this->assertEquals(
            'Restore passwords from vault',
            PasswordStrategy::VAULT_RESTORE->getDescription()
        );
        
        $this->assertEquals(
            'Extract passwords from existing data',
            PasswordStrategy::EXISTING_DATA->getDescription()
        );
        
        $this->assertEquals(
            'Generate new random passwords',
            PasswordStrategy::GENERATE_NEW->getDescription()
        );
        
        $this->assertEquals(
            'Preserve existing passwords, generate only for new services',
            PasswordStrategy::NO_MODIFICATIONS->getDescription()
        );
    }
    
    /**
     * Test requiresForceInit method
     */
    public function testRequiresForceInit(): void
    {
        $this->assertTrue(PasswordStrategy::VAULT_RESTORE->requiresForceInit());
        $this->assertFalse(PasswordStrategy::EXISTING_DATA->requiresForceInit());
        $this->assertFalse(PasswordStrategy::GENERATE_NEW->requiresForceInit());
        $this->assertFalse(PasswordStrategy::NO_MODIFICATIONS->requiresForceInit());
    }
    
    /**
     * Test enum instantiation from string
     */
    public function testFromString(): void
    {
        $strategy = PasswordStrategy::from('vault_restore');
        $this->assertEquals(PasswordStrategy::VAULT_RESTORE, $strategy);
        
        $strategy = PasswordStrategy::from('existing_data');
        $this->assertEquals(PasswordStrategy::EXISTING_DATA, $strategy);
        
        $strategy = PasswordStrategy::from('generate_new');
        $this->assertEquals(PasswordStrategy::GENERATE_NEW, $strategy);
    }
    
    /**
     * Test tryFrom method with invalid value
     */
    public function testTryFromInvalid(): void
    {
        $strategy = PasswordStrategy::tryFrom('invalid_strategy');
        $this->assertNull($strategy);
    }
    
    /**
     * Test all enum cases
     */
    public function testCases(): void
    {
        $cases = PasswordStrategy::cases();
        
        $this->assertCount(4, $cases);
        $this->assertContains(PasswordStrategy::VAULT_RESTORE, $cases);
        $this->assertContains(PasswordStrategy::EXISTING_DATA, $cases);
        $this->assertContains(PasswordStrategy::GENERATE_NEW, $cases);
        $this->assertContains(PasswordStrategy::NO_MODIFICATIONS, $cases);
    }
}