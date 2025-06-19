<?php

namespace Drupal\search_api_postgresql\Traits;

use Drupal\key\KeyRepositoryInterface;

/**
 * Trait for secure key management in backend plugins.
 * 
 * Provides secure handling of API keys, database passwords, and other
 * sensitive configuration values with support for the Key module and
 * passwordless connections.
 */
trait SecurityManagementTrait {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected $keyRepository;

  /**
   * Gets the key repository service.
   *
   * @return \Drupal\key\KeyRepositoryInterface|null
   *   The key repository or NULL if not available.
   */
  protected function getKeyRepository() {
    return $this->keyRepository ?? NULL;
  }

  /**
   * Sets the key repository service.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function setKeyRepository(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * Checks if the Key module is available and functional.
   *
   * @return bool
   *   TRUE if Key module is available, FALSE otherwise.
   */
  protected function isKeyModuleAvailable() {
    return !is_null($this->getKeyRepository());
  }

  /**
   * Gets database password from key or direct configuration.
   * 
   * Supports passwordless connections for modern authentication methods
   * like IAM, certificate-based auth, etc.
   *
   * @return string
   *   The database password (can be empty for passwordless connections).
   */
  protected function getDatabasePassword() {
    $connection_config = $this->configuration['connection'] ?? [];
    $password_key = $connection_config['password_key'] ?? '';
    $direct_password = $connection_config['password'] ?? '';
    
    return $this->getSecureKey($password_key, $direct_password, 'Database password', FALSE);
  }

  /**
   * Gets API key for AI embedding services.
   * 
   * Attempts to retrieve API keys from multiple configuration paths
   * to support different backend configurations.
   *
   * @param string $provider
   *   The AI provider ('azure', 'openai', etc.).
   *
   * @return string
   *   The API key value.
   */
  protected function getAiApiKey($provider = NULL) {
    if (!$provider) {
      $provider = $this->configuration['ai_embeddings']['provider'] ?? 'azure';
    }

    // Configuration paths to check for API keys
    $config_paths = [
      'azure' => [
        'ai_embeddings.azure',
        'azure_embedding',
        'ai_embeddings.azure_ai',
      ],
      'openai' => [
        'ai_embeddings.openai',
        'vector_search.openai',
      ],
      'huggingface' => [
        'vector_search.huggingface',
      ],
    ];

    $paths = $config_paths[$provider] ?? [];
    
    foreach ($paths as $path) {
      $config = $this->getConfigValue($path);
      if (!empty($config)) {
        $key_name = $config['api_key_name'] ?? '';
        $direct_key = $config['api_key'] ?? '';
        
        if (!empty($key_name) || !empty($direct_key)) {
          return $this->getSecureKey($key_name, $direct_key, ucfirst($provider) . ' API key', FALSE);
        }
      }
    }

    return '';
  }

  /**
   * Retrieves a secure key value with fallback support.
   *
   * @param string $key_name
   *   The key name/ID from Key module.
   * @param string $direct_key
   *   Fallback direct key value.
   * @param string $type
   *   Type of key for error messages (e.g., 'Database password', 'API key').
   * @param bool $required
   *   Whether the key is required for operation.
   *
   * @return string
   *   The key value.
   *
   * @throws \RuntimeException
   *   If the key is required but not found or accessible.
   */
  protected function getSecureKey($key_name, $direct_key = '', $type = 'Key', $required = FALSE) {
    // If no key name is provided, return direct key (may be empty for passwordless)
    if (empty($key_name)) {
      if ($required && empty($direct_key)) {
        throw new \RuntimeException(sprintf('%s is required but not configured.', $type));
      }
      return $direct_key;
    }

    $key_repository = $this->getKeyRepository();
    
    // Key module not available
    if (!$key_repository) {
      $message = sprintf('Key module is not available but %s key name "%s" is specified.', $type, $key_name);
      
      if ($required && empty($direct_key)) {
        throw new \RuntimeException($message . ' No fallback value available.');
      }
      
      if (!empty($direct_key)) {
        $this->logWarning($message . ' Using direct value as fallback.');
        return $direct_key;
      }
      
      if ($required) {
        throw new \RuntimeException($message . ' No fallback value available.');
      }
      
      $this->logWarning($message . ' Proceeding without key.');
      return '';
    }

    // Try to retrieve key from Key module
    $key = $key_repository->getKey($key_name);
    
    if (!$key) {
      $message = sprintf('%s key "%s" not found in Key module.', $type, $key_name);
      
      if ($required && empty($direct_key)) {
        throw new \RuntimeException($message . ' No fallback value available.');
      }
      
      if (!empty($direct_key)) {
        $this->logWarning($message . ' Using direct value as fallback.');
        return $direct_key;
      }
      
      if ($required) {
        throw new \RuntimeException($message . ' No fallback value available.');
      }
      
      $this->logWarning($message . ' Proceeding without key.');
      return '';
    }

    // Key exists, try to get its value
    $key_value = $key->getKeyValue();
    
    if ($key_value === NULL || $key_value === '') {
      $message = sprintf('%s key "%s" exists but has no value.', $type, $key_name);
      
      if ($required && empty($direct_key)) {
        throw new \RuntimeException($message . ' No fallback value available.');
      }
      
      if (!empty($direct_key)) {
        $this->logWarning($message . ' Using direct value as fallback.');
        return $direct_key;
      }
      
      if ($required) {
        throw new \RuntimeException($message . ' No fallback value available.');
      }
      
      $this->logWarning($message . ' Proceeding without key.');
      return '';
    }

    return $key_value;
  }

  /**
   * Gets a nested configuration value using dot notation.
   *
   * @param string $path
   *   The configuration path (e.g., 'ai_embeddings.azure').
   *
   * @return mixed
   *   The configuration value or NULL if not found.
   */
  protected function getConfigValue($path) {
    $keys = explode('.', $path);
    $value = $this->configuration;
    
    foreach ($keys as $key) {
      if (!is_array($value) || !isset($value[$key])) {
        return NULL;
      }
      $value = $value[$key];
    }
    
    return $value;
  }

  /**
   * Validates that required keys are accessible.
   *
   * @param array $required_keys
   *   Array of required key configurations. Each item should contain:
   *   - key_name: The key name from Key module
   *   - direct_key: Fallback direct value
   *   - label: Human-readable label for errors
   *   - required: Whether this key is mandatory
   *
   * @throws \RuntimeException
   *   If a required key is not accessible.
   */
  protected function validateRequiredKeys(array $required_keys) {
    $errors = [];
    
    foreach ($required_keys as $key_config) {
      $key_name = $key_config['key_name'] ?? '';
      $direct_key = $key_config['direct_key'] ?? '';
      $label = $key_config['label'] ?? 'Key';
      $required = $key_config['required'] ?? TRUE;

      try {
        $this->getSecureKey($key_name, $direct_key, $label, $required);
      } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
      }
    }
    
    if (!empty($errors)) {
      throw new \RuntimeException('Key validation failed: ' . implode('; ', $errors));
    }
  }

  /**
   * Prepares connection configuration with resolved passwords.
   *
   * @return array
   *   Connection configuration with resolved passwords.
   */
  protected function prepareConnectionConfig() {
    $connection_config = $this->configuration['connection'] ?? [];
    
    // Get password from key repository or direct configuration
    $password = $this->getDatabasePassword();
    
    // Only set password if we have one (supports passwordless connections)
    if (!empty($password)) {
      $connection_config['password'] = $password;
    } else {
      // Remove password key to avoid confusion in connection string
      unset($connection_config['password']);
    }
    
    return $connection_config;
  }

  /**
   * Adds key selection fields to a configuration form.
   *
   * @param array &$form
   *   The form array to add fields to.
   */
  protected function addKeyFieldsToForm(array &$form) {
    $key_repository = $this->getKeyRepository();
    
    if (!$key_repository) {
      // Key module not available - add informational message
      if (isset($form['connection'])) {
        $form['connection']['key_info'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--warning">' . 
            $this->t('Key module is not installed. Credentials will be stored directly in configuration. For better security, consider installing the Key module.') . 
            '</div>',
          '#weight' => -10,
        ];
      }
      return;
    }

    // Get available keys
    $keys = [];
    foreach ($key_repository->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }

    if (empty($keys)) {
      // No keys available
      if (isset($form['connection'])) {
        $form['connection']['key_info'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--info">' . 
            $this->t('No keys found. <a href="@url">Create a key</a> for better security.', [
              '@url' => '/admin/config/system/keys/add',
            ]) . 
            '</div>',
          '#weight' => -10,
        ];
      }
      return;
    }

    // Add database password key field
    if (isset($form['connection']['password'])) {
      $form['connection']['password_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Database Password Key (Optional)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Use direct password or passwordless -'),
        '#default_value' => $this->configuration['connection']['password_key'] ?? '',
        '#description' => $this->t('Select a key to use for the database password. Leave empty to use direct password or passwordless authentication.'),
        '#weight' => 5,
      ];

      // Update password field description
      $form['connection']['password']['#description'] = $this->t('Database password. Leave empty for passwordless authentication or if using Key module above.');
      $form['connection']['password']['#weight'] = 6;
      
      // Add state to hide password when key is selected
      $form['connection']['password']['#states'] = [
        'visible' => [
          ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
        ],
      ];
    }

    // Add AI API key fields
    $this->addAiKeyFieldsToForm($form, $keys);
  }

  /**
   * Adds AI provider key selection fields to the form.
   *
   * @param array &$form
   *   The form array to add fields to.
   * @param array $keys
   *   Available keys from Key module.
   */
  protected function addAiKeyFieldsToForm(array &$form, array $keys = NULL) {
    // If keys not provided, get them ourselves
    if ($keys === NULL) {
      $key_repository = $this->getKeyRepository();
      
      if (!$key_repository) {
        return;
      }

      // Get available keys
      $keys = [];
      foreach ($key_repository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      if (empty($keys)) {
        return;
      }
    }

    // Azure OpenAI API key
    if (isset($form['ai_embeddings']['azure']['api_key'])) {
      $form['ai_embeddings']['azure']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Azure API Key (from Key module)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Use direct API key -'),
        '#default_value' => $this->configuration['ai_embeddings']['azure']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key containing your Azure OpenAI API key.'),
        '#weight' => -1,
      ];

      $form['ai_embeddings']['azure']['api_key']['#states'] = [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][azure][api_key_name]"]' => ['value' => ''],
        ],
      ];
    }

    // OpenAI API key
    if (isset($form['ai_embeddings']['openai']['api_key'])) {
      $form['ai_embeddings']['openai']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('OpenAI API Key (from Key module)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Use direct API key -'),
        '#default_value' => $this->configuration['ai_embeddings']['openai']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key containing your OpenAI API key.'),
        '#weight' => -1,
      ];

      $form['ai_embeddings']['openai']['api_key']['#states'] = [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][openai][api_key_name]"]' => ['value' => ''],
        ],
      ];
    }
  }

  /**
   * Validates a key configuration during form validation.
   *
   * @param string $key_name
   *   The key name to validate.
   * @param string $type
   *   Type of key for error messages.
   *
   * @return array
   *   Array with 'valid' boolean and 'message' string.
   */
  protected function validateKey($key_name, $type = 'Key') {
    if (empty($key_name)) {
      return [
        'valid' => TRUE,
        'message' => sprintf('%s name is empty - direct value will be used.', $type),
      ];
    }

    $key_repository = $this->getKeyRepository();
    
    if (!$key_repository) {
      return [
        'valid' => FALSE,
        'message' => 'Key module is not available.',
      ];
    }

    $key = $key_repository->getKey($key_name);
    if (!$key) {
      return [
        'valid' => FALSE,
        'message' => sprintf('%s "%s" not found.', $type, $key_name),
      ];
    }

    try {
      $key_value = $key->getKeyValue();
      if (empty($key_value)) {
        return [
          'valid' => FALSE,
          'message' => sprintf('%s "%s" exists but has no value.', $type, $key_name),
        ];
      }
    } catch (\Exception $e) {
      return [
        'valid' => FALSE,
        'message' => sprintf('%s "%s" exists but cannot be accessed: %s', $type, $key_name, $e->getMessage()),
      ];
    }

    return [
      'valid' => TRUE,
      'message' => sprintf('%s "%s" is valid and accessible.', $type, $key_name),
    ];
  }

  /**
   * Performs a comprehensive test of all configured keys.
   *
   * @return array
   *   Array of test results with keys 'database', 'ai_keys', and 'summary'.
   */
  protected function testAllKeys() {
    $results = [
      'database' => ['tested' => FALSE, 'valid' => FALSE, 'message' => ''],
      'ai_keys' => [],
      'summary' => ['total' => 0, 'valid' => 0, 'errors' => []],
    ];

    // Test database password key
    $db_password_key = $this->configuration['connection']['password_key'] ?? '';
    if (!empty($db_password_key)) {
      $results['database'] = $this->validateKey($db_password_key, 'Database password key');
      $results['database']['tested'] = TRUE;
      $results['summary']['total']++;
      if ($results['database']['valid']) {
        $results['summary']['valid']++;
      } else {
        $results['summary']['errors'][] = $results['database']['message'];
      }
    }

    // Test AI provider keys
    $ai_providers = ['azure', 'openai'];
    foreach ($ai_providers as $provider) {
      $key_name = $this->configuration['ai_embeddings'][$provider]['api_key_name'] ?? '';
      if (!empty($key_name)) {
        $result = $this->validateKey($key_name, ucfirst($provider) . ' API key');
        $results['ai_keys'][$provider] = $result;
        $results['summary']['total']++;
        if ($result['valid']) {
          $results['summary']['valid']++;
        } else {
          $results['summary']['errors'][] = $result['message'];
        }
      }
    }

    return $results;
  }

  /**
   * Logs a warning message.
   *
   * @param string $message
   *   The warning message to log.
   */
  protected function logWarning($message) {
    if (isset($this->logger)) {
      $this->logger->warning($message);
    } elseif (function_exists('drupal_set_message')) {
      drupal_set_message($message, 'warning');
    } else {
      \Drupal::logger('search_api_postgresql')->warning($message);
    }
  }

  /**
   * Logs an error message.
   *
   * @param string $message
   *   The error message to log.
   */
  protected function logError($message) {
    if (isset($this->logger)) {
      $this->logger->error($message);
    } elseif (function_exists('drupal_set_message')) {
      drupal_set_message($message, 'error');
    } else {
      \Drupal::logger('search_api_postgresql')->error($message);
    }
  }

}