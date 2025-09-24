<?php

namespace Drupal\search_api_postgresql\Cache;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Database-backed embedding cache implementation.
 */
class DatabaseEmbeddingCache implements EmbeddingCacheInterface {
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
   * The cache table name.
   *
   * @var string
   */
  protected $tableName;

  /**
   * Cache configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a DatabaseEmbeddingCache object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param array $config
   *   Cache configuration.
   */
  public function __construct(Connection $connection, LoggerInterface $logger, array $config = []) {
    $this->connection = $connection;
    $this->logger = $logger;
    $this->tableName = $config['table_name'] ?? 'search_api_postgresql_embedding_cache';
    $this->config = $config + [
    // 30 days default
      'default_ttl' => 86400 * 30,
      'max_entries' => 100000,
    // 1% chance of cleanup on write
      'cleanup_probability' => 0.01,
      'enable_compression' => TRUE,
    ];

    $this->ensureTableExists();
  }

  /**
   * {@inheritdoc}
   */
  public function get($text_hash) {
    if (empty($text_hash)) {
      return NULL;
    }

    $this->validateHash($text_hash);

    $request_time = \Drupal::time()->getRequestTime();

    try {
      $query = $this->connection->select($this->tableName, 'c')
        ->fields('c', ['embedding_data', 'created', 'expires'])
        ->condition('text_hash', $text_hash)
        ->condition('expires', $request_time, '>')
        ->range(0, 1);

      $result = $query->execute()->fetchAssoc();

      if ($result) {
        $this->stats['hits']++;

        // Update last accessed time.
        $this->connection->update($this->tableName)
          ->fields(['last_accessed' => $request_time])
          ->condition('text_hash', $text_hash)
          ->execute();

        $embedding = $this->unserializeEmbedding($result['embedding_data']);

        $this->logger->debug('Embedding cache hit for hash: @hash', ['@hash' => $text_hash]);

        return $embedding;
      }
      else {
        $this->stats['misses']++;
        $this->logger->debug('Embedding cache miss for hash: @hash', ['@hash' => $text_hash]);
        return NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get embedding from cache: @message', ['@message' => $e->getMessage()]);
      $this->stats['misses']++;
      return NULL;
    }
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

    $request_time = \Drupal::time()->getRequestTime();

    $ttl = $ttl ?? $this->config['default_ttl'];
    $expires = $request_time + $ttl;

    try {
      $embedding_data = $this->serializeEmbedding($embedding);

      // Use MERGE/UPSERT pattern for PostgreSQL.
      $this->connection->merge($this->tableName)
        ->key(['text_hash' => $text_hash])
        ->fields([
          'embedding_data' => $embedding_data,
          'dimensions' => count($embedding),
          'created' => $request_time,
          'last_accessed' => $request_time,
          'expires' => $expires,
          'hit_count' => 1,
        ])
        ->expression('hit_count', 'hit_count + 1')
        ->execute();

      $this->stats['sets']++;

      $this->logger->debug('Cached embedding for hash: @hash (dimensions: @dim, expires: @expires)', [
        '@hash' => $text_hash,
        '@dim' => count($embedding),
        '@expires' => date('Y-m-d H:i:s', $expires),
      ]);

      // Probabilistic cleanup.
      if (mt_rand() / mt_getrandmax() < $this->config['cleanup_probability']) {
        $this->performCleanup();
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to set embedding in cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($text_hash) {
    if (empty($text_hash)) {
      return FALSE;
    }

    $this->validateHash($text_hash);

    try {
      $deleted = $this->connection->delete($this->tableName)
        ->condition('text_hash', $text_hash)
        ->execute();

      if ($deleted) {
        $this->stats['invalidations']++;
        $this->logger->debug('Invalidated embedding cache for hash: @hash', ['@hash' => $text_hash]);
      }

      return (bool) $deleted;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to invalidate embedding cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
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

    $request_time = \Drupal::time()->getRequestTime();

    // Validate all hashes.
    foreach ($text_hashes as $hash) {
      $this->validateHash($hash);
    }

    try {
      $query = $this->connection->select($this->tableName, 'c')
        ->fields('c', ['text_hash', 'embedding_data'])
        ->condition('text_hash', $text_hashes, 'IN')
        ->condition('expires', $request_time, '>');

      $results = $query->execute()->fetchAllKeyed();
      $embeddings = [];

      foreach ($results as $hash => $embedding_data) {
        $embedding = $this->unserializeEmbedding($embedding_data);
        if ($embedding !== NULL) {
          $embeddings[$hash] = $embedding;
          $this->stats['hits']++;
        }
      }

      // Update last accessed time for found items.
      if (!empty($embeddings)) {
        $this->connection->update($this->tableName)
          ->fields(['last_accessed' => $request_time])
          ->condition('text_hash', array_keys($embeddings), 'IN')
          ->execute();
      }

      // Count misses.
      $missed = array_diff($text_hashes, array_keys($embeddings));
      $this->stats['misses'] += count($missed);

      $this->logger->debug('Batch embedding cache lookup: @hits hits, @misses misses', [
        '@hits' => count($embeddings),
        '@misses' => count($missed),
      ]);

      return $embeddings;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get multiple embeddings from cache: @message', ['@message' => $e->getMessage()]);
      $this->stats['misses'] += count($text_hashes);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items, $ttl = NULL) {
    if (empty($items)) {
      return TRUE;
    }

    $request_time = \Drupal::time()->getRequestTime();

    $ttl = $ttl ?? $this->config['default_ttl'];
    $expires = $request_time + $ttl;

    try {
      $transaction = $this->connection->startTransaction();

      foreach ($items as $text_hash => $embedding) {
        if (empty($text_hash) || empty($embedding)) {
          continue;
        }

        $this->validateHash($text_hash);
        $this->validateEmbedding($embedding);

        $embedding_data = $this->serializeEmbedding($embedding);

        $this->connection->merge($this->tableName)
          ->key(['text_hash' => $text_hash])
          ->fields([
            'embedding_data' => $embedding_data,
            'dimensions' => count($embedding),
            'created' => $request_time,
            'last_accessed' => $request_time,
            'expires' => $expires,
            'hit_count' => 1,
          ])
          ->expression('hit_count', 'hit_count + 1')
          ->execute();

        $this->stats['sets']++;
      }

      $this->logger->debug('Batch cached @count embeddings', ['@count' => count($items)]);

      return TRUE;
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->logger->error('Failed to set multiple embeddings in cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    try {
      $deleted = $this->connection->delete($this->tableName)->execute();

      $this->logger->info('Cleared embedding cache: @count entries deleted', ['@count' => $deleted]);

      // Reset stats.
      $this->stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'invalidations' => $this->stats['invalidations'] + $deleted,
      ];

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear embedding cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStats() {
    $request_time = \Drupal::time()->getRequestTime();

    try {
      // Get database stats.
      $query = $this->connection->select($this->tableName, 'c');
      $query->addExpression('COUNT(*)', 'total_entries');
      $query->addExpression('SUM(hit_count)', 'total_hits');
      $query->addExpression('AVG(dimensions)', 'avg_dimensions');
      $query->addExpression('MIN(created)', 'oldest_entry');
      $query->addExpression('MAX(created)', 'newest_entry');

      $db_stats = $query->execute()->fetchAssoc();

      // Get expired count.
      $expired_count = $this->connection->select($this->tableName, 'c')
        ->condition('expires', $request_time, '<=')
        ->countQuery()
        ->execute()
        ->fetchField();

      return [
        'hits' => $this->stats['hits'],
        'misses' => $this->stats['misses'],
        'sets' => $this->stats['sets'],
        'invalidations' => $this->stats['invalidations'],
        'total_entries' => (int) $db_stats['total_entries'],
        'expired_entries' => (int) $expired_count,
        'total_database_hits' => (int) $db_stats['total_hits'],
        'average_dimensions' => round((float) $db_stats['avg_dimensions'], 2),
        'oldest_entry' => $db_stats['oldest_entry'] ? date('Y-m-d H:i:s', $db_stats['oldest_entry']) : NULL,
        'newest_entry' => $db_stats['newest_entry'] ? date('Y-m-d H:i:s', $db_stats['newest_entry']) : NULL,
        'hit_rate' => $this->stats['hits'] + $this->stats['misses'] > 0
          ? round($this->stats['hits'] / ($this->stats['hits'] + $this->stats['misses']) * 100, 2)
          : 0,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get cache stats: @message', ['@message' => $e->getMessage()]);
      return $this->stats + ['error' => $e->getMessage()];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function maintenance() {
    try {
      $this->performCleanup();
      $this->optimizeTable();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Cache maintenance failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Performs cleanup of expired and excess entries.
   */
  protected function performCleanup() {
    $request_time = \Drupal::time()->getRequestTime();

    // Remove expired entries.
    $expired_deleted = $this->connection->delete($this->tableName)
      ->condition('expires', $request_time, '<=')
      ->execute();

    if ($expired_deleted > 0) {
      $this->logger->debug('Cleaned up @count expired cache entries', ['@count' => $expired_deleted]);
    }

    // Remove excess entries if over limit.
    $total_count = $this->connection->select($this->tableName, 'c')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($total_count > $this->config['max_entries']) {
      $excess = $total_count - $this->config['max_entries'];

      // Delete least recently used entries.
      $subquery = $this->connection->select($this->tableName, 'c')
        ->fields('c', ['text_hash'])
        ->orderBy('last_accessed', 'ASC')
        ->orderBy('hit_count', 'ASC')
        ->range(0, $excess);

      $hashes_to_delete = $subquery->execute()->fetchCol();

      if (!empty($hashes_to_delete)) {
        $lru_deleted = $this->connection->delete($this->tableName)
          ->condition('text_hash', $hashes_to_delete, 'IN')
          ->execute();

        $this->logger->debug('Cleaned up @count LRU cache entries (limit: @limit)', [
          '@count' => $lru_deleted,
          '@limit' => $this->config['max_entries'],
        ]);
      }
    }
  }

  /**
   * Optimizes the cache table.
   */
  protected function optimizeTable() {
    // For PostgreSQL, run VACUUM and ANALYZE.
    if ($this->connection->driver() === 'pgsql') {
      try {
        $this->connection->query("VACUUM ANALYZE {" . $this->tableName . "}");
        $this->logger->debug('Optimized embedding cache table');
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to optimize cache table: @message', ['@message' => $e->getMessage()]);
      }
    }
  }

  /**
   * Ensures the cache table exists.
   */
  protected function ensureTableExists() {
    $schema = $this->connection->schema();

    if (!$schema->tableExists($this->tableName)) {
      $table_definition = $this->getTableDefinition();
      $schema->createTable($this->tableName, $table_definition);
      $this->logger->info('Created embedding cache table: @table', ['@table' => $this->tableName]);
    }
  }

  /**
   * Returns the table definition.
   *
   * @return array
   *   The table schema definition.
   */
  protected function getTableDefinition() {
    return [
      'description' => 'Cache table for embedding vectors.',
      'fields' => [
        'text_hash' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'description' => 'SHA256 hash of the text.',
        ],
        'embedding_data' => [
          'type' => 'blob',
          'size' => 'big',
          'not null' => TRUE,
          'description' => 'Serialized and possibly compressed embedding vector.',
        ],
        'dimensions' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Number of dimensions in the vector.',
        ],
        'created' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Unix timestamp when created.',
        ],
        'last_accessed' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Unix timestamp when last accessed.',
        ],
        'expires' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Unix timestamp when expires.',
        ],
        'hit_count' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Number of times accessed.',
        ],
      ],
      'primary key' => ['text_hash'],
      'indexes' => [
        'expires' => ['expires'],
        'last_accessed' => ['last_accessed'],
        'created' => ['created'],
      ],
    ];
  }

  /**
   * Validates a cache hash.
   *
   * @param string $hash
   *   The hash to validate.
   *
   * @throws \InvalidArgumentException
   *   If the hash is invalid.
   */
  protected function validateHash($hash) {
    if (!is_string($hash) || strlen($hash) !== 64 || !ctype_xdigit($hash)) {
      throw new \InvalidArgumentException('Invalid hash format. Expected 64-character hex string.');
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

    foreach ($embedding as $value) {
      if (!is_numeric($value)) {
        throw new \InvalidArgumentException('All embedding values must be numeric.');
      }
    }
  }

  /**
   * Serializes an embedding for storage.
   *
   * @param array $embedding
   *   The embedding vector.
   *
   * @return string
   *   The serialized embedding.
   */
  protected function serializeEmbedding(array $embedding) {
    $data = json_encode($embedding);

    if ($this->config['enable_compression'] && function_exists('gzcompress')) {
      $compressed = gzcompress($data, 9);
      if ($compressed !== FALSE && strlen($compressed) < strlen($data)) {
        return $compressed;
      }
    }

    return $data;
  }

  /**
   * Unserializes an embedding from storage.
   *
   * @param string $data
   *   The serialized embedding data.
   *
   * @return array|null
   *   The embedding vector or NULL on failure.
   */
  protected function unserializeEmbedding($data) {
    if (empty($data)) {
      return NULL;
    }

    // Try to decompress if it looks like compressed data.
    if ($this->config['enable_compression'] && function_exists('gzuncompress')) {
      $uncompressed = @gzuncompress($data);
      if ($uncompressed !== FALSE) {
        $data = $uncompressed;
      }
    }

    $embedding = json_decode($data, TRUE);

    return is_array($embedding) ? $embedding : NULL;
  }

}
