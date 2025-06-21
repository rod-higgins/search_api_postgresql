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
    
    try {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();

      // Check if it's a PostgreSQL backend
      if ($backend->getPluginId() !== 'postgresql') {
        $errors[] = 'Server is not using the PostgreSQL backend.';
        return ['errors' => $errors, 'warnings' => $warnings];
      }
    } catch (\Exception $e) {
      $errors[] = 'Unable to validate server configuration: ' . $e->getMessage();
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    // Validate database connection
    $db_validation = $this->validateDatabaseConnection($config);
    $errors = array_merge($errors, $db_validation['errors']);
    $warnings = array_merge($warnings, $db_validation['warnings']);

    // Validate key storage
    $key_validation = $this->validateKeyStorage($config);
    $errors = array_merge($errors, $key_validation['errors']);
    $warnings = array_merge($warnings, $key_validation['warnings']);

    // Validate AI embeddings configuration
    if ($this->isAiEmbeddingsEnabled($config)) {
      $ai_validation = $this->validateAiEmbeddingsConfiguration($config);
      $errors = array_merge($errors, $ai_validation['errors']);
      $warnings = array_merge($warnings, $ai_validation['warnings']);
    }

    // Validate vector search configuration
    if ($this->isVectorSearchEnabled($config)) {
      $vector_validation = $this->validateVectorSearchConfiguration($config);
      $errors = array_merge($errors, $vector_validation['errors']);
      $warnings = array_merge($warnings, $vector_validation['warnings']);
    }

    return ['errors' => $errors, 'warnings' => $warnings];
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

    // Validate password configuration - passwords are optional for development
    $password_key = $connection['password_key'] ?? '';
    $direct_password = $connection['password'] ?? '';
    
    if (empty($password_key) && empty($direct_password)) {
      $warnings[] = 'No database password configured. This may be acceptable for development environments (like Lando with trust authentication) but is not recommended for production.';
    }
    
    // If a password key is specified, validate it exists
    if (!empty($password_key)) {
      $key_validation = $this->validateKey($password_key, 'Database password');
      $errors = array_merge($errors, $key_validation['errors']);
      $warnings = array_merge($warnings, $key_validation['warnings']);
    }

    // Validate port
    $port = $connection['port'] ?? 0;
    if ($port < 1 || $port > 65535) {
      $errors[] = 'Database port must be between 1 and 65535.';
    }

    // Check SSL configuration for production
    $ssl_mode = $connection['ssl_mode'] ?? 'require';
    if (!in_array($ssl_mode, ['require', 'verify-ca', 'verify-full']) && empty($direct_password) && empty($password_key)) {
      $warnings[] = 'Using weak SSL mode without password authentication. Consider using "require", "verify-ca", or "verify-full" for better security.';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates key storage configuration.
   */
  protected function validateKeyStorage($config) {
    $errors = [];
    $warnings = [];

    // Check database password key (if specified)
    $password_key = $config['connection']['password_key'] ?? '';
    if (!empty($password_key)) {
      $key_validation = $this->validateKey($password_key, 'Database password');
      $errors = array_merge($errors, $key_validation['errors']);
      $warnings = array_merge($warnings, $key_validation['warnings']);
    }

    // Check AI API keys if enabled
    if ($this->isAiEmbeddingsEnabled($config)) {
      $ai_config = $config['ai_embeddings'] ?? [];
      
      // Check Azure OpenAI API key
      if (!empty($ai_config['azure']['api_key_key'])) {
        $key_validation = $this->validateKey($ai_config['azure']['api_key_key'], 'Azure OpenAI API');
        $errors = array_merge($errors, $key_validation['errors']);
        $warnings = array_merge($warnings, $key_validation['warnings']);
      } elseif (empty($ai_config['azure']['api_key'])) {
        $warnings[] = 'Azure OpenAI API key not configured. Use Key module for secure storage.';
      }

      // Check OpenAI API key
      if (!empty($ai_config['openai']['api_key_key'])) {
        $key_validation = $this->validateKey($ai_config['openai']['api_key_key'], 'OpenAI API');
        $errors = array_merge($errors, $key_validation['errors']);
        $warnings = array_merge($warnings, $key_validation['warnings']);
      } elseif (empty($ai_config['openai']['api_key'])) {
        $warnings[] = 'OpenAI API key not configured. Use Key module for secure storage.';
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates a specific key.
   */
  protected function validateKey($key_name, $type) {
    $errors = [];
    $warnings = [];

    if (!$this->keyRepository) {
      $errors[] = "Key module not available for {$type} key validation.";
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    try {
      $key = $this->keyRepository->getKey($key_name);
      if (!$key) {
        $errors[] = "{$type} key '{$key_name}' not found.";
      } else {
        // Check if key has a value
        $key_value = $key->getKeyValue();
        if (empty($key_value)) {
          $errors[] = "{$type} key '{$key_name}' exists but has no value.";
        }
      }
    } catch (\Exception $e) {
      $errors[] = "Error accessing {$type} key '{$key_name}': " . $e->getMessage();
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates AI embeddings configuration.
   */
  protected function validateAiEmbeddingsConfiguration($config) {
    $errors = [];
    $warnings = [];

    $ai_config = $config['ai_embeddings'] ?? [];
    $provider = $ai_config['provider'] ?? '';

    if (empty($provider)) {
      $errors[] = 'AI provider must be selected when embeddings are enabled.';
      return ['errors' => $errors, 'warnings' => $warnings];
    }

    // Validate provider-specific configuration
    if ($provider === 'azure') {
      $azure_config = $ai_config['azure'] ?? [];
      
      if (empty($azure_config['endpoint'])) {
        $errors[] = 'Azure OpenAI endpoint is required when Azure provider is selected.';
      }

      if (empty($azure_config['deployment_name'])) {
        $errors[] = 'Azure OpenAI deployment name is required when Azure provider is selected.';
      }

      if (empty($azure_config['api_key']) && empty($azure_config['api_key_key'])) {
        $errors[] = 'Azure OpenAI API key is required when Azure provider is selected.';
      }

      // Validate model configuration
      $model = $azure_config['model'] ?? 'text-embedding-3-small';
      $valid_models = ['text-embedding-ada-002', 'text-embedding-3-small', 'text-embedding-3-large'];
      if (!in_array($model, $valid_models)) {
        $warnings[] = "Unknown embedding model '{$model}'. Supported models: " . implode(', ', $valid_models);
      }
    } elseif ($provider === 'openai') {
      $openai_config = $ai_config['openai'] ?? [];
      
      if (empty($openai_config['api_key']) && empty($openai_config['api_key_key'])) {
        $errors[] = 'OpenAI API key is required when OpenAI provider is selected.';
      }

      // Validate model configuration
      $model = $openai_config['model'] ?? 'text-embedding-3-small';
      $valid_models = ['text-embedding-ada-002', 'text-embedding-3-small', 'text-embedding-3-large'];
      if (!in_array($model, $valid_models)) {
        $warnings[] = "Unknown embedding model '{$model}'. Supported models: " . implode(', ', $valid_models);
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates vector search configuration.
   */
  protected function validateVectorSearchConfiguration($config) {
    $errors = [];
    $warnings = [];

    $vector_config = $config['vector_index'] ?? [];

    // Validate distance method
    $distance = $vector_config['distance'] ?? 'cosine';
    if (!in_array($distance, ['cosine', 'l2', 'inner_product'])) {
      $errors[] = 'Vector distance method must be one of: cosine, l2, inner_product.';
    }

    // Validate index method
    $method = $vector_config['method'] ?? 'ivfflat';
    if (!in_array($method, ['ivfflat', 'hnsw'])) {
      $errors[] = 'Vector index method must be either "ivfflat" or "hnsw".';
    }

    // Validate method-specific parameters
    if ($method === 'ivfflat') {
      $lists = $vector_config['lists'] ?? 100;
      if ($lists < 1 || $lists > 10000) {
        $warnings[] = 'IVFFlat lists parameter should be between 1 and 10000.';
      }
      
      $probes = $vector_config['probes'] ?? 10;
      if ($probes < 1 || $probes > $lists) {
        $warnings[] = 'IVFFlat probes parameter should be between 1 and the number of lists.';
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Checks if AI embeddings are enabled.
   */
  protected function isAiEmbeddingsEnabled($config) {
    return !empty($config['ai_embeddings']['enabled']);
  }

  /**
   * Checks if vector search is enabled.
   */
  protected function isVectorSearchEnabled($config) {
    // Vector search is available when AI embeddings are enabled
    return $this->isAiEmbeddingsEnabled($config);
  }

  /**
   * Check if AI features are enabled (NEW METHOD).
   */
  private function isAiFeaturesEnabled(array $config) {
    return !empty($config['ai_features']['enabled']);
  }

/**
 * Validate AI features configuration (REPLACES OLD METHODS).
 */
private function validateAiFeaturesConfiguration(array $config) {
  $errors = [];
  $warnings = [];
  
  $ai_config = $config['ai_features'] ?? [];
  $provider = $ai_config['provider'] ?? '';
  
  if (empty($provider)) {
    $errors[] = 'AI provider must be selected when AI features are enabled.';
    return ['errors' => $errors, 'warnings' => $warnings];
  }

  // Validate provider-specific configuration
  switch ($provider) {
    case 'openai':
      $provider_config = $ai_config['openai'] ?? [];
      if (empty($provider_config['api_key_name']) && empty($provider_config['api_key'])) {
        $errors[] = 'OpenAI API key is required.';
      }
      break;

    case 'azure_openai':
      $provider_config = $ai_config['azure_openai'] ?? [];
      if (empty($provider_config['endpoint'])) {
        $errors[] = 'Azure OpenAI endpoint is required.';
      }
      if (empty($provider_config['deployment_name'])) {
        $errors[] = 'Azure OpenAI deployment name is required.';
      }
      if (empty($provider_config['api_key_name']) && empty($provider_config['api_key'])) {
        $errors[] = 'Azure OpenAI API key is required.';
      }
      break;

    case 'huggingface':
      $provider_config = $ai_config['huggingface'] ?? [];
      if (empty($provider_config['api_key_name']) && empty($provider_config['api_key'])) {
        $errors[] = 'Hugging Face API key is required.';
      }
      break;

    case 'local':
      $provider_config = $ai_config['local'] ?? [];
      if (empty($provider_config['model_path'])) {
        $errors[] = 'Local model path is required.';
      }
      break;

      default:
        $errors[] = "Unknown AI provider: {$provider}";
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Runs comprehensive system health checks.
   *
   * @return array
   *   Array of health check results.
   */
  public function runSystemHealthChecks() {
    $checks = [];
    
    // Get all PostgreSQL servers
    $servers = $this->entityTypeManager->getStorage('search_api_server')->loadMultiple();
    $postgresql_servers = array_filter($servers, function($server) {
      return in_array($server->getBackend()->getPluginId(), ['postgresql']);
    });

    if (empty($postgresql_servers)) {
      $checks['no_servers'] = [
        'status' => FALSE,
        'message' => 'No PostgreSQL servers configured',
        'details' => 'Create a PostgreSQL server to use this module',
      ];
      return $checks;
    }

    foreach ($postgresql_servers as $server) {
      $server_id = $server->id();
      
      // Test database connection
      $checks["db_connection_{$server_id}"] = $this->testDatabaseConnection($server);
      
      // Test key access
      $checks["key_access_{$server_id}"] = $this->testKeyAccess($server);
      
      // Test pgvector extension
      $checks["pgvector_{$server_id}"] = $this->testPgVectorExtension($server);
      
      // Test AI service if enabled
      if ($this->isAiEmbeddingsEnabled($server->getBackend()->getConfiguration())) {
        $checks["ai_service_{$server_id}"] = $this->testAiService($server);
      }
    }

    return $checks;
  }

  /**
   * Checks server health by running comprehensive tests.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server to check.
   *
   * @return array
   *   Array containing health check results with 'overall' status.
   */
  public function checkServerHealth(ServerInterface $server) {
    $tests = [];
    
    // Test database connection
    $tests['database_connection'] = $this->testDatabaseConnection($server);
    
    // Test key access
    $tests['key_access'] = $this->testKeyAccess($server);
    
    // Test pgvector extension
    $tests['pgvector_extension'] = $this->testPgVectorExtension($server);
    
    // Test AI service if enabled
    $backend = $server->getBackend();
    $config = $backend->getConfiguration();
    if ($this->isAiEmbeddingsEnabled($config)) {
      $tests['ai_service'] = $this->testAiService($server);
    }
    
    // Determine overall health status
    $overall_health = TRUE;
    $failed_tests = [];
    
    foreach ($tests as $test_name => $result) {
      if (!$result['success']) {
        $overall_health = FALSE;
        $failed_tests[] = $test_name;
      }
    }
    
    return [
      'overall' => $overall_health,
      'tests' => $tests,
      'failed_tests' => $failed_tests,
      'message' => $overall_health ? 
        'All health checks passed' : 
        sprintf('Failed tests: %s', implode(', ', $failed_tests))
    ];
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
   * Tests database connection.
   */
  protected function testDatabaseConnection(ServerInterface $server) {
    try {
      $backend = $server->getBackend();
      
      // Get password using the backend's method
      $config = $backend->getConfiguration();
      $connection_config = $config['connection'];
      
      // Use the backend's password resolution method
      if (method_exists($backend, 'getDatabasePassword')) {
        $reflection = new \ReflectionClass($backend);
        $method = $reflection->getMethod('getDatabasePassword');
        $method->setAccessible(TRUE);
        $connection_config['password'] = $method->invoke($backend);
      }

      $connector = new PostgreSQLConnector($connection_config, $this->logger);
      $connector->testConnection();

      $password_info = '';
      if (!empty($connection_config['password_key'])) {
        $password_info = ' (using key: ' . $connection_config['password_key'] . ')';
      }
      elseif (!empty($connection_config['password'])) {
        $password_info = ' (using direct password)';
      }
      else {
        $password_info = ' (passwordless connection)';
      }

      return [
        'success' => TRUE,
        'message' => 'Database connection successful',
        'details' => 'Connected to ' . $connection_config['host'] . ':' . $connection_config['port'] . $password_info,
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

      // Test database password key (if configured)
      $password_key = $config['connection']['password_key'] ?? '';
      if (!empty($password_key)) {
        if (method_exists($backend, 'getDatabasePassword')) {
          $reflection = new \ReflectionClass($backend);
          $method = $reflection->getMethod('getDatabasePassword');
          $method->setAccessible(TRUE);
          $password = $method->invoke($backend);
          
          if (empty($password)) {
            throw new \Exception('Database password key returned empty value');
          }
        }
      }

      // Test AI API key if enabled
      if ($this->isAiEmbeddingsEnabled($config)) {
        $method_name = method_exists($backend, 'getAzureApiKey') ? 'getAzureApiKey' : 'getAzureEmbeddingApiKey';
        if (method_exists($backend, $method_name)) {
          $reflection = new \ReflectionClass($backend);
          $method = $reflection->getMethod($method_name);
          $method->setAccessible(TRUE);
          $api_key = $method->invoke($backend);
          
          if (empty($api_key)) {
            throw new \Exception('AI API key returned empty value');
          }
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
      
      $available = $this->checkPgVectorExtension($connector);
      
      return [
        'success' => $available,
        'message' => $available ? 'pgvector extension is available' : 'pgvector extension not found',
        'details' => $available ? 'Vector search capabilities enabled' : 'Install pgvector extension for vector search',
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
      
      // This would test the actual AI service connection
      // For now, just validate configuration
      $ai_config = $config['ai_embeddings']['azure_ai'] ?? [];
      
      if (empty($ai_config['endpoint']) || empty($ai_config['deployment_name'])) {
        throw new \Exception('AI service configuration incomplete');
      }

      return [
        'success' => TRUE,
        'message' => 'AI service configuration valid',
        'details' => 'Configuration appears correct for ' . $ai_config['endpoint'],
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'AI service test failed',
        'details' => $e->getMessage(),
      ];
    }
  }

}