<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Exception;

use Drupal\search_api_postgresql\Exception\SearchApiPostgreSQLException;
use Drupal\search_api_postgresql\Exception\CacheException;
use Drupal\search_api_postgresql\Exception\RateLimitException;
use Drupal\search_api_postgresql\Exception\ConfigurationException;
use Drupal\search_api_postgresql\Exception\BatchOperationException;
use Drupal\search_api_postgresql\Exception\DatabaseConnectionException;
use Drupal\search_api_postgresql\Exception\VectorSearchException;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceException;
use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for SearchApiPostgreSQLException classes.
 * {@inheritdoc}
 *
 * @group search_api_postgresql
 */
class SearchApiPostgreSQLExceptionTest extends TestCase
{
  /**
   * Exception classes under test.
   */
  protected $exceptions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // Load actual exception classes.
    require_once __DIR__ . '/../../../../../../src/Exception/SearchApiPostgreSQLException.php';

    // Define Drupal mock for translation.
    if (!function_exists('Drupal')) {
      eval('
      class Drupal {
        public static function translation() {
          return new class {
            public function translate($string, array $args = []) {
              return strtr($string, $args);
            }
          };
        }
      }
      ');
    }
  }

  /**
   * Tests base SearchApiPostgreSQLException functionality.
   */
  public function testBaseExceptionConstruction()
  {
    // Test EmbeddingServiceException (concrete implementation)
    $exception = new EmbeddingServiceException(
        'Test embedding error',
        123,
        null,
        false,
        ['api_key' => 'test']
    );

    $this->assertEquals('Test embedding error', $exception->getMessage());
    $this->assertEquals(123, $exception->getCode());
    $this->assertEquals('warning', $exception->getSeverity());
    $this->assertFalse($exception->isRetryable());
    $this->assertEquals('skip_embeddings', $exception->getFallbackStrategy());
    $this->assertEquals(['api_key' => 'test'], $exception->getContext());
  }

  /**
   * Tests EmbeddingServiceException specific functionality.
   */
  public function testEmbeddingServiceException()
  {
    $exception = new EmbeddingServiceException(
        'API key invalid',
        401,
        null,
        true,
        ['endpoint' => 'https://api.openai.com']
    );

    $this->assertEquals('API key invalid', $exception->getMessage());
    $this->assertEquals(401, $exception->getCode());
    $this->assertEquals('warning', $exception->getSeverity());
    $this->assertTrue($exception->isRetryable());
    $this->assertEquals('skip_embeddings', $exception->getFallbackStrategy());
    $this->assertEquals(['endpoint' => 'https://api.openai.com'], $exception->getContext());

    // Test user message.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    $this->assertNotEmpty($userMessage);
    $this->assertStringContainsString('unavailable', $userMessage);
  }

  /**
   * Tests VectorSearchException functionality.
   */
  public function testVectorSearchException()
  {
    $exception = new VectorSearchException(
        'pgvector extension missing',
        500,
        null,
        ['database' => 'search_db']
    );

    $this->assertEquals('pgvector extension missing', $exception->getMessage());
    $this->assertEquals(500, $exception->getCode());
    $this->assertEquals('warning', $exception->getSeverity());
    $this->assertFalse($exception->isRetryable());
    $this->assertEquals('fallback_to_text', $exception->getFallbackStrategy());
    $this->assertEquals(['database' => 'search_db'], $exception->getContext());

    // Test user message.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    $this->assertStringContainsString('Vector search', $userMessage);
    $this->assertStringContainsString('text search', $userMessage);
  }

  /**
   * Tests DatabaseConnectionException functionality.
   */
  public function testDatabaseConnectionException()
  {
    $exception = new DatabaseConnectionException(
        'Connection timeout',
        2002,
        null,
        true,
        ['host' => 'localhost', 'port' => 5432]
    );

    $this->assertEquals('Connection timeout', $exception->getMessage());
    $this->assertEquals(2002, $exception->getCode());
    $this->assertEquals('critical', $exception->getSeverity());
    $this->assertTrue($exception->isRetryable());
    $this->assertEquals('cache_fallback', $exception->getFallbackStrategy());
    $this->assertEquals(['host' => 'localhost', 'port' => 5432], $exception->getContext());

    // Test user message.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    $this->assertStringContainsString('Database connection', $userMessage);
    $this->assertStringContainsString('unavailable', $userMessage);
  }

  /**
   * Tests BatchOperationException functionality.
   */
  public function testBatchOperationException()
  {
    $partialResults = [
      ['id' => 1, 'status' => 'success'],
      ['id' => 2, 'status' => 'success'],
    ];
    $failedItems = [
      ['id' => 3, 'error' => 'timeout'],
      ['id' => 4, 'error' => 'invalid_data'],
    ];

    $exception = new BatchOperationException(
        'Batch processing partially failed',
        $partialResults,
        $failedItems,
        206,
        null,
        ['batch_id' => 'batch_001']
    );

    $this->assertEquals('Batch processing partially failed', $exception->getMessage());
    $this->assertEquals(206, $exception->getCode());
    $this->assertEquals('warning', $exception->getSeverity());
    $this->assertTrue($exception->isRetryable());
    $this->assertEquals('partial_success', $exception->getFallbackStrategy());
    $this->assertEquals(['batch_id' => 'batch_001'], $exception->getContext());

    // Test batch-specific methods.
    $this->assertEquals($partialResults, $exception->getPartialResults());
    $this->assertEquals($failedItems, $exception->getFailedItems());

    // Test user message with counts.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    // Success count.
    $this->assertStringContainsString('2', $userMessage);
    // Failed count.
    $this->assertStringContainsString('2', $userMessage);
  }

  /**
   * Tests ConfigurationException functionality.
   */
  public function testConfigurationException()
  {
    $exception = new ConfigurationException(
        'Invalid API configuration',
        422,
        null,
        ['config_key' => 'azure_endpoint']
    );

    $this->assertEquals('Invalid API configuration', $exception->getMessage());
    $this->assertEquals(422, $exception->getCode());
    $this->assertEquals('critical', $exception->getSeverity());
    $this->assertFalse($exception->isRetryable());
    $this->assertEquals('disable_feature', $exception->getFallbackStrategy());
    $this->assertEquals(['config_key' => 'azure_endpoint'], $exception->getContext());

    // Test user message.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    $this->assertStringContainsString('Configuration error', $userMessage);
    $this->assertStringContainsString('disabled', $userMessage);
  }

  /**
   * Tests RateLimitException functionality.
   */
  public function testRateLimitException()
  {
    $exception = new RateLimitException(
        'API rate limit exceeded',
        // Retry after 5 minutes.
          300,
        429,
        null,
        ['requests_remaining' => 0]
    );

    $this->assertEquals('API rate limit exceeded', $exception->getMessage());
    $this->assertEquals(429, $exception->getCode());
    $this->assertEquals('notice', $exception->getSeverity());
    $this->assertTrue($exception->isRetryable());
    $this->assertEquals('delay_retry', $exception->getFallbackStrategy());
    $this->assertEquals(['requests_remaining' => 0], $exception->getContext());

    // Test rate limit specific methods.
    $this->assertEquals(300, $exception->getRetryAfter());

    // Test user message.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    $this->assertStringContainsString('rate limit', $userMessage);
    $this->assertStringContainsString('shortly', $userMessage);
  }

  /**
   * Tests CacheException functionality.
   */
  public function testCacheException()
  {
    $exception = new CacheException(
        'Redis connection failed',
        503,
        null,
        ['cache_backend' => 'redis']
    );

    $this->assertEquals('Redis connection failed', $exception->getMessage());
    $this->assertEquals(503, $exception->getCode());
    $this->assertEquals('notice', $exception->getSeverity());
    $this->assertFalse($exception->isRetryable());
    $this->assertEquals('bypass_cache', $exception->getFallbackStrategy());
    $this->assertEquals(['cache_backend' => 'redis'], $exception->getContext());

    // Test user message.
    $userMessage = $exception->getUserMessage();
    $this->assertIsString($userMessage);
    $this->assertStringContainsString('Cache', $userMessage);
    $this->assertStringContainsString('Performance', $userMessage);
  }

  /**
   * Tests exception inheritance chain.
   */
  public function testExceptionInheritance()
  {
    $exception = new EmbeddingServiceException('test');

    $this->assertInstanceOf(SearchApiPostgreSQLException::class, $exception);
    $this->assertInstanceOf(\Throwable::class, $exception);
  }

  /**
   * Tests exception chaining with previous exceptions.
   */
  public function testExceptionChaining()
  {
    $originalException = new \Exception('Original error');
    $wrappedException = new DatabaseConnectionException(
        'Database error',
        0,
        $originalException
    );

    $this->assertEquals($originalException, $wrappedException->getPrevious());
    $this->assertEquals('Original error', $wrappedException->getPrevious()->getMessage());
  }

  /**
   * Tests exception context data handling.
   */
  public function testContextDataHandling()
  {
    $context = [
      'operation' => 'embedding_generation',
      'entity_id' => 123,
      'field_name' => 'body',
      'retry_count' => 2,
      'timestamp' => time(),
    ];

    $exception = new EmbeddingServiceException(
        'Context test',
        0,
        null,
        true,
        $context
    );

    $retrievedContext = $exception->getContext();
    $this->assertEquals($context, $retrievedContext);
    $this->assertEquals('embedding_generation', $retrievedContext['operation']);
    $this->assertEquals(123, $retrievedContext['entity_id']);
    $this->assertEquals(2, $retrievedContext['retry_count']);
  }

  /**
   * Tests severity level validation.
   */
  public function testSeverityLevels()
  {
    $severityTests = [
      ['class' => EmbeddingServiceException::class, 'expected' => 'warning'],
      ['class' => VectorSearchException::class, 'expected' => 'warning'],
      ['class' => DatabaseConnectionException::class, 'expected' => 'critical'],
      ['class' => ConfigurationException::class, 'expected' => 'critical'],
      ['class' => RateLimitException::class, 'expected' => 'notice'],
      ['class' => CacheException::class, 'expected' => 'notice'],
    ];

    foreach ($severityTests as $test) {
      if ($test['class'] === BatchOperationException::class) {
        $exception = new $test['class']('test', [], []);
      } else {
        $exception = new $test['class']('test');
      }

      $this->assertEquals(
          $test['expected'],
          $exception->getSeverity(),
          "Severity mismatch for {$test['class']}"
      );
    }
  }

  /**
   * Tests fallback strategy validation.
   */
  public function testFallbackStrategies()
  {
    $strategyTests = [
      ['class' => EmbeddingServiceException::class, 'expected' => 'skip_embeddings'],
      ['class' => VectorSearchException::class, 'expected' => 'fallback_to_text'],
      ['class' => DatabaseConnectionException::class, 'expected' => 'cache_fallback'],
      ['class' => ConfigurationException::class, 'expected' => 'disable_feature'],
      ['class' => RateLimitException::class, 'expected' => 'delay_retry'],
      ['class' => CacheException::class, 'expected' => 'bypass_cache'],
    ];

    foreach ($strategyTests as $test) {
      if ($test['class'] === BatchOperationException::class) {
        $exception = new $test['class']('test', [], []);
      } else {
        $exception = new $test['class']('test');
      }

      $this->assertEquals(
          $test['expected'],
          $exception->getFallbackStrategy(),
          "Fallback strategy mismatch for {$test['class']}"
      );
    }
  }

  /**
   * Tests retryable flag validation.
   */
  public function testRetryableFlags()
  {
    $retryableTests = [
      ['class' => EmbeddingServiceException::class, 'default' => true],
      ['class' => VectorSearchException::class, 'default' => false],
      ['class' => DatabaseConnectionException::class, 'default' => true],
      ['class' => ConfigurationException::class, 'default' => false],
      ['class' => RateLimitException::class, 'default' => true],
      ['class' => CacheException::class, 'default' => false],
    ];

    foreach ($retryableTests as $test) {
      if ($test['class'] === BatchOperationException::class) {
        $exception = new $test['class']('test', [], []);
      } else {
        $exception = new $test['class']('test');
      }

      $this->assertEquals(
          $test['default'],
          $exception->isRetryable(),
          "Retryable flag mismatch for {$test['class']}"
      );
    }
  }

  /**
   * Tests exception serialization for logging.
   */
  public function testExceptionSerialization()
  {
    $exception = new EmbeddingServiceException(
        'Serialization test',
        500,
        null,
        true,
        ['key' => 'value']
    );

    // Test that exception can be converted to string.
    $stringified = (string) $exception;
    $this->assertStringContainsString('Serialization test', $stringified);
    $this->assertStringContainsString('EmbeddingServiceException', $stringified);

    // Test that all properties are accessible for logging.
    $this->assertIsString($exception->getMessage());
    $this->assertIsInt($exception->getCode());
    $this->assertIsString($exception->getSeverity());
    $this->assertIsBool($exception->isRetryable());
    $this->assertIsString($exception->getFallbackStrategy());
    $this->assertIsArray($exception->getContext());
  }
}
