<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Cache;

use Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for the MemoryEmbeddingCache.
 *
 * @group search_api_postgresql
 */
class MemoryEmbeddingCacheTest extends TestCase
{
  /**
   * Simple logger implementation for testing.
   */
  protected $logger;

  /**
   * The cache instance under test.
   *
   * @var \Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache
   */
  protected $cache;

  /**
   * Test configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // Load the actual module files.
    require_once __DIR__ . '/../../../../../src/Cache/EmbeddingCacheInterface.php';
    require_once __DIR__ . '/../../../../../src/Cache/MemoryEmbeddingCache.php';

    // Create a simple logger.
    $this->logger = new class implements LoggerInterface {

      /**
       * {@inheritdoc}
       */
      public function emergency($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function alert($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function critical($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function error($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function warning($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function notice($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function info($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function debug($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = [])
      {
      }

    };

    $this->config = [
      'default_ttl' => 3600,
      'max_entries' => 100,
      'cleanup_threshold' => 0.9,
    // Disable random cleanup for deterministic tests.
      'cleanup_probability' => 0.0,
    ];

    $this->cache = new MemoryEmbeddingCache($this->logger, $this->config);
  }

  /**
   * Tests cache miss scenario.
   *
   * @covers ::get
   */
  public function testCacheMiss()
  {
    $hash = str_repeat('a', 64);

    $result = $this->cache->get($hash);
    $this->assertNull($result);
  }

  /**
   * Tests cache hit scenario.
   *
   * @covers ::get
   * @covers ::set
   */
  public function testCacheHitAndSet()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    // Test set.
    $result = $this->cache->set($hash, $embedding);
    $this->assertTrue($result);

    // Test get.
    $cached_embedding = $this->cache->get($hash);
    $this->assertEquals($embedding, $cached_embedding);
  }

  /**
   * Tests expired cache entry.
   *
   * @covers ::get
   * @covers ::set
   */
  public function testExpiredCacheEntry()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    // Set with very short TTL.
    $result = $this->cache->set($hash, $embedding, 1);
    $this->assertTrue($result);

    // Simulate time passing.
    sleep(2);

    // Should return null for expired entry.
    $cached_embedding = $this->cache->get($hash);
    $this->assertNull($cached_embedding);
  }

  /**
   * Tests cache entry with no expiration.
   *
   * @covers ::get
   * @covers ::set
   */
  public function testNoExpirationCacheEntry()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    // Set with no TTL (0 = no expiration)
    $result = $this->cache->set($hash, $embedding, 0);
    $this->assertTrue($result);

    // Should return the embedding.
    $cached_embedding = $this->cache->get($hash);
    $this->assertEquals($embedding, $cached_embedding);
  }

  /**
   * Tests invalidating cache entry.
   *
   * @covers ::invalidate
   * @covers ::set
   */
  public function testInvalidate()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    // Set entry.
    $this->cache->set($hash, $embedding);

    // Invalidate.
    $result = $this->cache->invalidate($hash);
    $this->assertTrue($result);

    // Should be null after invalidation.
    $cached_embedding = $this->cache->get($hash);
    $this->assertNull($cached_embedding);
  }

  /**
   * Tests invalidating non-existent entry.
   *
   * @covers ::invalidate
   */
  public function testInvalidateNonExistent()
  {
    $hash = str_repeat('a', 64);

    $result = $this->cache->invalidate($hash);
    $this->assertFalse($result);
  }

  /**
   * Tests getting multiple cache entries.
   *
   * @covers ::getMultiple
   * @covers ::set
   */
  public function testGetMultiple()
  {
    $entries = [
      str_repeat('a', 64) => [1.0, 2.0, 3.0],
      str_repeat('b', 64) => [4.0, 5.0, 6.0],
      str_repeat('c', 64) => [7.0, 8.0, 9.0],
    ];

    // Set entries.
    foreach ($entries as $hash => $embedding) {
      $this->cache->set($hash, $embedding);
    }

    // Get some entries (including one that doesn't exist)
    $hashes = [
      str_repeat('a', 64),
      str_repeat('b', 64),
    // This one doesn't exist.
      str_repeat('d', 64),
    ];

    $result = $this->cache->getMultiple($hashes);

    $this->assertCount(2, $result);
    $this->assertEquals($entries[str_repeat('a', 64)], $result[str_repeat('a', 64)]);
    $this->assertEquals($entries[str_repeat('b', 64)], $result[str_repeat('b', 64)]);
    $this->assertArrayNotHasKey(str_repeat('d', 64), $result);
  }

  /**
   * Tests setting multiple cache entries.
   *
   * @covers ::setMultiple
   * @covers ::get
   */
  public function testSetMultiple()
  {
    $items = [
      str_repeat('a', 64) => [1.0, 2.0, 3.0],
      str_repeat('b', 64) => [4.0, 5.0, 6.0],
    ];

    $result = $this->cache->setMultiple($items);
    $this->assertTrue($result);

    // Verify entries were set.
    foreach ($items as $hash => $embedding) {
      $cached_embedding = $this->cache->get($hash);
      $this->assertEquals($embedding, $cached_embedding);
    }
  }

  /**
   * Tests clearing all cache entries.
   *
   * @covers ::clear
   * @covers ::set
   * @covers ::get
   */
  public function testClear()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    // Set entry.
    $this->cache->set($hash, $embedding);
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Clear cache.
    $result = $this->cache->clear();
    $this->assertTrue($result);

    // Entry should be gone.
    $cached_embedding = $this->cache->get($hash);
    $this->assertNull($cached_embedding);
  }

  /**
   * Tests getting cache statistics.
   *
   * @covers ::getStats
   * @covers ::set
   * @covers ::get
   */
  public function testGetStats()
  {
    $hash1 = str_repeat('a', 64);
    $hash2 = str_repeat('b', 64);
    $embedding1 = [1.0, 2.0, 3.0];
    $embedding2 = [4.0, 5.0, 6.0];

    // Set some entries.
    $this->cache->set($hash1, $embedding1);
    $this->cache->set($hash2, $embedding2);

    // Access them to generate hits.
    $this->cache->get($hash1);
    $this->cache->get($hash2);
    // This will be a miss.
    $this->cache->get(str_repeat('c', 64));

    $stats = $this->cache->getStats();

    $this->assertIsArray($stats);
    $this->assertEquals(2, $stats['hits']);
    $this->assertEquals(1, $stats['misses']);
    $this->assertEquals(2, $stats['sets']);
    $this->assertEquals(2, $stats['total_entries']);
    $this->assertArrayHasKey('hit_rate', $stats);
    // 2 hits out of 3 total
    $this->assertEquals(66.67, $stats['hit_rate']);
  }

  /**
   * Tests cache cleanup when reaching max entries.
   *
   * @covers ::set
   * @covers ::performCleanup
   */
  public function testCacheCleanupOnMaxEntries()
  {
    // Set config with very low max entries.
    $cache = new MemoryEmbeddingCache($this->logger, [
      'default_ttl' => 3600,
      'max_entries' => 2,
    // Cleanup when 50% full (1 entry)
      'cleanup_threshold' => 0.5,
    ]);

    // Add entries up to the cleanup threshold.
    $cache->set(str_repeat('a', 64), [1.0]);
    $cache->set(str_repeat('b', 64), [2.0]);

    // This should trigger cleanup.
    $cache->set(str_repeat('c', 64), [3.0]);

    $stats = $cache->getStats();
    $this->assertLessThanOrEqual(2, $stats['total_entries']);
  }

  /**
   * Tests cache maintenance.
   *
   * @covers ::maintenance
   * @covers ::set
   */
  public function testCacheMaintenance()
  {
    // Add some entries with short TTL.
    $this->cache->set(str_repeat('a', 64), [1.0], 1);
    $this->cache->set(str_repeat('b', 64), [2.0], 3600);

    // Wait for expiration.
    sleep(2);

    // Run maintenance.
    $result = $this->cache->maintenance();
    $this->assertTrue($result);

    // Check that expired entry is cleaned up.
    $this->assertNull($this->cache->get(str_repeat('a', 64)));
    $this->assertNotNull($this->cache->get(str_repeat('b', 64)));
  }

  /**
   * Tests hash validation.
   */
  public function testHashValidation()
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid hash format');

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateHash');
    $method->setAccessible(true);

    // Test with invalid hash.
    $method->invokeArgs($this->cache, ['invalid_hash']);
  }

  /**
   * Tests embedding validation.
   */
  public function testEmbeddingValidation()
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Embedding cannot be empty');

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateEmbedding');
    $method->setAccessible(true);

    // Test with empty embedding.
    $method->invokeArgs($this->cache, [[]]);
  }

  /**
   * Tests empty input handling.
   *
   * @covers ::get
   * @covers ::set
   * @covers ::invalidate
   * @covers ::getMultiple
   * @covers ::setMultiple
   */
  public function testEmptyInputHandling()
  {
    // Test empty hash.
    $this->assertNull($this->cache->get(''));
    $this->assertFalse($this->cache->set('', [1.0]));
    $this->assertFalse($this->cache->invalidate(''));

    // Test empty arrays.
    $this->assertEquals([], $this->cache->getMultiple([]));
    $this->assertTrue($this->cache->setMultiple([]));
  }

  /**
   * Tests access time and hit count tracking.
   *
   * @covers ::get
   * @covers ::set
   */
  public function testAccessTimeAndHitCountTracking()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    $this->cache->set($hash, $embedding);

    // Access multiple times.
    $this->cache->get($hash);
    $this->cache->get($hash);
    $this->cache->get($hash);

    // Use reflection to check metadata.
    $reflection = new \ReflectionClass($this->cache);
    $metadata_property = $reflection->getProperty('metadata');
    $metadata_property->setAccessible(true);
    $metadata = $metadata_property->getValue($this->cache);

    $this->assertEquals(3, $metadata[$hash]['hit_count']);
    $this->assertGreaterThan(time() - 10, $metadata[$hash]['last_accessed']);
  }
}
