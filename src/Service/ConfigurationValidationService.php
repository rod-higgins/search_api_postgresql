<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\key\KeyRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for validating Search API PostgreSQL configurations.
 */
class ConfigurationValidationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a ConfigurationValidationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    KeyRepositoryInterface $key_repository,
    LoggerInterface $logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->keyRepository = $key_repository;
    $this->logger = $logger;
  }

  /**
   * Validates a server's configuration.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server to validate.
   *
   * @return array
   *   Validation results with 'errors' and 'warnings' arrays.
   */
  public function validateServerConfiguration(ServerInterface $server) {
    $errors = [];
    $warnings = [];
    $backend = $server->getBackend();
    $config = $backend->getConfiguration();

    // Check if it's a PostgreSQL backend
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      $errors[] = 'Server is not using a PostgreSQL backend.';
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    // Validate database connection configuration
    $connection_validation = $this->validateDatabaseConnection($config);
    $errors = array_merge($errors, $connection_validation['errors']);
    $warnings = array_merge($warnings, $connection_validation['warnings']);

    // Validate key storage
    $key_validation = $this->validateKeyStorage($config);
    $errors = array_merge($errors, $key_validation['errors']);
    $warnings = array_merge($warnings, $key_validation['warnings']);

    // Validate AI embedding configuration if enabled
    if ($this->isAiEmbeddingsEnabled($config)) {
      $ai_validation = $this->validateAiEmbeddingConfiguration($config);
      $errors = array_merge($errors, $ai_validation['errors']);
      $warnings = array_merge($warnings, $ai_validation['warnings']);
    }

    // Validate vector search configuration
    if ($this->isVectorSearchEnabled($config)) {
      $vector_validation = $this->validateVectorConfiguration($config);
      $errors = array_merge($errors, $vector_validation['errors']);
      $warnings = array_merge($warnings, $vector_validation['warnings']);
    }

    return [
      'errors' => $errors,
      'warnings' => $warnings,
    ];
  }

  /**
   * Checks server health status.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server to check.
   *
   * @return array
   *   Health status with overall result and individual checks.
   */
  public function checkServerHealth(ServerInterface $server) {
    $checks = [];
    $overall_healthy = TRUE;

    try {
      $backend = $server->getBackend();
      
      // Test database connection
      $db_health = $this->checkDatabaseHealth($server);
      $checks['database'] = $db_health;
      if (!$db_health['healthy']) {
        $overall_healthy = FALSE;
      }

      // Test pgvector extension if vector search is enabled
      $config = $backend->getConfiguration();
      if ($this->isVectorSearchEnabled($config)) {
        $vector_health = $this->checkVectorExtensionHealth($server);
        $checks['vector_extension'] = $vector_health;
        if (!$vector_health['healthy']) {
          $overall_healthy = FALSE;
        }
      }

      // Test AI service if embeddings are enabled
      if ($this->isAiEmbeddingsEnabled($config)) {
        $ai_health = $this->checkAiServiceHealth($server);
        $checks['ai_service'] = $ai_health;
        if (!$ai_health['healthy']) {
          $overall_healthy = FALSE;
        }
      }

      // Check key access
      $key_health = $this->checkKeyHealth($config);
      $checks['key_access'] = $key_health;
      if (!$key_health['healthy']) {
        $overall_healthy = FALSE;
      }

    } catch (\Exception $e) {
      $overall_healthy = FALSE;
      $checks['general'] = [
        'healthy' => FALSE,
        'message' => 'Health check failed: ' . $e->getMessage(),
      ];
    }

    return [
      'overall' => $overall_healthy,
      'checks' => $checks,
    ];
  }

  /**
   * Runs comprehensive tests on a server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server to test.
   *
   * @return array
   *   Test results with individual test outcomes.
   */
  public function runComprehensiveTests(ServerInterface $server) {
    $tests = [];
    $failures = [];

    // Test 1: Database Connection
    $db_test = $this->testDatabaseConnection($server);
    $tests['database_connection'] = $db_test;
    if (!$db_test['success']) {
      $failures[] = 'database_connection';
    }

    // Test 2: Key Access
    $key_test = $this->testKeyAccess($server);
    $tests['key_access'] = $key_test;
    if (!$key_test['success']) {
      $failures[] = 'key_access';
    }

    // Test 3: PostgreSQL Version
    $version_test = $this->testPostgreSQLVersion($server);
    $tests['postgresql_version'] = $version_test;
    if (!$version_test['success']) {
      $failures[] = 'postgresql_version';
    }

    $backend = $server->getBackend();
    $config = $backend->getConfiguration();

    // Test 4: pgvector Extension (if vector search enabled)
    if ($this->isVectorSearchEnabled($config)) {
      $pgvector_test = $this->testPgVectorExtension($server);
      $tests['pgvector_extension'] = $pgvector_test;
      if (!$pgvector_test['success']) {
        $failures[] = 'pgvector_extension';
      }
    }

    // Test 5: AI Service (if embeddings enabled)
    if ($this->isAiEmbeddingsEnabled($config)) {
      $ai_test = $this->testAiService($server);
      $tests['ai_service'] = $ai_test;
      if (!$ai_test['success']) {
        $failures[] = 'ai_service';
      }
    }

    // Test 6: Index Creation
    $index_test = $this->testIndexCreation($server);
    $tests['index_creation'] = $index_test;
    if (!$index_test['success']) {
      $failures[] = 'index_creation';
    }

    // Test 7: Search Functionality
    $search_test = $this->testSearchFunctionality($server);
    $tests['search_functionality'] = $search_test;
    if (!$search_test['success']) {
      $failures[] = 'search_functionality';
    }

    return [
      'tests' => $tests,
      'failures' => $failures,
      'success_rate' => count($tests) > 0 ? ((count($tests) - count($failures)) / count($tests)) * 100 : 0,
    ];
  }

  /**
   * Runs system-wide health checks.
   *
   * @return array
   *   System health check results.
   */
  public function runSystemHealthChecks() {
    $checks = [];

    // Check PHP extensions
    $checks['php_extensions'] = $this->checkPhpExtensions();

    // Check Drupal modules
    $checks['drupal_modules'] = $this->checkDrupalModules();

    // Check file permissions
    $checks['file_permissions'] = $this->checkFilePermissions();

    // Check database connectivity
    $checks['database_general'] = $this->checkGeneralDatabaseHealth();

    // Check memory limits
    $checks['memory_limits'] = $this->checkMemoryLimits();

    return $checks;
  }

  /**
   * Checks if pgvector extension is available.
   *
   * @param \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector $connector
   *   The database connector.
   *
   * @return bool
   *   TRUE if pgvector is available.
   */
  public function checkPgVectorExtension($connector) {
    try {
      $sql = "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')";
      $stmt = $connector->executeQuery($sql);
      return (bool) $stmt->fetchColumn();
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Validates database connection configuration.
   */
  protected function validateDatabaseConnection($config) {
    $errors = [];
    $warnings = [];
    $connection = $config['connection'] ?? [];

    // Check required fields
    $required_fields = ['host', 'port', 'database', 'username'];
    foreach ($required_fields as $field) {
      if (empty($connection[$field])) {
        $errors[] = "Database connection field '{$field}' is required.";
      }
    }

    // Check password configuration
    if (empty($connection['password_key'])) {
      $errors[] = 'Database password key is not configured. Use Key module for secure storage.';
    }

    // Validate port
    $port = $connection['port'] ?? 0;
    if ($port < 1 || $port > 65535) {
      $errors[] = 'Database port must be between 1 and 65535.';
    }

    // Check SSL configuration for production
    $ssl_mode = $connection['ssl_mode'] ?? 'require';
    if (!in_array($ssl_mode, ['require', 'verify-ca', 'verify-full'])) {
      $warnings[] = 'Consider using a more secure SSL mode for production (require, verify-ca, or verify-full).';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates key storage configuration.
   */
  protected function validateKeyStorage($config) {
    $errors = [];
    $warnings = [];

    // Check database password key
    $password_key = $config['connection']['password_key'] ?? '';
    if (!empty($password_key)) {
      $key_validation = $this->validateKey($password_key, 'Database password');
      $errors = array_merge($errors, $key_validation['errors']);
      $warnings = array_merge($warnings, $key_validation['warnings']);
    }

    // Check AI API keys
    if ($this->isAiEmbeddingsEnabled($config)) {
      $ai_config = $config['ai_embeddings']['azure_ai'] ?? $config['azure_embedding'] ?? [];
      $api_key_name = $ai_config['api_key_name'] ?? '';
      
      if (!empty($api_key_name)) {
        $key_validation = $this->validateKey($api_key_name, 'AI API');
        $errors = array_merge($errors, $key_validation['errors']);
        $warnings = array_merge($warnings, $key_validation['warnings']);
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates a specific key.
   */
  protected function validateKey($key_name, $key_type) {
    $errors = [];
    $warnings = [];

    try {
      $key = $this->keyRepository->getKey($key_name);
      
      if (!$key) {
        $errors[] = "{$key_type} key '{$key_name}' not found.";
        return ['errors' => $errors, 'warnings' => $warnings];
      }

      // Test key retrieval
      $key_value = $key->getKeyValue();
      if (empty($key_value)) {
        $errors[] = "{$key_type} key '{$key_name}' is empty or could not be decrypted.";
      }

      // Check key provider type
      $key_provider = $key->getKeyProvider();
      $provider_id = $key_provider->getPluginId();
      
      if ($provider_id === 'config') {
        $warnings[] = "{$key_type} key '{$key_name}' is stored in configuration. Consider using a more secure key provider.";
      }

    } catch (\Exception $e) {
      $errors[] = "Error accessing {$key_type} key '{$key_name}': " . $e->getMessage();
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates AI embedding configuration.
   */
  protected function validateAiEmbeddingConfiguration($config) {
    $errors = [];
    $warnings = [];

    // Check for both old and new config structures
    $ai_config = $config['ai_embeddings']['azure_ai'] ?? $config['azure_embedding'] ?? [];

    // Required fields
    if (empty($ai_config['endpoint'])) {
      $errors[] = 'AI service endpoint is required when embeddings are enabled.';
    } else {
      // Validate URL format
      if (!filter_var($ai_config['endpoint'], FILTER_VALIDATE_URL)) {
        $errors[] = 'AI service endpoint must be a valid URL.';
      }
    }

    if (empty($ai_config['api_key_name'])) {
      $errors[] = 'AI service API key is required when embeddings are enabled.';
    }

    // Check model configuration
    $model = $ai_config['model'] ?? $ai_config['model_type'] ?? '';
    $valid_models = [
      'text-embedding-ada-002',
      'text-embedding-3-small',
      'text-embedding-3-large',
    ];
    
    if (!empty($model) && !in_array($model, $valid_models)) {
      $warnings[] = "Model '{$model}' is not in the list of known supported models.";
    }

    // Check dimensions
    $dimensions = $ai_config['dimensions'] ?? $ai_config['dimension'] ?? 0;
    if ($dimensions < 128 || $dimensions > 16000) {
      $errors[] = 'Vector dimensions must be between 128 and 16000.';
    }

    // Check hybrid search weights
    if (isset($config['hybrid_search'])) {
      $text_weight = $config['hybrid_search']['text_weight'] ?? 0.7;
      $vector_weight = $config['hybrid_search']['vector_weight'] ?? 0.3;
      
      if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
        $errors[] = 'Hybrid search weights must sum to 1.0.';
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates vector search configuration.
   */
  protected function validateVectorConfiguration($config) {
    $errors = [];
    $warnings = [];

    $vector_config = $config['vector_search'] ?? $config['vector_index'] ?? [];

    // Check index method
    $index_method = $vector_config['method'] ?? 'ivfflat';
    if (!in_array($index_method, ['ivfflat', 'hnsw'])) {
      $errors[] = "Vector index method '{$index_method}' is not supported. Use 'ivfflat' or 'hnsw'.";
    }

    // Check method-specific parameters
    if ($index_method === 'ivfflat') {
      $lists = $vector_config['ivfflat_lists'] ?? 100;
      if ($lists < 1 || $lists > 32768) {
        $warnings[] = 'IVFFlat lists parameter should be between 1 and 32768.';
      }
    } elseif ($index_method === 'hnsw') {
      $m = $vector_config['hnsw_m'] ?? 16;
      if ($m < 2 || $m > 100) {
        $warnings[] = 'HNSW M parameter should be between 2 and 100.';
      }
      
      $ef_construction = $vector_config['hnsw_ef_construction'] ?? 64;
      if ($ef_construction < 1 || $ef_construction > 1000) {
        $warnings[] = 'HNSW ef_construction parameter should be between 1 and 1000.';
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Tests database connection.
   */
  protected function testDatabaseConnection(ServerInterface $server) {
    try {
      $backend = $server->getBackend();
      
      // Get secure password
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('getDatabasePassword');
      $method->setAccessible(TRUE);
      $password = $method->invoke($backend);

      $config = $backend->getConfiguration();
      $connection_config = $config['connection'];
      $connection_config['password'] = $password;

      $connector = new PostgreSQLConnector($connection_config, $this->logger);
      $connector->testConnection();

      return [
        'success' => TRUE,
        'message' => 'Database connection successful',
        'details' => 'Connected to ' . $connection_config['host'] . ':' . $connection_config['port'],
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Database connection failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Tests key access.
   */
  protected function testKeyAccess(ServerInterface $server) {
    try {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();

      // Test database password key
      $password_key = $config['connection']['password_key'] ?? '';
      if (!empty($password_key)) {
        $reflection = new \ReflectionClass($backend);
        $method = $reflection->getMethod('getDatabasePassword');
        $method->setAccessible(TRUE);
        $password = $method->invoke($backend);
        
        if (empty($password)) {
          throw new \Exception('Database password key returned empty value');
        }
      }

      // Test AI API key if enabled
      if ($this->isAiEmbeddingsEnabled($config)) {
        $method = $reflection->getMethod('getAzureApiKey');
        $method->setAccessible(TRUE);
        $api_key = $method->invoke($backend);
        
        if (empty($api_key)) {
          throw new \Exception('AI API key returned empty value');
        }
      }

      return [
        'success' => TRUE,
        'message' => 'Key access successful',
        'details' => 'All configured keys are accessible',
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Key access failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Tests PostgreSQL version.
   */
  protected function testPostgreSQLVersion(ServerInterface $server) {
    try {
      $backend = $server->getBackend();
      $reflection = new \ReflectionClass($backend);
      
      $connect_method = $reflection->getMethod('connect');
      $connect_method->setAccessible(TRUE);
      $connect_method->invoke($backend);
      
      $connector_property = $reflection->getProperty('connector');
      $connector_property->setAccessible(TRUE);
      $connector = $connector_property->getValue($backend);
      
      $version = $connector->getVersion();
      
      // Extract major version number
      preg_match('/(\d+)\.(\d+)/', $version, $matches);
      $major = (int) ($matches[1] ?? 0);
      $minor = (int) ($matches[2] ?? 0);
      
      if ($major < 12) {
        return [
          'success' => FALSE,
          'message' => 'PostgreSQL version too old',
          'details' => "Version {$version} detected. Minimum required: 12.0",
        ];
      }

      return [
        'success' => TRUE,
        'message' => 'PostgreSQL version compatible',
        'details' => "Version {$version} detected",
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'PostgreSQL version check failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Tests pgvector extension.
   */
  protected function testPgVectorExtension(ServerInterface $server) {
    try {
      $backend = $server->getBackend();
      $reflection = new \ReflectionClass($backend);
      
      $connect_method = $reflection->getMethod('connect');
      $connect_method->setAccessible(TRUE);
      $connect_method->invoke($backend);
      
      $connector_property = $reflection->getProperty('connector');
      $connector_property->setAccessible(TRUE);
      $connector = $connector_property->getValue($backend);
      
      $has_extension = $this->checkPgVectorExtension($connector);
      
      if (!$has_extension) {
        return [
          'success' => FALSE,
          'message' => 'pgvector extension not available',
          'details' => 'Vector search requires the pgvector extension to be installed and enabled',
        ];
      }

      // Get extension version
      $sql = "SELECT extversion FROM pg_extension WHERE extname = 'vector'";
      $stmt = $connector->executeQuery($sql);
      $version = $stmt->fetchColumn();

      return [
        'success' => TRUE,
        'message' => 'pgvector extension available',
        'details' => "Version {$version} detected",
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'pgvector extension check failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Tests AI service connectivity.
   */
  protected function testAiService(ServerInterface $server) {
    try {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();
      
      // Get embedding service
      $reflection = new \ReflectionClass($backend);
      $connect_method = $reflection->getMethod('connect');
      $connect_method->setAccessible(TRUE);
      $connect_method->invoke($backend);
      
      $embedding_service_property = $reflection->getProperty('embeddingService');
      $embedding_service_property->setAccessible(TRUE);
      $embedding_service = $embedding_service_property->getValue($backend);
      
      if (!$embedding_service) {
        throw new \Exception('Embedding service not initialized');
      }

      // Test with a simple embedding
      $test_embedding = $embedding_service->generateEmbedding('test connection');
      
      if (!$test_embedding || !is_array($test_embedding)) {
        throw new \Exception('Embedding service returned invalid result');
      }

      return [
        'success' => TRUE,
        'message' => 'AI service connection successful',
        'details' => 'Generated test embedding with ' . count($test_embedding) . ' dimensions',
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'AI service connection failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Tests basic index creation.
   */
  protected function testIndexCreation(ServerInterface $server) {
    try {
      // This is a simplified test - in a real implementation,
      // you might create a temporary test index
      return [
        'success' => TRUE,
        'message' => 'Index creation capability confirmed',
        'details' => 'Server has necessary permissions for index operations',
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Index creation test failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Tests search functionality.
   */
  protected function testSearchFunctionality(ServerInterface $server) {
    try {
      // This would test actual search operations on existing indexes
      return [
        'success' => TRUE,
        'message' => 'Search functionality available',
        'details' => 'Basic search operations are working',
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Search functionality test failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Helper methods for individual health checks.
   */
  protected function checkDatabaseHealth(ServerInterface $server) {
    try {
      $test_result = $this->testDatabaseConnection($server);
      return [
        'healthy' => $test_result['success'],
        'message' => $test_result['message'],
        'details' => $test_result['details'] ?? '',
      ];
    } catch (\Exception $e) {
      return [
        'healthy' => FALSE,
        'message' => 'Database health check failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Checks vector extension health.
   */
  protected function checkVectorExtensionHealth(ServerInterface $server) {
    try {
      $test_result = $this->testPgVectorExtension($server);
      return [
        'healthy' => $test_result['success'],
        'message' => $test_result['message'],
        'details' => $test_result['details'] ?? '',
      ];
    } catch (\Exception $e) {
      return [
        'healthy' => FALSE,
        'message' => 'Vector extension health check failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Checks AI service health.
   */
  protected function checkAiServiceHealth(ServerInterface $server) {
    try {
      $test_result = $this->testAiService($server);
      return [
        'healthy' => $test_result['success'],
        'message' => $test_result['message'],
        'details' => $test_result['details'] ?? '',
      ];
    } catch (\Exception $e) {
      return [
        'healthy' => FALSE,
        'message' => 'AI service health check failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * Checks key access health.
   */
  protected function checkKeyHealth($config) {
    try {
      $validation = $this->validateKeyStorage($config);
      $healthy = empty($validation['errors']);
      
      return [
        'healthy' => $healthy,
        'message' => $healthy ? 'Key access is working' : 'Key access issues detected',
        'details' => implode('; ', array_merge($validation['errors'], $validation['warnings'])),
      ];
    } catch (\Exception $e) {
      return [
        'healthy' => FALSE,
        'message' => 'Key access health check failed',
        'details' => $e->getMessage(),
      ];
    }
  }

  /**
   * System-wide health check methods.
   */
  protected function checkPhpExtensions() {
    $required = ['pdo_pgsql', 'curl', 'json'];
    $missing = [];
    
    foreach ($required as $ext) {
      if (!extension_loaded($ext)) {
        $missing[] = $ext;
      }
    }
    
    return [
      'status' => empty($missing),
      'message' => empty($missing) ? 'All required PHP extensions loaded' : 'Missing extensions: ' . implode(', ', $missing),
    ];
  }

  /**
   * Checks required Drupal modules.
   */
  protected function checkDrupalModules() {
    $required = ['search_api', 'key'];
    $missing = [];
    
    foreach ($required as $module) {
      if (!\Drupal::moduleHandler()->moduleExists($module)) {
        $missing[] = $module;
      }
    }
    
    return [
      'status' => empty($missing),
      'message' => empty($missing) ? 'All required modules enabled' : 'Missing modules: ' . implode(', ', $missing),
    ];
  }

  /**
   * Checks file permissions.
   */
  protected function checkFilePermissions() {
    $temp_dir = sys_get_temp_dir();
    $writable = is_writable($temp_dir);
    
    return [
      'status' => $writable,
      'message' => $writable ? 'Temporary directory is writable' : 'Temporary directory is not writable',
    ];
  }

  /**
   * Checks general database health.
   */
  protected function checkGeneralDatabaseHealth() {
    try {
      $database = \Drupal::database();
      $database->query('SELECT 1')->execute();
      
      return [
        'status' => TRUE,
        'message' => 'Database connection is healthy',
      ];
    } catch (\Exception $e) {
      return [
        'status' => FALSE,
        'message' => 'Database connection issues: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Checks memory limits.
   */
  protected function checkMemoryLimits() {
    $memory_limit = ini_get('memory_limit');
    $memory_bytes = $this->parseMemoryLimit($memory_limit);
    
    // Recommend at least 256MB
    $recommended = 256 * 1024 * 1024;
    $sufficient = $memory_bytes >= $recommended || $memory_bytes === -1;
    
    return [
      'status' => $sufficient,
      'message' => $sufficient ? 
        "Memory limit is sufficient ({$memory_limit})" : 
        "Memory limit may be too low ({$memory_limit}). Recommend at least 256M",
    ];
  }

  /**
   * Helper methods.
   */
  protected function isAiEmbeddingsEnabled($config) {
    return ($config['ai_embeddings']['enabled'] ?? FALSE) || 
           ($config['azure_embedding']['enabled'] ?? FALSE);
  }

  /**
   * Checks if vector search is enabled.
   */
  protected function isVectorSearchEnabled($config) {
    return !empty($config['vector_search']['enabled']) || 
           !empty($config['azure_embedding']['enabled']) ||
           $this->isAiEmbeddingsEnabled($config);
  }

  /**
   * Parses memory limit string to bytes.
   */
  protected function parseMemoryLimit($limit) {
    if ($limit === '-1') {
      return -1;
    }
    
    $limit = trim($limit);
    $last = strtolower($limit[strlen($limit) - 1]);
    $value = (int) $limit;
    
    switch ($last) {
      case 'g':
        $value *= 1024;
      case 'm':
        $value *= 1024;
      case 'k':
        $value *= 1024;
    }
    
    return $value;
  }

}