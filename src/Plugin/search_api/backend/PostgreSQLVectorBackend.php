<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;

/**
 * PostgreSQL backend with flexible vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql_vector",
 *   label = @Translation("PostgreSQL with Multi-Provider Vector Search"),
 *   description = @Translation("PostgreSQL backend supporting multiple AI embedding providers (OpenAI, Hugging Face, Local models).")
 * )
 */
class PostgreSQLVectorBackend extends PostgreSQLBackend {

  use SecureKeyManagementTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    
    // Remove the Azure-specific ai_embeddings config
    unset($config['ai_embeddings']);
    
    // Add flexible vector search configuration
    $config['vector_search'] = [
      'enabled' => FALSE,
      'provider' => 'openai',
      'openai' => [
        'api_key' => '',
        'api_key_name' => '',
        'model' => 'text-embedding-3-small',
        'organization' => '',
        'base_url' => 'https://api.openai.com/v1',
        'dimension' => 1536,
      ],
      'huggingface' => [
        'api_key' => '',
        'api_key_name' => '',
        'model' => 'sentence-transformers/all-MiniLM-L6-v2',
        'dimension' => 384,
      ],
      'local' => [
        'model_path' => '',
        'model_type' => 'sentence_transformers',
        'dimension' => 384,
      ],
      'batch_size' => 25,
      'rate_limit_delay' => 100,
      'max_retries' => 3,
      'timeout' => 30,
      'enable_cache' => TRUE,
      'cache_ttl' => 3600,
    ];

    $config['vector_index'] = [
      'method' => 'ivfflat',
      'lists' => 100,
      'probes' => 10,
      'distance' => 'cosine',
    ];

    $config['hybrid_search'] = [
      'enabled' => TRUE,
      'text_weight' => 0.6,
      'vector_weight' => 0.4,
      'similarity_threshold' => 0.15,
    ];

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    \Drupal::logger('search_api_postgresql')->notice('buildConfigurationForm called for @class', [
      '@class' => static::class
    ]);

    // Get base form from parent but remove AI embeddings
    $form = parent::buildConfigurationForm($form, $form_state);
    unset($form['ai_embeddings']);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Vector search configuration
    $form['vector_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Search Configuration'),
      '#open' => !empty($this->configuration['vector_search']['enabled']),
      '#description' => $this->t('Configure semantic vector search using AI embeddings from multiple providers.'),
    ];

    $form['vector_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Vector Search'),
      '#default_value' => $this->configuration['vector_search']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using AI embeddings from various providers.'),
    ];

    $form['vector_search']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Provider'),
      '#options' => [
        'openai' => $this->t('OpenAI'),
        'huggingface' => $this->t('Hugging Face'),
        'local' => $this->t('Local Models'),
      ],
      '#default_value' => $this->configuration['vector_search']['provider'] ?? 'openai',
      '#description' => $this->t('Choose the AI embedding provider to use for vector search.'),
      '#states' => [
        'visible' => [
          ':input[name="vector_search[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // OpenAI Configuration
    $form['vector_search']['openai'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI Configuration'),
      '#open' => ($this->configuration['vector_search']['provider'] ?? 'openai') === 'openai',
      '#states' => [
        'visible' => [
          ':input[name="vector_search[enabled]"]' => ['checked' => TRUE],
          ':input[name="vector_search[provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['vector_search']['openai']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('OpenAI API Key'),
      '#default_value' => $this->configuration['vector_search']['openai']['api_key'] ?? '',
      '#description' => $this->t('Your OpenAI API key. Leave empty if using key management below.'),
    ];

    // Add OpenAI API key selection if Key module is available
    if ($this->getKeyRepository()) {
      $keys = [];
      foreach ($this->getKeyRepository()->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form['vector_search']['openai']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('OpenAI API Key (Key Management)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct API key above -'),
        '#default_value' => $this->configuration['vector_search']['openai']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key from the Key module for secure API key storage.'),
      ];
    }

    $form['vector_search']['openai']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('OpenAI Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions, efficient)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions, higher quality)'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions, legacy)'),
      ],
      '#default_value' => $this->configuration['vector_search']['openai']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Choose the OpenAI embedding model.'),
    ];

    // Hugging Face Configuration
    $form['vector_search']['huggingface'] = [
      '#type' => 'details',
      '#title' => $this->t('Hugging Face Configuration'),
      '#open' => ($this->configuration['vector_search']['provider'] ?? 'openai') === 'huggingface',
      '#states' => [
        'visible' => [
          ':input[name="vector_search[enabled]"]' => ['checked' => TRUE],
          ':input[name="vector_search[provider]"]' => ['value' => 'huggingface'],
        ],
      ],
    ];

    $form['vector_search']['huggingface']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Hugging Face API Key'),
      '#default_value' => $this->configuration['vector_search']['huggingface']['api_key'] ?? '',
      '#description' => $this->t('Your Hugging Face API key. Leave empty if using key management below.'),
    ];

    // Add Hugging Face API key selection if Key module is available
    if ($this->getKeyRepository()) {
      $keys = [];
      foreach ($this->getKeyRepository()->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form['vector_search']['huggingface']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Hugging Face API Key (Key Management)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct API key above -'),
        '#default_value' => $this->configuration['vector_search']['huggingface']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key from the Key module for secure API key storage.'),
      ];
    }

    $form['vector_search']['huggingface']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Name'),
      '#default_value' => $this->configuration['vector_search']['huggingface']['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2',
      '#description' => $this->t('Hugging Face model name (e.g., "sentence-transformers/all-MiniLM-L6-v2").'),
    ];

    // Local Models Configuration
    $form['vector_search']['local'] = [
      '#type' => 'details',
      '#title' => $this->t('Local Models Configuration'),
      '#open' => ($this->configuration['vector_search']['provider'] ?? 'openai') === 'local',
      '#states' => [
        'visible' => [
          ':input[name="vector_search[enabled]"]' => ['checked' => TRUE],
          ':input[name="vector_search[provider]"]' => ['value' => 'local'],
        ],
      ],
    ];

    $form['vector_search']['local']['model_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Path'),
      '#default_value' => $this->configuration['vector_search']['local']['model_path'] ?? '',
      '#description' => $this->t('Local path to the embedding model files.'),
    ];

    $form['vector_search']['local']['model_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Model Type'),
      '#options' => [
        'sentence_transformers' => $this->t('Sentence Transformers'),
        'transformers' => $this->t('Transformers'),
        'custom' => $this->t('Custom'),
      ],
      '#default_value' => $this->configuration['vector_search']['local']['model_type'] ?? 'sentence_transformers',
      '#description' => $this->t('Type of local model framework.'),
    ];

    // Performance settings
    $form['vector_search']['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="vector_search[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['vector_search']['batch_size'] ?? 25,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of items to process in each batch.'),
    ];

    $form['vector_search']['performance']['enable_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Embedding Cache'),
      '#default_value' => $this->configuration['vector_search']['enable_cache'] ?? TRUE,
      '#description' => $this->t('Cache embeddings to reduce API calls and improve performance.'),
    ];

    // Enhanced vector index configuration
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Advanced settings for vector similarity search performance.'),
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Recommended)'),
        'hnsw' => $this->t('HNSW (Higher accuracy)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector indexing method. IVFFlat is faster for most use cases.'),
    ];

    $form['vector_index']['distance'] = [
      '#type' => 'select',
      '#title' => $this->t('Distance Function'),
      '#options' => [
        'cosine' => $this->t('Cosine (Recommended)'),
        'euclidean' => $this->t('Euclidean'),
        'manhattan' => $this->t('Manhattan'),
      ],
      '#default_value' => $this->configuration['vector_index']['distance'] ?? 'cosine',
      '#description' => $this->t('Distance function for vector similarity calculations.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    
    $values = $form_state->getValues();
    
    // Validate vector search configuration
    if (!empty($values['vector_search']['enabled'])) {
      $vector_config = $values['vector_search'] ?? [];
      $provider = $vector_config['provider'] ?? '';
      
      if (empty($provider)) {
        $form_state->setErrorByName('vector_search][provider', $this->t('Provider must be selected when vector search is enabled.'));
        return;
      }

      // Validate provider-specific settings
      switch ($provider) {
        case 'openai':
          $openai_config = $vector_config['openai'] ?? [];
          
          if (empty($openai_config['api_key']) && empty($openai_config['api_key_name'])) {
            $form_state->setErrorByName('vector_search][openai][api_key', 
              $this->t('Either API key or key name must be provided for OpenAI provider.'));
          }
          break;

        case 'huggingface':
          $hf_config = $vector_config['huggingface'] ?? [];
          
          if (empty($hf_config['api_key']) && empty($hf_config['api_key_name'])) {
            $form_state->setErrorByName('vector_search][huggingface][api_key', 
              $this->t('Either API key or key name must be provided for Hugging Face provider.'));
          }
          break;

        case 'local':
          $local_config = $vector_config['local'] ?? [];
          
          if (empty($local_config['model_path'])) {
            $form_state->setErrorByName('vector_search][local][model_path', 
              $this->t('Model path must be provided for local provider.'));
          }
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Save vector search configuration
    if (isset($values['vector_search'])) {
      $this->configuration['vector_search'] = $values['vector_search'];
    }

    // Save vector index configuration
    if (isset($values['vector_index'])) {
      $this->configuration['vector_index'] = $values['vector_index'];
    }
  }

  /**
   * Gets API key for the selected provider using secure key management.
   *
   * @return string
   *   The API key for the current provider.
   */
  protected function getProviderApiKey() {
    $provider = $this->configuration['vector_search']['provider'] ?? '';
    
    switch ($provider) {
      case 'openai':
        $api_key_name = $this->configuration['vector_search']['openai']['api_key_name'] ?? '';
        $direct_key = $this->configuration['vector_search']['openai']['api_key'] ?? '';
        return $this->getSecureKey($api_key_name, $direct_key, 'OpenAI API key', FALSE);
        
      case 'huggingface':
        $api_key_name = $this->configuration['vector_search']['huggingface']['api_key_name'] ?? '';
        $direct_key = $this->configuration['vector_search']['huggingface']['api_key'] ?? '';
        return $this->getSecureKey($api_key_name, $direct_key, 'Hugging Face API key', FALSE);
        
      default:
        return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    // Add vector search specific features
    $features[] = 'search_api_multi_provider_embeddings';
    $features[] = 'search_api_flexible_vector_search';
    $features[] = 'search_api_local_models';
    
    return $features;
  }

}