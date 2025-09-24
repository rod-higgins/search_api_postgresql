# Testing Guide

## Test Structure

The search_api_postgresql module includes comprehensive tests organized into three categories:

### Unit Tests (`tests/src/Unit/`)
- **AzureEmbeddingServiceTest.php** - 18 test methods for Azure OpenAI integration
- **ComprehensiveErrorHandlingTest.php** - 18 test methods for error handling system
- **DatabaseEmbeddingCacheTest.php** - 14 test methods for database caching
- **MemoryEmbeddingCacheTest.php** - 16 test methods for memory caching
- **PostgreSQLConnectorTest.php** - 2 test methods for database connectivity
- **VectorIndexManagerTest.php** - 23 test methods for vector index management

### Kernel Tests (`tests/src/Kernel/`)
- **PostgreSQLBackendTest.php** - 3 test methods for backend functionality
- **VectorSearchTest.php** - 7 test methods for vector search features

### Integration Tests (`tests/src/Integration/`)
- **ErrorRecoveryIntegrationTest.php** - 10 test methods for error recovery workflows

## Test Coverage

**Total: 111 test methods across 9 test files**

### Key Testing Areas
- Database connection and querying
- Vector index creation and management
- Embedding generation and caching
- Error handling and recovery
- Search API backend integration
- AI service integration
- Performance and optimization
- Configuration validation

## Running Tests

### Using DDEV (Recommended)
```bash
# Start DDEV environment
ddev start

# Run all tests
ddev exec "cd /var/www/html && ./vendor/bin/phpunit --group search_api_postgresql"

# Run specific test suite
ddev exec "cd /var/www/html && ./vendor/bin/phpunit tests/modules/contrib/search_api_postgresql/tests/src/Unit"
```

### Using Local PHP
```bash
# Validate test syntax
php scripts/test-runner.php

# Comprehensive test analysis
php scripts/test-check.php
```

### Test Configuration

The module includes:
- **phpunit.xml** - PHPUnit configuration file
- **tests/bootstrap.php** - Test bootstrap file
- **scripts/test-runner.php** - Syntax validation script
- **scripts/test-check.php** - Comprehensive test analysis

## Test Quality

All tests have been validated for:
- Proper syntax and structure
- Correct namespacing
- Appropriate test method naming
- @group annotations
- Assertion usage
- Mock object patterns

## Writing New Tests

### Unit Test Template
```php
<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests for YourClass.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\YourClass
 */
class YourClassTest extends UnitTestCase {

  /**
   * Tests your functionality.
   */
  public function testYourFunctionality() {
    // Your test code here
    $this->assertTrue(TRUE);
  }
}
```

### Kernel Test Template
```php
<?php

namespace Drupal\Tests\search_api_postgresql\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests for your integration.
 *
 * @group search_api_postgresql
 */
class YourIntegrationTest extends KernelTestBase {

  protected static $modules = [
    'search_api',
    'search_api_postgresql',
  ];

  /**
   * Tests integration functionality.
   */
  public function testIntegration() {
    // Your integration test code here
    $this->assertTrue(TRUE);
  }
}
```

## CI/CD Integration

Tests are ready for continuous integration with:
- Proper exit codes for success/failure
- Comprehensive coverage of all major functionality
- Performance testing capabilities
- Error scenario testing

## Debugging Tests

### Common Issues
- **Database connection**: Ensure PostgreSQL with pgvector is available
- **Missing dependencies**: Check that all required modules are enabled
- **Mock objects**: Verify that mocked services match actual interfaces

### Debug Commands
```bash
# Check test syntax
find tests -name "*.php" -exec php -l {} \;

# Run single test class
ddev exec "./vendor/bin/phpunit tests/modules/contrib/search_api_postgresql/tests/src/Unit/DatabaseEmbeddingCacheTest.php"

# Run with verbose output
ddev exec "./vendor/bin/phpunit --verbose --group search_api_postgresql"
```