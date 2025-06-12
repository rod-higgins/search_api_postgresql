<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\PostgreSQL\VectorIndexManager;
use Drupal\search_api_postgresql\PostgreSQL\VectorQueryBuilder;
use Drupal\search_api_postgresql\Service\OpenAIEmbeddingService;
use Drupal\search_api_postgresql\Service\HuggingFaceEmbeddingService;

/**
 * Azure PostgreSQL backend with vector search support.
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
      'vector_search' => [
        'enabled' => FALSE,
        'provider' => 'openai',
        'api_key' => '',
        'api_key_name' => '',
        'model' => 'text-embedding-ada-002',
        'dimension' => 1536,
      ],
      'vector_index' => [
        'method' => 'ivfflat',
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
   * Initializes the embedding service based on configuration.
   */
  protected function initializeEmbeddingService() {
    $provider = $this->configuration['vector_search']['provider'];
    
    switch ($provider) {
      case 'openai':
        // Get API key using secure key management
        $api_key = NULL;
        if (!empty($this->configuration['vector_search']['api_key_name'])) {
          try {
            $key_repository = $this->getKeyRepository();
            if ($key_repository) {
              $key = $key_repository->getKey($this->configuration['vector_search']['api_key_name']);
              if ($key) {
                $api_key = $key->getKeyValue();
              }
            }
          }
          catch (\Exception $e) {
            $this->getLogger()->error('Failed to retrieve API key: @message', [
              '@message' => $e->getMessage(),
            ]);
          }
        }
        // Fall back to direct API key if no key name is set
        elseif (!empty($this->configuration['vector_search']['api_key'])) {
          $api_key = $this->configuration['vector_search']['api_key'];
        }
        
        if ($api_key && class_exists('Drupal\search_api_postgresql\Service\OpenAIEmbeddingService')) {
          $model = $this->configuration['vector_search']['model'];
          $this->embeddingService = new OpenAIEmbeddingService($api_key, $model);
        }
        break;
        
      case 'huggingface':
        // Similar key handling for Hugging Face
        $api_key = NULL;
        if (!empty($this->configuration['vector_search']['huggingface_api_key_name'])) {
          try {
            $key_repository = $this->getKeyRepository();
            if ($key_repository) {
              $key = $key_repository->getKey($this->configuration['vector_search']['huggingface_api_key_name']);
              if ($key) {
                $api_key = $key->getKeyValue();
              }
            }
          }
          catch (\Exception $e) {
            $this->getLogger()->error('Failed to retrieve Hugging Face API key: @message', [
              '@message' => $e->getMessage(),
            ]);
          }
        }
        elseif (!empty($this->configuration['vector_search']['huggingface_api_key'])) {
          $api_key = $this->configuration['vector_search']['huggingface_api_key'];
        }
        
        if ($api_key && class_exists('Drupal\search_api_postgresql\Service\HuggingFaceEmbeddingService')) {
          $model = $this->configuration['vector_search']['huggingface_model'] ?? 'sentence-transformers/all-MiniLM-L6-v2';
          $this->embeddingService = new HuggingFaceEmbeddingService($api_key, $model);
        }
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
      '#type' => 'details',
      '#title' => $this->t('Vector Search Configuration'),
      '#open' => $this->configuration['vector_search']['enabled'],
      '#description' => $this->t('Configure semantic vector search using AI embeddings.'),
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

    // Use the trait to add secure key fields for API keys
    $this->addVectorSearchKeyFields($form);

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

    // Hugging Face specific fields
    $form['vector_search']['huggingface_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hugging Face Model'),
      '#default_value' => $this->configuration['vector_search']['huggingface_model'] ?? 'sentence-transformers/all-MiniLM-L6-v2',
      '#description' => $this->t('Hugging Face model identifier (e.g., sentence-transformers/all-MiniLM-L6-v2).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'huggingface'],
        ],
      ],
    ];

    // Vector index configuration
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
        'ivfflat' => $this->t('IVFFlat (faster, less accurate)'),
        'hnsw' => $this->t('HNSW (slower, more accurate)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'],
      '#description' => $this->t('Vector index algorithm. IVFFlat is faster but less accurate, HNSW is slower but more accurate.'),
    ];

    // IVFFlat settings
    $form['vector_index']['ivfflat_lists'] = [
      '#type' => 'number',
      '#title' => $this->t('IVFFlat Lists'),
      '#default_value' => $this->configuration['vector_index']['ivfflat_lists'],
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of lists for IVFFlat index. More lists = slower build, faster search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    // HNSW settings
    $form['vector_index']['hnsw_m'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW M'),
      '#default_value' => $this->configuration['vector_index']['hnsw_m'],
      '#min' => 2,
      '#max' => 100,
      '#description' => $this->t('Number of bi-directional links for HNSW. Higher = better recall, more memory.'),
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
      '#description' => $this->t('Size of the dynamic candidate list. Higher = better index quality, slower build.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    // Hybrid search settings
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

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional text search (0-1). Text weight + vector weight should equal 1.'),
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for semantic vector search (0-1). Text weight + vector weight should equal 1.'),
    ];

    $form['hybrid_search']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#default_value' => $this->configuration['hybrid_search']['similarity_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#description' => $this->t('Minimum cosine similarity score (0-1) for vector search results.'),
    ];

    return $form;
  }

  /**
   * Adds vector search API key fields using secure key management.
   *
   * @param array &$form
   *   The form array to add fields to.
   */
  protected function addVectorSearchKeyFields(array &$form) {
    $key_repository = $this->getKeyRepository();
    
    if ($key_repository) {
      // Get available keys
      $keys = [];
      foreach ($key_repository->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      // OpenAI API key field
      $form['vector_search']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('OpenAI API Key'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key -'),
        '#default_value' => $this->configuration['vector_search']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key that contains the OpenAI API key.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
          ],
        ],
      ];

      // Hugging Face API key field
      $form['vector_search']['huggingface_api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Hugging Face API Key'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key -'),
        '#default_value' => $this->configuration['vector_search']['huggingface_api_key_name'] ?? '',
        '#description' => $this->t('Select a key that contains the Hugging Face API key.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'huggingface'],
          ],
        ],
      ];
    } else {
      // Fallback to direct API key fields if Key module is not available
      $form['vector_search']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#default_value' => '',
        '#description' => $this->t('API key for the embedding service. Leave empty to keep current key.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'openai'],
          ],
        ],
      ];

      $form['vector_search']['huggingface_api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Hugging Face API Key'),
        '#default_value' => '',
        '#description' => $this->t('Hugging Face API key for inference API.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[vector_search][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[vector_search][provider]"]' => ['value' => 'huggingface'],
          ],
        ],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    
    $values = $form_state->getValues();
    
    // Validate hybrid search weights
    if ($values['vector_search']['enabled']) {
      $text_weight = $values['hybrid_search']['text_weight'];
      $vector_weight = $values['hybrid_search']['vector_weight'];
      
      if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
        $form_state->setErrorByName('hybrid_search][text_weight', 
          $this->t('Text weight and vector weight must sum to 1.0'));
      }
      
      // Validate API key configuration
      $provider = $values['vector_search']['provider'];
      $key_field = $provider === 'huggingface' ? 'huggingface_api_key_name' : 'api_key_name';
      
      if (empty($values['vector_search'][$key_field]) && empty($values['vector_search']['api_key'])) {
        $form_state->setErrorByName('vector_search][' . $key_field, 
          $this->t('An API key is required when vector search is enabled.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    // Clear embedding service to force reinitialization
    $this->embeddingService = NULL;
  }
}