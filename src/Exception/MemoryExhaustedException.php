<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Memory exhaustion during operations.
 */
class MemoryExhaustedException extends GracefulDegradationException
{
  protected $memoryUsage;
  protected $memoryLimit;

  public function __construct($memory_usage, $memory_limit, ?\Exception $previous = null)
  {
    $this->memoryUsage = $memory_usage;
    $this->memoryLimit = $memory_limit;
    $this->userMessage = 'Search is processing in smaller batches due to high demand.';
    $this->fallbackStrategy = 'batch_size_reduction';

    parent::__construct("Memory exhausted: {$memory_usage}/{$memory_limit}", 507, $previous);
  }
}
