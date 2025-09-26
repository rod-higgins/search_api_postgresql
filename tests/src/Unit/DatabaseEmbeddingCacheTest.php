<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\search_api_postgresql\Cache\DatabaseEmbeddingCache;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for the DatabaseEmbeddingCache.
 *
 * @group search_api_postgresql
 */
class DatabaseEmbeddingCacheTest extends TestCase
{
  /**
   * Real database connection implementation for testing.
   */
  protected $connection;

  /**
   * Real logger implementation for testing.
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
   * In-memory storage for database operations.
   */
  protected $storage = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // Load actual cache classes.
    require_once __DIR__ . '/../../../../../src/Cache/EmbeddingCacheInterface.php';
    require_once __DIR__ . '/../../../../../src/Cache/DatabaseEmbeddingCache.php';

    // Define PSR LoggerInterface if not available.
    if (!interface_exists('Psr\Log\LoggerInterface')) {
      eval('
      namespace Psr\Log {
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
      }
      ');
    }

    // Create real logger implementation.
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

    // Create real database connection implementation for testing.
    $storage = &$this->storage;
    $this->connection = new class ($storage) {
      private $storage;

      public function __construct(&$storage)
      {
        $this->storage = &$storage;
      }

      /**
       * {@inheritdoc}
       */
      public function select($table, $alias = null, array $options = [])
      {
        return new class ($this->storage, $table) {
          private $storage;
          private $table;
          private $conditions = [];
          private $fields = [];
          private $limit = null;

          public function __construct(&$storage, $table)
          {
            $this->storage = &$storage;
            $this->table = $table;
          }

          /**
           * {@inheritdoc}
           */
          public function fields($table_alias, array $fields = [])
          {
            $this->fields = $fields;
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function condition($field, $value = null, $operator = '=')
          {
            $this->conditions[] = [$field, $value, $operator];
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function range($start = null, $length = null)
          {
            $this->limit = $length;
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function addExpression($expression, $alias = null, $arguments = [])
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function execute()
          {
            return new class ($this->storage, $this->table, $this->conditions, $this->limit) {
              private $storage;
              private $table;
              private $conditions;
              private $limit;

              public function __construct(&$storage, $table, $conditions, $limit)
              {
                $this->storage = &$storage;
                $this->table = $table;
                $this->conditions = $conditions;
                $this->limit = $limit;
              }

              /**
               * {@inheritdoc}
               */
              public function fetchAssoc()
              {
                if (!isset($this->storage[$this->table])) {
                  return false;
                }

                foreach ($this->storage[$this->table] as $row) {
                  $match = true;
                  foreach ($this->conditions as [$field, $value, $operator]) {
                    if ($operator === '=' && $row[$field] !== $value) {
                      $match = false;
                      break;
                    }
                  }
                  if ($match) {
                    return $row;
                  }
                }
                return false;
              }

              /**
               * {@inheritdoc}
               */
              public function fetchAllKeyed()
              {
                $results = [];
                if (!isset($this->storage[$this->table])) {
                  return $results;
                }

                foreach ($this->storage[$this->table] as $row) {
                  $match = true;
                  foreach ($this->conditions as [$field, $value, $operator]) {
                    if ($operator === 'IN' && !in_array($row[$field], $value)) {
                      $match = false;
                      break;
                    }
                  }
                  if ($match) {
                    $results[$row['hash']] = $row['embedding_data'];
                  }
                }
                return $results;
              }

              /**
               * {@inheritdoc}
               */
              public function fetchField()
              {
                if (!isset($this->storage[$this->table])) {
                  return 0;
                }

                $count = 0;
                foreach ($this->storage[$this->table] as $row) {
                  $match = true;
                  foreach ($this->conditions as [$field, $value, $operator]) {
                    if ($operator === '<' && $row[$field] >= $value) {
                      $match = false;
                      break;
                    }
                  }
                  if ($match) {
                    $count++;
                  }
                }
                return $count;
              }

            };
          }

          /**
           * {@inheritdoc}
           */
          public function countQuery()
          {
            return $this;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function merge($table, array $options = [])
      {
        return new class ($this->storage, $table) {
          private $storage;
          private $table;
          private $key_field;
          private $fields_data = [];

          public function __construct(&$storage, $table)
          {
            $this->storage = &$storage;
            $this->table = $table;
          }

          /**
           * {@inheritdoc}
           */
          public function key(array $key)
          {
            $this->key_field = array_keys($key)[0];
            $this->fields_data = array_merge($this->fields_data, $key);
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function fields(array $fields)
          {
            $this->fields_data = array_merge($this->fields_data, $fields);
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function expression($field, $expression)
          {
            $this->fields_data[$field] = time();
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function execute()
          {
            if (!isset($this->storage[$this->table])) {
              $this->storage[$this->table] = [];
            }

            $this->storage[$this->table][] = $this->fields_data;
            return 1;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function update($table, array $options = [])
      {
        return new class ($this->storage, $table) {
          private $storage;
          private $table;
          private $conditions = [];
          private $fields_data = [];

          public function __construct(&$storage, $table)
          {
            $this->storage = &$storage;
            $this->table = $table;
          }

          /**
           * {@inheritdoc}
           */
          public function fields(array $fields)
          {
            $this->fields_data = $fields;
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function condition($field, $value = null, $operator = '=')
          {
            $this->conditions[] = [$field, $value, $operator];
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function execute()
          {
            if (!isset($this->storage[$this->table])) {
              return 0;
            }

            $updated = 0;
            foreach ($this->storage[$this->table] as &$row) {
              $match = true;
              foreach ($this->conditions as [$field, $value, $operator]) {
                if ($operator === '=' && $row[$field] !== $value) {
                  $match = false;
                  break;
                }
                if ($operator === 'IN' && !in_array($row[$field], $value)) {
                  $match = false;
                  break;
                }
              }
              if ($match) {
                $row = array_merge($row, $this->fields_data);
                $updated++;
              }
            }
            return $updated;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function delete($table, array $options = [])
      {
        return new class ($this->storage, $table) {
          private $storage;
          private $table;
          private $conditions = [];

          public function __construct(&$storage, $table)
          {
            $this->storage = &$storage;
            $this->table = $table;
          }

          /**
           * {@inheritdoc}
           */
          public function condition($field, $value = null, $operator = '=')
          {
            $this->conditions[] = [$field, $value, $operator];
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function execute()
          {
            if (!isset($this->storage[$this->table])) {
              return 0;
            }

            $deleted = 0;
            if (empty($this->conditions)) {
              $deleted = count($this->storage[$this->table]);
              $this->storage[$this->table] = [];
            } else {
              $this->storage[$this->table] = array_filter(
                  $this->storage[$this->table],
                  function ($row) use (&$deleted) {
                    $match = true;
                    foreach ($this->conditions as [$field, $value, $operator]) {
                      if ($operator === '=' && $row[$field] !== $value) {
                        $match = false;
                        break;
                      }
                    }
                    if ($match) {
                                $deleted++;
                    }
                                        return !$match;
                  }
              );
            }
            return $deleted;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function startTransaction($name = '')
      {
        return true;
      }

      /**
       * {@inheritdoc}
       */
      public function schema()
      {
        return new class {

          /**
           * {@inheritdoc}
           */
          public function tableExists($table)
          {
            return true;
          }

          /**
           * {@inheritdoc}
           */
          public function createTable($table, $spec)
          {
            return true;
          }

        };
      }

    };

    $this->config = [
      'table_name' => 'test_embedding_cache',
      'default_ttl' => 3600,
      'max_entries' => 1000,
    // Disable random cleanup for deterministic tests.
      'cleanup_probability' => 0.0,
      'enable_compression' => false,
    ];

    $this->cache = new DatabaseEmbeddingCache($this->connection, $this->logger, $this->config);
  }

  /**
   * Tests cache miss scenario with real implementation.
   */
  public function testCacheMiss()
  {
    $hash = str_repeat('a', 64);

    // No data in storage - should return null.
    $result = $this->cache->get($hash);
    $this->assertNull($result);
  }

  /**
   * Tests cache hit scenario with real implementation.
   */
  public function testCacheHit()
  {
    $hash = str_repeat('a', 64);
    $embedding = [1.0, 2.0, 3.0];

    // First set the data, then retrieve it.
    $this->assertTrue($this->cache->set($hash, $embedding));
    $result = $this->cache->get($hash);
    $this->assertEquals($embedding, $result);
  }

  /**
   * Tests expired cache entry.
   *
   * @covers ::get
   */
  public function testExpiredCacheEntry()
  {
    $hash = str_repeat('a', 64);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    // No results due to expiry condition.
    $statement->method('fetchAssoc')->willReturn(false);

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
  public function testSet()
  {
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
  public function testInvalidate()
  {
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
  public function testGetMultiple()
  {
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

    // Mock update query for last_accessed.
    $update = $this->createMock(UpdateInterface::class);
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
  public function testSetMultiple()
  {
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
    $this->connection->method('startTransaction')->willReturn(true);

    $result = $this->cache->setMultiple($items);
    $this->assertTrue($result);
  }

  /**
   * Tests clearing all cache entries.
   *
   * @covers ::clear
   */
  public function testClear()
  {
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
  public function testGetStats()
  {
    // Mock main stats query.
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

    // Mock expired count query.
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
   *
   * @covers ::validateEmbedding
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
   * Tests embedding with invalid dimensions.
   */
  public function testEmbeddingDimensionsValidation()
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Embedding too large');

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateEmbedding');
    $method->setAccessible(true);

    // Test with too many dimensions.
    $large_embedding = array_fill(0, 20000, 1.0);
    $method->invokeArgs($this->cache, [$large_embedding]);
  }

  /**
   * Tests embedding with non-numeric values.
   */
  public function testEmbeddingNonNumericValidation()
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is not numeric');

    // Use reflection to test protected method.
    $reflection = new \ReflectionClass($this->cache);
    $method = $reflection->getMethod('validateEmbedding');
    $method->setAccessible(true);

    // Test with non-numeric values.
    $invalid_embedding = [1.0, 'not_a_number', 3.0];
    $method->invokeArgs($this->cache, [$invalid_embedding]);
  }

  /**
   * Tests serialization and unserialization of embeddings.
   */
  public function testEmbeddingSerialization()
  {
    $embedding = [1.0, 2.5, -3.7, 0.0];

    // Use reflection to test protected methods.
    $reflection = new \ReflectionClass($this->cache);

    $serialize_method = $reflection->getMethod('serializeEmbedding');
    $serialize_method->setAccessible(true);

    $unserialize_method = $reflection->getMethod('unserializeEmbedding');
    $unserialize_method->setAccessible(true);

    // Test serialization.
    $serialized = $serialize_method->invokeArgs($this->cache, [$embedding]);
    $this->assertIsString($serialized);

    // Test unserialization.
    $unserialized = $unserialize_method->invokeArgs($this->cache, [$serialized]);
    $this->assertEquals($embedding, $unserialized);
  }
}
