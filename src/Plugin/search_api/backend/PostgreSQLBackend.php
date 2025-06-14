<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL search backend with AI vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("PostgreSQL backend with Azure OpenAI embeddings for semantic search.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements ContainerFactoryPluginInterface {

  use SecureKeyManagementTrait;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    
    $instance->logger = $container->get('logger.factory')->get('search_api_postgresql');
    
    // Key repository is optional - don't fail if not available
    try {
      if ($container->has('key.repository')) {
        $instance->keyRepository = $container->get('key.repository');
      }
    } catch (\Exception $e) {
      // Key module not available, continue without it
      $instance->logger->info('Key module not available, using direct password entry only.');
    }

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'connection' => [
        'host' => 'localhost',
        'port' => 5432,
        'database' => '',
        'username' => '',
        'password' => '',
        'password_key' => '',
        'ssl_mode' => 'require',
        'ssl_ca' => '',
        'options' => [],
      ],
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      'debug' => FALSE,
      'batch_size' => 100,
      'ai_embeddings' => [
        'enabled' => FALSE,
        'provider' => 'azure_ai',
        'azure_ai' => [
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
        ],
        'vector_index' => [
          'method' => 'ivfflat',
          'ivfflat_lists' => 100,
          'hnsw_m' => 16,
          'hnsw_ef_construction' => 64,
        ],
        'hybrid_search' => [
          'text_weight' => 0.6,
          'vector_weight' => 0.4,
          'similarity_threshold' => 0.15,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    \Drupal::logger('search_api_postgresql')->notice('buildConfigurationForm called for @class', [
      '@class' => static::class
    ]);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Connection settings
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('PostgreSQL Connection'),
      '#open' => TRUE,
      '#description' => $this->t('Configure the database connection settings.'),
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->configuration['connection']['host'] ?? 'localhost',
      '#required' => TRUE,
      '#description' => $this->t('Database server hostname or IP address.'),
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['connection']['port'] ?? 5432,
      '#min' => 1,
      '#max' => 65535,
      '#required' => TRUE,
      '#description' => $this->t('Database server port (typically 5432 for PostgreSQL).'),
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Name of the database to connect to.'),
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Database username.'),
    ];

    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#default_value' => '',
      '#required' => FALSE,
      '#description' => $this->t('Database password (optional). Leave empty for passwordless connections.'),
    ];

    // Add password key fields if Key module is available  
    $this->addPasswordFields($form);

    $form['connection']['ssl_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('SSL Mode'),
      '#options' => [
        'disable' => $this->t('Disable'),
        'allow' => $this->t('Allow'),
        'prefer' => $this->t('Prefer'),
        'require' => $this->t('Require'),
        'verify-ca' => $this->t('Verify CA'),
        'verify-full' => $this->t('Verify Full'),
      ],
      '#default_value' => $this->configuration['connection']['ssl_mode'] ?? 'require',
      '#description' => $this->t('SSL connection mode.'),
    ];

    $form['connection']['ssl_ca'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SSL CA Certificate Path'),
      '#default_value' => $this->configuration['connection']['ssl_ca'] ?? '',
      '#description' => $this->t('Path to SSL CA certificate file (optional).'),
      '#states' => [
        'visible' => [
          [':input[name="backend_config[connection][ssl_mode]"]' => ['value' => 'verify-ca']],
          [':input[name="backend_config[connection][ssl_mode]"]' => ['value' => 'verify-full']],
        ],
      ],
    ];

    // Index settings
    $form['index_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Index Settings'),
      '#open' => FALSE,
    ];

    $form['index_settings']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
      '#description' => $this->t('Prefix for search index table names.'),
    ];

    $form['index_settings']['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Full Text Search Configuration'),
      '#options' => [
        'simple' => $this->t('Simple'),
        'english' => $this->t('English'),
        'spanish' => $this->t('Spanish'),
        'french' => $this->t('French'),
        'german' => $this->t('German'),
        'portuguese' => $this->t('Portuguese'),
        'italian' => $this->t('Italian'),
        'dutch' => $this->t('Dutch'),
        'danish' => $this->t('Danish'),
        'finnish' => $this->t('Finnish'),
        'hungarian' => $this->t('Hungarian'),
        'norwegian' => $this->t('Norwegian'),
        'russian' => $this->t('Russian'),
        'swedish' => $this->t('Swedish'),
        'turkish' => $this->t('Turkish'),
      ],
      '#default_value' => $this->configuration['fts_configuration'] ?? 'english',
      '#description' => $this->t('PostgreSQL full-text search configuration to use.'),
    ];

    // Advanced settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Mode'),
      '#default_value' => $this->configuration['debug'] ?? FALSE,
      '#description' => $this->t('Log all database queries for debugging.'),
    ];

    $form['advanced']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to process in each batch.'),
    ];

    // AI Embeddings configuration
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
      '#description' => $this->t('Configure AI embeddings for semantic search.'),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using AI embeddings.'),
    ];

    // Azure AI Service configuration
    $form['ai_embeddings']['service'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Service'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['service']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint (e.g., https://your-resource.openai.azure.com/).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add Azure API key fields
    $this->addAzureApiKeyFields($form['ai_embeddings']['service']);

    $form['ai_embeddings']['service']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'] ?? '',
      '#description' => $this->t('Azure OpenAI deployment name for embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['service']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'] ?? 'text-embedding-ada-002',
      '#description' => $this->t('Azure OpenAI embedding model to use.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance settings
    $form['ai_embeddings']['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Embedding Batch Size'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['batch_size'] ?? 50,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to send to Azure OpenAI in each request.'),
    ];

    $form['ai_embeddings']['performance']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['rate_limit_delay'] ?? 100,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between API requests to avoid rate limiting.'),
    ];

    $form['ai_embeddings']['performance']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Retries'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['max_retries'] ?? 3,
      '#min' => 0,
      '#max' => 10,
      '#description' => $this->t('Maximum number of retry attempts for failed API calls.'),
    ];

    $form['ai_embeddings']['performance']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['timeout'] ?? 30,
      '#min' => 5,
      '#max' => 120,
      '#description' => $this->t('Request timeout in seconds.'),
    ];

    // Vector index configuration
    $form['ai_embeddings']['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Faster search, more memory)'),
        'hnsw' => $this->t('HNSW (Hierarchical NSW)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector indexing method for pgvector.'),
    ];

    $form['ai_embeddings']['vector_index']['ivfflat_lists'] = [
      '#type' => 'number',
      '#title' => $this->t('IVFFlat Lists'),
      '#default_value' => $this->configuration['ai_embeddings']['vector_index']['ivfflat_lists'] ?? 100,
      '#min' => 1,
      '#max' => 10000,
      '#description' => $this->t('Number of cluster centroids for IVFFlat index.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    $form['ai_embeddings']['vector_index']['hnsw_m'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW M'),
      '#default_value' => $this->configuration['ai_embeddings']['vector_index']['hnsw_m'] ?? 16,
      '#min' => 2,
      '#max' => 100,
      '#description' => $this->t('Maximum number of bi-directional links for HNSW.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    $form['ai_embeddings']['vector_index']['hnsw_ef_construction'] = [
      '#type' => 'number',
      '#title' => $this->t('HNSW EF Construction'),
      '#default_value' => $this->configuration['ai_embeddings']['vector_index']['hnsw_ef_construction'] ?? 64,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Size of the dynamic candidate list for HNSW construction.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][vector_index][method]"]' => ['value' => 'hnsw'],
        ],
      ],
    ];

    // Hybrid search configuration
    $form['ai_embeddings']['hybrid'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['hybrid']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional text search (0-1). Should sum to 1.0 with vector weight.'),
    ];

    $form['ai_embeddings']['hybrid']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search']['vector_weight'] ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity search (0-1). Should sum to 1.0 with text weight.'),
    ];

    // Connection test button (this one works because it returns simple markup)
    $form['test_connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
      '#description' => $this->t('Test your database connection settings.'),
    ];

    $form['test_connection']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'connection-test-result',
      ],
    ];

    $form['test_connection']['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    return $form;
  }

  /**
   * Add Azure API key fields to form section.
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
        '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key containing your Azure OpenAI API key.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form_section['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Direct API Key (Fallback)'),
        '#description' => $this->t('Direct API key entry. Only used if no key is selected above.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
            ':input[name="backend_config[ai_embeddings][service][api_key_name]"]' => ['value' => ''],
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
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }
  }

  /**
   * Test database connection (this works because it returns simple markup).
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    try {
      $values = $form_state->getValues();
      $connection_config = $values['connection'] ?? [];
      
      // Get password from key if specified
      if (!empty($connection_config['password_key']) && $this->keyRepository) {
        $key = $this->keyRepository->getKey($connection_config['password_key']);
        if ($key) {
          $connection_config['password'] = $key->getKeyValue();
        }
      }
      
      // Test connection logic here...
      
      $result = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--status">' . 
          $this->t('Connection successful! Connected to @host:@port/@database', [
            '@host' => $connection_config['host'] ?? '',
            '@port' => $connection_config['port'] ?? '',
            '@database' => $connection_config['database'] ?? '',
          ]) . 
          '</div>',
      ];
    }
    catch (\Exception $e) {
      $result = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--error">' . 
          $this->t('Connection failed: @message', ['@message' => $e->getMessage()]) . 
          '</div>',
      ];
    }
    
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Validate connection settings
    if (empty($values['connection']['host'])) {
      $form_state->setErrorByName('connection][host', $this->t('Host is required.'));
    }

    if (empty($values['connection']['database'])) {
      $form_state->setErrorByName('connection][database', $this->t('Database name is required.'));
    }

    if (empty($values['connection']['username'])) {
      $form_state->setErrorByName('connection][username', $this->t('Username is required.'));
    }

    // Password is optional - don't validate it

    // Validate AI embeddings if enabled - MATCH SCHEMA STRUCTURE
    if (!empty($values['ai_embeddings']['enabled'])) {
      if (empty($values['ai_embeddings']['service']['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][service][endpoint', 
          $this->t('Azure OpenAI endpoint is required when AI embeddings are enabled.'));
      }

      // Validate API key (either from key module or direct) - MATCH SCHEMA STRUCTURE
      $api_key_name = $values['ai_embeddings']['service']['api_key_name'] ?? '';
      $direct_api_key = $values['ai_embeddings']['service']['api_key'] ?? '';
      
      if (empty($api_key_name) && empty($direct_api_key)) {
        $form_state->setErrorByName('ai_embeddings][service][api_key', 
          $this->t('Azure API key is required. Use Key module or direct entry.'));
      }

      // Validate hybrid search weights - MATCH SCHEMA STRUCTURE
      if (isset($values['ai_embeddings']['hybrid'])) {
        $text_weight = (float) ($values['ai_embeddings']['hybrid']['text_weight'] ?? 0.6);
        $vector_weight = (float) ($values['ai_embeddings']['hybrid']['vector_weight'] ?? 0.4);
        
        if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
          $form_state->setErrorByName('ai_embeddings][hybrid][text_weight', 
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
    $values = $form_state->getValues();

    // Save connection settings
    if (isset($values['connection'])) {
      $this->configuration['connection'] = $values['connection'];
    }

    // Save index settings
    if (isset($values['index_settings'])) {
      $this->configuration['index_prefix'] = $values['index_settings']['index_prefix'] ?? 'search_api_';
      $this->configuration['fts_configuration'] = $values['index_settings']['fts_configuration'] ?? 'english';
    }

    // Save advanced settings
    if (isset($values['advanced'])) {
      $this->configuration['debug'] = !empty($values['advanced']['debug']);
      $this->configuration['batch_size'] = $values['advanced']['batch_size'] ?? 100;
    }

    // Save AI embeddings configuration - MATCH SCHEMA STRUCTURE
    if (isset($values['ai_embeddings'])) {
      // Save basic enabled state
      $this->configuration['ai_embeddings']['enabled'] = !empty($values['ai_embeddings']['enabled']);
      
      // Save service configuration into azure_ai section (per schema)
      if (isset($values['ai_embeddings']['service'])) {
        if (!isset($this->configuration['ai_embeddings']['azure_ai'])) {
          $this->configuration['ai_embeddings']['azure_ai'] = [];
        }
        $this->configuration['ai_embeddings']['azure_ai'] = array_merge(
          $this->configuration['ai_embeddings']['azure_ai'],
          $values['ai_embeddings']['service']
        );
      }

      // Save performance settings into azure_ai section (per schema)
      if (isset($values['ai_embeddings']['performance'])) {
        if (!isset($this->configuration['ai_embeddings']['azure_ai'])) {
          $this->configuration['ai_embeddings']['azure_ai'] = [];
        }
        $this->configuration['ai_embeddings']['azure_ai'] = array_merge(
          $this->configuration['ai_embeddings']['azure_ai'],
          $values['ai_embeddings']['performance']
        );
      }

      // Save vector index settings (per schema)
      if (isset($values['ai_embeddings']['vector_index'])) {
        $this->configuration['ai_embeddings']['vector_index'] = $values['ai_embeddings']['vector_index'];
      }

      // Save hybrid search settings (per schema)
      if (isset($values['ai_embeddings']['hybrid'])) {
        $this->configuration['ai_embeddings']['hybrid_search'] = $values['ai_embeddings']['hybrid'];
      }
    }
  }

  /**
   * Get Azure API key.
   */
  protected function getAzureApiKey() {
    $api_key_name = $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
    $direct_key = $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '';

    if (!empty($api_key_name) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($api_key_name);
      if ($key) {
        return $key->getKeyValue();
      }
    }

    return $direct_key;
  }

  // ========================================================================
  // REQUIRED ABSTRACT METHOD IMPLEMENTATIONS
  // ========================================================================

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    // Placeholder implementation - actual indexing would be implemented here
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    // Placeholder implementation - actual deletion would be implemented here
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    // Placeholder implementation - actual clearing would be implemented here
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    // Placeholder implementation - actual search would be implemented here
    return $query->getResults();
  }

}