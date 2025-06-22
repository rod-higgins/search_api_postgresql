<?php

namespace Drupal\search_api_postgresql\Cache;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages embedding cache operations and provides utility functions.
 */
class EmbeddingCacheManager {

  /**
   * The embedding cache service.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheInterface
   */
  protected $cache;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an EmbeddingCacheManager.
   *
   * @param \Drupal\search_api_postgresql\Cache\EmbeddingCacheInterface $cache
   *   The embedding cache service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(EmbeddingCacheInterface $cache, LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    $this->cache = $cache;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
  }

  /**
   * Generates a cache key for text content.
   *
   * @param string $text
   *   The text content.
   * @param array $metadata
   *   Optional metadata to include in the hash (model, version, etc.).
   *
   * @return string
   *   SHA-256 hash suitable for use as cache key.
   */
  public function generateCacheKey($text, array $metadata = []) {
    if (empty($text)) {
      throw new \InvalidArgumentException('Text cannot be empty for cache key generation.');
    }

    // Normalize text for consistent caching
    $normalized_text = $this->normalizeText($text);
    
    // Include metadata in hash for cache invalidation when models change
    $cache_data = [
      'text' => $normalized_text,
      'metadata' => $metadata,
    ];

    return hash('sha256', serialize($cache_data));
  }

  /**
   * Gets an embedding from cache with automatic key generation.
   *
   * @param string $text
   *   The text content.
   * @param array $metadata
   *   Optional metadata.
   *
   * @return array|null
   *   The cached embedding, or NULL if not found.
   */
  public function getCachedEmbedding($text, array $metadata = []) {
    try {
      $cache_key = $this->generateCacheKey($text, $metadata);
      return $this->cache->get($cache_key);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get cached embedding: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Caches an embedding with automatic key generation.
   *
   * @param string $text
   *   The text content.
   * @param array $embedding
   *   The embedding vector.
   * @param array $metadata
   *   Optional metadata.
   * @param int $ttl
   *   Time to live in seconds.
   *
   * @return bool
   *   TRUE if successfully cached.
   */
  public function cacheEmbedding($text, array $embedding, array $metadata = [], $ttl = NULL) {
    try {
      $cache_key = $this->generateCacheKey($text, $metadata);
      return $this->cache->set($cache_key, $embedding, $ttl);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cache embedding: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Gets multiple embeddings from cache with automatic key generation.
   *
   * @param array $texts
   *   Array of text content.
   * @param array $metadata
   *   Optional metadata.
   *
   * @return array
   *   Array of embeddings keyed by original array index.
   */
  public function getCachedEmbeddingsBatch(array $texts, array $metadata = []) {
    if (empty($texts)) {
      return [];
    }

    try {
      $cache_keys = [];
      $key_to_index = [];

      foreach ($texts as $index => $text) {
        if (!empty($text)) {
          $cache_key = $this->generateCacheKey($text, $metadata);
          $cache_keys[] = $cache_key;
          $key_to_index[$cache_key] = $index;
        }
      }

      if (empty($cache_keys)) {
        return [];
      }

      $cached_embeddings = $this->cache->getMultiple($cache_keys);
      $result = [];

      foreach ($cached_embeddings as $cache_key => $embedding) {
        $original_index = $key_to_index[$cache_key];
        $result[$original_index] = $embedding;
      }

      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get cached embeddings batch: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Caches multiple embeddings with automatic key generation.
   *
   * @param array $texts
   *   Array of text content.
   * @param array $embeddings
   *   Array of embedding vectors, same indices as texts.
   * @param array $metadata
   *   Optional metadata.
   * @param int $ttl
   *   Time to live in seconds.
   *
   * @return bool
   *   TRUE if successfully cached.
   */
  public function cacheEmbeddingsBatch(array $texts, array $embeddings, array $metadata = [], $ttl = NULL) {
    if (empty($texts) || empty($embeddings) || count($texts) !== count($embeddings)) {
      return FALSE;
    }

    try {
      $cache_items = [];

      foreach ($texts as $index => $text) {
        if (!empty($text) && isset($embeddings[$index])) {
          $cache_key = $this->generateCacheKey($text, $metadata);
          $cache_items[$cache_key] = $embeddings[$index];
        }
      }

      if (empty($cache_items)) {
        return TRUE; // Nothing to cache, but not an error
      }

      return $this->cache->setMultiple($cache_items, $ttl);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cache embeddings batch: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Invalidates cached embeddings for specific metadata (e.g., when model changes).
   *
   * @param array $metadata
   *   The metadata to match for invalidation.
   *
   * @return int
   *   Number of cache entries invalidated.
   */
  public function invalidateByMetadata(array $metadata) {
    // This is a simplified implementation. For a full implementation,
    // you'd need to store metadata separately or iterate through cache entries.
    $this->logger->info('Invalidating cache entries for metadata: @metadata', [
      '@metadata' => json_encode($metadata)
    ]);

    // For now, just clear all cache if model metadata changes
    if (isset($metadata['model']) || isset($metadata['version'])) {
      $this->cache->clear();
      return 1; // Return 1 to indicate cache was cleared
    }

    return 0;
  }

  /**
   * Gets comprehensive cache statistics.
   *
   * @return array
   *   Detailed cache statistics.
   */
  public function getCacheStatistics() {
    $stats = $this->cache->getStats();
    
    // Add additional calculated metrics
    if (isset($stats['hits']) && isset($stats['misses'])) {
      $total_requests = $stats['hits'] + $stats['misses'];
      $stats['total_requests'] = $total_requests;
      
      if ($total_requests > 0) {
        $stats['hit_rate_percentage'] = round(($stats['hits'] / $total_requests) * 100, 2);
        $stats['miss_rate_percentage'] = round(($stats['misses'] / $total_requests) * 100, 2);
      }
    }

    // Add estimated cost savings (assuming $0.0001 per 1K tokens)
    if (isset($stats['hits'])) {
      $avg_text_length = 500; // Estimated average text length in characters
      $tokens_per_char = 0.25; // Rough estimate
      $cost_per_1k_tokens = 0.0001;
      
      $estimated_tokens_saved = $stats['hits'] * $avg_text_length * $tokens_per_char;
      $estimated_cost_saved = ($estimated_tokens_saved / 1000) * $cost_per_1k_tokens;
      
      $stats['estimated_tokens_saved'] = round($estimated_tokens_saved);
      $stats['estimated_cost_saved_usd'] = round($estimated_cost_saved, 4);
    }

    return $stats;
  }

  /**
   * Performs cache warmup for commonly used content.
   *
   * @param array $content_items
   *   Array of content items to warm up.
   * @param callable $embedding_generator
   *   Function to generate embeddings for content.
   * @param array $metadata
   *   Optional metadata.
   *
   * @return array
   *   Array with 'cached' and 'failed' counts.
   */
  public function warmupCache(array $content_items, callable $embedding_generator, array $metadata = []) {
    $results = ['cached' => 0, 'failed' => 0, 'skipped' => 0];

    if (empty($content_items)) {
      return $results;
    }

    $this->logger->info('Starting cache warmup for @count items', ['@count' => count($content_items)]);

    foreach ($content_items as $item) {
      try {
        if (empty($item)) {
          $results['skipped']++;
          continue;
        }

        // Check if already cached
        $cache_key = $this->generateCacheKey($item, $metadata);
        if ($this->cache->get($cache_key) !== NULL) {
          $results['skipped']++;
          continue;
        }

        // Generate and cache embedding
        $embedding = $embedding_generator($item);
        if (!empty($embedding) && is_array($embedding)) {
          if ($this->cache->set($cache_key, $embedding)) {
            $results['cached']++;
          } else {
            $results['failed']++;
          }
        } else {
          $results['failed']++;
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Cache warmup failed for item: @message', ['@message' => $e->getMessage()]);
        $results['failed']++;
      }
    }

    $this->logger->info('Cache warmup completed: @cached cached, @failed failed, @skipped skipped', $results);

    return $results;
  }

  /**
   * Performs cache maintenance and optimization.
   *
   * @return array
   *   Maintenance results.
   */
  public function performMaintenance() {
    $this->logger->info('Starting embedding cache maintenance');

    $stats_before = $this->cache->getStats();
    $maintenance_success = $this->cache->maintenance();
    $stats_after = $this->cache->getStats();

    $results = [
      'success' => $maintenance_success,
      'entries_before' => $stats_before['total_entries'] ?? 0,
      'entries_after' => $stats_after['total_entries'] ?? 0,
      'entries_cleaned' => ($stats_before['total_entries'] ?? 0) - ($stats_after['total_entries'] ?? 0),
    ];

    if ($maintenance_success) {
      $this->logger->info('Cache maintenance completed successfully: @results', ['@results' => json_encode($results)]);
    } else {
      $this->logger->error('Cache maintenance failed');
    }

    return $results;
  }

  /**
   * Clears all cached embeddings.
   *
   * @return bool
   *   TRUE if cache was cleared successfully.
   */
  public function clear() {
    try {
      $result = $this->cache->clear();
      if ($result) {
        $this->logger->info('Embedding cache cleared successfully');
      }
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear embedding cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Clears cached embeddings for a specific index.
   *
   * Note: Currently clears ALL cache entries. Future versions will implement
   * index-specific cache tracking for more granular control.
   *
   * @param string $index_id
   *   The index ID to clear cache for.
   *
   * @return bool
   *   TRUE if cache was cleared successfully.
   */
  public function clearByIndex($index_id) {
    try {
      // TODO: Implement index-specific cache tracking in future versions
      // For now, clear all cache but log the specific index for tracking
      $result = $this->cache->clear();
      if ($result) {
        $this->logger->info('Embedding cache cleared (all entries) due to index operation: @index', [
          '@index' => $index_id
        ]);
      }
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear cache for index @index: @message', [
        '@index' => $index_id,
        '@message' => $e->getMessage()
      ]);
      return FALSE;
    }
  }

  /**
   * Sets multiple cache entries.
   *
   * @param array $items
   *   Array of cache items keyed by cache key.
   * @param int $ttl
   *   Time to live in seconds.
   *
   * @return bool
   *   TRUE if all items were cached successfully.
   */
  public function setMultiple(array $items, $ttl = NULL) {
    try {
      return $this->cache->setMultiple($items, $ttl);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to set multiple cache items: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Gets multiple cache entries.
   *
   * @param array $keys
   *   Array of cache keys.
   *
   * @return array
   *   Array of cache values keyed by cache key.
   */
  public function getMultiple(array $keys) {
    try {
      return $this->cache->getMultiple($keys);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get multiple cache items: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets cache statistics with additional metrics.
   *
   * @return array
   *   Comprehensive cache statistics.
   */
  public function getStats() {
    return $this->getCacheStatistics(); // Alias for backward compatibility
  }

  /**
   * Performs cache maintenance.
   *
   * @return bool
   *   TRUE if maintenance was successful.
   */
  public function maintenance() {
    $results = $this->performMaintenance();
    return $results['success'] ?? FALSE;
  }

  /**
   * Normalizes text for consistent caching.
   *
   * @param string $text
   *   The input text.
   *
   * @return string
   *   Normalized text.
   */
  protected function normalizeText($text) {
    // Remove excessive whitespace
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    // Convert to lowercase for case-insensitive caching (optional)
    // $text = strtolower($text);
    
    // Remove null bytes and control characters
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    
    return $text;
  }

  /**
   * Gets the underlying cache implementation.
   *
   * @return \Drupal\search_api_postgresql\Cache\EmbeddingCacheInterface
   *   The cache implementation.
   */
  public function getCache() {
    return $this->cache;
  }

}