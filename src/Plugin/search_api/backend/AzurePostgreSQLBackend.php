<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\search_api_postgresql\PostgreSQL\AzureVectorIndexManager;
use Drupal\search_api_postgresql\PostgreSQL\AzureVectorQueryBuilder;
use Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService;

/**
 * Azure AI enhanced PostgreSQL backend with vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql_azure",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("PostgreSQL backend with Azure AI text embeddings and hybrid search capabilities optimized for Azure Database for PostgreSQL")
 * )
 */
class AzurePostgreSQLBackend extends PostgreSQLBackend {

  /**
   * The Azure embedding service.
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
        'service_type' => 'azure_openai', // or 'azure_cognitive'
        'endpoint' => '',
        'api_key' => '',
        'deployment_name' => '',
        'api_version' => '2024-02-01',
        'dimension' => 1536,
        'model_type' => 'text-embedding-ada-002',
      ],
      'vector_index' => [
        'method' => 'ivfflat', // Better for Azure Database for PostgreSQL
        'ivfflat_lists' => 100,
        'hnsw_m' => 16,
        'hnsw_ef_construction' => 64,
      ],
      'hybrid_search' => [
        'text_weight' => 0.6,
        'vector_weight' => 0.4,
        'similarity_threshold' => 0.15,
        'max_vector_results' => 1000,
      ],
      'azure_optimization' => [
        'batch_embedding' => TRUE,
        'batch_size' => 10,
        'retry_attempts' => 3,
        'timeout' => 30,
        'rate_limit_delay' => 100, // milliseconds
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function connect() {
    if (!$this->connector) {
      parent::connect();
      
      // Initialize Azure embedding service if enabled
      if ($this->configuration['azure_embedding']['enabled']) {
        $this->initializeAzureEmbeddingService();
      }
      
      // Use Azure-enhanced managers
      $this->indexManager = new AzureVectorIndexManager(
        $this->connector, 
        $this->fieldMapper, 
        $this->configuration,
        $this->embeddingService
      );
      
      $this->queryBuilder = new AzureVectorQueryBuilder(
        $this->connector, 
        $this->fieldMapper, 
        $this->configuration,
        $this->embeddingService
      );
    }
  }

  /**
   * Initializes the Azure embedding service.
   */
  protected function initializeAzureEmbeddingService() {
    $config = $this->configuration['azure_embedding'];
    
    switch ($config['service_type']) {
      case 'azure_openai':
        if ($config['endpoint'] && $config['api_key'] && $config['deployment_name']) {
          $this->embeddingService = new AzureOpenAIEmbeddingService(
            $config['endpoint'],
            $config['api_key'],
            $config['deployment_name'],
            $config['api_version'],
            $config['dimension']
          );
        }
        break;
        
      case 'azure_cognitive':
        // Note: Azure Cognitive Services doesn't yet offer direct text embeddings
        // This is here for future compatibility
        if ($config['endpoint'] && $config['api_key']) {
          throw new \Exception('Azure Cognitive Services text embeddings are not yet available. Please use Azure OpenAI Service.');
        }
        break;
    }
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
    // Check if pgvector extension is installed (required for Azure Database for PostgreSQL)
    $this->checkPgVectorExtension();
    
    parent::addIndex($index);
  }

  /**
   * Checks if pgvector extension is available in Azure Database for PostgreSQL.
   */
  protected function checkPgVectorExtension() {
    if (!$this->configuration['azure_embedding']['enabled']) {
      return;
    }

    try {
      // Check if extension exists
      $sql = "SELECT 1 FROM pg_available_extensions WHERE name = 'vector'";
      $result = $this->connector->executeQuery($sql);
      
      if (!$result->fetchColumn()) {
        throw new SearchApiException('pgvector extension is not available in this Azure Database for PostgreSQL instance. Please enable it in the Azure portal.');
      }

      // Check if extension is enabled
      $sql = "SELECT 1 FROM pg_extension WHERE extname = 'vector'";
      $result = $this->connector->executeQuery($sql);
      
      if (!$result->fetchColumn()) {
        // Try to create the extension
        $this->connector->executeQuery("CREATE EXTENSION IF NOT EXISTS vector");
      }
    }
    catch (\Exception $e) {
      throw new SearchApiException('pgvector extension setup failed: ' . $e->getMessage() . 
        '. Please ensure pgvector is enabled in your Azure Database for PostgreSQL.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Azure AI Embedding Configuration
    $form['azure_embedding'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure AI Embedding Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => !$this->configuration['azure_embedding']['enabled'],
      '#description' => $this->t('Configure Azure AI services for text embeddings and semantic search.'),
    ];

    $form['azure_embedding']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure AI Vector Search'),
      '#default_value' => $this->configuration['azure_embedding']['enabled'],
      '#description' => $this->t('Enable semantic vector search using Azure AI. Requires pgvector extension in Azure Database for PostgreSQL.'),
    ];

    $form['azure_embedding']['service_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure AI Service'),
      '#options' => [
        'azure_openai' => $this->t('Azure OpenAI Service'),
        'azure_cognitive' => $this->t('Azure Cognitive Services (Coming Soon)'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['service_type'],
      '#description' => $this->t('Choose the Azure AI service for generating text embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure Endpoint'),
      '#default_value' => $this->configuration['azure_embedding']['endpoint'],
      '#description' => $this->t('Your Azure OpenAI endpoint (e.g., https://myresource.openai.azure.com/)'),
      '#placeholder' => 'https://myresource.openai.azure.com/',
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Azure API Key'),
      '#description' => $this->t('Your Azure OpenAI API key. Leave empty to keep current key.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['azure_embedding']['deployment_name'],
      '#description' => $this->t('The name of your Azure OpenAI embedding model deployment.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[azure_embedding][service_type]"]' => ['value' => 'azure_openai'],
        ],
        'required' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[azure_embedding][service_type]"]' => ['value' => 'azure_openai'],
        ],
      ],
    ];

    $form['azure_embedding']['model_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['model_type'],
      '#description' => $this->t('The embedding model to use. Make sure this matches your Azure deployment.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[azure_embedding][service_type]"]' => ['value' => 'azure_openai'],
        ],
      ],
    ];

    // Vector Index Configuration optimized for Azure
    $form['vector_index'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vector Index Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => $this->t('Configure vector indexing optimized for Azure Database for PostgreSQL.'),
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
        'ivfflat' => $this->t('IVFFlat (Recommended for Azure Database)'),
        'hnsw' => $this->t('HNSW (Better recall, higher memory usage)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'],
      '#description' => $this->t('IVFFlat is recommended for Azure Database for PostgreSQL due to better memory efficiency.'),
    ];

    $form['vector_index']['ivfflat_lists'] = [
      '#type' => 'number',
      '#title' => $this->t('IVFFlat Lists'),
      '#default_value' => $this->configuration['vector_index']['ivfflat_lists'],
      '#min' => 10,
      '#max' => 1000,
      '#description' => $this->t('Number of clusters for IVFFlat index. Rule of thumb: rows/1000.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    // Hybrid Search Configuration
    $form['hybrid_search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => $this->t('Balance between traditional PostgreSQL full-text search and Azure AI vector search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for PostgreSQL full-text search scores.'),
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for Azure AI vector similarity scores.'),
    ];

    $form['hybrid_search']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#default_value' => $this->configuration['hybrid_search']['similarity_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#description' => $this->t('Minimum similarity score for vector search results (0-1, higher = more similar).'),
    ];

    // Azure Optimization Settings
    $form['azure_optimization'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure Optimization'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => $this->t('Optimize for Azure services rate limits and performance.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_optimization']['batch_embedding'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Batch Embedding Generation'),
      '#default_value' => $this->configuration['azure_optimization']['batch_embedding'],
      '#description' => $this->t('Process embeddings in batches to optimize Azure API usage and reduce costs.'),
    ];

    $form['azure_optimization']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['azure_optimization']['batch_size'],
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of items to process in each embedding batch.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_optimization][batch_embedding]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_optimization']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['azure_optimization']['rate_limit_delay'],
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between Azure API calls to respect rate limits.'),
    ];

    // Test connection button
    $form['test_azure_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Azure AI Connection'),
      '#ajax' => [
        'callback' => [$this, 'testAzureConnectionAjax'],
        'wrapper' => 'azure-connection-test-result',
      ],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_connection_test_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'azure-connection-test-result'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if ($form_state->getValue(['azure_embedding', 'enabled'])) {
      // Validate Azure endpoint format
      $endpoint = $form_state->getValue(['azure_embedding', 'endpoint']);
      if ($endpoint && !filter_var($endpoint, FILTER_VALIDATE_URL)) {
        $form_state->setErrorByName('azure_embedding][endpoint', 
          $this->t('Please enter a valid Azure endpoint URL.'));
      }

      // Validate weights sum to 1.0
      $text_weight = $form_state->getValue(['hybrid_search', 'text_weight']);
      $vector_weight = $form_state->getValue(['hybrid_search', 'vector_weight']);
      
      if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
        $form_state->setErrorByName('hybrid_search', 
          $this->t('Text and vector weights should sum to 1.0 (currently: @sum)', [
            '@sum' => $text_weight + $vector_weight
          ]));
      }

      // Validate required fields for Azure OpenAI
      $service_type = $form_state->getValue(['azure_embedding', 'service_type']);
      if ($service_type === 'azure_openai') {
        if (empty($endpoint)) {
          $form_state->setErrorByName('azure_embedding][endpoint', 
            $this->t('Azure endpoint is required.'));
        }
        
        $deployment_name = $form_state->getValue(['azure_embedding', 'deployment_name']);
        if (empty($deployment_name)) {
          $form_state->setErrorByName('azure_embedding][deployment_name', 
            $this->t('Deployment name is required for Azure OpenAI.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Azure embedding configuration
    $this->configuration['azure_embedding']['enabled'] = $form_state->getValue(['azure_embedding', 'enabled']);
    $this->configuration['azure_embedding']['service_type'] = $form_state->getValue(['azure_embedding', 'service_type']);
    $this->configuration['azure_embedding']['endpoint'] = $form_state->getValue(['azure_embedding', 'endpoint']);
    $this->configuration['azure_embedding']['deployment_name'] = $form_state->getValue(['azure_embedding', 'deployment_name']);
    $this->configuration['azure_embedding']['model_type'] = $form_state->getValue(['azure_embedding', 'model_type']);

    if ($api_key = $form_state->getValue(['azure_embedding', 'api_key'])) {
      $this->configuration['azure_embedding']['api_key'] = $api_key;
    }

    // Set dimension based on model
    $model_type = $form_state->getValue(['azure_embedding', 'model_type']);
    $dimension_map = [
      'text-embedding-ada-002' => 1536,
      'text-embedding-3-small' => 1536,
      'text-embedding-3-large' => 3072,
    ];
    $this->configuration['azure_embedding']['dimension'] = $dimension_map[$model_type] ?? 1536;

    // Vector index configuration
    $this->configuration['vector_index']['method'] = $form_state->getValue(['vector_index', 'method']);
    $this->configuration['vector_index']['ivfflat_lists'] = $form_state->getValue(['vector_index', 'ivfflat_lists']);

    // Hybrid search configuration
    $this->configuration['hybrid_search']['text_weight'] = $form_state->getValue(['hybrid_search', 'text_weight']);
    $this->configuration['hybrid_search']['vector_weight'] = $form_state->getValue(['hybrid_search', 'vector_weight']);
    $this->configuration['hybrid_search']['similarity_threshold'] = $form_state->getValue(['hybrid_search', 'similarity_threshold']);

    // Azure optimization configuration
    $this->configuration['azure_optimization']['batch_embedding'] = $form_state->getValue(['azure_optimization', 'batch_embedding']);
    $this->configuration['azure_optimization']['batch_size'] = $form_state->getValue(['azure_optimization', 'batch_size']);
    $this->configuration['azure_optimization']['rate_limit_delay'] = $form_state->getValue(['azure_optimization', 'rate_limit_delay']);
  }

  /**
   * AJAX callback for testing Azure AI connection.
   */
  public function testAzureConnectionAjax(array &$form, FormStateInterface $form_state) {
    $config = [
      'service_type' => $form_state->getValue(['azure_embedding', 'service_type']),
      'endpoint' => $form_state->getValue(['azure_embedding', 'endpoint']),
      'api_key' => $form_state->getValue(['azure_embedding', 'api_key']) ?: $this->configuration['azure_embedding']['api_key'],
      'deployment_name' => $form_state->getValue(['azure_embedding', 'deployment_name']),
      'model_type' => $form_state->getValue(['azure_embedding', 'model_type']),
    ];

    try {
      if ($config['service_type'] === 'azure_openai') {
        $test_service = new AzureOpenAIEmbeddingService(
          $config['endpoint'],
          $config['api_key'],
          $config['deployment_name']
        );
        
        // Test with a simple phrase
        $embedding = $test_service->generateEmbedding('test connection');
        
        if ($embedding && count($embedding) > 0) {
          $form['azure_connection_test_result']['#markup'] = 
            '<div class="messages messages--status">' . 
            $this->t('Azure AI connection successful! Generated @dim-dimensional embedding.', [
              '@dim' => count($embedding)
            ]) . 
            '</div>';
        } else {
          throw new \Exception('No embedding returned from Azure AI service.');
        }
      } else {
        throw new \Exception('Service type not yet supported for testing.');
      }
    }
    catch (\Exception $e) {
      $form['azure_connection_test_result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('Azure AI connection failed: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }

    return $form['azure_connection_test_result'];
  }

  /**
   * Gets Azure AI vector search statistics for an index.
   */
  public function getAzureVectorStats(IndexInterface $index) {
    if (!$this->configuration['azure_embedding']['enabled']) {
      return [];
    }

    $table_name = $this->getIndexTableName($index);
    
    $stats = [];
    
    try {
      // Count items with embeddings
      $sql = "SELECT COUNT(*) FROM {$table_name} WHERE content_embedding IS NOT NULL";
      $result = $this->connector->executeQuery($sql);
      $stats['items_with_embeddings'] = $result->fetchColumn();
      
      // Get total items
      $sql = "SELECT COUNT(*) FROM {$table_name}";
      $result = $this->connector->executeQuery($sql);
      $stats['total_items'] = $result->fetchColumn();
      
      $stats['embedding_coverage'] = $stats['total_items'] > 0 ? 
        ($stats['items_with_embeddings'] / $stats['total_items']) * 100 : 0;

      // Get vector index information
      $sql = "
        SELECT indexname, indexdef 
        FROM pg_indexes 
        WHERE tablename = :table_name AND indexdef LIKE '%vector%'
      ";
      $result = $this->connector->executeQuery($sql, [':table_name' => $table_name]);
      $stats['vector_indexes'] = $result->fetchAll();

      // Azure-specific stats
      $stats['azure_service'] = $this->configuration['azure_embedding']['service_type'];
      $stats['embedding_model'] = $this->configuration['azure_embedding']['model_type'];
      $stats['vector_dimension'] = $this->configuration['azure_embedding']['dimension'];
      
    } catch (\Exception $e) {
      $stats['error'] = $e->getMessage();
    }

    return $stats;
  }
}