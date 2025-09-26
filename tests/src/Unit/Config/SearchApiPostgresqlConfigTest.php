<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Config;

use Drupal\search_api_postgresql\Config\SearchApiPostgresqlConfig;
use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for SearchApiPostgresqlConfig.
 *
 * @group search_api_postgresql
 */
class SearchApiPostgresqlConfigTest extends TestCase
{
  /**
   * The configuration service under test.
   */
  protected $configService;

  /**
   * Mock config factory.
   */
  protected $configFactory;

  /**
   * Mock config object.
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // Load actual class.
    require_once __DIR__ . '/../../../../../../src/Config/SearchApiPostgresqlConfig.php';

    // Create mock config object.
    $this->config = new class {
      private $data = [
        'embedding_service' => 'azure_openai',
        'azure_endpoint' => 'https://test.openai.azure.com/',
        'api_key' => 'test_api_key',
        'deployment_name' => 'text-embedding-ada-002',
        'api_version' => '2024-02-01',
        'cache_enabled' => true,
        'cache_ttl' => 3600,
        'batch_size' => 50,
        'max_retries' => 3,
        'timeout' => 30,
        'queue_enabled' => true,
        'debug_mode' => false,
      ];

      /**
       * {@inheritdoc}
       */
      public function get($key)
      {
        return $this->data[$key] ?? null;
      }

      /**
       * {@inheritdoc}
       */
      public function set($key, $value)
      {
        $this->data[$key] = $value;
      }

    };

    // Create mock config factory.
    $this->configFactory = new class ($this->config) {
      private $config;

      public function __construct($config)
      {
        $this->config = $config;
      }

      /**
       * {@inheritdoc}
       */
      public function get($name)
      {
        return $this->config;
      }

      /**
       * {@inheritdoc}
       */
      public function getEditable($name)
      {
        return $this->config;
      }

    };

    // Create the service instance.
    $this->configService = new SearchApiPostgresqlConfig(
        $this->configFactory
    );
  }

  /**
   * Tests service instantiation.
   */
  public function testServiceInstantiation()
  {
    $this->assertInstanceOf(
        SearchApiPostgresqlConfig::class,
        $this->configService
    );
  }

  /**
   * Tests getting configuration values.
   */
  public function testGetConfigurationValues()
  {
    // Test existing values.
    $this->assertEquals('azure_openai', $this->configService->get('embedding_service'));
    $this->assertEquals('https://test.openai.azure.com/', $this->configService->get('azure_endpoint'));
    $this->assertEquals('test_api_key', $this->configService->get('api_key'));
    $this->assertEquals('text-embedding-ada-002', $this->configService->get('deployment_name'));
    $this->assertEquals('2024-02-01', $this->configService->get('api_version'));

    // Test boolean values.
    $this->assertTrue($this->configService->get('cache_enabled'));
    $this->assertTrue($this->configService->get('queue_enabled'));
    $this->assertFalse($this->configService->get('debug_mode'));

    // Test numeric values.
    $this->assertEquals(3600, $this->configService->get('cache_ttl'));
    $this->assertEquals(50, $this->configService->get('batch_size'));
    $this->assertEquals(3, $this->configService->get('max_retries'));
    $this->assertEquals(30, $this->configService->get('timeout'));
  }

  /**
   * Tests getting non-existent configuration values with defaults.
   */
  public function testGetNonExistentValuesWithDefaults()
  {
    // Test non-existent key with default.
    $this->assertEquals('default_value', $this->configService->get('non_existent_key', 'default_value'));
    $this->assertEquals(42, $this->configService->get('another_missing_key', 42));
    $this->assertTrue($this->configService->get('missing_boolean', true));
    $this->assertEquals([], $this->configService->get('missing_array', []));

    // Test non-existent key without default (should return null)
    $this->assertNull($this->configService->get('non_existent_key'));
  }

  /**
   * Tests checking if configuration keys exist.
   */
  public function testHasConfigurationKeys()
  {
    // Test existing keys.
    $this->assertTrue($this->configService->has('embedding_service'));
    $this->assertTrue($this->configService->has('azure_endpoint'));
    $this->assertTrue($this->configService->has('api_key'));
    $this->assertTrue($this->configService->has('cache_enabled'));
    $this->assertTrue($this->configService->has('debug_mode'));

    // Test non-existent keys.
    $this->assertFalse($this->configService->has('non_existent_key'));
    $this->assertFalse($this->configService->has('missing_setting'));
    $this->assertFalse($this->configService->has('undefined_option'));
  }

  /**
   * Tests getting the raw configuration object.
   */
  public function testGetConfigObject()
  {
    $configObject = $this->configService->getConfig();
    $this->assertNotNull($configObject);

    // Test that we can access configuration through the object.
    $this->assertEquals('azure_openai', $configObject->get('embedding_service'));
    $this->assertEquals('test_api_key', $configObject->get('api_key'));
    $this->assertTrue($configObject->get('cache_enabled'));
  }

  /**
   * Tests configuration value types.
   */
  public function testConfigurationValueTypes()
  {
    // String values.
    $stringConfigs = [
      'embedding_service',
      'azure_endpoint',
      'api_key',
      'deployment_name',
      'api_version',
    ];

    foreach ($stringConfigs as $key) {
      $value = $this->configService->get($key);
      $this->assertIsString($value, "Configuration key '{$key}' should be a string");
      $this->assertNotEmpty($value, "Configuration key '{$key}' should not be empty");
    }

    // Boolean values.
    $booleanConfigs = [
      'cache_enabled',
      'queue_enabled',
      'debug_mode',
    ];

    foreach ($booleanConfigs as $key) {
      $value = $this->configService->get($key);
      $this->assertIsBool($value, "Configuration key '{$key}' should be a boolean");
    }

    // Integer values.
    $integerConfigs = [
      'cache_ttl',
      'batch_size',
      'max_retries',
      'timeout',
    ];

    foreach ($integerConfigs as $key) {
      $value = $this->configService->get($key);
      $this->assertIsInt($value, "Configuration key '{$key}' should be an integer");
      $this->assertGreaterThan(0, $value, "Configuration key '{$key}' should be positive");
    }
  }

  /**
   * Tests Azure OpenAI configuration validation.
   */
  public function testAzureOpenAIConfiguration()
  {
    $endpoint = $this->configService->get('azure_endpoint');
    $apiKey = $this->configService->get('api_key');
    $deployment = $this->configService->get('deployment_name');
    $apiVersion = $this->configService->get('api_version');

    // Validate endpoint format.
    $this->assertStringStartsWith('https://', $endpoint);
    $this->assertStringContainsString('openai.azure.com', $endpoint);

    // Validate API key (should be non-empty string)
    $this->assertIsString($apiKey);
    $this->assertNotEmpty($apiKey);
    $this->assertGreaterThanOrEqual(16, strlen($apiKey));

    // Validate deployment name.
    $this->assertIsString($deployment);
    $this->assertNotEmpty($deployment);
    $this->assertStringContainsString('embedding', $deployment);

    // Validate API version format (YYYY-MM-DD or YYYY-MM-DD-preview)
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}(-preview)?$/', $apiVersion);
  }

  /**
   * Tests cache configuration validation.
   */
  public function testCacheConfiguration()
  {
    $cacheEnabled = $this->configService->get('cache_enabled');
    $cacheTtl = $this->configService->get('cache_ttl');

    $this->assertIsBool($cacheEnabled);

    if ($cacheEnabled) {
      $this->assertIsInt($cacheTtl);
      $this->assertGreaterThan(0, $cacheTtl);
      // Max 24 hours.
      $this->assertLessThanOrEqual(86400, $cacheTtl);
    }
  }

  /**
   * Tests queue configuration validation.
   */
  public function testQueueConfiguration()
  {
    $queueEnabled = $this->configService->get('queue_enabled');
    $batchSize = $this->configService->get('batch_size');

    $this->assertIsBool($queueEnabled);

    if ($queueEnabled) {
      $this->assertIsInt($batchSize);
      $this->assertGreaterThan(0, $batchSize);
      // Reasonable max batch size.
      $this->assertLessThanOrEqual(1000, $batchSize);
    }
  }

  /**
   * Tests retry and timeout configuration.
   */
  public function testRetryAndTimeoutConfiguration()
  {
    $maxRetries = $this->configService->get('max_retries');
    $timeout = $this->configService->get('timeout');

    // Validate retry configuration.
    $this->assertIsInt($maxRetries);
    $this->assertGreaterThanOrEqual(0, $maxRetries);
    // Reasonable max retries.
    $this->assertLessThanOrEqual(10, $maxRetries);

    // Validate timeout configuration.
    $this->assertIsInt($timeout);
    $this->assertGreaterThan(0, $timeout);
    // Max 5 minutes.
    $this->assertLessThanOrEqual(300, $timeout);
  }

  /**
   * Tests configuration defaults fallback.
   */
  public function testConfigurationDefaults()
  {
    $defaultValues = [
      'cache_ttl' => 3600,
      'batch_size' => 50,
      'max_retries' => 3,
      'timeout' => 30,
      'cache_enabled' => true,
      'queue_enabled' => true,
      'debug_mode' => false,
    ];

    foreach ($defaultValues as $key => $expectedDefault) {
      $value = $this->configService->get($key, $expectedDefault);
      $this->assertEquals(
          $expectedDefault,
          $value,
          "Default value for '{$key}' should be {$expectedDefault}"
      );
    }
  }

  /**
   * Tests configuration with empty/null values.
   */
  public function testEmptyAndNullValues()
  {
    // Set some values to null in the mock config.
    $this->config->set('nullable_setting', null);
    $this->config->set('empty_string', '');
    $this->config->set('zero_value', 0);
    $this->config->set('false_value', false);

    // Test null value with default.
    $this->assertEquals('fallback', $this->configService->get('nullable_setting', 'fallback'));

    // Test empty string (should return empty string, not default)
    $this->assertEquals('', $this->configService->get('empty_string', 'fallback'));

    // Test zero value (should return 0, not default)
    $this->assertEquals(0, $this->configService->get('zero_value', 99));

    // Test false value (should return false, not default)
    $this->assertFalse($this->configService->get('false_value', true));

    // Test has() method with these values.
    $this->assertFalse($this->configService->has('nullable_setting'));
    $this->assertTrue($this->configService->has('empty_string'));
    $this->assertTrue($this->configService->has('zero_value'));
    $this->assertTrue($this->configService->has('false_value'));
  }

  /**
   * Tests configuration service error handling.
   */
  public function testErrorHandling()
  {
    // Test with various invalid key types.
    $invalidKeys = [null, false, 0, [], new \stdClass()];

    foreach ($invalidKeys as $invalidKey) {
      // These should not throw exceptions but may return null.
      $result = $this->configService->get($invalidKey);
      $this->assertNull($result, "Invalid key should return null");

      $hasResult = $this->configService->has($invalidKey);
      $this->assertFalse($hasResult, "Invalid key should return false for has()");
    }
  }

  /**
   * Tests configuration validation helpers.
   */
  public function testConfigurationValidation()
  {
    // Test that all required Azure OpenAI settings are present.
    $requiredAzureSettings = [
      'azure_endpoint',
      'api_key',
      'deployment_name',
      'api_version',
    ];

    foreach ($requiredAzureSettings as $setting) {
      $this->assertTrue(
          $this->configService->has($setting),
          "Required Azure setting '{$setting}' should be configured"
      );

      $value = $this->configService->get($setting);
      $this->assertNotEmpty(
          $value,
          "Required Azure setting '{$setting}' should not be empty"
      );
    }
  }
}
