<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Enhanced IndexManager with Azure AI vector search capabilities.
 */
class AzureVectorIndexManager extends IndexManager {

  /**
   * The Azure embedding service.
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
  protected function buildCreateTableSql($table_name, IndexInterface $index) {
    $sql = parent::buildCreateTableSql($table_name, $index);
    
    // Add vector column for embeddings
    $vector_dimension = $this->config['azure_embedding']['dimension'] ?? 1536;
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
    
    // Create vector similarity index optimized for Azure Database for PostgreSQL
    $index_method = $this->config['vector_index']['method'] ?? 'ivfflat';
    $index_options = '';
    
    if ($index_method === 'ivfflat') {
      $lists = $this->config['vector_index']['ivfflat_lists'] ?? 100;
      $index_options = "WITH (lists = {$lists})";
    } elseif ($index_method === 'hnsw') {
      $m = $this->config['vector_index']['hnsw_m'] ?? 16;
      $ef_construction = $this->config['vector_index']['hnsw_ef_construction'] ?? 64;
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
    $fields = $item->getFields(TRUE);
    $values = [
      'search_api_id' => $item->getId(),
      'search_api_datasource' => $item->getDatasourceId(),
      'search_api_language' => $item->getLanguage() ?: 'und',
    ];

    // Prepare searchable text for both tsvector and embeddings
    $searchable_text = '';
    $searchable_fields = $this->fieldMapper->getSearchableFields($index);

    foreach ($fields as $field_id => $field) {
      $field_values = $field->getValues();
      $field_type = $field->getType();

      if (!empty($field_values)) {
        $value = reset($field_values);
        $values[$field_id] = $this->fieldMapper->prepareFieldValue($value, $field_type);

        if (in_array($field_id, $searchable_fields)) {
          $searchable_text .= ' ' . $this->fieldMapper->prepareFieldValue($value, 'text');
        }
      }
      else {
        $values[$field_id] = NULL;
      }
    }

    // Generate embedding using Azure AI
    $embedding = NULL;
    if ($this->embeddingService && !empty(trim($searchable_text))) {
      try {
        $embedding = $this->embeddingService->generateEmbedding(trim($searchable_text));
      }
      catch (\Exception $e) {
        \Drupal::logger('search_api_postgresql')->error('Failed to generate Azure embedding: @message', [
          '@message' => $e->getMessage()
        ]);
      }
    }

    // Prepare tsvector value
    $fts_config = $this->config['fts_configuration'];
    $values['search_vector'] = "to_tsvector('{$fts_config}', :searchable_text)";
    
    // Prepare vector value
    if ($embedding) {
      $values['content_embedding'] = ':content_embedding';
    }

    // Delete existing item
    $delete_sql = "DELETE FROM {$table_name} WHERE search_api_id = :item_id";
    $this->connector->executeQuery($delete_sql, [':item_id' => $item->getId()]);

    // Insert new item
    $columns = array_keys($values);
    $placeholders = array_map(function($col) use ($values) {
      if ($col === 'search_vector') {
        return $values[$col];
      }
      return ":{$col}";
    }, $columns);

    $insert_sql = "INSERT INTO {$table_name} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

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
 * Azure AI embedding service interface.
 */
interface EmbeddingServiceInterface {

  /**
   * Generates an embedding for the given text.
   *
   * @param string $text
   *   The text to embed.
   *
   * @return array|null
   *   Array of floats representing the embedding, or NULL on failure.
   */
  public function generateEmbedding($text);

  /**
   * Gets the dimension of embeddings produced by this service.
   *
   * @return int
   *   The embedding dimension.
   */
  public function getDimension();
}

/**
 * Azure OpenAI embedding service implementation.
 */
class AzureOpenAIEmbeddingService implements EmbeddingServiceInterface {

  /**
   * The Azure OpenAI endpoint.
   *
   * @var string
   */
  protected $endpoint;

  /**
   * The Azure OpenAI API key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * The deployment name for the embedding model.
   *
   * @var string
   */
  protected $deploymentName;

  /**
   * The API version.
   *
   * @var string
   */
  protected $apiVersion;

  /**
   * The embedding dimension.
   *
   * @var int
   */
  protected $dimension;

  /**
   * Constructs an Azure OpenAI embedding service.
   *
   * @param string $endpoint
   *   The Azure OpenAI endpoint (e.g., https://myresource.openai.azure.com/).
   * @param string $api_key
   *   The Azure OpenAI API key.
   * @param string $deployment_name
   *   The deployment name for the embedding model.
   * @param string $api_version
   *   The API version (default: 2024-02-01).
   * @param int $dimension
   *   The embedding dimension (default: 1536 for text-embedding-ada-002).
   */
  public function __construct($endpoint, $api_key, $deployment_name, $api_version = '2024-02-01', $dimension = 1536) {
    $this->endpoint = rtrim($endpoint, '/');
    $this->apiKey = $api_key;
    $this->deploymentName = $deployment_name;
    $this->apiVersion = $api_version;
    $this->dimension = $dimension;
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbedding($text) {
    // Clean and prepare text
    $text = $this->preprocessText($text);
    
    if (empty($text)) {
      return NULL;
    }

    $url = sprintf(
      '%s/openai/deployments/%s/embeddings?api-version=%s',
      $this->endpoint,
      $this->deploymentName,
      $this->apiVersion
    );
    
    $data = [
      'input' => $text,
    ];

    $headers = [
      'Content-Type: application/json',
      'api-key: ' . $this->apiKey,
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => TRUE,
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error) {
      throw new \Exception('cURL error: ' . $error);
    }

    if ($http_code !== 200) {
      throw new \Exception('Azure OpenAI API error: HTTP ' . $http_code . ' - ' . $response);
    }

    $result = json_decode($response, TRUE);
    
    if (isset($result['error'])) {
      throw new \Exception('Azure OpenAI API error: ' . $result['error']['message']);
    }

    return $result['data'][0]['embedding'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension() {
    return $this->dimension;
  }

  /**
   * Preprocesses text before embedding generation.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   The preprocessed text.
   */
  protected function preprocessText($text) {
    // Remove excessive whitespace
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    // Limit text length (Azure OpenAI has token limits)
    $max_chars = 8000; // Conservative limit for token count
    if (strlen($text) > $max_chars) {
      $text = substr($text, 0, $max_chars);
      // Try to break at word boundary
      $last_space = strrpos($text, ' ');
      if ($last_space !== FALSE && $last_space > $max_chars * 0.8) {
        $text = substr($text, 0, $last_space);
      }
    }

    return $text;
  }
}

/**
 * Azure Cognitive Services Text Analytics embedding service.
 */
class AzureCognitiveServicesEmbeddingService implements EmbeddingServiceInterface {

  /**
   * The Azure Cognitive Services endpoint.
   *
   * @var string
   */
  protected $endpoint;

  /**
   * The subscription key.
   *
   * @var string
   */
  protected $subscriptionKey;

  /**
   * The API version.
   *
   * @var string
   */
  protected $apiVersion;

  /**
   * Constructs an Azure Cognitive Services embedding service.
   *
   * @param string $endpoint
   *   The Azure Cognitive Services endpoint.
   * @param string $subscription_key
   *   The subscription key.
   * @param string $api_version
   *   The API version (default: 2023-11-15-preview).
   */
  public function __construct($endpoint, $subscription_key, $api_version = '2023-11-15-preview') {
    $this->endpoint = rtrim($endpoint, '/');
    $this->subscriptionKey = $subscription_key;
    $this->apiVersion = $api_version;
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbedding($text) {
    $url = sprintf(
      '%s/language/:analyze-text?api-version=%s',
      $this->endpoint,
      $this->apiVersion
    );
    
    $data = [
      'kind' => 'SentimentAnalysis', // This would need to be updated when embedding API is available
      'analysisInput' => [
        'documents' => [
          [
            'id' => '1',
            'text' => $text,
            'language' => 'en',
          ],
        ],
      ],
    ];

    $headers = [
      'Content-Type: application/json',
      'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => TRUE,
    ]);

    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error) {
      throw new \Exception('cURL error: ' . $error);
    }

    if ($http_code !== 200) {
      throw new \Exception('Azure Cognitive Services API error: HTTP ' . $http_code);
    }

    // Note: This is a placeholder implementation
    // Azure Cognitive Services doesn't currently offer direct text embeddings
    // You would need to use Azure OpenAI Service for embeddings
    throw new \Exception('Azure Cognitive Services text embeddings not yet available. Please use Azure OpenAI Service instead.');
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension() {
    return 0; // Not applicable for this service
  }
}

/**
 * Enhanced QueryBuilder with Azure AI vector search capabilities.
 */
class AzureVectorQueryBuilder extends QueryBuilder {

  /**
   * The Azure embedding service.
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
   * Builds a vector-only search query using Azure embeddings.
   */
  protected function buildVectorSearchQuery(QueryInterface $query) {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableName($index);
    
    $keys = $query->getKeys();
    if (!$keys || !$this->embeddingService) {
      return parent::buildSearchQuery($query);
    }

    // Generate embedding for search query using Azure AI
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    
    try {
      $query_embedding = $this->embeddingService->generateEmbedding($search_text);
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Failed to generate query embedding: @message', [
        '@message' => $e->getMessage()
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
      'ORDER' => 'ORDER BY search_api_relevance DESC',
      'LIMIT' => $this->buildLimitClause($query),
    ];

    $sql = $this->assembleSqlQuery($sql_parts);
    $params = $this->getQueryParameters($query);
    $params[':query_embedding'] = '[' . implode(',', $query_embedding) . ']';

    return ['sql' => $sql, 'params' => $params];
  }

  /**
   * Builds a hybrid search query combining PostgreSQL FTS and Azure AI vectors.
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
      \Drupal::logger('search_api_postgresql')->error('Azure embedding failed, falling back to text search: @message', [
        '@message' => $e->getMessage()
      ]);
      return parent::buildSearchQuery($query);
    }

    if (!$query_embedding) {
      return parent::buildSearchQuery($query);
    }

    // Weights for combining scores
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
   */
  protected function buildVectorSelectClause(QueryInterface $query) {
    $fields = ['search_api_id', 'search_api_datasource', 'search_api_language'];
    
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $field_id;
    }

    // Azure vector similarity score using cosine distance
    $fields[] = "(1 - (content_embedding <=> :query_embedding)) AS search_api_relevance";

    return implode(', ', $fields);
  }

  /**
   * Builds WHERE clause for vector search.
   */
  protected function buildVectorWhereClause(QueryInterface $query) {
    $conditions = ['content_embedding IS NOT NULL'];

    // Add minimum similarity threshold
    $similarity_threshold = $this->config['hybrid_search']['similarity_threshold'] ?? 0.1;
    $conditions[] = "(1 - (content_embedding <=> :query_embedding)) >= {$similarity_threshold}";

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
   * Builds SELECT clause for hybrid search combining PostgreSQL FTS and Azure vectors.
   */
  protected function buildHybridSelectClause(QueryInterface $query, $text_weight, $vector_weight) {
    $fields = ['search_api_id', 'search_api_datasource', 'search_api_language'];
    
    foreach ($query->getIndex()->getFields() as $field_id => $field) {
      $fields[] = $field_id;
    }

    $fts_config = $this->config['fts_configuration'];
    
    // Combine PostgreSQL FTS scores with Azure vector similarity
    $fields[] = "
      CASE 
        WHEN content_embedding IS NOT NULL AND search_vector @@ to_tsquery('{$fts_config}', :ts_query) THEN
          {$text_weight} * ts_rank(search_vector, to_tsquery('{$fts_config}', :ts_query)) +
          {$vector_weight} * (1 - (content_embedding <=> :query_embedding))
        WHEN content_embedding IS NOT NULL THEN
          {$vector_weight} * (1 - (content_embedding <=> :query_embedding))
        WHEN search_vector @@ to_tsquery('{$fts_config}', :ts_query) THEN
          {$text_weight} * ts_rank(search_vector, to_tsquery('{$fts_config}', :ts_query))
        ELSE 0
      END AS hybrid_score";
    
    $fields[] = "ts_rank(search_vector, to_tsquery('{$fts_config}', :ts_query)) AS text_score";
    $fields[] = "COALESCE((1 - (content_embedding <=> :query_embedding)), 0) AS vector_score";
    $fields[] = "hybrid_score AS search_api_relevance";

    return implode(', ', $fields);
  }

  /**
   * Builds WHERE clause for hybrid search.
   */
  protected function buildHybridWhereClause(QueryInterface $query) {
    $conditions = ['1=1'];
    $fts_config = $this->config['fts_configuration'];

    // Include items that match either text search OR have vector similarity
    if ($keys = $query->getKeys()) {
      $similarity_threshold = $this->config['hybrid_search']['similarity_threshold'] ?? 0.1;
      $conditions[] = "(
        search_vector @@ to_tsquery('{$fts_config}', :ts_query) OR
        (content_embedding IS NOT NULL AND (1 - (content_embedding <=> :query_embedding)) >= {$similarity_threshold})
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