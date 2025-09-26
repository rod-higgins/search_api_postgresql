<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Vector index corruption.
 */
class VectorIndexCorruptedException extends GracefulDegradationException
{
  protected $indexName;

  public function __construct($index_name, ?\Exception $previous = null)
  {
    $this->indexName = $index_name;
    $this->userMessage = 'AI search index needs rebuilding. Using text search temporarily.';
    $this->fallbackStrategy = 'text_search_with_reindex_queue';

    parent::__construct("Vector index corrupted: {$index_name}", 500, $previous);
  }
}
