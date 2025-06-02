<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for generating text embeddings using Azure AI Services.
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
   * Constructs an EmbeddingService object.
   *
   * @param array $config
   *   The Azure AI configuration.
   * @param string $api_key
   *   The API key.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $config, string $api_key, LoggerInterface $logger) {
    $this->config = $config;
    $this->apiKey = $api_key;
    $this->logger = $logger;
    $this->httpClient = new Client([
      'timeout' => 30,
      'headers' => [
        'Content-Type' => 'application/json',
        'api-key' => $this->apiKey,
      ],
    ]);
  }

  /**
   * Generates embeddings for given texts.
   *
   * @param array $texts
   *   Array of text strings to generate embeddings for.
   *
   * @return array
   *   Array of embedding vectors.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If embedding generation fails.
   */
  public function generateEmbeddings(array $texts) {
    if (empty($texts)) {
      return [];
    }

    // Process texts in batches.
    $batch_size = $this->config['batch_size'] ?? 10;
    $batches = array_chunk($texts, $batch_size);
    $all_embeddings = [];

    foreach ($batches as $batch) {
      $batch_embeddings = $this->processBatch($batch);
      $all_embeddings = array_merge($all_embeddings, $batch_embeddings);
    }

    return $all_embeddings;
  }

  /**
   * Processes a batch of texts to generate embeddings.
   *
   * @param array $texts
   *   Array of text strings.
   *
   * @return array
   *   Array of embedding vectors.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If batch processing fails.
   */
  protected function processBatch(array $texts) {
    $endpoint = rtrim($this->config['endpoint'], '/');
    $model = $this->config['model'];
    $url = "{$endpoint}/openai/deployments/{$model}/embeddings?api-version=2023-05-15";

    // Prepare the request payload.
    $payload = [
      'input' => $texts,
      'user' => 'drupal_search_api_postgresql',
    ];

    // Add dimensions parameter for newer models.
    if (in_array($model, ['text-embedding-3-small', 'text-embedding-3-large'])) {
      $payload['dimensions'] = $this->config['dimensions'];
    }

    try {
      $this->logger->debug('Sending embedding request to Azure AI: @url', ['@url' => $url]);
      
      $response = $this->httpClient->post($url, [
        'json' => $payload,
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (!isset($data['data']) || !is_array($data['data'])) {
        throw new SearchApiException('Invalid response format from Azure AI Services.');
      }

      $embeddings = [];
      foreach ($data['data'] as $item) {
        if (!isset($item['embedding']) || !is_array($item['embedding'])) {
          throw new SearchApiException('Invalid embedding data in response.');
        }
        $embeddings[] = $item['embedding'];
      }

      $this->logger->debug('Generated @count embeddings', ['@count' => count($embeddings)]);
      
      return $embeddings;
    }
    catch (RequestException $e) {
      $error_message = 'Azure AI Services request failed: ' . $e->getMessage();
      
      if ($e->hasResponse()) {
        $response_body = $e->getResponse()->getBody()->getContents();
        $error_data = json_decode($response_body, TRUE);
        
        if (isset($error_data['error']['message'])) {
          $error_message .= ' - ' . $error_data['error']['message'];
        }
      }

      $this->logger->error($error_message);
      throw new SearchApiException($error_message, $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Embedding generation failed: @message', ['@message' => $e->getMessage()]);
      throw new SearchApiException('Embedding generation failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Generates a single embedding for a text.
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
  public function generateEmbedding(string $text) {
    $embeddings = $this->generateEmbeddings([$text]);
    return reset($embeddings);
  }

  /**
   * Calculates cosine similarity between two vectors.
   *
   * @param array $vector1
   *   First vector.
   * @param array $vector2
   *   Second vector.
   *
   * @return float
   *   Cosine similarity score between -1 and 1.
   */
  public function cosineSimilarity(array $vector1, array $vector2) {
    if (count($vector1) !== count($vector2)) {
      throw new \InvalidArgumentException('Vectors must have the same length.');
    }

    $dot_product = 0;
    $magnitude1 = 0;
    $magnitude2 = 0;

    for ($i = 0; $i < count($vector1); $i++) {
      $dot_product += $vector1[$i] * $vector2[$i];
      $magnitude1 += $vector1[$i] * $vector1[$i];
      $magnitude2 += $vector2[$i] * $vector2[$i];
    }

    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);

    if ($magnitude1 == 0 || $magnitude2 == 0) {
      return 0;
    }

    return $dot_product / ($magnitude1 * $magnitude2);
  }

  /**
   * Normalizes a vector to unit length.
   *
   * @param array $vector
   *   The vector to normalize.
   *
   * @return array
   *   The normalized vector.
   */
  public function normalizeVector(array $vector) {
    $magnitude = sqrt(array_sum(array_map(function($x) { return $x * $x; }, $vector)));
    
    if ($magnitude == 0) {
      return $vector;
    }

    return array_map(function($x) use ($magnitude) { return $x / $magnitude; }, $vector);
  }

  /**
   * Converts a vector to PostgreSQL format.
   *
   * @param array $vector
   *   The vector array.
   *
   * @return string
   *   PostgreSQL vector format string.
   */
  public function vectorToPostgreSQL(array $vector) {
    return '[' . implode(',', $vector) . ']';
  }

  /**
   * Converts PostgreSQL vector format to array.
   *
   * @param string $vector_string
   *   PostgreSQL vector format string.
   *
   * @return array
   *   Vector array.
   */
  public function postgreSQLToVector(string $vector_string) {
    $vector_string = trim($vector_string, '[]');
    return array_map('floatval', explode(',', $vector_string));
  }

  /**
   * Chunks text into smaller pieces for embedding.
   *
   * @param string $text
   *   The text to chunk.
   * @param int $max_tokens
   *   Maximum tokens per chunk.
   * @param int $overlap
   *   Number of tokens to overlap between chunks.
   *
   * @return array
   *   Array of text chunks.
   */
  public function chunkText(string $text, int $max_tokens = 8000, int $overlap = 200) {
    // Simple word-based chunking (could be enhanced with proper tokenizer).
    $words = explode(' ', $text);
    $chunks = [];
    $current_chunk = [];
    $word_count = 0;

    foreach ($words as $word) {
      $current_chunk[] = $word;
      $word_count++;

      // Rough estimation: 1 token ≈ 0.75 words for English.
      if ($word_count >= ($max_tokens * 0.75)) {
        $chunks[] = implode(' ', $current_chunk);
        
        // Create overlap for next chunk.
        $overlap_words = array_slice($current_chunk, -($overlap * 0.75));
        $current_chunk = $overlap_words;
        $word_count = count($overlap_words);
      }
    }

    // Add remaining words as final chunk.
    if (!empty($current_chunk)) {
      $chunks[] = implode(' ', $current_chunk);
    }

    return array_filter($chunks, function($chunk) {
      return strlen(trim($chunk)) > 0;
    });
  }

  /**
   * Prepares text for embedding generation.
   *
   * @param string $text
   *   The raw text.
   *
   * @return string
   *   Cleaned and prepared text.
   */
  public function prepareTextForEmbedding(string $text) {
    // Remove HTML tags.
    $text = strip_tags($text);
    
    // Normalize whitespace.
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Remove excessive punctuation.
    $text = preg_replace('/[^\w\s\.\!\?\,\;\:\-\(\)]/', ' ', $text);
    
    // Trim and ensure minimum length.
    $text = trim($text);
    
    if (strlen($text) < 10) {
      return '';
    }

    return $text;
  }

  /**
   * Gets the API usage statistics.
   *
   * @return array
   *   Usage statistics.
   */
  public function getUsageStats() {
    // This would be enhanced to track actual usage.
    return [
      'total_requests' => 0,
      'total_tokens' => 0,
      'total_embeddings' => 0,
    ];
  }

}