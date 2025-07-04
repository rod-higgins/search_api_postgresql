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
   * Daily aggregates table name.
   *
   * @var string
   */
  protected $dailyAggregatesTable = 'search_api_postgresql_daily_aggregates';

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
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to record API call: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Records a performance metric.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $metric_type
   *   The metric type.
   * @param string $metric_name
   *   The metric name.
   * @param float $value
   *   The metric value.
   * @param array $metadata
   *   Additional metadata.
   * @param string|null $index_id
   *   Optional index ID.
   */
  public function recordMetric($server_id, $metric_type, $metric_name, $value, array $metadata = [], $index_id = NULL) {
    try {
      $fields = [
        'server_id' => $server_id,
        'metric_type' => $metric_type,
        'metric_name' => $metric_name,
        'value' => $value,
        'metadata' => json_encode($metadata),
        'timestamp' => time(),
      ];
      
      if ($index_id) {
        $fields['index_id'] = $index_id;
      }
      
      $this->database->insert($this->metricsTable)
        ->fields($fields)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to record metric: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Gets cost analytics for a period.
   *
   * @param string $period
   *   The time period (24h, 7d, 30d, 90d).
   * @param string|null $server_id
   *   Optional server ID filter.
   *
   * @return array
   *   Cost analytics data.
   */
  public function getCostAnalytics($period = '7d', $server_id = NULL) {
    // Default response for non-AI backends
    $default_response = [
      'current_cost' => 0,
      'tokens_used' => 0,
      'api_calls' => 0,
      'projected_monthly' => 0,
      'projected_calls' => 0,
      'projected_tokens' => 0,
      'trend' => 0,
      'by_operation' => [],
    ];

    // Check if analytics table exists - if not, return defaults
    if (!$this->database->schema()->tableExists($this->analyticsTable)) {
      return $default_response;
    }

    try {
      $start_time = time() - $this->getPeriodSeconds($period);
      
      // Wrap all database operations in try-catch
      $query = $this->database->select($this->analyticsTable, 'a')
        ->fields('a')
        ->condition('timestamp', $start_time, '>=');
      
      if ($server_id) {
        $query->condition('server_id', $server_id);
      }
      
      $results = $query->execute()->fetchAll();
      
      $total_cost = 0;
      $total_tokens = 0;
      $api_calls = 0;
      $by_operation = [];
      
      foreach ($results as $record) {
        $total_cost += $record->cost_usd;
        $total_tokens += $record->token_count;
        $api_calls++;
        
        if (!isset($by_operation[$record->operation])) {
          $by_operation[$record->operation] = [
            'cost' => 0,
            'tokens' => 0,
            'calls' => 0,
          ];
        }
        
        $by_operation[$record->operation]['cost'] += $record->cost_usd;
        $by_operation[$record->operation]['tokens'] += $record->token_count;
        $by_operation[$record->operation]['calls']++;
      }
      
      // Calculate projections
      $days_in_period = $this->getPeriodSeconds($period) / 86400;
      $daily_cost = $days_in_period > 0 ? $total_cost / $days_in_period : 0;
      $projected_monthly = $daily_cost * 30;
      
      // Calculate trend - WRAP IN ADDITIONAL TRY-CATCH
      $trend = 0;
      if ($days_in_period >= 2) {
        try {
          $mid_time = $start_time + ($this->getPeriodSeconds($period) / 2);
          
          $first_half_query = $this->database->select($this->analyticsTable, 'a')
            ->condition('timestamp', [$start_time, $mid_time], 'BETWEEN')
            ->addExpression('SUM(cost_usd)', 'total_cost');
          $first_half = $first_half_query->execute()->fetchField();
          
          $second_half_query = $this->database->select($this->analyticsTable, 'a')
            ->condition('timestamp', $mid_time, '>')
            ->addExpression('SUM(cost_usd)', 'total_cost');
          $second_half = $second_half_query->execute()->fetchField();
          
          if ($first_half > 0) {
            $trend = (($second_half - $first_half) / $first_half) * 100;
          }
        } catch (\Exception $e) {
          // If trend calculation fails, just use 0
          $trend = 0;
          $this->logger->warning('Failed to calculate cost trend: @message', ['@message' => $e->getMessage()]);
        }
      }
      
      return [
        'current_cost' => $total_cost,
        'tokens_used' => $total_tokens,
        'api_calls' => $api_calls,
        'projected_monthly' => $projected_monthly,
        'projected_calls' => $days_in_period > 0 ? $api_calls * (30 / $days_in_period) : 0,
        'projected_tokens' => $days_in_period > 0 ? $total_tokens * (30 / $days_in_period) : 0,
        'trend' => $trend,
        'by_operation' => $by_operation,
      ];
      
    } catch (\Exception $e) {
      // If any database operation fails, log error and return defaults
      $this->logger->error('Failed to get cost analytics: @message', ['@message' => $e->getMessage()]);
      return $default_response;
    }
  }

  /**
   * Gets cache analytics data.
   *
   * @return array
   *   Cache analytics data.
   */
  public function getCacheAnalytics() {
    try {
      // Get cache-related metrics from the metrics table
      $query = $this->database->select($this->metricsTable, 'm')
        ->fields('m', ['metric_name', 'value', 'timestamp'])
        ->condition('metric_type', 'cache')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 100);
      
      $cache_metrics = $query->execute()->fetchAll();
      
      // Get general analytics that might be cache-related
      $cost_query = $this->database->select($this->analyticsTable, 'a')
        ->fields('a', ['operation', 'cost_usd', 'duration_ms', 'timestamp'])
        ->condition('operation', ['cache_hit', 'cache_miss', 'cache_clear'], 'IN')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 50);
      
      $cost_analytics = $cost_query->execute()->fetchAll();
      
      return [
        'cache_metrics' => $cache_metrics,
        'cost_analytics' => $cost_analytics,
        'summary' => [
          'total_cache_operations' => count($cost_analytics),
          'recent_metrics' => count($cache_metrics),
          'timestamp' => time(),
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get cache analytics: @message', ['@message' => $e->getMessage()]);
      return [
        'cache_metrics' => [],
        'cost_analytics' => [],
        'summary' => [
          'total_cache_operations' => 0,
          'recent_metrics' => 0,
          'timestamp' => time(),
          'error' => $e->getMessage(),
        ],
      ];
    }
  }
  
  /**
   * Gets server metrics for a specific time period.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $period
   *   The time period (1h, 24h, 7d, 30d, 90d).
   *
   * @return array
   *   Server metrics data.
   */
  public function getServerMetrics($server_id, $period = '24h') {
    $period_seconds = $this->getPeriodSeconds($period);
    $start_time = time() - $period_seconds;
    
    $metrics = [
      'search_queries' => 0,
      'avg_query_time' => 0,
      'api_calls' => 0,
      'cache_hit_rate' => 0,
      'total_cost' => 0,
      'total_tokens' => 0,
      'error_rate' => 0,
      'period' => $period,
      'start_time' => $start_time,
      'end_time' => time(),
    ];
    
    try {
      // Get search query metrics
      $query_metrics_query = $this->database->select($this->metricsTable, 'm');
      $query_metrics_query->condition('server_id', $server_id);
      $query_metrics_query->condition('metric_type', 'search');
      $query_metrics_query->condition('timestamp', $start_time, '>=');
      $query_metrics_query->fields('m', ['metric_name', 'value']);
      $query_metrics = $query_metrics_query->execute()->fetchAll();
      
      $search_queries = 0;
      $total_query_time = 0;
      $query_count = 0;
      
      foreach ($query_metrics as $metric) {
        if ($metric->metric_name === 'query') {
          $search_queries++;
        } elseif ($metric->metric_name === 'query_time') {
          $total_query_time += $metric->value;
          $query_count++;
        }
      }
      
      $metrics['search_queries'] = $search_queries;
      $metrics['avg_query_time'] = $query_count > 0 ? round($total_query_time / $query_count, 2) : 0;
      
      // Get API call metrics
      $api_stats_query = $this->database->select($this->analyticsTable, 'a');
      $api_stats_query->condition('server_id', $server_id);
      $api_stats_query->condition('timestamp', $start_time, '>=');
      $api_stats_query->addExpression('COUNT(*)', 'total_calls');
      $api_stats_query->addExpression('SUM(cost_usd)', 'total_cost');
      $api_stats_query->addExpression('SUM(token_count)', 'total_tokens');
      $api_stats = $api_stats_query->execute()->fetchAssoc();
      
      if ($api_stats) {
        $metrics['api_calls'] = (int) $api_stats['total_calls'];
        $metrics['total_cost'] = (float) $api_stats['total_cost'];
        $metrics['total_tokens'] = (int) $api_stats['total_tokens'];
      }
      
      // Calculate cache hit rate
      $cache_hits_query = $this->database->select($this->metricsTable, 'm');
      $cache_hits_query->condition('server_id', $server_id);
      $cache_hits_query->condition('metric_type', 'cache');
      $cache_hits_query->condition('metric_name', 'hit');
      $cache_hits_query->condition('timestamp', $start_time, '>=');
      $cache_hits = $cache_hits_query->countQuery()->execute()->fetchField();
      
      $cache_misses_query = $this->database->select($this->metricsTable, 'm');
      $cache_misses_query->condition('server_id', $server_id);
      $cache_misses_query->condition('metric_type', 'cache');
      $cache_misses_query->condition('metric_name', 'miss');
      $cache_misses_query->condition('timestamp', $start_time, '>=');
      $cache_misses = $cache_misses_query->countQuery()->execute()->fetchField();
      
      $cache_total = $cache_hits + $cache_misses;
      if ($cache_total > 0) {
        $metrics['cache_hit_rate'] = round(($cache_hits / $cache_total) * 100, 2);
      }
      
      // Calculate error rate
      $error_count_query = $this->database->select($this->metricsTable, 'm');
      $error_count_query->condition('server_id', $server_id);
      $error_count_query->condition('metric_type', 'error');
      $error_count_query->condition('timestamp', $start_time, '>=');
      $error_count = $error_count_query->countQuery()->execute()->fetchField();
      
      $total_operations = $metrics['search_queries'] + $metrics['api_calls'];
      if ($total_operations > 0) {
        $metrics['error_rate'] = round(($error_count / $total_operations) * 100, 2);
      }
      
    } catch (\Exception $e) {
      $this->logger->error('Failed to get server metrics for @server: @message', [
        '@server' => $server_id,
        '@message' => $e->getMessage(),
      ]);
    }
    
    return $metrics;
  }

  /**
   * Gets performance metrics for a period.
   *
   * @param string $period
   *   The time period.
   * @param string|null $server_id
   *   Optional server ID filter.
   *
   * @return array
   *   Performance metrics data.
   */
  public function getPerformanceMetrics($period = '7d', $server_id = NULL) {
    $start_time = time() - $this->getPeriodSeconds($period);
    
    $query = $this->database->select($this->metricsTable, 'm')
      ->fields('m')
      ->condition('timestamp', $start_time, '>=')
      ->orderBy('timestamp', 'ASC');
    
    if ($server_id) {
      $query->condition('server_id', $server_id);
    }
    
    $results = $query->execute()->fetchAll();
    
    $metrics = [
      'search_latency' => [],
      'cache_hit_rate' => [],
      'queue_size' => [],
      'error_rate' => [],
    ];
    
    // Group metrics by hour
    $hourly_buckets = [];
    foreach ($results as $record) {
      $hour = floor($record->timestamp / 3600) * 3600;
      
      if (!isset($hourly_buckets[$hour])) {
        $hourly_buckets[$hour] = [
          'search_latency' => [],
          'cache_hits' => 0,
          'cache_misses' => 0,
          'queue_size' => [],
          'errors' => 0,
          'total' => 0,
        ];
      }
      
      switch ($record->metric_type) {
        case 'search':
          if ($record->metric_name === 'latency') {
            $hourly_buckets[$hour]['search_latency'][] = $record->value;
          }
          break;
          
        case 'cache':
          if ($record->metric_name === 'hit') {
            $hourly_buckets[$hour]['cache_hits']++;
          }
          elseif ($record->metric_name === 'miss') {
            $hourly_buckets[$hour]['cache_misses']++;
          }
          break;
          
        case 'queue':
          if ($record->metric_name === 'size') {
            $hourly_buckets[$hour]['queue_size'][] = $record->value;
          }
          break;
          
        case 'error':
          $hourly_buckets[$hour]['errors']++;
          break;
      }
      
      $hourly_buckets[$hour]['total']++;
    }
    
    // Process hourly buckets
    foreach ($hourly_buckets as $hour => $data) {
      // Average search latency
      if (!empty($data['search_latency'])) {
        $metrics['search_latency'][] = [
          'timestamp' => $hour,
          'value' => array_sum($data['search_latency']) / count($data['search_latency']),
        ];
      }
      
      // Cache hit rate
      $cache_total = $data['cache_hits'] + $data['cache_misses'];
      if ($cache_total > 0) {
        $metrics['cache_hit_rate'][] = [
          'timestamp' => $hour,
          'value' => ($data['cache_hits'] / $cache_total) * 100,
        ];
      }
      
      // Average queue size
      if (!empty($data['queue_size'])) {
        $metrics['queue_size'][] = [
          'timestamp' => $hour,
          'value' => array_sum($data['queue_size']) / count($data['queue_size']),
        ];
      }
      
      // Error rate
      if ($data['total'] > 0) {
        $metrics['error_rate'][] = [
          'timestamp' => $hour,
          'value' => ($data['errors'] / $data['total']) * 100,
        ];
      }
    }
    
    return $metrics;
  }

  /**
   * Aggregates daily statistics.
   *
   * This method is called by cron to aggregate analytics data into daily summaries.
   */
  public function aggregateDailyStats() {
    try {
      // Get the last aggregation timestamp
      $last_aggregation = $this->database->select($this->dailyAggregatesTable, 'da')
        ->fields('da', ['date'])
        ->orderBy('date', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      
      // Default to yesterday if no previous aggregation
      $start_date = $last_aggregation ? strtotime($last_aggregation . ' +1 day') : strtotime('yesterday midnight');
      $end_date = strtotime('today midnight');
      
      // Don't aggregate today's incomplete data
      if ($start_date >= $end_date) {
        return;
      }
      
      // Process each day
      for ($date = $start_date; $date < $end_date; $date = strtotime('+1 day', $date)) {
        $day_start = $date;
        $day_end = strtotime('+1 day', $date) - 1;
        
        // Aggregate cost data
        $cost_query = $this->database->select($this->analyticsTable, 'a')
          ->condition('timestamp', [$day_start, $day_end], 'BETWEEN');
        
        $cost_query->addExpression('COUNT(*)', 'total_calls');
        $cost_query->addExpression('SUM(cost_usd)', 'total_cost');
        $cost_query->addExpression('SUM(token_count)', 'total_tokens');
        $cost_query->addExpression('AVG(duration_ms)', 'avg_duration');
        $cost_query->addField('a', 'server_id');
        $cost_query->addField('a', 'operation');
        $cost_query->groupBy('server_id');
        $cost_query->groupBy('operation');
        
        $cost_results = $cost_query->execute()->fetchAll();
        
        foreach ($cost_results as $result) {
          $this->database->merge($this->dailyAggregatesTable)
            ->keys([
              'date' => date('Y-m-d', $date),
              'server_id' => $result->server_id,
              'operation' => $result->operation,
            ])
            ->fields([
              'total_calls' => $result->total_calls,
              'total_cost' => $result->total_cost,
              'total_tokens' => $result->total_tokens,
              'avg_duration' => $result->avg_duration,
              'created' => time(),
            ])
            ->execute();
        }
        
        // Aggregate performance metrics
        $metrics_query = $this->database->select($this->metricsTable, 'm')
          ->condition('timestamp', [$day_start, $day_end], 'BETWEEN');
        
        $metrics_query->addExpression('COUNT(*)', 'count');
        $metrics_query->addExpression('AVG(value)', 'avg_value');
        $metrics_query->addExpression('MIN(value)', 'min_value');
        $metrics_query->addExpression('MAX(value)', 'max_value');
        $metrics_query->addField('m', 'server_id');
        $metrics_query->addField('m', 'metric_type');
        $metrics_query->addField('m', 'metric_name');
        $metrics_query->groupBy('server_id');
        $metrics_query->groupBy('metric_type');
        $metrics_query->groupBy('metric_name');
        
        $metrics_results = $metrics_query->execute()->fetchAll();
        
        foreach ($metrics_results as $result) {
          $this->database->insert($this->metricsTable)
            ->fields([
              'server_id' => $result->server_id,
              'metric_type' => 'daily_aggregate',
              'metric_name' => $result->metric_type . '_' . $result->metric_name,
              'value' => $result->avg_value,
              'metadata' => json_encode([
                'date' => date('Y-m-d', $date),
                'count' => $result->count,
                'min' => $result->min_value,
                'max' => $result->max_value,
                'original_type' => $result->metric_type,
                'original_name' => $result->metric_name,
              ]),
              'timestamp' => $date,
            ])
            ->execute();
        }
      }
      
      // Clean up old raw data (keep 90 days)
      $cutoff_time = time() - (90 * 86400);
      $this->database->delete($this->analyticsTable)
        ->condition('timestamp', $cutoff_time, '<')
        ->execute();
      
      $this->logger->info('Aggregated daily stats from @start to @end', [
        '@start' => date('Y-m-d', $start_date),
        '@end' => date('Y-m-d', $end_date - 1),
      ]);
      
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to aggregate daily stats: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets server statistics.
   *
   * @param string $server_id
   *   The server ID.
   *
   * @return array
   *   Server statistics.
   */
  public function getServerStats($server_id) {
    $stats = [
      'total_embeddings' => 0,
      'total_cost' => 0,
      'avg_latency' => 0,
      'cache_hit_rate' => 0,
      'last_activity' => NULL,
    ];
    
    // Get total embeddings and cost
    $totals = $this->database->select($this->analyticsTable, 'a')
      ->condition('server_id', $server_id)
      ->fields('a')
      ->addExpression('COUNT(*)', 'total_calls')
      ->addExpression('SUM(cost_usd)', 'total_cost')
      ->addExpression('MAX(timestamp)', 'last_activity')
      ->execute()
      ->fetchAssoc();
    
    if ($totals) {
      $stats['total_embeddings'] = $totals['total_calls'];
      $stats['total_cost'] = $totals['total_cost'];
      $stats['last_activity'] = $totals['last_activity'];
    }
    
    // Get average latency from last 24 hours
    $yesterday = time() - 86400;
    $latency = $this->database->select($this->metricsTable, 'm')
      ->condition('server_id', $server_id)
      ->condition('metric_type', 'search')
      ->condition('metric_name', 'latency')
      ->condition('timestamp', $yesterday, '>')
      ->fields('m')
      ->addExpression('AVG(value)', 'avg_latency')
      ->execute()
      ->fetchField();
    
    if ($latency) {
      $stats['avg_latency'] = round($latency, 2);
    }
    
    // Calculate cache hit rate from last 24 hours
    $cache_hits = $this->database->select($this->metricsTable, 'm')
      ->condition('server_id', $server_id)
      ->condition('metric_type', 'cache')
      ->condition('metric_name', 'hit')
      ->condition('timestamp', $yesterday, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
    
    $cache_misses = $this->database->select($this->metricsTable, 'm')
      ->condition('server_id', $server_id)
      ->condition('metric_type', 'cache')
      ->condition('metric_name', 'miss')
      ->condition('timestamp', $yesterday, '>')
      ->countQuery()
      ->execute()
      ->fetchField();
    
    $cache_total = $cache_hits + $cache_misses;
    if ($cache_total > 0) {
      $stats['cache_hit_rate'] = round(($cache_hits / $cache_total) * 100, 2);
    }
    
    return $stats;
  }

  /**
   * Converts period string to seconds.
   *
   * @param string $period
   *   The period string.
   *
   * @return int
   *   Number of seconds.
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

    // Performance metrics table
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

    // Daily aggregates table
    if (!$schema->tableExists($this->dailyAggregatesTable)) {
      $table_spec = [
        'description' => 'Daily aggregated statistics for Search API PostgreSQL',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => 'Primary key',
          ],
          'date' => [
            'type' => 'varchar',
            'length' => 10,
            'not null' => TRUE,
            'description' => 'Date (YYYY-MM-DD)',
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
          'total_calls' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Total API calls',
          ],
          'total_cost' => [
            'type' => 'numeric',
            'precision' => 10,
            'scale' => 6,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Total cost in USD',
          ],
          'total_tokens' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Total tokens processed',
          ],
          'avg_duration' => [
            'type' => 'numeric',
            'precision' => 10,
            'scale' => 2,
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Average duration in milliseconds',
          ],
          'created' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'description' => 'Timestamp when aggregate was created',
          ],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'date_server_operation' => ['date', 'server_id', 'operation'],
        ],
        'indexes' => [
          'date' => ['date'],
          'server_date' => ['server_id', 'date'],
        ],
      ];
      $schema->createTable($this->dailyAggregatesTable, $table_spec);
    }
  }

}