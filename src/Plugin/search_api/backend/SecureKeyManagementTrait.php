<?php

/**
 * @file
 * Key integration methods for PostgreSQL backend classes.
 * 
 * Add these methods to your PostgreSQLBackend base class.
 */

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
   * Gets the key repository service.
   *
   * @return \Drupal\key\KeyRepositoryInterface
   *   The key repository.
   */
  protected function getKeyRepository() {
    if (!$this->keyRepository) {
      $this->keyRepository = \Drupal::service('key.repository');
    }
    return $this->keyRepository;
  }

  /**
   * Gets the database password from Key module.
   *
   * @return string
   *   The database password.
   */
  protected function getDatabasePassword() {
    $password_key = $this->configuration['connection']['password_key'] ?? '';
    
    if (empty($password_key)) {
      // Fallback to direct password if no key is configured
      return $this->configuration['connection']['password'] ?? '';
    }

    $key = $this->getKeyRepository()->getKey($password_key);
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

    $key = $this->getKeyRepository()->getKey($key_name);
    if ($key) {
      return $key->getKeyValue();
    }

    throw new \RuntimeException(sprintf('API key "%s" not found.', $key_name));
  }

  /**
   * Updates the buildConfigurationForm to use Key module.
   */
  protected function addKeyFieldsToForm(&$form) {
    // Get available keys
    $keys = [];
    foreach ($this->getKeyRepository()->getKeys() as $key) {
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
        '#weight' => $form['connection']['password']['#weight'] ?? 0,
      ];

      // Hide direct password field if key is selected
      $form['connection']['password']['#states'] = [
        'visible' => [
          ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
        ],
      ];
      $form['connection']['password']['#description'] = $this->t('Direct password entry. Using Key module is recommended for security.');
    }

    // AI API key field
    if (isset($form['ai_embeddings']['azure_ai']['api_key'])) {
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

      // Remove direct API key field for security
      unset($form['ai_embeddings']['azure_ai']['api_key']);
    }

    // Vector search API key field
    if (isset($form['vector_search']['api_key'])) {
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

      // Remove direct API key field for security
      unset($form['vector_search']['api_key']);
    }
  }

  /**
   * Validates key configuration.
   */
  protected function validateKeyConfiguration($form, $form_state) {
    // Validate database password key
    $password_key = $form_state->getValue(['connection', 'password_key']);
    $password = $form_state->getValue(['connection', 'password']);
    
    if (empty($password_key) && empty($password)) {
      $form_state->setError($form['connection']['password_key'], 
        $this->t('Either a password key or direct password must be provided.'));
    }

    // Validate AI API key if enabled
    if ($form_state->getValue(['ai_embeddings', 'enabled'])) {
      $api_key_name = $form_state->getValue(['ai_embeddings', 'azure_ai', 'api_key_name']);
      
      if (empty($api_key_name)) {
        $form_state->setError($form['ai_embeddings']['azure_ai']['api_key_name'], 
          $this->t('An API key is required when AI embeddings are enabled.'));
      }
    }

    // Validate vector search API key if enabled
    if ($form_state->getValue(['vector_search', 'enabled'])) {
      $api_key_name = $form_state->getValue(['vector_search', 'api_key_name']);
      
      if (empty($api_key_name)) {
        $form_state->setError($form['vector_search']['api_key_name'], 
          $this->t('An API key is required when vector search is enabled.'));
      }
    }
  }

  /**
   * Initialize embedding service with secure API key.
   */
  protected function initializeEmbeddingServiceSecurely() {
    try {
      $api_key = $this->getAiApiKey();
      
      if (empty($api_key)) {
        throw new \RuntimeException('No API key configured for embedding service.');
      }

      // Initialize based on configuration
      if (!empty($this->configuration['ai_embeddings']['enabled'])) {
        // Azure OpenAI configuration
        $config = $this->configuration['ai_embeddings']['azure_ai'];
        $this->embeddingService = new \Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService(
          $config['endpoint'],
          $api_key,
          $config['deployment_name'],
          $config['api_version'] ?? '2023-05-15',
          \Drupal::logger('search_api_postgresql')
        );
      }
      elseif (!empty($this->configuration['vector_search']['enabled'])) {
        // Direct OpenAI configuration
        $model = $this->configuration['vector_search']['model'] ?? 'text-embedding-ada-002';
        $this->embeddingService = new \Drupal\search_api_postgresql\Service\OpenAIEmbeddingService(
          $api_key,
          $model,
          \Drupal::logger('search_api_postgresql')
        );
      }

      // Wrap in resilient service
      if ($this->embeddingService) {
        $this->embeddingService = new \Drupal\search_api_postgresql\Service\ResilientEmbeddingService(
          $this->embeddingService,
          \Drupal::service('search_api_postgresql.circuit_breaker'),
          \Drupal::service('search_api_postgresql.cache_manager'),
          \Drupal::logger('search_api_postgresql'),
          $this->configuration
        );
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Failed to initialize embedding service: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Service remains null, graceful degradation will handle
    }
  }
}

/**
 * Example of how to use in your backend class:
 *
 * class PostgreSQLBackend extends BackendPluginBase {
 *   use SecureKeyManagementTrait;
 *   
 *   public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
 *     $form = parent::buildConfigurationForm($form, $form_state);
 *     // ... your existing form building code ...
 *     
 *     // Add key fields
 *     $this->addKeyFieldsToForm($form);
 *     
 *     return $form;
 *   }
 *   
 *   public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
 *     parent::validateConfigurationForm($form, $form_state);
 *     $this->validateKeyConfiguration($form, $form_state);
 *   }
 *   
 *   protected function connect() {
 *     // Get secure password
 *     $password = $this->getDatabasePassword();
 *     
 *     // Use in connection...
 *   }
 * }
 */