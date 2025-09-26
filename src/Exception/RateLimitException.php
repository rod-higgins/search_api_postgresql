<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Rate limit exception.
 */
class RateLimitException extends GracefulDegradationException
{
  public function __construct($message = 'Rate limit exceeded', $code = 429, ?\Exception $previous = null)
  {
    $this->userMessage = 'Search requests are being rate limited. Please try again shortly.';
    $this->fallbackStrategy = 'cached_results_with_delay';
    parent::__construct($message, $code, $previous);
  }
}
