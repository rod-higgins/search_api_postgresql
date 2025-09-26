<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Cache;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Memory embedding cache.
 *
 * @group              search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache
 */
class MemoryEmbeddingCacheUnitTest extends UnitTestCase
{
  /**
   * The memory cache under test.
   *
   * @var \Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache
   */
  protected $cache;

  /**
   * Mock logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->logger = $this->createMock(LoggerInterface::class);

    $config = [
      'max_entries' => 100,
      'default_ttl' => 3600,
    // Disable random cleanup for tests.
      'cleanup_probability' => 0.0,
    ];

    $this->cache = new MemoryEmbeddingCache($this->logger, $config);
  }

  /**
   * Tests cache miss scenario.
   *
   * @covers ::get
   */
  public function testCacheMiss()
  {
    $hash = 'nonexistent_hash';
    $result = $this->cache->get($hash);
    $this->assertNull($result);
  }

  /**
   * Tests cache set and get.
   *
   * @covers ::set
   * @covers ::get
   */
  public function testCacheSetAndGet()
  {
    $hash = 'test_hash_' . time();
    $embedding = [1.0, 2.0, 3.0, 4.0];

    // Set the embedding.
    $this->assertTrue($this->cache->set($hash, $embedding));

    // Get the embedding.
    $result = $this->cache->get($hash);
    $this->assertEquals($embedding, $result);
  }

  /**
   * Tests cache expiration.
   *
   * @covers ::set
   * @covers ::get
   */
  public function testCacheExpiration()
  {
    $hash = 'expiring_hash';
    $embedding = [1.0, 2.0, 3.0];

    // Set with short TTL (1 second)
    $this->assertTrue($this->cache->set($hash, $embedding, 1));

    // Should be available immediately.
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Wait for expiration.
    sleep(2);

    // Should be expired now.
    $this->assertNull($this->cache->get($hash));
  }

  /**
   * Tests cache invalidation.
   *
   * @covers ::set
   * @covers ::invalidate
   * @covers ::get
   */
  public function testCacheInvalidation()
  {
    $hash = 'invalidation_hash';
    $embedding = [5.0, 6.0, 7.0];

    // Set the embedding.
    $this->cache->set($hash, $embedding);
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Invalidate.
    $this->assertTrue($this->cache->invalidate($hash));

    // Should be gone now.
    $this->assertNull($this->cache->get($hash));
  }

  /**
   * Tests multiple cache operations.
   *
   * @covers ::setMultiple
   * @covers ::getMultiple
   */
  public function testMultipleCacheOperations()
  {
    $items = [
      'hash1' => [1.0, 2.0],
      'hash2' => [3.0, 4.0],
      'hash3' => [5.0, 6.0],
    ];

    // Set multiple.
    $this->assertTrue($this->cache->setMultiple($items));

    // Get multiple.
    $result = $this->cache->getMultiple(array_keys($items));

    $this->assertCount(3, $result);
    $this->assertEquals($items['hash1'], $result['hash1']);
    $this->assertEquals($items['hash2'], $result['hash2']);
    $this->assertEquals($items['hash3'], $result['hash3']);
  }

  /**
   * Tests cache clearing.
   *
   * @covers ::set
   * @covers ::clear
   * @covers ::get
   */
  public function testCacheClear()
  {
    // Set multiple items.
    $this->cache->set('clear_test1', [1.0, 2.0]);
    $this->cache->set('clear_test2', [3.0, 4.0]);

    // Verify they exist.
    $this->assertNotNull($this->cache->get('clear_test1'));
    $this->assertNotNull($this->cache->get('clear_test2'));

    // Clear all.
    $this->assertTrue($this->cache->clear());

    // Verify they're gone.
    $this->assertNull($this->cache->get('clear_test1'));
    $this->assertNull($this->cache->get('clear_test2'));
  }

  /**
   * Tests cache statistics.
   *
   * @covers ::getStats
   */
  public function testCacheStatistics()
  {
    // Perform some cache operations.
    $this->cache->set('stats_test1', [1.0]);
    $this->cache->set('stats_test2', [2.0]);
    // Hit.
    $this->cache->get('stats_test1');
    // Miss.
    $this->cache->get('nonexistent');

    $stats = $this->cache->getStats();

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('hits', $stats);
    $this->assertArrayHasKey('misses', $stats);
    $this->assertArrayHasKey('sets', $stats);
    $this->assertArrayHasKey('total_entries', $stats);

    $this->assertGreaterThanOrEqual(1, $stats['hits']);
    $this->assertGreaterThanOrEqual(1, $stats['misses']);
    $this->assertGreaterThanOrEqual(2, $stats['sets']);
  }

  /**
   * Tests cache size limits.
   *
   * @covers ::set
   */
  public function testCacheSizeLimits()
  {
    // Create cache with small max size.
    $smallCache = new MemoryEmbeddingCache($this->logger, ['max_entries' => 2]);

    // Add items up to the limit.
    $this->assertTrue($smallCache->set('item1', [1.0]));
    $this->assertTrue($smallCache->set('item2', [2.0]));

    // Verify both exist.
    $this->assertNotNull($smallCache->get('item1'));
    $this->assertNotNull($smallCache->get('item2'));

    // Add third item - should trigger eviction.
    $this->assertTrue($smallCache->set('item3', [3.0]));

    // item3 should exist.
    $this->assertNotNull($smallCache->get('item3'));

    // At least one of the earlier items should be evicted.
    $item1Exists = $smallCache->get('item1') !== null;
    $item2Exists = $smallCache->get('item2') !== null;
    $this->assertFalse($item1Exists && $item2Exists);
  }

  /**
   * Tests cache maintenance.
   *
   * @covers ::maintenance
   */
  public function testCacheMaintenance()
  {
    // Add some items.
    // Short TTL.
    $this->cache->set('maint_test1', [1.0], 1);
    // Long TTL.
    $this->cache->set('maint_test2', [2.0], 3600);

    // Wait for first item to expire.
    sleep(2);

    // Run maintenance.
    $result = $this->cache->maintenance();
    $this->assertTrue($result);

    // Expired item should be gone.
    $this->assertNull($this->cache->get('maint_test1'));
    // Non-expired item should still exist.
    $this->assertNotNull($this->cache->get('maint_test2'));
  }

  /**
   * Tests hash validation.
   *
   * @covers ::validateHash
   */
  public function testHashValidation()
  {
    // Valid hash (64 character hex string)
    $validHash = str_repeat('a', 64);
    $this->assertTrue($this->cache->set($validHash, [1.0]));

    // Invalid hash (too short)
    $this->expectException(\InvalidArgumentException::class);
    $this->cache->set('too_short', [1.0]);
  }

  /**
   * Tests embedding validation.
   *
   * @covers ::validateEmbedding
   */
  public function testEmbeddingValidation()
  {
    $hash = str_repeat('b', 64);

    // Valid embedding.
    $validEmbedding = [1.0, 2.0, 3.0];
    $this->assertTrue($this->cache->set($hash, $validEmbedding));

    // Empty embedding should fail.
    $this->expectException(\InvalidArgumentException::class);
    $this->cache->set($hash, []);
  }
}
