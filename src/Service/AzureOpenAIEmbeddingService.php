<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\search_api\SearchApiException;

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
   */
  public function __construct($endpoint, $api_key, $deployment_name, $api_version = '2024-02-01', $dimension = 1536, $max_retries = 3, $retry_delay = 1000) {
    $this->endpoint = rtrim($endpoint, '/');
    $this->apiKey = $api_key;
    $this->deploymentName = $deployment_name;
    $this->apiVersion = $api_version;
    $this->dimension = $dimension;
    $this->maxRetries = $max_retries;
    $this->retryDelay = $retry_delay;
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

    return $this->makeApiCall($url, $data);
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
    foreach ($texts as $index => $text) {
      $processed = $this->preprocessText($text);
      if (!empty($processed)) {
        $processed_texts[$index] = $processed;
      }
    }

    if (empty($processed_texts)) {
      return [];
    }

    $url = sprintf(
      '%s/openai/deployments/%s/embeddings?api-version=%s',
      $this->endpoint,
      $this->deploymentName,
      $this->apiVersion
    );
    
    $data = [
      'input' => array_values($processed_texts),
    ];

    $result = $this->makeApiCall($url, $data, TRUE);
    
    if (!$result) {
      return [];
    }

    // Map results back to original indices
    $embeddings = [];
    $result_index = 0;
    foreach (array_keys($processed_texts) as $original_index) {
      if (isset($result[$result_index])) {
        $embeddings[$original_index] = $result[$result_index];
        $result_index++;
      }
    }

    return $embeddings;
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

}