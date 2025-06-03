<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Service\VectorIndexManager;
use Drupal\search_api_postgresql\Exception\VectorIndexCorruptedException;
use Drupal\search_api_postgresql\Exception\DatabaseConnectionException;
use Drupal\search_api_postgresql\Exception\MemoryExhaustedException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\InsertInterface;
use Drupal\Core\Database\Query\UpdateInterface;
use Drupal\Core\Database\Query\DeleteInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the Vector Index Manager.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Service\VectorIndexManager
 */
class VectorIndexManagerTest extends UnitTestCase {

  /**
   * The database connection mock.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $connection;

  /**
   * The schema mock.
   *
   * @var \Drupal\Core\Database\Schema|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $schema;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The search index mock.
   *
   * @var \Drupal\search_api\IndexInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $index;

  /**
   * The vector index manager under test.
   *
   * @var \Drupal\search_api_postgresql\Service\VectorIndexManager
   */
  protected $vectorIndexManager;

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
    $this->schema = $this->createMock(Schema::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->index = $this->createMock(IndexInterface::class);

    $this->connection->method('schema')->willReturn($this->schema);

    $this->config = [
      'vector_dimension' => 1536,
      'distance_metric' => 'cosine',
      'index_type' => 'ivfflat',
      'lists' => 100,
      'probes' => 10,
      'ef_construction' => 64,
      'max_connections' => 16,
      'batch_size' => 1000,
      'maintenance_threshold' => 0.1,
    ];

    $this->index->method('id')->willReturn('test_index');
    $this->index->method('getOption')->willReturnMap([
      ['index_prefix', 'search_api_'],
    ]);

    $this->vectorIndexManager = new VectorIndexManager(
      $this->connection,
      $this->logger,
      $this->config
    );
  }

  /**
   * Tests vector index creation with different configurations.
   *
   * @covers ::createVectorIndex
   */
  public function testVectorIndexCreation() {
    $table_name = 'search_api_test_index_vectors';

    // Mock table existence check
    $this->schema->expects($this->once())
      ->method('tableExists')
      ->with($table_name)
      ->willReturn(FALSE);

    // Mock successful table creation
    $this->schema->expects($this->once())
      ->method('createTable')
      ->with($table_name, $this->isType('array'));

    // Mock index creation queries
    $this->connection->expects($this->atLeastOnce())
      ->method('query')
      ->willReturn(TRUE);

    $result = $this->vectorIndexManager->createVectorIndex($this->index);
    $this->assertTrue($result);
  }

  /**
   * Tests vector index creation when table already exists.
   *
   * @covers ::createVectorIndex
   */
  public function testVectorIndexCreationTableExists() {
    $table_name = 'search_api_test_index_vectors';

    // Mock table existence check
    $this->schema->expects($this->once())
      ->method('tableExists')
      ->with($table_name)
      ->willReturn(TRUE);

    // Should not attempt to create table
    $this->schema->expects($this->never())
      ->method('createTable');

    $result = $this->vectorIndexManager->createVectorIndex($this->index);
    $this->assertTrue($result);
  }

  /**
   * Tests vector insertion in batches.
   *
   * @covers ::insertVectors
   */
  public function testVectorInsertion() {
    $vectors = [
      'item_1' => [
        'item_id' => 'item_1',
        'embedding' => array_fill(0, 1536, 0.1),
        'text_content' => 'First test document',
      ],
      'item_2' => [
        'item_id' => 'item_2', 
        'embedding' => array_fill(0, 1536, 0.2),
        'text_content' => 'Second test document',
      ],
    ];

    // Mock insert query
    $insert = $this->createMock(InsertInterface::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('values')->willReturnSelf();
    $insert->method('execute')->willReturn(2);

    $this->connection->expects($this->once())
      ->method('insert')
      ->willReturn($insert);

    $result = $this->vectorIndexManager->insertVectors($this->index, $vectors);
    $this->assertEquals(2, $result);
  }

  /**
   * Tests large batch vector insertion with chunking.
   *
   * @covers ::insertVectors
   */
  public function testLargeBatchVectorInsertion() {
    // Create vectors exceeding batch size
    $vectors = [];
    for ($i = 0; $i < 2500; $i++) {
      $vectors["item_$i"] = [
        'item_id' => "item_$i",
        'embedding' => array_fill(0, 1536, $i * 0.001),
        'text_content' => "Test document $i",
      ];
    }

    // Mock multiple insert queries (should be chunked)
    $insert = $this->createMock(InsertInterface::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('values')->willReturnSelf();
    $insert->method('execute')->willReturn(1000, 1000, 500); // 3 batches

    $this->connection->expects($this->exactly(3))
      ->method('insert')
      ->willReturn($insert);

    $result = $this->vectorIndexManager->insertVectors($this->index, $vectors);
    $this->assertEquals(2500, $result);
  }

  /**
   * Tests vector similarity search.
   *
   * @covers ::searchSimilarVectors
   */
  public function testVectorSimilaritySearch() {
    $query_vector = array_fill(0, 1536, 0.5);
    $limit = 10;
    $threshold = 0.8;

    $mock_results = [
      [
        'item_id' => 'item_1',
        'similarity' => 0.95,
        'text_content' => 'Highly relevant document',
      ],
      [
        'item_id' => 'item_2', 
        'similarity' => 0.87,
        'text_content' => 'Somewhat relevant document',
      ],
    ];

    // Mock select query
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($mock_results);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $results = $this->vectorIndexManager->searchSimilarVectors(
      $this->index,
      $query_vector,
      $limit,
      $threshold
    );

    $this->assertCount(2, $results);
    $this->assertEquals('item_1', $results[0]['item_id']);
    $this->assertEquals(0.95, $results[0]['similarity']);
  }

  /**
   * Tests vector updating.
   *
   * @covers ::updateVector
   */
  public function testVectorUpdating() {
    $item_id = 'item_1';
    $new_embedding = array_fill(0, 1536, 0.8);
    $new_content = 'Updated document content';

    // Mock update query
    $update = $this->createMock(UpdateInterface::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $this->connection->expects($this->once())
      ->method('update')
      ->willReturn($update);

    $result = $this->vectorIndexManager->updateVector(
      $this->index,
      $item_id,
      $new_embedding,
      $new_content
    );

    $this->assertTrue($result);
  }

  /**
   * Tests vector deletion.
   *
   * @covers ::deleteVector
   */
  public function testVectorDeletion() {
    $item_id = 'item_1';

    // Mock delete query
    $delete = $this->createMock(DeleteInterface::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    $this->connection->expects($this->once())
      ->method('delete')
      ->willReturn($delete);

    $result = $this->vectorIndexManager->deleteVector($this->index, $item_id);
    $this->assertTrue($result);
  }

  /**
   * Tests bulk vector deletion.
   *
   * @covers ::deleteVectors
   */
  public function testBulkVectorDeletion() {
    $item_ids = ['item_1', 'item_2', 'item_3'];

    // Mock delete query
    $delete = $this->createMock(DeleteInterface::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(3);

    $this->connection->expects($this->once())
      ->method('delete')
      ->willReturn($delete);

    $result = $this->vectorIndexManager->deleteVectors($this->index, $item_ids);
    $this->assertEquals(3, $result);
  }

  /**
   * Tests index optimization.
   *
   * @covers ::optimizeIndex
   */
  public function testIndexOptimization() {
    $table_name = 'search_api_test_index_vectors';

    // Mock optimization queries
    $this->connection->expects($this->atLeastOnce())
      ->method('query')
      ->willReturn(TRUE);

    $result = $this->vectorIndexManager->optimizeIndex($this->index);
    $this->assertTrue($result);
  }

  /**
   * Tests index statistics collection.
   *
   * @covers ::getIndexStatistics
   */
  public function testIndexStatisticsCollection() {
    $mock_stats = [
      'total_vectors' => 10000,
      'avg_similarity' => 0.75,
      'index_size_mb' => 256,
      'last_optimized' => time() - 86400,
    ];

    // Mock statistics queries
    $select = $this->createMock(SelectInterface::class);
    $select->method('addExpression')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($mock_stats);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $stats = $this->vectorIndexManager->getIndexStatistics($this->index);

    $this->assertIsArray($stats);
    $this->assertEquals(10000, $stats['total_vectors']);
    $this->assertEquals(256, $stats['index_size_mb']);
  }

  /**
   * Tests index health checking.
   *
   * @covers ::checkIndexHealth
   */
  public function testIndexHealthChecking() {
    // Mock health check queries
    $select = $this->createMock(SelectInterface::class);
    $select->method('addExpression')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturnOnConsecutiveCalls(
      10000, // total vectors
      50,    // corrupted vectors
      5      // orphaned vectors
    );
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $health = $this->vectorIndexManager->checkIndexHealth($this->index);

    $this->assertIsArray($health);
    $this->assertArrayHasKey('status', $health);
    $this->assertArrayHasKey('issues', $health);
    $this->assertArrayHasKey('recommendations', $health);
  }

  /**
   * Tests corrupted index detection and handling.
   *
   * @covers ::checkIndexHealth
   */
  public function testCorruptedIndexDetection() {
    // Mock corrupted index scenario
    $select = $this->createMock(SelectInterface::class);
    $select->method('addExpression')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturnOnConsecutiveCalls(
      10000, // total vectors
      2000,  // corrupted vectors (20% - high corruption)
      500    // orphaned vectors
    );
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $health = $this->vectorIndexManager->checkIndexHealth($this->index);

    $this->assertEquals('critical', $health['status']);
    $this->assertContains('High corruption detected', $health['issues']);
    $this->assertContains('Rebuild index immediately', $health['recommendations']);
  }

  /**
   * Tests index rebuilding process.
   *
   * @covers ::rebuildIndex
   */
  public function testIndexRebuilding() {
    $table_name = 'search_api_test_index_vectors';

    // Mock table operations for rebuild
    $this->schema->expects($this->once())
      ->method('dropTable')
      ->with($table_name);

    $this->schema->expects($this->once())
      ->method('createTable')
      ->with($table_name, $this->isType('array'));

    // Mock index recreation
    $this->connection->expects($this->atLeastOnce())
      ->method('query')
      ->willReturn(TRUE);

    $result = $this->vectorIndexManager->rebuildIndex($this->index);
    $this->assertTrue($result);
  }

  /**
   * Tests memory exhaustion during large operations.
   *
   * @covers ::insertVectors
   */
  public function testMemoryExhaustionHandling() {
    // Create very large vector set to trigger memory issues
    $vectors = [];
    for ($i = 0; $i < 50000; $i++) {
      $vectors["item_$i"] = [
        'item_id' => "item_$i",
        'embedding' => array_fill(0, 1536, $i * 0.001),
        'text_content' => str_repeat("Large content $i ", 1000),
      ];
    }

    // Mock memory exhaustion
    $insert = $this->createMock(InsertInterface::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('values')->willReturnSelf();
    $insert->method('execute')->willThrowException(
      new \Exception('Fatal error: Allowed memory size exhausted')
    );

    $this->connection->method('insert')->willReturn($insert);

    $this->expectException(MemoryExhaustedException::class);

    $this->vectorIndexManager->insertVectors($this->index, $vectors);
  }

  /**
   * Tests database connection failure handling.
   *
   * @covers ::searchSimilarVectors
   */
  public function testDatabaseConnectionFailure() {
    $query_vector = array_fill(0, 1536, 0.5);

    // Mock database connection failure
    $this->connection->method('select')
      ->willThrowException(new \Exception('Connection refused'));

    $this->expectException(DatabaseConnectionException::class);

    $this->vectorIndexManager->searchSimilarVectors($this->index, $query_vector);
  }

  /**
   * Tests vector dimension validation.
   *
   * @covers ::validateVectorDimensions
   */
  public function testVectorDimensionValidation() {
    // Use reflection to access protected method
    $reflection = new \ReflectionClass($this->vectorIndexManager);
    $method = $reflection->getMethod('validateVectorDimensions');
    $method->setAccessible(TRUE);

    // Test valid dimensions
    $valid_vector = array_fill(0, 1536, 0.1);
    $this->assertTrue($method->invokeArgs($this->vectorIndexManager, [$valid_vector]));

    // Test invalid dimensions
    $invalid_vector = array_fill(0, 512, 0.1);
    
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid vector dimensions');
    $method->invokeArgs($this->vectorIndexManager, [$invalid_vector]);
  }

  /**
   * Tests distance metric calculations.
   *
   * @covers ::calculateDistance
   */
  public function testDistanceMetricCalculations() {
    // Use reflection to access protected method
    $reflection = new \ReflectionClass($this->vectorIndexManager);
    $method = $reflection->getMethod('calculateDistance');
    $method->setAccessible(TRUE);

    $vector1 = [1.0, 0.0, 0.0];
    $vector2 = [0.0, 1.0, 0.0];

    // Test cosine distance
    $cosine_distance = $method->invokeArgs($this->vectorIndexManager, [$vector1, $vector2, 'cosine']);
    $this->assertEquals(1.0, $cosine_distance, '', 0.001); // 90 degree vectors

    // Test euclidean distance  
    $euclidean_distance = $method->invokeArgs($this->vectorIndexManager, [$vector1, $vector2, 'euclidean']);
    $this->assertEquals(sqrt(2), $euclidean_distance, '', 0.001);

    // Test dot product
    $dot_product = $method->invokeArgs($this->vectorIndexManager, [$vector1, $vector2, 'dot_product']);
    $this->assertEquals(0.0, $dot_product, '', 0.001);
  }

  /**
   * Tests index configuration validation.
   *
   * @covers ::validateIndexConfiguration
   */
  public function testIndexConfigurationValidation() {
    // Use reflection to access protected method
    $reflection = new \ReflectionClass($this->vectorIndexManager);
    $method = $reflection->getMethod('validateIndexConfiguration');
    $method->setAccessible(TRUE);

    // Test valid configuration
    $valid_config = [
      'vector_dimension' => 1536,
      'distance_metric' => 'cosine',
      'index_type' => 'ivfflat',
    ];
    $this->assertTrue($method->invokeArgs($this->vectorIndexManager, [$valid_config]));

    // Test invalid distance metric
    $invalid_config = [
      'vector_dimension' => 1536,
      'distance_metric' => 'invalid_metric',
      'index_type' => 'ivfflat',
    ];
    
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unsupported distance metric');
    $method->invokeArgs($this->vectorIndexManager, [$invalid_config]);
  }

  /**
   * Tests concurrent access handling.
   *
   * @covers ::insertVectors
   */
  public function testConcurrentAccessHandling() {
    $vectors = [
      'item_1' => [
        'item_id' => 'item_1',
        'embedding' => array_fill(0, 1536, 0.1),
        'text_content' => 'Test document',
      ],
    ];

    // Mock deadlock scenario
    $insert = $this->createMock(InsertInterface::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('values')->willReturnSelf();
    $insert->method('execute')
      ->will($this->onConsecutiveCalls(
        $this->throwException(new \Exception('Deadlock found when trying to get lock')),
        1 // Success on retry
      ));

    $this->connection->expects($this->exactly(2))
      ->method('insert')
      ->willReturn($insert);

    // Should retry and succeed
    $result = $this->vectorIndexManager->insertVectors($this->index, $vectors);
    $this->assertEquals(1, $result);
  }

  /**
   * Tests performance monitoring.
   *
   * @covers ::getPerformanceMetrics
   */
  public function testPerformanceMonitoring() {
    $mock_metrics = [
      'avg_insert_time' => 0.05,
      'avg_search_time' => 0.02,
      'cache_hit_rate' => 0.85,
      'index_fragmentation' => 0.15,
    ];

    // Mock performance query
    $select = $this->createMock(SelectInterface::class);
    $select->method('addExpression')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($mock_metrics);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $metrics = $this->vectorIndexManager->getPerformanceMetrics($this->index);

    $this->assertIsArray($metrics);
    $this->assertEquals(0.05, $metrics['avg_insert_time']);
    $this->assertEquals(0.85, $metrics['cache_hit_rate']);
  }

  /**
   * Tests index cleanup and maintenance.
   *
   * @covers ::performMaintenance
   */
  public function testIndexCleanupAndMaintenance() {
    // Mock maintenance operations
    $this->connection->expects($this->atLeastOnce())
      ->method('query')
      ->willReturn(TRUE);

    $maintenance_result = $this->vectorIndexManager->performMaintenance($this->index);

    $this->assertIsArray($maintenance_result);
    $this->assertArrayHasKey('operations_performed', $maintenance_result);
    $this->assertArrayHasKey('cleanup_stats', $maintenance_result);
    $this->assertArrayHasKey('performance_improvements', $maintenance_result);
  }

  /**
   * Tests backup and recovery operations.
   *
   * @covers ::backupIndex
   * @covers ::restoreIndex
   */
  public function testBackupAndRecoveryOperations() {
    $backup_path = '/tmp/vector_index_backup.sql';

    // Mock backup operation
    $this->connection->expects($this->once())
      ->method('query')
      ->willReturn(TRUE);

    $backup_result = $this->vectorIndexManager->backupIndex($this->index, $backup_path);
    $this->assertTrue($backup_result);

    // Mock restore operation
    $restore_result = $this->vectorIndexManager->restoreIndex($this->index, $backup_path);
    $this->assertTrue($restore_result);
  }

  /**
   * Tests vector similarity threshold calibration.
   *
   * @covers ::calibrateSimilarityThreshold
   */
  public function testVectorSimilarityThresholdCalibration() {
    $sample_queries = [
      'query1' => array_fill(0, 1536, 0.1),
      'query2' => array_fill(0, 1536, 0.2),
      'query3' => array_fill(0, 1536, 0.3),
    ];

    // Mock calibration process
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn([0.95, 0.87, 0.76, 0.65, 0.54]);
    
    $select->method('execute')->willReturn($statement);
    $this->connection->method('select')->willReturn($select);

    $optimal_threshold = $this->vectorIndexManager->calibrateSimilarityThreshold(
      $this->index, 
      $sample_queries
    );

    $this->assertIsFloat($optimal_threshold);
    $this->assertGreaterThan(0, $optimal_threshold);
    $this->assertLessThan(1, $optimal_threshold);
  }
}