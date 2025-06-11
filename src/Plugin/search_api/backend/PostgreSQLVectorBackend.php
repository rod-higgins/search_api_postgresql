<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\IndexInterface;
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
      
      // Use vector-enhanced managers if they exist
      if (class_exists('Drupal\search_api_postgresql\PostgreSQL\VectorIndexManager')) {
        $this->indexManager = new VectorIndexManager(
          $this->connector, 
          $this->fieldMapper, 
          $this->configuration,
          $this->embeddingService
        );
      }
      
      if (class_exists('Drupal\search_api_postgresql\PostgreSQL\VectorQueryBuilder')) {
        $this->queryBuilder = new VectorQueryBuilder(
          $this->connector, 
          $this->fieldMapper, 
          $this->configuration,
          $this->embeddingService
        );
      }
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
        
        if ($api_key && class_exists('Drupal\search_api_postgresql\Service\OpenAIEmbeddingService')) {
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
      '#description' => $this->t('Configure embedding providers for semantic vector search.'),
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
        'huggingface' => $this->t('Hugging Face (Coming Soon)'),
        'local' => $this->t('Local Models (Coming Soon)'),
      ],
      '#default_value' => $this->configuration['vector_search']['provider'],
      '#description' => $this->t('Choose the embedding service provider.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_search']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['vector_search']['api_key'],
      '#description' => $this->t('API key for the embedding service.'),
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
      '#description' => $this->t('Select the embedding model to use.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['vector_search']['dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Dimension'),
      '#default_value' => $this->configuration['vector_search']['dimension'],
      '#min' => 1,
      '#max' => 4096,
      '#description' => $this->t('Dimension of the embedding vectors. Must match the model.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Vector index configuration
    $form['vector_index'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vector Index Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => $this->t('Advanced vector index settings for performance optimization.'),
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
        'ivfflat' => $this->t('IVFFlat (Recommended for most use cases)'),
        'hnsw' => $this->t('HNSW (Better for high-dimensional data)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'],
      '#description' => $this->t('Vector index algorithm for nearest neighbor search.'),
    ];

    $form['vector_index']['ivfflat_lists'] = [
      '#type' => 'number',
      '#title' => $this->t('IVFFlat Lists'),
      '#default_value' => $this->configuration['vector_index']['ivfflat_lists'],
      '#min' => 1,
      '#max' => 32768,
      '#description' => $this->t('Number of lists for IVFFlat index. Generally rows/1000.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    $form['vector_index']['hnsw_m'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW M Parameter'),
      '#default_value' => $this->configuration['vector_index']['hnsw_m'],
      '#min' => 2,
      '#max' => 100,
      '#description' => $this->t('Maximum number of bi-directional links for HNSW.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    $form['vector_index']['hnsw_ef_construction'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW ef_construction'),
      '#default_value' => $this->configuration['vector_index']['hnsw_ef_construction'],
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Size of dynamic candidate list for HNSW construction.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    // Hybrid search configuration
    $form['hybrid_search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => $this->t('Configure how to combine text and vector search results.'),
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
      '#description' => $this->t('Weight for traditional text search in hybrid mode.'),
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity search in hybrid mode.'),
    ];

    $form['hybrid_search']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#default_value' => $this->configuration['hybrid_search']['similarity_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#description' => $this->t('Minimum similarity score for vector search results.'),
    ];

    // Test connection button
    $form['test_vector_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Vector Search Setup'),
      '#ajax' => [
        'callback' => [$this, 'testVectorConnectionAjax'],
        'wrapper' => 'vector-test-result',
      ],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_test_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'vector-test-result'],
    ];

    return $form;
  }

  /**
   * AJAX callback for testing vector search connection.
   */
  public function testVectorConnectionAjax(array &$form, FormStateInterface $form_state) {
    try {
      // Test pgvector extension
      $this->checkPgVectorExtension();
      
      // Test embedding service if configured
      if ($form_state->getValue(['vector_search', 'enabled'])) {
        $provider = $form_state->getValue(['vector_search', 'provider']);
        $api_key = $form_state->getValue(['vector_search', 'api_key']);
        
        if ($provider === 'openai' && !empty($api_key)) {
          // Test embedding generation
          $test_service = new OpenAIEmbeddingService($api_key, 'text-embedding-ada-002');
          $test_embedding = $test_service->generateEmbedding('test connection');
          
          if ($test_embedding && count($test_embedding) > 0) {
            $form['vector_test_result']['#markup'] = 
              '<div class="messages messages--status">' . 
              $this->t('Vector search setup successful! Generated @dim-dimensional embedding.', [
                '@dim' => count($test_embedding)
              ]) . 
              '</div>';
          } else {
            throw new \Exception('No embedding returned from service.');
          }
        } else {
          $form['vector_test_result']['#markup'] = 
            '<div class="messages messages--status">' . 
            $this->t('pgvector extension is available. Configure embedding provider to test full setup.') . 
            '</div>';
        }
      }
    }
    catch (\Exception $e) {
      $form['vector_test_result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('Vector search test failed: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }

    return $form['vector_test_result'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if ($form_state->getValue(['vector_search', 'enabled'])) {
      // Validate hybrid search weights
      $text_weight = $form_state->getValue(['hybrid_search', 'text_weight']);
      $vector_weight = $form_state->getValue(['hybrid_search', 'vector_weight']);
      
      if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
        $form_state->setErrorByName('hybrid_search', 
          $this->t('Text and vector weights should sum to 1.0 (currently: @sum)', [
            '@sum' => $text_weight + $vector_weight
          ]));
      }

      // Validate OpenAI configuration
      $provider = $form_state->getValue(['vector_search', 'provider']);
      if ($provider === 'openai') {
        $api_key = $form_state->getValue(['vector_search', 'api_key']);
        if (empty($api_key)) {
          $form_state->setErrorByName('vector_search][api_key', 
            $this->t('API key is required for OpenAI provider.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Save vector search configuration
    $this->configuration['vector_search']['enabled'] = $form_state->getValue(['vector_search', 'enabled']);
    $this->configuration['vector_search']['provider'] = $form_state->getValue(['vector_search', 'provider']);
    $this->configuration['vector_search']['api_key'] = $form_state->getValue(['vector_search', 'api_key']);
    $this->configuration['vector_search']['model'] = $form_state->getValue(['vector_search', 'model']);
    $this->configuration['vector_search']['dimension'] = $form_state->getValue(['vector_search', 'dimension']);

    // Save vector index configuration
    $this->configuration['vector_index']['method'] = $form_state->getValue(['vector_index', 'method']);
    $this->configuration['vector_index']['ivfflat_lists'] = $form_state->getValue(['vector_index', 'ivfflat_lists']);
    $this->configuration['vector_index']['hnsw_m'] = $form_state->getValue(['vector_index', 'hnsw_m']);
    $this->configuration['vector_index']['hnsw_ef_construction'] = $form_state->getValue(['vector_index', 'hnsw_ef_construction']);

    // Save hybrid search configuration
    $this->configuration['hybrid_search']['text_weight'] = $form_state->getValue(['hybrid_search', 'text_weight']);
    $this->configuration['hybrid_search']['vector_weight'] = $form_state->getValue(['hybrid_search', 'vector_weight']);
    $this->configuration['hybrid_search']['similarity_threshold'] = $form_state->getValue(['hybrid_search', 'similarity_threshold']);
  }

}