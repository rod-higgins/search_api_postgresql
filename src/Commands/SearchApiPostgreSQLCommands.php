<?php

namespace Drupal\search_api_postgresql\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;
use Drupal\search_api_postgresql\Service\ConfigurationValidationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Search API PostgreSQL.
 */
class SearchApiPostgreSQLCommands extends DrushCommands implements ContainerInjectionInterface {
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager|null
   */
  protected $queueManager;

  /**
   * The cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager|null
   */
  protected $cacheManager;

  /**
   * The validation service.
   *
   * @var \Drupal\search_api_postgresql\Service\ConfigurationValidationService|null
   */
  protected $validationService;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $searchApiLogger;

  /**
   * Constructs a SearchApiPostgreSQLCommands object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ?EmbeddingQueueManager $queue_manager = NULL,
    ?EmbeddingCacheManager $cache_manager = NULL,
    ?ConfigurationValidationService $validation_service = NULL,
    ?LoggerInterface $logger = NULL,
  ) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->queueManager = $queue_manager;
    $this->cacheManager = $cache_manager;
    $this->validationService = $validation_service;
    $this->searchApiLogger = $logger ?: \Drupal::logger('search_api_postgresql');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Use NULL if services don't exist (e.g., during uninstall)
    return new static(
          $container->get('entity_type.manager'),
          $container->has('search_api_postgresql.embedding_queue_manager') ? $container->get('search_api_postgresql.embedding_queue_manager') : NULL,
          $container->has('search_api_postgresql.cache_manager') ? $container->get('search_api_postgresql.cache_manager') : NULL,
          $container->has('search_api_postgresql.configuration_validator') ? $container->get('search_api_postgresql.configuration_validator') : NULL,
          $container->has('logger.channel.search_api_postgresql') ? $container->get('logger.channel.search_api_postgresql') : NULL
      );
  }

  /**
   * Tests AI service connectivity for a server.
   *
   * @param string $server_id
   *   The server ID.
   *
   * @command search-api-postgresql:test-ai
   * @aliases sap-test-ai
   * @usage search-api-postgresql:test-ai my_server
   *   Tests AI service for the specified server.
   */
  public function testAi($server_id) {
    if (!$this->validationService) {
      throw new \Exception(dt('Validation service not available. Please ensure the module is properly installed.'));
    }

    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);

    if (!$server) {
      throw new \Exception(dt('Server @server not found.', ['@server' => $server_id]));
    }

    $this->io()->title(dt('Testing AI Service for @server', ['@server' => $server->label()]));

    $result = $this->validationService->testAiService($server);

    if ($result['success']) {
      $this->io()->success($result['message']);
      if (!empty($result['details'])) {
        $this->io()->text($result['details']);
      }
    }
    else {
      $this->io()->error($result['message']);
      if (!empty($result['details'])) {
        $this->io()->text($result['details']);
      }
    }
  }

  /**
   * Regenerates embeddings for an index.
   *
   * @param string $index_id
   *   The index ID.
   * @param array $options
   *   Command options.
   *
   * @command search-api-postgresql:regenerate-embeddings
   * @option batch-size Number of items per batch
   * @option force Force regeneration of existing embeddings
   * @aliases sap-regen
   * @usage search-api-postgresql:regenerate-embeddings my_index --batch-size=50
   *   Regenerates embeddings for the specified index.
   */
  public function regenerateEmbeddings($index_id, $options = ['batch-size' => 10, 'force' => FALSE]) {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);

    if (!$index) {
      throw new \Exception(dt('Index @index not found.', ['@index' => $index_id]));
    }

    $server = $index->getServerInstance();
    if (!$server) {
      throw new \Exception(dt('No server configured for index @index.', ['@index' => $index_id]));
    }

    $this->io()->title(dt('Regenerating embeddings for @index', ['@index' => $index->label()]));

    // Queue items for regeneration.
    $items_queued = $this->queueManager->queueIndexRegeneration($index_id, [
      'batch_size' => $options['batch-size'],
      'force' => $options['force'],
    ]);

    $this->io()->success(dt('@count items queued for embedding regeneration.', ['@count' => $items_queued]));
  }

  /**
   * Shows embedding statistics for an index.
   *
   * @command search-api-postgresql:embedding-stats
   * @param string $index_id
   *   The index ID.
   *
   * @aliases sap-stats
   * @usage search-api-postgresql:embedding-stats my_index
   *   Shows embedding statistics for the specified index.
   */
  public function embeddingStats($index_id) {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);

    if (!$index) {
      throw new \Exception(dt('Index @index not found.', ['@index' => $index_id]));
    }

    $this->io()->title(dt('Embedding Statistics for @index', ['@index' => $index->label()]));

    // This would need to be implemented based on your analytics service.
    $stats = [
      'total_items' => $index->getTrackerInstance()->getTotalItemsCount(),
      'indexed_items' => $index->getTrackerInstance()->getIndexedItemsCount(),
    // Would come from analytics.
      'embeddings_generated' => 0,
    // Would come from analytics.
      'cache_hit_rate' => 0,
    ];

    $rows = [];
    foreach ($stats as $label => $value) {
      $rows[] = [str_replace('_', ' ', ucfirst($label)), $value];
    }

    $this->io()->table(['Metric', 'Value'], $rows);
  }

  /**
   * Shows queue status.
   *
   * @command search-api-postgresql:queue-status
   * @aliases sap-queue
   * @usage search-api-postgresql:queue-status
   *   Shows the current queue status.
   */
  public function queueStatus() {
    $this->io()->title('Embedding Queue Status');

    $stats = $this->queueManager->getQueueStats();

    if (!empty($stats['error'])) {
      $this->io()->error($stats['error']);
      return;
    }

    $rows = [
      ['Queue Name', $stats['queue_name']],
      ['Pending Items', $stats['items_pending']],
      ['Enabled', $stats['config']['enabled'] ? 'Yes' : 'No'],
      ['Batch Size', $stats['config']['batch_size'] ?? 'Not set'],
    ];

    $this->io()->table(['Property', 'Value'], $rows);

    if (!empty($stats['operation_distribution'])) {
      $this->io()->section('Operation Distribution');
      $rows = [];
      foreach ($stats['operation_distribution'] as $op => $count) {
        $rows[] = [$op, $count];
      }
      $this->io()->table(['Operation', 'Count'], $rows);
    }
  }

  /**
   * Processes queue items.
   *
   * @command search-api-postgresql:queue-process
   * @option max-items Maximum items to process
   * @option time-limit Time limit in seconds
   * @aliases sap-process
   * @usage search-api-postgresql:queue-process --max-items=50
   *   Processes up to 50 queue items.
   */
  public function queueProcess($options = ['max-items' => 50, 'time-limit' => 60]) {
    $this->io()->title('Processing Embedding Queue');

    $result = $this->queueManager->processQueue([
      'max_items' => $options['max-items'],
      'max_time' => $options['time-limit'],
    ]);

    $this->io()->success(dt('Processed @processed items, @failed failed.', [
      '@processed' => $result['processed'],
      '@failed' => $result['failed'],
    ]));

    if (!empty($result['errors'])) {
      $this->io()->warning('Some items failed:');
      foreach ($result['errors'] as $error) {
        $this->io()->text('- ' . $error);
      }
    }
  }

  /**
   * Shows cache statistics.
   *
   * @command search-api-postgresql:cache-stats
   * @param string $server_id
   *   The server ID.
   *
   * @aliases sap-cache
   * @usage search-api-postgresql:cache-stats my_server
   *   Shows cache statistics for the server.
   */
  public function cacheStats($server_id) {
    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);

    if (!$server) {
      throw new \Exception(dt('Server @server not found.', ['@server' => $server_id]));
    }

    $this->io()->title(dt('Cache Statistics for @server', ['@server' => $server->label()]));

    $stats = $this->cacheManager->getCacheStatistics();

    $rows = [
      ['Total Entries', $stats['total_entries']],
      ['Total Size', format_size($stats['total_size'])],
      ['Hit Rate', sprintf('%.2f%%', $stats['hit_rate'] * 100)],
      ['Avg Entry Size', format_size($stats['avg_entry_size'])],
      ['Oldest Entry', $stats['oldest_entry'] ? date('Y-m-d H:i:s', $stats['oldest_entry']) : 'N/A'],
    ];

    $this->io()->table(['Metric', 'Value'], $rows);
  }

  /**
   * Clears the embedding cache.
   *
   * @command search-api-postgresql:cache-clear
   * @param string $server_id
   *   The server ID.
   *
   * @option confirm Skip confirmation
   * @aliases sap-cache-clear
   * @usage search-api-postgresql:cache-clear my_server
   *   Clears the cache for the specified server.
   */
  public function cacheClear($server_id, $options = ['confirm' => FALSE]) {
    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);

    if (!$server) {
      throw new \Exception(dt('Server @server not found.', ['@server' => $server_id]));
    }

    if (!$options['confirm']) {
      $confirm = $this->io()->confirm(dt('Are you sure you want to clear the cache for @server?', [
        '@server' => $server->label(),
      ]));

      if (!$confirm) {
        $this->io()->text('Operation cancelled.');
        return;
      }
    }

    $count = $this->cacheManager->clear($server_id);
    $this->io()->success(dt('Cleared @count cache entries.', ['@count' => $count]));
  }

  /**
   * Validates server configuration.
   *
   * @command search-api-postgresql:validate
   * @param string $server_id
   *   The server ID.
   *
   * @aliases sap-validate
   * @usage search-api-postgresql:validate my_server
   *   Validates the server configuration.
   */
  public function validate($server_id) {
    $server = $this->entityTypeManager->getStorage('search_api_server')->load($server_id);

    if (!$server) {
      throw new \Exception(dt('Server @server not found.', ['@server' => $server_id]));
    }

    $this->io()->title(dt('Validating @server', ['@server' => $server->label()]));

    $health_check = $this->validationService->runComprehensiveTests($server);

    // Display configuration results.
    if (!empty($health_check['configuration'])) {
      if ($health_check['configuration']['success']) {
        $this->io()->success('Configuration: Valid');
      }
      else {
        $this->io()->error('Configuration: Has issues');
        foreach ($health_check['configuration']['errors'] as $error) {
          $this->io()->text('  Error: ' . $error);
        }
      }
    }

    // Display health results.
    if (!empty($health_check['health'])) {
      foreach ($health_check['health'] as $test_name => $result) {
        if ($test_name === 'overall') {
          continue;
        }
        if ($result['success'] ?? FALSE) {
          $this->io()->success($test_name . ': ' . ($result['message'] ?? 'OK'));
        }
        else {
          $this->io()->error($test_name . ': ' . ($result['message'] ?? 'Failed'));
        }
      }
    }

    // Display overall result.
    if ($health_check['overall']['success'] ?? FALSE) {
      $this->io()->success('Overall: Server configuration is valid.');
    }
    else {
      $this->io()->error('Overall: Server configuration has issues.');
    }
  }

}
