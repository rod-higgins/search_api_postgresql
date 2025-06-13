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
      '#description' => $this->t('Enable semantic vector search capabilities. Requires pgvector extension.'),
    ];

    // Provider selection
    $form['vector_search']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Provider'),
      '#options' => [
        'openai' => $this->t('OpenAI (GPT models)'),
        'huggingface' => $this->t('Hugging Face (Open source models)'),
        'local' => $this->t('Local Models (No API required)'),
      ],
      '#default_value' => $this->configuration['vector_search']['provider'] ?? 'openai',
      '#description' => $this->t('Choose your embedding provider.'),
      '#ajax' => [
        'callback' => '::updateProviderSettings',
        'wrapper' => 'provider-settings-wrapper',
      ],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Provider-specific settings wrapper
    $form['vector_search']['provider_settings'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'provider-settings-wrapper'],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $selected_provider = $form_state->getValue(['vector_search', 'provider']) ?? 
                         $this->configuration['vector_search']['provider'] ?? 'openai';

    $this->addProviderSpecificSettings($form, $selected_provider);

    // Common vector settings
    $form['vector_search']['common'] = [
      '#type' => 'details',
      '#title' => $this->t('Common Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['common']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['vector_search']['batch_size'] ?? 25,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to process in each batch.'),
    ];

    $form['vector_search']['common']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['vector_search']['rate_limit_delay'] ?? 100,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between API requests to avoid rate limiting.'),
    ];

    $form['vector_search']['common']['enable_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Embedding Cache'),
      '#default_value' => $this->configuration['vector_search']['enable_cache'] ?? TRUE,
      '#description' => $this->t('Cache embeddings to reduce API calls and improve performance.'),
    ];

    $form['vector_search']['common']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $this->configuration['vector_search']['cache_ttl'] ?? 3600,
      '#min' => 300,
      '#max' => 86400,
      '#description' => $this->t('How long to cache embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][common][enable_cache]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Vector Index Configuration
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Configure pgvector indexing for optimal performance.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Recommended)'),
        'hnsw' => $this->t('HNSW (Hierarchical Navigable Small World)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector indexing method. IVFFlat is recommended for most use cases.'),
    ];

    $form['vector_index']['distance'] = [
      '#type' => 'select',
      '#title' => $this->t('Distance Function'),
      '#options' => [
        'cosine' => $this->t('Cosine (Recommended for embeddings)'),
        'l2' => $this->t('Euclidean (L2)'),
        'inner_product' => $this->t('Inner Product'),
      ],
      '#default_value' => $this->configuration['vector_index']['distance'] ?? 'cosine',
      '#description' => $this->t('Distance function for vector similarity. Cosine is recommended for most embedding models.'),
    ];

    $form['vector_index']['lists'] = [
      '#type' => 'number',
      '#title' => $this->t('Lists (IVFFlat)'),
      '#default_value' => $this->configuration['vector_index']['lists'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of lists for IVFFlat index. Generally set to rows/1000.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    // Hybrid Search Configuration
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#open' => TRUE,
      '#description' => $this->t('Configure hybrid search combining traditional full-text and vector search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['hybrid_search']['enabled'] ?? TRUE,
      '#description' => $this->t('Combine traditional full-text search with vector similarity search.'),
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional full-text search (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'] ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity search (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Add provider-specific settings to the form.
   */
  protected function addProviderSpecificSettings(array &$form, $provider) {
    switch ($provider) {
      case 'openai':
        $form['vector_search']['provider_settings']['openai'] = [
          '#type' => 'details',
          '#title' => $this->t('OpenAI Configuration'),
          '#open' => TRUE,
        ];

        // Add API key fields using the trait method
        $this->addApiKeyFields(
          $form['vector_search']['provider_settings']['openai'],
          'vector_search][provider_settings][openai',
          'vector_search.openai'
        );

        $form['vector_search']['provider_settings']['openai']['model'] = [
          '#type' => 'select',
          '#title' => $this->t('Model'),
          '#options' => [
            'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
            'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
            'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
          ],
          '#default_value' => $this->configuration['vector_search']['openai']['model'] ?? 'text-embedding-3-small',
          '#description' => $this->t('OpenAI embedding model to use.'),
        ];

        $form['vector_search']['provider_settings']['openai']['organization'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Organization (Optional)'),
          '#default_value' => $this->configuration['vector_search']['openai']['organization'] ?? '',
          '#description' => $this->t('Your OpenAI organization ID (optional).'),
        ];

        $form['vector_search']['provider_settings']['openai']['base_url'] = [
          '#type' => 'url',
          '#title' => $this->t('Base URL'),
          '#default_value' => $this->configuration['vector_search']['openai']['base_url'] ?? 'https://api.openai.com/v1',
          '#description' => $this->t('OpenAI API base URL. Change for Azure OpenAI or custom endpoints.'),
        ];
        break;

      case 'huggingface':
        $form['vector_search']['provider_settings']['huggingface'] = [
          '#type' => 'details',
          '#title' => $this->t('Hugging Face Configuration'),
          '#open' => TRUE,
        ];

        // Add API key fields using the trait method
        $this->addApiKeyFields(
          $form['vector_search']['provider_settings']['huggingface'],
          'vector_search][provider_settings][huggingface',
          'vector_search.huggingface'
        );

        $form['vector_search']['provider_settings']['huggingface']['model'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Model Name'),
          '#default_value' => $this->configuration['vector_search']['huggingface']['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2',
          '#description' => $this->t('Hugging Face model name (e.g., sentence-transformers/all-MiniLM-L6-v2).'),
        ];
        break;

      case 'local':
        $form['vector_search']['provider_settings']['local'] = [
          '#type' => 'details',
          '#title' => $this->t('Local Model Configuration'),
          '#open' => TRUE,
        ];

        $form['vector_search']['provider_settings']['local']['model_path'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Model Path'),
          '#default_value' => $this->configuration['vector_search']['local']['model_path'] ?? '',
          '#description' => $this->t('Path to local embedding model.'),
        ];

        $form['vector_search']['provider_settings']['local']['model_type'] = [
          '#type' => 'select',
          '#title' => $this->t('Model Type'),
          '#options' => [
            'sentence_transformers' => $this->t('Sentence Transformers'),
            'transformers' => $this->t('Hugging Face Transformers'),
            'word2vec' => $this->t('Word2Vec'),
          ],
          '#default_value' => $this->configuration['vector_search']['local']['model_type'] ?? 'sentence_transformers',
          '#description' => $this->t('Type of local model.'),
        ];
        break;
    }
  }

  /**
   * AJAX callback to update provider settings.
   */
  public function updateProviderSettings(array &$form, FormStateInterface $form_state) {
    $provider = $form_state->getValue(['vector_search', 'provider']);
    $this->addProviderSpecificSettings($form, $provider);
    
    return $form['vector_search']['provider_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Validate vector search configuration
    if (!empty($values['vector_search']['enabled'])) {
      $provider = $values['vector_search']['provider'] ?? '';
      
      switch ($provider) {
        case 'openai':
          $api_key_name = $values['vector_search']['provider_settings']['openai']['api_key_name'] ?? '';
          $direct_api_key = $values['vector_search']['provider_settings']['openai']['api_key'] ?? '';
          
          if (empty($api_key_name) && empty($direct_api_key)) {
            $form_state->setErrorByName('vector_search][provider_settings][openai][api_key', 
              $this->t('OpenAI API key is required.'));
          }
          break;

        case 'huggingface':
          $api_key_name = $values['vector_search']['provider_settings']['huggingface']['api_key_name'] ?? '';
          $direct_api_key = $values['vector_search']['provider_settings']['huggingface']['api_key'] ?? '';
          
          if (empty($api_key_name) && empty($direct_api_key)) {
            $form_state->setErrorByName('vector_search][provider_settings][huggingface][api_key', 
              $this->t('Hugging Face API key is required.'));
          }
          break;

        case 'local':
          if (empty($values['vector_search']['provider_settings']['local']['model_path'])) {
            $form_state->setErrorByName('vector_search][provider_settings][local][model_path', 
              $this->t('Local model path is required.'));
          }
          break;
      }

      // Validate hybrid search weights
      if (!empty($values['hybrid_search']['enabled'])) {
        $text_weight = (float) ($values['hybrid_search']['text_weight'] ?? 0.6);
        $vector_weight = (float) ($values['hybrid_search']['vector_weight'] ?? 0.4);
        
        if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
          $form_state->setErrorByName('hybrid_search][text_weight', 
            $this->t('Text and vector weights should sum to 1.0 (currently: @sum)', [
              '@sum' => number_format($text_weight + $vector_weight, 2)
            ]));
        }
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
      // Save provider settings
      if (isset($values['vector_search']['provider_settings'])) {
        $provider = $values['vector_search']['provider'];
        if (isset($values['vector_search']['provider_settings'][$provider])) {
          $this->configuration['vector_search'][$provider] = $values['vector_search']['provider_settings'][$provider];
        }
      }

      // Save common settings
      if (isset($values['vector_search']['common'])) {
        $this->configuration['vector_search'] = array_merge(
          $this->configuration['vector_search'],
          $values['vector_search']['common']
        );
      }

      // Save basic settings
      $this->configuration['vector_search']['enabled'] = !empty($values['vector_search']['enabled']);
      $this->configuration['vector_search']['provider'] = $values['vector_search']['provider'];
    }

    // Save vector index configuration
    if (isset($values['vector_index'])) {
      $this->configuration['vector_index'] = $values['vector_index'];
    }

    // Save hybrid search configuration
    if (isset($values['hybrid_search'])) {
      $this->configuration['hybrid_search'] = $values['hybrid_search'];
    }
  }

  /**
   * Get API key for the specified provider.
   */
  protected function getApiKey($provider, $config = []) {
    if (empty($config)) {
      $config = $this->configuration['vector_search'][$provider] ?? [];
    }

    $api_key_name = $config['api_key_name'] ?? '';
    $direct_key = $config['api_key'] ?? '';

    // Try key module first
    if (!empty($api_key_name) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($api_key_name);
      if ($key) {
        $key_value = $key->getKeyValue();
        if (!empty($key_value)) {
          return $key_value;
        }
      }
      // Log warning but don't throw exception - allow fallback
      \Drupal::logger('search_api_postgresql')->warning('@provider API key "@key" not found or empty. Falling back to direct key.', [
        '@provider' => ucfirst($provider),
        '@key' => $api_key_name,
      ]);
    }

    return $direct_key;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    if ($this->configuration['vector_search']['enabled']) {
      $features[] = 'search_api_vector_search';
      $features[] = 'search_api_semantic_search';
      $features[] = 'search_api_hybrid_search';
      $features[] = 'search_api_multi_provider_embeddings';
    }
    
    return $features;
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(\Drupal\search_api\IndexInterface $index) {
    // Check if pgvector extension is installed
    $this->checkPgVectorExtension();
    
    return parent::addIndex($index);
  }

  /**
   * Checks if pgvector extension is available.
   */
  protected function checkPgVectorExtension() {
    if (!$this->configuration['vector_search']['enabled']) {
      return;
    }

    try {
      $this->connect();
      $sql = "SELECT 1 FROM pg_extension WHERE extname = 'vector'";
      $result = $this->connector->executeQuery($sql);
      
      if (!$result->fetchColumn()) {
        // Try to create the extension
        $this->connector->executeQuery("CREATE EXTENSION IF NOT EXISTS vector");
        $this->logger->info('pgvector extension created successfully.');
      }
    }
    catch (\Exception $e) {
      throw new SearchApiException('pgvector extension is required for vector search: ' . $e->getMessage());
    }
  }

}