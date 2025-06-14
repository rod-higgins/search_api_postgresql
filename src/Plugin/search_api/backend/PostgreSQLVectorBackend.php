<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\search_api_postgresql\PostgreSQL\VectorIndexManager;
use Drupal\search_api_postgresql\PostgreSQL\VectorQueryBuilder;
use Drupal\search_api_postgresql\Service\OpenAIEmbeddingService;

/**
 * Enhanced PostgreSQL backend with vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql_vector",
 *   label = @Translation("PostgreSQL with Vector Search"),
 *   description = @Translation("PostgreSQL backend with hybrid text and semantic vector search capabilities")
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
        'api_key' => '',
        'model' => 'text-embedding-ada-002',
        'dimension' => 1536,
      ],
      'vector_index' => [
        'method' => 'ivfflat', // or 'hnsw'
        'ivfflat_lists' => 100,
        'hnsw_m' => 16,
        'hnsw_ef_construction' => 64,
      ],
      'hybrid_search' => [
        'text_weight' => 0.7,
        'vector_weight' => 0.3,
        'similarity_threshold' => 0.1,
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
   * Initializes the embedding service.
   */
  protected function initializeEmbeddingService() {
    $provider = $this->configuration['vector_search']['provider'];
    
    switch ($provider) {
      case 'openai':
        $api_key = $this->configuration['vector_search']['api_key'];
        $model = $this->configuration['vector_search']['model'];
        
        if ($api_key) {
          $this->embeddingService = new OpenAIEmbeddingService($api_key, $model);
        }
        break;
        
      // Add other providers as needed
      case 'huggingface':
        // Implementation for Hugging Face embeddings
        break;
        
      case 'local':
        // Implementation for local embedding models
        break;
    }
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
    }
    
    return $features;
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    // Check if pgvector extension is installed
    $this->checkPgVectorExtension();
    
    parent::addIndex($index);
  }

  /**
   * Checks if pgvector extension is available.
   */
  protected function checkPgVectorExtension() {
    if (!$this->configuration['vector_search']['enabled']) {
      return;
    }

    try {
      $sql = "SELECT 1 FROM pg_extension WHERE extname = 'vector'";
      $result = $this->connector->executeQuery($sql);
      
      if (!$result->fetchColumn()) {
        // Try to create the extension
        $this->connector->executeQuery("CREATE EXTENSION IF NOT EXISTS vector");
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
    $form = parent::buildConfigurationForm($form, $form_state);

    // Vector search configuration
    $form['vector_search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vector Search Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => !$this->configuration['vector_search']['enabled'],
    ];

    $form['vector_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Vector Search'),
      '#default_value' => $this->configuration['vector_search']['enabled'],
      '#description' => $this->t('Enable semantic vector search capabilities. Requires pgvector extension.'),
    ];

    $form['vector_search']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Provider'),
      '#options' => [
        'openai' => $this->t('OpenAI'),
        'huggingface' => $this->t('Hugging Face'),
        'local' => $this->t('Local Model'),
      ],
      '#default_value' => $this->configuration['vector_search']['provider'],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#default_value' => '', // Don't show existing key
      '#description' => $this->t('API key for the embedding service. Leave empty to keep current key.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['vector_search']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
      ],
      '#default_value' => $this->configuration['vector_search']['model'],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    // Vector index configuration
    $form['vector_index'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vector Index Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
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
        'ivfflat' => $this->t('IVFFlat (faster build, good for larger datasets)'),
        'hnsw' => $this->t('HNSW (slower build, better recall)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'],
      '#description' => $this->t('Vector index algorithm. HNSW generally provides better search quality.'),
    ];

    // Hybrid search configuration
    $form['hybrid_search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
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
      '#description' => $this->t('Weight for traditional full-text search scores in hybrid mode.'),
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity scores in hybrid mode.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if ($form_state->getValue(['vector_search', 'enabled'])) {
      // Validate weights sum to 1.0
      $text_weight = $form_state->getValue(['hybrid_search', 'text_weight']);
      $vector_weight = $form_state->getValue(['hybrid_search', 'vector_weight']);
      
      if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
        $form_state->setErrorByName('hybrid_search', 
          $this->t('Text and vector weights should sum to 1.0'));
      }

      // Test embedding service if API key provided
      $provider = $form_state->getValue(['vector_search', 'provider']);
      $api_key = $form_state->getValue(['vector_search', 'api_key']);
      
      if ($provider === 'openai' && $api_key) {
        try {
          $test_service = new OpenAIEmbeddingService($api_key);
          $test_service->generateEmbedding('test');
        }
        catch (\Exception $e) {
          $form_state->setErrorByName('vector_search', 
            $this->t('Failed to connect to embedding service: @message', 
              ['@message' => $e->getMessage()]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Vector search configuration
    $this->configuration['vector_search']['enabled'] = $form_state->getValue(['vector_search', 'enabled']);
    $this->configuration['vector_search']['provider'] = $form_state->getValue(['vector_search', 'provider']);
    $this->configuration['vector_search']['model'] = $form_state->getValue(['vector_search', 'model']);

    if ($api_key = $form_state->getValue(['vector_search', 'api_key'])) {
      $this->configuration['vector_search']['api_key'] = $api_key;
    }

    // Vector index configuration
    $this->configuration['vector_index']['method'] = $form_state->getValue(['vector_index', 'method']);

    // Hybrid search configuration
    $this->configuration['hybrid_search']['text_weight'] = $form_state->getValue(['hybrid_search', 'text_weight']);
    $this->configuration['hybrid_search']['vector_weight'] = $form_state->getValue(['hybrid_search', 'vector_weight']);
  }

  /**
   * Gets vector search statistics for an index.
   */
  public function getVectorStats(IndexInterface $index) {
    if (!$this->configuration['vector_search']['enabled']) {
      return [];
    }

    $table_name = $this->getIndexTableName($index);
    
    $stats = [];
    
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

    return $stats;
  }
}