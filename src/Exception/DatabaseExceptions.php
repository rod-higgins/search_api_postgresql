<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Database connection failures.
 */
class DatabaseConnectionException extends GracefulDegradationException {
  
  protected $connectionParams;
  
  public function __construct($connection_params, \Exception $previous = NULL) {
    $this->connectionParams = $connection_params;
    $this->userMessage = 'Database is temporarily unavailable. Please try again later.';
    $this->fallbackStrategy = 'cache_fallback_or_maintenance_mode';
    
    $message = "Database connection failed to {$connection_params['host']}:{$connection_params['port']}";
    parent::__construct($message, 503, $previous);
  }
}

/**
 * PostgreSQL transaction failures.
 */
class TransactionFailedException extends GracefulDegradationException {
  
  public function __construct($operation, \Exception $previous = NULL) {
    $this->userMessage = 'Search update failed. Previous search results remain available.';
    $this->fallbackStrategy = 'read_only_mode';
    
    parent::__construct("Transaction failed during: {$operation}", 500, $previous);
  }
}

/**
 * Query performance degradation.
 */
class QueryPerformanceDegradedException extends GracefulDegradationException {
  
  protected $queryTime;
  protected $threshold;
  
  public function __construct($query_time, $threshold = 5000) {
    $this->queryTime = $query_time;
    $this->threshold = $threshold;
    $this->userMessage = 'Search is running slower than usual. Results may take longer to load.';
    $this->fallbackStrategy = 'simplified_search_with_caching';
    $this->shouldLog = $query_time > ($threshold * 2); // Only log severe slowdowns
    
    parent::__construct("Query performance degraded: {$query_time}ms (threshold: {$threshold}ms)", 200);
  }
}