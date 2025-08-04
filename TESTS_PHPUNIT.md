# PHPUnit Testing Documentation

## Overview
This document covers the PHPUnit test suite for the PHP MVC framework, including the recent refactoring and test updates.

## Test Suite Structure

### Test Directories
```
test/
├── bootstrap.php           # Test bootstrap file
├── src/
│   ├── Controllers/        # Controller tests
│   ├── Mock/              # Mock object tests
│   └── Nimbus/            # Nimbus framework tests
│       ├── App/           # AppManager tests
│       ├── Core/          # Core functionality tests
│       ├── Database/      # Database-related tests
│       ├── Password/      # Password management tests
│       ├── Tasks/         # Task class tests (NEW)
│       └── Vault/         # Vault management tests
```

## Test Configuration

### PHPUnit Configuration (`phpunit.xml`)
- **Bootstrap**: `test/bootstrap.php`
- **Test Suite**: All files in `./test/` directory
- **Coverage**: HTML reports in `coverage/` directory
- **Source**: `./src/` directory for coverage analysis
- **Timeouts**: Small(3s), Medium(10s), Large(15s)

## Recent Test Updates

### 1. Fixed Legacy PHPUnit Compatibility Issues

#### Problem
Old test files were using deprecated PHPUnit syntax:
```php
// OLD - PHPUnit 4.x style
class IndexControllerTest extends PHPUnit_Framework_TestCase
{
    public function setup() { ... }
}
```

#### Solution
Updated to modern PHPUnit 10.x syntax:
```php
// NEW - PHPUnit 10.x style
use PHPUnit\Framework\TestCase;

class IndexControllerTest extends TestCase
{
    protected function setUp(): void { ... }
}
```

#### Files Updated:
- `test/src/Controllers/IndexController_Test.php` → `IndexControllerTest.php`
- `test/src/Mock/PDO_Test.php` → `PDOTest.php`

### 2. Fixed PasswordStrategy Enum Test

#### Problem
The `PasswordStrategyTest::testCases()` expected 3 enum cases but the enum had 4:
```php
// Test expected 3 cases
$this->assertCount(3, $cases);
```

#### Solution
Updated test to include the missing `NO_MODIFICATIONS` case:
```php
// Test now expects 4 cases
$this->assertCount(4, $cases);
$this->assertContains(PasswordStrategy::NO_MODIFICATIONS, $cases);
```

### 3. Created Tests for New Task Classes

After refactoring ApplicationTasks.php, created comprehensive tests for the new Task classes:

#### `BaseTaskTest`
Tests the common functionality shared by all Task classes:
- ANSI formatting methods
- Color constants (foreground/background)
- Composer package detection
- Abstract class structure

#### `CreateTaskTest`
Tests app creation functionality:
- Class instantiation
- Inheritance from BaseTask
- Required methods existence (`execute`, `create`, `createWithEda`, `createEdaKeycloak`)

#### `ContainerTaskTest`
Tests container management functionality:
- Class instantiation
- Inheritance from BaseTask
- Required methods existence (`execute`, `up`, `down`, `status`)

#### `InstallTaskTest`
Tests installation functionality:
- Class instantiation
- Inheritance from BaseTask
- Required methods existence (`execute`, `install`, `list`)

#### `FeatureTaskTest`
Tests feature management functionality:
- Class instantiation
- Inheritance from BaseTask
- Required methods existence (`execute`, `addEda`, `addKeycloak`, `addEdaKeycloak`)

## Running Tests

### All Tests
```bash
./vendor/bin/phpunit
```

### Specific Test Suites
```bash
# Run only Nimbus tests
./vendor/bin/phpunit test/src/Nimbus/

# Run only Task tests
./vendor/bin/phpunit test/src/Nimbus/Tasks/

# Run with detailed output
./vendor/bin/phpunit --testdox

# Run without coverage (faster)
./vendor/bin/phpunit --no-coverage
```

### Stop on First Failure
```bash
./vendor/bin/phpunit --stop-on-failure
```

## Test Results

### Current Status ✅
- **Total Tests**: 94
- **Total Assertions**: 290
- **Status**: All tests passing
- **Coverage**: HTML reports generated in `coverage/`

### Expected Warnings
The following warnings are expected and don't indicate test failures:

1. **YAML validation failed: YAML cannot contain tabs** - Tests YAML validation error handling
2. **Failed to backup passwords to vault** - Tests vault backup error conditions
3. **WARNING: image platform (linux/amd64) does not match** - Platform-specific Docker warnings
4. **No code coverage driver available** - Coverage driver not installed (optional)

## Test Categories

### Unit Tests
- **Mock Objects**: `test/src/Mock/`
- **Controllers**: `test/src/Controllers/`
- **Core Classes**: `test/src/Nimbus/Core/`
- **Task Classes**: `test/src/Nimbus/Tasks/`

### Integration Tests
- **App Manager**: `test/src/Nimbus/App/`
- **Database**: `test/src/Nimbus/Database/`
- **Vault Manager**: `test/src/Nimbus/Vault/`

### Feature Tests
- **Password Management**: `test/src/Nimbus/Password/`

## Best Practices

### Test Structure
1. **Arrange**: Set up test data and mocks
2. **Act**: Execute the code being tested
3. **Assert**: Verify the expected outcomes

### Naming Conventions
- Test files end with `Test.php`
- Test methods start with `test`
- Use descriptive method names: `testCreateWithValidParameters()`

### Mock Usage
```php
// Create mocks for external dependencies
$mockVault = $this->createMock(VaultManager::class);
$mockVault->method('isInitialized')->willReturn(true);
```

### Data Providers
Use data providers for testing multiple scenarios:
```php
/**
 * @dataProvider validAppNameProvider
 */
public function testValidAppNames(string $appName): void
{
    // Test logic here
}
```

## Troubleshooting

### Common Issues
1. **Composer autoload not found**: Run `composer install`
2. **Class not found errors**: Check namespace imports
3. **Mock creation fails**: Ensure interface/class exists in autoloader

### Debug Output
```bash
# Verbose output
./vendor/bin/phpunit --verbose

# Debug with var_dump
./vendor/bin/phpunit --debug
```

## Continuous Integration

The test suite is designed to run in CI environments:
- Tests are isolated and don't depend on external services
- Mock objects replace external dependencies
- Temporary directories are cleaned up automatically

## Contributing

When adding new functionality:
1. Write tests first (TDD approach)
2. Ensure tests are isolated and independent
3. Use meaningful assertions
4. Clean up resources in `tearDown()`
5. Update this documentation for significant changes