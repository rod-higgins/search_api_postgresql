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
    // Build DSN parts
    $dsn_parts = [
      'host=' . $this->config['host'],
      'port=' . $this->config['port'],
      'dbname=' . $this->config['database'],
    ];
    
    // Only add user if provided
    if (!empty($this->config['username'])) {
      $dsn_parts[] = 'user=' . $this->config['username'];
    }
    
    // Only add password if provided (optional for local dev)
    if (!empty($this->config['password'])) {
      $dsn_parts[] = 'password=' . $this->config['password'];
    }
    
    // SSL mode configuration
    if (!empty($this->config['ssl_mode']) && $this->config['ssl_mode'] !== 'disable') {
      $dsn_parts[] = 'sslmode=' . $this->config['ssl_mode'];
      
      if (!empty($this->config['ssl_ca'])) {
        $dsn_parts[] = 'sslrootcert=' . $this->config['ssl_ca'];
      }
    }
    
    $dsn = 'pgsql:' . implode(';', $dsn_parts);

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
      // Create connection without separate username/password parameters
      // They're already in the DSN if provided
      $this->connection = new \PDO($dsn, null, null, $options);
      
      // Set default character set.
      $this->connection->exec("SET NAMES 'UTF8'");
      
      // Set search path if configured
      if (!empty($this->config['search_path'])) {
        $this->connection->exec('SET search_path TO ' . $this->config['search_path']);
      }
      
      $this->logger->debug('PostgreSQL connection established to @host:@port/@database @passwordless', [
        '@host' => $this->config['host'],
        '@port' => $this->config['port'],
        '@database' => $this->config['database'],
        '@passwordless' => empty($this->config['password']) ? '(passwordless)' : '(with password)',
      ]);
    }
    catch (\PDOException $e) {
      $this->logger->error('Failed to connect to PostgreSQL: @message', ['@message' => $e->getMessage()]);
      
      // Provide helpful error message for common issues
      $error_message = $e->getMessage();
      
      if (stripos($error_message, 'password authentication failed') !== FALSE) {
        throw new SearchApiException(
          'Database connection failed: Password authentication failed. ' .
          'For local development environments (e.g., Lando) that don\'t require passwords, ' .
          'leave the password field empty.',
          (int) $e->getCode(),
          $e
        );
      }
      
      throw new SearchApiException('Database connection failed: ' . $error_message, (int) $e->getCode(), $e);
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
      return 'Unknown';
    }
  }

  /**
   * Validates an identifier (table, column, etc.) to prevent SQL injection.
   *
   * @param string $identifier
   *   The identifier to validate.
   * @param string $type
   *   The type of identifier (for error messages).
   *
   * @return string
   *   The validated identifier.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the identifier is invalid.
   */
  public function validateIdentifier($identifier, $type = 'identifier') {
    // Check if empty
    if (empty($identifier)) {
      throw new SearchApiException("Empty {$type} is not allowed.");
    }

    // Check length (PostgreSQL max identifier length is 63)
    if (strlen($identifier) > 63) {
      throw new SearchApiException("The {$type} '{$identifier}' exceeds maximum length of 63 characters.");
    }

    // Check for valid characters (alphanumeric and underscore)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
      throw new SearchApiException("The {$type} '{$identifier}' contains invalid characters. Only alphanumeric and underscore allowed.");
    }

    // Check against reserved words
    if (in_array(strtolower($identifier), self::$reservedWords)) {
      throw new SearchApiException("The {$type} '{$identifier}' is a reserved PostgreSQL keyword.");
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
      $this->connection->rollBack();
    }
    catch (\PDOException $e) {
      throw new SearchApiException('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Checks if in a transaction.
   *
   * @return bool
   *   TRUE if in a transaction, FALSE otherwise.
   */
  public function inTransaction() {
    return $this->connection->inTransaction();
  }

  /**
   * Gets the last insert ID.
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