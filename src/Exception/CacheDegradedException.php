<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Cache degradation exception.
 */
class CacheDegradedException extends GracefulDegradationException
{
  public function __construct($message = 'Cache is degraded or unavailable', $code = 503, ?\Exception $previous = null)
  {
    $this->userMessage = 'Search results may load more slowly due to cache issues.';
    $this->fallbackStrategy = 'no_cache_mode';
    parent::__construct($message, $code, $previous);
  }
}
