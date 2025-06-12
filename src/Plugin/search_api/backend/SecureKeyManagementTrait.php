<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

/**
 * Provides secure key management functionality for backends.
 * 
 * This trait is used by all three PostgreSQL backends:
 * - PostgreSQLBackend
 * - AzurePostgreSQLBackend  
 * - PostgreSQLVectorBackend
 */
trait SecureKeyManagementTrait {

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
    if (!isset($this->keyRepository)) {
      // Try to load it if not injected
      if (\Drupal::hasService('key.repository')) {
        $this->keyRepository = \Drupal::service('key.repository');
      }
    }
    return $this->keyRepository;
  }

  /**
   * Gets the database password from configuration.
   *
   * @return string|null
   *   The database password or NULL if not set.
   */
  protected function getDatabasePassword() {
    // First try to get password from Key module if configured
    if (!empty($this->configuration['connection']['password_key'])) {
      try {
        $key_repository = $this->getKeyRepository();
        if ($key_repository) {
          $key = $key_repository->getKey($this->configuration['connection']['password_key']);
          if ($key) {
            $password = $key->getKeyValue();
            if (!empty($password)) {
              return $password;
            }
          }
        }
      }
      catch (\Exception $e) {
        // Log error but continue to try direct password
        $this->getLogger()->warning('Failed to retrieve password from key: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
    
    // Fall back to direct password if available
    return $this->configuration['connection']['password'] ?? NULL;
  }

  /**
   * Gets the Azure API key from configuration.
   *
   * @return string|null
   *   The API key or NULL if not set.
   */
  protected function getAzureApiKey() {
    $key_name = isset($this->configuration['azure_embedding']) 
      ? $this->configuration['azure_embedding']['api_key_name'] ?? '' 
      : $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '';

    if (empty($key_name)) {
      return NULL;
    }

    $key_repository = $this->getKeyRepository();
    if (!$key_repository) {
      throw new \RuntimeException('Key repository service not available. Is the Key module installed?');
    }

    $key = $key_repository->getKey($key_name);
    if ($key) {
      return $key->getKeyValue();
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
    
    // Always add the password field first (for optional direct entry)
    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password (Optional)'),
      '#description' => $this->t('Database password. Leave empty if no password is required (e.g., local development with Lando).'),
      '#required' => FALSE,
      '#weight' => 5,
    ];
    
    // If Key module is available, add key selection as an alternative
    if ($key_repository) {
      // Get available keys
      $keys = [];
      foreach ($key_repository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      // Database password key field
      $form['connection']['password_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Database Password Key (Recommended)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- None / Use direct password -'),
        '#default_value' => $this->configuration['connection']['password_key'] ?? '',
        '#description' => $this->t('Select a key that contains the database password. This is more secure than entering the password directly.'),
        '#weight' => 4,
      ];

      // Show help text about security
      $form['connection']['password_security_note'] = [
        '#type' => 'item',
        '#markup' => $this->t('<strong>Security Note:</strong> For production environments, use the Key module to store passwords securely. For local development (e.g., Lando), you can leave both fields empty if no password is required.'),
        '#weight' => 6,
      ];

      // Update password field to indicate it's a fallback
      $form['connection']['password']['#title'] = $this->t('Password (Direct Entry)');
      $form['connection']['password']['#description'] = $this->t('Direct password entry. Leave empty if using a key above or if no password is required.');
      
      // Only show direct password field if no key is selected
      $form['connection']['password']['#states'] = [
        'visible' => [
          ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
        ],
      ];

      // AI API key field handling (if applicable)
      if (isset($form['ai_embeddings']['azure_ai'])) {
        $form['ai_embeddings']['azure_ai']['api_key_name'] = [
          '#type' => 'select',
          '#title' => $this->t('API Key'),
          '#options' => $keys,
          '#empty_option' => $this->t('- Select a key -'),
          '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '',
          '#description' => $this->t('Select a key that contains the Azure AI API key.'),
          '#states' => [
            'visible' => [
              ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];

        // Remove direct API key field for security if it exists
        if (isset($form['ai_embeddings']['azure_ai']['api_key'])) {
          unset($form['ai_embeddings']['azure_ai']['api_key']);
        }
      }

      // Vector search API key field (if applicable)
      if (isset($form['vector_search']) && isset($form['vector_search']['api_key'])) {
        $form['vector_search']['api_key_name'] = [
          '#type' => 'select',
          '#title' => $this->t('API Key'),
          '#options' => $keys,
          '#empty_option' => $this->t('- Select a key -'),
          '#default_value' => $this->configuration['vector_search']['api_key_name'] ?? '',
          '#description' => $this->t('Select a key that contains the API key.'),
          '#states' => [
            'visible' => [
              ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];

        // Hide direct API key field if using key module
        $form['vector_search']['api_key']['#states'] = [
          'visible' => [
            ':input[name="backend_config[vector_search][api_key_name]"]' => ['value' => ''],
          ],
        ];
        $form['vector_search']['api_key']['#description'] = $this->t('Direct API key entry. Using Key module is recommended for security.');
      }
    }
  }

  /**
   * Validates key configuration in a form.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $field_name
   *   The field name to validate.
   * @param string $key_type
   *   The type of key (e.g., 'Database password', 'API key').
   */
  protected function validateKeyField(array &$form, $form_state, $field_name, $key_type) {
    $key_name = $form_state->getValue($field_name);
    if (!empty($key_name)) {
      $key_repository = $this->getKeyRepository();
      if ($key_repository) {
        $key = $key_repository->getKey($key_name);
        if (!$key) {
          $form_state->setErrorByName($field_name, $this->t('@type key "@key" not found.', [
            '@type' => $key_type,
            '@key' => $key_name,
          ]));
        }
      }
    }
  }
}