# PHPUnit Test Suite Documentation

## Overview

This document provides detailed test coverage information for the core Nimbus components: VaultManager, PasswordManager, and AppManager. Each component has comprehensive test suites following PHPUnit best practices.

## Test Statistics

| Component | Test Methods | Assertions | Coverage Areas |
|-----------|-------------|------------|----------------|
| VaultManager | 20 | 46+ | Vault operations, YAML processing, security |
| PasswordManager | 8 | 35+ | Password strategies, vault integration |
| AppManager | 24 | 60+ | App lifecycle, containers, features |

## VaultManager Test Suite

**Location:** `test/src/Nimbus/Vault/VaultManagerTest.php`  
**Class:** `Test\Nimbus\Vault\VaultManagerTest`

### Test Coverage

#### Core Vault Operations
- **Vault Initialization** (2 tests)
  - `testInitializeVaultWithGeneratedPassword()` - Auto-generates secure master password
  - `testInitializeVaultWithProvidedPassword()` - Uses custom master password
  
- **Vault State Management** (3 tests)
  - `testIsInitialized()` - Checks vault initialization status
  - `testBackupAppCredentialsWithoutInitialization()` - Exception handling for uninitialized vault
  - `testBackupAppCredentialsSimulated()` - Simulates successful credential backup

#### Credential Management
- **Backup & Restore** (4 tests)
  - `testListBackedUpAppsWithoutInitialization()` - Empty list when not initialized
  - `testRemoveAppCredentialsWithoutInitialization()` - Returns false when not initialized  
  - `testGetAllCredentialsWithoutInitialization()` - Empty array when not initialized
  - `testRestoreNonExistentAppCredentials()` - Returns null for missing apps

#### Container Integration
- **Password Extraction** (2 tests)
  - `testExtractAppCredentialsEmpty()` - Returns empty array when no containers found
  - `testExtractPasswordFromContainerMethod()` - Tests private method via reflection

#### YAML Processing
- **Parsing & Generation** (4 tests)
  - `testParseSimpleYaml()` - Complex YAML structure parsing
  - `testArrayToSimpleYaml()` - Array to YAML conversion
  - `testParseEmptyYaml()` - Edge cases with empty/comment-only content
  - `testArrayToYamlWithEmptyApps()` - Empty structure handling

#### Security & Utilities
- **Security Features** (3 tests)
  - `testGenerateSecurePassword()` - Password generation with custom lengths
  - `testVaultFilePermissions()` - File permission validation (0700/0600)
  - `testVaultInitializationCreatesDirectoryStructure()` - Directory creation with proper permissions

#### Edge Cases
- **Error Handling** (2 tests)
  - `testRemoveNonExistentAppCredentials()` - Graceful handling of missing apps
  - `testConstructorWithNullBaseDir()` - Constructor behavior validation

### Key Test Patterns

```php
// Temporary directory setup for isolation
$this->baseDir = sys_get_temp_dir() . '/test_vault_' . uniqid();

// Reflection for private method testing
$reflection = new \ReflectionClass($this->vaultManager);
$method = $reflection->getMethod('parseSimpleYaml');
$method->setAccessible(true);

// File permission verification
$perms = substr(sprintf('%o', fileperms($file)), -4);
$this->assertEquals('0600', $perms);
```

## PasswordManager Test Suite

**Location:** `test/src/Nimbus/Password/PasswordManagerTest.php`  
**Class:** `Test\Nimbus\Password\PasswordManagerTest`

### Test Coverage

#### Password Resolution Strategies
- **Vault Restore Strategy** (1 test)
  - `testResolvePasswordsWithVaultRestore()` - Restores passwords from initialized vault
  - Validates all password types: database, keycloak admin, keycloak DB, client secret
  - Tests `PasswordStrategy::VAULT_RESTORE` assignment

- **Existing Data Strategy** (1 test)
  - `testResolvePasswordsWithExistingData()` - Detects existing PostgreSQL data directory
  - Creates mock `PG_VERSION` file to simulate existing installation
  - Tests `PasswordStrategy::EXISTING_DATA` assignment

- **Generate New Strategy** (1 test)
  - `testResolvePasswordsWithGenerateNew()` - Default fallback strategy
  - Validates 32-character password generation
  - Tests `PasswordStrategy::GENERATE_NEW` assignment

#### Error Handling
- **Vault Integration Errors** (1 test)
  - `testVaultCredentialsCheckWithException()` - Handles vault access failures
  - Graceful fallback to password generation

#### Vault Backup Operations
- **Successful Backup** (1 test)
  - `testBackupToVaultSuccess()` - Tests credential backup to initialized vault
  - Validates `PasswordSet.toArray()` conversion

- **Backup Error Conditions** (2 tests)
  - `testBackupToVaultNotInitialized()` - Returns false when vault not ready
  - `testBackupToVaultWithException()` - Handles backup failures gracefully

#### Password Quality
- **Generation Validation** (1 test)
  - `testPasswordGeneration()` - Validates alphanumeric character sets
  - Ensures shell-safe password characters

### Key Test Patterns

```php
// Mock VaultManager dependency
$this->vaultManager = $this->createMock(VaultManager::class);
$this->vaultManager->expects($this->once())
    ->method('isInitialized')
    ->willReturn(true);

// PasswordSet validation
$this->assertInstanceOf(PasswordSet::class, $passwordSet);
$this->assertEquals('vault_db_pass', $passwordSet->databasePassword);
$this->assertEquals(PasswordStrategy::VAULT_RESTORE, $passwordSet->strategy);

// Password quality checks
$this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $password);
$this->assertEquals(32, strlen($password));
```

## AppManager Test Suite

**Location:** `test/src/Nimbus/App/AppManagerTest.php`  
**Class:** `Test\Nimbus\App\AppManagerTest`

### Test Coverage

#### Constructor & Initialization
- **Directory Setup** (1 test)
  - `testConstructor()` - Validates property initialization via reflection
  - Checks `baseDir`, `installerDir`, `templatesDir` setup

#### App Creation from Templates
- **Validation & Error Handling** (3 tests)
  - `testCreateFromTemplateMissingTemplate()` - Missing template exception
  - `testCreateFromTemplateInvalidAppName()` - Invalid name validation
  - `testCreateFromTemplateAppAlreadyExists()` - Duplicate app prevention

- **Successful Creation** (1 test)
  - `testCreateFromTemplateSuccess()` - Complete app creation workflow
  - Uses anonymous class to mock `VaultManager` dependency
  - Validates directory structure and configuration files

#### App Management
- **Installation** (1 test)
  - `testInstallMissingApp()` - Error handling for missing apps

- **Listing & Existence** (3 tests)
  - `testListAppsEmpty()` - Empty list when no apps exist
  - `testListAppsWithApps()` - Populated list from `apps.json`
  - `testAppExists()` - App existence validation

#### Configuration Management
- **Config Loading** (2 tests)
  - `testLoadAppConfig()` - Successful config loading from `app.nimbus.json`
  - `testLoadAppConfigMissing()` - Exception for missing config files

#### Port Generation
- **Unique Port Assignment** (2 tests)
  - `testGeneratePort()` - Tests deterministic port generation (8000-8999 range)
  - `testGenerateEdaPort()` - Tests EDA port generation (5000-5999 range)
  - Validates same app names generate consistent ports

#### Feature Management
- **EDA (Event Driven Architecture)** (3 tests)
  - `testSetEdaNonExistentApp()` - Error handling for missing apps
  - `testSetEdaSuccess()` - Successful EDA configuration update
  - `testAddEdaAlreadyEnabled()` - Prevention of duplicate EDA enablement

- **Keycloak Integration** (1 test)
  - `testAddKeycloakSuccess()` - Complete Keycloak integration workflow
  - Uses mock template with Keycloak components
  - Validates config updates and file copying

#### App Deletion
- **Deletion Operations** (2 tests)
  - `testDeleteAppNonExistent()` - Error handling for missing apps
  - `testDeleteAppSuccess()` - Complete app removal workflow
  - Validates directory cleanup and registry updates

#### Container Management
- **Container Generation** (1 test)
  - `testGenerateContainers()` - YAML compose file generation
  - Password resolution integration
  - Template processing and variable substitution

#### External Dependencies
- **System Integration** (1 test)
  - `testCheckPodmanCompose()` - Static method testing for system dependencies
  - Validates return structure with `installed`, `version`, `error` keys

#### Utility Functions
- **YAML Validation** (2 tests)
  - `testValidateYamlValid()` - Valid YAML structure validation
  - `testValidateYamlWithTabs()` - Tab character detection and rejection

- **App Discovery** (1 test)
  - `testGetStartableApps()` - Discovers apps with compose files
  - Validates app metadata collection

### Key Test Patterns

```php
// Anonymous class for dependency mocking
$appManager = new class($this->baseDir) extends AppManager {
    public $mockVaultManager;
    
    protected function getVaultManager(): VaultManager
    {
        return $this->mockVaultManager;
    }
};

// Reflection for private method testing
$reflection = new \ReflectionClass($this->appManager);
$method = $reflection->getMethod('generatePort');
$method->setAccessible(true);
$port = $method->invoke($this->appManager, 'app-name');

// Mock template creation for testing
private function createMockTemplate(string $templateName, bool $withKeycloak = false): void
{
    $templateDir = $this->templatesDir . '/' . $templateName;
    mkdir($templateDir, 0777, true);
    // Create template files...
}
```

## Testing Best Practices Used

### Test Isolation
- Each test uses unique temporary directories
- Proper cleanup in `tearDown()` methods
- Independent test execution without side effects

### Dependency Mocking
- **VaultManager**: Mocked for password operations
- **External Commands**: Shell operations handled gracefully
- **File System**: Temporary directories for safe testing

### Edge Case Coverage
- Missing files and directories
- Invalid input validation
- Exception handling verification
- Boundary condition testing

### Security Testing
- File permission validation
- Password generation quality
- Input sanitization verification

### Performance Considerations
- Minimal external dependency calls
- Efficient temporary file cleanup
- Fast test execution patterns

## Running the Tests

### Individual Component Tests
```bash
# VaultManager tests
./vendor/bin/phpunit --testdox test/src/Nimbus/Vault/VaultManagerTest.php

# PasswordManager tests
./vendor/bin/phpunit --testdox test/src/Nimbus/Password/PasswordManagerTest.php

# AppManager tests
./vendor/bin/phpunit --testdox test/src/Nimbus/App/AppManagerTest.php
```

### All Nimbus Tests
```bash
./vendor/bin/phpunit --testdox test/src/Nimbus/
```

### With Coverage (requires Xdebug/PCOV)
```bash
./vendor/bin/phpunit --coverage-html coverage test/src/Nimbus/
```

## Test Maintenance

### Adding New Tests
1. Follow existing naming conventions: `test{Method}{Scenario}{ExpectedResult}`
2. Use descriptive test method names
3. Include both success and failure scenarios
4. Add proper docblock descriptions

### Mock Management
- Keep mocks minimal and focused
- Use `createMock()` for interface/class mocking
- Prefer anonymous classes for complex behavior overrides
- Validate mock interactions with `expects()` and `with()`

### Test Data
- Use temporary directories for file operations
- Create minimal test data structures
- Clean up all test artifacts in `tearDown()`
- Use unique identifiers to prevent test interference

## Integration Notes

These test suites integrate with the broader project testing framework:

- **PHPUnit Configuration**: Uses project-wide `phpunit.xml`
- **Autoloading**: Leverages Composer autoloader for test classes
- **CI/CD**: Compatible with automated testing pipelines
- **Code Coverage**: Supports coverage analysis tools

The tests provide comprehensive validation of the core Nimbus functionality while maintaining fast execution times and reliable, isolated test environments.