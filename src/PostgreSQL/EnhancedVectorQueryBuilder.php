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
  public function __construct(PostgreSQLConnector $connector, FieldMapper $field_mapper, array $config, $embedding_service = NULL, CircuitBreakerService $circuit_breaker = NULL) {
    parent::__construct($connector, $field_mapper, $config);
    $this->embeddingService = $embedding_service;
    $this->circuitBreaker = $circuit_breaker ?: \Drupal::service('search_api_postgresql.circuit_breaker');
  }

  /**
   * {@inheritdoc}
   */
  public function buildSearchQuery(QueryInterface $query) {
    // Reset degradation state for new query
    $this->resetDegradationState();

    // Check if vector search is enabled and available
    if (!$this->isVectorSearchEnabled()) {
      return $this->buildTextOnlyQuery($query);
    }

    $search_mode = $query->getOption('search_mode', 'hybrid');
    
    try {
      switch ($search_mode) {
        case 'vector_only':
          return $this->buildVectorSearchQueryWithFallback($query);
        
        case 'text_only':
          return parent::buildSearchQuery($query);
          
        case 'hybrid':
        default:
          return $this->buildHybridSearchQueryWithFallback($query);
      }
    }
    catch (GracefulDegradationException $e) {
      // Handle graceful degradation
      $this->handleDegradation($e);
      return $this->buildFallbackQuery($query, $e);
    }
    catch (\Exception $e) {
      // Convert unexpected exceptions to degradation
      $degradation_exception = DegradationExceptionFactory::createFromException($e, [
        'service_name' => 'Vector Search',
        'operation' => 'search query building',
      ]);
      
      $this->handleDegradation($degradation_exception);
      return $this->buildFallbackQuery($query, $degradation_exception);
    }
  }

  /**
   * Builds a vector search query with circuit breaker protection.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildVectorSearchQueryWithFallback(QueryInterface $query) {
    $keys = $query->getKeys();
    if (!$keys || !$this->embeddingService) {
      throw new VectorSearchDegradedException('No search keys or embedding service unavailable');
    }

    // Extract search text
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    
    // Use circuit breaker for embedding generation
    $query_embedding = $this->circuitBreaker->execute(
      'embedding_service',
      function() use ($search_text) {
        return $this->embeddingService->generateEmbedding($search_text);
      },
      function($exception) {
        // Fallback: return null to trigger text search
        throw new VectorSearchDegradedException('Embedding service circuit breaker open', $exception);
      },
      ['operation' => 'query_embedding_generation']
    );
    
    if (!$query_embedding) {
      throw new VectorSearchDegradedException('Failed to generate query embedding');
    }

    return $this->buildVectorOnlySQL($query, $query_embedding);
  }

  /**
   * Builds a hybrid search query with graceful degradation.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildHybridSearchQueryWithFallback(QueryInterface $query) {
    $keys = $query->getKeys();
    if (!$keys) {
      return parent::buildSearchQuery($query);
    }

    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    
    // Try to get embedding with circuit breaker protection
    $query_embedding = NULL;
    try {
      if ($this->embeddingService && $this->circuitBreaker->isServiceAvailable('embedding_service')) {
        $query_embedding = $this->circuitBreaker->execute(
          'embedding_service',
          function() use ($search_text) {
            return $this->embeddingService->generateEmbedding($search_text);
          },
          function($exception) {
            // Log degradation but continue with text-only search
            \Drupal::logger('search_api_postgresql')->info('Hybrid search degraded to text-only: @message', [
              '@message' => $exception->getMessage()
            ]);
            return NULL;
          },
          ['operation' => 'hybrid_query_embedding']
        );
      }
    }
    catch (\Exception $e) {
      // Embedding failed, but we can still do text search
      $this->setDegradationState(
        'Embedding generation failed, using text search only',
        'text_search_fallback',
        'Using traditional text search. Some semantic matching may be limited.'
      );
    }

    if ($query_embedding) {
      return $this->buildHybridSQL($query, $query_embedding);
    } else {
      // Degrade to text-only search
      $this->setDegradationState(
        'Vector search unavailable',
        'text_search_fallback',
        'Using traditional text search. Some semantic matching may be limited.'
      );
      return $this->buildTextOnlyQuery($query);
    }
  }

  /**
   * Builds fallback query based on degradation exception.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildFallbackQuery(QueryInterface $query, GracefulDegradationException $exception) {
    $strategy = $exception->getFallbackStrategy();
    
    switch ($strategy) {
      case 'text_search_only':
      case 'text_search_fallback':
        return $this->buildTextOnlyQuery($query);
        
      case 'retry_with_backoff':
        // For now, fall back to text search
        // In a more sophisticated implementation, this could implement retry logic
        return $this->buildTextOnlyQuery($query);
        
      case 'circuit_breaker_fallback':
        return $this->buildTextOnlyQuery($query);
        
      default:
        return parent::buildSearchQuery($query);
    }
  }

  /**
   * Builds a text-only search query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildTextOnlyQuery(QueryInterface $query) {
    // Use parent implementation for text search
    return parent::buildSearchQuery($query);
  }

  /**
   * Builds vector-only SQL query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param array $query_embedding
   *   The query embedding.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildVectorOnlySQL(QueryInterface $query, array $query_embedding) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);

    $sql_parts = [
      'SELECT' => $this->buildVectorSelectClause($query),
      'FROM' => $table_name,
      'WHERE' => $this->buildVectorWhereClause($query),
      'ORDER' => 'ORDER BY search_api_relevance DESC',
      'LIMIT' => $this->buildLimitClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);
    $params[':query_embedding'] = '[' . implode(',', $query_embedding) . ']';

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds hybrid SQL query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param array $query_embedding
   *   The query embedding.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildHybridSQL(QueryInterface $query, array $query_embedding) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);
    
    // Weights for combining scores
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
    $fields = ['search_api_id', 'search_api_datasource', 'search_api_language'];
    
    // Add requested fields
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field_id);
      $fields[] = $safe_field;
    }

    // Add vector similarity score
    $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS search_api_relevance";

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

    // Add minimum similarity threshold
    $similarity_threshold = $this->getSimilarityThreshold();
    $conditions[] = "(1 - (content_embedding <=> :query_embedding)) >= {$similarity_threshold}";

    // Handle filters
    if ($condition_group = $query->getConditionGroup()) {
      $condition_sql = $this->buildConditionGroup($condition_group, $query->getIndex());
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
        $language_field = $this->connector->validateFieldName('search_api_language');
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
    $fields = ['search_api_id', 'search_api_datasource', 'search_api_language'];
    
    // Add requested fields
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $safe_field = $this->validateIndexField($query->getIndex(), $field_id);
      $fields[] = $safe_field;
    }

    $fts_config = $this->validateFtsConfiguration();
    $search_vector_field = $this->connector->validateFieldName('search_vector');
    
    // Combine text and vector scores with NULL safety
    $fields[] = "
      CASE 
        WHEN content_embedding IS NOT NULL AND {$search_vector_field} @@ to_tsquery('{$fts_config}', :ts_query) THEN
          {$text_weight} * ts_rank({$search_vector_field}, to_tsquery('{$fts_config}', :ts_query)) +
          {$vector_weight} * (1 - (content_embedding <=> :query_embedding))
        WHEN content_embedding IS NOT NULL THEN
          {$vector_weight} * (1 - (content_embedding <=> :query_embedding))
        WHEN {$search_vector_field} @@ to_tsquery('{$fts_config}', :ts_query) THEN
          {$text_weight} * ts_rank({$search_vector_field}, to_tsquery('{$fts_config}', :ts_query))
        ELSE 0
      END AS hybrid_score";
    
    $fields[] = "COALESCE(ts_rank({$search_vector_field}, to_tsquery('{$fts_config}', :ts_query)), 0) AS text_score";
    $fields[] = "COALESCE((1 - (content_embedding <=> :query_embedding)), 0) AS vector_score";
    $fields[] = "hybrid_score AS search_api_relevance";

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
    $fts_config = $this->validateFtsConfiguration();
    $search_vector_field = $this->connector->validateFieldName('search_vector');

    // Include items that match either text search OR have vector similarity
    if ($keys = $query->getKeys()) {
      $similarity_threshold = $this->getSimilarityThreshold();
      $conditions[] = "(
        {$search_vector_field} @@ to_tsquery('{$fts_config}', :ts_query) OR
        (content_embedding IS NOT NULL AND (1 - (content_embedding <=> :query_embedding)) >= {$similarity_threshold})
      )";
    }

    // Handle filters
    if ($condition_group = $query->getConditionGroup()) {
      $condition_sql = $this->buildConditionGroup($condition_group, $query->getIndex());
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
        $language_field = $this->connector->validateFieldName('search_api_language');
        $conditions[] = $language_field . ' IN (' . implode(', ', $language_placeholders) . ')';
      }
    }

    return implode(' AND ', $conditions);
  }

  /**
   * Handles degradation by setting state and logging.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   */
  protected function handleDegradation(GracefulDegradationException $exception) {
    $this->setDegradationState(
      $exception->getMessage(),
      $exception->getFallbackStrategy(),
      $exception->getUserMessage()
    );

    if ($exception->shouldLog()) {
      \Drupal::logger('search_api_postgresql')->warning('Search degraded: @message', [
        '@message' => $exception->getMessage()
      ]);
    } else {
      \Drupal::logger('search_api_postgresql')->info('Search degraded: @message', [
        '@message' => $exception->getMessage()
      ]);
    }
  }

  /**
   * Sets the degradation state.
   *
   * @param string $reason
   *   The degradation reason.
   * @param string $strategy
   *   The fallback strategy.
   * @param string $user_message
   *   The user-friendly message.
   */
  protected function setDegradationState($reason, $strategy, $user_message) {
    $this->degradationState = [
      'is_degraded' => TRUE,
      'degradation_reason' => $reason,
      'fallback_strategy' => $strategy,
      'user_message' => $user_message,
    ];
  }

  /**
   * Resets the degradation state.
   */
  protected function resetDegradationState() {
    $this->degradationState = [
      'is_degraded' => FALSE,
      'degradation_reason' => NULL,
      'fallback_strategy' => NULL,
      'user_message' => NULL,
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
   * Checks if the current query is degraded.
   *
   * @return bool
   *   TRUE if degraded.
   */
  public function isDegraded() {
    return $this->degradationState['is_degraded'];
  }

  /**
   * Gets the user-friendly degradation message.
   *
   * @return string|null
   *   The user message, or NULL if not degraded.
   */
  public function getDegradationMessage() {
    return $this->degradationState['user_message'];
  }

  /**
   * Helper methods from parent functionality.
   */
  
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
           !empty($this->config['azure_embedding']['enabled']) ||
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
}