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
   * Gets API key for Azure services.
   *
   * @return string
   *   The API key.
   */
  protected function getAzureApiKey() {
    $api_key_name = $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
    $direct_key = $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '';

    if (!empty($api_key_name) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($api_key_name);
      if ($key) {
        return $key->getKeyValue();
      }
    }

    return $direct_key;
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