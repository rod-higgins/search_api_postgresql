<?php

namespace Drupal\search_api_postgresql\Traits;

use Drupal\key\KeyRepositoryInterface;

/**
 * Trait for secure key management across PostgreSQL backends.
 *
 * Provides consistent password and API key handling using the Key module
 * with fallback to direct entry when Key module is not available.
 */
trait SecureKeyManagementTrait {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Gets the key repository service.
   *
   * @return \Drupal\key\KeyRepositoryInterface|null
   *   The key repository service or NULL if not available.
   */
  protected function getKeyRepository() {
    if (!isset($this->keyRepository)) {
      try {
        $this->keyRepository = \Drupal::service('key.repository');
      } catch (\Exception $e) {
        // Key module not available
        $this->keyRepository = NULL;
      }
    }
    return $this->keyRepository;
  }

  /**
   * Gets a secure key value from either Key module or direct configuration.
   *
   * @param string $key_name
   *   The key name from Key module.
   * @param string $direct_value
   *   The direct value fallback.
   * @param string $label
   *   Human-readable label for error messages.
   * @param bool $required
   *   Whether the key is required.
   *
   * @return string
   *   The key value.
   *
   * @throws \RuntimeException
   *   If a required key is not found.
   */
  protected function getSecureKey($key_name, $direct_value, $label = 'Key', $required = TRUE) {
    // First try to get from Key module if key name is provided
    if (!empty($key_name)) {
      $key_repository = $this->getKeyRepository();
      if ($key_repository) {
        $key = $key_repository->getKey($key_name);
        if ($key) {
          $key_value = $key->getKeyValue();
          if (!empty($key_value)) {
            return $key_value;
          }
        }
        
        if ($required) {
          throw new \RuntimeException($this->t('@label "@key_name" not found or empty in Key repository.', [
            '@label' => $label,
            '@key_name' => $key_name,
          ]));
        }
      }
    }

    // Fall back to direct value
    if (!empty($direct_value)) {
      return $direct_value;
    }

    // If nothing found and required, throw exception
    if ($required) {
      throw new \RuntimeException($this->t('@label is required but not configured.', [
        '@label' => $label,
      ]));
    }

    return '';
  }

  /**
   * Adds password fields to a connection form section.
   *
   * This method adds both Key module integration and direct password entry
   * with proper form states to show/hide fields appropriately.
   *
   * @param array &$form
   *   The form array to modify.
   */
  protected function addPasswordFields(array &$form) {
    $key_repository = $this->getKeyRepository();
    
    if ($key_repository) {
      // Get available keys
      $keys = [];
      foreach ($key_repository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      // Add key selection field
      $form['connection']['password_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Database Password (Key Module)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct entry below -'),
        '#default_value' => $this->configuration['connection']['password_key'] ?? '',
        '#description' => $this->t('Optional: Select a key that contains the database password. If not selected, the direct password field below will be used. Both can be empty for passwordless connections.'),
        '#weight' => -1,
      ];

      // Add direct password field with form states
      $form['connection']['password'] = [
        '#type' => 'password',
        '#title' => $this->t('Database Password (Direct Entry)'),
        '#default_value' => '',
        '#required' => FALSE,
        '#description' => $this->t('Direct password entry (optional). Leave empty for passwordless connections. Using Key module is recommended for production security. This field is hidden when a password key is selected above.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
          ],
        ],
        '#weight' => 0,
      ];
    } else {
      // Key module not available, only show direct password field
      $form['connection']['password'] = [
        '#type' => 'password',
        '#title' => $this->t('Database Password'),
        '#default_value' => '',
        '#required' => FALSE,
        '#description' => $this->t('Database password (optional). Leave empty for passwordless connections. Installing the Key module is recommended for production security.'),
        '#weight' => 0,
      ];
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
    
    if ($key_repository) {
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
    } else {
      // No key module available, add only direct entry
      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Your API key. Installing the Key module is recommended for production security.'),
      ];
    }
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
   * Gets API key for various services.
   *
   * @param string $service
   *   The service name (e.g., 'azure_ai', 'openai').
   *
   * @return string
   *   The API key.
   */
  protected function getApiKey($service = 'azure_ai') {
    // Try different configuration paths based on backend type
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

  /**
   * Helper method to provide translation function if not available.
   *
   * @param string $string
   *   The string to translate.
   * @param array $args
   *   Translation arguments.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated string.
   */
  protected function t($string, array $args = []) {
    if (method_exists($this, 'getStringTranslation')) {
      return $this->getStringTranslation()->translate($string, $args);
    }
    
    // Fallback if StringTranslationTrait is not available
    if (function_exists('t')) {
      return t($string, $args);
    }
    
    // Last resort - return the string with simple substitution
    return strtr($string, $args);
  }

}