<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Insufficient permissions for operations.
 */
class InsufficientPermissionsException extends GracefulDegradationException
{
  /**
   * The required permission that is missing.
   * {@inheritdoc}
   *
   * @var string
   */
  protected $requiredPermission;

  public function __construct($required_permission, ?\Exception $previous = null)
  {
    $this->requiredPermission = $required_permission;
    $this->userMessage = 'Some search features are restricted. Contact your administrator.';
    $this->fallbackStrategy = 'limited_functionality';

    parent::__construct("Insufficient permissions for: {$required_permission}", 403, $previous);
  }
}
