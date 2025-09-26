<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Configuration degradation exception.
 */
class ConfigurationDegradedException extends GracefulDegradationException
{
  public function __construct($message = 'Configuration issue detected', $code = 500, ?\Exception $previous = null)
  {
    $this->userMessage = 'Search functionality is running with limited features due to configuration issues.';
    $this->fallbackStrategy = 'basic_functionality';
    parent::__construct($message, $code, $previous);
  }
}
