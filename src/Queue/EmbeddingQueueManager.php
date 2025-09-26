<?php

namespace Drupal\search_api_postgresql\Queue;

use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages embedding generation queues.
 */
class EmbeddingQueueManager
{
  /**
   * The queue factory.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The embedding queue.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The logger.
   * {@inheritdoc}
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Queue configuration.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs an EmbeddingQueueManager.
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
      QueueFactory $queue_factory,
      LoggerInterface $logger,
      ConfigFactoryInterface $config_factory
  ) {
    $this->queueFactory = $queue_factory;
    $this->logger = $logger;
    $this->configFactory = $config_factory;

    $this->config = $this->configFactory->get('search_api_postgresql.queue_settings')->get() ?: [];
    $this->config += $this->getDefaultQueueConfig();

    $this->queue = $this->queueFactory->get('search_api_postgresql_embedding');
  }

  /**
   * Queues a single embedding generation task.
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   * @param string $index_id
   *   The index ID.
   * @param string $item_id
   *   The item ID.
   * @param string $text_content
   *   The text content to embed.
   * @param int $priority
   *   Task priority (lower numbers = higher priority).
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully queued.
   */
  public function queueEmbeddingGeneration($server_id, $index_id, $item_id, $text_content, $priority = 100)
  {
    $data = [
      'operation' => 'generate_embedding',
      'server_id' => $server_id,
      'index_id' => $index_id,
      'item_id' => $item_id,
      'text_content' => $text_content,
      'priority' => $priority,
      'created' => time(),
    ];

    try {
      $this->queue->createItem($data);

      $this->logger->debug('Queued embedding generation for item @item in index @index', [
        '@item' => $item_id,
        '@index' => $index_id,
      ]);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to queue embedding generation: @message', [
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Queues batch embedding generation.
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   * @param string $index_id
   *   The index ID.
   * @param array $items
   *   Array of item_id => text_content pairs.
   * @param int $priority
   *   Task priority.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully queued.
   */
  public function queueBatchEmbeddingGeneration($server_id, $index_id, array $items, $priority = 100)
  {
    if (empty($items)) {
      return true;
    }

    // Split large batches if necessary.
    $batch_size = $this->config['batch_size'];
    $batches = array_chunk($items, $batch_size, true);

    $queued_count = 0;
    foreach ($batches as $batch) {
      $data = [
        'operation' => 'batch_generate_embeddings',
        'server_id' => $server_id,
        'index_id' => $index_id,
        'items' => $batch,
        'priority' => $priority,
        'created' => time(),
      ];

      try {
        $this->queue->createItem($data);
        $queued_count += count($batch);
      } catch (\Exception $e) {
        $this->logger->error('Failed to queue batch embedding generation: @message', [
          '@message' => $e->getMessage(),
        ]);
        return false;
      }
    }

    $this->logger->info('Queued @count items for batch embedding generation in @batches batches', [
      '@count' => $queued_count,
      '@batches' => count($batches),
    ]);

    return true;
  }

  /**
   * Gets recent queue activity.
   * {@inheritdoc}
   *
   * @param int $limit
   *   Number of recent activities to retrieve.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array of recent activity records.
   */
  public function getRecentActivity($limit = 20)
  {
    try {
      // Check if we have database connection.
      if (!$this->queue instanceof DatabaseQueue) {
        // For non-database queues, return empty array.
        return [];
      }

      $database = \Drupal::database();

      // Check if activity log table exists.
      if (!$database->schema()->tableExists('search_api_postgresql_queue_activity')) {
        // Return empty array if activity tracking table doesn't exist.
        return [];
      }

      // Query recent activity from activity log table.
      $query = $database->select('search_api_postgresql_queue_activity', 'qa')
        ->fields('qa', ['timestamp', 'operation', 'items_processed', 'status', 'duration'])
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit);

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      return $results ?: [];
    } catch (\Exception $e) {
      $this->logger->warning('Failed to get recent queue activity: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Return empty array on error to prevent form from breaking.
      return [];
    }
  }

  /**
   * Logs queue activity.
   * {@inheritdoc}
   *
   * @param string $operation
   *   The operation type.
   * @param int $items_processed
   *   Number of items processed.
   * @param string $status
   *   Operation status.
   * @param int $duration
   *   Operation duration in milliseconds.
   */
  protected function logActivity($operation, $items_processed = 0, $status = 'success', $duration = 0)
  {
    try {
      $database = \Drupal::database();

      // Create activity log table if it doesn't exist.
      $this->ensureActivityLogTable();

      $database->insert('search_api_postgresql_queue_activity')
        ->fields([
          'timestamp' => time(),
          'operation' => $operation,
          'items_processed' => $items_processed,
          'status' => $status,
          'duration' => $duration,
        ])
        ->execute();
    } catch (\Exception $e) {
      $this->logger->warning('Failed to log queue activity: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Ensures the activity log table exists.
   */
  protected function ensureActivityLogTable()
  {
    $database = \Drupal::database();
    $schema = $database->schema();

    if (!$schema->tableExists('search_api_postgresql_queue_activity')) {
      $table_spec = [
        'description' => 'Stores queue activity logs for Search API PostgreSQL',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'unsigned' => true,
            'not null' => true,
          ],
          'timestamp' => [
            'type' => 'int',
            'unsigned' => true,
            'not null' => true,
            'default' => 0,
          ],
          'operation' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => true,
            'default' => '',
          ],
          'items_processed' => [
            'type' => 'int',
            'unsigned' => true,
            'not null' => true,
            'default' => 0,
          ],
          'status' => [
            'type' => 'varchar',
            'length' => 50,
            'not null' => true,
            'default' => 'success',
          ],
          'duration' => [
            'type' => 'int',
            'unsigned' => true,
            'not null' => true,
            'default' => 0,
          ],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'timestamp' => ['timestamp'],
          'operation' => ['operation'],
        ],
      ];

      $schema->createTable('search_api_postgresql_queue_activity', $table_spec);
    }
  }

  /**
   * Queues index embedding regeneration.
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   * @param string $index_id
   *   The index ID.
   * @param int $batch_size
   *   The batch size for processing.
   * @param int $offset
   *   The offset to start from.
   * @param int $priority
   *   Task priority.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully queued.
   */
  public function queueIndexEmbeddingRegeneration($server_id, $index_id, $batch_size = 50, $offset = 0, $priority = 200)
  {
    $data = [
      'operation' => 'regenerate_index_embeddings',
      'server_id' => $server_id,
      'index_id' => $index_id,
      'batch_size' => $batch_size,
      'offset' => $offset,
      'priority' => $priority,
      'created' => time(),
    ];

    try {
      $this->queue->createItem($data);

      $this->logger->info('Queued embedding regeneration for index @index (batch size: @batch_size, offset: @offset)', [
        '@index' => $index_id,
        '@batch_size' => $batch_size,
        '@offset' => $offset,
      ]);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to queue embedding regeneration: @message', [
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Queues embedding generation for index items.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $items
   *   Array of search API items.
   * @param bool $use_batch
   *   Whether to use batch processing.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully queued.
   */
  public function queueIndexItems(IndexInterface $index, array $items, $use_batch = true)
  {
    if (empty($items)) {
      return true;
    }

    $server = $index->getServerInstance();
    $server_id = $server->id();
    $index_id = $index->id();

    if (!$this->isQueueEnabledForServer($server_id)) {
      return false;
    }

    // Extract text content from items.
    $embedding_items = [];
    foreach ($items as $item_id => $item) {
      $text_content = $this->extractTextFromItem($item, $index);
      if (!empty($text_content)) {
        $embedding_items[$item_id] = $text_content;
      }
    }

    if (empty($embedding_items)) {
      return true;
    }

    if ($use_batch && count($embedding_items) > 1) {
      return $this->queueBatchEmbeddingGeneration($server_id, $index_id, $embedding_items);
    } else {
      $success = true;
      foreach ($embedding_items as $item_id => $text_content) {
        if (!$this->queueEmbeddingGeneration($server_id, $index_id, $item_id, $text_content)) {
          $success = false;
        }
      }
      return $success;
    }
  }

  /**
   * Gets queue statistics.
   * {@inheritdoc}
   *
   * @return array
   *   Queue statistics.
   */
  public function getQueueStats()
  {
    try {
      $stats = [
        'queue_name' => 'search_api_postgresql_embedding',
        'items_pending' => $this->queue->numberOfItems(),
        'config' => $this->config,
      ];

      // Get additional stats from database if using DatabaseQueue.
      if (method_exists($this->queue, 'schemaDefinition')) {
        $connection = \Drupal::database();

        // FIX: Use the queue name directly instead of calling getName()
        $table_name = 'queue_search_api_postgresql_embedding';

        if ($connection->schema()->tableExists($table_name)) {
          // Get priority distribution.
          $priority_stats = $connection->select($table_name, 'q')
            ->fields('q', ['data'])
            ->execute()
            ->fetchAll();

          $priorities = [];
          $operations = [];

          foreach ($priority_stats as $row) {
            $data = unserialize($row->data);
            $priority = $data['priority'] ?? 100;
            $operation = $data['operation'] ?? 'unknown';

            $priorities[$priority] = ($priorities[$priority] ?? 0) + 1;
            $operations[$operation] = ($operations[$operation] ?? 0) + 1;
          }

          $stats['priority_distribution'] = $priorities;
          $stats['operation_distribution'] = $operations;
        }
      }

      return $stats;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get queue stats: @message', ['@message' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Processes queue items manually.
   * {@inheritdoc}
   *
   * @param int $max_items
   *   Maximum number of items to process.
   * @param int $time_limit
   *   Time limit in seconds.
   *   {@inheritdoc}.
   *
   * @return array
   *   Processing results.
   */
  public function processQueue($max_items = 50, $time_limit = 60)
  {
    $start_time = time();
    $start_microtime = microtime(true);
    $processed = 0;
    $failed = 0;
    $errors = [];

    $this->logger->info('Starting manual queue processing: max @max items, time limit @time seconds', [
      '@max' => $max_items,
      '@time' => $time_limit,
    ]);

    while ($processed < $max_items && (time() - $start_time) < $time_limit) {
      $item = $this->queue->claimItem();

      if (!$item) {
        // No more items in queue.
        break;
      }

      try {
        $worker = \Drupal::service('plugin.manager.queue_worker')
          ->createInstance('search_api_postgresql_embedding');

        $worker->processItem($item->data);
        $this->queue->deleteItem($item);
        $processed++;
      } catch (\Exception $e) {
        $this->queue->releaseItem($item);
        $failed++;
        $errors[] = $e->getMessage();

        $this->logger->error('Failed to process queue item: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Convert to milliseconds.
    $duration = round((microtime(true) - $start_microtime) * 1000);
    $results = [
      'processed' => $processed,
      'failed' => $failed,
      'elapsed_time' => time() - $start_time,
      'remaining_items' => $this->queue->numberOfItems(),
    ];

    if (!empty($errors)) {
      // Limit error list.
      $results['errors'] = array_slice($errors, 0, 10);
    }

    // Log activity.
    $status = ($failed > 0) ? 'partial_failure' : 'success';
    $this->logActivity('manual_processing', $processed, $status, $duration);

    $this->logger->info('Queue processing completed: @processed processed, @failed failed, @remaining remaining', [
      '@processed' => $processed,
      '@failed' => $failed,
      '@remaining' => $results['remaining_items'],
    ]);

    return $results;
  }

  /**
   * Clears failed items from the queue.
   * {@inheritdoc}
   *
   * @return array
   *   Results with 'cleared' count.
   */
  public function clearFailedItems()
  {
    $start_microtime = microtime(true);
    $cleared = 0;

    try {
      // For database queues, we would need to implement failed item tracking
      // For now, we'll just log the activity.
      $this->logger->info('Clearing failed queue items');

      // @todo Implement actual failed item clearing logic
      // This would require tracking failed items in the database.
      $duration = round((microtime(true) - $start_microtime) * 1000);
      $this->logActivity('clear_failed', $cleared, 'success', $duration);

      return ['cleared' => $cleared];
    } catch (\Exception $e) {
      $this->logger->error('Failed to clear failed items: @message', [
        '@message' => $e->getMessage(),
      ]);

      $duration = round((microtime(true) - $start_microtime) * 1000);
      $this->logActivity('clear_failed', 0, 'error', $duration);

      return ['cleared' => 0, 'error' => $e->getMessage()];
    }
  }

  /**
   * Clears all items from the queue.
   * {@inheritdoc}
   *
   * @return array
   *   Results with success status.
   */
  public function clearAllItems()
  {
    $start_microtime = microtime(true);

    try {
      $items_before = $this->queue->numberOfItems();
      $this->queue->deleteQueue();

      $duration = round((microtime(true) - $start_microtime) * 1000);
      $this->logActivity('clear_all', $items_before, 'success', $duration);

      $this->logger->info('Cleared all queue items: @count items removed', [
        '@count' => $items_before,
      ]);

      return ['cleared' => $items_before, 'success' => true];
    } catch (\Exception $e) {
      $this->logger->error('Failed to clear all items: @message', [
        '@message' => $e->getMessage(),
      ]);

      $duration = round((microtime(true) - $start_microtime) * 1000);
      $this->logActivity('clear_all', 0, 'error', $duration);

      return ['cleared' => 0, 'success' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * Pauses queue processing.
   * {@inheritdoc}
   *
   * @return bool
   *   true if successful.
   */
  public function pauseQueue()
  {
    try {
      $config = $this->configFactory->getEditable('search_api_postgresql.queue_settings');
      $config->set('enabled', false);
      $config->save();

      $this->logActivity('pause_queue', 0, 'success', 0);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to pause queue: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->logActivity('pause_queue', 0, 'error', 0);
      return false;
    }
  }

  /**
   * Resumes queue processing.
   * {@inheritdoc}
   *
   * @return bool
   *   true if successful.
   */
  public function resumeQueue()
  {
    try {
      $config = $this->configFactory->getEditable('search_api_postgresql.queue_settings');
      $config->set('enabled', true);
      $config->save();

      $this->logActivity('resume_queue', 0, 'success', 0);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to resume queue: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->logActivity('resume_queue', 0, 'error', 0);
      return false;
    }
  }

  /**
   * Requeues failed items.
   * {@inheritdoc}
   *
   * @return array
   *   Results with 'requeued' count.
   */
  public function requeueFailedItems()
  {
    $start_microtime = microtime(true);
    $requeued = 0;

    try {
      // @todo Implement actual failed item requeuing logic
      // This would require tracking failed items in the database.
      $duration = round((microtime(true) - $start_microtime) * 1000);
      $this->logActivity('requeue_failed', $requeued, 'success', $duration);

      return ['requeued' => $requeued];
    } catch (\Exception $e) {
      $this->logger->error('Failed to requeue failed items: @message', [
        '@message' => $e->getMessage(),
      ]);

      $duration = round((microtime(true) - $start_microtime) * 1000);
      $this->logActivity('requeue_failed', 0, 'error', $duration);

      return ['requeued' => 0, 'error' => $e->getMessage()];
    }
  }

  /**
   * Updates queue configuration.
   * {@inheritdoc}
   *
   * @param array $config
   *   Configuration array.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successful.
   */
  public function updateConfiguration(array $config)
  {
    try {
      $config_object = $this->configFactory->getEditable('search_api_postgresql.queue_settings');

      foreach ($config as $key => $value) {
        $config_object->set($key, $value);
      }

      $config_object->save();

      // Update local config.
      $this->config = array_merge($this->config, $config);

      $this->logActivity('update_config', 0, 'success', 0);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to update queue configuration: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->logActivity('update_config', 0, 'error', 0);
      return false;
    }
  }

  /**
   * Clears the queue.
   * {@inheritdoc}
   *
   * @return bool
   *   true if successfully cleared.
   */
  public function clearQueue()
  {
    try {
      $count = $this->queue->numberOfItems();
      $this->queue->deleteQueue();

      $this->logger->info('Cleared embedding queue: @count items removed', ['@count' => $count]);
      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to clear queue: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Checks if queue processing is enabled for a server.
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if queue processing is enabled.
   */
  public function isQueueEnabledForServer($server_id)
  {
    // Check global queue setting.
    if (!($this->config['enabled'] ?? false)) {
      return false;
    }

    // Check server-specific setting.
    $server_config = $this->config['servers'][$server_id] ?? [];
    return $server_config['enabled'] ?? $this->config['default_enabled'] ?? true;
  }

  /**
   * Enables or disables queue processing for a server.
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   * @param bool $enabled
   *   Whether to enable queue processing.
   */
  public function setQueueEnabledForServer($server_id, $enabled)
  {
    $config = $this->configFactory->getEditable('search_api_postgresql.queue_settings');
    $config->set("servers.{$server_id}.enabled", $enabled);
    $config->save();

    // Update local config.
    $this->config = $config->get() ?: [];
    $this->config += $this->getDefaultQueueConfig();
  }

  /**
   * Extracts text content from a search API item.
   * {@inheritdoc}
   *
   * @param mixed $item
   *   The search API item.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *   {@inheritdoc}.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractTextFromItem($item, IndexInterface $index)
  {
    $text_parts = [];

    foreach ($item->getFields(true) as $field_id => $field) {
      $field_type = $field->getType();

      // Only include text-based fields.
      if (in_array($field_type, ['text', 'postgresql_fulltext', 'string'])) {
        $values = $field->getValues();
        if (!empty($values)) {
          $value = reset($values);
          if (is_string($value)) {
            $text_parts[] = $value;
          }
        }
      }
    }

    return trim(implode(' ', $text_parts));
  }

  /**
   * Gets default queue configuration.
   * {@inheritdoc}
   *
   * @return array
   *   Default configuration.
   */
  protected function getDefaultQueueConfig()
  {
    return [
      'enabled' => false,
      'default_enabled' => true,
      'batch_size' => 10,
      'max_processing_time' => 50,
      'priority_levels' => [
        'high' => 50,
        'normal' => 100,
        'low' => 200,
      ],
      'servers' => [],
    ];
  }

  /**
   * Gets the underlying queue instance.
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The queue instance.
   */
  public function getQueue()
  {
    return $this->queue;
  }
}
