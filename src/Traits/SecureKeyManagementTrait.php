<?php

namespace Drupal\search_api_postgresql\Traits;

use Drupal\key\KeyRepositoryInterface;

/**
 * Trait for secure key management in backend plugins.
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
   *   The key repository or NULL if not available.
   */
  protected function getKeyRepository() {
    return $this->keyRepository ?? NULL;
  }

  /**
   * Gets database password from key or direct configuration.
   * 
   * Passwords are optional - supports passwordless connections.
   *
   * @return string
   *   The database password (can be empty for passwordless connections).
   */
  protected function getDatabasePassword() {
    $password_key = $this->configuration['connection']['password_key'] ?? '';
    
    // If no key is configured, fall back to direct password (which can be empty)
    if (empty($password_key)) {
      return $this->configuration['connection']['password'] ?? '';
    }

    // Try to get password from Key module
    $key_repository = $this->getKeyRepository();
    if (!$key_repository) {
      // Key module not available, fall back to direct password
      $direct_password = $this->configuration['connection']['password'] ?? '';
      
      if (empty($direct_password)) {
        \Drupal::logger('search_api_postgresql')->warning('Key module not available and no direct password configured. Using passwordless connection.');
      }
      
      return $direct_password;
    }

    $key = $key_repository->getKey($password_key);
    if ($key) {
      $key_value = $key->getKeyValue();
      
      // If key exists but has no value, log warning and fall back
      if (empty($key_value)) {
        \Drupal::logger('search_api_postgresql')->warning('Database password key "@key" exists but has no value. Falling back to direct password.', [
          '@key' => $password_key,
        ]);
        return $this->configuration['connection']['password'] ?? '';
      }
      
      return $key_value;
    }

    // Key not found, fall back to direct password
    \Drupal::logger('search_api_postgresql')->warning('Database password key "@key" not found. Falling back to direct password.', [
      '@key' => $password_key,
    ]);
    return $this->configuration['connection']['password'] ?? '';
  }

  /**
   * Gets API key from key module or direct configuration.
   * 
   * More lenient - logs warnings instead of throwing exceptions for optional features.
   *
   * @param string $key_name
   *   The key name from configuration.
   * @param string $direct_key
   *   The direct key value from configuration.
   * @param string $type
   *   Type of key for error messages (e.g., 'API', 'Database password').
   * @param bool $required
   *   Whether this key is required (throws exception) or optional (logs warning).
   *
   * @return string
   *   The API key value.
   *
   * @throws \RuntimeException
   *   If key is required but not found.
   */
  protected function getSecureKey($key_name, $direct_key, $type = 'API', $required = TRUE) {
    $key_repository = $this->getKeyRepository();

    // Try key module first if key name is provided
    if (!empty($key_name)) {
      if (!$key_repository) {
        $message = sprintf('Key module is not available but %s key name is specified.', $type);
        if ($required) {
          throw new \RuntimeException($message);
        } else {
          \Drupal::logger('search_api_postgresql')->warning($message);
          return $direct_key;
        }
      }
      
      $key = $key_repository->getKey($key_name);
      if ($key) {
        $key_value = $key->getKeyValue();
        
        if (empty($key_value)) {
          $message = sprintf('%s key "%s" exists but has no value.', $type, $key_name);
          if ($required) {
            throw new \RuntimeException($message);
          } else {
            \Drupal::logger('search_api_postgresql')->warning($message . ' Falling back to direct value.');
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
   * Adds Azure AI API key fields to form.
   *
   * @param array &$form
   *   The form array to add fields to.
   */
  protected function addAzureKeyFieldsToForm(array &$form) {
    $key_repository = $this->getKeyRepository();
    if (!$key_repository) {
      return;
    }

    // Get available keys
    $keys = [];
    foreach ($key_repository->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }

    // Azure API key field
    if (isset($form['ai_embeddings']['azure_ai']['api_key'])) {
      $form['ai_embeddings']['azure_ai']['api_key_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Azure API Key (Key Module)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct entry -'),
        '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_key'] ?? '',
        '#description' => $this->t('Select a key that contains the Azure API key. Using Key module is recommended for security.'),
        '#weight' => isset($form['ai_embeddings']['azure_ai']['api_key']['#weight']) ? $form['ai_embeddings']['azure_ai']['api_key']['#weight'] - 1 : -1,
      ];

      // Update API key field to show/hide based on key selection
      $form['ai_embeddings']['azure_ai']['api_key']['#states'] = [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][azure_ai][api_key_key]"]' => ['value' => ''],
        ],
      ];
      
      $form['ai_embeddings']['azure_ai']['api_key']['#description'] = $this->t('Direct API key entry. This field is hidden when a key is selected above.');
    }
  }

  /**
   * Validates a key configuration.
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
    $key_repository = $this->getKeyRepository();
    
    if (!$key_repository) {
      return [
        'valid' => FALSE,
        'message' => 'Key module is not available.',
      ];
    }

    if (empty($key_name)) {
      return [
        'valid' => FALSE,
        'message' => 'Key name is empty.',
      ];
    }

    $key = $key_repository->getKey($key_name);
    if (!$key) {
      return [
        'valid' => FALSE,
        'message' => sprintf('%s "%s" not found.', $type, $key_name),
      ];
    }

    $key_value = $key->getKeyValue();
    if (empty($key_value)) {
      return [
        'valid' => FALSE,
        'message' => sprintf('%s "%s" exists but has no value.', $type, $key_name),
      ];
    }

    return [
      'valid' => TRUE,
      'message' => sprintf('%s "%s" is valid.', $type, $key_name),
    ];
  }

}