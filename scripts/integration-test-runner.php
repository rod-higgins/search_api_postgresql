<?php

/**
 * @file
 * Integration test runner that executes tests without PHPUnit.
 */

// Create a minimal test framework
class SimpleTestCase {
  protected $assertions = 0;
  protected $failures = 0;

  protected function setUp(): void {}

  protected function assertEquals($expected, $actual, $message = '') {
    $this->assertions++;
    if ($expected !== $actual) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true));
    }
    echo '.';
  }

  protected function assertTrue($condition, $message = '') {
    $this->assertions++;
    if (!$condition) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected true, got: " . var_export($condition, true));
    }
    echo '.';
  }

  protected function assertFalse($condition, $message = '') {
    $this->assertions++;
    if ($condition) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected false, got: " . var_export($condition, true));
    }
    echo '.';
  }

  protected function assertNull($value, $message = '') {
    $this->assertions++;
    if ($value !== null) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected null, got: " . var_export($value, true));
    }
    echo '.';
  }

  protected function assertNotNull($value, $message = '') {
    $this->assertions++;
    if ($value === null) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected not null, got null");
    }
    echo '.';
  }

  protected function assertIsArray($value, $message = '') {
    $this->assertions++;
    if (!is_array($value)) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected array, got: " . gettype($value));
    }
    echo '.';
  }

  protected function assertCount($expectedCount, $haystack, $message = '') {
    $this->assertions++;
    $actualCount = count($haystack);
    if ($actualCount !== $expectedCount) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected count: $expectedCount, got: $actualCount");
    }
    echo '.';
  }

  protected function assertArrayHasKey($key, $array, $message = '') {
    $this->assertions++;
    if (!array_key_exists($key, $array)) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Key '$key' not found in array");
    }
    echo '.';
  }

  protected function assertGreaterThanOrEqual($expected, $actual, $message = '') {
    $this->assertions++;
    if ($actual < $expected) {
      $this->failures++;
      throw new Exception("Assertion failed: $message. Expected >= $expected, got: $actual");
    }
    echo '.';
  }

  protected function expectException($exceptionClass) {
    // Store expected exception for later checking
    $this->expectedException = $exceptionClass;
  }
}

// Create PSR Logger interface if not exists
if (!interface_exists('Psr\Log\LoggerInterface')) {
  interface LoggerInterface {
    public function emergency($message, array $context = []);
    public function alert($message, array $context = []);
    public function critical($message, array $context = []);
    public function error($message, array $context = []);
    public function warning($message, array $context = []);
    public function notice($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
    public function log($level, $message, array $context = []);
  }
  class_alias('LoggerInterface', 'Psr\Log\LoggerInterface');
}

// Load the actual cache implementation
require_once __DIR__ . '/../src/Cache/EmbeddingCacheInterface.php';
require_once __DIR__ . '/../src/Cache/MemoryEmbeddingCache.php';

// Create integration test class
class MemoryEmbeddingCacheIntegrationTest extends SimpleTestCase {

  protected $cache;
  protected $logger;

  protected function setUp(): void {
    parent::setUp();

    // Create a simple logger
    $this->logger = new class implements Psr\Log\LoggerInterface {
      public function emergency($message, array $context = []) {}
      public function alert($message, array $context = []) {}
      public function critical($message, array $context = []) {}
      public function error($message, array $context = []) {}
      public function warning($message, array $context = []) {}
      public function notice($message, array $context = []) {}
      public function info($message, array $context = []) {}
      public function debug($message, array $context = []) {}
      public function log($level, $message, array $context = []) {}
    };

    // Create cache instance
    $config = [
      'max_entries' => 10,
      'default_ttl' => 3600,
      'cleanup_probability' => 0.0,
    ];

    $this->cache = new Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache($this->logger, $config);
  }

  public function testBasicCacheOperations() {
    echo "\nTesting basic cache operations... ";

    // Test cache miss with proper hash format
    $nonexistentHash = str_repeat('0', 64);
    $result = $this->cache->get($nonexistentHash);
    $this->assertNull($result);

    // Test cache set and get with proper 64-char hex hash
    $hash = hash('sha256', 'test_hash_' . time());
    $embedding = [1.0, 2.0, 3.0, 4.0];

    $this->assertTrue($this->cache->set($hash, $embedding));
    $retrieved = $this->cache->get($hash);
    $this->assertEquals($embedding, $retrieved);

    echo " PASSED";
  }

  public function testCacheInvalidation() {
    echo "\nTesting cache invalidation... ";

    $hash = hash('sha256', 'invalidation_test');
    $embedding = [5.0, 6.0, 7.0];

    // Set and verify
    $this->cache->set($hash, $embedding);
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Invalidate and verify
    $this->assertTrue($this->cache->invalidate($hash));
    $this->assertNull($this->cache->get($hash));

    echo " PASSED";
  }

  public function testMultipleCacheOperations() {
    echo "\nTesting multiple cache operations... ";

    $items = [
      hash('sha256', 'hash1') => [1.0, 2.0],
      hash('sha256', 'hash2') => [3.0, 4.0],
      hash('sha256', 'hash3') => [5.0, 6.0],
    ];

    // Set multiple
    $this->assertTrue($this->cache->setMultiple($items));

    // Get multiple
    $result = $this->cache->getMultiple(array_keys($items));

    $this->assertCount(3, $result);
    $hash1 = hash('sha256', 'hash1');
    $hash2 = hash('sha256', 'hash2');
    $hash3 = hash('sha256', 'hash3');
    $this->assertEquals($items[$hash1], $result[$hash1]);
    $this->assertEquals($items[$hash2], $result[$hash2]);
    $this->assertEquals($items[$hash3], $result[$hash3]);

    echo " PASSED";
  }

  public function testCacheStatistics() {
    echo "\nTesting cache statistics... ";

    // Perform operations to generate stats
    $hash1 = hash('sha256', 'stats1');
    $hash2 = hash('sha256', 'stats2');
    $nonexistentHash = hash('sha256', 'nonexistent');

    $this->cache->set($hash1, [1.0]);
    $this->cache->set($hash2, [2.0]);
    $this->cache->get($hash1); // Hit
    $this->cache->get($nonexistentHash); // Miss

    $stats = $this->cache->getStats();

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('hits', $stats);
    $this->assertArrayHasKey('misses', $stats);
    $this->assertArrayHasKey('sets', $stats);
    $this->assertGreaterThanOrEqual(1, $stats['hits']);
    $this->assertGreaterThanOrEqual(1, $stats['misses']);
    $this->assertGreaterThanOrEqual(2, $stats['sets']);

    echo " PASSED";
  }

  public function testCacheExpiration() {
    echo "\nTesting cache expiration... ";

    $hash = hash('sha256', 'expiring_item');
    $embedding = [1.0, 2.0, 3.0];

    // Set with very short TTL
    $this->assertTrue($this->cache->set($hash, $embedding, 1));

    // Should be available immediately
    $this->assertEquals($embedding, $this->cache->get($hash));

    // Wait for expiration
    sleep(2);

    // Should be null now
    $this->assertNull($this->cache->get($hash));

    echo " PASSED";
  }

  public function testCacheSizeLimits() {
    echo "\nTesting cache size limits... ";

    // Create cache with small limit
    $smallCache = new Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache(
      $this->logger,
      ['max_entries' => 2, 'cleanup_threshold' => 0.1]  // Trigger cleanup early
    );

    // Add first two items
    $hash1 = hash('sha256', 'item1');
    $hash2 = hash('sha256', 'item2');
    $this->assertTrue($smallCache->set($hash1, [1.0]));
    $this->assertTrue($smallCache->set($hash2, [2.0]));

    // Access hash1 to make it more recently used than hash2
    $smallCache->get($hash1);

    // Add third item which should trigger cleanup and evict hash2 (least recently used)
    $hash3 = hash('sha256', 'item3');
    $this->assertTrue($smallCache->set($hash3, [3.0]));

    // hash3 should exist (just added)
    $this->assertNotNull($smallCache->get($hash3));

    // hash1 should exist (more recently accessed)
    $this->assertNotNull($smallCache->get($hash1));

    // hash2 should be evicted (least recently used)
    $this->assertNull($smallCache->get($hash2));

    echo " PASSED";
  }

  public function testCacheMaintenance() {
    echo "\nTesting cache maintenance... ";

    $hash1 = hash('sha256', 'maint1');
    $hash2 = hash('sha256', 'maint2');

    // Add items with different TTLs
    $this->cache->set($hash1, [1.0], 1); // Short TTL
    $this->cache->set($hash2, [2.0], 3600); // Long TTL

    // Wait for first to expire
    sleep(2);

    // Run maintenance
    $result = $this->cache->maintenance();
    $this->assertTrue($result);

    // Expired item should be cleaned up
    $this->assertNull($this->cache->get($hash1));
    // Non-expired should remain
    $this->assertNotNull($this->cache->get($hash2));

    echo " PASSED";
  }

  public function testHashValidation() {
    echo "\nTesting hash validation... ";

    // Valid hash should work
    $validHash = str_repeat('a', 64);
    $this->assertTrue($this->cache->set($validHash, [1.0]));

    // Test various invalid hashes that should throw exceptions
    $invalidHashes = [
      'too_short',
      str_repeat('g', 64), // Non-hex character
      str_repeat('a', 63), // Too short by 1
      str_repeat('a', 65), // Too long by 1
    ];

    $exceptionCount = 0;
    foreach ($invalidHashes as $invalidHash) {
      try {
        $this->cache->set($invalidHash, [1.0]);
      } catch (\InvalidArgumentException $e) {
        $exceptionCount++;
      }
    }

    // Should have thrown exception for all invalid hashes
    $this->assertEquals(count($invalidHashes), $exceptionCount);

    // Test empty hash separately - it returns false instead of throwing
    $result = $this->cache->set('', [1.0]);
    $this->assertFalse($result);

    echo " PASSED";
  }

  public function testEmbeddingValidation() {
    echo "\nTesting embedding validation... ";

    $hash = hash('sha256', 'embedding_test');

    // Valid embedding should work
    $this->assertTrue($this->cache->set($hash, [1.0, 2.0, 3.0]));

    // Test invalid embeddings that should throw exceptions
    $invalidEmbeddings = [
      ['not', 'numeric'], // Non-numeric values
      array_fill(0, 16001, 1.0), // Too large
    ];

    $exceptionCount = 0;
    foreach ($invalidEmbeddings as $invalidEmbedding) {
      try {
        $this->cache->set($hash, $invalidEmbedding);
      } catch (\InvalidArgumentException $e) {
        $exceptionCount++;
      }
    }

    // Should have thrown exception for all invalid embeddings
    $this->assertEquals(count($invalidEmbeddings), $exceptionCount);

    // Test empty embedding separately - it returns false instead of throwing
    $result = $this->cache->set($hash, []);
    $this->assertFalse($result);

    echo " PASSED";
  }

  public function runAllTests() {
    echo "Memory Embedding Cache Integration Tests\n";
    echo "========================================\n";

    $testMethods = [
      'testBasicCacheOperations',
      'testCacheInvalidation',
      'testMultipleCacheOperations',
      'testCacheStatistics',
      'testCacheExpiration',
      'testCacheSizeLimits',
      'testCacheMaintenance',
      'testHashValidation',
      'testEmbeddingValidation',
    ];

    $passed = 0;
    $failed = 0;

    foreach ($testMethods as $method) {
      try {
        $this->setUp();
        $this->$method();
        $passed++;
      } catch (Exception $e) {
        echo " FAILED: " . $e->getMessage();
        $failed++;
      }
    }

    echo "\n\nResults:\n";
    echo "--------\n";
    echo "Tests run: " . ($passed + $failed) . "\n";
    echo "Passed: $passed\n";
    echo "Failed: $failed\n";
    echo "Assertions: {$this->assertions}\n";

    return $failed === 0;
  }
}

// Run the tests
$test = new MemoryEmbeddingCacheIntegrationTest();
$success = $test->runAllTests();

exit($success ? 0 : 1);