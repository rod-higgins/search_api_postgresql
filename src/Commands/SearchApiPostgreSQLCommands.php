<?php

namespace Drupal\search_api_postgresql\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService;
use Drupal\search_api_postgresql\Service\ConfigurationValidationService;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Search API PostgreSQL.
 */
class SearchApiPostgreSQLCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The analytics service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService
   */
  protected $analyticsService;

  /**
   * The validation service.
   *
   * @var \Drupal\search_api_postgresql\Service\ConfigurationValidationService
   */
  protected $validationService;

  /**
   * The cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

  /**
   * The queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * Constructs a SearchApiPostgreSQLCommands object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EmbeddingAnalyticsService $analytics_service,
    ConfigurationValidationService $validation_service,
    EmbeddingCacheManager $cache_manager,
    EmbeddingQueueManager $queue_manager
  ) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->analyticsService = $analytics_service;
    $this->validationService = $validation_service;
    $this->cacheManager = $cache_manager;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('search_api_postgresql.analytics'),
      $container->get('search_api_postgresql.configuration_validator'),
      $container->get('search_api_postgresql.cache_manager'),
      $container->get('search_api_postgresql.embedding_queue_manager')
    );
  }

  /**
   * Tests AI service connectivity for a server.
   *
   * @command search-api-postgresql:test-ai
   * @param string $server_id The server ID.
   * @aliases sap-test-ai
   * @usage search-api-postgresql:test-ai my_server
   *   Tests AI service for the specified server.
   */
  public function testAi($server_id) {
    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);
    
    if (!$server) {
      throw new \Exception(dt('Server @server not found.', ['@server' => $server_id]));
    }

    $backend = $server->getBackend();
    if (!method_exists($backend, 'testEmbeddingService')) {
      throw new \Exception(dt('Server @server does not support AI embeddings.', ['@server' => $server_id]));
    }

    $this->io()->title(dt('Testing AI service for server: @server', ['@server' => $server->label()]));

    try {
      $result = $backend->testEmbeddingService();
      
      if ($result['success']) {
        $this->io()->success($result['message']);
        
        if (!empty($result['details'])) {
          $this->io()->section('Details');
          $this->io()->listing($result['details']);
        }
      }
      else {
        $this->io()->error($result['message']);
        
        if (!empty($result['error'])) {
          $this->io()->warning(dt('Error: @error', ['@error' => $result['error']]));
        }
      }
    }
    catch (\Exception $e) {
      $this->io()->error(dt('Test failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Shows embedding statistics for an index.
   *
   * @command search-api-postgresql:embedding-stats
   * @param string $index_id The index ID.
   * @aliases sap-stats
   * @usage search-api-postgresql:embedding-stats my_index
   *   Shows embedding statistics for the specified index.
   */
  public function embeddingStats($index_id) {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
    
    if (!$index) {
      throw new \Exception(dt('Index @index not found.', ['@index' => $index_id]));
    }

    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    
    if (!method_exists($backend, 'getEmbeddingStatistics')) {
      throw new \Exception(dt('Server does not support embedding statistics.'));
    }

    $stats = $backend->getEmbeddingStatistics($index);
    
    $this->io()->title(dt('Embedding Statistics for @index', ['@index' => $index->label()]));
    
    $rows = [
      [dt('Total Items'), number_format($stats['total_items'])],
      [dt('Items with Embeddings'), number_format($stats['items_with_embeddings'])],
      [dt('Coverage'), round($stats['coverage'] * 100, 2) . '%'],
      [dt('Pending in Queue'), number_format($stats['pending_items'])],
      [dt('Vector Dimensions'), $stats['vector_dimensions']],
      [dt('Average Generation Time'), round($stats['avg_generation_time'], 2) . 's'],
      [dt('Cache Hit Rate'), round($stats['cache_hit_rate'] * 100, 2) . '%'],
    ];
    
    $this->io()->table(['Metric', 'Value'], $rows);

    if (!empty($stats['recent_errors'])) {
      $this->io()->section('Recent Errors');
      foreach ($stats['recent_errors'] as $error) {
        $this->io()->error(dt('@time: @message', [
          '@time' => date('Y-m-d H:i:s', $error['timestamp']),
          '@message' => $error['message'],
        ]));
      }
    }
  }

  /**
   * Processes the embedding queue.
   *
   * @command search-api-postgresql:queue-process
   * @option max-items Maximum number of items to process.
   * @option max-time Maximum time in seconds.
   * @aliases sap-queue
   * @usage search-api-postgresql:queue-process --max-items=100
   *   Processes up to 100 items from the queue.
   */
  public function queueProcess($options = ['max-items' => 50, 'max-time' => 300]) {
    $this->io()->title('Processing Embedding Queue');
    
    $start_time = time();
    $result = $this->queueManager->processQueue([
      'max_items' => $options['max-items'],
      'max_time' => $options['max-time'],
    ]);
    
    $duration = time() - $start_time;
    
    $this->io()->success(dt('Processed @processed items in @duration seconds.', [
      '@processed' => $result['processed'],
      '@duration' => $duration,
    ]));
    
    if ($result['failed'] > 0) {
      $this->io()->warning(dt('@failed items failed and will be retried.', [
        '@failed' => $result['failed'],
      ]));
    }
    
    $this->io()->section('Queue Status');
    $rows = [
      [dt('Remaining Items'), number_format($result['remaining'])],
      [dt('Success Rate'), round($result['success_rate'] * 100, 2) . '%'],
      [dt('Average Processing Time'), round($result['avg_processing_time'], 2) . 's'],
    ];
    
    $this->io()->table(['Metric', 'Value'], $rows);
  }

  /**
   * Shows queue status.
   *
   * @command search-api-postgresql:queue-status
   * @aliases sap-queue-status
   * @usage search-api-postgresql:queue-status
   *   Shows the current queue status.
   */
  public function queueStatus() {
    $status = $this->queueManager->getQueueStatus();
    
    $this->io()->title('Embedding Queue Status');
    
    $rows = [
      [dt('Total Items'), number_format($status['total_items'])],
      [dt('Ready Items'), number_format($status['ready_items'])],
      [dt('Failed Items'), number_format($status['failed_items'])],
      [dt('Processing Items'), number_format($status['processing_items'])],
      [dt('Average Wait Time'), $this->formatDuration($status['avg_wait_time'])],
      [dt('Oldest Item Age'), $this->formatDuration($status['oldest_item_age'])],
    ];
    
    $this->io()->table(['Metric', 'Value'], $rows);

    if (!empty($status['by_index'])) {
      $this->io()->section('Items by Index');
      $index_rows = [];
      foreach ($status['by_index'] as $index_id => $count) {
        $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
        $index_rows[] = [$index ? $index->label() : $index_id, number_format($count)];
      }
      $this->io()->table(['Index', 'Items'], $index_rows);
    }
  }

  /**
   * Clears the embedding cache.
   *
   * @command search-api-postgresql:cache-clear
   * @param string $server_id The server ID (optional).
   * @aliases sap-cache-clear
   * @usage search-api-postgresql:cache-clear my_server
   *   Clears cache for the specified server.
   */
  public function cacheClear($server_id = NULL) {
    if ($server_id) {
      $this->io()->title(dt('Clearing cache for server: @server', ['@server' => $server_id]));
      $this->cacheManager->clearByServer($server_id);
    }
    else {
      if (!$this->io()->confirm(dt('Clear ALL embedding caches? This cannot be undone.'))) {
        return;
      }
      $this->io()->title('Clearing all embedding caches');
      $this->cacheManager->clearAll();
    }
    
    $this->io()->success('Cache cleared successfully.');
  }

  /**
   * Shows cache statistics.
   *
   * @command search-api-postgresql:cache-stats
   * @param string $server_id The server ID (optional).
   * @aliases sap-cache-stats
   * @usage search-api-postgresql:cache-stats
   *   Shows cache statistics.
   */
  public function cacheStats($server_id = NULL) {
    $stats = $this->cacheManager->getStatistics($server_id);
    
    $this->io()->title($server_id ? 
      dt('Cache Statistics for @server', ['@server' => $server_id]) : 
      'Cache Statistics (All Servers)'
    );
    
    $rows = [
      [dt('Total Entries'), number_format($stats['total_entries'])],
      [dt('Total Size'), $this->formatBytes($stats['total_size'])],
      [dt('Hit Rate'), round($stats['hit_rate'] * 100, 2) . '%'],
      [dt('Hits'), number_format($stats['hits'])],
      [dt('Misses'), number_format($stats['misses'])],
      [dt('Average Entry Size'), $this->formatBytes($stats['avg_entry_size'])],
      [dt('Oldest Entry'), date('Y-m-d H:i:s', $stats['oldest_entry'])],
      [dt('Newest Entry'), date('Y-m-d H:i:s', $stats['newest_entry'])],
    ];
    
    $this->io()->table(['Metric', 'Value'], $rows);

    if (!empty($stats['by_model'])) {
      $this->io()->section('Entries by Model');
      $model_rows = [];
      foreach ($stats['by_model'] as $model => $count) {
        $model_rows[] = [$model, number_format($count)];
      }
      $this->io()->table(['Model', 'Entries'], $model_rows);
    }
  }

  /**
   * Performs a comprehensive health check.
   *
   * @command search-api-postgresql:health-check
   * @param string $server_id The server ID.
   * @aliases sap-health
   * @usage search-api-postgresql:health-check my_server
   *   Runs health check for the specified server.
   */
  public function healthCheck($server_id) {
    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);
    
    if (!$server) {
      throw new \Exception(dt('Server @server not found.', ['@server' => $server_id]));
    }

    $this->io()->title(dt('Health Check for @server', ['@server' => $server->label()]));

    $health = $this->validationService->checkServerHealth($server);
    
    // Overall status
    $status_icon = $health['overall']['healthy'] ? '✓' : '✗';
    $status_text = $health['overall']['healthy'] ? 'HEALTHY' : 'UNHEALTHY';
    $status_style = $health['overall']['healthy'] ? 'success' : 'error';
    
    $this->io()->section('Overall Status');
    $this->io()->writeln("<$status_style>$status_icon $status_text</$status_style>");
    
    // Individual checks
    $this->io()->section('Component Status');
    $rows = [];
    foreach ($health['checks'] as $check => $result) {
      $icon = $result['success'] ? '✓' : '✗';
      $rows[] = [
        $icon,
        $result['title'],
        $result['success'] ? 'OK' : 'FAILED',
        $result['message'],
      ];
    }
    
    $this->io()->table(['', 'Component', 'Status', 'Details'], $rows);

    // Recommendations
    if (!empty($health['recommendations'])) {
      $this->io()->section('Recommendations');
      foreach ($health['recommendations'] as $recommendation) {
        $this->io()->warning($recommendation);
      }
    }
  }

  /**
   * Formats bytes to human readable format.
   */
  protected function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
  }

  /**
   * Formats duration to human readable format.
   */
  protected function formatDuration($seconds) {
    if ($seconds < 60) {
      return $seconds . 's';
    }
    elseif ($seconds < 3600) {
      return round($seconds / 60, 1) . 'm';
    }
    elseif ($seconds < 86400) {
      return round($seconds / 3600, 1) . 'h';
    }
    else {
      return round($seconds / 86400, 1) . 'd';
    }
  }
}