<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\Query\QueryInterface;

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
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config, $embedding_service = NULL) {
    parent::__construct($connector, $field_mapper, $config);
    $this->embeddingService = $embedding_service;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSearchQuery(QueryInterface $query) {
    // Handle queries without search keys using parent method.
    if (!$query->getKeys()) {
      return parent::buildSearchQuery($query);
    }

    // Check if vector search is enabled and available.
    if (!$this->isVectorSearchEnabled()) {
      return parent::buildSearchQuery($query);
    }

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
   * {@inheritdoc}
   *
   * Overrides parent to ensure search_api_relevance is always included.
   */
  protected function buildSelectClause(QueryInterface $query) {
    $fields = [];

    // Add system fields (properly quoted)
    $fields[] = $this->connector->quoteColumnName('search_api_id');
    $fields[] = $this->connector->quoteColumnName('search_api_datasource');
    $fields[] = $this->connector->quoteColumnName('search_api_language');

    // Add requested fields from the index.
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field_id);
      $fields[] = $safe_field;
    }

    // ALWAYS add relevance score - required by Search API specification.
    if ($query->getKeys()) {
      // With search keys: use actual relevance calculation.
      $fts_config = $this->validateFtsConfiguration();
      $fields[] = "ts_rank(" . $this->connector->quoteColumnName('search_vector') .
        ", to_tsquery('{$fts_config}', :ts_query)) AS " .
        $this->connector->quoteColumnName('search_api_relevance');
    }
    else {
      // Without search keys: provide default relevance value.
      $fields[] = "1.0 AS " . $this->connector->quoteColumnName('search_api_relevance');
    }

    return implode(', ', $fields);
  }

  /**
   * Builds a vector-only search query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildVectorSearchQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

    $keys = $query->getKeys();
    if (!$keys || !$this->embeddingService) {
      // Fallback to traditional search.
      return parent::buildSearchQuery($query);
    }

    // Generate embedding for search query.
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);

    try {
      $query_embedding = $this->embeddingService->generateEmbedding($search_text);
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Failed to generate query embedding: @message', [
        '@message' => $e->getMessage(),
      ]);
      return parent::buildSearchQuery($query);
    }

    if (!$query_embedding) {
      return parent::buildSearchQuery($query);
    }

    $sql_parts = [
      'SELECT' => $this->buildVectorSelectClause($query),
      'FROM' => $table_name,
      'WHERE' => $this->buildVectorWhereClause($query),
      'ORDER' => 'ORDER BY ' . $this->connector->quoteColumnName('search_api_relevance') . ' DESC',
      'LIMIT' => $this->buildLimitClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);
    $params[':query_embedding'] = '[' . implode(',', $query_embedding) . ']';

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds a hybrid search query combining text and vector search.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildHybridSearchQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

    $keys = $query->getKeys();
    if (!$keys) {
      return parent::buildSearchQuery($query);
    }

    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);

    try {
      $query_embedding = $this->embeddingService ? $this->embeddingService->generateEmbedding($search_text) : NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Embedding failed, falling back to text search: @message', [
        '@message' => $e->getMessage(),
      ]);
      return parent::buildSearchQuery($query);
    }

    if (!$query_embedding) {
      return parent::buildSearchQuery($query);
    }

    // Weights for combining scores.
    $text_weight = $this->getTextWeight();
    $vector_weight = $this->getVectorWeight();

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

    // Add system fields (properly quoted)
    $fields[] = $this->connector->quoteColumnName('search_api_id');
    $fields[] = $this->connector->quoteColumnName('search_api_datasource');
    $fields[] = $this->connector->quoteColumnName('search_api_language');

    // Add requested fields.
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $this->connector->quoteColumnName($field_id);
    }

    // ALWAYS add relevance score - required by Search API specification.
    if ($query->getKeys() && $this->embeddingService) {
      // With search keys and embedding service: use vector similarity score.
      $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS " .
        $this->connector->quoteColumnName('search_api_relevance');
    }
    else {
      // Without search keys or embedding service: provide default relevance value.
      $fields[] = "1.0 AS " . $this->connector->quoteColumnName('search_api_relevance');
    }

    return implode(', ', $fields);
  }

  /**
   * Builds WHERE clause for vector search.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The WHERE clause.
   */
  protected function buildVectorWhereClause(QueryInterface $query) {
    $conditions = ['content_embedding IS NOT NULL'];

    // Add minimum similarity threshold.
    $similarity_threshold = $this->getSimilarityThreshold();
    $conditions[] = "(1 - (content_embedding <=> :query_embedding)) >= {$similarity_threshold}";

    // Handle filters.
    if ($condition_group = $query->getConditionGroup()) {
      $condition_sql = $this->buildConditionGroupSql($condition_group, $query->getIndex());
      if (!empty($condition_sql)) {
        $conditions[] = $condition_sql;
      }
    }

    // Handle language filtering (properly quoted)
    if ($languages = $query->getLanguages()) {
      if ($languages !== [NULL]) {
        $language_placeholders = [];
        foreach ($languages as $i => $language) {
          $language_placeholders[] = ":language_{$i}";
        }
        $language_field = $this->connector->quoteColumnName('search_api_language');
        $conditions[] = $language_field . ' IN (' . implode(', ', $language_placeholders) . ')';
      }
    }

    return implode(' AND ', $conditions);
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

    // Add system fields (properly quoted)
    $fields[] = $this->connector->quoteColumnName('search_api_id');
    $fields[] = $this->connector->quoteColumnName('search_api_datasource');
    $fields[] = $this->connector->quoteColumnName('search_api_language');

    // Add requested fields.
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $this->connector->quoteColumnName($field_id);
    }

    // ALWAYS add relevance score - required by Search API specification.
    if ($query->getKeys() && $this->embeddingService) {
      // With search keys and embedding service: use hybrid scoring.
      $fts_config = $this->config['fts_configuration'] ?? 'english';

      // Combine text and vector scores.
      $fields[] = "
        (
          {$text_weight} * ts_rank(" . $this->connector->quoteColumnName('search_vector') .
        ", to_tsquery('{$fts_config}', :ts_query)) +
          {$vector_weight} * (1 - (content_embedding <=> :query_embedding))
        ) AS hybrid_score";

      $fields[] = "ts_rank(" . $this->connector->quoteColumnName('search_vector') .
        ", to_tsquery('{$fts_config}', :ts_query)) AS text_score";
      $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS vector_score";
      $fields[] = "hybrid_score AS " . $this->connector->quoteColumnName('search_api_relevance');
    }
    else {
      // Without search keys or embedding service: provide default relevance value.
      $fields[] = "1.0 AS " . $this->connector->quoteColumnName('search_api_relevance');
    }

    return implode(', ', $fields);
  }

  /**
   * Builds WHERE clause for hybrid search.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return string
   *   The WHERE clause.
   */
  protected function buildHybridWhereClause(QueryInterface $query) {
    $conditions = ['1=1'];
    $search_vector_field = $this->connector->quoteColumnName('search_vector');

    // Include items that match either text search OR have vector similarity.
    if ($keys = $query->getKeys()) {
      $fts_config = $this->config['fts_configuration'] ?? 'english';
      $similarity_threshold = $this->getSimilarityThreshold();
      $conditions[] = "(
        {$search_vector_field} @@ to_tsquery('{$fts_config}', :ts_query) OR
        (content_embedding IS NOT NULL AND (1 - (content_embedding <=> :query_embedding)) >= {$similarity_threshold})
      )";
    }

    // Handle filters.
    if ($condition_group = $query->getConditionGroup()) {
      $condition_sql = $this->buildConditionGroupSql($condition_group, $query->getIndex());
      if (!empty($condition_sql)) {
        $conditions[] = $condition_sql;
      }
    }

    // Handle language filtering (properly quoted)
    if ($languages = $query->getLanguages()) {
      if ($languages !== [NULL]) {
        $language_placeholders = [];
        foreach ($languages as $i => $language) {
          $language_placeholders[] = ":language_{$i}";
        }
        $language_field = $this->connector->quoteColumnName('search_api_language');
        $conditions[] = $language_field . ' IN (' . implode(', ', $language_placeholders) . ')';
      }
    }

    return implode(' AND ', $conditions);
  }

  /**
   * Extracts text from complex search keys structure.
   *
   * @param mixed $keys
   *   The search keys.
   *
   * @return string
   *   The extracted text.
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

  /**
   * Checks if vector search is enabled.
   *
   * @return bool
   *   TRUE if vector search is enabled, FALSE otherwise.
   */
  protected function isVectorSearchEnabled() {
    return !empty($this->config['vector_search']['enabled']) ||
       !empty($this->config['ai_embeddings']['enabled']);
  }

  /**
   * Gets the text search weight from configuration.
   *
   * @return float
   *   The text search weight.
   */
  protected function getTextWeight() {
    return $this->config['hybrid_search']['text_weight'] ?? 0.7;
  }

  /**
   * Gets the vector search weight from configuration.
   *
   * @return float
   *   The vector search weight.
   */
  protected function getVectorWeight() {
    return $this->config['hybrid_search']['vector_weight'] ?? 0.3;
  }

  /**
   * Gets the similarity threshold from configuration.
   *
   * @return float
   *   The similarity threshold.
   */
  protected function getSimilarityThreshold() {
    return $this->config['hybrid_search']['similarity_threshold'] ?? 0.1;
  }

  /**
   * Assembles SQL parts into a complete query.
   *
   * @param array $parts
   *   Array of SQL parts.
   *
   * @return string
   *   The assembled SQL query.
   */
  protected function assembleSqlQuery(array $parts) {
    $sql = [];

    foreach (['SELECT', 'FROM', 'WHERE', 'ORDER', 'LIMIT'] as $clause) {
      if (!empty($parts[$clause])) {
        if ($clause === 'SELECT') {
          $sql[] = 'SELECT ' . $parts[$clause];
        }
        elseif ($clause === 'FROM' || $clause === 'WHERE') {
          $sql[] = $clause . ' ' . $parts[$clause];
        }
        else {
          $sql[] = $parts[$clause];
        }
      }
    }

    return implode("\n", $sql);
  }

}
