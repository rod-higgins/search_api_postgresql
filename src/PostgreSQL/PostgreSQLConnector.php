<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;

/**
 * Manages PostgreSQL database connections with SQL injection prevention.
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
   * PostgreSQL reserved words that cannot be used as identifiers.
   *
   * @var array
   */
  protected static $reservedWords = [
    'all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric',
    'authorization', 'binary', 'both', 'case', 'cast', 'check', 'collate', 'collation',
    'column', 'concurrently', 'constraint', 'create', 'cross', 'current_catalog',
    'current_date', 'current_role', 'current_schema', 'current_time', 'current_timestamp',
    'current_user', 'default', 'deferrable', 'desc', 'distinct', 'do', 'else', 'end',
    'except', 'false', 'fetch', 'for', 'foreign', 'freeze', 'from', 'full', 'grant',
    'group', 'having', 'ilike', 'in', 'initially', 'inner', 'intersect', 'into', 'is',
    'isnull', 'join', 'lateral', 'leading', 'left', 'like', 'limit', 'localtime',
    'localtimestamp', 'natural', 'not', 'notnull', 'null', 'offset', 'on', 'only',
    'or', 'order', 'outer', 'overlaps', 'placing', 'primary', 'references', 'returning',
    'right', 'select', 'session_user', 'similar', 'some', 'symmetric', 'table', 'tablesample',
    'then', 'to', 'trailing', 'true', 'union', 'unique', 'user', 'using', 'variadic',
    'verbose', 'when', 'where', 'window', 'with',
    // Additional common SQL keywords
    'alter', 'insert', 'update', 'delete', 'drop', 'index', 'view', 'trigger',
    'function', 'procedure', 'database', 'schema', 'sequence', 'type', 'domain',
    'extension', 'role', 'user', 'group', 'password', 'login', 'connection',
  ];

  /**
   * Valid PostgreSQL data types.
   *
   * @var array
   */
  protected static $validDataTypes = [
    'BIGINT', 'BIGSERIAL', 'BIT', 'BIT VARYING', 'BOOLEAN', 'BOX', 'BYTEA',
    'CHARACTER', 'CHARACTER VARYING', 'CIDR', 'CIRCLE', 'DATE', 'DOUBLE PRECISION',
    'INET', 'INTEGER', 'INTERVAL', 'JSON', 'JSONB', 'LINE', 'LSEG', 'MACADDR',
    'MACADDR8', 'MONEY', 'NUMERIC', 'PATH', 'PG_LSN', 'POINT', 'POLYGON',
    'REAL', 'SMALLINT', 'SMALLSERIAL', 'SERIAL', 'TEXT', 'TIME', 'TIMESTAMP',
    'TIMESTAMPTZ', 'TSQUERY', 'TSVECTOR', 'TXID_SNAPSHOT', 'UUID', 'XML',
    'VARCHAR', 'INT', 'FLOAT', 'DECIMAL', 'CHAR', 'TIMETZ',
  ];

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
      // Handle empty passwords gracefully for Lando/development
      $password = $this->config['password'] ?? '';
      
      $this->connection = new \PDO(
        $dsn,
        $this->config['username'],
        $password, // Can be empty string for passwordless connections
        $options
      );
      
      // Set default character set.
      $this->connection->exec("SET NAMES 'UTF8'");
      
      $this->logger->debug('PostgreSQL connection established to @host:@port/@database @passwordless', [
        '@host' => $this->config['host'],
        '@port' => $this->config['port'],
        '@database' => $this->config['database'],
        '@passwordless' => empty($password) ? '(passwordless)' : '(with password)',
      ]);
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to connect to PostgreSQL: @message', ['@message' => $e->getMessage()]);
      
      // Provide helpful error message for common Lando issues
      $error_message = $e->getMessage();
      if (empty($this->config['password']) && strpos($error_message, 'authentication failed') !== FALSE) {
        $error_message .= ' (Note: If using Lando, ensure your database is configured for trust-based authentication)';
      }
      
      throw new SearchApiException('Database connection failed: ' . $error_message, $e->getCode(), $e);
    }
  }

  /**
   * Tests the database connection.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the connection test fails.
   */
  public function testConnection() {
    try {
      // Simple query to test connection
      $stmt = $this->connection->query('SELECT version()');
      $version = $stmt->fetchColumn();
      
      $this->logger->info('PostgreSQL connection test successful. Version: @version', [
        '@version' => $version,
      ]);
      
      return TRUE;
    }
    catch (\PDOException $e) {
      $this->logger->error('PostgreSQL connection test failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      throw new SearchApiException('Database connection test failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Gets the PostgreSQL version.
   *
   * @return string
   *   The PostgreSQL version string.
   */
  public function getVersion() {
    try {
      $stmt = $this->connection->query('SELECT version()');
      return $stmt->fetchColumn();
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to get PostgreSQL version: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 'Unknown';
    }
  }

  /**
   * Validates a SQL identifier (table name, column name, etc.).
   *
   * @param string $identifier
   *   The identifier to validate.
   * @param string $type
   *   The type of identifier for error messages.
   *
   * @return string
   *   The validated identifier.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the identifier is invalid.
   */
  public function validateIdentifier($identifier, $type = 'identifier') {
    // Check for null or empty identifier
    if (empty($identifier) || !is_string($identifier)) {
      throw new SearchApiException("Invalid {$type}: identifier cannot be empty.");
    }

    // PostgreSQL identifier rules:
    // - Start with letter (a-z, A-Z) or underscore
    // - Contain only letters, digits, underscores, and dollar signs
    // - Max 63 characters (PostgreSQL limit)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]{0,62}$/', $identifier)) {
      throw new SearchApiException("Invalid {$type}: '{$identifier}'. Must start with letter or underscore and contain only alphanumeric characters, underscores, and dollar signs (max 63 chars).");
    }
    
    // Check against PostgreSQL reserved words (case-insensitive)
    if (in_array(strtolower($identifier), self::$reservedWords)) {
      throw new SearchApiException("Invalid {$type}: '{$identifier}' is a PostgreSQL reserved word.");
    }
    
    return $identifier;
  }

  /**
   * Validates and quotes a table name for safe use in SQL.
   *
   * @param string $table_name
   *   The table name to validate.
   *
   * @return string
   *   The safely quoted table name.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the table name is invalid.
   */
  public function quoteTableName($table_name) {
    $validated_name = $this->validateIdentifier($table_name, 'table name');
    return '"' . $validated_name . '"';
  }

  /**
   * Validates and quotes a column name for safe use in SQL.
   *
   * @param string $column_name
   *   The column name to validate.
   *
   * @return string
   *   The safely quoted column name.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the column name is invalid.
   */
  public function quoteColumnName($column_name) {
    $validated_name = $this->validateIdentifier($column_name, 'column name');
    return '"' . $validated_name . '"';
  }

  /**
   * Validates and quotes an index name for safe use in SQL.
   *
   * @param string $index_name
   *   The index name to validate.
   *
   * @return string
   *   The safely quoted index name.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the index name is invalid.
   */
  public function quoteIndexName($index_name) {
    $validated_name = $this->validateIdentifier($index_name, 'index name');
    return '"' . $validated_name . '"';
  }

  /**
   * Validates a PostgreSQL data type.
   *
   * @param string $data_type
   *   The data type to validate.
   *
   * @return string
   *   The validated data type.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the data type is invalid.
   */
  public function validateDataType($data_type) {
    // Handle parameterized types
    if (preg_match('/^(VARCHAR|CHARACTER VARYING|BIT VARYING|CHAR|CHARACTER)\s*\(\s*\d+\s*\)$/i', $data_type)) {
      return strtoupper($data_type);
    }
    
    if (preg_match('/^(NUMERIC|DECIMAL)\s*\(\s*\d+\s*(,\s*\d+)?\s*\)$/i', $data_type)) {
      return strtoupper($data_type);
    }
    
    if (preg_match('/^(TIME|TIMESTAMP|TIMETZ|TIMESTAMPTZ)\s*\(\s*\d+\s*\)(\s+WITH(OUT)?\s+TIME\s+ZONE)?$/i', $data_type)) {
      return strtoupper($data_type);
    }
    
    // Handle VECTOR type for pgvector extension
    if (preg_match('/^VECTOR\s*\(\s*\d+\s*\)$/i', $data_type)) {
      return $data_type;
    }
    
    // Check against base types
    $base_type = strtoupper(trim($data_type));
    if (in_array($base_type, self::$validDataTypes)) {
      return $base_type;
    }
    
    throw new SearchApiException("Invalid PostgreSQL data type: '{$data_type}'");
  }

  /**
   * Gets the columns of a table.
   *
   * @param string $table_name
   *   The table name (unquoted).
   *
   * @return array
   *   Array of column names.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If getting columns fails.
   */
  public function getTableColumns($table_name) {
    try {
      $sql = "SELECT column_name 
              FROM information_schema.columns 
              WHERE table_schema = 'public' 
              AND table_name = :table_name 
              ORDER BY ordinal_position";
      
      $stmt = $this->executeQuery($sql, [':table_name' => $table_name]);
      $columns = [];
      
      while ($row = $stmt->fetch()) {
        $columns[] = $row['column_name'];
      }
      
      return $columns;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get columns for table @table: @message', [
        '@table' => $table_name,
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException("Failed to get table columns: " . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Executes a SQL query with parameters.
   *
   * @param string $sql
   *   The SQL query to execute.
   * @param array $params
   *   Optional parameters for the query.
   *
   * @return \PDOStatement
   *   The prepared statement.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the query execution fails.
   */
  public function executeQuery($sql, array $params = []) {
    try {
      $stmt = $this->connection->prepare($sql);
      $stmt->execute($params);
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
   * Begins a database transaction.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If starting the transaction fails.
   */
  public function beginTransaction() {
    try {
      $this->connection->beginTransaction();
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to begin transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Commits the current transaction.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If committing the transaction fails.
   */
  public function commit() {
    try {
      $this->connection->commit();
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to commit transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Rolls back the current transaction.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If rolling back the transaction fails.
   */
  public function rollback() {
    try {
      $this->connection->rollback();
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Checks if a table exists in the database.
   *
   * @param string $table_name
   *   The table name to check (without quotes).
   *
   * @return bool
   *   TRUE if the table exists, FALSE otherwise.
   */
  public function tableExists($table_name) {
    try {
      // Use unquoted table name for existence check
      $sql = "SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = :table_name
      )";
      
      $stmt = $this->executeQuery($sql, [':table_name' => $table_name]);
      return (bool) $stmt->fetchColumn();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check table existence for @table: @message', [
        '@table' => $table_name,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if an index exists in the database.
   *
   * @param string $index_name
   *   The index name to check (without quotes).
   *
   * @return bool
   *   TRUE if the index exists, FALSE otherwise.
   */
  public function indexExists($index_name) {
    try {
      $sql = "SELECT EXISTS (
        SELECT FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND indexname = :index_name
      )";
      
      $stmt = $this->executeQuery($sql, [':index_name' => $index_name]);
      return (bool) $stmt->fetchColumn();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check index existence for @index: @message', [
        '@index' => $index_name,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets the last inserted ID.
   *
   * @param string $sequence_name
   *   Optional sequence name for PostgreSQL.
   *
   * @return string
   *   The last insert ID.
   */
  public function lastInsertId($sequence_name = NULL) {
    return $this->connection->lastInsertId($sequence_name);
  }

  /**
   * Quotes a string value for safe use in SQL.
   *
   * @param string $value
   *   The value to quote.
   *
   * @return string
   *   The quoted value.
   */
  public function quote($value) {
    return $this->connection->quote($value);
  }

  /**
   * Gets the underlying PDO connection.
   *
   * @return \PDO
   *   The PDO connection object.
   */
  public function getConnection() {
    return $this->connection;
  }

  /**
   * Escapes LIKE pattern special characters.
   *
   * @param string $pattern
   *   The pattern to escape.
   *
   * @return string
   *   The escaped pattern.
   */
  public function escapeLikePattern($pattern) {
    // Escape PostgreSQL LIKE special characters
    $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pattern);
    return $pattern;
  }

  /**
   * Creates a database savepoint.
   *
   * @param string $savepoint_name
   *   The name of the savepoint.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If creating the savepoint fails.
   */
  public function createSavepoint($savepoint_name) {
    try {
      $validated_name = $this->validateIdentifier($savepoint_name, 'savepoint name');
      $this->connection->exec("SAVEPOINT \"{$validated_name}\"");
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to create savepoint: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Releases a database savepoint.
   *
   * @param string $savepoint_name
   *   The name of the savepoint.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If releasing the savepoint fails.
   */
  public function releaseSavepoint($savepoint_name) {
    try {
      $validated_name = $this->validateIdentifier($savepoint_name, 'savepoint name');
      $this->connection->exec("RELEASE SAVEPOINT \"{$validated_name}\"");
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to release savepoint: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Rolls back to a database savepoint.
   *
   * @param string $savepoint_name
   *   The name of the savepoint.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If rolling back to the savepoint fails.
   */
  public function rollbackToSavepoint($savepoint_name) {
    try {
      $validated_name = $this->validateIdentifier($savepoint_name, 'savepoint name');
      $this->connection->exec("ROLLBACK TO SAVEPOINT \"{$validated_name}\"");
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to rollback to savepoint: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Gets database connection information.
   *
   * @return array
   *   Array containing connection details.
   */
  public function getConnectionInfo() {
    return [
      'host' => $this->config['host'],
      'port' => $this->config['port'],
      'database' => $this->config['database'],
      'username' => $this->config['username'],
      'ssl_mode' => $this->config['ssl_mode'] ?? 'require',
      'passwordless' => empty($this->config['password']),
    ];
  }

  /**
   * Closes the database connection.
   */
  public function close() {
    $this->connection = NULL;
  }

  /**
   * Destructor to ensure connection is closed.
   */
  public function __destruct() {
    $this->close();
  }
}