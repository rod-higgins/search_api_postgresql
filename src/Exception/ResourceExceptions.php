<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Memory exhaustion during operations.
 */
class MemoryExhaustedException extends GracefulDegradationException {

  protected $memoryUsage;
  protected $memoryLimit;

  public function __construct($memory_usage, $memory_limit, ?\Exception $previous = NULL) {
    $this->memoryUsage = $memory_usage;
    $this->memoryLimit = $memory_limit;
    $this->userMessage = 'Search is processing in smaller batches due to high demand.';
    $this->fallbackStrategy = 'batch_size_reduction';

    parent::__construct("Memory exhausted: {$memory_usage}/{$memory_limit}", 507, $previous);
  }

}

/**
 * Vector index corruption.
 */
class VectorIndexCorruptedException extends GracefulDegradationException {

  protected $indexName;

  public function __construct($index_name, ?\Exception $previous = NULL) {
    $this->indexName = $index_name;
    $this->userMessage = 'AI search index needs rebuilding. Using text search temporarily.';
    $this->fallbackStrategy = 'text_search_with_reindex_queue';

    parent::__construct("Vector index corrupted: {$index_name}", 500, $previous);
  }

}
