<?php

namespace Drupal\Tests\search_api_postgresql\Integration;

use Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real integration tests for Memory Embedding Cache.
 *
 * @group search_api_postgresql
 */
class MemoryEmbeddingCacheIntegrationTest extends TestCase {
  /**
   * The cache instance under test.
   *
   * @var \Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache
   */
  protected $cache;

  /**
   * Mock logger for testing.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Include the actual module files.
    require_once __DIR__ . '/../../../src/Cache/EmbeddingCacheInterface.php';
    require_once __DIR__ . '/../../../src/Cache/MemoryEmbeddingCache.php';

    // Create a simple logger mock.
    $this->logger = new class implements LoggerInterface {

      /**
       *
       */
      public function emergency($message, array $context = []) {
      }

      /**
       *
       */
      public function alert($message, array $context = []) {
      }

      /**
       *
       */
      public function critical($message, array $context = []) {
      }

      /**
       *
       */
      public function error($message, array $context = []) {
      }

      /**
       *
       */
      public function warning($message, array $context = []) {
      }

      /**
       *
       */
      public function notice($message, array $context = []) {
      }

      /**
       *
       */
      public function info($message, array $context = []) {
      }

      /**
       *
       */
      public function debug($message, array $context = []) {
      }

      /**
       *
       */
      public function log($level, $message, array $context = []) {
      }

    };

    // Test configuration.
    $config = [
      'max_entries' => 10,
      'default_ttl' => 3600,
    // Disable for deterministic tests.
      'cleanup_probability' => 0.0,
    ];

    // Instantiate the real class.
    $this->cache = new MemoryEmbeddingCache($this->logger, $config);
  }

  /**
   * Test basic cache operations with real implementation.
   */
  public function testBasicCacheOperations() {
    // Test cache miss.
    $result = $this->cache->get('nonexistent');
    $this->assertNull($result);

    // Test cache set and get.
    $hash = 'test_hash_' . time();
    $embedding = [1.0, 2.0, 3.0, 4.0];

    $this->assertTrue($this->cache->set($hash, $embedding));
    $retrieved = $this->cache->get($hash);
    $this->assertEquals($embedding, $retrieved);
  }

  /**
   * Test cache expiration functionality.
   */
  public function testCacheExpiration() {
    $hash = 'expiring_hash';
    $embedding = [1.0, 2.0, 3.0];

    // Set with very short TTL.
    $this->assertTrue($this->cache->set($hash, $embedding, 1));

    // Should be available immediately.
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Wait for expiration and test.
    sleep(2);
    $this->assertNull($this->cache->get($hash));
  }

  /**
   * Test cache invalidation.
   */
  public function testCacheInvalidation() {
    $hash = 'invalidation_test';
    $embedding = [5.0, 6.0, 7.0];

    // Set and verify.
    $this->cache->set($hash, $embedding);
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Invalidate and verify.
    $this->assertTrue($this->cache->invalidate($hash));
    $this->assertNull($this->cache->get($hash));
  }

  /**
   * Test multiple cache operations.
   */
  public function testMultipleCacheOperations() {
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
   * Test cache clearing functionality.
   */
  public function testCacheClear() {
    // Add multiple items.
    $this->cache->set('clear1', [1.0, 2.0]);
    $this->cache->set('clear2', [3.0, 4.0]);

    // Verify they exist.
    $this->assertNotNull($this->cache->get('clear1'));
    $this->assertNotNull($this->cache->get('clear2'));

    // Clear all.
    $this->assertTrue($this->cache->clear());

    // Verify they're gone.
    $this->assertNull($this->cache->get('clear1'));
    $this->assertNull($this->cache->get('clear2'));
  }

  /**
   * Test cache statistics tracking.
   */
  public function testCacheStatistics() {
    // Perform operations to generate stats.
    $this->cache->set('stats1', [1.0]);
    $this->cache->set('stats2', [2.0]);
    // Hit.
    $this->cache->get('stats1');
    // Miss.
    $this->cache->get('nonexistent');

    $stats = $this->cache->getStats();

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('hits', $stats);
    $this->assertArrayHasKey('misses', $stats);
    $this->assertArrayHasKey('sets', $stats);
    $this->assertGreaterThanOrEqual(1, $stats['hits']);
    $this->assertGreaterThanOrEqual(1, $stats['misses']);
    $this->assertGreaterThanOrEqual(2, $stats['sets']);
  }

  /**
   * Test cache size limits and eviction.
   */
  public function testCacheSizeLimits() {
    // Create cache with small limit.
    $smallCache = new MemoryEmbeddingCache(
          $this->logger,
          ['max_entries' => 2]
      );

    // Fill to capacity.
    $this->assertTrue($smallCache->set('item1', [1.0]));
    $this->assertTrue($smallCache->set('item2', [2.0]));

    // Both should exist.
    $this->assertNotNull($smallCache->get('item1'));
    $this->assertNotNull($smallCache->get('item2'));

    // Add third item to trigger eviction.
    $this->assertTrue($smallCache->set('item3', [3.0]));

    // item3 should exist.
    $this->assertNotNull($smallCache->get('item3'));

    // At least one of the earlier items should be evicted.
    $item1Exists = $smallCache->get('item1') !== NULL;
    $item2Exists = $smallCache->get('item2') !== NULL;
    $this->assertFalse($item1Exists && $item2Exists);
  }

  /**
   * Test hash validation.
   */
  public function testHashValidation() {
    // Valid hash should work.
    $validHash = str_repeat('a', 64);
    $this->assertTrue($this->cache->set($validHash, [1.0]));

    // Invalid hash should throw exception.
    $this->expectException(\InvalidArgumentException::class);
    $this->cache->set('too_short', [1.0]);
  }

  /**
   * Test embedding validation.
   */
  public function testEmbeddingValidation() {
    $hash = str_repeat('b', 64);

    // Valid embedding should work.
    $this->assertTrue($this->cache->set($hash, [1.0, 2.0, 3.0]));

    // Empty embedding should fail.
    $this->expectException(\InvalidArgumentException::class);
    $this->cache->set($hash, []);
  }

  /**
   * Test cache maintenance operations.
   */
  public function testCacheMaintenance() {
    // Add items with different TTLs.
    // Short TTL.
    $this->cache->set('maint1', [1.0], 1);
    // Long TTL.
    $this->cache->set('maint2', [2.0], 3600);

    // Wait for first to expire.
    sleep(2);

    // Run maintenance.
    $result = $this->cache->maintenance();
    $this->assertTrue($result);

    // Expired item should be cleaned up.
    $this->assertNull($this->cache->get('maint1'));
    // Non-expired should remain.
    $this->assertNotNull($this->cache->get('maint2'));
  }

}
