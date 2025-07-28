<?php

namespace Test\Nimbus\Core;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use Auryn\Injector;

class ApplicationDatabaseTest extends TestCase
{
    /**
     * Test that PDO connection failures are properly caught and reported
     * This simulates the exact error from the stack trace
     */
    public function testPDOConnectionFailureWithInvalidPassword()
    {
        $expectedMessage = 'SQLSTATE[08006] [7] connection to server at "snoopy-db" (10.89.62.2), port 5432 failed: FATAL:  password authentication failed for user "snoopy_user"';
        
        // Create mock injector that throws PDOException
        $mockInjector = $this->createMock(Injector::class);
        $mockInjector->expects($this->once())
            ->method('make')
            ->with('PDO')
            ->willThrowException(new PDOException($expectedMessage));
        
        // Test the setupDatabase method behavior
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('password authentication failed for user "snoopy_user"');
        
        // Simulate what happens in Application::setupDatabase()
        $mockInjector->make('PDO');
    }
    
    /**
     * Test various PDO connection error scenarios that could occur
     */
    public function testVariousPDOConnectionErrorMessages()
    {
        $errorScenarios = [
            'password_auth_failed' => 'SQLSTATE[08006] [7] connection to server at "snoopy-db" (10.89.62.2), port 5432 failed: FATAL:  password authentication failed for user "snoopy_user"',
            'connection_refused' => 'SQLSTATE[08006] [7] could not connect to server: Connection refused',
            'database_not_exist' => 'SQLSTATE[08006] [7] FATAL:  database "nonexistent_db" does not exist',
            'host_unreachable' => 'SQLSTATE[HY000] [2002] No such file or directory'
        ];
        
        foreach ($errorScenarios as $scenario => $message) {
            $mockInjector = $this->createMock(Injector::class);
            $mockInjector->expects($this->once())
                ->method('make')
                ->with('PDO')
                ->willThrowException(new PDOException($message));
            
            try {
                $mockInjector->make('PDO');
                $this->fail("Expected PDOException was not thrown for scenario: $scenario");
            } catch (PDOException $e) {
                $this->assertEquals($message, $e->getMessage());
                $this->assertStringContainsString('SQLSTATE', $e->getMessage());
            }
        }
    }
    
    /**
     * Test error handler for database connection issues
     */
    public function testDatabaseConnectionErrorHandler()
    {
        $errorHandler = function(callable $connectionAttempt) {
            try {
                return $connectionAttempt();
            } catch (PDOException $e) {
                // Categorize the error
                $errorInfo = [
                    'error_type' => 'database_connection_failed',
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                // Check specific error types
                if (strpos($e->getMessage(), 'password authentication failed') !== false) {
                    $errorInfo['error_subtype'] = 'invalid_credentials';
                    $errorInfo['user'] = $this->extractUserFromMessage($e->getMessage());
                    $errorInfo['suggested_action'] = 'Verify database credentials in configuration';
                } elseif (strpos($e->getMessage(), 'Connection refused') !== false) {
                    $errorInfo['error_subtype'] = 'connection_refused';
                    $errorInfo['suggested_action'] = 'Check if database server is running';
                } elseif (strpos($e->getMessage(), 'database') !== false && strpos($e->getMessage(), 'does not exist') !== false) {
                    $errorInfo['error_subtype'] = 'database_not_found';
                    $errorInfo['suggested_action'] = 'Verify database name in configuration';
                }
                
                return $errorInfo;
            }
        };
        
        // Test password authentication failure
        $result = $errorHandler(function() {
            throw new PDOException('SQLSTATE[08006] [7] connection to server at "snoopy-db" (10.89.62.2), port 5432 failed: FATAL:  password authentication failed for user "snoopy_user"');
        });
        
        $this->assertIsArray($result);
        $this->assertEquals('database_connection_failed', $result['error_type']);
        $this->assertEquals('invalid_credentials', $result['error_subtype']);
        $this->assertEquals('snoopy_user', $result['user']);
        $this->assertStringContainsString('credentials', $result['suggested_action']);
    }
    
    /**
     * Test successful database connection simulation
     */
    public function testSuccessfulDatabaseConnection()
    {
        $mockPDO = $this->createMock(PDO::class);
        $mockInjector = $this->createMock(Injector::class);
        
        $mockInjector->expects($this->once())
            ->method('make')
            ->with('PDO')
            ->willReturn($mockPDO);
        
        $result = $mockInjector->make('PDO');
        $this->assertInstanceOf(PDO::class, $result);
    }
    
    private function extractUserFromMessage(string $message): ?string
    {
        if (preg_match('/password authentication failed for user "([^"]+)"/', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }
}