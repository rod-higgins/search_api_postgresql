<?php

namespace Drupal\search_api_postgresql\Traits;

/**
 * Trait for secure key management in search backends.
 */
trait SecureKeyManagementTrait {

  /**
   * Gets the key repository service.
   *
   * @return \Drupal\key\KeyRepositoryInterface|null
   *   The key repository service or NULL if not available.
   */
  protected function getKeyRepository() {
    return $this->keyRepository ?? NULL;
  }

  /**
   * Retrieves a secure key value.
   *
   * @param string $key_name
   *   The key name/ID.
   * @param string $direct_key
   *   Fallback direct key value.
   * @param string $type
   *   Type of key for error messages (e.g., 'Database password', 'API key').
   * @param bool $required
   *   Whether the key is required.
   *
   * @return string
   *   The key value.
   *
   * @throws \RuntimeException
   *   If the key is required but not found.
   */
  protected function getSecureKey($key_name, $direct_key = '', $type = 'Key', $required = FALSE) {
    $key_repository = $this->getKeyRepository();
    
    // Try key module first if available
    if (!empty($key_name) && $key_repository) {
      $key = $key_repository->getKey($key_name);
      if ($key) {
        $key_value = $key->getKeyValue();
        if (!empty($key_value)) {
          return $key_value;
        } else {
          // Key exists but is empty - this might be intentional for some setups
          if (!empty($direct_key)) {
            \Drupal::logger('search_api_postgresql')->warning('@type key "@key" is empty. Using direct value as fallback.', [
              '@type' => $type,
              '@key' => $key_name,
            ]);
            return $direct_key;
          }
        }
        
        return $key_value;
      }

      $message = sprintf('%s key "%s" not found.', $type, $key_name);
      if ($required) {
        throw new \RuntimeException($message);
      } else {
        \Drupal::logger('search_api_postgresql')->warning($message . ' Falling back to direct value.');
        return $direct_key;
      }
    }

    // Fall back to direct key
    return $direct_key;
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
      return;
    }

    // Get available keys
    $keys = [];
    foreach ($key_repository->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }

    // Database password key field - make it optional
    if (isset($form['connection']['password'])) {
      $form['connection']['password_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Database Password Key (Optional)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or leave empty -'),
        '#default_value' => $this->configuration['connection']['password_key'] ?? '',
        '#description' => $this->t('Optional: Select a key that contains the database password. If not selected, the direct password field below will be used. Both can be empty for passwordless connections (e.g., Lando development).'),
        '#weight' => isset($form['connection']['password']['#weight']) ? $form['connection']['password']['#weight'] - 1 : -1,
      ];

      // Update password field to show/hide based on key selection
      $form['connection']['password']['#states'] = [
        'visible' => [
          ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
        ],
      ];
      
      $form['connection']['password']['#description'] = $this->t('Direct password entry (optional). Leave empty for passwordless connections. Using Key module is recommended for production security. This field is hidden when a password key is selected above.');
      $form['connection']['password']['#required'] = FALSE;
    }
  }

  /**
   * Adds API key fields to a specific form section.
   *
   * @param array &$form_section
   *   The form section to add fields to.
   * @param string $base_path
   *   The base path for form states (e.g., 'azure_embedding][service').
   * @param string $config_path
   *   The configuration path for default values.
   */
  protected function addApiKeyFields(array &$form_section, $base_path, $config_path) {
    $key_repository = $this->getKeyRepository();
    if (!$key_repository) {
      // No key module available, add only direct entry
      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Your API key. Using Key module is recommended for production.'),
      ];
      return;
    }

    // Get available keys
    $keys = [];
    foreach ($key_repository->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }

    $form_section['api_key_name'] = [
      '#type' => 'select',
      '#title' => $this->t('API Key (Key Module)'),
      '#options' => $keys,
      '#empty_option' => $this->t('- Select a key or use direct entry below -'),
      '#default_value' => $this->getConfigValue($config_path . '.api_key_name'),
      '#description' => $this->t('Select a key containing your API key.'),
      '#weight' => -1,
    ];

    $form_section['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Direct API Key (Fallback)'),
      '#description' => $this->t('Direct API key entry. Only used if no key is selected above.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[' . $base_path . '][api_key_name]"]' => ['value' => ''],
        ],
      ],
      '#weight' => 0,
    ];
  }

  /**
   * Get API key name from configuration.
   *
   * @return string
   *   The API key name.
   */
  protected function getApiKeyNameFromConfig() {
    // Try different possible configuration paths
    $paths = [
      'ai_embeddings.azure_ai.api_key_name',
      'azure_embedding.api_key_name',
      'vector_search.openai.api_key_name',
      'vector_search.huggingface.api_key_name',
    ];

    foreach ($paths as $path) {
      $value = $this->getConfigValue($path);
      if (!empty($value)) {
        return $value;
      }
    }

    return '';
  }

  /**
   * Get configuration value by dot notation path.
   *
   * @param string $path
   *   The configuration path in dot notation.
   *
   * @return mixed|null
   *   The configuration value or NULL if not found.
   */
  protected function getConfigValue($path) {
    $keys = explode('.', $path);
    $value = $this->configuration;

    foreach ($keys as $key) {
      if (is_array($value) && isset($value[$key])) {
        $value = $value[$key];
      } else {
        return NULL;
      }
    }

    return $value;
  }

  /**
   * Validates API key configuration.
   *
   * @param array $values
   *   Form values.
   * @param string $field_path
   *   The field path for error reporting.
   * @param string $key_name_field
   *   The key name field name.
   * @param string $direct_key_field
   *   The direct key field name.
   * @param string $label
   *   Human-readable label for the key type.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function validateApiKeyConfig(array $values, $field_path, $key_name_field, $direct_key_field, $label = 'API key') {
    $key_name = $values[$key_name_field] ?? '';
    $direct_key = $values[$direct_key_field] ?? '';

    if (empty($key_name) && empty($direct_key)) {
      return FALSE;
    }

    // If key name is specified, verify it exists
    if (!empty($key_name)) {
      $key_repository = $this->getKeyRepository();
      if ($key_repository) {
        $key = $key_repository->getKey($key_name);
        if (!$key) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Gets database password considering key configuration.
   *
   * @return string
   *   The database password.
   */
  protected function getDatabasePassword() {
    $connection_config = $this->configuration['connection'] ?? [];
    $password_key = $connection_config['password_key'] ?? '';
    $direct_password = $connection_config['password'] ?? '';

    return $this->getSecureKey($password_key, $direct_password, 'Database password', FALSE);
  }

  /**
   * Gets API key for Azure services.
   *
   * @return string
   *   The API key.
   */
  protected function getApiKey() {
    // Try different configuration paths
    $configs = [
      'ai_embeddings.azure_ai' => 'Azure AI',
      'azure_embedding' => 'Azure Embedding',
      'vector_search.openai' => 'OpenAI',
      'vector_search.huggingface' => 'Hugging Face',
    ];

    foreach ($configs as $path => $label) {
      $config = $this->getConfigValue($path);
      if (!empty($config)) {
        $key_name = $config['api_key_name'] ?? '';
        $direct_key = $config['api_key'] ?? '';
        
        if (!empty($key_name) || !empty($direct_key)) {
          return $this->getSecureKey($key_name, $direct_key, $label . ' API key', FALSE);
        }
      }
    }

    return '';
  }

  /**
   * Validates that required keys are accessible.
   *
   * @param array $required_keys
   *   Array of required key configurations.
   *
   * @throws \RuntimeException
   *   If a required key is not accessible.
   */
  protected function validateRequiredKeys(array $required_keys) {
    foreach ($required_keys as $key_config) {
      $key_name = $key_config['key_name'] ?? '';
      $direct_key = $key_config['direct_key'] ?? '';
      $label = $key_config['label'] ?? 'Key';
      $required = $key_config['required'] ?? TRUE;

      if ($required) {
        $this->getSecureKey($key_name, $direct_key, $label, TRUE);
      }
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
    
    // Get password from key if specified
    if (!empty($connection_config['password_key'])) {
      $password = $this->getDatabasePassword();
      if (!empty($password)) {
        $connection_config['password'] = $password;
      }
    }

    return $connection_config;
  }

}