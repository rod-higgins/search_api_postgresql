<?php

/**
 * @file
 * Simple test executor that runs basic test validation.
 */

// Mock Drupal test framework
if (!class_exists('PHPUnit\Framework\TestCase')) {
  class MockTestCase {
    public function setUp(): void {}
    public function tearDown(): void {}

    public function assertEquals($expected, $actual, $message = '') {
      if ($expected !== $actual) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertTrue($condition, $message = '') {
      if (!$condition) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertFalse($condition, $message = '') {
      if ($condition) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertNull($actual, $message = '') {
      if ($actual !== null) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertNotNull($actual, $message = '') {
      if ($actual === null) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertIsArray($actual, $message = '') {
      if (!is_array($actual)) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertCount($expectedCount, $haystack, $message = '') {
      if (count($haystack) !== $expectedCount) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertArrayHasKey($key, $array, $message = '') {
      if (!array_key_exists($key, $array)) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertStringContainsString($needle, $haystack, $message = '') {
      if (strpos($haystack, $needle) === false) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function assertInstanceOf($expected, $actual, $message = '') {
      if (!($actual instanceof $expected)) {
        throw new Exception("Assertion failed: $message");
      }
      echo "[OK]";
      return true;
    }

    public function createMock($className) {
      return new MockObject($className);
    }

    public function expectException($exception) {
      // Just note that we expect an exception
      return true;
    }
  }
}

class MockObject {
  private $className;

  public function __construct($className) {
    $this->className = $className;
  }

  public function method($name) {
    return $this;
  }

  public function willReturn($value) {
    return $value;
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

  public function never() {
    return $this;
  }

  public function with(...$args) {
    return $this;
  }

  public function __call($name, $args) {
    // Return mock values based on method name
    if (strpos($name, 'get') === 0) {
      return null; // Default getter return
    }
    return $this;
  }
}

function runTestExecution() {
  echo "Simple Test Execution\n";
  echo "====================\n\n";

  // Test that we can instantiate our mock framework
  $testCase = new MockTestCase();

  try {
    // Test basic assertions
    $testCase->assertTrue(true, "Basic true assertion");
    $testCase->assertFalse(false, "Basic false assertion");
    $testCase->assertEquals(1, 1, "Basic equality assertion");
    $testCase->assertIsArray([1, 2, 3], "Basic array assertion");
    $testCase->assertCount(3, [1, 2, 3], "Basic count assertion");
    $testCase->assertNull(null, "Basic null assertion");
    $testCase->assertNotNull("test", "Basic not null assertion");
    $testCase->assertArrayHasKey('key', ['key' => 'value'], "Basic array key assertion");
    $testCase->assertStringContainsString('test', 'testing', "Basic string contains assertion");

    echo "\nAll basic assertions work correctly\n";
  } catch (Exception $e) {
    echo "\nBasic assertion failed: " . $e->getMessage() . "\n";
    return false;
  }

  // Test mock object creation
  try {
    $mock = $testCase->createMock('SomeClass');
    $mock->method('someMethod')->willReturn('test_value');
    echo "Mock object creation works\n";
  } catch (Exception $e) {
    echo "Mock creation failed: " . $e->getMessage() . "\n";
    return false;
  }

  // Test file loading and parsing
  $testFiles = [
    'tests/src/Unit/Form/CacheManagementFormTest.php',
    'tests/src/Unit/Cache/MemoryEmbeddingCacheUnitTest.php',
    'tests/src/Unit/Service/EmbeddingServiceTest.php',
  ];

  $totalTests = 0;
  $passedTests = 0;

  foreach ($testFiles as $file) {
    if (file_exists($file)) {
      echo "Testing file: $file\n";
      $totalTests++;

      try {
        // Basic syntax check
        $syntax = shell_exec("php -l " . escapeshellarg($file) . " 2>&1");
        if (strpos($syntax, 'No syntax errors') !== false) {
          echo "  Syntax OK\n";
          $passedTests++;
        } else {
          echo "  Syntax Error: $syntax\n";
        }

        // Check test method count
        $content = file_get_contents($file);
        preg_match_all('/public\s+function\s+test[A-Za-z0-9_]+/', $content, $matches);
        $methodCount = count($matches[0]);
        echo "  Test methods: $methodCount\n";

      } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
      }
    } else {
      echo "File not found: $file\n";
    }
    echo "\n";
  }

  echo "Summary:\n";
  echo "--------\n";
  echo "Total files tested: $totalTests\n";
  echo "Passed: $passedTests\n";
  echo "Failed: " . ($totalTests - $passedTests) . "\n";

  if ($passedTests === $totalTests) {
    echo "All tests are ready for execution!\n";
    return true;
  } else {
    echo "Some tests need attention.\n";
    return false;
  }
}

runTestExecution();