<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Query performance degradation.
 */
class QueryPerformanceDegradedException extends GracefulDegradationException
{
  /**
   * Query execution time in milliseconds.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $queryTime;
  /**
   * Performance threshold in milliseconds.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $threshold;

  public function __construct($query_time, $threshold = 5000)
  {
    $this->queryTime = $query_time;
    $this->threshold = $threshold;
    $this->userMessage = 'Search is running slower than usual. Results may take longer to load.';
    $this->fallbackStrategy = 'simplified_search_with_caching';
    // Only log severe slowdowns.
    $this->shouldLog = $query_time > ($threshold * 2);

    parent::__construct("Query performance degraded: {$query_time}ms (threshold: {$threshold}ms)", 200);
  }
}
