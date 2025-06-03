<?php

namespace Drupal\search_api_postgresql\Cache;

/**
 * Interface for embedding cache implementations.
 */
interface EmbeddingCacheInterface {

  /**
   * Gets an embedding from the cache.
   *
   * @param string $text_hash
   *   The hash of the text content.
   *
   * @return array|null
   *   The cached embedding array, or NULL if not found.
   */
  public function get($text_hash);

  /**
   * Sets an embedding in the cache.
   *
   * @param string $text_hash
   *   The hash of the text content.
   * @param array $embedding
   *   The embedding vector to cache.
   * @param int $ttl
   *   Time to live in seconds (optional).
   *
   * @return bool
   *   TRUE if successfully cached, FALSE otherwise.
   */
  public function set($text_hash, array $embedding, $ttl = NULL);

  /**
   * Invalidates a cached embedding.
   *
   * @param string $text_hash
   *   The hash of the text content.
   *
   * @return bool
   *   TRUE if successfully invalidated, FALSE otherwise.
   */
  public function invalidate($text_hash);

  /**
   * Gets multiple embeddings from the cache.
   *
   * @param array $text_hashes
   *   Array of text hashes.
   *
   * @return array
   *   Array of embeddings keyed by hash, missing entries will be omitted.
   */
  public function getMultiple(array $text_hashes);

  /**
   * Sets multiple embeddings in the cache.
   *
   * @param array $items
   *   Array of text_hash => embedding pairs.
   * @param int $ttl
   *   Time to live in seconds (optional).
   *
   * @return bool
   *   TRUE if all items were successfully cached, FALSE otherwise.
   */
  public function setMultiple(array $items, $ttl = NULL);

  /**
   * Clears all cached embeddings.
   *
   * @return bool
   *   TRUE if successfully cleared, FALSE otherwise.
   */
  public function clear();

  /**
   * Gets cache statistics.
   *
   * @return array
   *   Array with cache statistics (hits, misses, size, etc.).
   */
  public function getStats();

  /**
   * Performs cache maintenance (cleanup expired entries, etc.).
   *
   * @return bool
   *   TRUE if maintenance completed successfully.
   */
  public function maintenance();

}