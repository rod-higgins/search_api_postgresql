<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for generating text embeddings using Azure AI Services with caching.
 */
class EmbeddingService {

  /**
   * The Azure AI configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The API key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The embedding cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

  /**
   * Constructs an EmbeddingService object.
   *
   * @param array $config
   *   The full backend configuration including AI embeddings settings.
   */
  public function __construct(array $config) {
    // Extract Azure AI configuration from the full config
    $this->config = $config['azure_ai'] ?? $config;
    
    // Extract API key from config
    $this->apiKey = $this->config['api_key'] ?? '';
    
    // Create a simple logger if not injected
    $this->logger = \Drupal::logger('search_api_postgresql');
    
    // Initialize HTTP client with Azure AI settings
    $headers = [
      'Content-Type' => 'application/json',
    ];
    
    if (!empty($this->apiKey)) {
      $headers['api-key'] = $this->apiKey;
    }
    
    $this->httpClient = new Client([
      'timeout' => $this->config['timeout'] ?? 30,
      'headers' => $headers,
    ]);
  }

  /**
   * Sets the logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function setLogger(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Sets the cache manager.
   *
   * @param \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager $cache_manager
   *   The cache manager.
   */
  public function setCacheManager(EmbeddingCacheManager $cache_manager) {
    $this->cacheManager = $cache_manager;
  }

  /**
   * Sets the API key.
   *
   * @param string $api_key
   *   The API key.
   */
  public function setApiKey($api_key) {
    $this->apiKey = $api_key;
    // Update HTTP client headers
    $this->httpClient = new Client([
      'timeout' => $this->config['timeout'] ?? 30,
      'headers' => [
        'Content-Type' => 'application/json',
        'api-key' => $this->apiKey,
      ],
    ]);
  }

  /**
   * Generates embeddings for given texts with caching.
   *
   * @param array|string $texts
   *   Array of text strings or a single string to generate embeddings for.
   *
   * @return array
   *   Array of embedding vectors or single vector if input was string.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If embedding generation fails.
   */
  public function generateEmbeddings($texts) {
    // Handle single text input
    $single_input = FALSE;
    if (is_string($texts)) {
      $texts = [$texts];
      $single_input = TRUE;
    }

    if (empty($texts)) {
      return [];
    }

    // Check cache first if available
    $uncached_texts = [];
    $cached_embeddings = [];
    $text_indices = [];

    if ($this->cacheManager) {
      foreach ($texts as $index => $text) {
        $cached = $this->cacheManager->get($text);
        if ($cached !== NULL) {
          $cached_embeddings[$index] = $cached;
        }
        else {
          $uncached_texts[] = $text;
          $text_indices[] = $index;
        }
      }
    }
    else {
      $uncached_texts = $texts;
      $text_indices = array_keys($texts);
    }

    // Generate embeddings for uncached texts
    $new_embeddings = [];
    if (!empty($uncached_texts)) {
      $new_embeddings = $this->callAzureApi($uncached_texts);
      
      // Cache the new embeddings
      if ($this->cacheManager) {
        foreach ($new_embeddings as $i => $embedding) {
          $this->cacheManager->set($uncached_texts[$i], $embedding);
        }
      }
    }

    // Combine cached and new embeddings in correct order
    $all_embeddings = [];
    $new_embedding_index = 0;
    
    foreach ($texts as $index => $text) {
      if (isset($cached_embeddings[$index])) {
        $all_embeddings[] = $cached_embeddings[$index];
      }
      else {
        $all_embeddings[] = $new_embeddings[$new_embedding_index++];
      }
    }

    // Return single embedding if input was single text
    return $single_input ? $all_embeddings[0] : $all_embeddings;
  }

  /**
   * Alias for generateEmbeddings for single text input.
   *
   * @param string $text
   *   The text to generate embedding for.
   *
   * @return array
   *   The embedding vector.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If embedding generation fails.
   */
  public function generateEmbedding($text) {
    return $this->generateEmbeddings($text);
  }

  /**
   * Calls Azure OpenAI API to generate embeddings.
   *
   * @param array $texts
   *   Array of texts to generate embeddings for.
   *
   * @return array
   *   Array of embedding vectors.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If API call fails.
   */
  protected function callAzureApi(array $texts) {
    if (empty($this->config['endpoint'])) {
      throw new SearchApiException('Azure AI endpoint not configured.');
    }

    if (empty($this->apiKey)) {
      throw new SearchApiException('Azure AI API key not configured.');
    }

    // Batch texts if needed
    $batch_size = $this->config['batch_size'] ?? 16;
    $all_embeddings = [];

    foreach (array_chunk($texts, $batch_size) as $batch) {
      try {
        $endpoint = rtrim($this->config['endpoint'], '/');
        $deployment = $this->config['deployment_name'] ?? 'text-embedding-ada-002';
        $api_version = $this->config['api_version'] ?? '2024-02-01';
        
        $url = "{$endpoint}/openai/deployments/{$deployment}/embeddings?api-version={$api_version}";
        
        $response = $this->httpClient->post($url, [
          'json' => [
            'input' => $batch,
            'model' => $this->config['model'] ?? 'text-embedding-ada-002',
          ],
          'headers' => [
            'Content-Type' => 'application/json',
            'api-key' => $this->apiKey,
          ],
        ]);

        $data = json_decode($response->getBody()->getContents(), TRUE);
        
        if (!isset($data['data'])) {
          throw new SearchApiException('Invalid response from Azure AI API.');
        }

        // Extract embeddings from response
        foreach ($data['data'] as $item) {
          $all_embeddings[] = $item['embedding'];
        }
      }
      catch (RequestException $e) {
        $error_message = 'Azure AI API request failed: ' . $e->getMessage();
        
        if ($e->hasResponse()) {
          $error_body = $e->getResponse()->getBody()->getContents();
          $error_data = json_decode($error_body, TRUE);
          if (isset($error_data['error']['message'])) {
            $error_message .= ' - ' . $error_data['error']['message'];
          }
        }
        
        $this->logger->error($error_message);
        throw new SearchApiException($error_message, $e->getCode(), $e);
      }
      catch (\Exception $e) {
        $this->logger->error('Embedding generation failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        throw new SearchApiException('Failed to generate embeddings: ' . $e->getMessage(), $e->getCode(), $e);
      }

      // Add delay between batches to respect rate limits
      if (count($texts) > $batch_size) {
        $delay = $this->config['retry_delay'] ?? 1000;
        usleep($delay * 1000); // Convert to microseconds
      }
    }

    return $all_embeddings;
  }

  /**
   * Validates the embedding service configuration.
   *
   * @return bool
   *   TRUE if configuration is valid.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If configuration is invalid.
   */
  public function validateConfiguration() {
    if (empty($this->config['endpoint'])) {
      throw new SearchApiException('Azure AI endpoint is required.');
    }

    if (empty($this->apiKey) && empty($this->config['api_key_name'])) {
      throw new SearchApiException('Azure AI API key or key reference is required.');
    }

    if (empty($this->config['deployment_name'])) {
      throw new SearchApiException('Azure AI deployment name is required.');
    }

    // Test the connection
    try {
      $test_embedding = $this->generateEmbedding('test');
      if (!is_array($test_embedding) || empty($test_embedding)) {
        throw new SearchApiException('Invalid embedding response from Azure AI.');
      }
    }
    catch (\Exception $e) {
      throw new SearchApiException('Azure AI connection test failed: ' . $e->getMessage());
    }

    return TRUE;
  }

  /**
   * Gets the embedding dimensions based on the configured model.
   *
   * @return int
   *   The number of dimensions.
   */
  public function getEmbeddingDimensions() {
    // Use configured dimensions if available
    if (!empty($this->config['dimensions'])) {
      return (int) $this->config['dimensions'];
    }

    // Default dimensions based on model
    $model = $this->config['model'] ?? 'text-embedding-ada-002';
    
    $model_dimensions = [
      'text-embedding-ada-002' => 1536,
      'text-embedding-3-small' => 1536,
      'text-embedding-3-large' => 3072,
    ];

    return $model_dimensions[$model] ?? 1536;
  }
}