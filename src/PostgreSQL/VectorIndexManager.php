<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Enhanced IndexManager with vector search capabilities.
 */
class VectorIndexManager extends IndexManager {

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   * 
   * FIXED: Accept $configuration parameter and pass all 4 parameters to parent
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $configuration, $embedding_service = NULL) {
    // Call parent with only the 3 parameters it expects
    parent::__construct($connector, $field_mapper, $configuration);
    
    // Handle embedding service in this enhanced version
    $this->embeddingService = $embedding_service;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCreateTableSql($table_name, IndexInterface $index) {
    $sql = parent::buildCreateTableSql($table_name, $index);
    
    // Add vector column for embeddings
    $vector_dimension = $this->config['vector_dimension'] ?? 1536; // OpenAI default
    $sql = str_replace(
      "search_vector TSVECTOR\n);",
      "search_vector TSVECTOR,\n  content_embedding VECTOR({$vector_dimension})\n);"
    );

    return $sql;
  }

  /**
   * {@inheritdoc}
   */
  protected function createFullTextIndexes($table_name, IndexInterface $index) {
    parent::createFullTextIndexes($table_name, $index);
    
    // Create vector similarity index
    $index_method = $this->config['vector_index_method'] ?? 'ivfflat';
    $index_options = '';
    
    if ($index_method === 'ivfflat') {
      $lists = $this->config['ivfflat_lists'] ?? 100;
      $index_options = "WITH (lists = {$lists})";
    } elseif ($index_method === 'hnsw') {
      $m = $this->config['hnsw_m'] ?? 16;
      $ef_construction = $this->config['hnsw_ef_construction'] ?? 64;
      $index_options = "WITH (m = {$m}, ef_construction = {$ef_construction})";
    }

    $sql = "CREATE INDEX {$table_name}_vector_idx ON {$table_name} 
            USING {$index_method} (content_embedding vector_cosine_ops) {$index_options}";
    $this->connector->executeQuery($sql);
  }

  /**
   * {@inheritdoc}
   */
  protected function indexItem($table_name, IndexInterface $index, ItemInterface $item) {
    // Get the parent indexing logic
    $fields = $item->getFields(TRUE);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Map field values
    $searchable_text = '';
    foreach ($fields as $field_id => $field) {
      $field_values = $field->getValues();
      if (!empty($field_values)) {
        $field_value = reset($field_values);
        $values[$field_id] = $field_value;
        
        // Collect text for full-text search and embeddings
        if (in_array($field->getType(), ['text', 'string'])) {
          $searchable_text .= ' ' . $field_value;
        }
      }
    }

    // Generate embedding if service is available
    $embedding = NULL;
    if ($this->embeddingService && !empty(trim($searchable_text))) {
      try {
        $embedding = $this->embeddingService->generateEmbedding(trim($searchable_text));
      } catch (\Exception $e) {
        // Log error but continue indexing without embedding
        \Drupal::logger('search_api_postgresql')->warning(
          'Failed to generate embedding for item @id: @error',
          ['@id' => $item->getId(), '@error' => $e->getMessage()]
        );
      }
    }

    // Build full-text search vector
    $fts_config = $this->config['fts_configuration'] ?? 'english';
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";

    // Build INSERT query
    $placeholders = [];
    foreach ($values as $key => $value) {
      if ($key === 'search_vector') {
        $placeholders[] = $value; // Raw SQL function
      } elseif ($key === 'content_embedding' && $embedding) {
        $placeholders[] = ':content_embedding';
      } else {
        $placeholders[] = ":{$key}";
      }
    }

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', array_keys($values)) . 
                  ") VALUES (" . implode(', ', $placeholders) . ")";

    $params = [];
    foreach ($values as $key => $value) {
      if ($key !== 'search_vector') {
        $params[":{$key}"] = $value;
      }
    }
    $params[':searchable_text'] = trim($searchable_text);
    
    if ($embedding) {
      $params[':content_embedding'] = '[' . implode(',', $embedding) . ']';
    }

    $this->connector->executeQuery($insert_sql, $params);
  }
}

/**
 * Enhanced QueryBuilder with vector search capabilities.
 */
class VectorQueryBuilder extends QueryBuilder {

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   * 
   * FIXED: Accept $configuration parameter, parent only needs 3 parameters
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $configuration, $embedding_service = NULL) {
    // Parent QueryBuilder only expects 3 parameters (no embedding service)
    parent::__construct($connector, $field_mapper, $configuration);
    $this->embeddingService = $embedding_service;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSearchQuery(QueryInterface $query) {
    $search_mode = $query->getOption('search_mode', 'hybrid');
    
    switch ($search_mode) {
      case 'vector_only':
        return $this->buildVectorSearchQuery($query);
      
      case 'text_only':
        return parent::buildSearchQuery($query);
        
      case 'hybrid':
      default:
        return $this->buildHybridSearchQuery($query);
    }
  }

  /**
   * Builds a vector-only search query.
   */
  protected function buildVectorSearchQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);
    
    $keys = $query->getKeys();
    if (!$keys || !$this->embeddingService) {
      // Fallback to traditional search
      return parent::buildSearchQuery($query);
    }

    // Generate embedding for search query
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    $query_embedding = $this->embeddingService->generateEmbedding($search_text);
    
    if (!$query_embedding) {
      return parent::buildSearchQuery($query);
    }

    $sql_parts = [
      'SELECT' => $this->buildVectorSelectClause($query),
      'FROM' => $table_name,
      'WHERE' => $this->buildWhereClause($query),
      'ORDER' => $this->buildVectorOrderClause($query),
      'LIMIT' => $this->buildLimitClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);
    $params[':query_embedding'] = '[' . implode(',', $query_embedding) . ']';

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds a hybrid search query combining text and vector search.
   */
  protected function buildHybridSearchQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);
    
    $keys = $query->getKeys();
    if (!$keys) {
      return parent::buildSearchQuery($query);
    }

    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    
    // Try to generate embedding for hybrid search
    $query_embedding = NULL;
    if ($this->embeddingService) {
      try {
        $query_embedding = $this->embeddingService->generateEmbedding($search_text);
      } catch (\Exception $e) {
        // Fall back to text-only search if embedding fails
        return parent::buildSearchQuery($query);
      }
    }

    if (!$query_embedding) {
      return parent::buildSearchQuery($query);
    }

    // Build hybrid query
    $text_weight = $this->config['hybrid_search']['text_weight'] ?? 0.7;
    $vector_weight = $this->config['hybrid_search']['vector_weight'] ?? 0.3;

    $sql_parts = [
      'SELECT' => $this->buildHybridSelectClause($query, $text_weight, $vector_weight),
      'FROM' => $table_name,
      'WHERE' => $this->buildHybridWhereClause($query),
      'ORDER' => 'ORDER BY hybrid_score DESC',
      'LIMIT' => $this->buildLimitClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);
    $params[':query_embedding'] = '[' . implode(',', $query_embedding) . ']';

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds SELECT clause for vector search.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The SELECT clause.
   */
  protected function buildVectorSelectClause(QueryInterface $query) {
    $fields = [];
    
    // Add system fields
    $fields[] = 'search_api_id';
    $fields[] = 'search_api_datasource';
    $fields[] = 'search_api_language';
    
    // Add requested fields
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $field_id;
    }

    // ALWAYS add relevance score - required by Search API specification
    if ($query->getKeys() && $this->embeddingService) {
      // With search keys and embedding service: use vector similarity score
      $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS search_api_relevance";
    } else {
      // Without search keys or embedding service: provide default relevance value
      $fields[] = "1.0 AS search_api_relevance";
    }

    return implode(', ', $fields);
  }

  /**
   * Builds SELECT clause for hybrid search.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param float $text_weight
   *   Weight for text search scores.
   * @param float $vector_weight
   *   Weight for vector search scores.
   *
   * @return string
   *   The SELECT clause.
   */
  protected function buildHybridSelectClause(QueryInterface $query, $text_weight, $vector_weight) {
    $fields = [];
    
    // Add system fields
    $fields[] = 'search_api_id';
    $fields[] = 'search_api_datasource';
    $fields[] = 'search_api_language';
    
    // Add requested fields
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $field_id;
    }

    // ALWAYS add relevance score - required by Search API specification
    if ($query->getKeys() && $this->embeddingService) {
      // With search keys and embedding service: use hybrid scoring
      $fts_config = $this->config['fts_configuration'] ?? 'english';
      
      // Combine text and vector scores
      $fields[] = "
        (
          {$text_weight} * ts_rank(search_vector, to_tsquery('{$fts_config}', :ts_query)) +
          {$vector_weight} * (1 - (content_embedding <=> :query_embedding))
        ) AS hybrid_score";
      
      $fields[] = "ts_rank(search_vector, to_tsquery('{$fts_config}', :ts_query)) AS text_score";
      $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS vector_score";
      $fields[] = "hybrid_score AS search_api_relevance";
    } else {
      // Without search keys or embedding service: provide default relevance value
      $fields[] = "1.0 AS search_api_relevance";
    }

    return implode(', ', $fields);
  }

  /**
   * Builds WHERE clause for hybrid search.
   */
  protected function buildHybridWhereClause(QueryInterface $query) {
    $conditions = ['1=1'];

    // Include both text and vector conditions
    if ($keys = $query->getKeys()) {
      $fts_config = $this->config['fts_configuration'];
      $conditions[] = "(
        search_vector @@ to_tsquery('{$fts_config}', :ts_query) OR
        content_embedding IS NOT NULL
      )";
    }

    // Handle filters
    if ($condition_group = $query->getConditionGroup()) {
      $condition_sql = $this->buildConditionGroup($condition_group);
      if (!empty($condition_sql)) {
        $conditions[] = $condition_sql;
      }
    }

    // Handle language filtering
    if ($languages = $query->getLanguages()) {
      if ($languages !== [NULL]) {
        $language_placeholders = [];
        foreach ($languages as $i => $language) {
          $language_placeholders[] = ":language_{$i}";
        }
        $conditions[] = 'search_api_language IN (' . implode(', ', $language_placeholders) . ')';
      }
    }

    return implode(' AND ', $conditions);
  }

  /**
   * Builds ORDER clause for vector search.
   */
  protected function buildVectorOrderClause(QueryInterface $query) {
    $sorts = $query->getSorts();
    
    if (empty($sorts)) {
      return 'ORDER BY search_api_relevance DESC';
    }

    return parent::buildOrderClause($query);
  }

  /**
   * Extracts text from complex search keys structure.
   */
  protected function extractTextFromKeys($keys) {
    if (is_string($keys)) {
      return $keys;
    }

    $text_parts = [];
    foreach ($keys as $key => $value) {
      if ($key === '#conjunction') {
        continue;
      }
      
      if (is_array($value)) {
        $text_parts[] = $this->extractTextFromKeys($value);
      }
      else {
        $text_parts[] = $value;
      }
    }

    return implode(' ', $text_parts);
  }
}