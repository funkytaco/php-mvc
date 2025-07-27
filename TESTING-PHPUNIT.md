# PHPUnit Testing Guide for PHP-MVC-LKUI

This guide covers the PHPUnit testing setup and practices for the PHP-MVC-LKUI project, with a focus on the Nimbus components.

## Table of Contents
- [Testing Setup](#testing-setup)
- [Running Tests](#running-tests)
- [Test Structure](#test-structure)
- [Writing Tests](#writing-tests)
- [Testing Best Practices](#testing-best-practices)
- [Component Test Coverage](#component-test-coverage)

## Testing Setup

### Prerequisites
- PHP 8.2 or higher
- Composer
- PHPUnit 10.5+ (installed via composer)

### Configuration
The project uses `phpunit.xml` for test configuration:

```xml
<?xml version="1.0"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    backupGlobals="false"
    colors="true"
    processIsolation="false"
    bootstrap="test/bootstrap.php"
    timeoutForSmallTests="3"
    timeoutForMediumTests="10"
    timeoutForLargeTests="15"
    cacheDirectory=".phpunit.cache"
    >
    <testsuites>
        <testsuite name="First Tests">
            <directory>./test/</directory>
        </testsuite>
    </testsuites>

    <coverage>
        <report>
            <html outputDirectory="coverage"/>
        </report>
    </coverage>

    <source>
        <include>
            <directory suffix=".php">./src/</directory>
        </include>
    </source>
</phpunit>
```

## Running Tests

### Run All Tests
```bash
./vendor/bin/phpunit
```

### Run Tests with Details
```bash
./vendor/bin/phpunit --testdox
```

### Run Specific Test Suite
```bash
# Run only Nimbus tests
./vendor/bin/phpunit --testdox test/src/Nimbus/

# Run only PasswordManager tests
./vendor/bin/phpunit --testdox test/src/Nimbus/Password/

# Run only AppManager tests
./vendor/bin/phpunit --testdox test/src/Nimbus/App/
```

### Run with Code Coverage
```bash
# Requires Xdebug or PCOV
./vendor/bin/phpunit --coverage-html coverage
```

## Test Structure

Tests follow the same namespace structure as the source code:

```
test/
├── bootstrap.php              # Test bootstrap file
└── src/
    └── Nimbus/
        ├── App/
        │   └── AppManagerTest.php
        └── Password/
            ├── PasswordManagerTest.php
            ├── PasswordSetTest.php
            └── PasswordStrategyTest.php
```

## Writing Tests

### Basic Test Structure

```php
<?php

namespace Test\Nimbus\Password;

use PHPUnit\Framework\TestCase;
use Nimbus\Password\PasswordManager;

class PasswordManagerTest extends TestCase
{
    private PasswordManager $passwordManager;
    
    protected function setUp(): void
    {
        // Initialize test dependencies
        $this->passwordManager = new PasswordManager(...);
    }
    
    protected function tearDown(): void
    {
        // Clean up after tests
    }
    
    public function testSomething(): void
    {
        // Arrange
        $input = 'test-data';
        
        // Act
        $result = $this->passwordManager->doSomething($input);
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Mocking Dependencies

For classes with external dependencies (like VaultManager), use mocks:

```php
public function testWithMockedDependency(): void
{
    // Create mock
    $vaultManager = $this->createMock(VaultManager::class);
    
    // Configure mock behavior
    $vaultManager->expects($this->once())
        ->method('isInitialized')
        ->willReturn(true);
    
    // Use mock in test
    $passwordManager = new PasswordManager($vaultManager, '/base/dir');
}
```

### Testing Private/Protected Methods

For testing private methods, use reflection or anonymous classes:

```php
// Using anonymous class to override protected method
$appManager = new class($this->baseDir) extends AppManager {
    public $mockVaultManager;
    
    protected function getVaultManager(): VaultManager
    {
        return $this->mockVaultManager;
    }
};

// Using reflection for private methods
$reflection = new \ReflectionClass($appManager);
$method = $reflection->getMethod('generatePort');
$method->setAccessible(true);
$result = $method->invoke($appManager, 'app-name');
```

## Testing Best Practices

### 1. Test Isolation
- Each test should be independent
- Use `setUp()` and `tearDown()` for initialization and cleanup
- Create temporary directories for file operations

### 2. Test Data
- Use descriptive test data
- Create helper methods for complex test data setup
- Clean up test files and directories after tests

### 3. Assertions
- Use specific assertions (`assertEquals`, `assertInstanceOf`, etc.)
- Test both success and failure scenarios
- Include edge cases and boundary conditions

### 4. Test Naming
- Use descriptive test method names
- Follow pattern: `test<Method><Scenario><ExpectedResult>`
- Example: `testResolvePasswordsWithVaultRestore`

### 5. Exception Testing
```php
public function testInvalidInput(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Expected error message");
    
    $this->appManager->createFromTemplate('Invalid!Name', 'template');
}
```

## Component Test Coverage

### PasswordManager (8 tests)
- Password resolution strategies (vault, existing data, generate new)
- Vault backup functionality
- Password generation
- Error handling

### PasswordSet (8 tests)
- Construction and properties
- Force initialization logic
- Array conversion
- Strategy descriptions

### PasswordStrategy (6 tests)
- Enum values and methods
- Strategy-specific behaviors
- String conversions

### AppManager (24 tests)
- App creation and deletion
- Template management
- Container generation
- Feature management (EDA, Keycloak)
- Configuration handling
- YAML validation

## Common Testing Patterns

### Testing File Operations
```php
protected function setUp(): void
{
    $this->baseDir = sys_get_temp_dir() . '/test_nimbus_' . uniqid();
    mkdir($this->baseDir, 0777, true);
}

protected function tearDown(): void
{
    $this->removeDirectory($this->baseDir);
}

private function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) return;
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? $this->removeDirectory($path) : unlink($path);
    }
    rmdir($dir);
}
```

### Testing External Commands
When testing classes that use `shell_exec()`:

```php
public function testExternalCommand(): void
{
    // Can't easily mock shell_exec, so test the behavior
    $result = $this->appManager->checkPodmanCompose();
    
    $this->assertIsArray($result);
    $this->assertArrayHasKey('installed', $result);
    $this->assertArrayHasKey('version', $result);
}
```

## Continuous Integration

Add to your CI pipeline:

```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: xdebug
      
      - name: Install dependencies
        run: composer install
      
      - name: Run tests
        run: ./vendor/bin/phpunit --coverage-clover coverage.xml
      
      - name: Upload coverage
        uses: codecov/codecov-action@v2
```

## Troubleshooting

### Common Issues

1. **"No code coverage driver available"**
   - Install Xdebug or PCOV for code coverage
   - Or run tests without coverage: `./vendor/bin/phpunit`

2. **"Class not found" errors**
   - Check autoloading in `composer.json`
   - Run `composer dump-autoload`

3. **Test isolation issues**
   - Ensure proper cleanup in `tearDown()`
   - Use unique identifiers for test data

### Debugging Tests
```bash
# Run specific test method
./vendor/bin/phpunit --filter testMethodName

# Stop on first failure
./vendor/bin/phpunit --stop-on-failure

# Verbose output
./vendor/bin/phpunit -v
```

## Contributing

When adding new features:
1. Write tests first (TDD approach)
2. Ensure all tests pass
3. Maintain or improve code coverage
4. Follow existing test patterns
5. Update this documentation if needed