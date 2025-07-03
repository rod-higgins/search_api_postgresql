<?php

namespace Drupal\search_api_postgresql\Queue;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages embedding generation queues.
 */
class EmbeddingQueueManager {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The embedding queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Queue configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs an EmbeddingQueueManager.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(QueueFactory $queue_factory, LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    $this->queueFactory = $queue_factory;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    
    $this->config = $this->configFactory->get('search_api_postgresql.queue_settings')->get() ?: [];
    $this->config += $this->getDefaultQueueConfig();
    
    $this->queue = $this->queueFactory->get('search_api_postgresql_embedding');
  }

  /**
   * Queues a single embedding generation task.
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
   *
   * @return bool
   *   TRUE if successfully queued.
   */
  public function queueEmbeddingGeneration($server_id, $index_id, $item_id, $text_content, $priority = 100) {
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
        '@index' => $index_id
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to queue embedding generation: @message', [
        '@message' => $e->getMessage()
      ]);
      return FALSE;
    }
  }

  /**
   * Queues batch embedding generation.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $index_id
   *   The index ID.
   * @param array $items
   *   Array of item_id => text_content pairs.
   * @param int $priority
   *   Task priority.
   *
   * @return bool
   *   TRUE if successfully queued.
   */
  public function queueBatchEmbeddingGeneration($server_id, $index_id, array $items, $priority = 100) {
    if (empty($items)) {
      return TRUE;
    }

    // Split large batches if necessary
    $batch_size = $this->config['batch_size'];
    $batches = array_chunk($items, $batch_size, TRUE);

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
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to queue batch embedding generation: @message', [
          '@message' => $e->getMessage()
        ]);
        return FALSE;
      }
    }

    $this->logger->info('Queued @count items for batch embedding generation in @batches batches', [
      '@count' => $queued_count,
      '@batches' => count($batches)
    ]);

    return TRUE;
  }

  /**
   * Queues index embedding regeneration.
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
   *
   * @return bool
   *   TRUE if successfully queued.
   */
  public function queueIndexEmbeddingRegeneration($server_id, $index_id, $batch_size = 50, $offset = 0, $priority = 200) {
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
        '@offset' => $offset
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to queue embedding regeneration: @message', [
        '@message' => $e->getMessage()
      ]);
      return FALSE;
    }
  }

  /**
   * Queues embedding generation for index items.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $items
   *   Array of search API items.
   * @param bool $use_batch
   *   Whether to use batch processing.
   *
   * @return bool
   *   TRUE if successfully queued.
   */
  public function queueIndexItems(IndexInterface $index, array $items, $use_batch = TRUE) {
    if (empty($items)) {
      return TRUE;
    }

    $server = $index->getServerInstance();
    $server_id = $server->id();
    $index_id = $index->id();

    if (!$this->isQueueEnabledForServer($server_id)) {
      return FALSE;
    }

    // Extract text content from items
    $embedding_items = [];
    foreach ($items as $item_id => $item) {
      $text_content = $this->extractTextFromItem($item, $index);
      if (!empty($text_content)) {
        $embedding_items[$item_id] = $text_content;
      }
    }

    if (empty($embedding_items)) {
      return TRUE;
    }

    if ($use_batch && count($embedding_items) > 1) {
      return $this->queueBatchEmbeddingGeneration($server_id, $index_id, $embedding_items);
    } else {
      $success = TRUE;
      foreach ($embedding_items as $item_id => $text_content) {
        if (!$this->queueEmbeddingGeneration($server_id, $index_id, $item_id, $text_content)) {
          $success = FALSE;
        }
      }
      return $success;
    }
  }

  /**
   * Gets queue statistics.
   *
   * @return array
   *   Queue statistics.
   */
  public function getQueueStats() {
    try {
      $stats = [
        'queue_name' => 'search_api_postgresql_embedding',
        'items_pending' => $this->queue->numberOfItems(),
        'config' => $this->config,
      ];

      // Get additional stats from database if using DatabaseQueue
      if (method_exists($this->queue, 'schemaDefinition')) {
        $connection = \Drupal::database();
        
        // FIX: Use the queue name directly instead of calling getName()
        $table_name = 'queue_search_api_postgresql_embedding';
        
        if ($connection->schema()->tableExists($table_name)) {
          // Get priority distribution
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
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get queue stats: @message', ['@message' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }
  
  /**
   * Processes queue items manually.
   *
   * @param int $max_items
   *   Maximum number of items to process.
   * @param int $time_limit
   *   Time limit in seconds.
   *
   * @return array
   *   Processing results.
   */
  public function processQueue($max_items = 50, $time_limit = 60) {
    $start_time = time();
    $processed = 0;
    $failed = 0;
    $errors = [];

    $this->logger->info('Starting manual queue processing: max @max items, time limit @time seconds', [
      '@max' => $max_items,
      '@time' => $time_limit
    ]);

    while ($processed < $max_items && (time() - $start_time) < $time_limit) {
      $item = $this->queue->claimItem();
      
      if (!$item) {
        // No more items in queue
        break;
      }

      try {
        $worker = \Drupal::service('plugin.manager.queue_worker')
          ->createInstance('search_api_postgresql_embedding');
        
        $worker->processItem($item->data);
        $this->queue->deleteItem($item);
        $processed++;
        
      }
      catch (\Exception $e) {
        $this->queue->releaseItem($item);
        $failed++;
        $errors[] = $e->getMessage();
        
        $this->logger->error('Failed to process queue item: @message', [
          '@message' => $e->getMessage()
        ]);
      }
    }

    $results = [
      'processed' => $processed,
      'failed' => $failed,
      'elapsed_time' => time() - $start_time,
      'remaining_items' => $this->queue->numberOfItems(),
    ];

    if (!empty($errors)) {
      $results['errors'] = array_slice($errors, 0, 10); // Limit error list
    }

    $this->logger->info('Queue processing completed: @processed processed, @failed failed, @remaining remaining', [
      '@processed' => $processed,
      '@failed' => $failed,
      '@remaining' => $results['remaining_items']
    ]);

    return $results;
  }

  /**
   * Clears the queue.
   *
   * @return bool
   *   TRUE if successfully cleared.
   */
  public function clearQueue() {
    try {
      $count = $this->queue->numberOfItems();
      $this->queue->deleteQueue();
      
      $this->logger->info('Cleared embedding queue: @count items removed', ['@count' => $count]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear queue: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Checks if queue processing is enabled for a server.
   *
   * @param string $server_id
   *   The server ID.
   *
   * @return bool
   *   TRUE if queue processing is enabled.
   */
  public function isQueueEnabledForServer($server_id) {
    // Check global queue setting
    if (!($this->config['enabled'] ?? FALSE)) {
      return FALSE;
    }

    // Check server-specific setting
    $server_config = $this->config['servers'][$server_id] ?? [];
    return $server_config['enabled'] ?? $this->config['default_enabled'] ?? TRUE;
  }

  /**
   * Enables or disables queue processing for a server.
   *
   * @param string $server_id
   *   The server ID.
   * @param bool $enabled
   *   Whether to enable queue processing.
   */
  public function setQueueEnabledForServer($server_id, $enabled) {
    $config = $this->configFactory->getEditable('search_api_postgresql.queue_settings');
    $config->set("servers.{$server_id}.enabled", $enabled);
    $config->save();
    
    // Update local config
    $this->config = $config->get() ?: [];
    $this->config += $this->getDefaultQueueConfig();
  }

  /**
   * Extracts text content from a search API item.
   *
   * @param mixed $item
   *   The search API item.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractTextFromItem($item, IndexInterface $index) {
    $text_parts = [];
    
    foreach ($item->getFields(TRUE) as $field_id => $field) {
      $field_type = $field->getType();
      
      // Only include text-based fields
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
   *
   * @return array
   *   Default configuration.
   */
  protected function getDefaultQueueConfig() {
    return [
      'enabled' => FALSE,
      'default_enabled' => TRUE,
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
   *
   * @return \Drupal\Core\Queue\QueueInterface
   *   The queue instance.
   */
  public function getQueue() {
    return $this->queue;
  }

}