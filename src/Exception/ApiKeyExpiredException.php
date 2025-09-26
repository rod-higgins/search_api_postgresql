<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * API key expiration or invalidity.
 */
class ApiKeyExpiredException extends GracefulDegradationException
{

  public function __construct($service_name, ?\Exception $previous = null)
  {
    $this->userMessage = 'AI search features are temporarily unavailable due to authentication issues.';
    $this->fallbackStrategy = 'text_search_only';

    parent::__construct("API key expired for service: {$service_name}", 401, $previous);
  }
}
