<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;

/**
 * OpenAI embedding service implementation with caching and retry logic.
 */
class OpenAIEmbeddingService implements EmbeddingServiceInterface
{
  /**
   * The OpenAI API endpoint.
   * {@inheritdoc}
   *
   * @var string
   */
  protected $endpoint;

  /**
   * The OpenAI API key.
   * {@inheritdoc}
   *
   * @var string
   */
  protected $apiKey;

  /**
   * The embedding model to use.
   * {@inheritdoc}
   *
   * @var string
   */
  protected $model;

  /**
   * The embedding dimension.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $dimension;

  /**
   * Maximum number of retries for API calls.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $maxRetries;

  /**
   * Delay between retries in milliseconds.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $retryDelay;

  /**
   * The embedding cache manager.
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

  /**
   * Maximum tokens per request.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $maxTokensPerRequest;

  /**
   * Organization ID (optional).
   * {@inheritdoc}
   *
   * @var string|null
   */
  protected $organizationId;

  /**
   * Request timeout in seconds.
   * {@inheritdoc}
   *
   * @var int
   */
  protected $timeout;

  /**
   * Available embedding models and their dimensions.
   * {@inheritdoc}
   *
   * @var array
   */
  protected static $modelDimensions = [
    'text-embedding-ada-002' => 1536,
    'text-embedding-3-small' => 1536,
    'text-embedding-3-large' => 3072,
  ];

  /**
   * Constructs an OpenAI embedding service.
   * {@inheritdoc}
   *
   * @param string $api_key
   *   The OpenAI API key.
   * @param string $model
   *   The embedding model (default: text-embedding-3-small).
   * @param int|null $dimension
   *   The embedding dimension (null for model default).
   * @param int $max_retries
   *   Maximum number of retries for API calls.
   * @param int $retry_delay
   *   Delay between retries in milliseconds.
   * @param \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager $cache_manager
   *   The embedding cache manager (optional).
   * @param string|null $organization_id
   *   OpenAI organization ID (optional).
   * @param int $timeout
   *   Request timeout in seconds.
   * @param string $endpoint
   *   Custom API endpoint (default: OpenAI's API).
   */
  public function __construct(
      $api_key,
      $model = 'text-embedding-3-small',
      $dimension = null,
      $max_retries = 3,
      $retry_delay = 1000,
      ?EmbeddingCacheManager $cache_manager = null,
      $organization_id = null,
      $timeout = 30,
      $endpoint = 'https://api.openai.com/v1/embeddings',
  ) {
    $this->apiKey = $api_key;
    $this->model = $model;
    $this->maxRetries = $max_retries;
    $this->retryDelay = $retry_delay;
    $this->cacheManager = $cache_manager;
    $this->organizationId = $organization_id;
    $this->timeout = $timeout;
    $this->endpoint = $endpoint;

    // Set dimension based on model or custom value.
    if ($dimension !== null) {
      $this->dimension = $dimension;
    } else {
      $this->dimension = self::$modelDimensions[$model] ?? 1536;
    }

    // Set max tokens based on model.
    $this->maxTokensPerRequest = $this->getMaxTokensForModel($model);
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbedding($text)
  {
    // Clean and prepare text.
    $text = $this->preprocessText($text);

    if (empty($text)) {
      return null;
    }

    // Try to get from cache first.
    if ($this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      $cached_embedding = $this->cacheManager->getCachedEmbedding($text, $metadata);

      if ($cached_embedding !== null) {
        return $cached_embedding;
      }
    }

    // Generate embedding via API.
    $embedding = $this->generateEmbeddingFromApi($text);

    // Cache the result if successful.
    if ($embedding && $this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      $this->cacheManager->cacheEmbedding($text, $embedding, $metadata);
    }

    return $embedding;
  }

  /**
   * {@inheritdoc}
   */
  public function generateBatchEmbeddings(array $texts)
  {
    if (empty($texts)) {
      return [];
    }

    // Preprocess all texts.
    $processed_texts = [];
    $original_indices = [];
    foreach ($texts as $index => $text) {
      $processed = $this->preprocessText($text);
      if (!empty($processed)) {
        $processed_texts[] = $processed;
        $original_indices[] = $index;
      }
    }

    if (empty($processed_texts)) {
      return [];
    }

    $final_embeddings = [];
    $texts_to_generate = [];
    $indices_to_generate = [];

    // Check cache for all texts.
    if ($this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      $cached_embeddings = $this->cacheManager->getCachedEmbeddingsBatch($processed_texts, $metadata);

      // Separate cached and uncached texts.
      foreach ($processed_texts as $i => $text) {
        $original_index = $original_indices[$i];

        if (isset($cached_embeddings[$i])) {
          $final_embeddings[$original_index] = $cached_embeddings[$i];
        } else {
          $texts_to_generate[] = $text;
          $indices_to_generate[] = $original_index;
        }
      }
    } else {
      // No cache, generate all embeddings.
      $texts_to_generate = $processed_texts;
      $indices_to_generate = $original_indices;
    }

    // Generate embeddings for uncached texts in batches.
    if (!empty($texts_to_generate)) {
      $batches = $this->splitIntoBatches($texts_to_generate);
      $batch_start_index = 0;

      foreach ($batches as $batch) {
        $batch_embeddings = $this->generateBatchEmbeddingsFromApi($batch);

        // Add batch embeddings to final result.
        foreach ($batch_embeddings as $i => $embedding) {
          $original_index = $indices_to_generate[$batch_start_index + $i];
          $final_embeddings[$original_index] = $embedding;
        }

        // Cache the batch embeddings.
        if ($this->cacheManager && !empty($batch_embeddings)) {
          $metadata = $this->getCacheMetadata();
          $this->cacheManager->cacheEmbeddingsBatch($batch, $batch_embeddings, $metadata);
        }

        $batch_start_index += count($batch);
      }
    }

    return $final_embeddings;
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension()
  {
    return $this->dimension;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable()
  {
    return !empty($this->apiKey) && !empty($this->model);
  }

  /**
   * Generates a single embedding from the API.
   * {@inheritdoc}
   *
   * @param string $text
   *   The preprocessed text.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   The embedding vector or NULL on failure.
   */
  protected function generateEmbeddingFromApi($text)
  {
    $data = [
      'input' => $text,
      'model' => $this->model,
    ];

    // Add dimensions parameter for models that support it.
    if (in_array($this->model, ['text-embedding-3-small', 'text-embedding-3-large'])) {
      $data['dimensions'] = $this->dimension;
    }

    $result = $this->makeApiCall($data, false);
    return $result;
  }

  /**
   * Generates batch embeddings from the API.
   * {@inheritdoc}
   *
   * @param array $texts
   *   Array of preprocessed texts.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array of embedding vectors.
   */
  protected function generateBatchEmbeddingsFromApi(array $texts)
  {
    $data = [
      'input' => $texts,
      'model' => $this->model,
    ];

    // Add dimensions parameter for models that support it.
    if (in_array($this->model, ['text-embedding-3-small', 'text-embedding-3-large'])) {
      $data['dimensions'] = $this->dimension;
    }

    $result = $this->makeApiCall($data, true);
    return $result ?: [];
  }

  /**
   * Makes an API call to OpenAI with retry logic.
   * {@inheritdoc}
   *
   * @param array $data
   *   The request data.
   * @param bool $is_batch
   *   Whether this is a batch request.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   The embedding(s) or NULL on failure.
   */
  protected function makeApiCall(array $data, $is_batch = false)
  {
    $headers = [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $this->apiKey,
    ];

    // Add organization header if provided.
    if ($this->organizationId) {
      $headers[] = 'OpenAI-Organization: ' . $this->organizationId;
    }

    $attempt = 0;
    while ($attempt <= $this->maxRetries) {
      $curl = curl_init();
      curl_setopt_array($curl, [
        CURLOPT_URL => $this->endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $this->timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Drupal Search API PostgreSQL Module',
      ]);

      $response = curl_exec($curl);
      $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $error = curl_error($curl);
      curl_close($curl);

      if ($error) {
        if ($attempt < $this->maxRetries) {
          $attempt++;
          usleep($this->retryDelay * 1000);
          continue;
        }
        throw new SearchApiException('cURL error: ' . $error);
      }

      if ($http_code === 200) {
        $result = json_decode($response, true);

        if (isset($result['error'])) {
          throw new SearchApiException('OpenAI API error: ' . $result['error']['message']);
        }

        if ($is_batch) {
          $embeddings = [];
          if (isset($result['data']) && is_array($result['data'])) {
            foreach ($result['data'] as $item) {
              $embeddings[] = $item['embedding'] ?? null;
            }
          }
          return $embeddings;
        } else {
          return $result['data'][0]['embedding'] ?? null;
        }
      }

      // Handle rate limiting and temporary errors.
      if (in_array($http_code, [429, 500, 502, 503, 504]) && $attempt < $this->maxRetries) {
        $attempt++;
        // Exponential backoff.
        $delay = $this->retryDelay * pow(2, $attempt - 1);

        // Parse retry-after header if available for 429 responses.
        if ($http_code === 429) {
          $retry_after = $this->parseRetryAfterHeader($response);
          if ($retry_after > 0) {
            // Use smaller of retry-after or exponential backoff.
            $delay = min($retry_after * 1000, $delay * 2);
          }
        }

        usleep($delay * 1000);
        continue;
      }

      // Parse error response for better error messages.
      $error_message = 'HTTP ' . $http_code;
      if ($response) {
        $error_data = json_decode($response, true);
        if (isset($error_data['error']['message'])) {
          $error_message .= ': ' . $error_data['error']['message'];
        } else {
          $error_message .= ' - ' . $response;
        }
      }

      throw new SearchApiException('OpenAI API error: ' . $error_message);
    }

    return null;
  }

  /**
   * Splits texts into batches based on token limits.
   * {@inheritdoc}
   *
   * @param array $texts
   *   Array of texts to split.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array of text batches.
   */
  protected function splitIntoBatches(array $texts)
  {
    $batches = [];
    $current_batch = [];
    $current_tokens = 0;

    foreach ($texts as $text) {
      $estimated_tokens = $this->estimateTokenCount($text);

      // If adding this text would exceed limits, start a new batch.
      if (!empty($current_batch) &&
            ($current_tokens + $estimated_tokens > $this->maxTokensPerRequest ||
            // OpenAI has a limit of 2048 inputs per batch.
            count($current_batch) >= 2048)
        ) {
        $batches[] = $current_batch;
        $current_batch = [];
        $current_tokens = 0;
      }

      $current_batch[] = $text;
      $current_tokens += $estimated_tokens;
    }

    if (!empty($current_batch)) {
      $batches[] = $current_batch;
    }

    return $batches;
  }

  /**
   * Estimates token count for text.
   * {@inheritdoc}
   *
   * @param string $text
   *   The text to estimate.
   *   {@inheritdoc}.
   *
   * @return int
   *   Estimated token count.
   */
  protected function estimateTokenCount($text)
  {
    // Rough estimation: ~4 characters per token for English text.
    return ceil(strlen($text) / 4);
  }

  /**
   * Gets cache metadata for this embedding service.
   * {@inheritdoc}
   *
   * @return array
   *   Metadata array for cache key generation.
   */
  protected function getCacheMetadata()
  {
    return [
      'service' => 'openai',
      'model' => $this->model,
      'dimension' => $this->dimension,
      'endpoint' => $this->endpoint,
    ];
  }

  /**
   * Preprocesses text before embedding generation.
   * {@inheritdoc}
   *
   * @param string $text
   *   The input text.
   *   {@inheritdoc}.
   *
   * @return string
   *   The preprocessed text.
   */
  protected function preprocessText($text)
  {
    // Remove excessive whitespace.
    $text = preg_replace('/\s+/', ' ', trim($text));

    // Remove null bytes and control characters.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

    // Limit text length based on model token limits.
    $max_chars = $this->getMaxCharsForModel($this->model);
    if (strlen($text) > $max_chars) {
      $text = substr($text, 0, $max_chars);
      // Try to break at word boundary.
      $last_space = strrpos($text, ' ');
      if ($last_space !== false && $last_space > $max_chars * 0.8) {
        $text = substr($text, 0, $last_space);
      }
    }

    return $text;
  }

  /**
   * Gets maximum characters for a given model.
   * {@inheritdoc}
   *
   * @param string $model
   *   The model name.
   *   {@inheritdoc}.
   *
   * @return int
   *   Maximum characters (conservative estimate).
   */
  protected function getMaxCharsForModel($model)
  {
    // Conservative character limits based on token limits
    // OpenAI models generally have 8192 token limits.
    $token_limits = [
      'text-embedding-ada-002' => 8192,
      'text-embedding-3-small' => 8192,
      'text-embedding-3-large' => 8192,
    ];

    $token_limit = $token_limits[$model] ?? 8192;
    // Conservative estimate: 3 characters per token on average.
    return $token_limit * 3;
  }

  /**
   * Gets maximum tokens per request for a model.
   * {@inheritdoc}
   *
   * @param string $model
   *   The model name.
   *   {@inheritdoc}.
   *
   * @return int
   *   Maximum tokens per request.
   */
  protected function getMaxTokensForModel($model)
  {
    // Standard limit for OpenAI embedding models.
    return 8192;
  }

  /**
   * Parses retry-after header from API response.
   * {@inheritdoc}
   *
   * @param string $response
   *   The API response.
   *   {@inheritdoc}.
   *
   * @return int
   *   Retry after seconds, or 0 if not found.
   */
  protected function parseRetryAfterHeader($response)
  {
    // This is a simplified implementation
    // In practice, you'd parse the actual HTTP headers.
    return 0;
  }

  /**
   * Gets cache statistics for this service.
   * {@inheritdoc}
   *
   * @return array
   *   Cache statistics if cache manager is available.
   */
  public function getCacheStats()
  {
    if ($this->cacheManager) {
      return $this->cacheManager->getCacheStatistics();
    }
    return ['cache_enabled' => false];
  }

  /**
   * Invalidates cache entries for this service.
   * {@inheritdoc}
   *
   * @return bool
   *   true if cache was invalidated.
   */
  public function invalidateCache()
  {
    if ($this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      return $this->cacheManager->invalidateByMetadata($metadata) > 0;
    }
    return false;
  }

  /**
   * Gets service information and configuration.
   * {@inheritdoc}
   *
   * @return array
   *   Service information.
   */
  public function getServiceInfo()
  {
    return [
      'service_type' => 'openai_direct',
      'model' => $this->model,
      'dimension' => $this->dimension,
      'endpoint' => $this->endpoint,
      'max_retries' => $this->maxRetries,
      'timeout' => $this->timeout,
      'cache_enabled' => $this->cacheManager !== null,
      'organization_id' => $this->organizationId ? '[SET]' : null,
    ];
  }

  /**
   * Tests the service connectivity.
   * {@inheritdoc}
   *
   * @return array
   *   Test results.
   */
  public function testConnection()
  {
    try {
      $test_text = 'test connection';
      $embedding = $this->generateEmbeddingFromApi($test_text);

      if ($embedding && is_array($embedding) && count($embedding) === $this->dimension) {
        return [
          'success' => true,
          'message' => 'Connection successful',
          'dimension' => count($embedding),
          'model' => $this->model,
        ];
      }

      return [
        'success' => false,
        'message' => 'Invalid response from API',
        'dimension' => $embedding ? count($embedding) : 0,
      ];
    } catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Connection failed: ' . $e->getMessage(),
        'exception' => get_class($e),
      ];
    }
  }

  /**
   * Gets available models for OpenAI embeddings.
   * {@inheritdoc}
   *
   * @return array
   *   Available models with their dimensions.
   */
  public static function getAvailableModels()
  {
    return self::$modelDimensions;
  }

  /**
   * Validates a model name.
   * {@inheritdoc}
   *
   * @param string $model
   *   The model name to validate.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if model is valid.
   */
  public static function isValidModel($model)
  {
    return isset(self::$modelDimensions[$model]);
  }

  /**
   * Gets the default dimension for a model.
   * {@inheritdoc}
   *
   * @param string $model
   *   The model name.
   *   {@inheritdoc}.
   *
   * @return int|null
   *   Default dimension or NULL if model is invalid.
   */
  public static function getModelDimension($model)
  {
    return self::$modelDimensions[$model] ?? null;
  }
}
