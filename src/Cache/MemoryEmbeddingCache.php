<?php

namespace Drupal\search_api_postgresql\Cache;

use Psr\Log\LoggerInterface;

/**
 * In-memory implementation of embedding cache for testing and development.
 */
class MemoryEmbeddingCache implements EmbeddingCacheInterface {
  /**
   * The in-memory cache storage.
   *
   * @var array
   */
  protected $cache = [];

  /**
   * Cache metadata.
   *
   * @var array
   */
  protected $metadata = [];

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Cache configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Cache statistics.
   *
   * @var array
   */
  protected $stats = [
    'hits' => 0,
    'misses' => 0,
    'sets' => 0,
    'invalidations' => 0,
  ];

  /**
   * Constructs a MemoryEmbeddingCache.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param array $config
   *   Cache configuration.
   */
  public function __construct(LoggerInterface $logger, array $config = []) {
    $this->logger = $logger;
    $this->config = $config + [
    // 1 hour for memory cache
      'default_ttl' => 3600,
      'max_entries' => 1000,
    // Cleanup when 90% full.
      'cleanup_threshold' => 0.9,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function get($text_hash) {
    if (empty($text_hash)) {
      return NULL;
    }

    $this->validateHash($text_hash);

    if (!isset($this->cache[$text_hash])) {
      $this->stats['misses']++;
      $this->logger->debug('Memory embedding cache miss for hash: @hash', ['@hash' => $text_hash]);
      return NULL;
    }

    $metadata = $this->metadata[$text_hash] ?? [];
    $expires = $metadata['expires'] ?? 0;

    // Check if expired.
    if ($expires > 0 && time() > $expires) {
      unset($this->cache[$text_hash]);
      unset($this->metadata[$text_hash]);
      $this->stats['misses']++;
      $this->logger->debug('Memory embedding cache expired for hash: @hash', ['@hash' => $text_hash]);
      return NULL;
    }

    // Update access time and hit count.
    $this->metadata[$text_hash]['last_accessed'] = time();
    $this->metadata[$text_hash]['hit_count'] = ($metadata['hit_count'] ?? 0) + 1;

    $this->stats['hits']++;
    $this->logger->debug('Memory embedding cache hit for hash: @hash', ['@hash' => $text_hash]);

    return $this->cache[$text_hash];
  }

  /**
   * {@inheritdoc}
   */
  public function set($text_hash, array $embedding, $ttl = NULL) {
    if (empty($text_hash) || empty($embedding)) {
      return FALSE;
    }

    $this->validateHash($text_hash);
    $this->validateEmbedding($embedding);

    $ttl = $ttl ?? $this->config['default_ttl'];
    $expires = $ttl > 0 ? time() + $ttl : 0;

    $this->cache[$text_hash] = $embedding;
    $this->metadata[$text_hash] = [
      'created' => time(),
      'last_accessed' => time(),
      'expires' => $expires,
      'hit_count' => 0,
      'dimensions' => count($embedding),
    ];

    // Check if we need to cleanup after adding the item.
    if (count($this->cache) > $this->config['max_entries']) {
      $this->performCleanup();
    }

    $this->stats['sets']++;

    $this->logger->debug('Cached embedding in memory for hash: @hash (dimensions: @dim)', [
      '@hash' => $text_hash,
      '@dim' => count($embedding),
    ]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($text_hash) {
    if (empty($text_hash)) {
      return FALSE;
    }

    $this->validateHash($text_hash);

    $existed = isset($this->cache[$text_hash]);

    if ($existed) {
      unset($this->cache[$text_hash]);
      unset($this->metadata[$text_hash]);
      $this->stats['invalidations']++;
      $this->logger->debug('Invalidated memory embedding cache for hash: @hash', ['@hash' => $text_hash]);
    }

    return $existed;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $text_hashes) {
    if (empty($text_hashes)) {
      return [];
    }

    $text_hashes = array_filter($text_hashes);
    if (empty($text_hashes)) {
      return [];
    }

    $results = [];
    $current_time = time();

    foreach ($text_hashes as $hash) {
      $this->validateHash($hash);

      if (!isset($this->cache[$hash])) {
        $this->stats['misses']++;
        continue;
      }

      $metadata = $this->metadata[$hash] ?? [];
      $expires = $metadata['expires'] ?? 0;

      // Check if expired.
      if ($expires > 0 && $current_time > $expires) {
        unset($this->cache[$hash]);
        unset($this->metadata[$hash]);
        $this->stats['misses']++;
        continue;
      }

      // Update access time and hit count.
      $this->metadata[$hash]['last_accessed'] = $current_time;
      $this->metadata[$hash]['hit_count'] = ($metadata['hit_count'] ?? 0) + 1;

      $results[$hash] = $this->cache[$hash];
      $this->stats['hits']++;
    }

    $this->logger->debug('Memory batch embedding cache lookup: @hits hits, @misses misses', [
      '@hits' => count($results),
      '@misses' => count($text_hashes) - count($results),
    ]);

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items, $ttl = NULL) {
    if (empty($items)) {
      return TRUE;
    }

    $ttl = $ttl ?? $this->config['default_ttl'];
    $expires = $ttl > 0 ? time() + $ttl : 0;
    $current_time = time();

    // Check if we need to cleanup.
    $new_count = count($this->cache) + count($items);
    if ($new_count >= $this->config['max_entries'] * $this->config['cleanup_threshold']) {
      $this->performCleanup();
    }

    foreach ($items as $text_hash => $embedding) {
      if (empty($text_hash) || empty($embedding)) {
        continue;
      }

      $this->validateHash($text_hash);
      $this->validateEmbedding($embedding);

      $this->cache[$text_hash] = $embedding;
      $this->metadata[$text_hash] = [
        'created' => $current_time,
        'last_accessed' => $current_time,
        'expires' => $expires,
        'hit_count' => 0,
        'dimensions' => count($embedding),
      ];

      $this->stats['sets']++;
    }

    $this->logger->debug('Memory batch cached @count embeddings', ['@count' => count($items)]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    $count = count($this->cache);

    $this->cache = [];
    $this->metadata = [];

    $this->stats['invalidations'] += $count;

    $this->logger->info('Cleared memory embedding cache: @count entries', ['@count' => $count]);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getStats() {
    $current_time = time();
    $total_entries = count($this->cache);
    $expired_count = 0;
    $total_hits = 0;
    $total_dimensions = 0;
    $oldest_created = NULL;
    $newest_created = NULL;

    foreach ($this->metadata as $metadata) {
      if (isset($metadata['expires']) && $metadata['expires'] > 0 && $current_time > $metadata['expires']) {
        $expired_count++;
      }

      $total_hits += $metadata['hit_count'] ?? 0;
      $total_dimensions += $metadata['dimensions'] ?? 0;

      $created = $metadata['created'] ?? 0;
      if ($created > 0) {
        if ($oldest_created === NULL || $created < $oldest_created) {
          $oldest_created = $created;
        }
        if ($newest_created === NULL || $created > $newest_created) {
          $newest_created = $created;
        }
      }
    }

    return [
      'hits' => $this->stats['hits'],
      'misses' => $this->stats['misses'],
      'sets' => $this->stats['sets'],
      'invalidations' => $this->stats['invalidations'],
      'total_entries' => $total_entries,
      'expired_entries' => $expired_count,
      'total_memory_hits' => $total_hits,
      'average_dimensions' => $total_entries > 0 ? round($total_dimensions / $total_entries, 2) : 0,
      'oldest_entry' => $oldest_created ? date('Y-m-d H:i:s', $oldest_created) : NULL,
      'newest_entry' => $newest_created ? date('Y-m-d H:i:s', $newest_created) : NULL,
      'hit_rate' => $this->stats['hits'] + $this->stats['misses'] > 0
        ? round($this->stats['hits'] / ($this->stats['hits'] + $this->stats['misses']) * 100, 2)
        : 0,
      'memory_usage_mb' => round(memory_get_usage(TRUE) / 1024 / 1024, 2),
      'max_entries' => $this->config['max_entries'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function maintenance() {
    $this->performCleanup();
    return TRUE;
  }

  /**
   * Performs cleanup of expired and excess entries.
   */
  protected function performCleanup() {
    $current_time = time();
    $cleaned_expired = 0;

    // Remove expired entries.
    foreach ($this->metadata as $hash => $metadata) {
      $expires = $metadata['expires'] ?? 0;
      if ($expires > 0 && $current_time > $expires) {
        unset($this->cache[$hash]);
        unset($this->metadata[$hash]);
        $cleaned_expired++;
      }
    }

    if ($cleaned_expired > 0) {
      $this->logger->debug('Cleaned up @count expired memory cache entries', ['@count' => $cleaned_expired]);
    }

    // Remove excess entries if over limit.
    $total_count = count($this->cache);
    if ($total_count > $this->config['max_entries']) {
      $excess = $total_count - $this->config['max_entries'];

      // Sort by LRU (least recently used)
      $lru_candidates = [];
      foreach ($this->metadata as $hash => $metadata) {
        $lru_candidates[$hash] = [
          'last_accessed' => $metadata['last_accessed'] ?? 0,
          'hit_count' => $metadata['hit_count'] ?? 0,
        ];
      }

      // Sort by last accessed time (ascending) and then by hit count (ascending)
      uasort($lru_candidates, function ($a, $b) {
        if ($a['last_accessed'] === $b['last_accessed']) {
            return $a['hit_count'] <=> $b['hit_count'];
        }
          return $a['last_accessed'] <=> $b['last_accessed'];
      });

      $hashes_to_remove = array_slice(array_keys($lru_candidates), 0, $excess);

      foreach ($hashes_to_remove as $hash) {
        unset($this->cache[$hash]);
        unset($this->metadata[$hash]);
      }

      $this->logger->debug('Cleaned up @count LRU memory cache entries (limit: @limit)', [
        '@count' => count($hashes_to_remove),
        '@limit' => $this->config['max_entries'],
      ]);
    }
  }

  /**
   * Validates a text hash.
   *
   * @param string $hash
   *   The hash to validate.
   *
   * @throws \InvalidArgumentException
   *   If the hash is invalid.
   */
  protected function validateHash($hash) {
    if (!is_string($hash) || strlen($hash) !== 64 || !ctype_xdigit($hash)) {
      throw new \InvalidArgumentException('Invalid hash format. Expected 64-character hexadecimal string.');
    }
  }

  /**
   * Validates an embedding vector.
   *
   * @param array $embedding
   *   The embedding to validate.
   *
   * @throws \InvalidArgumentException
   *   If the embedding is invalid.
   */
  protected function validateEmbedding(array $embedding) {
    if (empty($embedding)) {
      throw new \InvalidArgumentException('Embedding cannot be empty.');
    }

    if (count($embedding) > 16000) {
      throw new \InvalidArgumentException('Embedding too large: ' . count($embedding) . ' dimensions (max 16000).');
    }

    foreach ($embedding as $index => $value) {
      if (!is_numeric($value)) {
        throw new \InvalidArgumentException("Embedding component at index {$index} is not numeric.");
      }

      $float_val = (float) $value;
      if (!is_finite($float_val)) {
        throw new \InvalidArgumentException("Embedding component at index {$index} is infinite or NaN.");
      }
    }
  }

}
