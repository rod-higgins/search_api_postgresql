<?php

namespace Drupal\Tests\search_api_postgresql\Unit\PostgreSQL;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PostgreSQL connector.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
 */
class PostgreSQLConnectorUnitTest extends UnitTestCase {
  /**
   * The PostgreSQL connector under test.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Mock schema.
   *
   * @var \Drupal\Core\Database\Schema|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $schema;

  /**
   * Mock logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    $this->schema = $this->createMock(Schema::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->database->method('schema')->willReturn($this->schema);

    $config = [
      'host' => 'localhost',
      'port' => 5432,
      'database' => 'test_db',
      'username' => 'test_user',
      'password' => 'test_pass',
    ];

    $this->connector = new PostgreSQLConnector($this->database, $this->logger, $config);
  }

  /**
   * Tests connector initialization.
   *
   * @covers ::__construct
   */
  public function testConnectorInitialization() {
    $this->assertInstanceOf(PostgreSQLConnector::class, $this->connector);
  }

  /**
   * Tests database connection testing.
   *
   * @covers ::testConnection
   */
  public function testTestConnection() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn('PostgreSQL 16.0');

    $this->database->method('query')->willReturn($statement);

    $result = $this->connector->testConnection();
    $this->assertTrue($result);
  }

  /**
   * Tests extension checking.
   *
   * @covers ::checkExtension
   */
  public function testCheckExtension() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(1);

    $this->database->method('query')->willReturn($statement);

    $result = $this->connector->checkExtension('vector');
    $this->assertTrue($result);
  }

  /**
   * Tests extension installation.
   *
   * @covers ::installExtension
   */
  public function testInstallExtension() {
    $this->database->method('query')->willReturn(TRUE);

    $result = $this->connector->installExtension('vector');
    $this->assertTrue($result);
  }

  /**
   * Tests query execution.
   *
   * @covers ::executeQuery
   */
  public function testExecuteQuery() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([
      ['id' => 1, 'name' => 'test1'],
      ['id' => 2, 'name' => 'test2'],
    ]);

    $this->database->method('query')->willReturn($statement);

    $result = $this->connector->executeQuery('SELECT * FROM test_table');
    $this->assertCount(2, $result);
    $this->assertEquals('test1', $result[0]['name']);
  }

  /**
   * Tests parameterized query execution.
   *
   * @covers ::executeQuery
   */
  public function testExecuteQueryWithParameters() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([
      ['id' => 1, 'name' => 'test1'],
    ]);

    $this->database->method('query')->willReturn($statement);

    $params = [':id' => 1];
    $result = $this->connector->executeQuery('SELECT * FROM test_table WHERE id = :id', $params);
    $this->assertCount(1, $result);
  }

  /**
   * Tests table existence checking.
   *
   * @covers ::tableExists
   */
  public function testTableExists() {
    $this->schema->method('tableExists')->willReturn(TRUE);

    $result = $this->connector->tableExists('test_table');
    $this->assertTrue($result);
  }

  /**
   * Tests index existence checking.
   *
   * @covers ::indexExists
   */
  public function testIndexExists() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(1);

    $this->database->method('query')->willReturn($statement);

    $result = $this->connector->indexExists('test_table', 'test_index');
    $this->assertTrue($result);
  }

  /**
   * Tests vector operations support checking.
   *
   * @covers ::supportsVectorOperations
   */
  public function testSupportsVectorOperations() {
    // Mock pgvector extension check.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(1);
    $this->database->method('query')->willReturn($statement);

    $result = $this->connector->supportsVectorOperations();
    $this->assertTrue($result);
  }

  /**
   * Tests fulltext search support checking.
   *
   * @covers ::supportsFullTextSearch
   */
  public function testSupportsFullTextSearch() {
    $result = $this->connector->supportsFullTextSearch();
    $this->assertTrue($result);
  }

  /**
   * Tests database version retrieval.
   *
   * @covers ::getDatabaseVersion
   */
  public function testGetDatabaseVersion() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn('PostgreSQL 16.0 on x86_64-pc-linux-gnu');

    $this->database->method('query')->willReturn($statement);

    $version = $this->connector->getDatabaseVersion();
    $this->assertStringContainsString('PostgreSQL', $version);
    $this->assertStringContainsString('16.0', $version);
  }

  /**
   * Tests database configuration retrieval.
   *
   * @covers ::getDatabaseConfiguration
   */
  public function testGetDatabaseConfiguration() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllKeyed')->willReturn([
      'shared_buffers' => '128MB',
      'work_mem' => '4MB',
      'effective_cache_size' => '4GB',
    ]);

    $this->database->method('query')->willReturn($statement);

    $config = $this->connector->getDatabaseConfiguration();
    $this->assertIsArray($config);
    $this->assertArrayHasKey('shared_buffers', $config);
    $this->assertEquals('128MB', $config['shared_buffers']);
  }

  /**
   * Tests error handling in connection failures.
   *
   * @covers ::testConnection
   */
  public function testConnectionFailure() {
    $this->database->method('query')->willThrowException(new \Exception('Connection failed'));

    $result = $this->connector->testConnection();
    $this->assertFalse($result);
  }

  /**
   * Tests transaction handling.
   *
   * @covers ::beginTransaction
   * @covers ::commitTransaction
   * @covers ::rollbackTransaction
   */
  public function testTransactionHandling() {
    $this->database->method('startTransaction')->willReturn(TRUE);

    $this->assertTrue($this->connector->beginTransaction());
    $this->assertTrue($this->connector->commitTransaction());
    $this->assertTrue($this->connector->rollbackTransaction());
  }

}
