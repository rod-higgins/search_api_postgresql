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

  // Check if it's a PostgreSQL backend - NOW ONLY ONE BACKEND
  if ($backend->getPluginId() !== 'postgresql') {
    $errors[] = 'Server is not using the PostgreSQL backend.';
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

  // Validate AI features configuration (NEW UNIFIED STRUCTURE)
  if ($this->isAiFeaturesEnabled($config)) {
    $ai_validation = $this->validateAiFeaturesConfiguration($config);
    $errors = array_merge($errors, $ai_validation['errors']);
    $warnings = array_merge($warnings, $ai_validation['warnings']);
  }

  return ['errors' => $errors, 'warnings' => $warnings];
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
      return in_array($server->getBackend()->getPluginId(), ['postgresql', 'postgresql_azure', 'postgresql_vector']);
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

    // Validate password configuration - UPDATED: passwords are now optional
    $password_key = $connection['password_key'] ?? '';
    $direct_password = $connection['password'] ?? '';
    
    if (empty($password_key) && empty($direct_password)) {
      // Allow empty passwords but issue a warning for security awareness
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

    // Check AI API keys for all backend types
    if ($this->isAiEmbeddingsEnabled($config)) {
      // Handle different config structures
      $ai_config = $config['ai_embeddings']['azure_ai'] ?? $config['azure_embedding'] ?? [];
      $api_key_name = $ai_config['api_key_name'] ?? '';
      
      if (!empty($api_key_name)) {
        $key_validation = $this->validateKey($api_key_name, 'AI API');
        $errors = array_merge($errors, $key_validation['errors']);
        $warnings = array_merge($warnings, $key_validation['warnings']);
      }
      elseif (empty($ai_config['api_key'])) {
        $warnings[] = 'AI API key not configured. Use Key module for secure storage.';
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

    $key = $this->keyRepository->getKey($key_name);
    if (!$key) {
      $errors[] = "{$type} key '{$key_name}' not found.";
    }
    else {
      // Check if key has a value
      $key_value = $key->getKeyValue();
      if (empty($key_value)) {
        $errors[] = "{$type} key '{$key_name}' exists but has no value.";
      }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates AI embeddings configuration.
   */
  protected function validateAiEmbeddingsConfiguration($config) {
    $errors = [];
    $warnings = [];

    // Handle different config structures
    $ai_config = $config['ai_embeddings']['azure_ai'] ?? $config['azure_embedding'] ?? [];

    if (empty($ai_config['endpoint'])) {
      $errors[] = 'Azure AI endpoint is required when embeddings are enabled.';
    }

    if (empty($ai_config['deployment_name'])) {
      $errors[] = 'Azure AI deployment name is required when embeddings are enabled.';
    }

    if (empty($ai_config['api_key']) && empty($ai_config['api_key_name'])) {
      $errors[] = 'Azure AI API key is required when embeddings are enabled.';
    }

    // Validate model configuration
    $model = $ai_config['model'] ?? 'text-embedding-ada-002';
    $valid_models = ['text-embedding-ada-002', 'text-embedding-3-small', 'text-embedding-3-large'];
    if (!in_array($model, $valid_models)) {
      $warnings[] = "Unknown embedding model '{$model}'. Supported models: " . implode(', ', $valid_models);
    }

    return ['errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validates vector search configuration.
   */
  protected function validateVectorSearchConfiguration($config) {
    $errors = [];
    $warnings = [];

    $vector_config = $config['vector_search'] ?? [];

    // Validate dimension
    $dimension = $vector_config['dimension'] ?? 1536;
    if ($dimension < 1 || $dimension > 10000) {
      $errors[] = 'Vector dimension must be between 1 and 10000.';
    }

    // Validate index method
    $index_method = $vector_config['index_method'] ?? 'ivfflat';
    if (!in_array($index_method, ['ivfflat', 'hnsw'])) {
      $errors[] = 'Vector index method must be either "ivfflat" or "hnsw".';
    }

    // Validate method-specific parameters
    if ($index_method === 'ivfflat') {
      $lists = $vector_config['ivfflat_lists'] ?? 100;
      if ($lists < 1 || $lists > 10000) {
        $warnings[] = 'IVFFlat lists parameter should be between 1 and 10000.';
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

  /**
   * Checks if AI embeddings are enabled.
   */
  protected function isAiEmbeddingsEnabled($config) {
    return !empty($config['ai_embeddings']['enabled']) || 
           !empty($config['azure_embedding']['enabled']);
  }

  /**
   * Checks if vector search is enabled.
   */
  protected function isVectorSearchEnabled($config) {
    return !empty($config['vector_search']['enabled']);
  }
}