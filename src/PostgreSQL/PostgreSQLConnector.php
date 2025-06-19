<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Psr\Log\LoggerInterface;

/**
 * PostgreSQL database connector.
 */
class PostgreSQLConnector {

  /**
   * The database connection.
   *
   * @var \PDO
   */
  protected $connection;

  /**
   * Connection configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   *
   * @param array $config
   *   Database connection configuration.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger service.
   */
  public function __construct(array $config, LoggerInterface $logger) {
    $this->config = $config + $this->getDefaultConfig();
    $this->logger = $logger;
  }

  /**
   * Get default configuration.
   *
   * @return array
   *   Default configuration values.
   */
  protected function getDefaultConfig() {
    return [
      'host' => 'localhost',
      'port' => 5432,
      'database' => '',
      'username' => '',
      'password' => '',
      'ssl_mode' => 'require',
      'ssl_ca' => '',
      'options' => [],
      'charset' => 'utf8',
    ];
  }

  /**
   * Connect to the database.
   *
   * @return \PDO
   *   The PDO connection.
   *
   * @throws \Exception
   *   If connection fails.
   */
  public function connect() {
    if ($this->connection) {
      return $this->connection;
    }

    try {
      $dsn = $this->buildDsn();
      $options = $this->buildPdoOptions();

      $this->connection = new \PDO($dsn, $this->config['username'], $this->config['password'], $options);
      $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
      
      $this->logger->info('Successfully connected to PostgreSQL database @host:@port/@database', [
        '@host' => $this->config['host'],
        '@port' => $this->config['port'],
        '@database' => $this->config['database'],
      ]);

      return $this->connection;
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to connect to PostgreSQL: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Database connection failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Build the DSN string.
   *
   * @return string
   *   The DSN string.
   */
  protected function buildDsn() {
    $dsn_parts = [
      'host=' . $this->config['host'],
      'port=' . $this->config['port'],
      'dbname=' . $this->config['database'],
    ];

    // Add SSL configuration
    if (!empty($this->config['ssl_mode'])) {
      $dsn_parts[] = 'sslmode=' . $this->config['ssl_mode'];
    }

    if (!empty($this->config['ssl_ca'])) {
      $dsn_parts[] = 'sslrootcert=' . $this->config['ssl_ca'];
    }

    // Add additional options
    if (!empty($this->config['options']) && is_array($this->config['options'])) {
      foreach ($this->config['options'] as $key => $value) {
        $dsn_parts[] = $key . '=' . $value;
      }
    }

    return 'pgsql:' . implode(';', $dsn_parts);
  }

  /**
   * Build PDO options array.
   *
   * @return array
   *   PDO options.
   */
  protected function buildPdoOptions() {
    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_TIMEOUT => 30,
    ];

    // Set charset if specified
    if (!empty($this->config['charset'])) {
      $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . $this->config['charset'];
    }

    return $options;
  }

  /**
   * Test the database connection.
   *
   * @throws \Exception
   *   If connection test fails.
   */
  public function testConnection() {
    $connection = $this->connect();
    
    // Test with a simple query
    $stmt = $connection->query('SELECT version()');
    $version = $stmt->fetchColumn();
    
    if (empty($version)) {
      throw new \Exception('Connection test failed: Could not retrieve database version.');
    }

    $this->logger->info('Connection test successful. PostgreSQL version: @version', [
      '@version' => $version,
    ]);
  }

  /**
   * Execute a query.
   *
   * @param string $sql
   *   The SQL query.
   * @param array $params
   *   Query parameters.
   *
   * @return \PDOStatement
   *   The executed statement.
   *
   * @throws \Exception
   *   If query execution fails.
   */
  public function executeQuery($sql, array $params = []) {
    try {
      $connection = $this->connect();
      
      if (empty($params)) {
        return $connection->query($sql);
      } else {
        $stmt = $connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
      }
    }
    catch (\PDOException $e) {
      $this->logger->error('Query execution failed: @message. SQL: @sql', [
        '@message' => $e->getMessage(),
        '@sql' => $sql,
      ]);
      throw new \Exception('Query execution failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Execute a prepared statement.
   *
   * @param string $sql
   *   The SQL query with placeholders.
   * @param array $params
   *   Query parameters.
   *
   * @return \PDOStatement
   *   The executed statement.
   *
   * @throws \Exception
   *   If statement execution fails.
   */
  public function executePrepared($sql, array $params = []) {
    try {
      $connection = $this->connect();
      $stmt = $connection->prepare($sql);
      $stmt->execute($params);
      return $stmt;
    }
    catch (\PDOException $e) {
      $this->logger->error('Prepared statement execution failed: @message. SQL: @sql', [
        '@message' => $e->getMessage(),
        '@sql' => $sql,
      ]);
      throw new \Exception('Prepared statement execution failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Begin a transaction.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If transaction cannot be started.
   */
  public function beginTransaction() {
    try {
      $connection = $this->connect();
      return $connection->beginTransaction();
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to begin transaction: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to begin transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Commit a transaction.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If transaction cannot be committed.
   */
  public function commit() {
    try {
      if ($this->connection) {
        return $this->connection->commit();
      }
      return FALSE;
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to commit transaction: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to commit transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Rollback a transaction.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If transaction cannot be rolled back.
   */
  public function rollback() {
    try {
      if ($this->connection) {
        return $this->connection->rollback();
      }
      return FALSE;
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to rollback transaction: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Check if a table exists.
   *
   * @param string $table_name
   *   The table name.
   *
   * @return bool
   *   TRUE if table exists.
   */
  public function tableExists($table_name) {
    try {
      $sql = "SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_type = 'BASE TABLE'";
      $stmt = $this->executePrepared($sql, [$table_name]);
      return $stmt->fetchColumn() !== FALSE;
    }
    catch (\Exception $e) {
      $this->logger->warning('Error checking if table exists: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Quote a table name for safe SQL usage.
   *
   * @param string $table_name
   *   The table name to quote.
   *
   * @return string
   *   The quoted table name.
   */
  public function quoteTableName($table_name) {
    $this->validateIdentifier($table_name, 'table name');
    return '"' . str_replace('"', '""', $table_name) . '"';
  }

  /**
   * Quote a column name for safe SQL usage.
   *
   * @param string $column_name
   *   The column name to quote.
   *
   * @return string
   *   The quoted column name.
   */
  public function quoteColumnName($column_name) {
    $this->validateIdentifier($column_name, 'column name');
    return '"' . str_replace('"', '""', $column_name) . '"';
  }

  /**
   * Quote an index name for safe SQL usage.
   *
   * @param string $index_name
   *   The index name to quote.
   *
   * @return string
   *   The quoted index name.
   */
  public function quoteIndexName($index_name) {
    $this->validateIdentifier($index_name, 'index name');
    return '"' . str_replace('"', '""', $index_name) . '"';
  }

  /**
   * Validates an identifier for safe SQL usage.
   *
   * @param string $identifier
   *   The identifier to validate.
   * @param string $type
   *   The type of identifier (for error messages).
   *
   * @throws \InvalidArgumentException
   *   If the identifier is invalid.
   */
  public function validateIdentifier($identifier, $type = 'identifier') {
    if (empty($identifier)) {
      throw new \InvalidArgumentException("Empty {$type} not allowed");
    }
    
    if (!is_string($identifier)) {
      throw new \InvalidArgumentException("{$type} must be a string");
    }
    
    // PostgreSQL identifier length limit
    if (strlen($identifier) > 63) {
      throw new \InvalidArgumentException("{$type} '{$identifier}' exceeds maximum length of 63 characters");
    }
    
    // Basic validation for SQL identifiers
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
      throw new \InvalidArgumentException("Invalid {$type}: '{$identifier}'. Must start with a letter or underscore and contain only letters, numbers, and underscores.");
    }
  }

  /**
   * Validates a data type for safe SQL usage.
   *
   * @param string $data_type
   *   The data type to validate.
   *
   * @return string
   *   The validated data type.
   *
   * @throws \InvalidArgumentException
   *   If the data type is invalid.
   */
  public function validateDataType($data_type) {
    $allowed_types = [
      'VARCHAR', 'TEXT', 'INTEGER', 'BIGINT', 'DECIMAL', 'NUMERIC',
      'BOOLEAN', 'TIMESTAMP', 'DATE', 'TIME', 'TSVECTOR', 'VECTOR',
      'JSONB', 'UUID', 'BYTEA', 'REAL', 'DOUBLE PRECISION'
    ];
    
    // Handle parameterized types like VARCHAR(255) or VECTOR(1536)
    $base_type = preg_replace('/\([^)]*\)/', '', strtoupper(trim($data_type)));
    
    if (!in_array($base_type, $allowed_types)) {
      throw new \InvalidArgumentException("Invalid data type: {$data_type}");
    }
    
    return $data_type;
  }

  /**
   * Gets the columns of a table.
   *
   * @param string $table_name
   *   The table name (unquoted).
   *
   * @return array
   *   Array of column names.
   */
  public function getTableColumns($table_name) {
    try {
      $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position";
      $stmt = $this->executePrepared($sql, [$table_name]);
      return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    catch (\Exception $e) {
      $this->logger->warning('Error getting table columns for @table: @message', [
        '@table' => $table_name,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Escapes a string for use in LIKE patterns.
   *
   * @param string $string
   *   The string to escape.
   *
   * @return string
   *   The escaped string.
   */
  public function escapeLikePattern($string) {
    // Escape PostgreSQL LIKE pattern special characters
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $string);
  }

  /**
   * Check if an extension exists.
   *
   * @param string $extension_name
   *   The extension name.
   *
   * @return bool
   *   TRUE if extension is installed.
   */
  public function extensionExists($extension_name) {
    try {
      $sql = "SELECT 1 FROM pg_extension WHERE extname = ?";
      $stmt = $this->executePrepared($sql, [$extension_name]);
      return $stmt->fetchColumn() !== FALSE;
    }
    catch (\Exception $e) {
      $this->logger->warning('Error checking if extension exists: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get the last insert ID.
   *
   * @param string $sequence_name
   *   Optional sequence name.
   *
   * @return string
   *   The last insert ID.
   */
  public function lastInsertId($sequence_name = NULL) {
    $connection = $this->connect();
    return $connection->lastInsertId($sequence_name);
  }

  /**
   * Quote a string for safe SQL usage.
   *
   * @param string $string
   *   The string to quote.
   *
   * @return string
   *   The quoted string.
   */
  public function quote($string) {
    $connection = $this->connect();
    return $connection->quote($string);
  }

  /**
   * Get connection information.
   *
   * @return array
   *   Connection information.
   */
  public function getConnectionInfo() {
    return [
      'host' => $this->config['host'],
      'port' => $this->config['port'],
      'database' => $this->config['database'],
      'username' => $this->config['username'],
      'ssl_mode' => $this->config['ssl_mode'],
    ];
  }

  /**
   * Close the database connection.
   */
  public function disconnect() {
    $this->connection = NULL;
  }

  /**
   * Destructor.
   */
  public function __destruct() {
    $this->disconnect();
  }

}