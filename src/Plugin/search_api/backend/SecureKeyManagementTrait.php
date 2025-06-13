<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\key\KeyRepositoryInterface;

/**
 * Trait for secure key management in backends.
 */
trait SecureKeyManagementTrait {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Sets the key repository service.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function setKeyRepository(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * Gets the key repository service.
   *
   * @return \Drupal\key\KeyRepositoryInterface|null
   *   The key repository, or NULL if not available.
   */
  protected function getKeyRepository() {
    if (!$this->keyRepository && \Drupal::hasService('key.repository')) {
      $this->keyRepository = \Drupal::service('key.repository');
    }
    return $this->keyRepository;
  }

  /**
   * Gets the database password from Key module or direct configuration.
   *
   * UPDATED: Passwords are now optional - supports passwordless connections.
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
   * Gets the API key for AI services from Key module or direct configuration.
   *
   * @return string
   *   The API key.
   *
   * @throws \RuntimeException
   *   If no API key is configured when AI features are enabled.
   */
  protected function getAiApiKey() {
    // Check for Azure AI configuration first
    if (!empty($this->configuration['ai_embeddings']['azure_ai']['api_key_name'])) {
      $key_name = $this->configuration['ai_embeddings']['azure_ai']['api_key_name'];
    }
    // Check for vector search configuration
    elseif (!empty($this->configuration['vector_search']['api_key_name'])) {
      $key_name = $this->configuration['vector_search']['api_key_name'];
    }
    else {
      // Fallback to direct API key if no key name is configured
      $direct_key = $this->configuration['vector_search']['api_key'] ?? 
                    $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '';
      
      if (empty($direct_key)) {
        throw new \RuntimeException('No AI API key configured. Either specify a Key module key name or provide a direct API key.');
      }
      
      return $direct_key;
    }

    $key_repository = $this->getKeyRepository();
    if (!$key_repository) {
      // Key module not available, try direct key
      $direct_key = $this->configuration['vector_search']['api_key'] ?? 
                    $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '';
      
      if (empty($direct_key)) {
        throw new \RuntimeException('Key module not available and no direct API key configured.');
      }
      
      return $direct_key;
    }

    $key = $key_repository->getKey($key_name);
    if ($key) {
      $key_value = $key->getKeyValue();
      
      if (empty($key_value)) {
        throw new \RuntimeException(sprintf('API key "%s" exists but has no value.', $key_name));
      }
      
      return $key_value;
    }

    throw new \RuntimeException(sprintf('API key "%s" not found.', $key_name));
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

    // AI API key field
    if (isset($form['ai_embeddings']['azure_ai'])) {
      $form['ai_embeddings']['azure_ai']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('API Key'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key -'),
        '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key that contains the Azure AI API key. Using Key module is recommended for security.'),
        '#weight' => -1,
        '#states' => [
          'required' => [
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      // Add fallback direct API key field
      $form['ai_embeddings']['azure_ai']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key (Direct)'),
        '#description' => $this->t('Direct API key entry. Only used if no key is selected above. Using Key module is recommended for security.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[ai_embeddings][api_key_name]"]' => ['value' => ''],
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="backend_config[ai_embeddings][api_key_name]"]' => ['value' => ''],
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }
  }

  /**
   * Validates password configuration during form validation.
   *
   * @param array $values
   *   Form values.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function validatePasswordConfiguration(array $values, $form_state) {
    $password_key = $values['connection']['password_key'] ?? '';
    $direct_password = $values['connection']['password'] ?? '';
    
    // Both key and direct password are optional
    if (empty($password_key) && empty($direct_password)) {
      // Issue a warning but don't treat as error
      \Drupal::messenger()->addWarning($this->t('No database password configured. This may be acceptable for development environments (like Lando with trust authentication) but is not recommended for production.'));
    }
    
    // If key is specified, validate it exists
    if (!empty($password_key)) {
      $key_repository = $this->getKeyRepository();
      if ($key_repository) {
        $key = $key_repository->getKey($password_key);
        if (!$key) {
          $form_state->setErrorByName('connection][password_key', $this->t('The specified password key "@key" does not exist.', ['@key' => $password_key]));
        }
        elseif (empty($key->getKeyValue())) {
          $form_state->setErrorByName('connection][password_key', $this->t('The specified password key "@key" exists but has no value.', ['@key' => $password_key]));
        }
      }
    }
  }

  /**
   * Validates AI API key configuration during form validation.
   *
   * @param array $values
   *   Form values.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function validateAiApiKeyConfiguration(array $values, $form_state) {
    if (empty($values['ai_embeddings']['enabled'])) {
      return;
    }
    
    $api_key_name = $values['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
    $direct_api_key = $values['ai_embeddings']['azure_ai']['api_key'] ?? '';
    
    // At least one API key method must be provided when AI is enabled
    if (empty($api_key_name) && empty($direct_api_key)) {
      $form_state->setErrorByName('ai_embeddings][azure_ai][api_key', $this->t('API key is required when AI embeddings are enabled.'));
      return;
    }
    
    // If key name is specified, validate it exists
    if (!empty($api_key_name)) {
      $key_repository = $this->getKeyRepository();
      if ($key_repository) {
        $key = $key_repository->getKey($api_key_name);
        if (!$key) {
          $form_state->setErrorByName('ai_embeddings][azure_ai][api_key_name', $this->t('The specified API key "@key" does not exist.', ['@key' => $api_key_name]));
        }
        elseif (empty($key->getKeyValue())) {
          $form_state->setErrorByName('ai_embeddings][azure_ai][api_key_name', $this->t('The specified API key "@key" exists but has no value.', ['@key' => $api_key_name]));
        }
      }
    }
  }

  /**
   * Checks if passwordless connection is likely being used.
   *
   * @return bool
   *   TRUE if this appears to be a passwordless connection setup.
   */
  protected function isPasswordlessConnection() {
    $password_key = $this->configuration['connection']['password_key'] ?? '';
    $direct_password = $this->configuration['connection']['password'] ?? '';
    
    // Both key and direct password are empty
    if (empty($password_key) && empty($direct_password)) {
      return TRUE;
    }
    
    // Check for common development/trust auth indicators
    $host = $this->configuration['connection']['host'] ?? '';
    $ssl_mode = $this->configuration['connection']['ssl_mode'] ?? 'require';
    
    // Localhost with weak SSL might indicate dev environment
    if (in_array($host, ['localhost', '127.0.0.1', 'database']) && in_array($ssl_mode, ['disable', 'allow'])) {
      return TRUE;
    }
    
    return FALSE;
  }
}