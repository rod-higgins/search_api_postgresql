<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;

/**
 * Azure PostgreSQL search backend with optimized AI vector search.
 *
 * @SearchApiBackend(
 *   id = "postgresql_azure",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("Optimized PostgreSQL backend for Azure Database with Azure OpenAI integration.")
 * )
 */
class AzurePostgreSQLBackend extends PostgreSQLBackend {

  use SecureKeyManagementTrait;

  /**
   * The Azure embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\AzureEmbeddingService
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    
    // Override defaults for Azure optimization
    $config['connection']['ssl_mode'] = 'require';
    $config['connection']['port'] = 5432;
    
    // Azure-specific configuration
    $config['azure_embedding'] = [
      'enabled' => FALSE,
      'endpoint' => '',
      'api_key' => '',
      'api_key_name' => '',
      'deployment_name' => '',
      'model' => 'text-embedding-3-small',
      'dimension' => 1536,
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
    ];

    $config['hybrid_search'] = [
      'enabled' => TRUE,
      'text_weight' => 0.6,
      'vector_weight' => 0.4,
      'similarity_threshold' => 0.15,
      'max_results' => 1000,
    ];

    $config['performance'] = [
      'connection_pool_size' => 10,
      'statement_timeout' => 30000,
      'work_mem' => '256MB',
      'effective_cache_size' => '2GB',
    ];

    // Remove the generic ai_embeddings config
    unset($config['ai_embeddings']);

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Get base form from parent but modify for Azure specifics
    $form = parent::buildConfigurationForm($form, $form_state);

    // Remove the generic AI embeddings section
    unset($form['ai_embeddings']);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Modify connection section for Azure
    $form['connection']['#description'] = $this->t('Configure Azure Database for PostgreSQL connection. SSL is required for Azure.');

    // Force SSL requirement for Azure
    $form['connection']['ssl_mode']['#default_value'] = 'require';
    $form['connection']['ssl_mode']['#disabled'] = TRUE;
    $form['connection']['ssl_mode']['#description'] = $this->t('SSL is required for Azure Database for PostgreSQL.');

    // Azure AI Embeddings configuration
    $form['azure_embedding'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AI Embeddings'),
      '#open' => !empty($this->configuration['azure_embedding']['enabled']),
      '#description' => $this->t('Configure Azure OpenAI Service for semantic vector search. Optimized for Azure Database for PostgreSQL.'),
    ];

    $form['azure_embedding']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure AI Vector Search'),
      '#default_value' => $this->configuration['azure_embedding']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings with pgvector extension.'),
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
      '#placeholder' => 'https://your-resource.openai.azure.com/',
    ];

    // Add API key fields using the trait method
    $this->addApiKeyFields(
      $form['azure_embedding']['service'],
      'azure_embedding][service',
      'azure_embedding'
    );

    $form['azure_embedding']['service']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['azure_embedding']['deployment_name'] ?? '',
      '#description' => $this->t('Azure OpenAI deployment name for the embedding model.'),
      '#placeholder' => 'my-embedding-deployment',
    ];

    $form['azure_embedding']['service']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Choose the embedding model. Newer models provide better semantic understanding.'),
    ];

    // Vector Index Configuration
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Configure pgvector indexing for optimal performance.'),
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
        'ivfflat' => $this->t('IVFFlat (Recommended for most use cases)'),
        'hnsw' => $this->t('HNSW (Better for high-dimensional vectors)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector indexing method. IVFFlat is recommended for most use cases.'),
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

    $form['vector_index']['probes'] = [
      '#type' => 'number',
      '#title' => $this->t('Probes (IVFFlat)'),
      '#default_value' => $this->configuration['vector_index']['probes'] ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of probes for query time. Higher values = better recall, slower queries.'),
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
      '#description' => $this->t('Configure hybrid search combining traditional full-text and AI vector search.'),
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

    $form['hybrid_search']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#default_value' => $this->configuration['hybrid_search']['similarity_threshold'] ?? 0.15,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#description' => $this->t('Minimum similarity score for vector results (0-1). Lower = more results.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance Configuration
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Optimize performance for Azure Database for PostgreSQL.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Embedding Batch Size'),
      '#default_value' => $this->configuration['azure_embedding']['batch_size'] ?? 25,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to process in each API batch. Lower values reduce memory usage.'),
    ];

    $form['performance']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['azure_embedding']['rate_limit_delay'] ?? 100,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between API requests to avoid rate limiting.'),
    ];

    $form['performance']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Retries'),
      '#default_value' => $this->configuration['azure_embedding']['max_retries'] ?? 3,
      '#min' => 0,
      '#max' => 10,
      '#description' => $this->t('Maximum retries for failed API calls.'),
    ];

    $form['performance']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout (seconds)'),
      '#default_value' => $this->configuration['azure_embedding']['timeout'] ?? 30,
      '#min' => 5,
      '#max' => 300,
      '#description' => $this->t('API request timeout.'),
    ];

    $form['performance']['enable_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Embedding Cache'),
      '#default_value' => $this->configuration['azure_embedding']['enable_cache'] ?? TRUE,
      '#description' => $this->t('Cache embeddings to reduce API calls and improve performance.'),
    ];

    $form['performance']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $this->configuration['azure_embedding']['cache_ttl'] ?? 3600,
      '#min' => 300,
      '#max' => 86400,
      '#description' => $this->t('How long to cache embeddings (300 seconds to 24 hours).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[performance][enable_cache]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Azure-specific test connection
    $form['test_connection']['#description'] = $this->t('Test connection to Azure Database for PostgreSQL and verify pgvector extension.');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Validate Azure embedding configuration
    if (!empty($values['azure_embedding']['enabled'])) {
      if (empty($values['azure_embedding']['service']['endpoint'])) {
        $form_state->setErrorByName('azure_embedding][service][endpoint', 
          $this->t('Azure OpenAI endpoint is required when embeddings are enabled.'));
      }

      // Validate API key (either from key module or direct)
      $api_key_name = $values['azure_embedding']['service']['api_key_name'] ?? '';
      $direct_api_key = $values['azure_embedding']['service']['api_key'] ?? '';
      
      if (empty($api_key_name) && empty($direct_api_key)) {
        $form_state->setErrorByName('azure_embedding][service][api_key_name', 
          $this->t('Azure API key is required. Use Key module or direct entry.'));
      }

      if (empty($values['azure_embedding']['service']['deployment_name'])) {
        $form_state->setErrorByName('azure_embedding][service][deployment_name', 
          $this->t('Deployment name is required for Azure OpenAI.'));
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

  /**
   * Check if pgvector extension is available.
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
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    if (!empty($this->configuration['azure_embedding']['enabled'])) {
      $features[] = 'search_api_azure_ai';
      $features[] = 'search_api_vector_similarity';
      $features[] = 'search_api_hybrid_ranking';
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

}