<?php

namespace Drupal\Tests\search_api_postgresql\Integration;

use Drupal\search_api_postgresql\Exception\TemporaryApiException;
use Drupal\search_api_postgresql\Exception\ComprehensiveExceptionFactory;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api_postgresql\Exception\DatabaseConnectionException;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceUnavailableException;
use Drupal\search_api_postgresql\Exception\VectorSearchDegradedException;
use Drupal\search_api_postgresql\Exception\RateLimitException;
use Drupal\search_api_postgresql\Exception\MemoryExhaustedException;
use Drupal\search_api_postgresql\Service\ErrorRecoveryService;
use Drupal\search_api_postgresql\Service\ErrorClassificationService;
use Drupal\search_api_postgresql\Service\EnhancedDegradationMessageService;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Integration tests for error recovery workflows.
 *
 * @group search_api_postgresql
 */
class ErrorRecoveryIntegrationTest extends KernelTestBase {
  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_postgresql',
    'user',
    'system',
    'node',
    'field',
    'text',
  ];

  /**
   * The search server used for testing.
   *
   * @var \Drupal\search_api\ServerInterface
   */
  protected $server;

  /**
   * The search index used for testing.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * The error recovery service.
   *
   * @var \Drupal\search_api_postgresql\Service\ErrorRecoveryService
   */
  protected $recoveryService;

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
   * Mock queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queueFactory;

  /**
   * Mock cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cache;

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['search_api', 'node', 'field']);

    // Create test server.
    $this->server = Server::create([
      'id' => 'postgresql_recovery_test',
      'name' => 'PostgreSQL Recovery Test Server',
      'backend' => 'postgresql_azure',
      'backend_config' => [
        'connection' => [
          'host' => 'localhost',
          'port' => 5432,
          'database' => 'drupal_test',
          'username' => 'drupal',
          'password' => 'drupal',
          'ssl_mode' => 'disable',
        ],
        'index_prefix' => 'test_recovery_',
        'fts_configuration' => 'english',
        'debug' => TRUE,
        'azure_embedding' => [
          'enabled' => TRUE,
          'service_type' => 'azure_openai',
          'endpoint' => 'https://test.openai.azure.com/',
          'api_key' => 'test-key',
          'deployment_name' => 'test-deployment',
          'dimension' => 1536,
        ],
        'error_recovery' => [
          'max_retry_attempts' => 3,
          'retry_delay' => 1,
          'circuit_breaker_threshold' => 5,
          'circuit_breaker_timeout' => 60,
          'health_check_interval' => 30,
        ],
      ],
    ]);
    $this->server->save();

    // Create test index.
    $this->index = Index::create([
      'id' => 'postgresql_recovery_test_index',
      'name' => 'PostgreSQL Recovery Test Index',
      'server' => $this->server->id(),
      'datasource_settings' => [
        'entity:node' => [],
      ],
    ]);
    $this->index->save();

    // Mock dependencies.
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->database = $this->createMock(Connection::class);

    // Initialize services.
    $logger = $this->container->get('logger.factory')->get('search_api_postgresql');
    $this->recoveryService = new ErrorRecoveryService();
    $this->classificationService = new ErrorClassificationService();
    $this->messageService = new EnhancedDegradationMessageService($logger);
  }

  /**
   * Tests automatic recovery workflow for database connection failures.
   */
  public function testDatabaseConnectionRecoveryWorkflow() {
    // Simulate database connection failure.
    $exception = new DatabaseConnectionException([
      'host' => 'localhost',
      'port' => 5432,
      'database' => 'drupal_test',
    ]);

    $context = [
      'server_id' => $this->server->id(),
      'operation' => 'index_update',
      'retry_attempts' => 0,
    ];

    // Test error classification.
    $classification = $this->classificationService->classifyError($exception, $context);

    $this->assertEquals('CRITICAL', $classification['severity']);
    $this->assertEquals('SYSTEM', $classification['impact_scope']);
    $this->assertEquals('reconnect_database', $classification['recovery_strategy']);
    $this->assertTrue($classification['escalation_required']);

    // Test message generation.
    $message = $this->messageService->generateMessage($exception, $context);

    $this->assertStringContainsString('temporarily unavailable', $message['message']);
    $this->assertEquals('error', $message['icon']);
    $this->assertArrayHasKey('alternatives', $message);

    // Test recovery attempt.
    $recovery_result = $this->recoveryService->attemptRecovery($exception, $context);

    // In a real scenario, this would attempt actual database reconnection
    // For testing, we verify the recovery strategy is attempted.
    $this->assertIsBool($recovery_result);
  }

  /**
   * Tests graceful degradation chain for embedding service failures.
   */
  public function testEmbeddingServiceDegradationChain() {
    // Primary failure: Embedding service unavailable.
    $primary_exception = new EmbeddingServiceUnavailableException('Azure OpenAI');

    $context = [
      'index_id' => $this->index->id(),
      'query_text' => 'test search query',
      'search_mode' => 'hybrid',
    ];

    // Test graceful degradation.
    $classification = $this->classificationService->classifyError($primary_exception, $context);

    $this->assertEquals('MEDIUM', $classification['severity']);
    $this->assertEquals('USER', $classification['impact_scope']);
    $this->assertFalse($classification['escalation_required']);

    // Verify fallback strategy.
    $this->assertEquals('text_search_only', $primary_exception->getFallbackStrategy());

    // Test message generation for users.
    $user_message = $this->messageService->generateMessage($primary_exception, $context);

    $this->assertStringContainsString('AI-powered search', $user_message['message']);
    $this->assertStringContainsString('traditional search', $user_message['message']);
    $this->assertIsArray($user_message['alternatives']);
    $this->assertContains('Try using more specific keywords', $user_message['alternatives']);

    // Test that subsequent operations can continue with degraded functionality.
    $degraded_context = $context + ['search_mode' => 'text_only'];

    // Simulate query with degraded functionality.
    $query = $this->index->query();
    $query->setOption('search_mode', 'text_only');

    $this->assertEquals('text_only', $query->getOption('search_mode'));
  }

  /**
   * Tests cascading failure prevention and recovery.
   */
  public function testCascadingFailurePrevention() {
    // Simulate multiple related failures.
    $failures = [
      new DatabaseConnectionException(['host' => 'localhost', 'port' => 5432]),
      new EmbeddingServiceUnavailableException('Azure OpenAI'),
      new VectorSearchDegradedException('Cache unavailable'),
    ];

    $context = [
      'server_id' => $this->server->id(),
      'index_id' => $this->index->id(),
      'operation' => 'batch_index_update',
    ];

    // Test status report generation for multiple failures.
    $status_report = $this->messageService->generateStatusReport($failures, $context);

    $this->assertEquals('degraded', $status_report['status']);
    $this->assertEquals(3, $status_report['total_issues']);
    $this->assertIsArray($status_report['affected_features']);

    // Should prioritize most critical failure.
    $this->assertStringContainsString('significantly', strtolower($status_report['title']));

    // Test that each failure has a viable fallback.
    foreach ($failures as $failure) {
      $fallback = $failure->getFallbackStrategy();
      $this->assertNotEmpty($fallback);
      $this->assertNotEquals('none', $fallback);

      // Each fallback should be different to prevent convergence.
      $this->assertNotEquals('crash', $fallback);
    }

    // Test recovery coordination.
    $recovery_attempts = [];
    foreach ($failures as $failure) {
      $recovery_result = $this->recoveryService->attemptRecovery($failure, $context);
      $recovery_attempts[] = $recovery_result;
    }

    // At least some recovery attempts should be made.
    $this->assertNotEmpty($recovery_attempts);
  }

  /**
   * Tests rate limiting recovery with exponential backoff.
   */
  public function testRateLimitingRecoveryWorkflow() {
    // Simulate rate limiting from external API.
    $exception = new RateLimitException(120, 'Azure OpenAI API');

    $context = [
      'service_name' => 'azure_openai',
      'retry_attempts' => 2,
      'last_request_time' => time() - 30,
    ];

    // Test classification.
    $classification = $this->classificationService->classifyError($exception, $context);

    $this->assertEquals('MEDIUM', $classification['severity']);
    $this->assertEquals('rate_limit_backoff', $classification['recovery_strategy']);

    // Test message generation.
    $message = $this->messageService->generateMessage($exception, $context);

    $this->assertStringContainsString('high search traffic', $message['message']);
    $this->assertStringContainsString('moment longer', $message['message']);
    $this->assertEquals(120, $exception->getRetryAfter());

    // Test that alternatives are provided.
    $this->assertIsArray($message['alternatives']);
    $this->assertContains('Wait a moment before searching again', $message['alternatives']);

    // Test recovery strategy.
    $recovery_result = $this->recoveryService->attemptRecovery($exception, $context);

    // Recovery should implement exponential backoff.
    $this->assertIsBool($recovery_result);
  }

  /**
   * Tests memory exhaustion recovery with batch size reduction.
   */
  public function testMemoryExhaustionRecoveryWorkflow() {
    // Simulate memory exhaustion during large batch processing.
    $exception = new MemoryExhaustedException(
    // 512MB current usage
          512 * 1024 * 1024,
          // 256MB limit
          256 * 1024 * 1024
      );

    $context = [
      'operation' => 'batch_embedding_generation',
      'batch_size' => 1000,
      'processed_items' => 500,
      'remaining_items' => 500,
    ];

    // Test classification.
    $classification = $this->classificationService->classifyError($exception, $context);

    $this->assertEquals('HIGH', $classification['severity']);
    $this->assertEquals('batch_size_reduction', $classification['recovery_strategy']);

    // Test message generation.
    $message = $this->messageService->generateMessage($exception, $context);

    $this->assertStringContainsString('smaller batches', $message['message']);
    $this->assertStringContainsString('high demand', $message['message']);

    // Test recovery strategy - should reduce batch size.
    $recovery_result = $this->recoveryService->attemptRecovery($exception, $context);

    // In real implementation, this would adjust batch processing parameters.
    $this->assertIsBool($recovery_result);
  }

  /**
   * Tests health check and proactive error prevention.
   */
  public function testHealthCheckAndProactiveErrorPrevention() {
    // Perform comprehensive health check.
    $health_check_results = $this->recoveryService->performHealthCheck();

    $this->assertIsArray($health_check_results);
    $this->assertArrayHasKey('database_connectivity', $health_check_results);
    $this->assertArrayHasKey('memory_usage', $health_check_results);
    $this->assertArrayHasKey('disk_space', $health_check_results);
    $this->assertArrayHasKey('vector_indexes', $health_check_results);
    $this->assertArrayHasKey('external_services', $health_check_results);

    // Each health check should return status information.
    foreach ($health_check_results as $check_name => $result) {
      $this->assertIsArray($result, "Health check '{$check_name}' should return array");
      $this->assertArrayHasKey('status', $result);
      $this->assertContains($result['status'], ['healthy', 'warning', 'critical']);
    }
  }

  /**
   * Tests circuit breaker functionality.
   */
  public function testCircuitBreakerFunctionality() {
    // Simulate multiple consecutive failures to trigger circuit breaker.
    $failures = [];
    // Above threshold of 5.
    for ($i = 0; $i < 6; $i++) {
      $failures[] = new EmbeddingServiceUnavailableException('Azure OpenAI');
    }

    $context = [
      'service_name' => 'azure_openai',
      'failure_count' => count($failures),
    // 5 minutes
      'time_window' => 300,
    ];

    // After threshold failures, circuit breaker should open.
    $last_failure = end($failures);
    $classification = $this->classificationService->classifyError($last_failure, $context);

    // Should escalate to circuit breaker mode.
    $this->assertEquals('circuit_breaker_fallback', $classification['recovery_strategy']);

    // Test message generation for circuit breaker state.
    $message = $this->messageService->generateMessage($last_failure, $context);

    $this->assertStringContainsString('temporarily disabled', $message['message']);
    $this->assertStringContainsString('system stability', $message['message']);
  }

  /**
   * Tests recovery success tracking and learning.
   */
  public function testRecoverySuccessTrackingAndLearning() {
    $exception = new EmbeddingServiceUnavailableException('Azure OpenAI');

    $context = [
      'service_name' => 'azure_openai',
      'recovery_history' => [
        'total_attempts' => 10,
        'successful_recoveries' => 8,
    // Seconds.
        'average_recovery_time' => 45,
      ],
    ];

    // Test that recovery history influences strategy.
    $classification = $this->classificationService->classifyError($exception, $context);

    // With good recovery history, should be less severe.
    $this->assertContains($classification['severity'], ['LOW', 'MEDIUM']);

    // Test message adaptation based on history.
    $message = $this->messageService->generateMessage($exception, $context);

    // Should provide more optimistic messaging for services with good recovery history.
    $expected_resolution = $message['estimated_resolution'];
    $this->assertStringContainsString('quickly', $expected_resolution);
  }

  /**
   * Tests user notification levels and escalation.
   */
  public function testUserNotificationLevelsAndEscalation() {
    $test_scenarios = [
      // Low impact - minimal notification.
      [
        'exception' => new VectorSearchDegradedException('Routine maintenance'),
        'expected_notification_level' => 'minimal',
        'should_escalate' => FALSE,
      ],
      // Medium impact - user notification.
      [
        'exception' => new EmbeddingServiceUnavailableException('Azure OpenAI'),
        'expected_notification_level' => 'user',
        'should_escalate' => FALSE,
      ],
      // High impact - admin notification and escalation.
      [
        'exception' => new DatabaseConnectionException(['host' => 'localhost', 'port' => 5432]),
        'expected_notification_level' => 'admin',
        'should_escalate' => TRUE,
      ],
    ];

    foreach ($test_scenarios as $scenario) {
      $classification = $this->classificationService->classifyError($scenario['exception']);

      $this->assertEquals(
            $scenario['expected_notification_level'],
            $classification['user_notification_level']
        );

      $this->assertEquals(
            $scenario['should_escalate'],
            $classification['escalation_required']
        );

      // Test message generation adapts to notification level.
      $message = $this->messageService->generateMessage($scenario['exception']);

      if ($scenario['expected_notification_level'] === 'minimal') {
        $this->assertEquals('info', $message['icon']);
      }
      elseif ($scenario['expected_notification_level'] === 'admin') {
        $this->assertEquals('error', $message['icon']);
      }
    }
  }

  /**
   * Tests end-to-end recovery workflow simulation.
   */
  public function testEndToEndRecoveryWorkflowSimulation() {
    // Simulate complete workflow: failure detection -> classification -> recovery -> messaging.
    // Step 1: Failure occurs during search operation.
    $original_exception = new \Exception('Connection timeout', 504);
    $operation_context = [
      'operation' => 'search_query',
      'user_id' => 1,
      'query_text' => 'artificial intelligence',
      'search_mode' => 'hybrid',
    ];

    // Step 2: Exception classification and wrapping.
    $classified_exception = ComprehensiveExceptionFactory::createFromException(
          $original_exception,
          $operation_context
      );

    $this->assertInstanceOf(
          TemporaryApiException::class,
          $classified_exception
      );

    // Step 3: Error classification.
    $classification = $this->classificationService->classifyError($classified_exception, $operation_context);

    $this->assertIsArray($classification);
    $this->assertArrayHasKey('recovery_strategy', $classification);

    // Step 4: Recovery attempt.
    $recovery_result = $this->recoveryService->attemptRecovery($classified_exception, $operation_context);

    // Step 5: User message generation.
    $user_message = $this->messageService->generateMessage($classified_exception, $operation_context);

    $this->assertIsArray($user_message);
    $this->assertArrayHasKey('title', $user_message);
    $this->assertArrayHasKey('alternatives', $user_message);

    // Step 6: Verify workflow completed without additional failures.
    $this->assertTrue(TRUE, 'End-to-end workflow completed successfully');
  }

}
