<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Psr\Log\LoggerInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;

// If vector search classes exist these will be needed.

/**
 * PostgreSQL database connector.
 */
class PostgreSQLConnector
{
  /**
   * The database connection.
   * {@inheritdoc}
   *
   * @var \PDO
   */
  protected $connection;

  /**
   * Connection configuration.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $config;

  /**
   * Logger service.
   * {@inheritdoc}
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   * {@inheritdoc}
   *
   * @param array $config
   *   Database connection configuration.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger service.
   */
  public function __construct(array $config, LoggerInterface $logger)
  {
    $this->config = $config + $this->getDefaultConfig();
    $this->logger = $logger;
  }

  /**
   * Get default configuration.
   * {@inheritdoc}
   *
   * @return array
   *   Default configuration values.
   */
  protected function getDefaultConfig()
  {
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
   * {@inheritdoc}
   *
   * @return \PDO
   *   The PDO connection.
   *   {@inheritdoc}
   *
   * @throws \Exception
   *   If connection fails.
   */
  public function connect()
  {
    if ($this->connection) {
      return $this->connection;
    }

    try {
      $dsn = $this->buildDsn();
      $options = $this->buildPdoOptions();

      $this->connection = new \PDO($dsn, $this->config['username'], $this->config['password'], $options);
      $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

      return $this->connection;
    } catch (\PDOException $e) {
      $this->logger->error('Failed to connect to PostgreSQL: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Database connection failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Build the DSN string.
   * {@inheritdoc}
   *
   * @return string
   *   The DSN string.
   */
  protected function buildDsn()
  {
    $dsn_parts = [
      'host=' . $this->config['host'],
      'port=' . $this->config['port'],
      'dbname=' . $this->config['database'],
    ];

    // Add SSL configuration.
    if (!empty($this->config['ssl_mode'])) {
      $dsn_parts[] = 'sslmode=' . $this->config['ssl_mode'];
    }

    if (!empty($this->config['ssl_ca'])) {
      $dsn_parts[] = 'sslrootcert=' . $this->config['ssl_ca'];
    }

    // Add additional options.
    if (!empty($this->config['options']) && is_array($this->config['options'])) {
      foreach ($this->config['options'] as $key => $value) {
        $dsn_parts[] = $key . '=' . $value;
      }
    }

    return 'pgsql:' . implode(';', $dsn_parts);
  }

  /**
   * Build PDO options array.
   * {@inheritdoc}
   *
   * @return array
   *   PDO options.
   */
  protected function buildPdoOptions()
  {
    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_TIMEOUT => 30,
      \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return $options;
  }

  /**
   * Check if a table exists.
   * {@inheritdoc}
   *
   * @param string $table_name
   *   The table name (unquoted).
   * @param string $schema_name
   *   The schema name (defaults to 'public').
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if the table exists.
   */
  public function tableExists($table_name, $schema_name = 'public')
  {
    try {
      $sql = "SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = ? 
        AND table_name = ?
      )";
      $stmt = $this->executePrepared($sql, [$schema_name, $table_name]);
      return (bool) $stmt->fetchColumn();
    } catch (\Exception $e) {
      $this->logger->warning('Error checking if table exists @table in schema @schema: @message', [
        '@table' => $table_name,
        '@schema' => $schema_name,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Test the database connection.
   * {@inheritdoc}
   *
   * @return array
   *   Array with connection test results.
   *   {@inheritdoc}
   *
   * @throws \Exception
   *   If connection test fails.
   */
  public function testConnection()
  {
    try {
      $connection = $this->connect();

      // Test with a simple query.
      $stmt = $connection->query('SELECT version()');
      $version = $stmt->fetchColumn();

      if (empty($version)) {
        return [
          'success' => false,
          'error' => 'Connection test failed: Could not retrieve database version.',
          'database' => $this->config['database'] ?? '',
          'version' => '',
        ];
      }

      return [
        'success' => true,
        'database' => $this->config['database'] ?? '',
        'version' => $version,
        'host' => $this->config['host'] ?? '',
        'port' => $this->config['port'] ?? 5432,
      ];
    } catch (\Exception $e) {
      $this->logger->error('Connection test failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'success' => false,
        'error' => $e->getMessage(),
        'database' => $this->config['database'] ?? '',
        'version' => '',
      ];
    }
  }

  /**
   * Execute a query with debugging.
   */
  public function executeQuery($sql, array $params = [])
  {
    $start_time = microtime(true);
    try {
      $connection = $this->connect();

      if (empty($params)) {
        $result = $connection->query($sql);
      } else {
        $stmt = $connection->prepare($sql);
        $stmt->execute($params);
        $result = $stmt;
      }

      $elapsed = microtime(true) - $start_time;

      // Log slow queries.
      if ($elapsed > 1.0) {
        $this->logger->warning('SLOW QUERY (@seconds seconds): @sql', [
          '@seconds' => round($elapsed, 2),
          '@sql' => $sql,
        ]);
      }

      return $result;
    } catch (\PDOException $e) {
      $elapsed = microtime(true) - $start_time;
      $this->logger->error('Query failed after @seconds seconds: @sql - Error: @error', [
        '@seconds' => round($elapsed, 2),
        '@sql' => $sql,
        '@error' => $e->getMessage(),
      ]);
      throw new \Exception('Query execution failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Execute a prepared statement.
   * {@inheritdoc}
   *
   * @param string $sql
   *   The SQL query with placeholders.
   * @param array $params
   *   Query parameters.
   *   {@inheritdoc}.
   *
   * @return \PDOStatement
   *   The executed statement.
   *   {@inheritdoc}
   *
   * @throws \Exception
   *   If statement execution fails.
   */
  public function executePrepared($sql, array $params = [])
  {
    try {
      $connection = $this->connect();
      $stmt = $connection->prepare($sql);
      $stmt->execute($params);
      return $stmt;
    } catch (\PDOException $e) {
      $this->logger->error('Prepared statement execution failed: @message. SQL: @sql', [
        '@message' => $e->getMessage(),
        '@sql' => $sql,
      ]);
      // FIX: Cast string code to integer.
      throw new \Exception('Prepared statement execution failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Begin a transaction.
   * {@inheritdoc}
   *
   * @return bool
   *   true on success.
   *   {@inheritdoc}
   *
   * @throws \Exception
   *   If transaction cannot be started.
   */
  public function beginTransaction()
  {
    try {
      $connection = $this->connect();
      return $connection->beginTransaction();
    } catch (\PDOException $e) {
      $this->logger->error('Failed to begin transaction: @message', [
        '@message' => $e->getMessage(),
      ]);
      // FIX: Cast string code to integer.
      throw new \Exception('Failed to begin transaction: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Commit a transaction.
   * {@inheritdoc}
   *
   * @return bool
   *   true on success.
   *   {@inheritdoc}
   *
   * @throws \Exception
   *   If transaction cannot be committed.
   */
  public function commit()
  {
    try {
      if ($this->connection) {
        return $this->connection->commit();
      }
      return false;
    } catch (\PDOException $e) {
      $this->logger->error('Failed to commit transaction: @message', [
        '@message' => $e->getMessage(),
      ]);
      // FIX: Cast PDOException code (string) to integer for Exception constructor.
      throw new \Exception('Failed to commit transaction: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Rollback a transaction.
   * {@inheritdoc}
   *
   * @return bool
   *   true on success.
   *   {@inheritdoc}
   *
   * @throws \Exception
   *   If transaction cannot be rolled back.
   */
  public function rollback()
  {
    try {
      if ($this->connection) {
        return $this->connection->rollback();
      }
      return false;
    } catch (\PDOException $e) {
      $this->logger->error('Failed to rollback transaction: @message', [
        '@message' => $e->getMessage(),
      ]);
      // FIX: Cast PDOException code (string) to integer for Exception constructor.
      throw new \Exception('Failed to rollback transaction: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Get table columns.
   * {@inheritdoc}
   *
   * @param string $table_name
   *   The table name (unquoted).
   *   {@inheritdoc}.
   *
   * @return array
   *   Array of column names.
   */
  public function getTableColumns($table_name)
  {
    try {
      $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position";
      $stmt = $this->executePrepared($sql, [$table_name]);
      return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Exception $e) {
      $this->logger->warning('Error getting table columns for @table: @message', [
        '@table' => $table_name,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Escapes a string for use in LIKE patterns.
   * {@inheritdoc}
   *
   * @param string $string
   *   The string to escape.
   *   {@inheritdoc}.
   *
   * @return string
   *   The escaped string.
   */
  public function escapeLikePattern($string)
  {
    // Escape PostgreSQL LIKE pattern special characters.
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $string);
  }

  /**
   * Check if an extension exists.
   * {@inheritdoc}
   *
   * @param string $extension_name
   *   The extension name.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if extension is installed.
   */
  public function extensionExists($extension_name)
  {
    try {
      $sql = "SELECT 1 FROM pg_extension WHERE extname = ?";
      $stmt = $this->executePrepared($sql, [$extension_name]);
      return $stmt->fetchColumn() !== false;
    } catch (\Exception $e) {
      $this->logger->warning('Error checking if extension exists: @message', [
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Get the last insert ID.
   * {@inheritdoc}
   *
   * @param string $sequence_name
   *   Optional sequence name.
   *   {@inheritdoc}.
   *
   * @return string
   *   The last insert ID.
   */
  public function lastInsertId($sequence_name = null)
  {
    $connection = $this->connect();
    return $connection->lastInsertId($sequence_name);
  }

  /**
   * Quote a string for safe SQL usage.
   * {@inheritdoc}
   *
   * @param string $string
   *   The string to quote.
   *   {@inheritdoc}.
   *
   * @return string
   *   The quoted string.
   */
  public function quote($string)
  {
    $connection = $this->connect();
    return $connection->quote($string);
  }

  /**
   * Quotes a table name for safe SQL usage.
   * {@inheritdoc}
   *
   * @param string $table_name
   *   The table name to quote.
   *   {@inheritdoc}.
   *
   * @return string
   *   The properly quoted table name.
   */
  public function quoteTableName($table_name)
  {
    // Remove any existing quotes and validate the name.
    $table_name = trim($table_name, '"');

    // Basic validation - only allow alphanumeric and underscores.
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name)) {
      throw new \InvalidArgumentException("Invalid table name: {$table_name}");
    }

    // Return properly quoted identifier for PostgreSQL.
    return '"' . $table_name . '"';
  }

  /**
   * Quotes a column name for safe SQL usage.
   * {@inheritdoc}
   *
   * @param string $column_name
   *   The column name to quote.
   *   {@inheritdoc}.
   *
   * @return string
   *   The properly quoted column name.
   */
  public function quoteColumnName($column_name)
  {
    // Remove any existing quotes and validate the name.
    $column_name = trim($column_name, '"');

    // Basic validation - only allow alphanumeric and underscores.
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column_name)) {
      throw new \InvalidArgumentException("Invalid column name: {$column_name}");
    }

    // Return properly quoted identifier for PostgreSQL.
    return '"' . $column_name . '"';
  }

  /**
   * Quotes an index name for safe SQL usage.
   * {@inheritdoc}
   *
   * @param string $index_name
   *   The index name to quote.
   *   {@inheritdoc}.
   *
   * @return string
   *   The properly quoted index name.
   */
  public function quoteIndexName($index_name)
  {
    // Remove any existing quotes and validate the name.
    $index_name = trim($index_name, '"');

    // Basic validation - only allow alphanumeric and underscores.
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $index_name)) {
      throw new \InvalidArgumentException("Invalid index name: {$index_name}");
    }

    // Return properly quoted identifier for PostgreSQL.
    return '"' . $index_name . '"';
  }

  /**
   * Validates an identifier without quoting it.
   * {@inheritdoc}
   * Used for metadata queries where unquoted names are needed.
   * {@inheritdoc}
   *
   * @param string $identifier
   *   The identifier to validate.
   * @param string $type
   *   The type of identifier (for error messages).
   *   {@inheritdoc}.
   *
   * @return string
   *   The validated but unquoted identifier.
   *   {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   If the identifier is invalid.
   */
  public function validateIdentifier($identifier, $type = 'identifier')
  {
    // Remove any existing quotes and validate the name.
    $identifier = trim($identifier, '"');

    // Basic validation - only allow alphanumeric and underscores.
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
      throw new \InvalidArgumentException("Invalid {$type}: {$identifier}");
    }

    // Return unquoted but validated identifier for metadata queries.
    return $identifier;
  }

  /**
   * Executes a search query - FOLLOWS search_api_db PATTERNS.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   *   {@inheritdoc}.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The search results.
   *   {@inheritdoc}
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the search fails.
   */
  public function search(QueryInterface $query)
  {
    try {
      $index = $query->getIndex();
      $backend = $index->getServerInstance()->getBackend();
      $backend_config = $backend->getConfiguration();

      // Initialize field mapper with backend configuration.
      $field_mapper = new FieldMapper($backend_config);

      // Determine which query builder to use based on backend configuration.
      $query_builder = $this->createQueryBuilder($field_mapper, $backend_config);

      // Build the SQL query.
      $query_info = $query_builder->buildSearchQuery($query);
      $sql = $query_info['sql'];
      $params = $query_info['params'];
      $results = $query->getResults();

      // Handle result count first.
      $skip_count = $query->getOption('skip result count');
      $count = null;

      if (!$skip_count) {
        try {
          // Try to build count query, fall back to result count if method doesn't exist.
          if (method_exists($query_builder, 'buildCountQuery')) {
            $count_query_info = $query_builder->buildCountQuery($query);
            $count_stmt = $this->executePrepared($count_query_info['sql'], $count_query_info['params']);
            $count = (int) $count_stmt->fetchColumn();
          } else {
            // Execute main query to get count.
            $stmt = $this->executePrepared($sql, $params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $count = count($rows);
          }
          $results->setResultCount($count);
        } catch (\Exception $e) {
          $this->logger->warning('Count query failed: @message', [
            '@message' => $e->getMessage(),
          ]);
          // Continue without count.
        }
      }

      // Execute main query if we have results or skip_count is true.
      if ($skip_count || $count) {
        // Execute the main query.
        $stmt = $this->executePrepared($sql, $params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $indexed_fields = $index->getFields(true);
        $retrieved_field_names = $query->getOption('search_api_retrieved_field_values', []);

        // Process each result row (like search_api_db)
        foreach ($rows as $row) {
          $item_id = $row['search_api_id'];
          $relevance = $row['search_api_relevance'] ?? 1.0;
          $item = $this->getFieldsHelper()->createItem($index, $item_id);
          $item->setScore($relevance);
          $this->extractRetrievedFieldValuesWhereAvailable($row, $indexed_fields, $retrieved_field_names, $item);
          $results->addResultItem($item);
        }

        // Set result count for skip_count case (like search_api_db)
        if ($skip_count && !empty($rows)) {
          $results->setResultCount(1);
        }
      }

      return $results;
    } catch (\PDOException $e) {
      if ($query instanceof RefinableCacheableDependencyInterface) {
        $query->mergeCacheMaxAge(0);
      }
      throw new SearchApiException('A database exception occurred while searching.', $e->getCode(), $e);
    } catch (\Exception $e) {
      $this->logger->error('Search execution failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException('Search execution failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Extract retrieved field values where available (like search_api_db).
   * {@inheritdoc}
   *
   * @param object $row
   *   The database row.
   * @param array $indexed_fields
   *   The indexed fields.
   * @param array $retrieved_field_names
   *   The retrieved field names.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to populate.
   */
  protected function extractRetrievedFieldValuesWhereAvailable(
      $row,
      array $indexed_fields,
      array $retrieved_field_names,
      $item
  ) {
    // Convert row array to object if needed.
    if (is_array($row)) {
      $row = (object) $row;
    }

    foreach ($indexed_fields as $field_id => $field) {
      // Skip if field not in retrieved fields and retrieved fields are specified.
      if (!empty($retrieved_field_names) && !in_array($field_id, $retrieved_field_names)) {
        continue;
      }

      // Skip if field not in row.
      if (!isset($row->{$field_id})) {
        continue;
      }

      try {
        $field_value = $this->processFieldValue($row->{$field_id}, $field->getType());
        $item_field = $item->getField($field_id);
        if ($item_field) {
          $item_field->setValues([$field_value]);
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed to set field @field on item @item: @error', [
          '@field' => $field_id,
          '@item' => $item->getId(),
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Handle serialization - exclude PDO connection and logger.
   * {@inheritdoc}
   *
   * @return array
   *   Properties to serialize.
   */
  public function __sleep(): array
  {
    // Exclude non-serializable resources from serialization.
    $properties = get_object_vars($this);
    unset($properties['connection']);
    unset($properties['logger']);
    return array_keys($properties);
  }

  /**
   * Handle unserialization - recreate logger and reset connection.
   */
  public function __wakeup(): void
  {
    // Reset the connection so it will be recreated when needed.
    $this->connection = null;

    // Recreate the logger service using the service container.
    $this->logger = \Drupal::logger('search_api_postgresql');
  }

  /**
   * Get the fields helper service.
   * {@inheritdoc}
   *
   * @return \Drupal\search_api\Utility\FieldsHelperInterface
   *   The fields helper.
   */
  protected function getFieldsHelper()
  {
    return \Drupal::service('search_api.fields_helper');
  }

  /**
   * Process field value based on field type.
   * {@inheritdoc}
   *
   * @param mixed $value
   *   The raw field value from database.
   * @param string $field_type
   *   The Search API field type.
   *   {@inheritdoc}.
   *
   * @return mixed
   *   The processed field value.
   */
  protected function processFieldValue($value, $field_type)
  {
    if ($value === null) {
      return null;
    }

    switch ($field_type) {
      case 'boolean':
          return (bool) $value;

      case 'integer':
          return (int) $value;

      case 'decimal':
          return (float) $value;

      case 'date':
        // Convert timestamp to proper format if needed.
        if (is_numeric($value)) {
          return (int) $value;
        }
          return strtotime($value);

      case 'text':
      case 'string':
      case 'postgresql_fulltext':
      default:
          return (string) $value;
    }
  }

  /**
   * Creates the appropriate query builder based on backend configuration.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\PostgreSQL\FieldMapper $field_mapper
   *   The field mapper.
   * @param array $backend_config
   *   The backend configuration.
   *   {@inheritdoc}.
   *
   * @return \Drupal\search_api_postgresql\PostgreSQL\QueryBuilder
   *   The query builder instance.
   */
  protected function createQueryBuilder(FieldMapper $field_mapper, array $backend_config)
  {
    // Check if vector search is enabled.
    $ai_config = $backend_config['ai_embeddings'] ?? [];
    $vector_enabled = !empty($ai_config['enabled']);

    if ($vector_enabled) {
      // Determine which vector query builder to use.
      $provider = $ai_config['provider'] ?? 'openai';

      switch ($provider) {
        case 'azure':
          // Try to get Azure embedding service.
          try {
            $embedding_service = \Drupal::getContainer()->get('search_api_postgresql.azure_embedding');
            if (class_exists('Drupal\search_api_postgresql\PostgreSQL\AzureVectorQueryBuilder')) {
              return new AzureVectorQueryBuilder(
                  $this,
                  $field_mapper,
                  $backend_config,
                  $embedding_service
              );
            }
          } catch (\Exception $e) {
            $this->logger->warning('Azure embedding service not available, falling back to standard search: @message', [
              '@message' => $e->getMessage(),
            ]);
          }
            break;

        default:
          // Try to get general embedding service.
          try {
            $embedding_service = \Drupal::getContainer()->get('search_api_postgresql.embedding');

            // Check if enhanced query builder is available.
            if (class_exists('Drupal\search_api_postgresql\PostgreSQL\EnhancedVectorQueryBuilder')) {
              return new EnhancedVectorQueryBuilder(
                  $this,
                  $field_mapper,
                  $backend_config,
                  $embedding_service
              );
            } elseif (class_exists('Drupal\search_api_postgresql\PostgreSQL\VectorQueryBuilder')) {
              return new VectorQueryBuilder(
                  $this,
                  $field_mapper,
                  $backend_config,
                  $embedding_service
              );
            }
          } catch (\Exception $e) {
            $this->logger->warning('Embedding service not available, falling back to standard search: @message', [
              '@message' => $e->getMessage(),
            ]);
          }
            break;
      }
    }

    // Fall back to standard query builder.
    if (class_exists('Drupal\search_api_postgresql\PostgreSQL\QueryBuilder')) {
      return new QueryBuilder(
          $this,
          $field_mapper,
          $backend_config
      );
    }

    // Throw an exception if no query builder is available.
    throw new SearchApiException('No query builder class found. Please ensure the module is properly installed.');
  }

  /**
   * Get connection information.
   * {@inheritdoc}
   *
   * @return array
   *   Connection information.
   */
  public function getConnectionInfo()
  {
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
  public function disconnect()
  {
    $this->connection = null;
  }

  /**
   * Destructor.
   */
  public function __destruct()
  {
    $this->disconnect();
  }
}
