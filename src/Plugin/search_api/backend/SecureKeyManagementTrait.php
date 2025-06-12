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
   * Gets the database password from Key module.
   *
   * @return string
   *   The database password.
   *
   * @throws \RuntimeException
   *   If the password key is not found.
   */
  protected function getDatabasePassword() {
    $password_key = $this->configuration['connection']['password_key'] ?? '';
    
    if (empty($password_key)) {
      // Fallback to direct password if no key is configured
      return $this->configuration['connection']['password'] ?? '';
    }

    $key_repository = $this->getKeyRepository();
    if (!$key_repository) {
      throw new \RuntimeException('Key repository service not available. Is the Key module installed?');
    }

    $key = $key_repository->getKey($password_key);
    if ($key) {
      return $key->getKeyValue();
    }

    throw new \RuntimeException(sprintf('Database password key "%s" not found.', $password_key));
  }

  /**
   * Gets the API key for AI services from Key module.
   *
   * @return string
   *   The API key.
   *
   * @throws \RuntimeException
   *   If the API key is not found.
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
      return $this->configuration['vector_search']['api_key'] ?? 
             $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '';
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
    if (!$key_repository) {
      return;
    }

    // Get available keys
    $keys = [];
    foreach ($key_repository->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }

    // Database password key field
    if (isset($form['connection']['password'])) {
      $form['connection']['password_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Database Password Key'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key -'),
        '#default_value' => $this->configuration['connection']['password_key'] ?? '',
        '#description' => $this->t('Select a key that contains the database password.'),
        '#weight' => isset($form['connection']['password']['#weight']) ? $form['connection']['password']['#weight'] : 0,
      ];

      // Hide direct password field if key is selected
      $form['connection']['password']['#states'] = [
        'visible' => [
          ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
        ],
      ];
      $form['connection']['password']['#description'] = $this->t('Direct password entry. Using Key module is recommended for security.');
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
        '#description' => $this->t('Select a key that contains the Azure AI API key.'),
        '#states' => [
          'visible' => [
            ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      // Hide direct API key field if it exists
      if (isset($form['ai_embeddings']['azure_ai']['api_key'])) {
        $form['ai_embeddings']['azure_ai']['api_key']['#access'] = FALSE;
      }
    }

    // Vector search API key field
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