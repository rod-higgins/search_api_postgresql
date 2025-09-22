<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api_postgresql\Exception\GracefulDegradationException;
use Drupal\search_api_postgresql\Exception\VectorSearchDegradedException;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceUnavailableException;
use Drupal\search_api_postgresql\Exception\DegradationExceptionFactory;
use Drupal\search_api_postgresql\Service\CircuitBreakerService;

/**
 * Enhanced QueryBuilder with graceful degradation for vector search.
 */
class EnhancedVectorQueryBuilder extends QueryBuilder {

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * The circuit breaker service.
   *
   * @var \Drupal\search_api_postgresql\Service\CircuitBreakerService
   */
  protected $circuitBreaker;

  /**
   * Degradation state for current query.
   *
   * @var array
   */
  protected $degradationState = [
    'is_degraded' => FALSE,
    'degradation_reason' => NULL,
    'fallback_strategy' => NULL,
    'user_message' => NULL,
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config, $embedding_service = NULL, ?CircuitBreakerService $circuit_breaker = NULL) {
    parent::__construct($connector, $field_mapper, $config);
    $this->embeddingService = $embedding_service;
    $this->circuitBreaker = $circuit_breaker ?: \Drupal::service('search_api_postgresql.circuit_breaker');
  }

  /**
   * {@inheritdoc}
   */
  public function buildSearchQuery(QueryInterface $query) {
    try {
      return $this->buildQueryWithDegradation($query);
    }
    catch (GracefulDegradationException $e) {
      // Handle graceful degradation.
      $this->handleDegradation($e);
      return $this->buildFallbackQuery($query, $e->getFallbackStrategy());
    }
    catch (\Exception $e) {
      // Unexpected error - degrade gracefully.
      $degradation_exception = DegradationExceptionFactory::createFromException($e);
      $this->handleDegradation($degradation_exception);
      return $this->buildFallbackQuery($query, $degradation_exception->getFallbackStrategy());
    }
  }

  /**
   * Builds query with degradation handling.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   *
   * @throws \Drupal\search_api_postgresql\Exception\GracefulDegradationException
   *   When degradation is needed.
   */
  protected function buildQueryWithDegradation(QueryInterface $query) {
    // Check circuit breaker status.
    if ($this->circuitBreaker && !$this->circuitBreaker->canProceed('vector_search')) {
      throw new VectorSearchDegradedException(
        'Vector search circuit breaker is open',
        'text_only',
        'Circuit breaker protection is active due to recent failures.'
      );
    }

    // Check if vector search is enabled and available.
    if (!$this->isVectorSearchEnabled()) {
      return parent::buildSearchQuery($query);
    }

    $search_mode = $query->getOption('search_mode', 'hybrid');

    switch ($search_mode) {
      case 'vector_only':
        return $this->buildVectorSearchQueryWithDegradation($query);

      case 'text_only':
        return parent::buildSearchQuery($query);

      case 'hybrid':
      default:
        return $this->buildHybridSearchQueryWithDegradation($query);
    }
  }

  /**
   * Builds vector search query with degradation handling.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   *
   * @throws \Drupal\search_api_postgresql\Exception\GracefulDegradationException
   *   When degradation is needed.
   */
  protected function buildVectorSearchQueryWithDegradation(QueryInterface $query) {
    $keys = $query->getKeys();
    if (!$keys || !$this->embeddingService) {
      throw new EmbeddingServiceUnavailableException(
        'Embedding service unavailable for vector search',
        'text_only',
        'AI search is temporarily unavailable. Switching to text search.'
      );
    }

    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);

    try {
      $query_embedding = $this->embeddingService->generateEmbedding($search_text);

      // Record successful operation with circuit breaker.
      if ($this->circuitBreaker) {
        $this->circuitBreaker->recordSuccess('vector_search');
      }
    }
    catch (\Exception $e) {
      // Record failure with circuit breaker.
      if ($this->circuitBreaker) {
        $this->circuitBreaker->recordFailure('vector_search');
      }

      throw new EmbeddingServiceUnavailableException(
        'Failed to generate embedding: ' . $e->getMessage(),
        'text_only',
        'AI search encountered an error. Using traditional text search instead.'
      );
    }

    if (!$query_embedding) {
      throw new EmbeddingServiceUnavailableException(
        'Empty embedding returned',
        'text_only',
        'AI search returned no results. Trying text search instead.'
      );
    }

    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

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
   * Builds hybrid search query with degradation handling.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   *
   * @throws \Drupal\search_api_postgresql\Exception\GracefulDegradationException
   *   When degradation is needed.
   */
  protected function buildHybridSearchQueryWithDegradation(QueryInterface $query) {
    $keys = $query->getKeys();
    if (!$keys) {
      return parent::buildSearchQuery($query);
    }

    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    $query_embedding = NULL;

    // Try to get embedding, but don't fail if it's unavailable.
    if ($this->embeddingService) {
      try {
        $query_embedding = $this->embeddingService->generateEmbedding($search_text);

        // Record successful operation.
        if ($this->circuitBreaker) {
          $this->circuitBreaker->recordSuccess('vector_search');
        }
      }
      catch (\Exception $e) {
        // Record failure but continue with text-only search.
        if ($this->circuitBreaker) {
          $this->circuitBreaker->recordFailure('vector_search');
        }

        \Drupal::logger('search_api_postgresql')->warning('Embedding failed in hybrid search, continuing with text only: @message', [
          '@message' => $e->getMessage(),
        ]);

        // Set degradation state but don't throw exception.
        $this->degradationState = [
          'is_degraded' => TRUE,
          'degradation_reason' => 'embedding_service_partial_failure',
          'fallback_strategy' => 'text_only',
          'user_message' => 'AI search features are temporarily limited. Results are using traditional text search.',
        ];
      }
    }

    if (!$query_embedding) {
      // Fall back to text-only search within hybrid query.
      return parent::buildSearchQuery($query);
    }

    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

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
   * Handles degradation by setting state and logging.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   */
  protected function handleDegradation(GracefulDegradationException $exception) {
    $this->degradationState = [
      'is_degraded' => TRUE,
      'degradation_reason' => $exception->getDegradationReason(),
      'fallback_strategy' => $exception->getFallbackStrategy(),
      'user_message' => $exception->getUserMessage(),
    ];

    // Log degradation event.
    \Drupal::logger('search_api_postgresql')->info('Search degraded: @reason. Fallback: @strategy', [
      '@reason' => $exception->getMessage(),
      '@strategy' => $exception->getFallbackStrategy(),
    ]);
  }

  /**
   * Builds fallback query based on strategy.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param string $strategy
   *   The fallback strategy.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildFallbackQuery(QueryInterface $query, $strategy) {
    switch ($strategy) {
      case 'text_only':
        return parent::buildSearchQuery($query);

      case 'cached_results':
        // Try to get cached results, fallback to text if unavailable.
        return $this->buildCachedFallbackQuery($query);

      case 'simplified_vector':
        // Use a simplified vector approach.
        return $this->buildSimplifiedVectorQuery($query);

      default:
        return parent::buildSearchQuery($query);
    }
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

    // Add requested fields with validation.
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field_id);
      $fields[] = $safe_field;
    }

    // Add vector similarity score.
    $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS " . $this->connector->quoteColumnName('search_api_relevance');

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

    // Add requested fields with validation.
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field_id);
      $fields[] = $safe_field;
    }

    $fts_config = $this->config['fts_configuration'] ?? 'english';
    $search_vector_field = $this->connector->quoteColumnName('search_vector');

    // Enhanced hybrid scoring with fallback handling.
    $fields[] = "
      CASE 
        WHEN content_embedding IS NOT NULL AND {$search_vector_field} @@ to_tsquery('{$fts_config}', :ts_query) THEN
          GREATEST(
            {$text_weight} * ts_rank({$search_vector_field}, to_tsquery('{$fts_config}', :ts_query)),
            0.001
          ) +
          GREATEST(
            {$vector_weight} * (1 - (content_embedding <=> :query_embedding)),
            0.001
          )
        WHEN content_embedding IS NOT NULL THEN
          GREATEST(
            {$vector_weight} * (1 - (content_embedding <=> :query_embedding)),
            0.001
          )
        WHEN {$search_vector_field} @@ to_tsquery('{$fts_config}', :ts_query) THEN
          GREATEST(
            {$text_weight} * ts_rank({$search_vector_field}, to_tsquery('{$fts_config}', :ts_query)),
            0.001
          )
        ELSE 0.001
      END AS hybrid_score";

    $fields[] = "COALESCE(ts_rank({$search_vector_field}, to_tsquery('{$fts_config}', :ts_query)), 0) AS text_score";
    $fields[] = "COALESCE((1 - (content_embedding <=> :query_embedding)), 0) AS vector_score";
    $fields[] = "hybrid_score AS " . $this->connector->quoteColumnName('search_api_relevance');

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
   * Builds cached fallback query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildCachedFallbackQuery(QueryInterface $query) {
    // For now, just return text search - could be enhanced with actual caching.
    return parent::buildSearchQuery($query);
  }

  /**
   * Builds simplified vector query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildSimplifiedVectorQuery(QueryInterface $query) {
    // For now, just return text search - could be enhanced with cached embeddings.
    return parent::buildSearchQuery($query);
  }

  /**
   * Gets degradation state.
   *
   * @return array
   *   The degradation state.
   */
  public function getDegradationState() {
    return $this->degradationState;
  }

  /**
   * Checks if query is degraded.
   *
   * @return bool
   *   TRUE if degraded, FALSE otherwise.
   */
  public function isDegraded() {
    return $this->degradationState['is_degraded'];
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
   * Validates an index field and returns the quoted field name.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string $field_id
   *   The field ID to validate.
   *
   * @return string
   *   The quoted field name.
   *
   * @throws \InvalidArgumentException
   *   If the field is invalid.
   */
  protected function validateIndexField(IndexInterface $index, $field_id) {
    // Validate that the field exists in the index.
    if (!$index->getField($field_id)) {
      throw new \InvalidArgumentException("Field '{$field_id}' does not exist in index '{$index->id()}'");
    }

    return $this->connector->quoteColumnName($field_id);
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
