<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * API key expiration or invalidity.
 */
class ApiKeyExpiredException extends GracefulDegradationException {

  public function __construct($service_name, ?\Exception $previous = NULL) {
    $this->userMessage = 'AI search features are temporarily unavailable due to authentication issues.';
    $this->fallbackStrategy = 'text_search_only';

    parent::__construct("API key expired for service: {$service_name}", 401, $previous);
  }

}

/**
 * Insufficient permissions for operations.
 */
class InsufficientPermissionsException extends GracefulDegradationException {
  protected $requiredPermission;

  public function __construct($required_permission, ?\Exception $previous = NULL) {
    $this->requiredPermission = $required_permission;
    $this->userMessage = 'Some search features are restricted. Contact your administrator.';
    $this->fallbackStrategy = 'limited_functionality';

    parent::__construct("Insufficient permissions for: {$required_permission}", 403, $previous);
  }

}
