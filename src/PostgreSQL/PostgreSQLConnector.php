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
  public function validateTableName($table_name) {
    $validated = $this->validateIdentifier($table_name, 'table name');
    return '"' . $validated . '"';
  }

  /**
   * Validates and quotes a field name for safe use in SQL.
   *
   * @param string $field_name
   *   The field name to validate.
   *
   * @return string
   *   The safely quoted field name.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the field name is invalid.
   */
  public function validateFieldName($field_name) {
    $validated = $this->validateIdentifier($field_name, 'field name');
    return '"' . $validated . '"';
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
  public function validateIndexName($index_name) {
    $validated = $this->validateIdentifier($index_name, 'index name');
    return '"' . $validated . '"';
  }

  /**
   * Validates multiple identifiers at once.
   *
   * @param array $identifiers
   *   Array of identifiers to validate.
   * @param string $type
   *   The type of identifiers for error messages.
   *
   * @return array
   *   Array of validated identifiers.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If any identifier is invalid.
   */
  public function validateIdentifiers(array $identifiers, $type = 'identifier') {
    $validated = [];
    foreach ($identifiers as $key => $identifier) {
      $validated[$key] = $this->validateIdentifier($identifier, $type);
    }
    return $validated;
  }

  /**
   * Validates a sort direction.
   *
   * @param string $direction
   *   The sort direction.
   *
   * @return string
   *   The validated direction (ASC or DESC).
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the direction is invalid.
   */
  public function validateSortDirection($direction) {
    $direction = strtoupper(trim($direction));
    
    if (!in_array($direction, ['ASC', 'DESC'])) {
      throw new SearchApiException("Invalid sort direction: '{$direction}'. Must be ASC or DESC.");
    }
    
    return $direction;
  }

  /**
   * Validates a PostgreSQL data type.
   *
   * @param string $type
   *   The data type to validate.
   *
   * @return string
   *   The validated data type.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the data type is invalid.
   */
  public function validateDataType($type) {
    $type = trim($type);
    
    // Allowed PostgreSQL data types for Search API
    $allowed_types = [
      'TEXT',
      'VARCHAR(255)',
      'INTEGER',
      'BIGINT',
      'DECIMAL(10,2)',
      'NUMERIC',
      'TIMESTAMP',
      'TIMESTAMPTZ',
      'DATE',
      'BOOLEAN',
      'TSVECTOR',
      'JSON',
      'JSONB',
    ];
    
    // Handle parameterized types
    if (preg_match('/^VARCHAR\(\d+\)$/', $type)) {
      return $type;
    }
    
    if (preg_match('/^DECIMAL\(\d+,\d+\)$/', $type)) {
      return $type;
    }
    
    if (preg_match('/^NUMERIC(\(\d+(,\d+)?\))?$/', $type)) {
      return $type;
    }
    
    // Handle vector types (for AI embeddings)
    if (preg_match('/^VECTOR\(\d+\)$/', $type)) {
      return $type;
    }
    
    if (!in_array($type, $allowed_types)) {
      throw new SearchApiException("Invalid data type: '{$type}'. Allowed types: " . implode(', ', $allowed_types));
    }
    
    return $type;
  }

  /**
   * Safely constructs a table reference with schema.
   *
   * @param string $table_name
   *   The table name.
   * @param string $schema
   *   The schema name (optional).
   *
   * @return string
   *   The safely quoted table reference.
   */
  public function buildTableReference($table_name, $schema = NULL) {
    $safe_table = $this->validateTableName($table_name);
    
    if ($schema) {
      $safe_schema = $this->validateIdentifier($schema, 'schema name');
      return '"' . $safe_schema . '".' . $safe_table;
    }
    
    return $safe_table;
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
   *   The table name (unquoted).
   *
   * @return bool
   *   TRUE if the table exists, FALSE otherwise.
   */
  public function tableExists($table_name) {
    // Validate the table name first
    $this->validateIdentifier($table_name, 'table name');
    
    $sql = "SELECT EXISTS (
      SELECT FROM information_schema.tables 
      WHERE table_name = :table_name
    )";
    $stmt = $this->executeQuery($sql, [':table_name' => $table_name]);
    return (bool) $stmt->fetchColumn();
  }

  /**
   * Checks if an index exists.
   *
   * @param string $index_name
   *   The index name (unquoted).
   *
   * @return bool
   *   TRUE if the index exists, FALSE otherwise.
   */
  public function indexExists($index_name) {
    // Validate the index name first
    $this->validateIdentifier($index_name, 'index name');
    
    $sql = "SELECT EXISTS (
      SELECT FROM pg_indexes 
      WHERE indexname = :index_name
    )";
    $stmt = $this->executeQuery($sql, [':index_name' => $index_name]);
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

  /**
   * Gets information about table columns.
   *
   * @param string $table_name
   *   The table name (unquoted).
   *
   * @return array
   *   Array of column information.
   */
  public function getTableColumns($table_name) {
    // Validate the table name first
    $this->validateIdentifier($table_name, 'table name');
    
    $sql = "
      SELECT column_name, data_type, is_nullable, column_default
      FROM information_schema.columns 
      WHERE table_name = :table_name
      ORDER BY ordinal_position
    ";
    $stmt = $this->executeQuery($sql, [':table_name' => $table_name]);
    return $stmt->fetchAll();
  }

  /**
   * Gets information about table indexes.
   *
   * @param string $table_name
   *   The table name (unquoted).
   *
   * @return array
   *   Array of index information.
   */
  public function getTableIndexes($table_name) {
    // Validate the table name first
    $this->validateIdentifier($table_name, 'table name');
    
    $sql = "
      SELECT indexname, indexdef
      FROM pg_indexes 
      WHERE tablename = :table_name
    ";
    $stmt = $this->executeQuery($sql, [':table_name' => $table_name]);
    return $stmt->fetchAll();
  }

}