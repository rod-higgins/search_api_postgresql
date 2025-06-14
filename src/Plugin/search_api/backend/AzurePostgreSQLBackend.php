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

    \Drupal::logger('search_api_postgresql')->notice('buildConfigurationForm called for @class', [
      '@class' => static::class
    ]);
    error_log('BACKEND DEBUG: buildConfigurationForm called for ' . static::class);

    // Get base form from parent but modify for Azure specifics
    $form = parent::buildConfigurationForm($form, $form_state);

    // Remove the generic AI embeddings section
    unset($form['ai_embeddings']);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Modify connection section for Azure
    $form['connection']['#description'] = $this->t('Configure Azure Database for PostgreSQL connection. SSL is required for Azure.');

    // Force SSL mode to require for Azure
    $form['connection']['ssl_mode']['#default_value'] = 'require';
    $form['connection']['ssl_mode']['#description'] = $this->t('SSL is required for Azure Database for PostgreSQL.');

    // Azure Embedding configuration
    $form['azure_embedding'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AI Embeddings'),
      '#open' => !empty($this->configuration['azure_embedding']['enabled']),
      '#description' => $this->t('Configure Azure OpenAI embeddings for semantic search.'),
    ];

    $form['azure_embedding']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure AI Embeddings'),
      '#default_value' => $this->configuration['azure_embedding']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings.'),
    ];

    // Azure AI Service configuration
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
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add Azure API key fields
    $this->addAzureApiKeyFields($form['azure_embedding']['service']);

    $form['azure_embedding']['service']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['azure_embedding']['deployment_name'] ?? '',
      '#description' => $this->t('Azure OpenAI deployment name for embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['service']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Azure OpenAI embedding model to use.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance settings
    $form['azure_embedding']['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['azure_embedding']['batch_size'] ?? 25,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to process in each batch.'),
    ];

    $form['azure_embedding']['performance']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['azure_embedding']['rate_limit_delay'] ?? 100,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between API requests to avoid rate limiting.'),
    ];

    $form['azure_embedding']['performance']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Retries'),
      '#default_value' => $this->configuration['azure_embedding']['max_retries'] ?? 3,
      '#min' => 0,
      '#max' => 10,
      '#description' => $this->t('Maximum number of retries for failed API calls.'),
    ];

    $form['azure_embedding']['performance']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#default_value' => $this->configuration['azure_embedding']['timeout'] ?? 30,
      '#min' => 5,
      '#max' => 300,
      '#description' => $this->t('API request timeout in seconds.'),
    ];

    $form['azure_embedding']['performance']['enable_cache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Cache'),
      '#default_value' => $this->configuration['azure_embedding']['enable_cache'] ?? TRUE,
      '#description' => $this->t('Cache embeddings to reduce API calls and improve performance.'),
    ];

    $form['azure_embedding']['performance']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $this->configuration['azure_embedding']['cache_ttl'] ?? 3600,
      '#min' => 300,
      '#max' => 86400,
      '#description' => $this->t('How long to cache embeddings (300 seconds to 24 hours).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][performance][enable_cache]"]' => ['checked' => TRUE],
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
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Recommended for Azure)'),
        'hnsw' => $this->t('HNSW (Hierarchical Navigable Small World)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector index algorithm for similarity search.'),
    ];

    $form['vector_index']['lists'] = [
      '#type' => 'number',
      '#title' => $this->t('Lists (IVFFlat)'),
      '#default_value' => $this->configuration['vector_index']['lists'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of lists for IVFFlat index. More lists = better accuracy, slower inserts.'),
    ];

    $form['vector_index']['probes'] = [
      '#type' => 'number',
      '#title' => $this->t('Probes (IVFFlat)'),
      '#default_value' => $this->configuration['vector_index']['probes'] ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of lists to search. More probes = better accuracy, slower queries.'),
    ];

    // Hybrid search configuration
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#open' => FALSE,
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
      '#description' => $this->t('Minimum similarity score for vector results (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['max_results'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Results'),
      '#default_value' => $this->configuration['hybrid_search']['max_results'] ?? 1000,
      '#min' => 10,
      '#max' => 10000,
      '#description' => $this->t('Maximum number of results to return from hybrid search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Azure-specific performance settings
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure Performance Settings'),
      '#open' => FALSE,
      '#description' => $this->t('Optimize performance for Azure Database for PostgreSQL.'),
    ];

    $form['performance']['connection_pool_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection Pool Size'),
      '#default_value' => $this->configuration['performance']['connection_pool_size'] ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of connections to maintain in the pool.'),
    ];

    $form['performance']['statement_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Statement Timeout (ms)'),
      '#default_value' => $this->configuration['performance']['statement_timeout'] ?? 30000,
      '#min' => 1000,
      '#max' => 300000,
      '#description' => $this->t('Maximum time to wait for statement execution.'),
    ];

    // Azure-specific test connection
    $form['test_connection']['#description'] = $this->t('Test connection to Azure Database for PostgreSQL and verify pgvector extension.');

    return $form;
  }

  /**
   * Add Azure API key fields to form section.
   *
   * @param array &$form_section
   *   The form section to add fields to.
   */
  protected function addAzureApiKeyFields(array &$form_section) {
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
        '#default_value' => $this->configuration['azure_embedding']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key containing your Azure OpenAI API key.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Direct API Key (Fallback)'),
        '#description' => $this->t('Direct API key entry. Only used if no key is selected above.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[azure_embedding][service][api_key_name]"]' => ['value' => ''],
          ],
        ],
      ];
    } else {
      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Your Azure OpenAI API key. Using Key module is recommended for production.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
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
      }

      // Save performance settings
      if (isset($values['azure_embedding']['performance'])) {
        $this->configuration['azure_embedding'] = array_merge(
          $this->configuration['azure_embedding'] ?? [],
          $values['azure_embedding']['performance']
        );
      }

      // Save enabled state
      $this->configuration['azure_embedding']['enabled'] = !empty($values['azure_embedding']['enabled']);
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
      $this->configuration['performance'] = $values['performance'];
    }
  }

  /**
   * Get Azure API key from configuration.
   */
  protected function getAzureApiKey() {
    $api_key_name = $this->configuration['azure_embedding']['api_key_name'] ?? '';
    $direct_key = $this->configuration['azure_embedding']['api_key'] ?? '';

    // Try key module first
    if (!empty($api_key_name) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($api_key_name);
      if ($key) {
        return $key->getKeyValue();
      }
    }

    // Fall back to direct key
    return $direct_key;
  }
}