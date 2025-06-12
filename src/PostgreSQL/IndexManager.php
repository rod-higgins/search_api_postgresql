<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\SearchApiException;

/**
 * Manages PostgreSQL index tables and operations with vector support and SQL injection prevention.
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

    // Check if table already exists (using unquoted name for existence check)
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);
    if ($this->connector->tableExists($unquoted_table_name)) {
      throw new SearchApiException("Index table '{$unquoted_table_name}' already exists.");
    }

    // Ensure vector extension is available if needed
    if ($this->isVectorSearchEnabled() && !$this->checkVectorSupport()) {
      throw new SearchApiException('Vector search is enabled but pgvector extension is not available. Please install and enable the pgvector extension.');
    }

    // Create main index table
    $sql = $this->buildCreateTableSql($table_name, $index);
    $this->connector->executeQuery($sql);

    // Create full-text indexes
    $this->createFullTextIndexes($table_name, $index);

    // Create vector indexes if enabled
    if ($this->isVectorSearchEnabled()) {
      $this->createVectorIndexes($table_name, $index);
    }

    // Create supporting indexes for faceting and filtering
    $this->createSupportingIndexes($table_name, $index);
  }

  /**
   * Updates an index table structure.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  public function updateIndex(IndexInterface $index) {
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);

    if (!$this->connector->tableExists($unquoted_table_name)) {
      $this->createIndex($index);
      return;
    }

    $table_name = $this->getIndexTableName($index);

    // Get current table structure
    $current_columns = $this->getTableColumns($unquoted_table_name);
    $required_columns = $this->fieldMapper->getFieldDefinitions($index);

    // Add missing columns
    foreach ($required_columns as $field_id => $field_info) {
      if (!in_array($field_id, $current_columns)) {
        $safe_field_id = $this->connector->quoteColumnName($field_id);
        $safe_type = $this->connector->validateDataType($field_info['type']);
        $sql = "ALTER TABLE {$table_name} ADD COLUMN {$safe_field_id} {$safe_type}";
        $this->connector->executeQuery($sql);
      }
    }

    // Recreate full-text indexes
    $this->dropFullTextIndexes($table_name, $index);
    $this->createFullTextIndexes($table_name, $index);

    // Recreate vector indexes if enabled
    if ($this->isVectorSearchEnabled()) {
      $this->dropVectorIndexes($table_name, $index);
      $this->createVectorIndexes($table_name, $index);
    }
  }

  /**
   * Drops an index table.
   *
   * @param \Drupal\search_api\IndexInterface|string $index
   *   The search index or index ID.
   */
  public function dropIndex($index) {
    $table_name = $this->getIndexTableName($index);
    
    // Drop the table (CASCADE will remove dependent objects)
    $sql = "DROP TABLE IF EXISTS {$table_name} CASCADE";
    $this->connector->executeQuery($sql);
  }

  /**
   * Indexes an item in the database.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to index.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If indexing fails.
   */
  public function indexItem($table_name, IndexInterface $index, ItemInterface $item) {
    $fields = $item->getFields(TRUE);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: '',
    ];

    // Process field values
    foreach ($fields as $field_id => $field) {
      $field_value = $field->getValues();
      if (!empty($field_value)) {
        $prepared_value = $this->fieldMapper->prepareFieldValue(
          reset($field_value),
          $field->getType()
        );
        $values[$field_id] = $prepared_value;
      }
    }

    // Generate full-text search vector
    $searchable_text = $this->fieldMapper->extractSearchableText($fields, $index);
    if (!empty($searchable_text)) {
      $fts_config = $this->validateFtsConfiguration();
      $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";
    }

    // Generate embeddings if enabled
    if ($this->isVectorSearchEnabled() && $this->embeddingService) {
      $embedding_text = $this->fieldMapper->generateEmbeddingText($values, $index);
      if (!empty($embedding_text)) {
        try {
          $embedding = $this->embeddingService->generateEmbedding($embedding_text);
          if (!empty($embedding)) {
            $values['embedding_vector'] = $this->fieldMapper->prepareFieldValue($embedding, 'vector');
          }
        }
        catch (\Exception $e) {
          // Log error but don't fail indexing
          \Drupal::logger('search_api_postgresql')->warning('Failed to generate embedding for item @id: @message', [
            '@id' => $item->getId(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    // Build INSERT query
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($values as $field_id => $value) {
      $columns[] = $this->connector->quoteColumnName($field_id);
      if ($field_id === 'search_vector' && strpos($value, 'to_tsvector') === 0) {
        $placeholders[] = $value;
      }
      else {
        $placeholders[] = ":{$field_id}";
        $params[":{$field_id}"] = $value;
      }
    }

    if (isset($values['search_vector'])) {
      $params[':searchable_text'] = $searchable_text;
    }

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")
                   ON CONFLICT (search_api_id) DO UPDATE SET " . $this->buildUpdateSet($columns, $values);
    
    $this->connector->executeQuery($insert_sql, $params);
  }

  /**
   * Deletes items from the index.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param array $item_ids
   *   Array of item IDs to delete.
   */
  public function deleteItems($table_name, array $item_ids) {
    if (empty($item_ids)) {
      return;
    }

    $placeholders = [];
    $params = [];

    foreach ($item_ids as $i => $id) {
      $placeholders[] = ":id_{$i}";
      $params[":id_{$i}"] = $id;
    }

    $id_field = $this->connector->quoteColumnName('search_api_id');
    $sql = "DELETE FROM {$table_name} WHERE {$id_field} IN (" . implode(', ', $placeholders) . ")";
    
    $this->connector->executeQuery($sql, $params);
  }

  /**
   * Clears all items from an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string|null $datasource_id
   *   Optional datasource ID to clear.
   */
  public function clearIndex(IndexInterface $index, $datasource_id = NULL) {
    $table_name = $this->getIndexTableName($index);

    if ($datasource_id) {
      $datasource_field = $this->connector->quoteColumnName('search_api_datasource');
      $sql = "DELETE FROM {$table_name} WHERE {$datasource_field} = :datasource";
      $this->connector->executeQuery($sql, [':datasource' => $datasource_id]);
    }
    else {
      $sql = "TRUNCATE TABLE {$table_name}";
      $this->connector->executeQuery($sql);
    }
  }

  /**
   * Builds the CREATE TABLE SQL statement.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return string
   *   The CREATE TABLE SQL.
   */
  protected function buildCreateTableSql($table_name, IndexInterface $index) {
    $fields = $this->fieldMapper->getFieldDefinitions($index);

    $sql = "CREATE TABLE {$table_name} (\n";
    
    // Add system fields
    $id_field = $this->connector->quoteColumnName('search_api_id');
    $datasource_field = $this->connector->quoteColumnName('search_api_datasource');
    $language_field = $this->connector->quoteColumnName('search_api_language');
    
    $sql .= "  {$id_field} VARCHAR(255) PRIMARY KEY,\n";
    $sql .= "  {$datasource_field} VARCHAR(255) NOT NULL,\n";
    $sql .= "  {$language_field} VARCHAR(12) NOT NULL DEFAULT '',\n";

    foreach ($fields as $field_id => $field_info) {
      $safe_field_id = $this->connector->quoteColumnName($field_id);
      $safe_type = $this->connector->validateDataType($field_info['type']);
      $sql .= "  {$safe_field_id} {$safe_type},\n";
    }

    // Add tsvector column for full-text search
    $search_vector_field = $this->connector->quoteColumnName('search_vector');
    $sql .= "  {$search_vector_field} TSVECTOR\n";
    $sql .= ");";

    return $sql;
  }

  /**
   * Creates full-text indexes.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createFullTextIndexes($table_name, IndexInterface $index) {
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);
    
    // Create GIN index on tsvector column
    $search_vector_field = $this->connector->quoteColumnName('search_vector');
    $fts_index_name = $this->connector->quoteIndexName($unquoted_table_name . '_fts_idx');
    
    $sql = "CREATE INDEX {$fts_index_name} ON {$table_name} USING gin({$search_vector_field})";
    $this->connector->executeQuery($sql);

    // Create trigram indexes for fuzzy search on searchable fields
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);
    foreach ($searchable_fields as $field_id) {
      $safe_field = $this->connector->quoteColumnName($field_id);
      $trigram_index = $this->connector->quoteIndexName($unquoted_table_name . '_' . $field_id . '_trgm_idx');
      
      try {
        $sql = "CREATE INDEX {$trigram_index} ON {$table_name} USING gin({$safe_field} gin_trgm_ops)";
        $this->connector->executeQuery($sql);
      }
      catch (\Exception $e) {
        // Trigram extension might not be available
        \Drupal::logger('search_api_postgresql')->notice('Could not create trigram index: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Creates vector indexes for similarity search.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createVectorIndexes($table_name, IndexInterface $index) {
    $vector_fields = $this->fieldMapper->getVectorSearchableFields($index);
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);

    foreach ($vector_fields as $field_id) {
      $safe_field = $this->connector->quoteColumnName($field_id);
      $index_name = $this->connector->quoteIndexName($unquoted_table_name . '_' . $field_id . '_vector_idx');
      
      // Create HNSW index for fast vector similarity search
      try {
        $sql = "CREATE INDEX {$index_name} ON {$table_name} USING hnsw ({$safe_field} vector_cosine_ops)";
        $this->connector->executeQuery($sql);
      }
      catch (\Exception $e) {
        // Fall back to IVFFlat if HNSW is not available
        try {
          $sql = "CREATE INDEX {$index_name} ON {$table_name} USING ivfflat ({$safe_field} vector_cosine_ops) WITH (lists = 100)";
          $this->connector->executeQuery($sql);
        }
        catch (\Exception $e2) {
          throw new SearchApiException("Failed to create vector index: " . $e2->getMessage());
        }
      }
    }
  }

  /**
   * Creates supporting indexes for faceting and filtering.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function createSupportingIndexes($table_name, IndexInterface $index) {
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);
    
    // Create indexes on facetable fields
    $facetable_fields = $this->fieldMapper->getFacetableFields($index);
    foreach ($facetable_fields as $field_id) {
      $safe_field = $this->connector->quoteColumnName($field_id);
      $index_name = $this->connector->quoteIndexName($unquoted_table_name . '_' . $field_id . '_idx');
      
      $sql = "CREATE INDEX {$index_name} ON {$table_name} ({$safe_field})";
      $this->connector->executeQuery($sql);
    }

    // Create indexes on system fields
    $datasource_field = $this->connector->quoteColumnName('search_api_datasource');
    $language_field = $this->connector->quoteColumnName('search_api_language');
    
    $datasource_index = $this->connector->quoteIndexName($unquoted_table_name . '_datasource_idx');
    $language_index = $this->connector->quoteIndexName($unquoted_table_name . '_language_idx');
    
    $this->connector->executeQuery("CREATE INDEX {$datasource_index} ON {$table_name} ({$datasource_field})");
    $this->connector->executeQuery("CREATE INDEX {$language_index} ON {$table_name} ({$language_field})");
  }

  /**
   * Drops full-text indexes.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function dropFullTextIndexes($table_name, IndexInterface $index) {
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);
    
    // Drop GIN index
    $fts_index_name = $this->connector->quoteIndexName($unquoted_table_name . '_fts_idx');
    $sql = "DROP INDEX IF EXISTS {$fts_index_name}";
    $this->connector->executeQuery($sql);

    // Drop trigram indexes
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);
    foreach ($searchable_fields as $field_id) {
      $trigram_index = $this->connector->quoteIndexName($unquoted_table_name . '_' . $field_id . '_trgm_idx');
      $sql = "DROP INDEX IF EXISTS {$trigram_index}";
      $this->connector->executeQuery($sql);
    }
  }

  /**
   * Drops vector indexes.
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   */
  protected function dropVectorIndexes($table_name, IndexInterface $index) {
    $vector_fields = $this->fieldMapper->getVectorSearchableFields($index);
    $unquoted_table_name = $this->getIndexTableNameUnquoted($index);

    foreach ($vector_fields as $field_id) {
      $index_name = $this->connector->quoteIndexName($unquoted_table_name . '_' . $field_id . '_vector_idx');
      $sql = "DROP INDEX IF EXISTS {$index_name}";
      $this->connector->executeQuery($sql);
    }
  }

  /**
   * Gets the quoted table name for an index.
   *
   * @param \Drupal\search_api\IndexInterface|string $index
   *   The search index or index ID.
   *
   * @return string
   *   The quoted table name.
   */
  protected function getIndexTableName($index) {
    $index_id = is_string($index) ? $index : $index->id();
    
    // Validate index ID
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $index_id)) {
      throw new \InvalidArgumentException("Invalid index ID: {$index_id}");
    }
    
    $table_name = ($this->config['index_prefix'] ?? 'search_api_') . $index_id;
    return $this->connector->quoteTableName($table_name);
  }

  /**
   * Gets the unquoted table name for an index (for metadata queries).
   *
   * @param \Drupal\search_api\IndexInterface|string $index
   *   The search index or index ID.
   *
   * @return string
   *   The unquoted table name.
   */
  protected function getIndexTableNameUnquoted($index) {
    $index_id = is_string($index) ? $index : $index->id();
    
    // Validate index ID
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $index_id)) {
      throw new \InvalidArgumentException("Invalid index ID: {$index_id}");
    }
    
    $table_name = ($this->config['index_prefix'] ?? 'search_api_') . $index_id;
    
    // Validate but don't quote
    $this->connector->validateIdentifier($table_name, 'table name');
    
    return $table_name;
  }

  /**
   * Gets the columns of a table.
   *
   * @param string $table_name
   *   The table name (unquoted).
   *
   * @return array
   *   Array of column names.
   */
  protected function getTableColumns($table_name) {
    return $this->connector->getTableColumns($table_name);
  }

  /**
   * Validates the FTS configuration.
   *
   * @return string
   *   The validated FTS configuration.
   */
  protected function validateFtsConfiguration() {
    $fts_config = $this->config['fts_configuration'] ?? 'english';
    
    // Allowed PostgreSQL text search configurations
    $allowed_configs = [
      'simple', 'english', 'french', 'german', 'spanish', 'portuguese',
      'italian', 'dutch', 'russian', 'norwegian', 'swedish', 'danish',
      'finnish', 'hungarian', 'romanian', 'turkish'
    ];
    
    if (!in_array($fts_config, $allowed_configs)) {
      throw new \InvalidArgumentException("Invalid FTS configuration: {$fts_config}");
    }
    
    return $fts_config;
  }

  /**
   * Checks if vector search is enabled.
   *
   * @return bool
   *   TRUE if vector search is enabled.
   */
  protected function isVectorSearchEnabled() {
    return !empty($this->config['ai_embeddings']['enabled']) || 
           !empty($this->config['vector_search']['enabled']);
  }

  /**
   * Checks if pgvector extension is available.
   *
   * @return bool
   *   TRUE if pgvector is available.
   */
  protected function checkVectorSupport() {
    try {
      $sql = "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')";
      $stmt = $this->connector->executeQuery($sql);
      return (bool) $stmt->fetchColumn();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Builds the UPDATE SET clause for upsert operations.
   *
   * @param array $columns
   *   Array of quoted column names.
   * @param array $values
   *   Array of values.
   *
   * @return string
   *   The UPDATE SET clause.
   */
  protected function buildUpdateSet(array $columns, array $values) {
    $updates = [];
    
    foreach ($columns as $i => $column) {
      // Skip primary key
      if (strpos($column, 'search_api_id') !== FALSE) {
        continue;
      }
      
      $updates[] = "{$column} = EXCLUDED.{$column}";
    }
    
    return implode(', ', $updates);
  }
}