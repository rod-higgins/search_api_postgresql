<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Database connection failures.
 */
class DatabaseConnectionException extends GracefulDegradationException
{
  /**
   * Database connection parameters.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $connectionParams;

  public function __construct($connection_params, ?\Exception $previous = null)
  {
    $this->connectionParams = $connection_params;
    $this->userMessage = 'Database is temporarily unavailable. Please try again later.';
    $this->fallbackStrategy = 'cache_fallback_or_maintenance_mode';

    $message = "Database connection failed to {$connection_params['host']}:{$connection_params['port']}";
    parent::__construct($message, 503, $previous);
  }
}
