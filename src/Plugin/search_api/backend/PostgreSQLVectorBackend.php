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

    try {
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

      // Provider selection - NO AJAX, uses form states
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
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      // Provider-specific settings - MATCH SCHEMA: direct under vector_search, not in wrapper
      $this->addAllProviderSettings($form['vector_search']);

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

      $form['vector_search']['common']['max_retries'] = [
        '#type' => 'number',
        '#title' => $this->t('Max Retries'),
        '#default_value' => $this->configuration['vector_search']['max_retries'] ?? 3,
        '#min' => 0,
        '#max' => 10,
        '#description' => $this->t('Maximum number of retry attempts for failed API calls.'),
      ];

      $form['vector_search']['common']['timeout'] = [
        '#type' => 'number',
        '#title' => $this->t('Timeout (seconds)'),
        '#default_value' => $this->configuration['vector_search']['timeout'] ?? 30,
        '#min' => 5,
        '#max' => 120,
        '#description' => $this->t('Request timeout in seconds.'),
      ];

      $form['vector_search']['common']['enable_cache'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Embedding Cache'),
        '#default_value' => $this->configuration['vector_search']['enable_cache'] ?? TRUE,
        '#description' => $this->t('Cache embedding results to improve performance.'),
      ];

      $form['vector_search']['common']['cache_ttl'] = [
        '#type' => 'number',
        '#title' => $this->t('Cache TTL (seconds)'),
        '#default_value' => $this->configuration['vector_search']['cache_ttl'] ?? 3600,
        '#min' => 300,
        '#max' => 86400,
        '#description' => $this->t('How long to cache embedding results.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][common][enable_cache]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      // Vector index configuration
      $form['vector_index'] = [
        '#type' => 'details',
        '#title' => $this->t('Vector Index Configuration'),
        '#open' => FALSE,
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
          'ivfflat' => $this->t('IVFFlat (Faster search, more memory)'),
          'hnsw' => $this->t('HNSW (Hierarchical NSW)'),
        ],
        '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
        '#description' => $this->t('Vector indexing method for pgvector.'),
      ];

      $form['vector_index']['lists'] = [
        '#type' => 'number',
        '#title' => $this->t('Lists (IVFFlat only)'),
        '#default_value' => $this->configuration['vector_index']['lists'] ?? 100,
        '#min' => 1,
        '#max' => 10000,
        '#description' => $this->t('Number of cluster centroids for IVFFlat index.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
          ],
        ],
      ];

      $form['vector_index']['probes'] = [
        '#type' => 'number',
        '#title' => $this->t('Probes (IVFFlat only)'),
        '#default_value' => $this->configuration['vector_index']['probes'] ?? 10,
        '#min' => 1,
        '#max' => 1000,
        '#description' => $this->t('Number of lists to probe during search.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
          ],
        ],
      ];

      $form['vector_index']['distance'] = [
        '#type' => 'select',
        '#title' => $this->t('Distance Function'),
        '#options' => [
          'cosine' => $this->t('Cosine Distance'),
          'l2' => $this->t('Euclidean Distance (L2)'),
          'inner_product' => $this->t('Inner Product'),
        ],
        '#default_value' => $this->configuration['vector_index']['distance'] ?? 'cosine',
        '#description' => $this->t('Distance function for vector comparisons.'),
      ];

      // Hybrid search configuration
      $form['hybrid_search'] = [
        '#type' => 'details',
        '#title' => $this->t('Hybrid Search Configuration'),
        '#open' => FALSE,
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
        '#description' => $this->t('Combine traditional text search with vector similarity search.'),
      ];

      $form['hybrid_search']['text_weight'] = [
        '#type' => 'number',
        '#title' => $this->t('Text Search Weight'),
        '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.6,
        '#min' => 0,
        '#max' => 1,
        '#step' => 0.1,
        '#description' => $this->t('Weight for traditional text search (0-1).'),
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

      $form['hybrid_search']['similarity_threshold'] = [
        '#type' => 'number',
        '#title' => $this->t('Similarity Threshold'),
        '#default_value' => $this->configuration['hybrid_search']['similarity_threshold'] ?? 0.15,
        '#min' => 0,
        '#max' => 1,
        '#step' => 0.01,
        '#description' => $this->t('Minimum similarity score for vector search results.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      \Drupal::logger('search_api_postgresql')->notice('Form built successfully with @count elements', [
        '@count' => count($form)
      ]);
      
      return $form;

    } catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Exception in buildConfigurationForm: @message', [
        '@message' => $e->getMessage(),
        '@file' => $e->getFile(),
        '@line' => $e->getLine()
      ]);
      
      return [
        'error' => [
          '#type' => 'markup',
          '#markup' => '<div style="color: red; padding: 10px; border: 1px solid red;">Error building form: ' . $e->getMessage() . '</div>',
        ],
      ];
    } 
  }

  /**
   * Add all provider-specific settings with form states for visibility.
   */
  protected function addAllProviderSettings(array &$form_section) {
    // OpenAI Settings - MATCH SCHEMA: direct under vector_search, not under provider_settings
    $form_section['openai'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $this->addProviderApiKeyFields($form_section['openai'], 'openai');

    $form_section['openai']['model'] = [
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

    $form_section['openai']['organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization (Optional)'),
      '#default_value' => $this->configuration['vector_search']['openai']['organization'] ?? '',
      '#description' => $this->t('Your OpenAI organization ID (optional).'),
    ];

    $form_section['openai']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->configuration['vector_search']['openai']['base_url'] ?? 'https://api.openai.com/v1',
      '#description' => $this->t('OpenAI API base URL. Change for Azure OpenAI or custom endpoints.'),
    ];

    // Hugging Face Settings - MATCH SCHEMA: direct under vector_search
    $form_section['huggingface'] = [
      '#type' => 'details',
      '#title' => $this->t('Hugging Face Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'huggingface'],
        ],
      ],
    ];

    $this->addProviderApiKeyFields($form_section['huggingface'], 'huggingface');

    $form_section['huggingface']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Name'),
      '#default_value' => $this->configuration['vector_search']['huggingface']['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2',
      '#description' => $this->t('Hugging Face model name (e.g., sentence-transformers/all-MiniLM-L6-v2).'),
    ];

    // Local Model Settings - MATCH SCHEMA: direct under vector_search
    $form_section['local'] = [
      '#type' => 'details',
      '#title' => $this->t('Local Model Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'local'],
        ],
      ],
    ];

    $form_section['local']['model_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Path'),
      '#default_value' => $this->configuration['vector_search']['local']['model_path'] ?? '',
      '#description' => $this->t('Path to local embedding model.'),
    ];

    $form_section['local']['model_type'] = [
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
  }

  /**
   * Add API key fields for specific provider.
   */
  protected function addProviderApiKeyFields(array &$form_section, $provider) {
    if (!empty($this->keyRepository)) {
      // Get available keys
      $keys = [];
      foreach ($this->keyRepository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form_section['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('API Key (Key Module)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct entry below -'),
        '#default_value' => $this->configuration['vector_search'][$provider]['api_key_name'] ?? '',
        '#description' => $this->t('Select a key containing your @provider API key.', ['@provider' => ucfirst($provider)]),
      ];

      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Direct API Key (Fallback)'),
        '#description' => $this->t('Direct API key entry. Only used if no key is selected above.'),
        '#states' => [
          'visible' => [
            // FIXED: Match schema structure - no provider_settings nesting
            ':input[name="backend_config[vector_search][' . $provider . '][api_key_name]"]' => ['value' => ''],
          ],
        ],
      ];
    } else {
      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Your @provider API key. Using Key module is recommended for production.', ['@provider' => ucfirst($provider)]),
      ];
    }
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
          // FIXED: Match schema structure - direct under vector_search
          $api_key_name = $values['vector_search']['openai']['api_key_name'] ?? '';
          $direct_api_key = $values['vector_search']['openai']['api_key'] ?? '';
          
          if (empty($api_key_name) && empty($direct_api_key)) {
            $form_state->setErrorByName('vector_search][openai][api_key', 
              $this->t('OpenAI API key is required.'));
          }
          break;

        case 'huggingface':
          // FIXED: Match schema structure - direct under vector_search
          $api_key_name = $values['vector_search']['huggingface']['api_key_name'] ?? '';
          $direct_api_key = $values['vector_search']['huggingface']['api_key'] ?? '';
          
          if (empty($api_key_name) && empty($direct_api_key)) {
            $form_state->setErrorByName('vector_search][huggingface][api_key', 
              $this->t('Hugging Face API key is required.'));
          }
          break;

        case 'local':
          // FIXED: Match schema structure - direct under vector_search
          if (empty($values['vector_search']['local']['model_path'])) {
            $form_state->setErrorByName('vector_search][local][model_path', 
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

    // Save vector search configuration - MATCH SCHEMA STRUCTURE
    if (isset($values['vector_search'])) {
      // Save basic settings
      $this->configuration['vector_search']['enabled'] = !empty($values['vector_search']['enabled']);
      $this->configuration['vector_search']['provider'] = $values['vector_search']['provider'];

      // Save provider-specific settings - MATCH SCHEMA: direct under vector_search
      $provider = $values['vector_search']['provider'];
      if (isset($values['vector_search'][$provider])) {
        $this->configuration['vector_search'][$provider] = $values['vector_search'][$provider];
      }

      // Save common settings - MATCH SCHEMA: direct under vector_search
      if (isset($values['vector_search']['common'])) {
        $common_settings = $values['vector_search']['common'];
        $this->configuration['vector_search']['batch_size'] = $common_settings['batch_size'] ?? 25;
        $this->configuration['vector_search']['rate_limit_delay'] = $common_settings['rate_limit_delay'] ?? 100;
        $this->configuration['vector_search']['max_retries'] = $common_settings['max_retries'] ?? 3;
        $this->configuration['vector_search']['timeout'] = $common_settings['timeout'] ?? 30;
        $this->configuration['vector_search']['enable_cache'] = !empty($common_settings['enable_cache']);
        $this->configuration['vector_search']['cache_ttl'] = $common_settings['cache_ttl'] ?? 3600;
      }
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

    if (!empty($api_key_name) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($api_key_name);
      if ($key) {
        return $key->getKeyValue();
      }
    }

    return $direct_key;
  }

}