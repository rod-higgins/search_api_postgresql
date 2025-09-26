<?php

namespace Drupal\search_api_postgresql\Exception;

use Drupal\search_api\SearchApiException;

/**
 * Base exception for recoverable errors that allow graceful degradation.
 */
abstract class GracefulDegradationException extends SearchApiException
{
  /**
   * The fallback strategy to use.
   * {@inheritdoc}
   *
   * @var string
   */
  protected $fallbackStrategy;

  /**
   * User-friendly message for display.
   * {@inheritdoc}
   *
   * @var string
   */
  protected $userMessage;

  /**
   * Whether this error should be logged.
   * {@inheritdoc}
   *
   * @var bool
   */
  protected $shouldLog = true;

  /**
   * Gets the fallback strategy.
   * {@inheritdoc}
   *
   * @return string
   *   The fallback strategy identifier.
   */
  public function getFallbackStrategy()
  {
    return $this->fallbackStrategy;
  }

  /**
   * Gets the user-friendly message.
   * {@inheritdoc}
   *
   * @return string
   *   The user message.
   */
  public function getUserMessage()
  {
    return $this->userMessage;
  }

  /**
   * Whether this exception should be logged.
   * {@inheritdoc}
   *
   * @return bool
   *   true if should be logged.
   */
  public function shouldLog()
  {
    return $this->shouldLog;
  }
}
