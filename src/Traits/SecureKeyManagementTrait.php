<?php

namespace Drupal\search_api_postgresql\Traits;

/**
 * Simple trait for secure key management.
 */
trait SecureKeyManagementTrait {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected $keyRepository;

  /**
   * Gets a secure key value.
   *
   * @param string $key_name
   *   The key name from configuration.
   * @param string $direct_value
   *   The direct value as fallback.
   *
   * @return string
   *   The key value.
   */
  protected function getSecureValue($key_name, $direct_value = '') {
    // If no key name provided, use direct value
    if (empty($key_name)) {
      return $direct_value;
    }

    // If key repository not available, use direct value
    if (!$this->keyRepository) {
      return $direct_value;
    }

    // Try to get value from key module
    $key = $this->keyRepository->getKey($key_name);
    if ($key) {
      $key_value = $key->getKeyValue();
      if (!empty($key_value)) {
        return $key_value;
      }
    }

    // Fallback to direct value
    return $direct_value;
  }

  /**
   * Gets database password.
   *
   * @return string
   *   The database password.
   */
  protected function getDatabasePassword() {
    $key_name = $this->configuration['connection']['password_key'] ?? '';
    $direct_password = $this->configuration['connection']['password'] ?? '';
    
    return $this->getSecureValue($key_name, $direct_password);
  }

  /**
   * Gets API key.
   *
   * @return string
   *   The API key.
   */
  protected function getApiKey() {
    $key_name = $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
    $direct_key = $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '';
    
    return $this->getSecureValue($key_name, $direct_key);
  }

}