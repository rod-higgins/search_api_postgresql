<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for tracking and analyzing embedding usage, costs, and performance.
 */
class EmbeddingAnalyticsService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Analytics table name.
   *
   * @var string
   */
  protected $analyticsTable = 'search_api_postgresql_analytics';

  /**
   * Cost tracking table name.
   *
   * @var string
   */
  protected $costTable = 'search_api_postgresql_costs';

  /**
   * Performance metrics table name.
   *
   * @var string
   */
  protected $metricsTable = 'search_api_postgresql_metrics';

  /**
   * Constructs an EmbeddingAnalyticsService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    
    $this->ensureTablesExist();
  }

  /**
   * Records an embedding API call.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $operation
   *   The operation type (generate_embedding, batch_generate, etc.).
   * @param int $token_count
   *   Number of tokens processed.
   * @param float $cost
   *   Cost in USD.
   * @param int $duration_ms
   *   Duration in milliseconds.
   * @param array $metadata
   *   Additional metadata.
   */
  public function recordApiCall($server_id, $operation, $token_count, $cost, $duration_ms, array $metadata = []) {
    try {
      $this->database->insert($this->analyticsTable)
        ->fields([
          'server_id' => $server_id,
          'operation' => $operation,
          'token_count' => $token_count,
          'cost_usd' => $cost,
          'duration_ms' => $duration_ms,
          'metadata' => json_encode($metadata),
          'timestamp' => time(),
        ])
        ->execute();

      // Also record in cost tracking table
      $this->recordCost($server_id, $cost, $token_count, $operation);
      
    } catch (\Exception $e) {
      $this->logger->error('Failed to record API call analytics: @message', [
        '@message' => $e->getMessage()
      ]);
    }
  }

  /**
   * Records a search operation.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $index_id
   *   The index ID.
   * @param string $search_type
   *   Type of search (text, vector, hybrid).
   * @param int $duration_ms
   *   Duration in milliseconds.
   * @param int $result_count
   *   Number of results returned.
   * @param array $metadata
   *   Additional metadata.
   */
  public function recordSearch($server_id, $index_id, $search_type, $duration_ms, $result_count, array $metadata = []) {
    try {
      $this->database->insert($this->metricsTable)
        ->fields([
          'server_id' => $server_id,
          'index_id' => $index_id,
          'metric_type' => 'search',
          'metric_name' => 'search_operation',
          'value' => $duration_ms,
          'metadata' => json_encode(array_merge($metadata, [
            'search_type' => $search_type,
            'result_count' => $result_count,
          ])),
          'timestamp' => time(),
        ])
        ->execute();
        
    } catch (\Exception $e) {
      $this->logger->error('Failed to record search analytics: @message', [
        '@message' => $e->getMessage()
      ]);
    }
  }

  /**
   * Records cache performance.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $operation
   *   Cache operation (hit, miss, set).
   * @param array $metadata
   *   Additional metadata.
   */
  public function recordCacheOperation($server_id, $operation, array $metadata = []) {
    try {
      $this->database->insert($this->metricsTable)
        ->fields([
          'server_id' => $server_id,
          'metric_type' => 'cache',
          'metric_name' => 'cache_' . $operation,
          'value' => 1,
          'metadata' => json_encode($metadata),
          'timestamp' => time(),
        ])
        ->execute();
        
    } catch (\Exception $e) {
      $this->logger->error('Failed to record cache analytics: @message', [
        '@message' => $e->getMessage()
      ]);
    }
  }

  /**
   * Gets cost analytics for a time period.
   *
   * @param string $period
   *   Time period (24h, 7d, 30d, 90d).
   * @param string $server_id
   *   Optional server ID to filter by.
   *
   * @return array
   *   Cost analytics data.
   */
  public function getCostAnalytics($period, $server_id = NULL) {
    $seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $seconds;
    
    $query = $this->database->select($this->costTable, 'c')
      ->condition('c.timestamp', $start_time, '>=');
      
    if ($server_id) {
      $query->condition('c.server_id', $server_id);
    }

    // Current period costs
    $current_cost = $query
      ->addExpression('SUM(cost_usd)', 'total_cost')
      ->addExpression('SUM(token_count)', 'total_tokens')
      ->addExpression('COUNT(*)', 'api_calls')
      ->execute()
      ->fetchAssoc();

    // Previous period for comparison
    $prev_start = $start_time - $seconds;
    $prev_query = $this->database->select($this->costTable, 'c')
      ->condition('c.timestamp', $prev_start, '>=')
      ->condition('c.timestamp', $start_time, '<');
      
    if ($server_id) {
      $prev_query->condition('c.server_id', $server_id);
    }

    $previous_cost = $prev_query
      ->addExpression('SUM(cost_usd)', 'total_cost')
      ->execute()
      ->fetchField();

    // Calculate trend
    $trend = 0;
    if ($previous_cost > 0) {
      $trend = (($current_cost['total_cost'] - $previous_cost) / $previous_cost) * 100;
    }

    // Project monthly costs
    $daily_average = $current_cost['total_cost'] / ($seconds / 86400);
    $projected_monthly = $daily_average * 30;

    return [
      'current_cost' => round($current_cost['total_cost'], 4),
      'api_calls' => (int) $current_cost['api_calls'],
      'tokens_used' => (int) $current_cost['total_tokens'],
      'trend' => round($trend, 1),
      'projected_monthly' => round($projected_monthly, 2),
      'projected_calls' => round(($current_cost['api_calls'] / ($seconds / 86400)) * 30),
      'projected_tokens' => round(($current_cost['total_tokens'] / ($seconds / 86400)) * 30),
      'daily_average' => round($daily_average, 4),
    ];
  }

  /**
   * Gets performance metrics for a time period.
   *
   * @param string $period
   *   Time period (24h, 7d, 30d, 90d).
   * @param string $server_id
   *   Optional server ID to filter by.
   *
   * @return array
   *   Performance metrics data.
   */
  public function getPerformanceMetrics($period, $server_id = NULL) {
    $seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $seconds;
    
    // Search latency over time
    $latency_data = $this->getTimeSeriesData(
      'search',
      'search_operation',
      $start_time,
      time(),
      $server_id,
      'AVG'
    );

    // Cache hit rate over time
    $cache_hits = $this->getTimeSeriesData(
      'cache',
      'cache_hit',
      $start_time,
      time(),
      $server_id,
      'SUM'
    );

    $cache_misses = $this->getTimeSeriesData(
      'cache',
      'cache_miss',
      $start_time,
      time(),
      $server_id,
      'SUM'
    );

    // Calculate hit rate percentage
    $cache_hit_rate = [];
    foreach ($cache_hits as $timestamp => $hits) {
      $misses = $cache_misses[$timestamp] ?? 0;
      $total = $hits + $misses;
      $cache_hit_rate[$timestamp] = $total > 0 ? ($hits / $total) * 100 : 0;
    }

    return [
      'search_latency' => $latency_data,
      'cache_hit_rate' => $cache_hit_rate,
    ];
  }

  /**
   * Gets usage patterns for a time period.
   *
   * @param string $period
   *   Time period (24h, 7d, 30d, 90d).
   * @param string $server_id
   *   Optional server ID to filter by.
   *
   * @return array
   *   Usage patterns data.
   */
  public function getUsagePatterns($period, $server_id = NULL) {
    $seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $seconds;

    // Query volume over time
    $query_volume = $this->getTimeSeriesData(
      'search',
      'search_operation',
      $start_time,
      time(),
      $server_id,
      'COUNT'
    );

    // Embedding generation over time
    $embedding_volume = $this->getEmbeddingGenerationData($start_time, time(), $server_id);

    return [
      'query_volume' => $query_volume,
      'embedding_generation' => $embedding_volume,
    ];
  }

  /**
   * Gets server-specific metrics.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $period
   *   Time period (24h, 7d, 30d, 90d).
   *
   * @return array
   *   Server metrics.
   */
  public function getServerMetrics($server_id, $period) {
    $seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $seconds;

    // Search metrics
    $search_metrics = $this->database->select($this->metricsTable, 'm')
      ->fields('m')
      ->condition('m.server_id', $server_id)
      ->condition('m.metric_type', 'search')
      ->condition('m.timestamp', $start_time, '>=')
      ->execute()
      ->fetchAll();

    $search_queries = count($search_metrics);
    $avg_query_time = $search_queries > 0 ? 
      array_sum(array_column($search_metrics, 'value')) / $search_queries : 0;

    // API call metrics
    $api_metrics = $this->database->select($this->analyticsTable, 'a')
      ->condition('a.server_id', $server_id)
      ->condition('a.timestamp', $start_time, '>=')
      ->addExpression('COUNT(*)', 'api_calls')
      ->addExpression('SUM(cost_usd)', 'total_cost')
      ->addExpression('SUM(token_count)', 'total_tokens')
      ->execute()
      ->fetchAssoc();

    // Cache metrics
    $cache_hits = $this->database->select($this->metricsTable, 'm')
      ->condition('m.server_id', $server_id)
      ->condition('m.metric_name', 'cache_hit')
      ->condition('m.timestamp', $start_time, '>=')
      ->addExpression('SUM(value)', 'hits')
      ->execute()
      ->fetchField();

    $cache_misses = $this->database->select($this->metricsTable, 'm')
      ->condition('m.server_id', $server_id)
      ->condition('m.metric_name', 'cache_miss')
      ->condition('m.timestamp', $start_time, '>=')
      ->addExpression('SUM(value)', 'misses')
      ->execute()
      ->fetchField();

    $cache_total = $cache_hits + $cache_misses;
    $cache_hit_rate = $cache_total > 0 ? ($cache_hits / $cache_total) * 100 : 0;

    // Count different search types
    $vector_searches = 0;
    $hybrid_searches = 0;
    $embeddings_generated = 0;

    foreach ($search_metrics as $metric) {
      $metadata = json_decode($metric->metadata, TRUE) ?: [];
      $search_type = $metadata['search_type'] ?? 'text';
      
      if ($search_type === 'vector_only') {
        $vector_searches++;
      } elseif ($search_type === 'hybrid') {
        $hybrid_searches++;
      }
    }

    // Count embedding generations
    $embedding_operations = $this->database->select($this->analyticsTable, 'a')
      ->condition('a.server_id', $server_id)
      ->condition('a.operation', ['generate_embedding', 'batch_generate_embeddings'], 'IN')
      ->condition('a.timestamp', $start_time, '>=')
      ->execute()
      ->fetchAll();

    foreach ($embedding_operations as $operation) {
      $metadata = json_decode($operation->metadata, TRUE) ?: [];
      $embeddings_generated += $metadata['embedding_count'] ?? 1;
    }

    return [
      'search_queries' => $search_queries,
      'avg_query_time' => round($avg_query_time, 2),
      'api_calls' => (int) $api_metrics['api_calls'],
      'api_cost' => (float) $api_metrics['total_cost'],
      'tokens_used' => (int) $api_metrics['total_tokens'],
      'cache_hit_rate' => round($cache_hit_rate, 1),
      'vector_searches' => $vector_searches,
      'hybrid_searches' => $hybrid_searches,
      'embeddings_generated' => $embeddings_generated,
    ];
  }

  /**
   * Gets cost breakdown by operation type.
   *
   * @param string $period
   *   Time period.
   * @param string $server_id
   *   Optional server ID.
   *
   * @return array
   *   Cost breakdown.
   */
  public function getCostBreakdown($period, $server_id = NULL) {
    $seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $seconds;
    
    $query = $this->database->select($this->analyticsTable, 'a')
      ->fields('a', ['operation'])
      ->condition('a.timestamp', $start_time, '>=')
      ->addExpression('SUM(cost_usd)', 'total_cost')
      ->addExpression('SUM(token_count)', 'total_tokens')
      ->addExpression('COUNT(*)', 'call_count')
      ->groupBy('a.operation')
      ->orderBy('total_cost', 'DESC');
      
    if ($server_id) {
      $query->condition('a.server_id', $server_id);
    }

    $results = $query->execute()->fetchAllAssoc('operation');
    
    $breakdown = [];
    foreach ($results as $operation => $data) {
      $breakdown[] = [
        'operation' => $operation,
        'cost' => round($data->total_cost, 4),
        'tokens' => (int) $data->total_tokens,
        'calls' => (int) $data->call_count,
        'avg_cost_per_call' => $data->call_count > 0 ? round($data->total_cost / $data->call_count, 6) : 0,
      ];
    }

    return $breakdown;
  }

  /**
   * Gets top consuming indexes.
   *
   * @param string $period
   *   Time period.
   * @param int $limit
   *   Number of results to return.
   *
   * @return array
   *   Top consuming indexes.
   */
  public function getTopConsumingIndexes($period, $limit = 10) {
    $seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $seconds;

    // Get costs by server, then map to indexes
    $server_costs = $this->database->select($this->analyticsTable, 'a')
      ->fields('a', ['server_id'])
      ->condition('a.timestamp', $start_time, '>=')
      ->addExpression('SUM(cost_usd)', 'total_cost')
      ->addExpression('SUM(token_count)', 'total_tokens')
      ->groupBy('a.server_id')
      ->orderBy('total_cost', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAllAssoc('server_id');

    $results = [];
    foreach ($server_costs as $server_id => $cost_data) {
      // Get indexes for this server
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadByProperties(['server' => $server_id]);

      foreach ($indexes as $index) {
        $results[] = [
          'index_id' => $index->id(),
          'index_name' => $index->label(),
          'server_id' => $server_id,
          'cost' => round($cost_data->total_cost / count($indexes), 4), // Approximate
          'tokens' => round($cost_data->total_tokens / count($indexes)),
        ];
      }
    }

    // Sort by cost and limit
    usort($results, function($a, $b) {
      return $b['cost'] <=> $a['cost'];
    });

    return array_slice($results, 0, $limit);
  }

  /**
   * Cleans up old analytics data.
   *
   * @param int $retention_days
   *   Number of days to retain data.
   *
   * @return array
   *   Cleanup results.
   */
  public function cleanupOldData($retention_days = 90) {
    $cutoff_time = time() - ($retention_days * 86400);
    
    $results = [];
    
    try {
      // Clean analytics table
      $deleted_analytics = $this->database->delete($this->analyticsTable)
        ->condition('timestamp', $cutoff_time, '<')
        ->execute();
      
      // Clean cost table
      $deleted_costs = $this->database->delete($this->costTable)
        ->condition('timestamp', $cutoff_time, '<')
        ->execute();
      
      // Clean metrics table
      $deleted_metrics = $this->database->delete($this->metricsTable)
        ->condition('timestamp', $cutoff_time, '<')
        ->execute();

      $results = [
        'success' => TRUE,
        'deleted_analytics' => $deleted_analytics,
        'deleted_costs' => $deleted_costs,
        'deleted_metrics' => $deleted_metrics,
        'total_deleted' => $deleted_analytics + $deleted_costs + $deleted_metrics,
        'retention_days' => $retention_days,
      ];

      $this->logger->info('Cleaned up old analytics data: @total records deleted', [
        '@total' => $results['total_deleted']
      ]);

    } catch (\Exception $e) {
      $results = [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
      
      $this->logger->error('Failed to clean up analytics data: @message', [
        '@message' => $e->getMessage()
      ]);
    }

    return $results;
  }

  /**
   * Records a cost entry.
   */
  protected function recordCost($server_id, $cost, $token_count, $operation) {
    $this->database->insert($this->costTable)
      ->fields([
        'server_id' => $server_id,
        'cost_usd' => $cost,
        'token_count' => $token_count,
        'operation' => $operation,
        'timestamp' => time(),
      ])
      ->execute();
  }

  /**
   * Gets time series data for metrics.
   */
  protected function getTimeSeriesData($metric_type, $metric_name, $start_time, $end_time, $server_id = NULL, $aggregation = 'AVG') {
    // Calculate interval based on time range
    $duration = $end_time - $start_time;
    $interval = $this->calculateInterval($duration);

    $query = $this->database->select($this->metricsTable, 'm')
      ->condition('m.metric_type', $metric_type)
      ->condition('m.metric_name', $metric_name)
      ->condition('m.timestamp', $start_time, '>=')
      ->condition('m.timestamp', $end_time, '<=');

    if ($server_id) {
      $query->condition('m.server_id', $server_id);
    }

    // Group by time intervals
    $query->addExpression("FLOOR(timestamp / {$interval}) * {$interval}", 'time_bucket');
    
    switch ($aggregation) {
      case 'SUM':
        $query->addExpression('SUM(value)', 'aggregated_value');
        break;
      case 'COUNT':
        $query->addExpression('COUNT(*)', 'aggregated_value');
        break;
      case 'MAX':
        $query->addExpression('MAX(value)', 'aggregated_value');
        break;
      case 'MIN':
        $query->addExpression('MIN(value)', 'aggregated_value');
        break;
      default:
        $query->addExpression('AVG(value)', 'aggregated_value');
    }

    $query->groupBy('time_bucket')
      ->orderBy('time_bucket');

    $results = $query->execute()->fetchAllKeyed();
    
    // Fill in missing intervals with zeros
    $filled_results = [];
    for ($time = $start_time; $time <= $end_time; $time += $interval) {
      $bucket = floor($time / $interval) * $interval;
      $filled_results[$bucket] = $results[$bucket] ?? 0;
    }

    return $filled_results;
  }

  /**
   * Gets embedding generation data over time.
   */
  protected function getEmbeddingGenerationData($start_time, $end_time, $server_id = NULL) {
    $duration = $end_time - $start_time;
    $interval = $this->calculateInterval($duration);

    $query = $this->database->select($this->analyticsTable, 'a')
      ->condition('a.operation', ['generate_embedding', 'batch_generate_embeddings'], 'IN')
      ->condition('a.timestamp', $start_time, '>=')
      ->condition('a.timestamp', $end_time, '<=');

    if ($server_id) {
      $query->condition('a.server_id', $server_id);
    }

    $query->addExpression("FLOOR(timestamp / {$interval}) * {$interval}", 'time_bucket');
    $query->addExpression('COUNT(*)', 'operation_count');
    $query->groupBy('time_bucket')
      ->orderBy('time_bucket');

    $results = $query->execute()->fetchAllKeyed();
    
    // Fill in missing intervals
    $filled_results = [];
    for ($time = $start_time; $time <= $end_time; $time += $interval) {
      $bucket = floor($time / $interval) * $interval;
      $filled_results[$bucket] = $results[$bucket] ?? 0;
    }

    return $filled_results;
  }

  /**
   * Calculates appropriate interval for time series data.
   */
  protected function calculateInterval($duration) {
    if ($duration <= 86400) { // 1 day
      return 3600; // 1 hour intervals
    } elseif ($duration <= 604800) { // 1 week
      return 21600; // 6 hour intervals
    } elseif ($duration <= 2592000) { // 30 days
      return 86400; // 1 day intervals
    } else {
      return 604800; // 1 week intervals
    }
  }

  /**
   * Converts period string to seconds.
   */
  protected function getPeriodSeconds($period) {
    switch ($period) {
      case '1h':
        return 3600;
      case '24h':
        return 86400;
      case '7d':
        return 604800;
      case '30d':
        return 2592000;
      case '90d':
        return 7776000;
      default:
        return 604800; // Default to 7 days
    }
  }

  /**
   * Ensures analytics tables exist.
   */
  protected function ensureTablesExist() {
    $schema = $this->database->schema();

    // Analytics table
    if (!$schema->tableExists($this->analyticsTable)) {
      $table_spec = [
        'description' => 'Analytics data for Search API PostgreSQL embedding operations',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => 'Primary key',
          ],
          'server_id' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Server ID',
          ],
          'operation' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Operation type',
          ],
          'token_count' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Number of tokens',
          ],
          'cost_usd' => [
            'type' => 'numeric',
            'precision' => 10,
            'scale' => 6,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Cost in USD',
          ],
          'duration_ms' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Duration in milliseconds',
          ],
          'metadata' => [
            'type' => 'text',
            'description' => 'Additional metadata as JSON',
          ],
          'timestamp' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'description' => 'Unix timestamp',
          ],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'server_timestamp' => ['server_id', 'timestamp'],
          'operation_timestamp' => ['operation', 'timestamp'],
          'timestamp' => ['timestamp'],
        ],
      ];
      $schema->createTable($this->analyticsTable, $table_spec);
    }

    // Cost tracking table
    if (!$schema->tableExists($this->costTable)) {
      $table_spec = [
        'description' => 'Cost tracking for embedding operations',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => 'Primary key',
          ],
          'server_id' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Server ID',
          ],
          'cost_usd' => [
            'type' => 'numeric',
            'precision' => 10,
            'scale' => 6,
            'not null' => TRUE,
            'description' => 'Cost in USD',
          ],
          'token_count' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'description' => 'Number of tokens',
          ],
          'operation' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Operation type',
          ],
          'timestamp' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'description' => 'Unix timestamp',
          ],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'server_timestamp' => ['server_id', 'timestamp'],
          'timestamp' => ['timestamp'],
        ],
      ];
      $schema->createTable($this->costTable, $table_spec);
    }

    // Metrics table
    if (!$schema->tableExists($this->metricsTable)) {
      $table_spec = [
        'description' => 'Performance metrics for Search API PostgreSQL',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => 'Primary key',
          ],
          'server_id' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Server ID',
          ],
          'index_id' => [
            'type' => 'varchar',
            'length' => 255,
            'description' => 'Index ID (optional)',
          ],
          'metric_type' => [
            'type' => 'varchar',
            'length' => 100,
            'not null' => TRUE,
            'description' => 'Metric type (search, cache, etc)',
          ],
          'metric_name' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Specific metric name',
          ],
          'value' => [
            'type' => 'numeric',
            'precision' => 15,
            'scale' => 6,
            'not null' => TRUE,
            'description' => 'Metric value',
          ],
          'metadata' => [
            'type' => 'text',
            'description' => 'Additional metadata as JSON',
          ],
          'timestamp' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'description' => 'Unix timestamp',
          ],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'metric_timestamp' => ['metric_type', 'metric_name', 'timestamp'],
          'server_timestamp' => ['server_id', 'timestamp'],
          'timestamp' => ['timestamp'],
        ],
      ];
      $schema->createTable($this->metricsTable, $table_spec);
    }
  }

}