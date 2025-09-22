<?php

namespace Drupal\search_api_postgresql\Exception;

use Drupal\search_api\SearchApiException;

/**
 * Base exception for recoverable errors that allow graceful degradation.
 */
abstract class GracefulDegradationException extends SearchApiException {

  /**
   * The fallback strategy to use.
   *
   * @var string
   */
  protected $fallbackStrategy;

  /**
   * User-friendly message for display.
   *
   * @var string
   */
  protected $userMessage;

  /**
   * Whether this error should be logged.
   *
   * @var bool
   */
  protected $shouldLog = TRUE;

  /**
   * Gets the fallback strategy.
   *
   * @return string
   *   The fallback strategy identifier.
   */
  public function getFallbackStrategy() {
    return $this->fallbackStrategy;
  }

  /**
   * Gets the user-friendly message.
   *
   * @return string
   *   The user message.
   */
  public function getUserMessage() {
    return $this->userMessage;
  }

  /**
   * Whether this exception should be logged.
   *
   * @return bool
   *   TRUE if should be logged.
   */
  public function shouldLog() {
    return $this->shouldLog;
  }

}

/**
 * Exception thrown when embedding service is unavailable.
 */
class EmbeddingServiceUnavailableException extends GracefulDegradationException {

  /**
   * Constructs an EmbeddingServiceUnavailableException.
   *
   * @param string $service_name
   *   The service name.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($service_name = 'Embedding service', ?\Exception $previous = NULL) {
    $message = "Embedding service '{$service_name}' is currently unavailable";
    $this->userMessage = 'AI-powered search is temporarily unavailable. Using traditional search instead.';
    $this->fallbackStrategy = 'text_search_only';
    parent::__construct($message, 503, $previous);
  }

}

/**
 * Exception for temporary API failures that should retry.
 */
class TemporaryApiException extends GracefulDegradationException {

  /**
   * Number of retry attempts.
   *
   * @var int
   */
  protected $retryAttempts = 0;

  /**
   * Constructs a TemporaryApiException.
   *
   * @param string $message
   *   The error message.
   * @param int $retry_attempts
   *   Number of retry attempts made.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($message, $retry_attempts = 0, ?\Exception $previous = NULL) {
    $this->retryAttempts = $retry_attempts;
    $this->userMessage = 'Search service is experiencing high load. Some features may be limited.';
    $this->fallbackStrategy = 'retry_with_backoff';
    parent::__construct($message, 429, $previous);
  }

  /**
   * Gets the number of retry attempts.
   *
   * @return int
   *   The retry attempts.
   */
  public function getRetryAttempts() {
    return $this->retryAttempts;
  }

}

/**
 * Exception for partial failures in batch operations.
 */
class PartialBatchFailureException extends GracefulDegradationException {

  /**
   * Successful items.
   *
   * @var array
   */
  protected $successfulItems = [];

  /**
   * Failed items with their errors.
   *
   * @var array
   */
  protected $failedItems = [];

  /**
   * Constructs a PartialBatchFailureException.
   *
   * @param array $successful_items
   *   Items that were processed successfully.
   * @param array $failed_items
   *   Items that failed processing.
   * @param string $operation
   *   The operation that partially failed.
   */
  public function __construct(array $successful_items, array $failed_items, $operation = 'batch operation') {
    $this->successfulItems = $successful_items;
    $this->failedItems = $failed_items;

    $total = count($successful_items) + count($failed_items);
    $success_count = count($successful_items);
    $failure_count = count($failed_items);

    $message = "Partial failure in {$operation}: {$success_count}/{$total} items succeeded";
    $this->userMessage = "Some content may not be fully searchable due to processing issues. Search functionality remains available.";
    $this->fallbackStrategy = 'continue_with_partial_results';
    // Only log if >50% failed.
    $this->shouldLog = $failure_count > ($total * 0.5);

    // 206 Partial Content
    parent::__construct($message, 206);
  }

  /**
   * Gets the successful items.
   *
   * @return array
   *   The successful items.
   */
  public function getSuccessfulItems() {
    return $this->successfulItems;
  }

  /**
   * Gets the failed items.
   *
   * @return array
   *   The failed items with their errors.
   */
  public function getFailedItems() {
    return $this->failedItems;
  }

  /**
   * Gets the success rate.
   *
   * @return float
   *   Success rate as a percentage.
   */
  public function getSuccessRate() {
    $total = count($this->successfulItems) + count($this->failedItems);
    return $total > 0 ? (count($this->successfulItems) / $total) * 100 : 0;
  }

}

/**
 * Exception for vector search failures that can fall back to text search.
 */
class VectorSearchDegradedException extends GracefulDegradationException {

  /**
   * The degradation reason.
   *
   * @var string
   */
  protected $degradationReason;

  /**
   * Constructs a VectorSearchDegradedException.
   *
   * @param string $reason
   *   The reason for degradation.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($reason = 'Vector search unavailable', ?\Exception $previous = NULL) {
    $this->degradationReason = $reason;
    $this->userMessage = 'Using traditional text search. Some semantic matching may be limited.';
    $this->fallbackStrategy = 'text_search_fallback';
    // This is expected behavior, not an error.
    $this->shouldLog = FALSE;

    parent::__construct("Vector search degraded: {$reason}", 200, $previous);
  }

  /**
   * Gets the degradation reason.
   *
   * @return string
   *   The degradation reason.
   */
  public function getDegradationReason() {
    return $this->degradationReason;
  }

}

/**
 * Exception for configuration issues that allow partial functionality.
 */
class ConfigurationDegradedException extends GracefulDegradationException {

  /**
   * Available features despite configuration issues.
   *
   * @var array
   */
  protected $availableFeatures = [];

  /**
   * Constructs a ConfigurationDegradedException.
   *
   * @param string $config_issue
   *   Description of the configuration issue.
   * @param array $available_features
   *   Features that remain available.
   */
  public function __construct($config_issue, array $available_features = []) {
    $this->availableFeatures = $available_features;
    $this->userMessage = 'Some advanced search features are unavailable due to configuration. Basic search remains functional.';
    $this->fallbackStrategy = 'basic_functionality_only';

    parent::__construct("Configuration issue: {$config_issue}", 200);
  }

  /**
   * Gets the available features.
   *
   * @return array
   *   Available features.
   */
  public function getAvailableFeatures() {
    return $this->availableFeatures;
  }

}

/**
 * Exception for queue processing issues that don't affect immediate operations.
 */
class QueueDegradedException extends GracefulDegradationException {

  /**
   * The queue operation that failed.
   *
   * @var string
   */
  protected $queueOperation;

  /**
   * Constructs a QueueDegradedException.
   *
   * @param string $operation
   *   The queue operation.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($operation = 'background processing', ?\Exception $previous = NULL) {
    $this->queueOperation = $operation;
    $this->userMessage = 'Background processing is delayed. Search results are still available but may not include the latest content updates.';
    $this->fallbackStrategy = 'synchronous_processing';

    parent::__construct("Queue operation degraded: {$operation}", 200, $previous);
  }

  /**
   * Gets the queue operation.
   *
   * @return string
   *   The queue operation.
   */
  public function getQueueOperation() {
    return $this->queueOperation;
  }

}

/**
 * Exception for cache failures that don't affect core functionality.
 */
class CacheDegradedException extends GracefulDegradationException {

  /**
   * Constructs a CacheDegradedException.
   *
   * @param string $cache_type
   *   The type of cache that failed.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($cache_type = 'embedding cache', ?\Exception $previous = NULL) {
    $this->userMessage = 'Search performance may be slower due to caching issues.';
    $this->fallbackStrategy = 'direct_processing';
    // Performance issue, not critical.
    $this->shouldLog = FALSE;

    parent::__construct("Cache degraded: {$cache_type}", 200, $previous);
  }

}

/**
 * Exception for rate limiting that suggests backoff strategies.
 */
class RateLimitException extends GracefulDegradationException {

  /**
   * Retry after seconds.
   *
   * @var int
   */
  protected $retryAfter;

  /**
   * Constructs a RateLimitException.
   *
   * @param int $retry_after
   *   Seconds to wait before retry.
   * @param string $service
   *   The service that is rate limited.
   */
  public function __construct($retry_after = 60, $service = 'API service') {
    $this->retryAfter = $retry_after;
    $this->userMessage = 'Search service is busy. Results may be limited temporarily.';
    $this->fallbackStrategy = 'rate_limit_backoff';

    parent::__construct("Rate limited by {$service}, retry after {$retry_after} seconds", 429);
  }

  /**
   * Gets the retry after time.
   *
   * @return int
   *   Seconds to wait.
   */
  public function getRetryAfter() {
    return $this->retryAfter;
  }

}

/**
 * Exception for circuit breaker open state.
 */
class CircuitBreakerException extends GracefulDegradationException {

  /**
   * The service name.
   *
   * @var string
   */
  protected $serviceName;

  /**
   * Constructs a CircuitBreakerException.
   *
   * @param string $service_name
   *   The service name.
   */
  public function __construct($service_name) {
    $this->serviceName = $service_name;
    $this->userMessage = 'Some search features are temporarily unavailable. Basic search functionality continues to work.';
    $this->fallbackStrategy = 'circuit_breaker_fallback';

    parent::__construct("Circuit breaker open for service: {$service_name}", 503);
  }

  /**
   * Gets the service name.
   *
   * @return string
   *   The service name.
   */
  public function getServiceName() {
    return $this->serviceName;
  }

}

/**
 * Factory class for creating appropriate degradation exceptions.
 */
class DegradationExceptionFactory {

  /**
   * Creates an appropriate exception based on the error context.
   *
   * @param \Exception $original_exception
   *   The original exception.
   * @param array $context
   *   Error context.
   *
   * @return \Drupal\search_api_postgresql\Exception\GracefulDegradationException
   *   The appropriate degradation exception.
   */
  public static function createFromException(\Exception $original_exception, array $context = []) {
    $message = $original_exception->getMessage();
    $code = $original_exception->getCode();

    // Network/connectivity issues.
    if (strpos($message, 'cURL error') !== FALSE || strpos($message, 'Connection refused') !== FALSE) {
      return new EmbeddingServiceUnavailableException($context['service_name'] ?? 'External service', $original_exception);
    }

    // Rate limiting.
    if ($code === 429 || strpos($message, 'rate limit') !== FALSE) {
      $retry_after = $context['retry_after'] ?? 60;
      return new RateLimitException($retry_after, $context['service_name'] ?? 'API service');
    }

    // Temporary API issues.
    if (in_array($code, [500, 502, 503, 504])) {
      return new TemporaryApiException($message, $context['retry_attempts'] ?? 0, $original_exception);
    }

    // Vector/embedding specific issues.
    if (strpos($message, 'vector') !== FALSE || strpos($message, 'embedding') !== FALSE) {
      return new VectorSearchDegradedException($message, $original_exception);
    }

    // Queue issues.
    if (strpos($message, 'queue') !== FALSE) {
      return new QueueDegradedException($context['operation'] ?? 'background processing', $original_exception);
    }

    // Cache issues.
    if (strpos($message, 'cache') !== FALSE) {
      return new CacheDegradedException($context['cache_type'] ?? 'embedding cache', $original_exception);
    }

    // Default to service unavailable.
    return new EmbeddingServiceUnavailableException($context['service_name'] ?? 'Service', $original_exception);
  }

  /**
   * Creates a partial batch failure exception.
   *
   * @param array $successful_items
   *   Successful items.
   * @param array $failed_items
   *   Failed items.
   * @param string $operation
   *   The operation name.
   *
   * @return \Drupal\search_api_postgresql\Exception\PartialBatchFailureException
   *   The partial failure exception.
   */
  public static function createPartialBatchFailure(array $successful_items, array $failed_items, $operation = 'batch operation') {
    return new PartialBatchFailureException($successful_items, $failed_items, $operation);
  }

}
