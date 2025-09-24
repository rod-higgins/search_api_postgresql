<?php

/**
 * @file
 * Integration test for Vector data type.
 */

// Create mock base class
if (!class_exists('Drupal\search_api\DataType\DataTypePluginBase')) {
  class DataTypePluginBase {
    protected $configuration;
    protected $pluginId;
    protected $pluginDefinition;

    public function __construct(array $configuration, $plugin_id, $plugin_definition) {
      $this->configuration = $configuration;
      $this->pluginId = $plugin_id;
      $this->pluginDefinition = $plugin_definition;
    }
  }
  class_alias('DataTypePluginBase', 'Drupal\search_api\DataType\DataTypePluginBase');
}

// Load the actual Vector data type
require_once __DIR__ . '/../src/Plugin/search_api/data_type/Vector.php';

// Create minimal test framework
class VectorDataTypeTest {
  protected $vector;

  public function setUp() {
    $this->vector = new Drupal\search_api_postgresql\Plugin\search_api\data_type\Vector([], 'vector', []);
  }

  public function testArrayInput() {
    echo "Testing array input... ";

    $input = [1, 2, 3, 4.5];
    $result = $this->vector->getValue($input);

    // Should convert all to floats
    $expected = [1.0, 2.0, 3.0, 4.5];

    if ($result === $expected) {
      echo "PASSED\n";
    } else {
      echo "FAILED - Expected: " . json_encode($expected) . ", Got: " . json_encode($result) . "\n";
    }
  }

  public function testStringInput() {
    echo "Testing string input... ";

    $input = "[1.0,2.0,3.0,4.5]";
    $result = $this->vector->getValue($input);

    $expected = [1.0, 2.0, 3.0, 4.5];

    if ($result === $expected) {
      echo "PASSED\n";
    } else {
      echo "FAILED - Expected: " . json_encode($expected) . ", Got: " . json_encode($result) . "\n";
    }
  }

  public function testCommaSeparatedInput() {
    echo "Testing comma separated input... ";

    $input = "1.0,2.0,3.0,4.5";
    $result = $this->vector->getValue($input);

    $expected = [1.0, 2.0, 3.0, 4.5];

    if ($result === $expected) {
      echo "PASSED\n";
    } else {
      echo "FAILED - Expected: " . json_encode($expected) . ", Got: " . json_encode($result) . "\n";
    }
  }

  public function testEmptyInput() {
    echo "Testing empty input... ";

    $inputs = [null, "", "[]", []];
    $allPassed = true;

    foreach ($inputs as $input) {
      $result = $this->vector->getValue($input);
      if ($result !== []) {
        echo "FAILED - Input: " . json_encode($input) . ", Expected: [], Got: " . json_encode($result) . "\n";
        $allPassed = false;
        break;
      }
    }

    if ($allPassed) {
      echo "PASSED\n";
    }
  }

  public function testIntegerConversion() {
    echo "Testing integer conversion... ";

    $input = [1, 2, 3];
    $result = $this->vector->getValue($input);

    // Should all be floats now
    $expected = [1.0, 2.0, 3.0];

    if ($result === $expected) {
      echo "PASSED\n";
    } else {
      echo "FAILED - Expected: " . json_encode($expected) . ", Got: " . json_encode($result) . "\n";
    }
  }

  public function testStringNumbers() {
    echo "Testing string numbers... ";

    $input = ["1.1", "2.2", "3.3"];
    $result = $this->vector->getValue($input);

    $expected = [1.1, 2.2, 3.3];

    if ($result === $expected) {
      echo "PASSED\n";
    } else {
      echo "FAILED - Expected: " . json_encode($expected) . ", Got: " . json_encode($result) . "\n";
    }
  }

  public function testMalformedString() {
    echo "Testing malformed string... ";

    $inputs = ["[1,2,3", "1,2,3]", "not-a-vector", "1,2,a,4"];
    $allPassed = true;

    foreach ($inputs as $input) {
      $result = $this->vector->getValue($input);
      // Should handle gracefully, either return empty array or valid conversion
      if (!is_array($result)) {
        echo "FAILED - Input: '$input' should return array, got: " . gettype($result) . "\n";
        $allPassed = false;
        break;
      }
    }

    if ($allPassed) {
      echo "PASSED\n";
    }
  }

  public function runAllTests() {
    echo "Vector Data Type Integration Tests\n";
    echo "==================================\n\n";

    $this->setUp();

    $this->testArrayInput();
    $this->testStringInput();
    $this->testCommaSeparatedInput();
    $this->testEmptyInput();
    $this->testIntegerConversion();
    $this->testStringNumbers();
    $this->testMalformedString();

    echo "\nAll tests completed.\n";
  }
}

$test = new VectorDataTypeTest();
$test->runAllTests();