<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Exception\ComprehensiveExceptionFactory;
use Drupal\search_api_postgresql\Exception\DatabaseConnectionException;
use Drupal\search_api_postgresql\Exception\MemoryExhaustedException;
use Drupal\search_api_postgresql\Exception\VectorIndexCorruptedException;
use Drupal\search_api_postgresql\Exception\ApiKeyExpiredException;
use Drupal\search_api_postgresql\Exception\InsufficientPermissionsException;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceUnavailableException;
use Drupal\search_api_postgresql\Exception\TemporaryApiException;
use Drupal\search_api_postgresql\Exception\RateLimitException;
use Drupal\search_api_postgresql\Exception\VectorSearchDegradedException;
use Drupal\search_api_postgresql\Service\ErrorClassificationService;
use Drupal\search_api_postgresql\Service\EnhancedDegradationMessageService;
use Psr\Log\LoggerInterface;

/**
 * Comprehensive tests for error handling system.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Exception\ComprehensiveExceptionFactory
 */
class ComprehensiveErrorHandlingTest extends UnitTestCase {

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The error classification service.
   *
   * @var \Drupal\search_api_postgresql\Service\ErrorClassificationService
   */
  protected $classificationService;

  /**
   * The message service.
   *
   * @var \Drupal\search_api_postgresql\Service\EnhancedDegradationMessageService
   */
  protected $messageService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = $this->createMock(LoggerInterface::class);
    $this->classificationService = new ErrorClassificationService();
    $this->messageService = new EnhancedDegradationMessageService($this->logger);
  }

  /**
   * Tests database connection exception creation and handling.
   *
   * @covers ::createFromException
   */
  public function testDatabaseConnectionException() {
    // Test connection refused scenario.
    $original_exception = new \Exception('Connection refused to host 127.0.0.1', 2002);
    $context = [
      'connection_params' => [
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'test_db',
      ],
    ];

    $exception = ComprehensiveExceptionFactory::createFromException($original_exception, $context);

    $this->assertInstanceOf(DatabaseConnectionException::class, $exception);
    $this->assertEquals('text_search_only', $exception->getFallbackStrategy());
    $this->assertTrue($exception->shouldLog());
    $this->assertStringContainsString('temporarily unavailable', $exception->getUserMessage());
  }

  /**
   * Tests memory exhaustion exception handling.
   *
   * @covers \Drupal\search_api_postgresql\Exception\MemoryExhaustedException
   */
  public function testMemoryExhaustionHandling() {
    // 512MB
    $memory_usage = 512 * 1024 * 1024;
    // 256MB (impossible scenario for testing)
    $memory_limit = 256 * 1024 * 1024;

    $exception = new MemoryExhaustedException($memory_usage, $memory_limit);

    $this->assertEquals('batch_size_reduction', $exception->getFallbackStrategy());
    $this->assertStringContainsString('smaller batches', $exception->getUserMessage());
    // HTTP 507 Insufficient Storage.
    $this->assertEquals(507, $exception->getCode());
  }

  /**
   * Tests vector index corruption handling.
   */
  public function testVectorIndexCorruptionHandling() {
    $exception = new VectorIndexCorruptedException('test_index');

    $this->assertEquals('text_search_with_reindex_queue', $exception->getFallbackStrategy());
    $this->assertStringContainsString('rebuilding', $exception->getUserMessage());
    $this->assertEquals(500, $exception->getCode());
  }

  /**
   * Tests API key expiration scenarios.
   */
  public function testApiKeyExpiredHandling() {
    $exception = new ApiKeyExpiredException('Azure OpenAI');

    $this->assertEquals('text_search_only', $exception->getFallbackStrategy());
    $this->assertStringContainsString('authentication issues', $exception->getUserMessage());
    $this->assertEquals(401, $exception->getCode());
  }

  /**
   * Tests insufficient permissions handling.
   */
  public function testInsufficientPermissionsHandling() {
    $exception = new InsufficientPermissionsException('vector_search_admin');

    $this->assertEquals('limited_functionality', $exception->getFallbackStrategy());
    $this->assertStringContainsString('administrator', $exception->getUserMessage());
    $this->assertEquals(403, $exception->getCode());
  }

  /**
   * Tests error classification accuracy.
   */
  public function testErrorClassification() {
    $test_cases = [
      // Database errors.
      [
        'exception' => new DatabaseConnectionException(['host' => 'localhost', 'port' => 5432]),
        'expected_severity' => 'CRITICAL',
        'expected_scope' => 'SYSTEM',
      ],
      // Memory errors.
      [
        'exception' => new MemoryExhaustedException(1000000, 500000),
        'expected_severity' => 'HIGH',
        'expected_scope' => 'SERVER',
      ],
      // Vector search degradation.
      [
        'exception' => new VectorSearchDegradedException('Index rebuild required'),
        'expected_severity' => 'MEDIUM',
        'expected_scope' => 'USER',
      ],
    ];

    foreach ($test_cases as $case) {
      $classification = $this->classificationService->classifyError($case['exception']);

      $this->assertEquals($case['expected_severity'], $classification['severity']);
      $this->assertEquals($case['expected_scope'], $classification['impact_scope']);
      $this->assertArrayHasKey('recovery_strategy', $classification);
      $this->assertArrayHasKey('user_notification_level', $classification);
      $this->assertArrayHasKey('escalation_required', $classification);
    }
  }

  /**
   * Tests exception factory pattern matching.
   */
  public function testExceptionFactoryPatternMatching() {
    $test_patterns = [
      // Network patterns.
      [
        'message' => 'cURL error 7: Failed to connect to api.openai.com',
        'expected_type' => EmbeddingServiceUnavailableException::class,
      ],
      [
        'message' => 'Connection refused by host',
        'expected_type' => DatabaseConnectionException::class,
      ],
      // Authentication patterns.
      [
        'message' => 'API key invalid or expired',
        'expected_type' => ApiKeyExpiredException::class,
      ],
      [
        'message' => 'Permission denied for operation',
        'expected_type' => InsufficientPermissionsException::class,
      ],
      // Resource patterns.
      [
        'message' => 'Fatal error: Allowed memory size exhausted',
        'expected_type' => MemoryExhaustedException::class,
      ],
      [
        'message' => 'Vector index corruption detected',
        'expected_type' => VectorIndexCorruptedException::class,
      ],
    ];

    foreach ($test_patterns as $pattern) {
      $original_exception = new \Exception($pattern['message']);
      $classified_exception = ComprehensiveExceptionFactory::createFromException($original_exception);

      $this->assertInstanceOf($pattern['expected_type'], $classified_exception);
    }
  }

  /**
   * Tests rate limiting scenarios.
   */
  public function testRateLimitingScenarios() {
    // Test 429 status code.
    $original_exception = new \Exception('Too Many Requests', 429);
    $context = ['retry_after' => 120, 'service_name' => 'Azure OpenAI'];

    $exception = ComprehensiveExceptionFactory::createFromException($original_exception, $context);

    $this->assertInstanceOf(RateLimitException::class, $exception);
    $this->assertEquals(120, $exception->getRetryAfter());
    $this->assertEquals('rate_limit_backoff', $exception->getFallbackStrategy());
  }

  /**
   * Tests temporary API failure handling.
   */
  public function testTemporaryApiFailureHandling() {
    $test_codes = [500, 502, 503, 504];

    foreach ($test_codes as $code) {
      $original_exception = new \Exception('Server Error', $code);
      $context = ['retry_attempts' => 2];

      $exception = ComprehensiveExceptionFactory::createFromException($original_exception, $context);

      $this->assertInstanceOf(TemporaryApiException::class, $exception);
      $this->assertEquals(2, $exception->getRetryAttempts());
      $this->assertEquals('retry_with_backoff', $exception->getFallbackStrategy());
    }
  }

  /**
   * Tests message generation for different degradation types.
   */
  public function testDegradationMessageGeneration() {
    $test_exceptions = [
      new EmbeddingServiceUnavailableException('Azure OpenAI'),
      new VectorSearchDegradedException('Index maintenance'),
      new RateLimitException(60, 'API Service'),
    ];

    foreach ($test_exceptions as $exception) {
      $message = $this->messageService->generateMessage($exception);

      $this->assertIsArray($message);
      $this->assertArrayHasKey('title', $message);
      $this->assertArrayHasKey('message', $message);
      $this->assertArrayHasKey('icon', $message);
      $this->assertArrayHasKey('action', $message);
      $this->assertArrayHasKey('estimated_resolution', $message);
      $this->assertArrayHasKey('alternatives', $message);

      // Ensure messages are user-friendly.
      $this->assertNotEmpty($message['title']);
      $this->assertNotEmpty($message['message']);
      $this->assertIsArray($message['alternatives']);
    }
  }

  /**
   * Tests contextual message enhancement.
   */
  public function testContextualMessageEnhancement() {
    $exception = new EmbeddingServiceUnavailableException('Test Service');

    // Test with admin context.
    $admin_context = [
      'user_role' => 'administrator',
      'show_technical_details' => TRUE,
    ];

    $admin_message = $this->messageService->generateMessage($exception, $admin_context);

    $this->assertArrayHasKey('technical_details', $admin_message);
    $this->assertArrayHasKey('admin_note', $admin_message);
    $this->assertEquals(get_class($exception), $admin_message['technical_details']['exception_type']);

    // Test with regular user context.
    $user_context = ['user_role' => 'authenticated'];
    $user_message = $this->messageService->generateMessage($exception, $user_context);

    $this->assertArrayNotHasKey('technical_details', $user_message);
    $this->assertArrayNotHasKey('admin_note', $user_message);
  }

  /**
   * Tests status report generation for multiple issues.
   */
  public function testStatusReportGeneration() {
    // Test healthy state.
    $healthy_report = $this->messageService->generateStatusReport([]);

    $this->assertEquals('healthy', $healthy_report['status']);
    $this->assertStringContainsString('operational', strtolower($healthy_report['title']));

    // Test multiple degradations.
    $exceptions = [
      new EmbeddingServiceUnavailableException('Azure OpenAI'),
      new VectorSearchDegradedException('Index maintenance'),
      new RateLimitException(60, 'API Service'),
    ];

    $degraded_report = $this->messageService->generateStatusReport($exceptions);

    $this->assertContains($degraded_report['status'], ['minor', 'partial', 'degraded']);
    $this->assertEquals(3, $degraded_report['total_issues']);
    $this->assertIsArray($degraded_report['affected_features']);
    $this->assertNotEmpty($degraded_report['estimated_resolution']);
  }

  /**
   * Tests error recovery strategies.
   */
  public function testErrorRecoveryStrategies() {
    $strategies = [
      'text_search_only' => EmbeddingServiceUnavailableException::class,
      'rate_limit_backoff' => RateLimitException::class,
      'batch_size_reduction' => MemoryExhaustedException::class,
      'text_search_with_reindex_queue' => VectorIndexCorruptedException::class,
    ];

    foreach ($strategies as $expected_strategy => $exception_class) {
      $reflection = new \ReflectionClass($exception_class);

      if ($exception_class === MemoryExhaustedException::class) {
        $exception = $reflection->newInstance(1000000, 500000);
      }
      elseif ($exception_class === VectorIndexCorruptedException::class) {
        $exception = $reflection->newInstance('test_index');
      }
      elseif ($exception_class === RateLimitException::class) {
        $exception = $reflection->newInstance(60, 'Test Service');
      }
      else {
        $exception = $reflection->newInstance('Test Service');
      }

      $this->assertEquals($expected_strategy, $exception->getFallbackStrategy());
    }
  }

  /**
   * Tests cascading failure prevention.
   */
  public function testCascadingFailurePrevention() {
    // Simulate a cascade of failures.
    $primary_failure = new DatabaseConnectionException(['host' => 'localhost', 'port' => 5432]);
    $secondary_failure = new EmbeddingServiceUnavailableException('Azure OpenAI');
    $tertiary_failure = new VectorSearchDegradedException('Cache unavailable');

    $exceptions = [$primary_failure, $secondary_failure, $tertiary_failure];
    $status_report = $this->messageService->generateStatusReport($exceptions);

    // Should prioritize the most critical failure.
    $this->assertEquals('degraded', $status_report['status']);

    // Should provide consolidated guidance.
    $this->assertIsString($status_report['message']);
    $this->assertStringContainsString('multiple', strtolower($status_report['message']));
  }

  /**
   * Tests graceful degradation chain.
   */
  public function testGracefulDegradationChain() {
    // Test that each exception provides a viable fallback.
    $chain_tests = [
      EmbeddingServiceUnavailableException::class => 'text_search_only',
      VectorSearchDegradedException::class => 'text_search_fallback',
      RateLimitException::class => 'rate_limit_backoff',
      MemoryExhaustedException::class => 'batch_size_reduction',
    ];

    foreach ($chain_tests as $exception_class => $expected_fallback) {
      $reflection = new \ReflectionClass($exception_class);

      if ($exception_class === MemoryExhaustedException::class) {
        $exception = $reflection->newInstance(1000000, 500000);
      }
      elseif ($exception_class === RateLimitException::class) {
        $exception = $reflection->newInstance(60, 'Test Service');
      }
      else {
        $exception = $reflection->newInstance('Test Service');
      }

      $this->assertEquals($expected_fallback, $exception->getFallbackStrategy());
      $this->assertNotEmpty($exception->getUserMessage());

      // Ensure fallback strategy is actionable.
      $this->assertNotEquals('none', $exception->getFallbackStrategy());
    }
  }

  /**
   * Tests exception logging behavior.
   */
  public function testExceptionLoggingBehavior() {
    $should_log_exceptions = [
      new DatabaseConnectionException(['host' => 'localhost', 'port' => 5432]),
      new MemoryExhaustedException(1000000, 500000),
      new ApiKeyExpiredException('Azure OpenAI'),
    ];

    $should_not_log_exceptions = [
      new VectorSearchDegradedException('Expected maintenance'),
    ];

    foreach ($should_log_exceptions as $exception) {
      $this->assertTrue($exception->shouldLog(), get_class($exception) . ' should be logged');
    }

    foreach ($should_not_log_exceptions as $exception) {
      $this->assertFalse($exception->shouldLog(), get_class($exception) . ' should not be logged');
    }
  }

  /**
   * Tests user message quality and consistency.
   */
  public function testUserMessageQuality() {
    $exceptions = [
      new EmbeddingServiceUnavailableException('Azure OpenAI'),
      new VectorSearchDegradedException('Index maintenance'),
      new RateLimitException(60, 'API Service'),
      new MemoryExhaustedException(1000000, 500000),
      new ApiKeyExpiredException('Azure OpenAI'),
    ];

    foreach ($exceptions as $exception) {
      $message = $exception->getUserMessage();

      // Messages should be user-friendly.
      $this->assertNotEmpty($message);
      $this->assertStringNotContainsString('Exception', $message);
      $this->assertStringNotContainsString('Error', $message);
      $this->assertStringNotContainsString('Fatal', $message);

      // Messages should be informative.
      $this->assertGreaterThan(20, strlen($message));

      // Messages should suggest alternatives or next steps.
      $this->assertTrue(
        strpos($message, 'temporarily') !== FALSE ||
        strpos($message, 'alternative') !== FALSE ||
        strpos($message, 'continue') !== FALSE ||
        strpos($message, 'available') !== FALSE
      );
    }
  }

  /**
   * Tests error context preservation.
   */
  public function testErrorContextPreservation() {
    $original_exception = new \Exception('Connection timeout', 1234);
    $context = [
      'operation' => 'embedding_generation',
      'item_id' => 'node:123',
      'retry_attempts' => 2,
      'service_name' => 'Azure OpenAI',
    ];

    $classified_exception = ComprehensiveExceptionFactory::createFromException($original_exception, $context);

    // Context should be preserved for debugging.
    $message_with_context = $this->messageService->generateMessage(
      $classified_exception,
      ['show_technical_details' => TRUE] + $context
    );

    $this->assertArrayHasKey('technical_details', $message_with_context);
    $this->assertArrayHasKey('context', $message_with_context['technical_details']);
    $this->assertEquals('embedding_generation', $message_with_context['technical_details']['context']['operation']);
  }

}
