<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\search_api_postgresql\PostgreSQL\FieldMapper;
use Drupal\search_api_postgresql\PostgreSQL\IndexManager;
use Drupal\search_api_postgresql\PostgreSQL\QueryBuilder;
use Drupal\search_api_postgresql\Plugin\search_api\backend\SecureKeyManagementTrait;
use Drupal\key\KeyRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL backend for Search API.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("High-performance PostgreSQL backend with full-text search capabilities and optional AI embeddings")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase {

  use SecureKeyManagementTrait;

  /**
   * The database connector.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The field mapper.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\FieldMapper
   */
  protected $fieldMapper;

  /**
   * The index manager.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\IndexManager
   */
  protected $indexManager;

  /**
   * The query builder.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\QueryBuilder
   */
  protected $queryBuilder;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    
    $instance->logger = $container->get('logger.factory')->get('search_api_postgresql');
    
    // Key repository is optional
    if ($container->has('key.repository')) {
      $instance->keyRepository = $container->get('key.repository');
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
      '#description' => $this->t('Database username for authentication.'),
    ];

    // Password fields - both optional
    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Database password. Leave empty for passwordless connections (e.g., Lando development). Not shown when editing existing configuration.'),
      '#required' => FALSE,
    ];

    // Add key module fields for secure password storage if available
    if (!empty($this->keyRepository)) {
      $this->addKeyFieldsToForm($form);
    }

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
      '#description' => $this->t('SSL connection mode. Use "require" or higher for production.'),
    ];

    // Index settings
    $form['index_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Index Settings'),
      '#open' => TRUE,
      '#description' => $this->t('Configure how search indexes are managed.'),
    ];

    $form['index_settings']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index table prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
      '#description' => $this->t('Prefix for index tables in the database.'),
    ];

    $form['index_settings']['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Full-text search configuration'),
      '#options' => [
        'simple' => $this->t('Simple'),
        'english' => $this->t('English'),
        'spanish' => $this->t('Spanish'),
        'french' => $this->t('French'),
        'german' => $this->t('German'),
        'italian' => $this->t('Italian'),
        'portuguese' => $this->t('Portuguese'),
        'russian' => $this->t('Russian'),
        'chinese' => $this->t('Chinese'),
        'japanese' => $this->t('Japanese'),
      ],
      '#default_value' => $this->configuration['fts_configuration'] ?? 'english',
      '#description' => $this->t('PostgreSQL full-text search configuration to use.'),
    ];

    // Advanced settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
      '#description' => $this->t('Advanced configuration options for debugging and performance.'),
    ];

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#default_value' => $this->configuration['debug'] ?? FALSE,
      '#description' => $this->t('Log queries and debug information. Should be disabled in production.'),
    ];

    $form['advanced']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to process in each batch during indexing.'),
    ];

    // AI Embeddings configuration
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings (Azure OpenAI)'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
      '#description' => $this->t('Enable semantic search using AI text embeddings. Requires Azure OpenAI Service.'),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings.'),
    ];

    // Azure AI configuration
    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint (e.g., https://your-resource.openai.azure.com/).'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add AI API key fields
    if (!empty($this->keyRepository)) {
      $this->addAzureApiKeyFields($form);
    } else {
      $form['ai_embeddings']['azure_ai']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#description' => $this->t('Azure OpenAI API key. Using Key module is recommended for security.'),
        '#states' => [
          'required' => [
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['ai_embeddings']['azure_ai']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'] ?? '',
      '#description' => $this->t('The name of your embedding model deployment in Azure OpenAI.'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'] ?? 'text-embedding-ada-002',
      '#description' => $this->t('The embedding model to use.'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Vector index configuration
    $form['ai_embeddings']['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Advanced settings for vector similarity search performance.'),
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
        'ivfflat' => $this->t('IVFFlat (Faster indexing, good for most cases)'),
        'hnsw' => $this->t('HNSW (Better accuracy, slower indexing)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector indexing algorithm for similarity search.'),
    ];

    // Hybrid search settings
    $form['ai_embeddings']['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Settings'),
      '#open' => FALSE,
      '#description' => $this->t('Configure how traditional full-text search and vector similarity search are combined.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional full-text search (0-1).'),
    ];

    $form['ai_embeddings']['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search']['vector_weight'] ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity search (0-1). Should sum to 1.0 with text weight.'),
    ];

    // Connection test button
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
   * AJAX callback to test database connection.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    try {
      $values = $form_state->getValues();
      $connection_config = $values['connection'];
      
      // Get password from key if specified
      if (!empty($connection_config['password_key']) && $this->keyRepository) {
        $key = $this->keyRepository->getKey($connection_config['password_key']);
        if ($key) {
          $connection_config['password'] = $key->getKeyValue();
        }
      }
      
      $connector = new PostgreSQLConnector($connection_config, $this->logger);
      $connector->testConnection();
      
      $form['test_connection']['result']['#markup'] = 
        '<div class="messages messages--status">' . 
        $this->t('Connection successful! Connected to @host:@port/@database', [
          '@host' => $connection_config['host'],
          '@port' => $connection_config['port'],
          '@database' => $connection_config['database'],
        ]) . 
        '</div>';
    }
    catch (\Exception $e) {
      $form['test_connection']['result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('Connection failed: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }
    
    return $form['test_connection']['result'];
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

    // Validate password configuration - both key and direct password are optional
    $password_key = $values['connection']['password_key'] ?? '';
    $direct_password = $values['connection']['password'] ?? '';
    
    if (empty($password_key) && empty($direct_password)) {
      // Allow empty passwords but show a warning for security awareness
      \Drupal::messenger()->addWarning($this->t('No database password configured. This may be acceptable for development environments (like Lando with trust authentication) but is not recommended for production.'));
    }
    
    // If a password key is specified, validate it exists (but don't fail if empty)
    if (!empty($password_key) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($password_key);
      if (!$key) {
        $form_state->setErrorByName('connection][password_key', $this->t('The specified password key "@key" does not exist.', ['@key' => $password_key]));
      }
      // Note: We don't validate if the key has a value here, as empty keys are allowed for passwordless connections
    }

    // Validate AI embeddings if enabled
    if (!empty($values['ai_embeddings']['enabled'])) {
      if (empty($values['ai_embeddings']['azure_ai']['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', $this->t('Azure AI endpoint is required when embeddings are enabled.'));
      }
      
      if (empty($values['ai_embeddings']['azure_ai']['deployment_name'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][deployment_name', $this->t('Deployment name is required when embeddings are enabled.'));
      }

      // Validate hybrid search weights
      $text_weight = (float) ($values['ai_embeddings']['hybrid_search']['text_weight'] ?? 0.6);
      $vector_weight = (float) ($values['ai_embeddings']['hybrid_search']['vector_weight'] ?? 0.4);
      
      if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
        $form_state->setErrorByName('ai_embeddings][hybrid_search][text_weight', 
          $this->t('Text and vector weights should sum to 1.0 (currently: @sum)', [
            '@sum' => number_format($text_weight + $vector_weight, 2)
          ]));
      }
    }

    // Test connection if requested
    if ($form_state->getTriggeringElement()['#value'] == $this->t('Test Connection')) {
      try {
        $connection_config = $values['connection'];
        
        // Get password from key if specified
        if (!empty($connection_config['password_key']) && $this->keyRepository) {
          $key = $this->keyRepository->getKey($connection_config['password_key']);
          if ($key) {
            $connection_config['password'] = $key->getKeyValue();
          }
        }
        
        $connector = new PostgreSQLConnector($connection_config, $this->logger);
        $connector->testConnection();
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('connection', $this->t('Failed to connect to database: @message', [
          '@message' => $e->getMessage(),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Save connection configuration
    $this->configuration['connection']['host'] = $values['connection']['host'];
    $this->configuration['connection']['port'] = $values['connection']['port'];
    $this->configuration['connection']['database'] = $values['connection']['database'];
    $this->configuration['connection']['username'] = $values['connection']['username'];
    $this->configuration['connection']['ssl_mode'] = $values['connection']['ssl_mode'];

    // Handle password configuration - both key and direct password are optional
    if (!empty($values['connection']['password_key'])) {
      $this->configuration['connection']['password_key'] = $values['connection']['password_key'];
      // Clear direct password if using key
      $this->configuration['connection']['password'] = '';
    }
    elseif (!empty($values['connection']['password'])) {
      // Only save if explicitly provided
      $this->configuration['connection']['password'] = $values['connection']['password'];
      $this->configuration['connection']['password_key'] = '';
    }

    // Save other configuration
    $this->configuration['index_prefix'] = $values['index_settings']['index_prefix'];
    $this->configuration['fts_configuration'] = $values['index_settings']['fts_configuration'];
    $this->configuration['debug'] = $values['advanced']['debug'];
    $this->configuration['batch_size'] = $values['advanced']['batch_size'];

    // Save AI embeddings configuration
    $this->configuration['ai_embeddings'] = $values['ai_embeddings'];
  }

  /**
   * Establishes connection to the database.
   */
  protected function connect() {
    if (!$this->connector) {
      $this->connector = new PostgreSQLConnector($this->configuration['connection'], $this->logger);
      $this->fieldMapper = new FieldMapper($this->configuration);
      $this->indexManager = new IndexManager($this->connector, $this->fieldMapper, $this->configuration);
      $this->queryBuilder = new QueryBuilder($this->connector, $this->fieldMapper, $this->configuration);
    }
  }

  /**
   * Gets the database password from key or direct configuration.
   */
  protected function getDatabasePassword() {
    $password_key = $this->configuration['connection']['password_key'] ?? '';
    $direct_password = $this->configuration['connection']['password'] ?? '';

    if (!empty($password_key) && $this->keyRepository) {
      $key = $this->keyRepository->getKey($password_key);
      if ($key) {
        return $key->getKeyValue();
      }
    }

    return $direct_password;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
      'search_api_facets_operator_or',
      'search_api_autocomplete',
      'search_api_spellcheck',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_grouping',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    $this->connect();
    return $this->indexManager->addIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    $this->connect();
    return $this->indexManager->updateIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    $this->connect();
    return $this->indexManager->removeIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $this->connect();
    return $this->indexManager->indexItems($index, $items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $ids) {
    $this->connect();
    return $this->indexManager->deleteItems($index, $ids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->connect();
    return $this->indexManager->deleteAllIndexItems($index, $datasource_id);
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $this->connect();
    return $this->queryBuilder->search($query);
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    try {
      $this->connect();
      $this->connector->testConnection();
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}