<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\search_api_postgresql\Exception\GracefulDegradationException;
use Drupal\search_api_postgresql\Exception\PartialBatchFailureException;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceUnavailableException;
use Drupal\search_api_postgresql\Exception\DegradationExceptionFactory;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;
use Psr\Log\LoggerInterface;

/**
 * Resilient embedding service with graceful degradation capabilities.
 */
class ResilientEmbeddingService implements EmbeddingServiceInterface {
  /**
   * The underlying embedding service.
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
   * The cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Service configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a ResilientEmbeddingService.
   *
   * @param \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface $embedding_service
   *   The underlying embedding service.
   * @param \Drupal\search_api_postgresql\Service\CircuitBreakerService $circuit_breaker
   *   The circuit breaker service.
   * @param \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager $cache_manager
   *   The cache manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param array $config
   *   Service configuration.
   */
  public function __construct(
    EmbeddingServiceInterface $embedding_service,
    CircuitBreakerService $circuit_breaker,
    EmbeddingCacheManager $cache_manager,
    LoggerInterface $logger,
    array $config = [],
  ) {
    $this->embeddingService = $embedding_service;
    $this->circuitBreaker = $circuit_breaker;
    $this->cacheManager = $cache_manager;
    $this->logger = $logger;
    $this->config = $config + $this->getDefaultConfig();
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbedding($text) {
    if (empty(trim($text))) {
      return NULL;
    }

    // Try cache first.
    $cached_embedding = $this->getCachedEmbedding($text);
    if ($cached_embedding !== NULL) {
      return $cached_embedding;
    }

    // Use circuit breaker for external API call.
    return $this->circuitBreaker->execute(
          'embedding_generation',
          function () use ($text) {
              return $this->generateEmbeddingWithRetry($text);
          },
          function ($exception) use ($text) {
              return $this->handleEmbeddingFailure($text, $exception);
          },
          ['operation' => 'single_embedding', 'text_length' => strlen($text)]
      );
  }

  /**
   * {@inheritdoc}
   */
  public function generateBatchEmbeddings(array $texts) {
    if (empty($texts)) {
      return [];
    }

    // Filter out empty texts and prepare for processing.
    $valid_texts = [];
    $text_indices = [];
    foreach ($texts as $index => $text) {
      if (!empty(trim($text))) {
        $valid_texts[] = $text;
        $text_indices[] = $index;
      }
    }

    if (empty($valid_texts)) {
      return [];
    }

    // Check cache for all texts.
    $cached_results = [];
    $uncached_texts = [];
    $uncached_indices = [];

    foreach ($valid_texts as $i => $text) {
      $cached = $this->getCachedEmbedding($text);
      if ($cached !== NULL) {
        $cached_results[$text_indices[$i]] = $cached;
      }
      else {
        $uncached_texts[] = $text;
        $uncached_indices[] = $text_indices[$i];
      }
    }

    // Process uncached texts in batches with graceful degradation.
    $final_results = $cached_results;
    if (!empty($uncached_texts)) {
      $batch_results = $this->processBatchWithDegradation($uncached_texts, $uncached_indices);
      $final_results = array_merge($final_results, $batch_results);
    }

    return $final_results;
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension() {
    return $this->embeddingService->getDimension();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    // Check if circuit breaker allows the service.
    if (!$this->circuitBreaker->isServiceAvailable('embedding_generation')) {
      return FALSE;
    }

    return $this->embeddingService->isAvailable();
  }

  /**
   * Generates embedding with retry logic.
   *
   * @param string $text
   *   The text to embed.
   *
   * @return array|null
   *   The embedding or NULL on failure.
   *
   * @throws \Exception
   *   On unrecoverable failures.
   */
  protected function generateEmbeddingWithRetry($text) {
    $max_retries = $this->config['max_retries'];
    $base_delay = $this->config['base_retry_delay'];

    for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
      try {
        $embedding = $this->embeddingService->generateEmbedding($text);

        if ($embedding) {
          // Cache successful result.
          $this->cacheEmbedding($text, $embedding);
          return $embedding;
        }

        throw new \Exception('Embedding service returned empty result');
      }
      catch (\Exception $e) {
        $is_last_attempt = ($attempt === $max_retries);

        // Determine if this is a retryable error.
        if (!$this->isRetryableError($e) || $is_last_attempt) {
          throw $this->wrapException($e, $attempt);
        }

        // Exponential backoff with jitter.
        $delay = $base_delay * pow(2, $attempt) + random_int(0, 1000);
        usleep($delay * 1000);

        $this->logger->info('Retrying embedding generation (attempt @attempt/@max): @error', [
          '@attempt' => $attempt + 1,
          '@max' => $max_retries + 1,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    throw new EmbeddingServiceUnavailableException(
          'Embedding service',
          new \Exception('Max retries exceeded')
      );
  }

  /**
   * Processes batch embeddings with partial failure handling.
   *
   * @param array $texts
   *   Texts to process.
   * @param array $indices
   *   Original indices for the texts.
   *
   * @return array
   *   Results keyed by original indices.
   */
  protected function processBatchWithDegradation(array $texts, array $indices) {
    $batch_size = $this->config['batch_size'];
    $results = [];
    $all_failures = [];

    // Split into smaller batches.
    $batches = array_chunk($texts, $batch_size, TRUE);
    $index_batches = array_chunk($indices, $batch_size, TRUE);

    foreach ($batches as $batch_index => $batch_texts) {
      $batch_indices = $index_batches[$batch_index];

      try {
        $batch_results = $this->circuitBreaker->execute(
              'embedding_batch_generation',
              function () use ($batch_texts) {
                  return $this->embeddingService->generateBatchEmbeddings($batch_texts);
              },
              function ($exception) use ($batch_texts, $batch_indices) {
                        return $this->handleBatchFailure($batch_texts, $batch_indices, $exception);
              },
              ['operation' => 'batch_embedding', 'batch_size' => count($batch_texts)]
          );

        // Map results back to original indices.
        foreach ($batch_results as $batch_pos => $embedding) {
          if (isset($batch_indices[$batch_pos]) && $embedding) {
            $original_index = $batch_indices[$batch_pos];
            $results[$original_index] = $embedding;

            // Cache successful embeddings.
            $this->cacheEmbedding($batch_texts[$batch_pos], $embedding);
          }
        }
      }
      catch (PartialBatchFailureException $e) {
        // Handle partial batch failure.
        $successful = $e->getSuccessfulItems();
        $failed = $e->getFailedItems();

        // Add successful items to results.
        foreach ($successful as $batch_pos => $embedding) {
          if (isset($batch_indices[$batch_pos])) {
            $original_index = $batch_indices[$batch_pos];
            $results[$original_index] = $embedding;
            $this->cacheEmbedding($batch_texts[$batch_pos], $embedding);
          }
        }

        // Track failures.
        foreach ($failed as $batch_pos => $error) {
          if (isset($batch_indices[$batch_pos])) {
            $original_index = $batch_indices[$batch_pos];
            $all_failures[$original_index] = $error;
          }
        }

        $this->logger->warning('Partial batch failure: @success/@total successful', [
          '@success' => count($successful),
          '@total' => count($batch_texts),
        ]);
      }
      catch (GracefulDegradationException $e) {
        // Entire batch failed, but we can continue.
        foreach ($batch_indices as $batch_pos => $original_index) {
          $all_failures[$original_index] = $e->getMessage();
        }

        $this->logger->warning('Batch embedding failed gracefully: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // If we have partial failures, throw PartialBatchFailureException.
    if (!empty($all_failures) && !empty($results)) {
      $successful_items = [];
      $failed_items = [];

      foreach ($indices as $index) {
        if (isset($results[$index])) {
          $successful_items[$index] = $results[$index];
        }
        elseif (isset($all_failures[$index])) {
          $failed_items[$index] = $all_failures[$index];
        }
      }

      throw new PartialBatchFailureException($successful_items, $failed_items, 'batch embedding generation');
    }

    // If all failed, throw appropriate exception.
    if (empty($results) && !empty($all_failures)) {
      throw new EmbeddingServiceUnavailableException('Batch embedding service');
    }

    return $results;
  }

  /**
   * Handles embedding generation failure.
   *
   * @param string $text
   *   The text that failed.
   * @param \Exception $exception
   *   The failure exception.
   *
   * @return array|null
   *   Fallback embedding or NULL.
   */
  protected function handleEmbeddingFailure($text, \Exception $exception) {
    // Check if we have a fallback strategy.
    if ($this->config['enable_fallback']) {
      // Try to get a cached similar embedding.
      $fallback_embedding = $this->getFallbackEmbedding($text);
      if ($fallback_embedding) {
        $this->logger->info('Using fallback embedding for failed generation');
        return $fallback_embedding;
      }
    }

    // If no fallback available, return NULL to trigger text-only search.
    $this->logger->warning('Embedding generation failed, no fallback available: @message', [
      '@message' => $exception->getMessage(),
    ]);

    return NULL;
  }

  /**
   * Handles batch failure with individual item processing.
   *
   * @param array $texts
   *   The texts that failed.
   * @param array $indices
   *   The indices for the texts.
   * @param \Exception $exception
   *   The failure exception.
   *
   * @return array
   *   Partial results.
   *
   * @throws \Drupal\search_api_postgresql\Exception\PartialBatchFailureException
   *   When some items succeed and others fail.
   */
  protected function handleBatchFailure(array $texts, array $indices, \Exception $exception) {
    // If circuit breaker is open or this is a complete service failure,
    // don't try individual processing.
    if (!$this->circuitBreaker->isServiceAvailable('embedding_generation')) {
      throw new EmbeddingServiceUnavailableException('Batch embedding service', $exception);
    }

    // Try processing items individually if configured to do so.
    if (!$this->config['individual_fallback']) {
      throw new EmbeddingServiceUnavailableException('Batch embedding service', $exception);
    }

    $successful = [];
    $failed = [];

    foreach ($texts as $pos => $text) {
      try {
        $embedding = $this->generateEmbeddingWithRetry($text);
        if ($embedding) {
          $successful[$pos] = $embedding;
        }
        else {
          $failed[$pos] = 'No embedding returned';
        }
      }
      catch (\Exception $e) {
        $failed[$pos] = $e->getMessage();
      }

      // Add small delay between individual requests.
      if ($pos < count($texts) - 1) {
        usleep($this->config['individual_delay'] * 1000);
      }
    }

    if (!empty($successful)) {
      throw new PartialBatchFailureException($successful, $failed, 'individual embedding fallback');
    }

    throw new EmbeddingServiceUnavailableException('Individual embedding fallback', $exception);
  }

  /**
   * Gets cached embedding for text.
   *
   * @param string $text
   *   The text.
   *
   * @return array|null
   *   Cached embedding or NULL.
   */
  protected function getCachedEmbedding($text) {
    if (!$this->cacheManager) {
      return NULL;
    }

    try {
      return $this->cacheManager->getCachedEmbedding($text, $this->getCacheMetadata());
    }
    catch (\Exception $e) {
      $this->logger->warning('Cache retrieval failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Caches an embedding.
   *
   * @param string $text
   *   The text.
   * @param array $embedding
   *   The embedding.
   */
  protected function cacheEmbedding($text, array $embedding) {
    if (!$this->cacheManager) {
      return;
    }

    try {
      $this->cacheManager->cacheEmbedding($text, $embedding, $this->getCacheMetadata());
    }
    catch (\Exception $e) {
      $this->logger->warning('Cache storage failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Gets a fallback embedding for failed generation.
   *
   * @param string $text
   *   The text.
   *
   * @return array|null
   *   Fallback embedding or NULL.
   */
  protected function getFallbackEmbedding($text) {
    // Simple fallback: try to find cached embedding for similar text
    // In a more sophisticated implementation, this could use:
    // - Simplified embeddings (fewer dimensions)
    // - Pre-computed embeddings for common terms
    // - Statistical text features as pseudo-embeddings.
    if (!$this->cacheManager) {
      return NULL;
    }

    // For now, return NULL - this is where you'd implement fallback strategies.
    return NULL;
  }

  /**
   * Checks if an error is retryable.
   *
   * @param \Exception $exception
   *   The exception to check.
   *
   * @return bool
   *   TRUE if retryable.
   */
  protected function isRetryableError(\Exception $exception) {
    $message = $exception->getMessage();
    $code = $exception->getCode();

    // Retryable errors: network issues, rate limits, temporary server errors.
    $retryable_codes = [429, 500, 502, 503, 504];
    if (in_array($code, $retryable_codes)) {
      return TRUE;
    }

    // Check message for retryable conditions.
    $retryable_messages = [
      'connection timeout',
      'connection refused',
      'temporary failure',
      'rate limit',
      'server overloaded',
      'curl error',
    ];

    foreach ($retryable_messages as $retryable) {
      if (stripos($message, $retryable) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Wraps an exception with appropriate degradation exception.
   *
   * @param \Exception $exception
   *   The original exception.
   * @param int $retry_attempts
   *   Number of retry attempts made.
   *
   * @return \Drupal\search_api_postgresql\Exception\GracefulDegradationException
   *   The wrapped exception.
   */
  protected function wrapException(\Exception $exception, $retry_attempts = 0) {
    return DegradationExceptionFactory::createFromException($exception, [
      'service_name' => 'Embedding Service',
      'retry_attempts' => $retry_attempts,
    ]);
  }

  /**
   * Gets cache metadata.
   *
   * @return array
   *   Cache metadata.
   */
  protected function getCacheMetadata() {
    return [
      'service' => 'resilient_embedding',
      'dimension' => $this->getDimension(),
    ];
  }

  /**
   * Gets default configuration.
   *
   * @return array
   *   Default configuration.
   */
  protected function getDefaultConfig() {
    return [
      'max_retries' => 3,
    // Milliseconds.
      'base_retry_delay' => 1000,
      'batch_size' => 10,
      'enable_fallback' => TRUE,
      'individual_fallback' => TRUE,
    // Milliseconds between individual requests.
      'individual_delay' => 100,
    ];
  }

  /**
   * Gets service statistics including degradation info.
   *
   * @return array
   *   Service statistics.
   */
  public function getServiceStats() {
    return [
      'circuit_breaker_stats' => $this->circuitBreaker->getServiceStats('embedding_generation'),
      'underlying_service_available' => $this->embeddingService->isAvailable(),
      'cache_enabled' => $this->cacheManager !== NULL,
      'config' => $this->config,
    ];
  }

  /**
   * Resets the circuit breaker for this service.
   */
  public function resetCircuitBreaker() {
    $this->circuitBreaker->resetCircuit('embedding_generation');
    $this->circuitBreaker->resetCircuit('embedding_batch_generation');
  }

}
