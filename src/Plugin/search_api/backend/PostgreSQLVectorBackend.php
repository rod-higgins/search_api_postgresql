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
    
    // Remove the standard ai_embeddings config
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
    try {
      // Get base form from parent but remove ai_embeddings
      $form = parent::buildConfigurationForm($form, $form_state);
      unset($form['ai_embeddings']);

      // Ensure we have default configuration
      $this->configuration = $this->configuration + $this->defaultConfiguration();

      // Vector Search Configuration
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

      // Provider Selection
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

      // Add all provider-specific settings
      $this->addAllProviderSettings($form['vector_search']);

      // Common Vector Settings
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
        '#max' => 300,
        '#description' => $this->t('Request timeout for API calls.'),
      ];

      $form['vector_search']['common']['enable_cache'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Caching'),
        '#default_value' => $this->configuration['vector_search']['enable_cache'] ?? TRUE,
        '#description' => $this->t('Cache embedding results to improve performance.'),
      ];

      $form['vector_search']['common']['cache_ttl'] = [
        '#type' => 'number',
        '#title' => $this->t('Cache TTL (seconds)'),
        '#default_value' => $this->configuration['vector_search']['cache_ttl'] ?? 3600,
        '#min' => 60,
        '#max' => 86400,
        '#description' => $this->t('How long to cache embedding results.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][common][enable_cache]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      // Vector Index Configuration
      $form['vector_index'] = [
        '#type' => 'details',
        '#title' => $this->t('Vector Index Settings'),
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
          'ivfflat' => $this->t('IVFFlat (Fast, good for large datasets)'),
          'hnsw' => $this->t('HNSW (More accurate, uses more memory)'),
        ],
        '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
        '#description' => $this->t('Vector index algorithm to use.'),
      ];

      $form['vector_index']['lists'] = [
        '#type' => 'number',
        '#title' => $this->t('Lists (IVFFlat only)'),
        '#default_value' => $this->configuration['vector_index']['lists'] ?? 100,
        '#min' => 1,
        '#max' => 32768,
        '#description' => $this->t('Number of inverted lists for IVFFlat index.'),
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
        '#description' => $this->t('Number of probes for IVFFlat search.'),
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
          'cosine' => $this->t('Cosine Distance (recommended)'),
          'l2' => $this->t('Euclidean Distance (L2)'),
          'inner_product' => $this->t('Inner Product'),
        ],
        '#default_value' => $this->configuration['vector_index']['distance'] ?? 'cosine',
        '#description' => $this->t('Distance function for vector similarity.'),
      ];

      // Hybrid Search Configuration
      $form['hybrid_search'] = [
        '#type' => 'details',
        '#title' => $this->t('Hybrid Search Settings'),
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
  protected function addAllProviderSettings(array &$form) {
    // OpenAI Provider Settings
    $form['openai'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    // Add API key fields for OpenAI using the trait
    $this->addApiKeyFields($form['openai'], 'vector_search][openai', 'vector_search.openai');

    $form['openai']['model'] = [
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

    $form['openai']['organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID (Optional)'),
      '#default_value' => $this->configuration['vector_search']['openai']['organization'] ?? '',
      '#description' => $this->t('Your OpenAI organization ID (optional).'),
    ];

    $form['openai']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->configuration['vector_search']['openai']['base_url'] ?? 'https://api.openai.com/v1',
      '#description' => $this->t('OpenAI API base URL (use default unless using a proxy).'),
    ];

    // Hugging Face Provider Settings
    $form['huggingface'] = [
      '#type' => 'details',
      '#title' => $this->t('Hugging Face Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'huggingface'],
        ],
      ],
    ];

    // Add API key fields for Hugging Face using the trait
    $this->addApiKeyFields($form['huggingface'], 'vector_search][huggingface', 'vector_search.huggingface');

    $form['huggingface']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $this->configuration['vector_search']['huggingface']['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2',
      '#description' => $this->t('Hugging Face model identifier (e.g., sentence-transformers/all-MiniLM-L6-v2).'),
    ];

    $form['huggingface']['dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Dimension'),
      '#default_value' => $this->configuration['vector_search']['huggingface']['dimension'] ?? 384,
      '#min' => 1,
      '#max' => 4096,
      '#description' => $this->t('Vector dimension for the selected model.'),
    ];

    // Local Provider Settings
    $form['local'] = [
      '#type' => 'details',
      '#title' => $this->t('Local Model Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'local'],
        ],
      ],
    ];

    $form['local']['model_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Path'),
      '#default_value' => $this->configuration['vector_search']['local']['model_path'] ?? '',
      '#description' => $this->t('Path to local model files (absolute path or relative to Drupal root).'),
    ];

    $form['local']['model_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Model Type'),
      '#options' => [
        'sentence_transformers' => $this->t('Sentence Transformers'),
        'tensorflow' => $this->t('TensorFlow'),
        'pytorch' => $this->t('PyTorch'),
      ],
      '#default_value' => $this->configuration['vector_search']['local']['model_type'] ?? 'sentence_transformers',
      '#description' => $this->t('Type of local model framework.'),
    ];

    $form['local']['dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Dimension'),
      '#default_value' => $this->configuration['vector_search']['local']['dimension'] ?? 384,
      '#min' => 1,
      '#max' => 4096,
      '#description' => $this->t('Vector dimension for the local model.'),
    ];
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
          $openai_config = $values['vector_search']['openai'] ?? [];
          $api_key_name = $openai_config['api_key_name'] ?? '';
          $api_key = $openai_config['api_key'] ?? '';
          
          if (empty($api_key_name) && empty($api_key)) {
            $form_state->setErrorByName('vector_search][openai][api_key', 
              $this->t('OpenAI API key is required when using OpenAI provider.'));
          }
          
          if (!empty($api_key_name) && !empty($api_key)) {
            $form_state->setErrorByName('vector_search][openai][api_key', 
              $this->t('Please use either a key reference OR direct API key, not both.'));
          }
          break;

        case 'huggingface':
          $hf_config = $values['vector_search']['huggingface'] ?? [];
          $api_key_name = $hf_config['api_key_name'] ?? '';
          $api_key = $hf_config['api_key'] ?? '';
          
          if (empty($api_key_name) && empty($api_key)) {
            $form_state->setErrorByName('vector_search][huggingface][api_key', 
              $this->t('Hugging Face API key is required when using Hugging Face provider.'));
          }
          
          if (!empty($api_key_name) && !empty($api_key)) {
            $form_state->setErrorByName('vector_search][huggingface][api_key', 
              $this->t('Please use either a key reference OR direct API key, not both.'));
          }

          if (empty($hf_config['model'])) {
            $form_state->setErrorByName('vector_search][huggingface][model', 
              $this->t('Model identifier is required for Hugging Face provider.'));
          }
          break;

        case 'local':
          $local_config = $values['vector_search']['local'] ?? [];
          
          if (empty($local_config['model_path'])) {
            $form_state->setErrorByName('vector_search][local][model_path', 
              $this->t('Model path is required for local provider.'));
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
      // Save basic settings
      $this->configuration['vector_search']['enabled'] = !empty($values['vector_search']['enabled']);
      $this->configuration['vector_search']['provider'] = $values['vector_search']['provider'];

      // Save provider-specific settings
      $provider = $values['vector_search']['provider'];
      if (isset($values['vector_search'][$provider])) {
        $this->configuration['vector_search'][$provider] = $values['vector_search'][$provider];
      }

      // Save common settings
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
   *
   * @param string $provider
   *   The provider name (openai, huggingface, etc.).
   * @param array $config
   *   Optional provider configuration.
   *
   * @return string
   *   The API key.
   */
  protected function getApiKey($provider, $config = []) {
    if (empty($config)) {
      $config = $this->configuration['vector_search'][$provider] ?? [];
    }

    $api_key_name = $config['api_key_name'] ?? '';
    $direct_key = $config['api_key'] ?? '';

    return $this->getSecureKey($api_key_name, $direct_key, ucfirst($provider) . ' API key', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    // Add vector search specific features
    if (!empty($this->configuration['vector_search']['enabled'])) {
      $features[] = 'semantic_search';
      $features[] = 'vector_search';
      $features[] = 'hybrid_search';
      $features[] = 'multi_provider_embeddings';
    }
    
    return $features;
  }

}