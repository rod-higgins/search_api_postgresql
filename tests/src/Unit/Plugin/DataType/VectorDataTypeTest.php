<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Plugin\DataType;

use Drupal\search_api_postgresql\Plugin\search_api\data_type\Vector;
use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for the Vector data type plugin.
 *
 * @group search_api_postgresql
 */
class VectorDataTypeTest extends TestCase
{
  /**
   * The Vector data type plugin under test.
   *
   * @var \Drupal\search_api_postgresql\Plugin\search_api\data_type\Vector
   */
  protected $dataType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // Load the actual module files.
    require_once __DIR__ . '/../../../../../../../src/Plugin/search_api/data_type/Vector.php';

    $configuration = [];
    $plugin_id = 'vector';
    $plugin_definition = [
      'id' => 'vector',
      'label' => 'Vector',
      'description' => 'Vector data type for AI embeddings',
    ];

    $this->dataType = new Vector($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Tests vector value processing with array input.
   *
   * @covers ::getValue
   */
  public function testGetValueWithArray()
  {
    $vector = [1.0, 2.5, -3.7, 0.0];
    $result = $this->dataType->getValue($vector);

    $this->assertIsString($result);
    $this->assertStringContainsString('[', $result);
    $this->assertStringContainsString(']', $result);
  }

  /**
   * Tests vector value processing with string input.
   *
   * @covers ::getValue
   */
  public function testGetValueWithString()
  {
    $vector = '[1.0, 2.5, -3.7, 0.0]';
    $result = $this->dataType->getValue($vector);

    $this->assertIsString($result);
    $this->assertEquals('[1.0, 2.5, -3.7, 0.0]', $result);
  }

  /**
   * Tests vector value processing with comma-separated string.
   *
   * @covers ::getValue
   */
  public function testGetValueWithCommaSeparated()
  {
    $vector = '1.0,2.5,-3.7,0.0';
    $result = $this->dataType->getValue($vector);

    $this->assertIsString($result);
    $this->assertStringContainsString('[', $result);
    $this->assertStringContainsString(']', $result);
  }

  /**
   * Tests vector value processing with empty input.
   *
   * @covers ::getValue
   */
  public function testGetValueWithEmpty()
  {
    $result = $this->dataType->getValue('');
    $this->assertNull($result);

    $result = $this->dataType->getValue([]);
    $this->assertNull($result);

    $result = $this->dataType->getValue(null);
    $this->assertNull($result);
  }

  /**
   * Tests vector validation with valid input.
   *
   * @covers ::validateValue
   */
  public function testValidateValueValid()
  {
    $validVectors = [
      [1.0, 2.0, 3.0],
      '[1.0, 2.0, 3.0]',
      '1.0,2.0,3.0',
    ];

    foreach ($validVectors as $vector) {
      $this->assertTrue($this->dataType->validateValue($vector));
    }
  }

  /**
   * Tests vector validation with invalid input.
   *
   * @covers ::validateValue
   */
  public function testValidateValueInvalid()
  {
    $invalidVectors = [
      'not a vector',
      ['not', 'numeric', 'values'],
      [1, 2, 'three'],
    ];

    foreach ($invalidVectors as $vector) {
      $this->assertFalse($this->dataType->validateValue($vector));
    }
  }

  /**
   * Tests vector dimension validation.
   *
   * @covers ::validateDimensions
   */
  public function testValidateDimensions()
  {
    // Test valid dimensions.
    $validVector = array_fill(0, 1536, 1.0);
    $this->assertTrue($this->dataType->validateDimensions($validVector));

    // Test invalid dimensions (too large)
    $invalidVector = array_fill(0, 20000, 1.0);
    $this->assertFalse($this->dataType->validateDimensions($invalidVector));

    // Test empty vector.
    $this->assertFalse($this->dataType->validateDimensions([]));
  }

  /**
   * Tests vector normalization.
   *
   * @covers ::normalizeVector
   */
  public function testNormalizeVector()
  {
    $vector = [3.0, 4.0, 0.0];
    $normalized = $this->dataType->normalizeVector($vector);

    $this->assertIsArray($normalized);
    $this->assertCount(3, $normalized);

    // Check if vector is normalized (magnitude should be 1.0)
    $magnitude = sqrt(array_sum(array_map(function ($v) {
        return $v * $v;
    }, $normalized)));
    $this->assertEqualsWithDelta(1.0, $magnitude, 0.0001);
  }

  /**
   * Tests similarity calculation.
   *
   * @covers ::calculateSimilarity
   */
  public function testCalculateSimilarity()
  {
    $vector1 = [1.0, 0.0, 0.0];
    $vector2 = [1.0, 0.0, 0.0];
    $vector3 = [0.0, 1.0, 0.0];

    // Identical vectors should have similarity of 1.0.
    $similarity = $this->dataType->calculateSimilarity($vector1, $vector2);
    $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);

    // Orthogonal vectors should have similarity of 0.0.
    $similarity = $this->dataType->calculateSimilarity($vector1, $vector3);
    $this->assertEqualsWithDelta(0.0, $similarity, 0.0001);
  }

  /**
   * Tests data type fallback.
   *
   * @covers ::getFallbackType
   */
  public function testGetFallbackType()
  {
    $fallback = $this->dataType->getFallbackType();
    $this->assertEquals('string', $fallback);
  }

  /**
   * Tests data type default value.
   *
   * @covers ::getDefaultValue
   */
  public function testGetDefaultValue()
  {
    $default = $this->dataType->getDefaultValue();
    $this->assertNull($default);
  }
}
