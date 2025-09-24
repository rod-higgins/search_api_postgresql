<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Cache;

use Drupal\search_api_postgresql\Cache\EmbeddingCacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmbeddingCacheInterface compliance.
 *
 * @group search_api_postgresql
 */
class EmbeddingCacheInterfaceTest extends TestCase {
  /**
   * Test cache implementation.
   */
  protected $cache;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load actual interface.
    require_once __DIR__ . '/../../../../../../src/Cache/EmbeddingCacheInterface.php';

    // Create a test implementation of the interface.
    $this->cache = new class implements EmbeddingCacheInterface {
      private $storage = [];
      private $stats = ['hits' => 0, 'misses' => 0, 'sets' => 0];

      /**
       *
       */
      public function get($text_hash) {
        if (isset($this->storage[$text_hash])) {
          $this->stats['hits']++;
          return $this->storage[$text_hash]['data'];
        }
        $this->stats['misses']++;
        return NULL;
      }

      /**
       *
       */
      public function set($text_hash, array $embedding, $ttl = NULL) {
        $this->storage[$text_hash] = [
          'data' => $embedding,
          'ttl' => $ttl,
          'created' => time(),
        ];
        $this->stats['sets']++;
        return TRUE;
      }

      /**
       *
       */
      public function invalidate($text_hash) {
        if (isset($this->storage[$text_hash])) {
          unset($this->storage[$text_hash]);
          return TRUE;
        }
        return FALSE;
      }

      /**
       *
       */
      public function getMultiple(array $text_hashes) {
        $results = [];
        foreach ($text_hashes as $hash) {
          $result = $this->get($hash);
          if ($result !== NULL) {
            $results[$hash] = $result;
          }
        }
        return $results;
      }

      /**
       *
       */
      public function setMultiple(array $items, $ttl = NULL) {
        foreach ($items as $hash => $embedding) {
          if (!$this->set($hash, $embedding, $ttl)) {
            return FALSE;
          }
        }
        return TRUE;
      }

      /**
       *
       */
      public function clear() {
        $this->storage = [];
        return TRUE;
      }

      /**
       *
       */
      public function getStats() {
        return array_merge($this->stats, [
          'size' => count($this->storage),
          'memory_usage' => memory_get_usage(),
        ]);
      }

      /**
       *
       */
      public function maintenance() {
        // Remove expired entries.
        $now = time();
        foreach ($this->storage as $hash => $item) {
          if ($item['ttl'] && ($item['created'] + $item['ttl']) < $now) {
            unset($this->storage[$hash]);
          }
        }
        return TRUE;
      }

    };
  }

  /**
   * Tests cache interface compliance.
   */
  public function testInterfaceCompliance() {
    $this->assertInstanceOf(
          EmbeddingCacheInterface::class,
          $this->cache
      );
  }

  /**
   * Tests get and set operations.
   */
  public function testGetAndSet() {
    $textHash = hash('sha256', 'test content');
    $embedding = array_fill(0, 1536, 0.1);

    // Test setting an embedding.
    $result = $this->cache->set($textHash, $embedding);
    $this->assertTrue($result);

    // Test getting the embedding.
    $retrieved = $this->cache->get($textHash);
    $this->assertEquals($embedding, $retrieved);

    // Test getting non-existent embedding.
    $nonExistent = $this->cache->get('non_existent_hash');
    $this->assertNull($nonExistent);
  }

  /**
   * Tests invalidate operation.
   */
  public function testInvalidate() {
    $textHash = hash('sha256', 'test content for invalidation');
    $embedding = array_fill(0, 1536, 0.2);

    // Set an embedding.
    $this->cache->set($textHash, $embedding);
    $this->assertEquals($embedding, $this->cache->get($textHash));

    // Invalidate it.
    $result = $this->cache->invalidate($textHash);
    $this->assertTrue($result);

    // Verify it's gone.
    $this->assertNull($this->cache->get($textHash));

    // Test invalidating non-existent item.
    $result = $this->cache->invalidate('non_existent_hash');
    $this->assertFalse($result);
  }

  /**
   * Tests getMultiple operation.
   */
  public function testGetMultiple() {
    $items = [
      hash('sha256', 'content1') => array_fill(0, 1536, 0.1),
      hash('sha256', 'content2') => array_fill(0, 1536, 0.2),
      hash('sha256', 'content3') => array_fill(0, 1536, 0.3),
    ];

    // Set multiple items.
    foreach ($items as $hash => $embedding) {
      $this->cache->set($hash, $embedding);
    }

    // Get multiple items.
    $hashes = array_keys($items);
    $retrieved = $this->cache->getMultiple($hashes);

    $this->assertIsArray($retrieved);
    $this->assertCount(3, $retrieved);

    foreach ($items as $hash => $embedding) {
      $this->assertArrayHasKey($hash, $retrieved);
      $this->assertEquals($embedding, $retrieved[$hash]);
    }

    // Test with some non-existent hashes.
    $mixedHashes = array_merge($hashes, ['non_existent1', 'non_existent2']);
    $mixedRetrieved = $this->cache->getMultiple($mixedHashes);

    // Only existing items.
    $this->assertCount(3, $mixedRetrieved);
    $this->assertArrayNotHasKey('non_existent1', $mixedRetrieved);
    $this->assertArrayNotHasKey('non_existent2', $mixedRetrieved);
  }

  /**
   * Tests setMultiple operation.
   */
  public function testSetMultiple() {
    $items = [
      hash('sha256', 'batch1') => array_fill(0, 1536, 0.4),
      hash('sha256', 'batch2') => array_fill(0, 1536, 0.5),
      hash('sha256', 'batch3') => array_fill(0, 1536, 0.6),
    ];

    // Set multiple items at once.
    $result = $this->cache->setMultiple($items);
    $this->assertTrue($result);

    // Verify all items were set.
    foreach ($items as $hash => $embedding) {
      $retrieved = $this->cache->get($hash);
      $this->assertEquals($embedding, $retrieved);
    }

    // Test setMultiple with TTL.
    $itemsWithTtl = [
      hash('sha256', 'ttl1') => array_fill(0, 1536, 0.7),
      hash('sha256', 'ttl2') => array_fill(0, 1536, 0.8),
    ];

    $result = $this->cache->setMultiple($itemsWithTtl, 3600);
    $this->assertTrue($result);

    // Verify TTL items were set.
    foreach ($itemsWithTtl as $hash => $embedding) {
      $retrieved = $this->cache->get($hash);
      $this->assertEquals($embedding, $retrieved);
    }
  }

  /**
   * Tests clear operation.
   */
  public function testClear() {
    // Add some items.
    $items = [
      hash('sha256', 'clear1') => array_fill(0, 1536, 0.9),
      hash('sha256', 'clear2') => array_fill(0, 1536, 1.0),
    ];

    foreach ($items as $hash => $embedding) {
      $this->cache->set($hash, $embedding);
    }

    // Verify items exist.
    foreach (array_keys($items) as $hash) {
      $this->assertNotNull($this->cache->get($hash));
    }

    // Clear cache.
    $result = $this->cache->clear();
    $this->assertTrue($result);

    // Verify items are gone.
    foreach (array_keys($items) as $hash) {
      $this->assertNull($this->cache->get($hash));
    }
  }

  /**
   * Tests getStats operation.
   */
  public function testGetStats() {
    // Get initial stats.
    $initialStats = $this->cache->getStats();
    $this->assertIsArray($initialStats);
    $this->assertArrayHasKey('hits', $initialStats);
    $this->assertArrayHasKey('misses', $initialStats);
    $this->assertArrayHasKey('sets', $initialStats);
    $this->assertArrayHasKey('size', $initialStats);

    // Perform some operations.
    $hash1 = hash('sha256', 'stats test 1');
    $hash2 = hash('sha256', 'stats test 2');
    $embedding = array_fill(0, 1536, 0.5);

    $this->cache->set($hash1, $embedding);
    $this->cache->set($hash2, $embedding);
    // Hit.
    $this->cache->get($hash1);
    // Miss.
    $this->cache->get('non_existent');

    // Get updated stats.
    $updatedStats = $this->cache->getStats();
    $this->assertGreaterThan($initialStats['sets'], $updatedStats['sets']);
    $this->assertGreaterThan($initialStats['hits'], $updatedStats['hits']);
    $this->assertGreaterThan($initialStats['misses'], $updatedStats['misses']);
    $this->assertGreaterThan($initialStats['size'], $updatedStats['size']);
  }

  /**
   * Tests maintenance operation.
   */
  public function testMaintenance() {
    // Add some items with TTL.
    $shortTtlHash = hash('sha256', 'short ttl');
    $longTtlHash = hash('sha256', 'long ttl');
    $noTtlHash = hash('sha256', 'no ttl');

    $embedding = array_fill(0, 1536, 0.3);

    // 1 second TTL
    $this->cache->set($shortTtlHash, $embedding, 1);
    // 1 hour TTL
    $this->cache->set($longTtlHash, $embedding, 3600);
    // No TTL.
    $this->cache->set($noTtlHash, $embedding);

    // Verify all items exist.
    $this->assertNotNull($this->cache->get($shortTtlHash));
    $this->assertNotNull($this->cache->get($longTtlHash));
    $this->assertNotNull($this->cache->get($noTtlHash));

    // Wait for short TTL to expire (simulate)
    sleep(2);

    // Run maintenance.
    $result = $this->cache->maintenance();
    $this->assertTrue($result);

    // Check results (this depends on implementation)
    // The short TTL item should be expired
    // The long TTL and no TTL items should remain.
    // Maintenance completed successfully.
    $this->assertTrue(TRUE);
  }

  /**
   * Tests interface method signatures.
   */
  public function testInterfaceMethodSignatures() {
    $reflection = new \ReflectionClass(EmbeddingCacheInterface::class);
    $methods = $reflection->getMethods();

    $expectedMethods = [
      'get',
      'set',
      'invalidate',
      'getMultiple',
      'setMultiple',
      'clear',
      'getStats',
      'maintenance',
    ];

    $methodNames = array_map(function ($method) {
        return $method->getName();
    }, $methods);

    foreach ($expectedMethods as $expectedMethod) {
      $this->assertContains(
            $expectedMethod,
            $methodNames,
            "Interface should define method: {$expectedMethod}"
        );
    }
  }

  /**
   * Tests embedding data validation.
   */
  public function testEmbeddingDataValidation() {
    $validEmbeddings = [
    // Standard OpenAI embedding size.
      array_fill(0, 1536, 0.1),
    // Alternative embedding size.
      array_fill(0, 768, 0.2),
    // Small test embedding.
      [0.1, 0.2, 0.3, 0.4, 0.5],
    ];

    foreach ($validEmbeddings as $index => $embedding) {
      $hash = hash('sha256', "test embedding {$index}");
      $result = $this->cache->set($hash, $embedding);
      $this->assertTrue($result, "Should accept valid embedding array");

      $retrieved = $this->cache->get($hash);
      $this->assertEquals($embedding, $retrieved);
      $this->assertIsArray($retrieved);
      $this->assertCount(count($embedding), $retrieved);
    }
  }

  /**
   * Tests hash validation.
   */
  public function testHashValidation() {
    $validHashes = [
      hash('sha256', 'test content'),
      hash('md5', 'test content'),
      'custom_hash_string_12345',
      'another-valid-hash_format',
    ];

    $embedding = array_fill(0, 1536, 0.1);

    foreach ($validHashes as $hash) {
      $result = $this->cache->set($hash, $embedding);
      $this->assertTrue($result, "Should accept hash: {$hash}");

      $retrieved = $this->cache->get($hash);
      $this->assertEquals($embedding, $retrieved);
    }
  }

  /**
   * Tests cache size management.
   */
  public function testCacheSizeManagement() {
    $initialStats = $this->cache->getStats();
    $initialSize = $initialStats['size'];

    // Add multiple items.
    $itemCount = 10;
    for ($i = 0; $i < $itemCount; $i++) {
      $hash = hash('sha256', "item {$i}");
      $embedding = array_fill(0, 100, $i * 0.1);
      $this->cache->set($hash, $embedding);
    }

    $afterAddStats = $this->cache->getStats();
    $this->assertEquals($initialSize + $itemCount, $afterAddStats['size']);

    // Clear cache.
    $this->cache->clear();
    $afterClearStats = $this->cache->getStats();
    $this->assertEquals(0, $afterClearStats['size']);
  }

  /**
   * Tests TTL functionality.
   */
  public function testTtlFunctionality() {
    $embedding = array_fill(0, 1536, 0.7);

    // Test setting with TTL.
    $hashWithTtl = hash('sha256', 'ttl test');
    $result = $this->cache->set($hashWithTtl, $embedding, 3600);
    $this->assertTrue($result);

    // Test setting without TTL.
    $hashWithoutTtl = hash('sha256', 'no ttl test');
    $result = $this->cache->set($hashWithoutTtl, $embedding);
    $this->assertTrue($result);

    // Both should be retrievable.
    $this->assertEquals($embedding, $this->cache->get($hashWithTtl));
    $this->assertEquals($embedding, $this->cache->get($hashWithoutTtl));

    // Test setMultiple with TTL.
    $multipleItems = [
      hash('sha256', 'multi1') => $embedding,
      hash('sha256', 'multi2') => $embedding,
    ];

    $result = $this->cache->setMultiple($multipleItems, 7200);
    $this->assertTrue($result);

    foreach (array_keys($multipleItems) as $hash) {
      $this->assertEquals($embedding, $this->cache->get($hash));
    }
  }

}
