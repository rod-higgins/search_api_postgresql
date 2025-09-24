<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api_postgresql\Exception\GracefulDegradationException;
use Drupal\search_api_postgresql\Exception\PartialBatchFailureException;
use Drupal\search_api_postgresql\Exception\VectorSearchDegradedException;
use Drupal\search_api_postgresql\Exception\DegradationExceptionFactory;
use Drupal\search_api_postgresql\Service\ResilientEmbeddingService;

/**
 * Enhanced IndexManager with graceful degradation for embedding failures.
 */
class ResilientIndexManager extends IndexManager {
  /**
   * Degradation state tracking.
   *
   * @var array
   */
  protected $degradationState = [
    'embedding_failures' => 0,
    'total_items' => 0,
    'fallback_mode' => FALSE,
    'messages' => [],
  ];

  /**
   * Configuration for degradation behavior.
   *
   * @var array
   */
  protected $degradationConfig;

  /**
   * {@inheritdoc}
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config, $embedding_service = NULL) {
    parent::__construct($connector, $field_mapper, $config, $embedding_service);

    $this->degradationConfig = [
    // 50% failure rate triggers fallback
      'embedding_failure_threshold' => 0.5,
      'allow_partial_indexing' => TRUE,
      'continue_on_embedding_failure' => TRUE,
      'max_embedding_retries' => 2,
    // Seconds.
      'embedding_timeout' => 30,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $this->resetDegradationState();
    $this->degradationState['total_items'] = count($items);

    if (!$this->isVectorSearchEnabled()) {
      return $this->indexItemsTextOnly($index, $items);
    }

    try {
      return $this->indexItemsWithEmbeddings($index, $items);
    }
    catch (PartialBatchFailureException $e) {
      return $this->handlePartialIndexingFailure($index, $e);
    }
    catch (GracefulDegradationException $e) {
      return $this->handleIndexingDegradation($index, $items, $e);
    }
    catch (\Exception $e) {
      // Convert unexpected exceptions to graceful degradation.
      $degradation_exception = DegradationExceptionFactory::createFromException($e, [
        'service_name' => 'Indexing Service',
        'operation' => 'index items',
      ]);

      return $this->handleIndexingDegradation($index, $items, $degradation_exception);
    }
  }

  /**
   * Indexes items with embedding generation and graceful degradation.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $items
   *   The items to index.
   *
   * @return array
   *   Array of successfully indexed item IDs.
   */
  protected function indexItemsWithEmbeddings(IndexInterface $index, array $items) {
    $table_name = $this->getIndexTableName($index);
    $indexed_items = [];
    $embedding_failures = [];

    $this->connector->beginTransaction();

    try {
      // Prepare embedding texts for all items.
      $embedding_texts = [];
      $item_data = [];

      foreach ($items as $item_id => $item) {
        $field_values = $this->prepareItemFieldValues($item, $index);
        $item_data[$item_id] = $field_values;

        // Extract embedding text.
        $embedding_text = $this->fieldMapper->generateEmbeddingText($field_values, $index);
        if (!empty($embedding_text)) {
          $embedding_texts[$item_id] = $embedding_text;
        }
      }

      // Generate embeddings in batch with degradation handling.
      $embeddings = [];
      if (!empty($embedding_texts) && $this->embeddingService) {
        $embeddings = $this->generateEmbeddingsWithDegradation($embedding_texts);
      }

      // Index each item.
      foreach ($items as $item_id => $item) {
        try {
          $embedding = $embeddings[$item_id] ?? NULL;
          $this->indexSingleItem($table_name, $index, $item, $item_data[$item_id], $embedding);
          $indexed_items[] = $item_id;

          // Track embedding success/failure.
          if (isset($embedding_texts[$item_id])) {
            if ($embedding) {
              // Embedding succeeded.
            }
            else {
              $this->recordEmbeddingFailure($item_id, 'No embedding generated');
            }
          }
        }
        catch (\Exception $e) {
          $this->recordEmbeddingFailure($item_id, $e->getMessage());

          if ($this->degradationConfig['continue_on_embedding_failure']) {
            // Try to index without embedding.
            try {
              $this->indexSingleItem($table_name, $index, $item, $item_data[$item_id], NULL);
              $indexed_items[] = $item_id;
            }
            catch (\Exception $inner_e) {
              // Complete failure for this item.
              \Drupal::logger('search_api_postgresql')->error('Failed to index item @item: @message', [
                '@item' => $item_id,
                '@message' => $inner_e->getMessage(),
              ]);
            }
          }
        }
      }

      $this->connector->commit();

      // Check if degradation occurred.
      $this->evaluateDegradationState();

      return $indexed_items;
    }
    catch (\Exception $e) {
      $this->connector->rollback();
      throw $e;
    }
  }

  /**
   * Generates embeddings with degradation handling.
   *
   * @param array $embedding_texts
   *   Array of texts to embed.
   *
   * @return array
   *   Array of embeddings keyed by item ID.
   */
  protected function generateEmbeddingsWithDegradation(array $embedding_texts) {
    if (!$this->embeddingService instanceof ResilientEmbeddingService) {
      // Wrap in resilient service if not already.
      $this->embeddingService = new ResilientEmbeddingService(
            $this->embeddingService,
            \Drupal::service('search_api_postgresql.circuit_breaker'),
            \Drupal::service('search_api_postgresql.cache_manager'),
            \Drupal::logger('search_api_postgresql')
        );
    }

    try {
      return $this->embeddingService->generateBatchEmbeddings($embedding_texts);
    }
    catch (PartialBatchFailureException $e) {
      // Handle partial failures.
      $successful = $e->getSuccessfulItems();
      $failed = $e->getFailedItems();

      foreach ($failed as $item_id => $error) {
        $this->recordEmbeddingFailure($item_id, $error);
      }

      \Drupal::logger('search_api_postgresql')->warning('Partial embedding failure: @success/@total successful', [
        '@success' => count($successful),
        '@total' => count($embedding_texts),
      ]);

      return $successful;
    }
    catch (GracefulDegradationException $e) {
      // All embeddings failed, but we can continue indexing without them.
      foreach (array_keys($embedding_texts) as $item_id) {
        $this->recordEmbeddingFailure($item_id, $e->getMessage());
      }

      $this->addDegradationMessage($e->getUserMessage());

      return [];
    }
  }

  /**
   * Indexes a single item with prepared data.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   * @param array $field_values
   *   Prepared field values.
   * @param array|null $embedding
   *   The embedding vector.
   */
  protected function indexSingleItem($table_name, IndexInterface $index, ItemInterface $item, array $field_values, ?array $embedding = NULL) {
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Add field values.
    $values = array_merge($values, $field_values['processed']);

    // Prepare tsvector value.
    $fts_config = $this->validateFtsConfiguration();
    $search_vector_field = $this->connector->validateFieldName('search_vector');
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";

    // Add embedding if available.
    if ($embedding && $this->isVectorSearchEnabled()) {
      $embedding_field = $this->connector->validateFieldName('content_embedding');
      $values[$embedding_field] = ':content_embedding';
    }

    // Delete existing item.
    $id_field = $this->connector->validateFieldName('search_api_id');
    $delete_sql = "DELETE FROM {$table_name} WHERE {$id_field} = :item_id";
    $this->connector->executeQuery($delete_sql, [':item_id' => $item->getId()]);

    // Insert new item.
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($values as $key => $value) {
      $safe_column = $this->connector->validateFieldName($key);
      $columns[] = $safe_column;

      if ($key === 'search_vector') {
        // Raw SQL expression.
        $placeholders[] = $value;
      }
      else {
        $placeholder = ":{$key}";
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
      }
    }

    $params[':searchable_text'] = $field_values['searchable_text'];

    if ($embedding) {
      $params[':content_embedding'] = '[' . implode(',', $embedding) . ']';
    }

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $this->connector->executeQuery($insert_sql, $params);
  }

  /**
   * Prepares field values for an item.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Prepared field values with 'processed' and 'searchable_text' keys.
   */
  protected function prepareItemFieldValues(ItemInterface $item, IndexInterface $index) {
    $fields = $item->getFields(TRUE);
    $processed_values = [];
    $searchable_text = '';
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);

    foreach ($fields as $field_id => $field) {
      // Validate field ID.
      $this->connector->validateIdentifier($field_id, 'field ID');

      $field_values = $field->getValues();
      $field_type = $field->getType();

      if (!empty($field_values)) {
        $value = reset($field_values);
        $processed_values[$field_id] = $this->fieldMapper->prepareFieldValue($value, $field_type);

        // Collect text for full-text search.
        if (in_array($field_id, $searchable_fields)) {
          $searchable_text .= ' ' . $this->fieldMapper->prepareFieldValue($value, 'text');
        }
      }
      else {
        $processed_values[$field_id] = NULL;
      }
    }

    return [
      'processed' => $processed_values,
      'searchable_text' => trim($searchable_text),
    ];
  }

  /**
   * Indexes items without embeddings (fallback mode).
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $items
   *   The items to index.
   *
   * @return array
   *   Array of successfully indexed item IDs.
   */
  protected function indexItemsTextOnly(IndexInterface $index, array $items) {
    return parent::indexItems($index, $items);
  }

  /**
   * Handles partial indexing failure.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api_postgresql\Exception\PartialBatchFailureException $exception
   *   The partial failure exception.
   *
   * @return array
   *   Array of successfully indexed item IDs.
   */
  protected function handlePartialIndexingFailure(IndexInterface $index, PartialBatchFailureException $exception) {
    $success_rate = $exception->getSuccessRate();

    if ($success_rate >= 50) {
      // Acceptable partial failure.
      \Drupal::messenger()->addWarning($exception->getUserMessage());
    }
    else {
      // High failure rate.
      \Drupal::messenger()->addError(t('Indexing experienced significant issues. @success_rate% of items were processed successfully.', [
        '@success_rate' => round($success_rate, 1),
      ]));
    }

    return array_keys($exception->getSuccessfulItems());
  }

  /**
   * Handles indexing degradation.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $items
   *   The items to index.
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   *
   * @return array
   *   Array of successfully indexed item IDs.
   */
  protected function handleIndexingDegradation(IndexInterface $index, array $items, GracefulDegradationException $exception) {
    $strategy = $exception->getFallbackStrategy();

    switch ($strategy) {
      case 'text_search_only':
      case 'text_search_fallback':
        \Drupal::messenger()->addWarning($exception->getUserMessage());
        return $this->indexItemsTextOnly($index, $items);

      case 'continue_with_partial_results':
        // Already handled by partial failure logic.
        \Drupal::messenger()->addWarning($exception->getUserMessage());
        return [];

      default:
        // For other strategies, try text-only indexing.
        \Drupal::messenger()->addWarning($exception->getUserMessage());
        return $this->indexItemsTextOnly($index, $items);
    }
  }

  /**
   * Records an embedding failure.
   *
   * @param string $item_id
   *   The item ID.
   * @param string $reason
   *   The failure reason.
   */
  protected function recordEmbeddingFailure($item_id, $reason) {
    $this->degradationState['embedding_failures']++;

    \Drupal::logger('search_api_postgresql')->debug('Embedding failed for item @item: @reason', [
      '@item' => $item_id,
      '@reason' => $reason,
    ]);
  }

  /**
   * Adds a degradation message.
   *
   * @param string $message
   *   The message.
   */
  protected function addDegradationMessage($message) {
    if (!in_array($message, $this->degradationState['messages'])) {
      $this->degradationState['messages'][] = $message;
    }
  }

  /**
   * Evaluates the current degradation state.
   */
  protected function evaluateDegradationState() {
    $total = $this->degradationState['total_items'];
    $failures = $this->degradationState['embedding_failures'];

    if ($total > 0) {
      $failure_rate = $failures / $total;

      if ($failure_rate >= $this->degradationConfig['embedding_failure_threshold']) {
        $this->degradationState['fallback_mode'] = TRUE;

        $message = t('Vector search features may be limited due to embedding generation issues. Text search remains fully functional.');
        $this->addDegradationMessage($message);

        \Drupal::messenger()->addWarning($message);

        \Drupal::logger('search_api_postgresql')->warning('High embedding failure rate detected: @rate% (@failures/@total)', [
          '@rate' => round($failure_rate * 100, 1),
          '@failures' => $failures,
          '@total' => $total,
        ]);
      }
    }
  }

  /**
   * Resets the degradation state.
   */
  protected function resetDegradationState() {
    $this->degradationState = [
      'embedding_failures' => 0,
      'total_items' => 0,
      'fallback_mode' => FALSE,
      'messages' => [],
    ];
  }

  /**
   * Gets the current degradation state.
   *
   * @return array
   *   The degradation state.
   */
  public function getDegradationState() {
    return $this->degradationState;
  }

  /**
   * Checks if indexing is in fallback mode.
   *
   * @return bool
   *   TRUE if in fallback mode.
   */
  public function isInFallbackMode() {
    return $this->degradationState['fallback_mode'];
  }

  /**
   * Gets degradation messages.
   *
   * @return array
   *   Array of degradation messages.
   */
  public function getDegradationMessages() {
    return $this->degradationState['messages'];
  }

  /**
   * Attempts to recover from degradation by testing the embedding service.
   *
   * @return bool
   *   TRUE if recovery successful.
   */
  public function attemptRecovery() {
    if (!$this->embeddingService) {
      return FALSE;
    }

    try {
      // Test with a simple embedding.
      $test_embedding = $this->embeddingService->generateEmbedding('test recovery');

      if ($test_embedding) {
        $this->resetDegradationState();
        \Drupal::logger('search_api_postgresql')->info('Embedding service recovery successful');
        return TRUE;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->warning('Embedding service recovery failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function regenerateEmbeddings(IndexInterface $index) {
    if (!$this->isVectorSearchEnabled() || !$this->embeddingService) {
      throw new VectorSearchDegradedException('Vector search not enabled or embedding service unavailable');
    }

    $this->resetDegradationState();

    try {
      return $this->regenerateEmbeddingsWithDegradation($index);
    }
    catch (GracefulDegradationException $e) {
      \Drupal::messenger()->addWarning($e->getUserMessage());
      throw $e;
    }
  }

  /**
   * Regenerates embeddings with graceful degradation.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function regenerateEmbeddingsWithDegradation(IndexInterface $index) {
    $table_name = $this->getIndexTableName($index);

    // Get all items without pagination for now (could be enhanced for large datasets)
    $embedding_source_fields = $this->fieldMapper->getEmbeddingSourceFields($index);
    $safe_fields = [];
    $id_field = $this->connector->validateFieldName('search_api_id');
    $safe_fields[] = $id_field;

    foreach ($embedding_source_fields as $field_id) {
      $safe_fields[] = $this->connector->validateFieldName($field_id);
    }

    $sql = "SELECT " . implode(', ', $safe_fields) . " FROM {$table_name}";
    $stmt = $this->connector->executeQuery($sql);

    $batch_size = $this->config['ai_embeddings']['azure_ai']['batch_size'] ?? 10;
    $items_batch = [];
    $embedding_texts_batch = [];

    while ($row = $stmt->fetch()) {
      $item_id = $row['search_api_id'];
      unset($row['search_api_id']);

      $embedding_text = $this->fieldMapper->generateEmbeddingText($row, $index);
      if (!empty($embedding_text)) {
        $items_batch[] = $item_id;
        $embedding_texts_batch[] = $embedding_text;

        if (count($items_batch) >= $batch_size) {
          $this->processBatchEmbeddingsWithDegradation($table_name, $items_batch, $embedding_texts_batch);
          $items_batch = [];
          $embedding_texts_batch = [];
        }
      }
    }

    // Process remaining items.
    if (!empty($items_batch)) {
      $this->processBatchEmbeddingsWithDegradation($table_name, $items_batch, $embedding_texts_batch);
    }

    $this->evaluateDegradationState();
  }

  /**
   * Processes a batch of embeddings with degradation handling.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param array $item_ids
   *   Array of item IDs.
   * @param array $texts
   *   Array of texts to embed.
   */
  protected function processBatchEmbeddingsWithDegradation($table_name, array $item_ids, array $texts) {
    $this->degradationState['total_items'] += count($item_ids);

    try {
      $embeddings = $this->generateEmbeddingsWithDegradation(array_combine($item_ids, $texts));

      $this->connector->beginTransaction();
      try {
        $vector_field = $this->connector->validateFieldName('content_embedding');
        $id_field = $this->connector->validateFieldName('search_api_id');

        foreach ($item_ids as $index => $item_id) {
          if (isset($embeddings[$item_id])) {
            $vector_value = '[' . implode(',', $embeddings[$item_id]) . ']';
            $sql = "UPDATE {$table_name} SET {$vector_field} = :vector WHERE {$id_field} = :item_id";
            $this->connector->executeQuery($sql, [
              ':vector' => $vector_value,
              ':item_id' => $item_id,
            ]);
          }
          else {
            $this->recordEmbeddingFailure($item_id, 'No embedding generated in batch');
          }
        }
        $this->connector->commit();
      }
      catch (\Exception $e) {
        $this->connector->rollback();
        throw $e;
      }
    }
    catch (GracefulDegradationException $e) {
      // Log and continue with next batch.
      foreach ($item_ids as $item_id) {
        $this->recordEmbeddingFailure($item_id, $e->getMessage());
      }
    }
  }

}
