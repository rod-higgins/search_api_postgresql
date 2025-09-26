<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Exception for temporary API failures that should retry.
 */
class TemporaryApiException extends GracefulDegradationException
{
  /**
   * Number of retry attempts.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $retryAttempts = 0;

  /**
   * Constructs a TemporaryApiException.
   * {@inheritdoc}
   *
   * @param string $message
   *   The error message.
   * @param int $retry_attempts
   *   Number of retry attempts made.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($message, $retry_attempts = 0, ?\Exception $previous = null)
  {
    $this->retryAttempts = $retry_attempts;
    $this->userMessage = 'Search service is experiencing high load. Some features may be limited.';
    $this->fallbackStrategy = 'retry_with_backoff';
    parent::__construct($message, 429, $previous);
  }

  /**
   * Gets the number of retry attempts.
   * {@inheritdoc}
   *
   * @return int
   *   The retry attempts.
   */
  public function getRetryAttempts()
  {
    return $this->retryAttempts;
  }
}
