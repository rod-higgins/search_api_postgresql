<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Drupal\search_api_postgresql\Service\QueuedEmbeddingService;

/**
 * Enhanced IndexManager with queue-based embedding processing.
 */
class EnhancedIndexManager extends IndexManager {

  /**
   * The embedding queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * The queued embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\QueuedEmbeddingService
   */
  protected $queuedEmbeddingService;

  /**
   * Whether to use queue processing for embeddings.
   *
   * @var bool
   */
  protected $useQueueProcessing;

  /**
   * Current server ID for queue context.
   *
   * @var string
   */
  protected $serverId;

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config, $embedding_service = NULL, $server_id = NULL) {
    // Call parent with only the 3 parameters it expects
    parent::__construct($connector, $field_mapper, $config);
    
    // Handle embedding service in the enhanced version
    $this->embeddingService = $embedding_service;
    $this->serverId = $server_id;
    $this->initializeQueueServices();
    $this->useQueueProcessing = $this->shouldUseQueueProcessing();
  }

  /**
   * Initializes queue-related services.
   */
  protected function initializeQueueServices() {
    try {
      $this->queueManager = \Drupal::service('search_api_postgresql.embedding_queue_manager');
      $this->queuedEmbeddingService = \Drupal::service('search_api_postgresql.queued_embedding_service');
      
      // Set the actual embedding service for fallback
      if ($this->embeddingService) {
        $this->queuedEmbeddingService->setEmbeddingService($this->embeddingService);
      }
    }
    catch (\Exception $e) {
      // Queue services not available, fall back to synchronous processing
      $this->queueManager = NULL;
      $this->queuedEmbeddingService = NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    if ($this->useQueueProcessing && $this->canUseQueueForIndex($index)) {
      return $this->indexItemsWithQueue($index, $items);
    }

    // Fall back to synchronous processing
    return parent::indexItems($index, $items);
  }

  /**
   * Indexes items using queue-based embedding processing.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   The items to index.
   *
   * @return array
   *   Array of successfully indexed item IDs.
   */
  protected function indexItemsWithQueue(IndexInterface $index, array $items) {
    $table_name = $this->getIndexTableName($index);
    $indexed_items = [];

    $this->connector->beginTransaction();

    try {
      // Process items and collect embedding tasks
      $embedding_tasks = [];
      
      foreach ($items as $item_id => $item) {
        // Index item without embedding first
        $this->indexItemWithoutEmbedding($table_name, $index, $item);
        $indexed_items[] = $item_id;
        
        // Collect embedding text for queue processing
        if ($this->isVectorSearchEnabled()) {
          $embedding_text = $this->generateEmbeddingText($item, $index);
          if (!empty($embedding_text)) {
            $embedding_tasks[$item_id] = $embedding_text;
          }
        }
      }

      $this->connector->commit();

      // Queue embedding generation tasks
      if (!empty($embedding_tasks)) {
        $this->queueEmbeddingTasks($index, $embedding_tasks);
      }

    }
    catch (\Exception $e) {
      $this->connector->rollback();
      throw new SearchApiException('Failed to index items with queue: ' . $e->getMessage(), $e->getCode(), $e);
    }

    return $indexed_items;
  }

  /**
   * Indexes an item without generating embeddings.
   *
   * @param string $table_name
   *   The index table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   */
  protected function indexItemWithoutEmbedding($table_name, IndexInterface $index, ItemInterface $item) {
    $fields = $item->getFields(TRUE);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Prepare searchable text for tsvector only
    $searchable_text = '';
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);

    foreach ($fields as $field_id => $field) {
      // Validate field ID
      $this->connector->validateIdentifier($field_id, 'field ID');
      
      $field_values = $field->getValues();
      $field_type = $field->getType();

      if (!empty($field_values)) {
        $value = reset($field_values);
        $values[$field_id] = $this->fieldMapper->prepareFieldValue($value, $field_type);

        // Collect text for full-text search
        if (in_array($field_id, $searchable_fields)) {
          $searchable_text .= ' ' . $this->fieldMapper->prepareFieldValue($value, 'text');
        }
      }
      else {
        $values[$field_id] = NULL;
      }
    }

    // Prepare tsvector value
    $fts_config = $this->validateFtsConfiguration();
    $search_vector_field = $this->connector->validateFieldName('search_vector');
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";

    // Set embedding field to NULL initially (will be updated via queue)
    if ($this->isVectorSearchEnabled()) {
      $values['content_embedding'] = NULL;
    }

    // Delete existing item
    $id_field = $this->connector->validateFieldName('search_api_id');
    $delete_sql = "DELETE FROM {$table_name} WHERE {$id_field} = :item_id";
    $this->connector->executeQuery($delete_sql, [':item_id' => $item->getId()]);

    // Insert new item
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($values as $key => $value) {
      $safe_column = $this->connector->validateFieldName($key);
      $columns[] = $safe_column;
      
      if ($key === 'search_vector') {
        $placeholders[] = $value; // Raw SQL expression
      }
      else {
        $placeholder = ":{$key}";
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
      }
    }

    $params[':searchable_text'] = trim($searchable_text);

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $this->connector->executeQuery($insert_sql, $params);
  }

  /**
   * Generates embedding text from an item.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The search API item.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The combined embedding text.
   */
  protected function generateEmbeddingText(ItemInterface $item, IndexInterface $index) {
    $embedding_source_fields = $this->fieldMapper->getEmbeddingSourceFields($index);
    $text_parts = [];

    foreach ($item->getFields(TRUE) as $field_id => $field) {
      if (in_array($field_id, $embedding_source_fields)) {
        $values = $field->getValues();
        if (!empty($values)) {
          $value = reset($values);
          $text_parts[] = $this->fieldMapper->prepareFieldValue($value, 'text');
        }
      }
    }

    return trim(implode(' ', array_filter($text_parts)));
  }

  /**
   * Queues embedding generation tasks.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $embedding_tasks
   *   Array of item_id => text_content pairs.
   */
  protected function queueEmbeddingTasks(IndexInterface $index, array $embedding_tasks) {
    if (!$this->queueManager || !$this->serverId) {
      return;
    }

    // Set queue context
    $this->queuedEmbeddingService->setQueueContext([
      'server_id' => $this->serverId,
      'index_id' => $index->id(),
      'items' => $embedding_tasks,
      'batch_mode' => count($embedding_tasks) > 1,
      'priority' => 'normal',
    ]);

    // Determine if we should use batch processing
    $use_batch = count($embedding_tasks) >= ($this->config['queue_batch_threshold'] ?? 5);

    if ($use_batch) {
      $success = $this->queueManager->queueBatchEmbeddingGeneration(
        $this->serverId,
        $index->id(),
        $embedding_tasks
      );
    } else {
      $success = TRUE;
      foreach ($embedding_tasks as $item_id => $text_content) {
        if (!$this->queueManager->queueEmbeddingGeneration(
          $this->serverId,
          $index->id(),
          $item_id,
          $text_content
        )) {
          $success = FALSE;
          break;
        }
      }
    }

    if ($success) {
      \Drupal::logger('search_api_postgresql')->info('Queued @count embedding tasks for index @index', [
        '@count' => count($embedding_tasks),
        '@index' => $index->id()
      ]);
    } else {
      \Drupal::logger('search_api_postgresql')->error('Failed to queue embedding tasks for index @index', [
        '@index' => $index->id()
      ]);
    }
  }

  /**
   * Processes embeddings synchronously for urgent items.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $items
   *   Array of items to process.
   * @param array $options
   *   Processing options.
   *
   * @return array
   *   Processing results.
   */
  public function processEmbeddingsSync(IndexInterface $index, array $items, array $options = []) {
    if (!$this->embeddingService) {
      throw new SearchApiException('No embedding service available for synchronous processing');
    }

    $table_name = $this->getIndexTableName($index);
    $results = ['processed' => 0, 'failed' => 0, 'errors' => []];

    $this->connector->beginTransaction();

    try {
      foreach ($items as $item_id => $text_content) {
        try {
          $embedding = $this->embeddingService->generateEmbedding($text_content);
          
          if ($embedding) {
            $this->updateItemEmbedding($table_name, $item_id, $embedding);
            $results['processed']++;
          } else {
            $results['failed']++;
            $results['errors'][] = "Failed to generate embedding for item: {$item_id}";
          }
        }
        catch (\Exception $e) {
          $results['failed']++;
          $results['errors'][] = "Error processing item {$item_id}: " . $e->getMessage();
        }
      }

      $this->connector->commit();
    }
    catch (\Exception $e) {
      $this->connector->rollback();
      throw new SearchApiException('Failed to process embeddings synchronously: ' . $e->getMessage(), $e->getCode(), $e);
    }

    return $results;
  }

  /**
   * Updates an item's embedding in the database.
   *
   * @param string $table_name
   *   The index table name (quoted).
   * @param string $item_id
   *   The item ID.
   * @param array $embedding
   *   The embedding vector.
   */
  protected function updateItemEmbedding($table_name, $item_id, array $embedding) {
    $vector_field = $this->connector->validateFieldName('content_embedding');
    $id_field = $this->connector->validateFieldName('search_api_id');
    
    $sql = "UPDATE {$table_name} SET {$vector_field} = :embedding WHERE {$id_field} = :item_id";
    $params = [
      ':embedding' => '[' . implode(',', $embedding) . ']',
      ':item_id' => $item_id,
    ];
    
    $this->connector->executeQuery($sql, $params);
  }

  /**
   * Gets embedding processing statistics for the index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Processing statistics.
   */
  public function getEmbeddingProcessingStats(IndexInterface $index) {
    $table_name = $this->getIndexTableNameUnquoted($index);
    
    $stats = [
      'queue_enabled' => $this->useQueueProcessing,
      'server_id' => $this->serverId,
    ];

    try {
    
      // Get total items
      $total_sql = "SELECT COUNT(*) as total FROM {$table_name}";
      $total_stmt = $this->connector->executeQuery($total_sql);
      $stats['total_items'] = $total_stmt->fetchColumn();

      $columns = $this->connector->getTableColumns($table_name);
      if (in_array('content_embedding', $columns)) {
        // Only query embedding column if it exists
        $embedded_sql = "SELECT COUNT(*) as embedded FROM {$table_name} WHERE content_embedding IS NOT NULL";
        $embedded_stmt = $this->connector->executeQuery($embedded_sql);
        $stats['items_with_embeddings'] = $embedded_stmt->fetchColumn();
      } else {
        // For non-AI backends, no items have embeddings
        $stats['items_with_embeddings'] = 0;
      }

      // Calculate coverage
      $stats['embedding_coverage'] = $stats['total_items'] > 0 
        ? ($stats['items_with_embeddings'] / $stats['total_items']) * 100 
        : 0;

      // Get queue stats if available
      if ($this->queueManager) {
        $queue_stats = $this->queueManager->getQueueStats();
        $stats['queue_items_pending'] = $queue_stats['items_pending'] ?? 0;
      }

    } catch (\Exception $e) {
      $stats['error'] = $e->getMessage();
    }

    return $stats;
  }

  /**
   * Forces synchronous processing for the next operation.
   *
   * @param bool $force_sync
   *   Whether to force synchronous processing.
   */
  public function setForceSynchronousProcessing($force_sync = TRUE) {
    $this->useQueueProcessing = !$force_sync;
  }

  /**
   * Checks if queue processing should be used.
   *
   * @return bool
   *   TRUE if queue processing should be used.
   */
  protected function shouldUseQueueProcessing() {
    // Check if queue services are available
    if (!$this->queueManager || !$this->queuedEmbeddingService) {
      return FALSE;
    }

    // Check global queue setting
    if (!($this->config['queue_processing']['enabled'] ?? FALSE)) {
      return FALSE;
    }

    // Check server-specific setting
    if ($this->serverId && !$this->queueManager->isQueueEnabledForServer($this->serverId)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if queue processing can be used for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return bool
   *   TRUE if queue can be used.
   */
  protected function canUseQueueForIndex(IndexInterface $index) {
    // Check if vector search is enabled
    if (!$this->isVectorSearchEnabled()) {
      return FALSE;
    }

    // Check if index has embedding-capable fields
    $embedding_fields = $this->fieldMapper->getEmbeddingSourceFields($index);
    if (empty($embedding_fields)) {
      return FALSE;
    }

    return TRUE;
  }

}