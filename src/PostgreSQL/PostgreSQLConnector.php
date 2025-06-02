<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;

/**
 * Manages PostgreSQL database connections.
 */
class PostgreSQLConnector {

  /**
   * The PDO connection.
   *
   * @var \PDO
   */
  protected $connection;

  /**
   * The connection configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a PostgreSQLConnector object.
   *
   * @param array $config
   *   The connection configuration.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $config, LoggerInterface $logger) {
    $this->config = $config;
    $this->logger = $logger;
    $this->connect();
  }

  /**
   * Establishes the database connection.
   */
  protected function connect() {
    $dsn = sprintf(
      "pgsql:host=%s;port=%d;dbname=%s;sslmode=%s",
      $this->config['host'],
      $this->config['port'],
      $this->config['database'],
      $this->config['ssl_mode'] ?? 'require'
    );

    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES => FALSE,
    ];

    // Add any additional options from configuration.
    if (!empty($this->config['options'])) {
      $options = array_merge($options, $this->config['options']);
    }

    try {
      $this->connection = new \PDO(
        $dsn,
        $this->config['username'],
        $this->config['password'],
        $options
      );
      
      // Set default character set.
      $this->connection->exec("SET NAMES 'UTF8'");
      
      $this->logger->debug('PostgreSQL connection established to @host:@port/@database', [
        '@host' => $this->config['host'],
        '@port' => $this->config['port'],
        '@database' => $this->config['database'],
      ]);
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to connect to PostgreSQL: @message', ['@message' => $e->getMessage()]);
      throw new SearchApiException('Database connection failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Executes a query and returns the statement.
   *
   * @param string $sql
   *   The SQL query.
   * @param array $params
   *   Query parameters.
   *
   * @return \PDOStatement
   *   The executed statement.
   */
  public function executeQuery($sql, array $params = []) {
    try {
      $stmt = $this->connection->prepare($sql);
      $stmt->execute($params);
      
      $this->logger->debug('Executed query: @sql with params: @params', [
        '@sql' => $sql,
        '@params' => json_encode($params),
      ]);
      
      return $stmt;
    }
    catch (\PDOException $e) {
      $this->logger->error('Query execution failed: @message. SQL: @sql', [
        '@message' => $e->getMessage(),
        '@sql' => $sql,
      ]);
      throw new SearchApiException('Query execution failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Starts a database transaction.
   */
  public function beginTransaction() {
    return $this->connection->beginTransaction();
  }

  /**
   * Commits a database transaction.
   */
  public function commit() {
    return $this->connection->commit();
  }

  /**
   * Rolls back a database transaction.
   */
  public function rollback() {
    return $this->connection->rollback();
  }

  /**
   * Tests the database connection.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the connection test fails.
   */
  public function testConnection() {
    try {
      $stmt = $this->connection->query('SELECT version()');
      $version = $stmt->fetchColumn();
      $this->logger->info('PostgreSQL connection test successful. Version: @version', ['@version' => $version]);
      return TRUE;
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Connection test failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Gets the PostgreSQL version.
   *
   * @return string
   *   The PostgreSQL version string.
   */
  public function getVersion() {
    $stmt = $this->connection->query('SELECT version()');
    return $stmt->fetchColumn();
  }

  /**
   * Checks if a table exists.
   *
   * @param string $table_name
   *   The table name.
   *
   * @return bool
   *   TRUE if the table exists, FALSE otherwise.
   */
  public function tableExists($table_name) {
    $sql = "SELECT EXISTS (
      SELECT FROM information_schema.tables 
      WHERE table_name = :table_name
    )";
    $stmt = $this->executeQuery($sql, [':table_name' => $table_name]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * Gets the last insert ID.
   *
   * @param string $name
   *   The sequence name.
   *
   * @return string
   *   The last insert ID.
   */
  public function lastInsertId($name = NULL) {
    return $this->connection->lastInsertId($name);
  }

  /**
   * Quotes a string for use in a query.
   *
   * @param string $string
   *   The string to quote.
   *
   * @return string
   *   The quoted string.
   */
  public function quote($string) {
    return $this->connection->quote($string);
  }

}