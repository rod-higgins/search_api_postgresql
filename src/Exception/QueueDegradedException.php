<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Queue degradation exception.
 */
class QueueDegradedException extends GracefulDegradationException
{
  public function __construct($message = 'Queue processing is degraded', $code = 503, ?\Exception $previous = null)
  {
    $this->userMessage = 'Background processing is slower than usual. Content may take longer to appear in search.';
    $this->fallbackStrategy = 'synchronous_processing';
    parent::__construct($message, $code, $previous);
  }
}
