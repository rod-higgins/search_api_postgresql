<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\PostgreSQL\VectorIndexManager;
use Drupal\search_api_postgresql\PostgreSQL\VectorQueryBuilder;
use Drupal\search_api_postgresql\Service\OpenAIEmbeddingService;
use Drupal\search_api_postgresql\Service\HuggingFaceEmbeddingService;
use Drupal\search_api_postgresql\Service\LocalEmbeddingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL backend with flexible vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql_vector",
 *   label = @Translation("PostgreSQL with Vector Search"),
 *   description = @Translation("PostgreSQL backend with vector search capabilities and multiple embedding provider support")
 * )
 */
class PostgreSQLVectorBackend extends PostgreSQLBackend {

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'vector_search' => [
        'enabled' => FALSE,
        'provider' => 'openai',
        'openai' => [
          'api_key' => '',
          'api_key_name' => '',
          'model' => 'text-embedding-3-small',
          'dimension' => 1536,
          'organization' => '',
          'base_url' => 'https://api.openai.com/v1',
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
        'cache_ttl' => 604800,
      ],
      'vector_index' => [
        'method' => 'hnsw',
        'ivfflat_lists' => 100,
        'hnsw_m' => 16,
        'hnsw_ef_construction' => 64,
        'distance_function' => 'cosine',
      ],
      'hybrid_search' => [
        'enabled' => TRUE,
        'text_weight' => 0.7,
        'vector_weight' => 0.3,
        'similarity_threshold' => 0.1,
        'boost_exact_matches' => TRUE,
        'normalize_scores' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function connect() {
    if (!$this->connector) {
      parent::connect();
      
      // Initialize embedding service if enabled
      if ($this->configuration['vector_search']['enabled']) {
        $this->initializeEmbeddingService();
      }
      
      // Use vector-enhanced managers
      $this->indexManager = new VectorIndexManager(
        $this->connector, 
        $this->fieldMapper, 
        $this->configuration,
        $this->embeddingService
      );
      
      $this->queryBuilder = new VectorQueryBuilder(
        $this->connector, 
        $this->fieldMapper, 
        $this->configuration,
        $this->embeddingService
      );
    }
  }

  /**
   * Initializes the embedding service based on configuration.
   */
  protected function initializeEmbeddingService() {
    $config = $this->configuration['vector_search'];
    $provider = $config['provider'] ?? 'openai';

    switch ($provider) {
      case 'openai':
        $api_key = $this->getProviderApiKey('openai');
        $openai_config = $config['openai'];
        
        $this->embeddingService = new OpenAIEmbeddingService(
          $api_key,
          $openai_config['model'] ?? 'text-embedding-3-small',
          $openai_config['organization'] ?? '',
          $openai_config['base_url'] ?? 'https://api.openai.com/v1'
        );
        break;
        
      case 'huggingface':
        $api_key = $this->getProviderApiKey('huggingface');
        $hf_config = $config['huggingface'];
        
        $this->embeddingService = new HuggingFaceEmbeddingService(
          $api_key,
          $hf_config['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2'
        );
        break;
        
      case 'local':
        $local_config = $config['local'];
        $this->embeddingService = new LocalEmbeddingService(
          $local_config['model_path'] ?? '',
          $local_config['model_type'] ?? 'sentence_transformers'
        );
        break;
        
      default:
        throw new SearchApiException('Unsupported embedding provider: ' . $provider);
    }

    // Configure common service options
    if (method_exists($this->embeddingService, 'setBatchSize')) {
      $this->embeddingService->setBatchSize($config['batch_size'] ?? 25);
    }
    
    if (method_exists($this->embeddingService, 'setRateLimit')) {
      $this->embeddingService->setRateLimit($config['rate_limit_delay'] ?? 100);
    }
    
    if (method_exists($this->embeddingService, 'setMaxRetries')) {
      $this->embeddingService->setMaxRetries($config['max_retries'] ?? 3);
    }
    
    if (method_exists($this->embeddingService, 'setTimeout')) {
      $this->embeddingService->setTimeout($config['timeout'] ?? 30);
    }
    
    if ($config['enable_cache'] ?? TRUE) {
      if (method_exists($this->embeddingService, 'enableCache')) {
        $this->embeddingService->enableCache($config['cache_ttl'] ?? 604800);
      }
    }
  }

  /**
   * Gets API key for a specific provider.
   */
  protected function getProviderApiKey($provider) {
    $config = $this->configuration['vector_search'][$provider] ?? [];
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
  public function addIndex(IndexInterface $index) {
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

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Get base form from parent
    $form = parent::buildConfigurationForm($form, $form_state);

    // Replace AI embeddings section with flexible vector search configuration
    unset($form['ai_embeddings']);

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
      '#description' => $this->t('Cache embeddings to improve performance and reduce costs.'),
    ];

    // Vector Index Configuration
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Advanced settings for vector search performance and accuracy.'),
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
        'ivfflat' => $this->t('IVFFlat (Faster indexing, good for large datasets)'),
        'hnsw' => $this->t('HNSW (Better accuracy, recommended)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'hnsw',
      '#description' => $this->t('Vector indexing algorithm for similarity search.'),
    ];

    $form['vector_index']['distance_function'] = [
      '#type' => 'select',
      '#title' => $this->t('Distance Function'),
      '#options' => [
        'cosine' => $this->t('Cosine Distance (Recommended)'),
        'l2' => $this->t('Euclidean Distance (L2)'),
        'inner_product' => $this->t('Inner Product'),
      ],
      '#default_value' => $this->configuration['vector_index']['distance_function'] ?? 'cosine',
      '#description' => $this->t('Distance function for similarity calculations.'),
    ];

    // HNSW specific settings
    $form['vector_index']['hnsw_m'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW M Parameter'),
      '#default_value' => $this->configuration['vector_index']['hnsw_m'] ?? 16,
      '#min' => 2,
      '#max' => 100,
      '#description' => $this->t('Number of connections for HNSW. Higher values = better accuracy, more memory.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    $form['vector_index']['hnsw_ef_construction'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW ef_construction'),
      '#default_value' => $this->configuration['vector_index']['hnsw_ef_construction'] ?? 64,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Size of dynamic candidate list for HNSW construction.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    // IVFFlat specific settings
    $form['vector_index']['ivfflat_lists'] = [
      '#type' => 'number',
      '#title' => $this->t('IVFFlat Lists'),
      '#default_value' => $this->configuration['vector_index']['ivfflat_lists'] ?? 100,
      '#min' => 1,
      '#max' => 10000,
      '#description' => $this->t('Number of lists for IVFFlat index. Should be around sqrt(total_rows).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    // Hybrid Search Configuration
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Settings'),
      '#open' => FALSE,
      '#description' => $this->t('Combine traditional full-text search with vector similarity search.'),
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
      '#description' => $this->t('Combine text and vector search for best results.'),
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.7,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#description' => $this->t('Weight for traditional full-text search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'] ?? 0.3,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#description' => $this->t('Weight for vector similarity search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Test vector configuration
    $form['vector_test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Vector Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Test your vector search configuration.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_test']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Vector Configuration'),
      '#ajax' => [
        'callback' => '::testVectorConfiguration',
        'wrapper' => 'vector-test-result',
      ],
    ];

    $form['vector_test']['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'vector-test-result'],
    ];

    return $form;
  }

  /**
   * Adds provider-specific settings to the form.
   */
  protected function addProviderSpecificSettings(array &$form, $provider) {
    $keys = [];
    if (!empty($this->keyRepository)) {
      foreach ($this->keyRepository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }
    }

    switch ($provider) {
      case 'openai':
        $form['vector_search']['provider_settings']['openai'] = [
          '#type' => 'details',
          '#title' => $this->t('OpenAI Configuration'),
          '#open' => TRUE,
        ];

        if (!empty($keys)) {
          $form['vector_search']['provider_settings']['openai']['api_key_name'] = [
            '#type' => 'select',
            '#title' => $this->t('API Key (Key Module)'),
            '#options' => $keys,
            '#empty_option' => $this->t('- Select a key -'),
            '#default_value' => $this->configuration['vector_search']['openai']['api_key_name'] ?? '',
            '#description' => $this->t('Select a key containing your OpenAI API key.'),
          ];

          $form['vector_search']['provider_settings']['openai']['api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('Direct API Key (Fallback)'),
            '#description' => $this->t('Direct API key entry. Only used if no key is selected above.'),
            '#states' => [
              'visible' => [
                ':input[name="backend_config[vector_search][provider_settings][openai][api_key_name]"]' => ['value' => ''],
              ],
            ],
          ];
        } else {
          $form['vector_search']['provider_settings']['openai']['api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('OpenAI API Key'),
            '#description' => $this->t('Your OpenAI API key. Installing Key module is recommended.'),
          ];
        }

        $form['vector_search']['provider_settings']['openai']['model'] = [
          '#type' => 'select',
          '#title' => $this->t('Model'),
          '#options' => [
            'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions, cost-effective)'),
            'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions, highest quality)'),
            'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions, legacy)'),
          ],
          '#default_value' => $this->configuration['vector_search']['openai']['model'] ?? 'text-embedding-3-small',
          '#description' => $this->t('OpenAI embedding model to use.'),
        ];

        $form['vector_search']['provider_settings']['openai']['organization'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Organization ID (Optional)'),
          '#default_value' => $this->configuration['vector_search']['openai']['organization'] ?? '',
          '#description' => $this->t('Optional OpenAI organization ID.'),
        ];
        break;

      case 'huggingface':
        $form['vector_search']['provider_settings']['huggingface'] = [
          '#type' => 'details',
          '#title' => $this->t('Hugging Face Configuration'),
          '#open' => TRUE,
        ];

        if (!empty($keys)) {
          $form['vector_search']['provider_settings']['huggingface']['api_key_name'] = [
            '#type' => 'select',
            '#title' => $this->t('API Key (Key Module)'),
            '#options' => $keys,
            '#empty_option' => $this->t('- Select a key -'),
            '#default_value' => $this->configuration['vector_search']['huggingface']['api_key_name'] ?? '',
            '#description' => $this->t('Select a key containing your Hugging Face API key.'),
          ];

          $form['vector_search']['provider_settings']['huggingface']['api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('Direct API Key (Fallback)'),
            '#description' => $this->t('Direct API key entry. Only used if no key is selected above.'),
            '#states' => [
              'visible' => [
                ':input[name="backend_config[vector_search][provider_settings][huggingface][api_key_name]"]' => ['value' => ''],
              ],
            ],
          ];
        } else {
          $form['vector_search']['provider_settings']['huggingface']['api_key'] = [
            '#type' => 'password',
            '#title' => $this->t('Hugging Face API Key'),
            '#description' => $this->t('Your Hugging Face API key.'),
          ];
        }

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
   * AJAX callback to test vector configuration.
   */
  public function testVectorConfiguration(array &$form, FormStateInterface $form_state) {
    try {
      // Test pgvector extension first
      $this->connect();
      $sql = "SELECT extversion FROM pg_extension WHERE extname = 'vector'";
      $result = $this->connector->executeQuery($sql);
      $version = $result->fetchColumn();
      
      if (!$version) {
        throw new \Exception('pgvector extension not found. Please install pgvector.');
      }

      $messages = [];
      $messages[] = "✅ pgvector extension is available (version {$version})";

      // Test embedding service if provider is configured
      $values = $form_state->getValues();
      $vector_config = $values['vector_search'];
      
      if (!empty($vector_config['enabled'])) {
        // Create temporary embedding service for testing
        $provider = $vector_config['provider'] ?? 'openai';
        
        switch ($provider) {
          case 'openai':
            $api_key = $this->getTestApiKey($vector_config, 'openai');
            if (!empty($api_key)) {
              $model = $vector_config['provider_settings']['openai']['model'] ?? 'text-embedding-3-small';
              $service = new OpenAIEmbeddingService($api_key, $model);
              
              $test_text = "This is a test sentence for OpenAI embedding generation.";
              $embedding = $service->generateEmbedding($test_text);
              
              if (is_array($embedding) && count($embedding) > 0) {
                $messages[] = "✅ OpenAI embedding service working ({$model}, " . count($embedding) . " dimensions)";
              }
            } else {
              $messages[] = "⚠️ OpenAI API key not configured for testing";
            }
            break;
            
          case 'huggingface':
            $api_key = $this->getTestApiKey($vector_config, 'huggingface');
            if (!empty($api_key)) {
              $model = $vector_config['provider_settings']['huggingface']['model'] ?? 'sentence-transformers/all-MiniLM-L6-v2';
              $service = new HuggingFaceEmbeddingService($api_key, $model);
              
              $test_text = "This is a test sentence for Hugging Face embedding generation.";
              $embedding = $service->generateEmbedding($test_text);
              
              if (is_array($embedding) && count($embedding) > 0) {
                $messages[] = "✅ Hugging Face embedding service working ({$model}, " . count($embedding) . " dimensions)";
              }
            } else {
              $messages[] = "⚠️ Hugging Face API key not configured for testing";
            }
            break;
            
          case 'local':
            $messages[] = "⚠️ Local model testing requires actual model files";
            break;
        }
      }

      $form['vector_test']['result']['#markup'] = 
        '<div class="messages messages--status">' . 
        implode('<br>', $messages) . 
        '</div>';
    }
    catch (\Exception $e) {
      $form['vector_test']['result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('❌ Vector search test failed: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }
    
    return $form['vector_test']['result'];
  }

  /**
   * Gets API key for testing from form values.
   */
  protected function getTestApiKey(array $config, $provider) {
    $provider_config = $config['provider_settings'][$provider] ?? [];
    $api_key_name = $provider_config['api_key_name'] ?? '';
    $direct_key = $provider_config['api_key'] ?? '';

    if (!empty($api_key_name) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($api_key_name);
      if ($key) {
        return $key->getKeyValue();
      }
    }

    return $direct_key;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();
    
    if (!empty($values['vector_search']['enabled'])) {
      $provider = $values['vector_search']['provider'] ?? 'openai';
      $provider_config = $values['vector_search']['provider_settings'][$provider] ?? [];
      
      // Validate API key for external providers
      if (in_array($provider, ['openai', 'huggingface'])) {
        $api_key_name = $provider_config['api_key_name'] ?? '';
        $direct_api_key = $provider_config['api_key'] ?? '';
        
        if (empty($api_key_name) && empty($direct_api_key)) {
          $form_state->setErrorByName("vector_search][provider_settings][{$provider}][api_key_name", 
            $this->t('@provider API key is required.', ['@provider' => ucfirst($provider)]));
        }
      }

      // Validate local model path
      if ($provider === 'local') {
        if (empty($provider_config['model_path'])) {
          $form_state->setErrorByName('vector_search][provider_settings][local][model_path', 
            $this->t('Model path is required for local embedding provider.'));
        }
      }

      // Validate hybrid search weights if enabled
      if (!empty($values['hybrid_search']['enabled'])) {
        $text_weight = (float) ($values['hybrid_search']['text_weight'] ?? 0.7);
        $vector_weight = (float) ($values['hybrid_search']['vector_weight'] ?? 0.3);
        
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
      // Merge provider-specific settings into main config
      $provider = $values['vector_search']['provider'] ?? 'openai';
      if (isset($values['vector_search']['provider_settings'][$provider])) {
        $this->configuration['vector_search'][$provider] = $values['vector_search']['provider_settings'][$provider];
      }
      
      // Save common settings
      $this->configuration['vector_search']['enabled'] = $values['vector_search']['enabled'];
      $this->configuration['vector_search']['provider'] = $provider;
      
      if (isset($values['vector_search']['common'])) {
        $this->configuration['vector_search'] = array_merge(
          $this->configuration['vector_search'],
          $values['vector_search']['common']
        );
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
    
    // Clear embedding service to force reinitialization
    $this->embeddingService = NULL;
  }

}