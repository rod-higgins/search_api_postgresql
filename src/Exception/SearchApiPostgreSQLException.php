<?php

namespace Drupal\search_api_postgresql\Exception;

use Drupal\search_api\SearchApiException;

/**
 * Base exception for Search API PostgreSQL operations.
 */
abstract class SearchApiPostgreSQLException extends SearchApiException
{
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
  public function __construct(
      $message = '',
      $code = 0,
      ?\Throwable $previous = null,
      $severity = 'critical',
      $retryable = false,
      $fallback_strategy = 'none',
      array $context = [],
  ) {
    parent::__construct($message, $code, $previous);
    $this->severity = $severity;
    $this->retryable = $retryable;
    $this->fallbackStrategy = $fallback_strategy;
    $this->context = $context;
  }

  /**
   * Gets the error severity.
   */
  public function getSeverity()
  {
    return $this->severity;
  }

  /**
   * Checks if the operation is retryable.
   */
  public function isRetryable()
  {
    return $this->retryable;
  }

  /**
   * Gets the fallback strategy.
   */
  public function getFallbackStrategy()
  {
    return $this->fallbackStrategy;
  }

  /**
   * Gets the context data.
   */
  public function getContext()
  {
    return $this->context;
  }

  /**
   * Gets a user-friendly error message.
   */
  abstract public function getUserMessage();
}
