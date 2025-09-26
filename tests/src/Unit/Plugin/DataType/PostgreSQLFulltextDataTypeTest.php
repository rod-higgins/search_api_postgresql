<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Plugin\DataType;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Plugin\search_api\data_type\PostgreSQLFulltext;

/**
 * Tests for the PostgreSQL Fulltext data type plugin.
 *
 * @group              search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Plugin\search_api\data_type\PostgreSQLFulltext
 */
class PostgreSQLFulltextDataTypeTest extends UnitTestCase
{
  /**
   * The PostgreSQL Fulltext data type plugin under test.
   *
   * @var \Drupal\search_api_postgresql\Plugin\search_api\data_type\PostgreSQLFulltext
   */
  protected $dataType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $configuration = [];
    $plugin_id = 'postgresql_fulltext';
    $plugin_definition = [
      'id' => 'postgresql_fulltext',
      'label' => 'PostgreSQL Fulltext',
      'description' => 'PostgreSQL fulltext search data type',
    ];

    $this->dataType = new PostgreSQLFulltext($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Tests text processing for fulltext search.
   *
   * @covers ::getValue
   */
  public function testGetValueWithSimpleText()
  {
    $text = 'This is a simple test text.';
    $result = $this->dataType->getValue($text);

    $this->assertIsString($result);
    $this->assertNotEmpty($result);
  }

  /**
   * Tests text processing with HTML content.
   *
   * @covers ::getValue
   */
  public function testGetValueWithHtmlContent()
  {
    $html = '<p>This is <strong>HTML</strong> content with <em>formatting</em>.</p>';
    $result = $this->dataType->getValue($html);

    $this->assertIsString($result);
    $this->assertStringNotContainsString('<p>', $result);
    $this->assertStringNotContainsString('<strong>', $result);
    $this->assertStringContainsString('HTML', $result);
    $this->assertStringContainsString('formatting', $result);
  }

  /**
   * Tests text processing with special characters.
   *
   * @covers ::getValue
   */
  public function testGetValueWithSpecialCharacters()
  {
    $text = 'Text with special chars: &amp; &lt; &gt; "quotes" \'apostrophes\'';
    $result = $this->dataType->getValue($text);

    $this->assertIsString($result);
    $this->assertStringContainsString('&', $result);
    $this->assertStringContainsString('"', $result);
    $this->assertStringContainsString("'", $result);
  }

  /**
   * Tests text processing with empty input.
   *
   * @covers ::getValue
   */
  public function testGetValueWithEmpty()
  {
    $this->assertEmpty($this->dataType->getValue(''));
    $this->assertEmpty($this->dataType->getValue(null));
    $this->assertEmpty($this->dataType->getValue(0));
  }

  /**
   * Tests text processing with numeric input.
   *
   * @covers ::getValue
   */
  public function testGetValueWithNumeric()
  {
    $result = $this->dataType->getValue(12345);
    $this->assertEquals('12345', $result);

    $result = $this->dataType->getValue(123.45);
    $this->assertEquals('123.45', $result);
  }

  /**
   * Tests text processing with array input.
   *
   * @covers ::getValue
   */
  public function testGetValueWithArray()
  {
    $array = ['First item', 'Second item', 'Third item'];
    $result = $this->dataType->getValue($array);

    $this->assertIsString($result);
    $this->assertStringContainsString('First item', $result);
    $this->assertStringContainsString('Second item', $result);
    $this->assertStringContainsString('Third item', $result);
  }

  /**
   * Tests language configuration handling.
   *
   * @covers ::getLanguageConfiguration
   */
  public function testGetLanguageConfiguration()
  {
    $languages = ['english', 'spanish', 'french', 'german'];

    foreach ($languages as $language) {
      $config = $this->dataType->getLanguageConfiguration($language);
      $this->assertIsString($config);
      $this->assertNotEmpty($config);
    }
  }

  /**
   * Tests text search configuration validation.
   *
   * @covers ::validateSearchConfiguration
   */
  public function testValidateSearchConfiguration()
  {
    $validConfigs = ['english', 'simple', 'spanish'];
    $invalidConfigs = ['invalid_lang', 'nonexistent'];

    foreach ($validConfigs as $config) {
      $this->assertTrue($this->dataType->validateSearchConfiguration($config));
    }

    foreach ($invalidConfigs as $config) {
      $this->assertFalse($this->dataType->validateSearchConfiguration($config));
    }
  }

  /**
   * Tests text preprocessing.
   *
   * @covers ::preprocessText
   */
  public function testPreprocessText()
  {
    $text = '  This   has   multiple   spaces   ';
    $result = $this->dataType->preprocessText($text);

    $this->assertIsString($result);
    $this->assertEquals(trim($text), trim($result));
    $this->assertStringNotContainsString('   ', $result);
  }

  /**
   * Tests stemming configuration.
   *
   * @covers ::getStemmingConfiguration
   */
  public function testGetStemmingConfiguration()
  {
    $config = $this->dataType->getStemmingConfiguration();

    $this->assertIsArray($config);
    $this->assertArrayHasKey('enabled', $config);
    $this->assertArrayHasKey('language', $config);
  }

  /**
   * Tests weight configuration.
   *
   * @covers ::getWeightConfiguration
   */
  public function testGetWeightConfiguration()
  {
    $weights = ['A', 'B', 'C', 'D'];

    foreach ($weights as $weight) {
      $config = $this->dataType->getWeightConfiguration($weight);
      $this->assertIsString($config);
      $this->assertContains($weight, ['A', 'B', 'C', 'D']);
    }
  }

  /**
   * Tests data type fallback.
   *
   * @covers ::getFallbackType
   */
  public function testGetFallbackType()
  {
    $fallback = $this->dataType->getFallbackType();
    $this->assertEquals('text', $fallback);
  }
}
