<?php

namespace Drupal\search_api_postgresql\Exception;

use Drupal\search_api\SearchApiException;

/**
 * Base exception for Search API PostgreSQL operations.
 */
abstract class SearchApiPostgreSQLException extends SearchApiException {
  /**
   * The error severity level.
   *
   * @var string
   */
  protected $severity;

  /**
   * Whether the operation can be retried.
   *
   * @var bool
   */
  protected $retryable;

  /**
   * Fallback strategy to use.
   *
   * @var string
   */
  protected $fallbackStrategy;

  /**
   * Additional context data.
   *
   * @var array
   */
  protected $context;

  /**
   * Constructs a SearchApiPostgreSQLException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Throwable $previous
   *   The previous exception.
   * @param string $severity
   *   Error severity (critical, warning, notice).
   * @param bool $retryable
   *   Whether the operation can be retried.
   * @param string $fallback_strategy
   *   Fallback strategy to use.
   * @param array $context
   *   Additional context data.
   */
  public function __construct($message = '', $code = 0, ?\Throwable $previous = NULL, $severity = 'critical', $retryable = FALSE, $fallback_strategy = 'none', array $context = []) {
    parent::__construct($message, $code, $previous);
    $this->severity = $severity;
    $this->retryable = $retryable;
    $this->fallbackStrategy = $fallback_strategy;
    $this->context = $context;
  }

  /**
   * Gets the error severity.
   */
  public function getSeverity() {
    return $this->severity;
  }

  /**
   * Checks if the operation is retryable.
   */
  public function isRetryable() {
    return $this->retryable;
  }

  /**
   * Gets the fallback strategy.
   */
  public function getFallbackStrategy() {
    return $this->fallbackStrategy;
  }

  /**
   * Gets the context data.
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * Gets a user-friendly error message.
   */
  abstract public function getUserMessage();

}

/**
 * Exception for embedding service failures.
 */
class EmbeddingServiceException extends SearchApiPostgreSQLException {

  public function __construct($message = '', $code = 0, ?\Throwable $previous = NULL, $retryable = TRUE, array $context = []) {
    parent::__construct(
          $message,
          $code,
          $previous,
          'warning',
          $retryable,
          'skip_embeddings',
          $context
      );
  }

  /**
   * Gets the user-friendly message for the exception.
   */
  public function getUserMessage() {
    return $this->t('AI embeddings are temporarily unavailable. Search will continue using traditional text search.');
  }

  /**
   *
   */
  private function t($string) {
    return \Drupal::translation()->translate($string);
  }

}

/**
 * Exception for vector search failures.
 */
class VectorSearchException extends SearchApiPostgreSQLException {

  public function __construct($message = '', $code = 0, ?\Throwable $previous = NULL, array $context = []) {
    parent::__construct(
          $message,
          $code,
          $previous,
          'warning',
          FALSE,
          'fallback_to_text',
          $context
      );
  }

  /**
   *
   */
  public function getUserMessage() {
    return $this->t('Vector search is temporarily unavailable. Falling back to text search.');
  }

  /**
   *
   */
  private function t($string) {
    return \Drupal::translation()->translate($string);
  }

}

/**
 * Exception for database connection failures.
 */
class DatabaseConnectionException extends SearchApiPostgreSQLException {

  public function __construct($message = '', $code = 0, ?\Throwable $previous = NULL, $retryable = TRUE, array $context = []) {
    parent::__construct(
          $message,
          $code,
          $previous,
          'critical',
          $retryable,
          'cache_fallback',
          $context
      );
  }

  /**
   *
   */
  public function getUserMessage() {
    return $this->t('Database connection is temporarily unavailable. Some features may be limited.');
  }

  /**
   *
   */
  private function t($string) {
    return \Drupal::translation()->translate($string);
  }

}

/**
 * Exception for batch operation failures.
 */
class BatchOperationException extends SearchApiPostgreSQLException {
  /**
   * Partial results from the batch operation.
   *
   * @var array
   */
  protected $partialResults;

  /**
   * Failed items in the batch.
   *
   * @var array
   */
  protected $failedItems;

  public function __construct($message = '', array $partial_results = [], array $failed_items = [], $code = 0, ?\Throwable $previous = NULL, array $context = []) {
    $this->partialResults = $partial_results;
    $this->failedItems = $failed_items;

    parent::__construct(
          $message,
          $code,
          $previous,
          'warning',
          TRUE,
          'partial_success',
          $context
      );
  }

  /**
   *
   */
  public function getPartialResults() {
    return $this->partialResults;
  }

  /**
   *
   */
  public function getFailedItems() {
    return $this->failedItems;
  }

  /**
   *
   */
  public function getUserMessage() {
    $success_count = count($this->partialResults);
    $failed_count = count($this->failedItems);

    return $this->t('@success items processed successfully, @failed items failed and will be retried.', [
      '@success' => $success_count,
      '@failed' => $failed_count,
    ]);
  }

  /**
   *
   */
  private function t($string, array $args = []) {
    return \Drupal::translation()->translate($string, $args);
  }

}

/**
 * Exception for configuration validation failures.
 */
class ConfigurationException extends SearchApiPostgreSQLException {

  public function __construct($message = '', $code = 0, ?\Throwable $previous = NULL, array $context = []) {
    parent::__construct(
          $message,
          $code,
          $previous,
          'critical',
          FALSE,
          'disable_feature',
          $context
      );
  }

  /**
   *
   */
  public function getUserMessage() {
    return $this->t('Configuration error detected. Some advanced features have been disabled. Please check your server configuration.');
  }

  /**
   *
   */
  private function t($string) {
    return \Drupal::translation()->translate($string);
  }

}

/**
 * Exception for API rate limiting.
 */
class RateLimitException extends SearchApiPostgreSQLException {
  /**
   * Retry after seconds.
   *
   * @var int
   */
  protected $retryAfter;

  public function __construct($message = '', $retry_after = 60, $code = 0, ?\Throwable $previous = NULL, array $context = []) {
    $this->retryAfter = $retry_after;

    parent::__construct(
          $message,
          $code,
          $previous,
          'notice',
          TRUE,
          'delay_retry',
          $context
      );
  }

  /**
   *
   */
  public function getRetryAfter() {
    return $this->retryAfter;
  }

  /**
   *
   */
  public function getUserMessage() {
    return $this->t('API rate limit reached. Operations will resume shortly.');
  }

  /**
   *
   */
  private function t($string) {
    return \Drupal::translation()->translate($string);
  }

}

/**
 * Exception for cache failures.
 */
class CacheException extends SearchApiPostgreSQLException {

  public function __construct($message = '', $code = 0, ?\Throwable $previous = NULL, array $context = []) {
    parent::__construct(
          $message,
          $code,
          $previous,
          'notice',
          FALSE,
          'bypass_cache',
          $context
      );
  }

  /**
   *
   */
  public function getUserMessage() {
    return $this->t('Cache temporarily unavailable. Performance may be reduced.');
  }

  /**
   *
   */
  private function t($string) {
    return \Drupal::translation()->translate($string);
  }

}
