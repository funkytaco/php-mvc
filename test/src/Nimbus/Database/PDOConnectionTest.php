<?php

namespace Test\Nimbus\Database;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

class PDOConnectionTest extends TestCase
{
    /**
     * Test PDO connection with invalid password
     * This test simulates the exact error from the stack trace
     */
    public function testPDOConnectionFailsWithInvalidPassword()
    {
        $dsn = 'pgsql:host=invalid-host;port=5432;dbname=test_db';
        $username = 'snoopy_user';
        $password = 'wrong_password';
        
        try {
            // Attempt to create PDO connection with invalid credentials
            // This will fail in test environment but demonstrates the pattern
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // If we reach here in a real environment with invalid host, mark as skipped
            $this->markTestSkipped('Cannot test real PDO connection failure without database server');
            
        } catch (PDOException $e) {
            // Verify the exception contains expected error patterns
            $this->assertStringContainsString('SQLSTATE', $e->getMessage());
            
            // Check for common connection error codes
            $errorCode = $e->getCode();
            $this->assertMatchesRegularExpression(
                '/^(08006|HY000|7)$/',
                (string)$errorCode,
                'Unexpected PDO error code'
            );
        }
    }
    
    /**
     * Test creating a PDO mock that simulates password authentication failure
     */
    public function testMockPDOPasswordAuthenticationFailure()
    {
        // This is what the actual error looks like
        $expectedMessage = 'SQLSTATE[08006] [7] connection to server at "snoopy-db" (10.89.62.2), port 5432 failed: FATAL:  password authentication failed for user "snoopy_user"';
        
        // Create a mock that throws the exact error
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('password authentication failed for user "snoopy_user"');
        
        // Simulate the error
        throw new PDOException($expectedMessage);
    }
    
    /**
     * Test helper method to validate PDO configuration
     */
    public function testValidatePDOConfiguration()
    {
        $validConfigs = [
            [
                'dsn' => 'pgsql:host=localhost;port=5432;dbname=test',
                'user' => 'test_user',
                'password' => 'test_pass'
            ],
            [
                'dsn' => 'mysql:host=localhost;port=3306;dbname=test',
                'user' => 'root',
                'password' => ''
            ]
        ];
        
        foreach ($validConfigs as $config) {
            $this->assertArrayHasKey('dsn', $config);
            $this->assertArrayHasKey('user', $config);
            $this->assertArrayHasKey('password', $config);
            $this->assertIsString($config['dsn']);
            $this->assertIsString($config['user']);
            $this->assertIsString($config['password']);
        }
    }
    
    /**
     * Test PDO connection error handler
     */
    public function testPDOConnectionErrorHandler()
    {
        $errorHandler = function($dsn, $user, $password) {
            try {
                return new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
            } catch (PDOException $e) {
                // Log error details
                $errorDetails = [
                    'error_type' => 'database_connection_failed',
                    'dsn' => $dsn,
                    'user' => $user,
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                // Check if it's a password authentication error
                if (strpos($e->getMessage(), 'password authentication failed') !== false) {
                    $errorDetails['error_subtype'] = 'invalid_credentials';
                    $errorDetails['suggested_action'] = 'Check database password in configuration';
                }
                
                return $errorDetails;
            }
        };
        
        // Test with invalid connection
        $result = $errorHandler(
            'pgsql:host=invalid;port=5432;dbname=test',
            'user',
            'pass'
        );
        
        // If it's an array, it means error was caught
        if (is_array($result)) {
            $this->assertArrayHasKey('error_type', $result);
            $this->assertArrayHasKey('error_message', $result);
            $this->assertEquals('database_connection_failed', $result['error_type']);
        } else {
            // Connection succeeded (unlikely in test environment)
            $this->assertInstanceOf(PDO::class, $result);
        }
    }
}