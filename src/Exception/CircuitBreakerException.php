<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Circuit breaker exception.
 */
class CircuitBreakerException extends GracefulDegradationException
{
  public function __construct($message = 'Circuit breaker activated', $code = 503, ?\Exception $previous = null)
  {
    $this->userMessage = 'Search service is temporarily disabled due to recurring issues.';
    $this->fallbackStrategy = 'offline_mode';
    parent::__construct($message, $code, $previous);
  }
}
