<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Cache;

use Drupal\search_api_postgresql\Cache\DatabaseEmbeddingCache;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\MergeInterface;
use Drupal\Core\Database\Query\DeleteInterface;
use Drupal\Core\Database\Schema;
use Psr\Log\LoggerInterface;

/**
 * Tests the DatabaseEmbeddingCache.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Cache\DatabaseEmbeddingCache
 */
class DatabaseEmbeddingCacheTest extends UnitTestCase {

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $connection;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The cache instance under test.
   *
   * @var \Drupal\search_api_postgresql\Cache\DatabaseEmbeddingCache
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
  protected function setUp(): void {
    parent::setUp();

    $this->connection = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    
    $this->config = [
      'table_name' => 'test_embedding_cache',
      'default_ttl' => 3600,
      'max_entries' => 1000,
      'cleanup_probability' => 0.0, // Disable random cleanup for tests
      'enable_compression' => FALSE,
    ];

    // Mock schema to avoid table creation during tests
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $this->connection->method('schema')->willReturn($schema);

    $this->cache = new DatabaseEmbeddingCache($this->connection, $this->logger, $this->config);
  }

  /**
   * Tests cache miss scenario.
   *
   * @covers ::get
   */
  public function testCacheMiss() {
    $hash = str_repeat('a', 64);
    
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $result = $this->cache->get($hash);
    $this->assertNull($result);
  }

  /**
   * Tests cache hit scenario.
   *
   * @covers ::get
   */
  public function testCacheHit() {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];
    $serialized_embedding = serialize($embedding);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'embedding_data' => $serialized_embedding,
      'created' => time() - 100,
      'expires' => time() + 3600,
    ]);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Mock update query for last_accessed
    $update = $this->createMock(\Drupal\Core\Database\Query\UpdateInterface::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);
    $this->connection->method('update')->willReturn($update);

    $result = $this->cache->get($hash);
    $this->assertEquals($embedding, $result);
  }

  /**
   * Tests expired cache entry.
   *
   * @covers ::get
   */
  public function testExpiredCacheEntry() {
    $hash = str_repeat('a', 64);
    
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE); // No results due to expiry condition
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $result = $this->cache->get($hash);
    $this->assertNull($result);
  }

  /**
   * Tests setting cache entry.
   *
   * @covers ::set
   */
  public function testSet() {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    $merge = $this->createMock(MergeInterface::class);
    $merge->method('key')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->method('expression')->willReturnSelf();
    $merge->method('execute')->willReturn(1);
    
    $this->connection->method('merge')->willReturn($merge);

    $result = $this->cache->set($hash, $embedding);
    $this->assertTrue($result);
  }

  /**
   * Tests invalidating cache entry.
   *
   * @covers ::invalidate
   */
  public function testInvalidate() {
    $hash = str_repeat('a', 64);

    $delete = $this->createMock(DeleteInterface::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);
    
    $this->connection->method('delete')->willReturn($delete);

    $result = $this->cache->invalidate($hash);
    $this->assertTrue($result);
  }

  /**
   * Tests getting multiple cache entries.
   *
   * @covers ::getMultiple
   */
  public function testGetMultiple() {
    $hashes = [str_repeat('a', 64), str_repeat('b', 64)];
    $embeddings = [
      [1.0, 2.0, 3.0],
      [4.0, 5.0, 6.0],
    ];

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllKeyed')->willReturn([
      $hashes[0] => serialize($embeddings[0]),
      $hashes[1] => serialize($embeddings[1]),
    ]);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    // Mock update query for last_accessed
    $update = $this->createMock(\Drupal\Core\Database\Query\UpdateInterface::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(2);
    $this->connection->method('update')->willReturn($update);

    $result = $this->cache->getMultiple($hashes);
    
    $this->assertCount(2, $result);
    $this->assertEquals($embeddings[0], $result[$hashes[0]]);
    $this->assertEquals($embeddings[1], $result[$hashes[1]]);
  }

  /**
   * Tests setting multiple cache entries.
   *
   * @covers ::setMultiple
   */
  public function testSetMultiple() {
    $items = [
      str_repeat('a', 64) => [1.0, 2.0, 3.0],
      str_repeat('b', 64) => [4.0, 5.0, 6.0],
    ];

    $merge = $this->createMock(MergeInterface::class);
    $merge->method('key')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->method('expression')->willReturnSelf();
    $merge->method('execute')->willReturn(1);
    
    $this->connection->method('merge')->willReturn($merge);
    $this->connection->method('startTransaction')->willReturn(TRUE);

    $result = $this->cache->setMultiple($items);
    $this->assertTrue($result);
  }

  /**
   * Tests clearing all cache entries.
   *
   * @covers ::clear
   */
  public function testClear() {
    $delete = $this->createMock(DeleteInterface::class);
    $delete->method('execute')->willReturn(5);
    
    $this->connection->method('delete')->willReturn($delete);

    $result = $this->cache->clear();
    $this->assertTrue($result);
  }

  /**
   * Tests getting cache statistics.
   *
   * @covers ::getStats
   */
  public function testGetStats() {
    // Mock main stats query
    $select = $this->createMock(SelectInterface::class);
    $select->method('addExpression')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'total_entries' => 100,
      'total_hits' => 500,
      'avg_dimensions' => 1536,
      'oldest_entry' => time() - 86400,
      'newest_entry' => time(),
    ]);
    
    $select->method('execute')->willReturn($statement);
    
    // Mock expired count query
    $count_select = $this->createMock(SelectInterface::class);
    $count_select->method('condition')->willReturnSelf();
    $count_select->method('countQuery')->willReturnSelf();
    
    $count_statement = $this->createMock(StatementInterface::class);
    $count_statement->method('fetchField')->willReturn(5);
    $count_select->method('execute')->willReturn($count_statement);

    $this->connection->method('select')
      ->willReturnOnConsecutiveCalls($select, $count_select);

    $stats = $this->cache->getStats();
    
    $this->assertIsArray($stats);
    $this->assertEquals(100, $stats['total_entries']);
    $this->assertEquals(5, $stats['expired_entries']);
    $this->assertEquals(1536, $stats['average_dimensions']);
  }

  /**
   * Tests hash validation.
   *
   * @covers ::validateHash
   */
  public function testHashValidation() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid hash format');
    
    // Use reflection to test protected method
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateHash');
    $method->setAccessible(TRUE);
    
    // Test with invalid hash
    $method->invokeArgs($this->cache, ['invalid_hash']);
  }

  /**
   * Tests embedding validation.
   *
   * @covers ::validateEmbedding
   */
  public function testEmbeddingValidation() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Embedding cannot be empty');
    
    // Use reflection to test protected method
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateEmbedding');
    $method->setAccessible(TRUE);
    
    // Test with empty embedding
    $method->invokeArgs($this->cache, [[]]);
  }

  /**
   * Tests embedding with invalid dimensions.
   */
  public function testEmbeddingDimensionsValidation() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Embedding too large');
    
    // Use reflection to test protected method
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateEmbedding');
    $method->setAccessible(TRUE);
    
    // Test with too many dimensions
    $large_embedding = array_fill(0, 20000, 1.0);
    $method->invokeArgs($this->cache, [$large_embedding]);
  }

  /**
   * Tests embedding with non-numeric values.
   */
  public function testEmbeddingNonNumericValidation() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is not numeric');
    
    // Use reflection to test protected method
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateEmbedding');
    $method->setAccessible(TRUE);
    
    // Test with non-numeric values
    $invalid_embedding = [1.0, 'not_a_number', 3.0];
    $method->invokeArgs($this->cache, [$invalid_embedding]);
  }

  /**
   * Tests serialization and unserialization of embeddings.
   */
  public function testEmbeddingSerialization() {
    $embedding = [1.0, 2.5, -3.7, 0.0];
    
    // Use reflection to test protected methods
    $reflection = new \ReflectionClass($this->cache);
    
    $serialize_method = $reflection->getMethod('serializeEmbedding');
    $serialize_method->setAccessible(TRUE);
    
    $unserialize_method = $reflection->getMethod('unserializeEmbedding');
    $unserialize_method->setAccessible(TRUE);
    
    // Test serialization
    $serialized = $serialize_method->invokeArgs($this->cache, [$embedding]);
    $this->assertIsString($serialized);
    
    // Test unserialization
    $unserialized = $unserialize_method->invokeArgs($this->cache, [$serialized]);
    $this->assertEquals($embedding, $unserialized);
  }

}