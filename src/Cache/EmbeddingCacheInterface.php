<?php

namespace Drupal\search_api_postgresql\Cache;

/**
 * Interface for embedding cache implementations.
 */
interface EmbeddingCacheInterface
{

  /**
   * Gets an embedding from the cache.
   * {@inheritdoc}
   *
   * @param string $text_hash
   *   The hash of the text content.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   The cached embedding array, or NULL if not found.
   */
  public function get($text_hash);

  /**
   * Sets an embedding in the cache.
   * {@inheritdoc}
   *
   * @param string $text_hash
   *   The hash of the text content.
   * @param array $embedding
   *   The embedding vector to cache.
   * @param int $ttl
   *   Time to live in seconds (optional).
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully cached, false otherwise.
   */
  public function set($text_hash, array $embedding, $ttl = null);

  /**
   * Invalidates a cached embedding.
   * {@inheritdoc}
   *
   * @param string $text_hash
   *   The hash of the text content.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully invalidated, false otherwise.
   */
  public function invalidate($text_hash);

  /**
   * Gets multiple embeddings from the cache.
   * {@inheritdoc}
   *
   * @param array $text_hashes
   *   Array of text hashes.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array of embeddings keyed by hash, missing entries will be omitted.
   */
  public function getMultiple(array $text_hashes);

  /**
   * Sets multiple embeddings in the cache.
   * {@inheritdoc}
   *
   * @param array $items
   *   Array of text_hash => embedding pairs.
   * @param int $ttl
   *   Time to live in seconds (optional).
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if all items were successfully cached, false otherwise.
   */
  public function setMultiple(array $items, $ttl = null);

  /**
   * Clears all cached embeddings.
   * {@inheritdoc}
   *
   * @return bool
   *   true if successfully cleared, false otherwise.
   */
  public function clear();

  /**
   * Gets cache statistics.
   * {@inheritdoc}
   *
   * @return array
   *   Array with cache statistics (hits, misses, size, etc.).
   */
  public function getStats();

  /**
   * Performs cache maintenance (cleanup expired entries, etc.).
   * {@inheritdoc}
   *
   * @return bool
   *   true if maintenance completed successfully.
   */
  public function maintenance();
}
