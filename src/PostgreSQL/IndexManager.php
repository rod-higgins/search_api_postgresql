<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\SearchApiException;

/**
 * Manages PostgreSQL index tables and operations with vector support.
 */
class IndexManager {

  /**
   * The PostgreSQL connector.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The field mapper.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\FieldMapper
   */
  protected $fieldMapper;

  /**
   * The backend configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\EmbeddingService
   */
  protected $embeddingService;

  /**
   * Constructs an IndexManager object.
   *
   * @param \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector $connector
   *   The PostgreSQL connector.
   * @param \Drupal\search_api_postgresql\PostgreSQL\FieldMapper $field_mapper
   *   The field mapper.
   * @param array $config
   *   The backend configuration.
   * @param \Drupal\search_api_postgresql\PostgreSQL\EmbeddingService $embedding_service
   *   The embedding service (optional).
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config, EmbeddingService $embedding_service = NULL) {
    $this->connector = $connector;
    $this->fieldMapper = $field_mapper;
    $this->config = $config;
    $this->embeddingService = $embedding_service;
  }

  /**
   * Creates an index table.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If table creation fails.
   */
  public function createIndex(IndexInterface $index) {
    $table_name = $this->getIndexTableName($index);

    if ($this->connector->tableExists($table_name)) {
      throw new SearchApiException("Index table '{$table_name}' already exists.");
    }

    // Ensure vector extension is available if needed.
    if ($this->isVectorSearchEnabled() && !$this->fieldMapper->checkVectorSupport($this->connector)) {
      throw new SearchApiException('Vector search is enabled but pgvector extension is not available. Please install and enable the pgvector extension.');
    }

    // Create main index table.
    $sql = $this->buildCreateTableSql($table_name, $index);
    $this->connector->executeQuery($sql);

    // Create full-text indexes.
    $this->createFullTextIndexes($table_name, $index);

    // Create vector indexes if enabled.
    if ($this->isVectorSearchEnabled()) {
      $this->createVectorIndexes($table_name, $index);
    }

    // Create supporting indexes for faceting and filtering.
    $this->createSupportingIndexes($table_name, $index);
  }

  /**
   * Updates an index table structure.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  public function updateIndex(IndexInterface $index) {
    $table_name = $this->getIndexTableName($index);

    if (!$this->connector->tableExists($table_name)) {
      $this->createIndex($index);
      return;
    }

    // Get current table structure.
    $current_columns = $this->getTableColumns($table_name);
    $required_columns = $this->fieldMapper->getFieldDefinitions($index);

    // Add missing columns.
    foreach ($required_columns as $field_id => $field_info) {
      if (!in_array($field_id, $current_columns)) {
        $sql = "ALTER TABLE {$table_name} ADD COLUMN {$field_id} {$field_info['type']}";
        $this->connector->executeQuery($sql);
      }
    }

    // Recreate full-text indexes.
    $this->dropFullTextIndexes($table_name);
    $this->createFullTextIndexes($table_name, $index);

    // Recreate vector indexes if enabled.
    if ($this->isVectorSearchEnabled()) {
      $this->dropVectorIndexes($table_name);
      $this->createVectorIndexes($table_name, $index);
    }
  }

  /**
   * Indexes items in the database.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   The items to index.
   *
   * @return array
   *   Array of successfully indexed item IDs.
   */
  public function indexItems(IndexInterface $index, array $items) {
    $table_name = $this->getIndexTableName($index);
    $indexed_items = [];

    $this->connector->beginTransaction();

    try {
      // Prepare embedding texts if AI embeddings are enabled.
      $embedding_texts = [];
      if ($this->isVectorSearchEnabled() && $this->embeddingService) {
        foreach ($items as $item_id => $item) {
          $field_values = [];
          foreach ($item->getFields(TRUE) as $field_id => $field) {
            $values = $field->getValues();
            if (!empty($values)) {
              $field_values[$field_id] = reset($values);
            }
          }
          
          $embedding_text = $this->fieldMapper->generateEmbeddingText($field_values, $index);
          if (!empty($embedding_text)) {
            $embedding_texts[$item_id] = $embedding_text;
          }
        }

        // Generate embeddings in batch.
        if (!empty($embedding_texts)) {
          $embeddings = $this->embeddingService->generateEmbeddings(array_values($embedding_texts));
          $embedding_map = array_combine(array_keys($embedding_texts), $embeddings);
        }
      }

      foreach ($items as $item_id => $item) {
        $embedding_vector = isset($embedding_map[$item_id]) ? $embedding_map[$item_id] : NULL;
        $this->indexItem($table_name, $index, $item, $embedding_vector);
        $indexed_items[] = $item_id;
      }

      $this->connector->commit();
    }
    catch (\Exception $e) {
      $this->connector->rollback();
      throw new SearchApiException('Failed to index items: ' . $e->getMessage(), $e->getCode(), $e);
    }

    return $indexed_items;
  }

  /**
   * Indexes a single item.
   *
   * @param string $table_name
   *   The index table name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   * @param array|null $embedding_vector
   *   The embedding vector for the item.
   */
  protected function indexItem($table_name, IndexInterface $index, ItemInterface $item, array $embedding_vector = NULL) {
    $fields = $item->getFields(TRUE);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Prepare searchable text for tsvector.
    $searchable_text = '';
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);

    foreach ($fields as $field_id => $field) {
      $field_values = $field->getValues();
      $field_type = $field->getType();

      if (!empty($field_values)) {
        $value = reset($field_values);
        $values[$field_id] = $this->fieldMapper->prepareFieldValue($value, $field_type);

        // Collect text for full-text search.
        if (in_array($field_id, $searchable_fields)) {
          $searchable_text .= ' ' . $this->fieldMapper->prepareFieldValue($value, 'text');
        }
      }
      else {
        $values[$field_id] = NULL;
      }
    }

    // Add embedding vector if available.
    if ($embedding_vector && $this->isVectorSearchEnabled()) {
      $values['embedding_vector'] = $this->fieldMapper->prepareFieldValue($embedding_vector, 'vector');
    }

    // Prepare tsvector value.
    $fts_config = $this->config['fts_configuration'];
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";

    // Delete existing item.
    $delete_sql = "DELETE FROM {$table_name} WHERE search_api_id = :item_id";
    $this->connector->executeQuery($delete_sql, [':item_id' => $item->getId()]);

    // Insert new item.
    $columns = array_keys($values);
    $placeholders = array_map(function($col) use ($values) {
      return $col === 'search_vector' ? $values[$col] : ":{$col}";
    }, $columns);

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

    $params = [];
    foreach ($values as $key => $value) {
      if ($key !== 'search_vector') {
        $params[":{$key}"] = $value;
      }
    }
    $params[':searchable_text'] = trim($searchable_text);

    $this->connector->executeQuery($insert_sql, $params);
  }

  /**
   * Builds the CREATE TABLE SQL statement.
   *
   * @param string $table_name
   *   The table name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The CREATE TABLE SQL.
   */
  protected function buildCreateTableSql($table_name, IndexInterface $index) {
    $fields = $this->fieldMapper->getFieldDefinitions($index);

    $sql = "CREATE TABLE {$table_name} (\n";
    $sql .= "  search_api_id VARCHAR(255) PRIMARY KEY,\n";
    $sql .= "  search_api_datasource VARCHAR(255) NOT NULL,\n";
    $sql .= "  search_api_language VARCHAR(12) NOT NULL DEFAULT '',\n";

    foreach ($fields as $field_id => $field_info) {
      $sql .= "  {$field_id} {$field_info['type']},\n";
    }

    // Add tsvector column for full-text search.
    $sql .= "  search_vector TSVECTOR\n";
    $sql .= ");";

    return $sql;
  }

  /**
   * Creates vector indexes for similarity search.
   *
   * @param string $table_name
   *   The table name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createVectorIndexes($table_name, IndexInterface $index) {
    $vector_fields = $this->fieldMapper->getVectorSearchableFields($index);

    foreach ($vector_fields as $field_id) {
      // Create HNSW index for fast vector similarity search.
      $index_name = "{$table_name}_{$field_id}_vector_idx";
      $sql = "CREATE INDEX {$index_name} ON {$table_name} USING hnsw ({$field_id} vector_cosine_ops)";
      
      try {
        $this->connector->executeQuery($sql);
      }
      catch (\Exception $e) {
        // Fall back to IVFFlat if HNSW is not available.
        $sql = "CREATE INDEX {$index_name} ON {$table_name} USING ivfflat ({$field_id} vector_cosine_ops) WITH (lists = 100)";
        $this->connector->executeQuery($sql);
      }
    }
  }

  /**
   * Drops vector indexes.
   *
   * @param string $table_name
   *   The table name.
   */
  protected function dropVectorIndexes($table_name) {
    // Get vector fields and drop their indexes.
    $sql = "
      SELECT indexname 
      FROM pg_indexes 
      WHERE tablename = :table_name 
      AND indexname LIKE :pattern
    ";
    $stmt = $this->connector->executeQuery($sql, [
      ':table_name' => $table_name,
      ':pattern' => "%_vector_idx",
    ]);

    while ($row = $stmt->fetch()) {
      $drop_sql = "DROP INDEX IF EXISTS {$row['indexname']}";
      $this->connector->executeQuery($drop_sql);
    }
  }

  /**
   * Creates full-text indexes.
   *
   * @param string $table_name
   *   The table name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createFullTextIndexes($table_name, IndexInterface $index) {
    // Create GIN index on tsvector column.
    $sql = "CREATE INDEX {$table_name}_fts_idx ON {$table_name} USING GIN(search_vector)";
    $this->connector->executeQuery($sql);

    // Create trigger to auto-update tsvector when searchable fields change.
    $this->createTsvectorTrigger($table_name, $index);
  }

  /**
   * Creates supporting indexes for faceting and filtering.
   *
   * @param string $table_name
   *   The table name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createSupportingIndexes($table_name, IndexInterface $index) {
    $fields = $this->fieldMapper->getFieldDefinitions($index);

    foreach ($fields as $field_id => $field_info) {
      if (($field_info['facetable'] || $field_info['sortable']) && !($field_info['vector'] ?? FALSE)) {
        $index_name = "{$table_name}_{$field_id}_idx";
        $sql = "CREATE INDEX {$index_name} ON {$table_name} ({$field_id})";
        $this->connector->executeQuery($sql);
      }
    }

    // Create indexes on system fields.
    $this->connector->executeQuery("CREATE INDEX {$table_name}_datasource_idx ON {$table_name} (search_api_datasource)");
    $this->connector->executeQuery("CREATE INDEX {$table_name}_language_idx ON {$table_name} (search_api_language)");
  }

  /**
   * Creates a trigger to automatically update the tsvector column.
   *
   * @param string $table_name
   *   The table name.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createTsvectorTrigger($table_name, IndexInterface $index) {
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);
    
    if (empty($searchable_fields)) {
      return;
    }

    $fts_config = $this->config['fts_configuration'];
    $field_concat = implode(" || ' ' || COALESCE(", $searchable_fields);
    $field_concat = "COALESCE({$field_concat}, '')";

    // Add closing parentheses for each COALESCE.
    for ($i = 1; $i < count($searchable_fields); $i++) {
      $field_concat .= ", '')";
    }

    $trigger_name = "{$table_name}_tsvector_update";
    $function_name = "{$table_name}_tsvector_trigger";

    // Create trigger function.
    $function_sql = "
      CREATE OR REPLACE FUNCTION {$function_name}() RETURNS trigger AS $$
      BEGIN
        NEW.search_vector := to_tsvector('{$fts_config}', {$field_concat});
        RETURN NEW;
      END
      $$ LANGUAGE plpgsql;
    ";

    $this->connector->executeQuery($function_sql);

    // Create trigger.
    $trigger_sql = "
      CREATE TRIGGER {$trigger_name}
      BEFORE INSERT OR UPDATE ON {$table_name}
      FOR EACH ROW EXECUTE FUNCTION {$function_name}();
    ";

    $this->connector->executeQuery($trigger_sql);
  }

  /**
   * Drops full-text indexes.
   *
   * @param string $table_name
   *   The table name.
   */
  protected function dropFullTextIndexes($table_name) {
    // Drop GIN index.
    $sql = "DROP INDEX IF EXISTS {$table_name}_fts_idx";
    $this->connector->executeQuery($sql);

    // Drop trigger and function.
    $this->connector->executeQuery("DROP TRIGGER IF EXISTS {$table_name}_tsvector_update ON {$table_name}");
    $this->connector->executeQuery("DROP FUNCTION IF EXISTS {$table_name}_tsvector_trigger()");
  }

  /**
   * Checks if vector search is enabled.
   *
   * @return bool
   *   TRUE if vector search is enabled.
   */
  protected function isVectorSearchEnabled() {
    return !empty($this->config['ai_embeddings']['enabled']);
  }

  /**
   * Re-generates embeddings for all items in an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If re-embedding fails.
   */
  public function regenerateEmbeddings(IndexInterface $index) {
    if (!$this->isVectorSearchEnabled() || !$this->embeddingService) {
      throw new SearchApiException('Vector search is not enabled or embedding service is not available.');
    }

    $table_name = $this->getIndexTableName($index);
    
    // Get all items without pagination for now (could be enhanced for large datasets).
    $sql = "SELECT search_api_id, " . implode(', ', $this->fieldMapper->getEmbeddingSourceFields($index)) . " FROM {$table_name}";
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
          $this->processBatchEmbeddings($table_name, $items_batch, $embedding_texts_batch);
          $items_batch = [];
          $embedding_texts_batch = [];
        }
      }
    }

    // Process remaining items.
    if (!empty($items_batch)) {
      $this->processBatchEmbeddings($table_name, $items_batch, $embedding_texts_batch);
    }
  }

  /**
   * Processes a batch of embeddings.
   *
   * @param string $table_name
   *   The table name.
   * @param array $item_ids
   *   Array of item IDs.
   * @param array $texts
   *   Array of texts to embed.
   */
  protected function processBatchEmbeddings($table_name, array $item_ids, array $texts) {
    $embeddings = $this->embeddingService->generateEmbeddings($texts);

    $this->connector->beginTransaction();
    try {
      foreach ($item_ids as $index => $item_id) {
        if (isset($embeddings[$index])) {
          $vector_value = $this->fieldMapper->prepareFieldValue($embeddings[$index], 'vector');
          $sql = "UPDATE {$table_name} SET embedding_vector = :vector WHERE search_api_id = :item_id";
          $this->connector->executeQuery($sql, [
            ':vector' => $vector_value,
            ':item_id' => $item_id,
          ]);
        }
      }
      $this->connector->commit();
    }
    catch (\Exception $e) {
      $this->connector->rollback();
      throw $e;
    }
  }

  /**
   * Gets the table name for an index.
   *
   * @param \Drupal\search_api\IndexInterface|string $index
   *   The search index or index ID.
   *
   * @return string
   *   The table name.
   */
  protected function getIndexTableName($index) {
    $index_id = is_string($index) ? $index : $index->id();
    return $this->config['index_prefix'] . $index_id;
  }

  /**
   * Gets the columns of a table.
   *
   * @param string $table_name
   *   The table name.
   *
   * @return array
   *   Array of column names.
   */
  protected function getTableColumns($table_name) {
    $sql = "
      SELECT column_name 
      FROM information_schema.columns 
      WHERE table_name = :table_name
    ";
    $stmt = $this->connector->executeQuery($sql, [':table_name' => $table_name]);
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
  }

  // ... (existing methods: dropIndex, deleteItems, deleteAllItems remain the same)

}