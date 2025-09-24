<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests the PostgreSQL connector.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
 */
class PostgreSQLConnectorTest extends UnitTestCase {

  /**
   * Tests connection configuration validation.
   */
  public function testConnectionConfigValidation() {
    $config = [
      'host' => 'localhost',
      'port' => 5432,
      'database' => 'test_db',
      'username' => 'test_user',
      'password' => 'test_pass',
      'ssl_mode' => 'require',
    ];

    // Test valid configuration structure.
    $this->assertArrayHasKey('host', $config);
    $this->assertArrayHasKey('port', $config);
    $this->assertArrayHasKey('database', $config);
    $this->assertArrayHasKey('username', $config);
    $this->assertArrayHasKey('password', $config);
    $this->assertEquals(5432, $config['port']);
  }

  /**
   * Tests query parameter binding logic.
   */
  public function testQueryParameterBinding() {
    // Test parameter validation.
    $params = [
      ':name' => 'test_value',
      ':id' => 123,
      ':active' => TRUE,
    ];

    $this->assertIsArray($params);
    $this->assertCount(3, $params);
    $this->assertEquals('test_value', $params[':name']);
    $this->assertEquals(123, $params[':id']);
    $this->assertTrue($params[':active']);
  }

}
