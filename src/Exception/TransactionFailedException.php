<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * PostgreSQL transaction failures.
 */
class TransactionFailedException extends GracefulDegradationException
{

  public function __construct($operation, ?\Exception $previous = null)
  {
    $this->userMessage = 'Search update failed. Previous search results remain available.';
    $this->fallbackStrategy = 'read_only_mode';

    parent::__construct("Transaction failed during: {$operation}", 500, $previous);
  }
}
