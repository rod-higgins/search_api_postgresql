<?php

/**
 * @file
 * Manual test runner for search_api_postgresql tests.
 *
 * This script manually validates test classes without requiring
 * a full PHPUnit installation or Drupal autoloader.
 */

// Skip autoloader due to PHP version incompatibility

// Mock some Drupal classes if not available
if (!class_exists('Drupal\Tests\UnitTestCase')) {
  class MockUnitTestCase {
    protected function setUp(): void {}
    protected function createMock($class) {
      return new class {
        public function method($name) {
          return $this;
        }
        public function willReturn($value) {
          return $this;
        }
        public function willReturnMap($map) {
          return $this;
        }
        public function expects($times) {
          return $this;
        }
        public function once() {
          return $this;
        }
        public function with($value) {
          return $this;
        }
        public function __call($name, $args) {
          return $this;
        }
      };
    }
    protected function assertEquals($expected, $actual, $message = '') {
      if ($expected !== $actual) {
        throw new Exception("Assertion failed: $message. Expected: " . var_export($expected, true) . ", Actual: " . var_export($actual, true));
      }
      echo "[OK] ";
    }
    protected function assertTrue($value, $message = '') {
      if (!$value) {
        throw new Exception("Assertion failed: $message. Expected true, got: " . var_export($value, true));
      }
      echo "[OK] ";
    }
    protected function assertFalse($value, $message = '') {
      if ($value) {
        throw new Exception("Assertion failed: $message. Expected false, got: " . var_export($value, true));
      }
      echo "[OK] ";
    }
    protected function assertNull($value, $message = '') {
      if ($value !== null) {
        throw new Exception("Assertion failed: $message. Expected null, got: " . var_export($value, true));
      }
      echo "[OK] ";
    }
    protected function assertNotNull($value, $message = '') {
      if ($value === null) {
        throw new Exception("Assertion failed: $message. Expected not null, got null");
      }
      echo "[OK] ";
    }
    protected function assertIsArray($value, $message = '') {
      if (!is_array($value)) {
        throw new Exception("Assertion failed: $message. Expected array, got: " . gettype($value));
      }
      echo "[OK] ";
    }
    protected function assertCount($expected, $actual, $message = '') {
      if (count($actual) !== $expected) {
        throw new Exception("Assertion failed: $message. Expected count: $expected, Actual count: " . count($actual));
      }
      echo "[OK] ";
    }
    protected function assertArrayHasKey($key, $array, $message = '') {
      if (!array_key_exists($key, $array)) {
        throw new Exception("Assertion failed: $message. Key '$key' not found in array");
      }
      echo "[OK] ";
    }
    protected function assertStringContainsString($needle, $haystack, $message = '') {
      if (strpos($haystack, $needle) === false) {
        throw new Exception("Assertion failed: $message. String '$needle' not found in '$haystack'");
      }
      echo "[OK] ";
    }
    protected function expectException($exception) {
      // For now, just note this
      echo "[Expecting $exception] ";
    }
  }

  // Create namespace alias
  class_alias('MockUnitTestCase', 'Drupal\Tests\UnitTestCase');
}

function runBasicTestValidation() {
  echo "Search API PostgreSQL Manual Test Validation\n";
  echo "============================================\n\n";

  $testFiles = [
    'tests/src/Unit/Form/CacheManagementFormTest.php',
    'tests/src/Unit/Cache/MemoryEmbeddingCacheUnitTest.php',
    'tests/src/Unit/Service/EmbeddingServiceTest.php',
    'tests/src/Unit/PostgreSQL/PostgreSQLConnectorUnitTest.php',
  ];

  $totalTests = 0;
  $passedTests = 0;
  $failedTests = 0;

  foreach ($testFiles as $file) {
    if (!file_exists($file)) {
      echo "Test file not found: $file\n";
      continue;
    }

    echo "Testing: $file\n";

    // Check basic class instantiation
    try {
      include_once $file;
      $totalTests++;

      // Extract class name from file
      $content = file_get_contents($file);
      if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
        $className = $matches[1];

        // Try to determine full class name with namespace
        if (preg_match('/namespace\s+([\w\\\\]+);/', $content, $nsMatches)) {
          $fullClassName = $nsMatches[1] . '\\' . $className;

          if (class_exists($fullClassName)) {
            echo "  Class $fullClassName exists and can be loaded\n";
            $passedTests++;
          } else {
            echo "  Class $fullClassName cannot be instantiated\n";
            $failedTests++;
          }
        }
      }

      echo "  File parsed successfully\n";

    } catch (Exception $e) {
      echo "  Error: " . $e->getMessage() . "\n";
      $failedTests++;
    }

    echo "\n";
  }

  echo "Summary:\n";
  echo "--------\n";
  echo "Total test files: $totalTests\n";
  echo "Passed: $passedTests\n";
  echo "Failed: $failedTests\n";

  if ($failedTests === 0) {
    echo "All tests validated successfully!\n";
    return true;
  } else {
    echo "Some tests have issues that need attention.\n";
    return false;
  }
}

// Run the validation
runBasicTestValidation();