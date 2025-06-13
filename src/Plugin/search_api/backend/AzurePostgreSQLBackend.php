<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\PostgreSQL\VectorIndexManager;
use Drupal\search_api_postgresql\PostgreSQL\VectorQueryBuilder;
use Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Azure PostgreSQL backend with AI vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql_azure",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("Azure-optimized PostgreSQL backend with hybrid text and semantic vector search capabilities")
 * )
 */
class AzurePostgreSQLBackend extends PostgreSQLBackend {

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
      'azure_embedding' => [
        'enabled' => FALSE,
        'endpoint' => '',
        'api_key' => '',
        'api_key_name' => '',
        'deployment_name' => '',
        'model' => 'text-embedding-ada-002',
        'dimension' => 1536,
        'batch_size' => 50,
        'rate_limit_delay' => 100,
        'max_retries' => 3,
        'timeout' => 30,
        'enable_cache' => TRUE,
        'cache_ttl' => 604800, // 7 days
        'enable_queue' => TRUE,
        'priority' => 0,
      ],
      'vector_index' => [
        'method' => 'ivfflat',
        'ivfflat_lists' => 100,
        'hnsw_m' => 16,
        'hnsw_ef_construction' => 64,
        'distance_function' => 'cosine',
      ],
      'hybrid_search' => [
        'enabled' => TRUE,
        'text_weight' => 0.6,
        'vector_weight' => 0.4,
        'similarity_threshold' => 0.15,
        'boost_exact_matches' => TRUE,
        'normalize_scores' => TRUE,
      ],
      'performance' => [
        'connection_pooling' => TRUE,
        'max_connections' => 10,
        'connection_timeout' => 30,
        'query_timeout' => 60,
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
      if ($this->configuration['azure_embedding']['enabled']) {
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
   * Initializes the Azure OpenAI embedding service.
   */
  protected function initializeEmbeddingService() {
    $config = $this->configuration['azure_embedding'];
    
    // Get API key from key module or direct configuration
    $api_key = $this->getAzureApiKey();
    
    if (empty($api_key)) {
      throw new SearchApiException('Azure API key is required for embedding service.');
    }

    $this->embeddingService = new AzureOpenAIEmbeddingService(
      $config['endpoint'],
      $api_key,
      $config['deployment_name'],
      $config['model'] ?? 'text-embedding-ada-002'
    );

    // Configure service options
    $this->embeddingService->setBatchSize($config['batch_size'] ?? 50);
    $this->embeddingService->setRateLimit($config['rate_limit_delay'] ?? 100);
    $this->embeddingService->setMaxRetries($config['max_retries'] ?? 3);
    $this->embeddingService->setTimeout($config['timeout'] ?? 30);
    
    if ($config['enable_cache'] ?? TRUE) {
      $this->embeddingService->enableCache($config['cache_ttl'] ?? 604800);
    }
  }

  /**
   * Gets Azure API key from key module or direct configuration.
   */
  protected function getAzureApiKey() {
    $config = $this->configuration['azure_embedding'];
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
      // Log warning but don't throw exception - allow fallback to direct key
      \Drupal::logger('search_api_postgresql')->warning('Azure API key "@key" not found or empty. Falling back to direct key.', [
        '@key' => $api_key_name,
      ]);
    }

    // Fall back to direct key
    return $direct_key;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    if ($this->configuration['azure_embedding']['enabled']) {
      $features[] = 'search_api_azure_vector_search';
      $features[] = 'search_api_semantic_search';
      $features[] = 'search_api_hybrid_search';
      $features[] = 'search_api_azure_ai';
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
   * Checks if pgvector extension is available and creates it if needed.
   */
  protected function checkPgVectorExtension() {
    if (!$this->configuration['azure_embedding']['enabled']) {
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
      throw new SearchApiException('pgvector extension is required for Azure vector search: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Get base form from parent
    $form = parent::buildConfigurationForm($form, $form_state);

    // Replace AI embeddings section with Azure-specific configuration
    unset($form['ai_embeddings']);

    // Azure AI Embeddings configuration
    $form['azure_embedding'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AI Embeddings'),
      '#open' => !empty($this->configuration['azure_embedding']['enabled']),
      '#description' => $this->t('Configure Azure OpenAI Service for semantic vector search.'),
    ];

    $form['azure_embedding']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure AI Vector Search'),
      '#default_value' => $this->configuration['azure_embedding']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings.'),
    ];

    // Azure OpenAI Service Configuration
    $form['azure_embedding']['service'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Service'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['service']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['azure_embedding']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint (e.g., https://your-resource.openai.azure.com/).'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add API key fields
    if (!empty($this->keyRepository)) {
      $keys = [];
      foreach ($this->keyRepository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form['azure_embedding']['service']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Azure API Key (Key Module)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key -'),
        '#default_value' => $this->configuration['azure_embedding']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key that contains your Azure OpenAI API key. Recommended approach for security.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['azure_embedding']['service']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Direct API Key (Fallback)'),
        '#description' => $this->t('Direct API key entry. Only used if no key is selected above. Not recommended for production.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[azure_embedding][service][api_key_name]"]' => ['value' => ''],
          ],
        ],
      ];
    } else {
      $form['azure_embedding']['service']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Azure API Key'),
        '#description' => $this->t('Azure OpenAI API key. Installing the Key module is recommended for secure storage.'),
        '#states' => [
          'required' => [
            ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['azure_embedding']['service']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['azure_embedding']['deployment_name'] ?? '',
      '#description' => $this->t('The name of your embedding model deployment in Azure OpenAI.'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['service']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions) - Most compatible'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions) - Better performance'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions) - Highest accuracy'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['model'] ?? 'text-embedding-ada-002',
      '#description' => $this->t('The embedding model to use. Must match your Azure deployment.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Vector Index Configuration
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Advanced settings for vector search performance and accuracy.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Faster indexing, good recall)'),
        'hnsw' => $this->t('HNSW (Better accuracy, slower indexing)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector indexing algorithm. HNSW generally provides better search quality.'),
    ];

    $form['vector_index']['distance_function'] = [
      '#type' => 'select',
      '#title' => $this->t('Distance Function'),
      '#options' => [
        'cosine' => $this->t('Cosine Distance (Recommended for embeddings)'),
        'l2' => $this->t('Euclidean Distance (L2)'),
        'inner_product' => $this->t('Inner Product'),
      ],
      '#default_value' => $this->configuration['vector_index']['distance_function'] ?? 'cosine',
      '#description' => $this->t('Distance function for similarity calculations.'),
    ];

    // Hybrid Search Configuration
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Settings'),
      '#open' => FALSE,
      '#description' => $this->t('Configure how traditional full-text search and AI vector search are combined.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['hybrid_search']['enabled'] ?? TRUE,
      '#description' => $this->t('Combine traditional text search with vector similarity search for best results.'),
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
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
      '#step' => 0.05,
      '#description' => $this->t('Weight for AI vector search (0-1). Should sum to 1.0 with text weight.'),
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
      '#description' => $this->t('Minimum similarity score for vector results (0-1). Lower values include more results.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance Settings
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance & Reliability'),
      '#open' => FALSE,
      '#description' => $this->t('Azure-optimized performance settings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Embedding Batch Size'),
      '#default_value' => $this->configuration['azure_embedding']['batch_size'] ?? 50,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to send to Azure API in each batch. Higher values are more efficient but may hit rate limits.'),
    ];

    $form['performance']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['azure_embedding']['rate_limit_delay'] ?? 100,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between API requests to avoid rate limiting.'),
    ];

    $form['performance']['enable_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Embedding Cache'),
      '#default_value' => $this->configuration['azure_embedding']['enable_cache'] ?? TRUE,
      '#description' => $this->t('Cache embeddings to reduce API costs and improve performance.'),
    ];

    $form['performance']['cache_ttl'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache Duration'),
      '#options' => [
        3600 => $this->t('1 hour'),
        86400 => $this->t('1 day'),
        604800 => $this->t('1 week'),
        2592000 => $this->t('1 month'),
        -1 => $this->t('Never expire'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['cache_ttl'] ?? 604800,
      '#description' => $this->t('How long to cache embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[performance][enable_cache]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Test Azure Connection
    $form['azure_test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Azure Connection'),
      '#open' => FALSE,
      '#description' => $this->t('Test your Azure OpenAI configuration.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_test']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Azure AI Connection'),
      '#ajax' => [
        'callback' => '::testAzureConnection',
        'wrapper' => 'azure-test-result',
      ],
    ];

    $form['azure_test']['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'azure-test-result'],
    ];

    return $form;
  }

  /**
   * AJAX callback to test Azure connection.
   */
  public function testAzureConnection(array &$form, FormStateInterface $form_state) {
    try {
      $values = $form_state->getValues();
      $azure_config = $values['azure_embedding'];
      
      // Get API key
      $api_key = '';
      if (!empty($azure_config['service']['api_key_name']) && $this->keyRepository) {
        $key = $this->keyRepository->getKey($azure_config['service']['api_key_name']);
        if ($key) {
          $api_key = $key->getKeyValue();
        }
      } else {
        $api_key = $azure_config['service']['api_key'] ?? '';
      }
      
      if (empty($api_key)) {
        throw new \Exception('API key is required for testing.');
      }
      
      // Create temporary embedding service
      $service = new AzureOpenAIEmbeddingService(
        $azure_config['service']['endpoint'],
        $api_key,
        $azure_config['service']['deployment_name'],
        $azure_config['service']['model'] ?? 'text-embedding-ada-002'
      );
      
      // Test with sample text
      $test_text = "This is a test sentence for Azure OpenAI embedding generation.";
      $embedding = $service->generateEmbedding($test_text);
      
      if (is_array($embedding) && count($embedding) > 0) {
        $form['azure_test']['result']['#markup'] = 
          '<div class="messages messages--status">' . 
          $this->t('✅ Azure AI connection successful! Generated embedding with @dims dimensions. API is working correctly.', [
            '@dims' => count($embedding),
          ]) . 
          '</div>';
      } else {
        throw new \Exception('Azure API returned invalid embedding data.');
      }
    }
    catch (\Exception $e) {
      $form['azure_test']['result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('❌ Azure AI connection failed: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }
    
    return $form['azure_test']['result'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();
    
    if (!empty($values['azure_embedding']['enabled'])) {
      // Validate Azure configuration
      if (empty($values['azure_embedding']['service']['endpoint'])) {
        $form_state->setErrorByName('azure_embedding][service][endpoint', 
          $this->t('Azure OpenAI endpoint is required when Azure embeddings are enabled.'));
      }
      
      if (empty($values['azure_embedding']['service']['deployment_name'])) {
        $form_state->setErrorByName('azure_embedding][service][deployment_name', 
          $this->t('Deployment name is required when Azure embeddings are enabled.'));
      }

      // Validate API key
      $api_key_name = $values['azure_embedding']['service']['api_key_name'] ?? '';
      $direct_api_key = $values['azure_embedding']['service']['api_key'] ?? '';
      
      if (empty($api_key_name) && empty($direct_api_key)) {
        $form_state->setErrorByName('azure_embedding][service][api_key_name', 
          $this->t('Azure API key is required. Use Key module or direct entry.'));
      }

      // Validate hybrid search weights if enabled
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
    
    // Save Azure embedding configuration
    if (isset($values['azure_embedding'])) {
      // Merge service configuration into azure_embedding
      if (isset($values['azure_embedding']['service'])) {
        $this->configuration['azure_embedding'] = array_merge(
          $this->configuration['azure_embedding'] ?? [],
          $values['azure_embedding']['service']
        );
        $this->configuration['azure_embedding']['enabled'] = $values['azure_embedding']['enabled'];
      } else {
        $this->configuration['azure_embedding'] = $values['azure_embedding'];
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

    // Save performance configuration
    if (isset($values['performance'])) {
      $this->configuration['azure_embedding'] = array_merge(
        $this->configuration['azure_embedding'] ?? [],
        $values['performance']
      );
    }
    
    // Clear embedding service to force reinitialization
    $this->embeddingService = NULL;
  }

}