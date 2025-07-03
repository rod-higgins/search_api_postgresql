<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;

/**
 * Azure OpenAI embedding service implementation with caching.
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
   * Maximum number of retries for API calls.
   *
   * @var int
   */
  protected $maxRetries;

  /**
   * Delay between retries in milliseconds.
   *
   * @var int
   */
  protected $retryDelay;

  /**
   * The embedding cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

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
   * @param int $max_retries
   *   Maximum number of retries for API calls.
   * @param int $retry_delay
   *   Delay between retries in milliseconds.
   * @param \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager $cache_manager
   *   The embedding cache manager (optional).
   */
  public function __construct($endpoint, $api_key, $deployment_name, $api_version = '2024-02-01', $dimension = 1536, $max_retries = 3, $retry_delay = 1000, EmbeddingCacheManager $cache_manager = NULL) {
    $this->endpoint = rtrim($endpoint, '/');
    $this->apiKey = $api_key;
    $this->deploymentName = $deployment_name;
    $this->apiVersion = $api_version;
    $this->dimension = $dimension;
    $this->maxRetries = $max_retries;
    $this->retryDelay = $retry_delay;
    $this->cacheManager = $cache_manager;
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

    // Try to get from cache first
    if ($this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      $cached_embedding = $this->cacheManager->getCachedEmbedding($text, $metadata);
      
      if ($cached_embedding !== NULL) {
        return $cached_embedding;
      }
    }

    // Generate embedding via API
    $embedding = $this->generateEmbeddingFromApi($text);

    // Cache the result if successful
    if ($embedding && $this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      $this->cacheManager->cacheEmbedding($text, $embedding, $metadata);
    }

    return $embedding;
  }

  /**
   * {@inheritdoc}
   */
  public function generateBatchEmbeddings(array $texts) {
    if (empty($texts)) {
      return [];
    }

    // Preprocess all texts
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

    // Check cache for all texts
    if ($this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      $cached_embeddings = $this->cacheManager->getCachedEmbeddingsBatch($processed_texts, $metadata);

      // Separate cached and uncached texts
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
      // No cache, generate all embeddings
      $texts_to_generate = $processed_texts;
      $indices_to_generate = $original_indices;
    }

    // Generate embeddings for uncached texts
    if (!empty($texts_to_generate)) {
      $new_embeddings = $this->generateBatchEmbeddingsFromApi($texts_to_generate);

      // Add new embeddings to final result
      foreach ($new_embeddings as $i => $embedding) {
        if (isset($indices_to_generate[$i])) {
          $original_index = $indices_to_generate[$i];
          $final_embeddings[$original_index] = $embedding;
        }
      }

      // Cache the new embeddings
      if ($this->cacheManager && !empty($new_embeddings)) {
        $metadata = $this->getCacheMetadata();
        $this->cacheManager->cacheEmbeddingsBatch($texts_to_generate, $new_embeddings, $metadata);
      }
    }

    return $final_embeddings;
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension() {
    return $this->dimension;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return !empty($this->endpoint) && !empty($this->apiKey) && !empty($this->deploymentName);
  }

  /**
   * Tests the Azure OpenAI service connectivity.
   *
   * @return array
   *   Test results with success status and details.
   */
  public function testConnection() {
    try {
      // Test with a simple embedding generation
      $test_text = 'test connection';
      $embedding = $this->generateEmbedding($test_text);
      
      if ($embedding && is_array($embedding) && count($embedding) === $this->dimension) {
        return [
          'success' => TRUE,
          'message' => 'Azure OpenAI connection successful',
          'endpoint' => $this->endpoint,
          'deployment' => $this->deploymentName,
          'dimension' => count($embedding),
          'api_version' => $this->apiVersion,
        ];
      }
      
      return [
        'success' => FALSE,
        'message' => 'Invalid response from Azure OpenAI API',
        'endpoint' => $this->endpoint,
        'dimension' => $embedding ? count($embedding) : 0,
      ];
      
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Azure OpenAI connection failed: ' . $e->getMessage(),
        'endpoint' => $this->endpoint,
        'exception' => get_class($e),
      ];
    }
  }

  /**
   * Generates a single embedding from the API.
   *
   * @param string $text
   *   The preprocessed text.
   *
   * @return array|null
   *   The embedding vector or NULL on failure.
   */
  protected function generateEmbeddingFromApi($text) {
    $url = sprintf(
      '%s/openai/deployments/%s/embeddings?api-version=%s',
      $this->endpoint,
      $this->deploymentName,
      $this->apiVersion
    );
    
    $data = [
      'input' => $text,
    ];

    $result = $this->makeApiCall($url, $data, FALSE);
    return $result;
  }

  /**
   * Generates batch embeddings from the API.
   *
   * @param array $texts
   *   Array of preprocessed texts.
   *
   * @return array
   *   Array of embedding vectors.
   */
  protected function generateBatchEmbeddingsFromApi(array $texts) {
    $url = sprintf(
      '%s/openai/deployments/%s/embeddings?api-version=%s',
      $this->endpoint,
      $this->deploymentName,
      $this->apiVersion
    );
    
    $data = [
      'input' => $texts,
    ];

    $result = $this->makeApiCall($url, $data, TRUE);
    return $result ?: [];
  }

  /**
   * Makes an API call to Azure OpenAI with retry logic.
   *
   * @param string $url
   *   The API endpoint URL.
   * @param array $data
   *   The request data.
   * @param bool $is_batch
   *   Whether this is a batch request.
   *
   * @return array|null
   *   The embedding(s) or NULL on failure.
   */
  protected function makeApiCall($url, array $data, $is_batch = FALSE) {
    $headers = [
      'Content-Type: application/json',
      'api-key: ' . $this->apiKey,
    ];

    $attempt = 0;
    while ($attempt <= $this->maxRetries) {
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
        if ($attempt < $this->maxRetries) {
          $attempt++;
          usleep($this->retryDelay * 1000);
          continue;
        }
        throw new SearchApiException('cURL error: ' . $error);
      }

      if ($http_code === 200) {
        $result = json_decode($response, TRUE);
        
        if (isset($result['error'])) {
          throw new SearchApiException('Azure OpenAI API error: ' . $result['error']['message']);
        }

        if ($is_batch) {
          $embeddings = [];
          foreach ($result['data'] as $item) {
            $embeddings[] = $item['embedding'];
          }
          return $embeddings;
        } else {
          return $result['data'][0]['embedding'] ?? NULL;
        }
      }

      // Handle rate limiting and temporary errors
      if (in_array($http_code, [429, 500, 502, 503, 504]) && $attempt < $this->maxRetries) {
        $attempt++;
        $delay = $this->retryDelay * pow(2, $attempt - 1); // Exponential backoff
        usleep($delay * 1000);
        continue;
      }

      throw new SearchApiException('Azure OpenAI API error: HTTP ' . $http_code . ' - ' . $response);
    }

    return NULL;
  }

  /**
   * Gets cache metadata for this embedding service.
   *
   * @return array
   *   Metadata array for cache key generation.
   */
  protected function getCacheMetadata() {
    return [
      'service' => 'azure_openai',
      'endpoint' => $this->endpoint,
      'deployment' => $this->deploymentName,
      'api_version' => $this->apiVersion,
      'dimension' => $this->dimension,
    ];
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
    
    // Remove null bytes and control characters
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    
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

  /**
   * Gets cache statistics for this service.
   *
   * @return array
   *   Cache statistics if cache manager is available.
   */
  public function getCacheStats() {
    if ($this->cacheManager) {
      return $this->cacheManager->getCacheStatistics();
    }
    return ['cache_enabled' => FALSE];
  }

  /**
   * Invalidates cache entries for this service.
   *
   * @return bool
   *   TRUE if cache was invalidated.
   */
  public function invalidateCache() {
    if ($this->cacheManager) {
      $metadata = $this->getCacheMetadata();
      return $this->cacheManager->invalidateByMetadata($metadata) > 0;
    }
    return FALSE;
  }

}