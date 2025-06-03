<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Config\Config;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\search_api_postgresql\PostgreSQL\QueryBuilder;
use Drupal\search_api_postgresql\PostgreSQL\IndexManager;
use Drupal\search_api_postgresql\PostgreSQL\FieldMapper;
use Drupal\search_api_postgresql\PostgreSQL\EmbeddingService;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL backend for Search API with AI embeddings support.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("Index items using PostgreSQL native full-text search with Azure Database compatibility and AI text embeddings")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * The PostgreSQL connector.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The query builder.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\QueryBuilder
   */
  protected $queryBuilder;

  /**
   * The index manager.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\IndexManager
   */
  protected $indexManager;

  /**
   * The field mapper.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\FieldMapper
   */
  protected $fieldMapper;

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\EmbeddingService
   */
  protected $embeddingService;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $backend = new static($configuration, $plugin_id, $plugin_definition);

    $backend->setLogger($container->get('logger.factory')->get('search_api_postgresql'));
    $backend->setMessenger($container->get('messenger'));
    $backend->setModuleHandler($container->get('module_handler'));
    
    // Key repository is now required
    if (!$container->has('key.repository')) {
      throw new SearchApiException('Key module is required for secure credential storage. Please install and enable the Key module.');
    }
    $backend->setKeyRepository($container->get('key.repository'));

    return $backend;
  }

  /**
   * Sets the logger.
   */
  public function setLogger($logger) {
    $this->logger = $logger;
  }

  /**
   * Sets the messenger.
   */
  public function setMessenger(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * Sets the module handler.
   */
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * Sets the key repository.
   */
  public function setKeyRepository(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
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
        'password_key' => '', // Changed from 'password' to 'password_key'
        'ssl_mode' => 'require',
        'options' => [],
      ],
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      'debug' => FALSE,
      'batch_size' => 100,
      'ai_embeddings' => [
        'enabled' => FALSE,
        'hybrid_search' => TRUE,
        'azure_ai' => [
          'endpoint' => '',
          'api_key_name' => '', // Changed from 'api_key' to 'api_key_name' (Key entity ID)
          'model' => 'text-embedding-ada-002',
          'dimensions' => 1536,
          'batch_size' => 10,
        ],
        'similarity_threshold' => 0.7,
        'weight_vector' => 0.6,
        'weight_fulltext' => 0.4,
      ],
    ];
  }

  /**
   * Gets a secure key value from the Key module.
   *
   * @param string $key_id
   *   The Key entity ID.
   *
   * @return string
   *   The decrypted key value.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the key cannot be retrieved.
   */
  protected function getSecureKey($key_id) {
    if (empty($key_id)) {
      throw new SearchApiException('No key ID provided for secure key retrieval.');
    }

    if (!$this->keyRepository) {
      throw new SearchApiException('Key repository service is not available.');
    }

    $key = $this->keyRepository->getKey($key_id);
    if (!$key) {
      throw new SearchApiException(sprintf('Key with ID "%s" not found.', $key_id));
    }

    $key_value = $key->getKeyValue();
    if (empty($key_value)) {
      throw new SearchApiException(sprintf('Key "%s" is empty or could not be decrypted.', $key_id));
    }

    return $key_value;
  }

  /**
   * Gets the database password from secure storage.
   *
   * @return string
   *   The database password.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the password cannot be retrieved.
   */
  protected function getDatabasePassword() {
    $password_key = $this->configuration['connection']['password_key'] ?? '';
    if (empty($password_key)) {
      throw new SearchApiException('Database password key is not configured. Please configure a key for secure password storage.');
    }

    return $this->getSecureKey($password_key);
  }

  /**
   * Gets the Azure AI API key from secure storage.
   *
   * @return string
   *   The Azure AI API key.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the API key cannot be retrieved.
   */
  protected function getAzureApiKey() {
    if (!($this->configuration['ai_embeddings']['enabled'] ?? FALSE)) {
      return '';
    }

    $api_key_name = $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
    if (empty($api_key_name)) {
      throw new SearchApiException('Azure AI API key is not configured. Please configure a key for secure API key storage.');
    }

    return $this->getSecureKey($api_key_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = [
      'search_api_facets',
      'search_api_autocomplete',
      'search_api_spellcheck',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_grouping',
    ];

    // Add vector search features if embeddings are enabled.
    if ($this->configuration['ai_embeddings']['enabled']) {
      $features[] = 'search_api_vector_similarity';
      $features[] = 'search_api_hybrid_search';
    }

    return $features;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    $supported_types = [
      'text',
      'string',
      'integer',
      'decimal',
      'date',
      'boolean',
      'postgresql_fulltext',
    ];

    // Add vector data type if embeddings are enabled.
    if ($this->configuration['ai_embeddings']['enabled']) {
      $supported_types[] = 'vector';
    }

    return in_array($type, $supported_types);
  }

  /**
   * Initializes the PostgreSQL connection and related services.
   */
  protected function connect() {
    if (!$this->connector) {
      // Get secure database password
      $connection_config = $this->configuration['connection'];
      $connection_config['password'] = $this->getDatabasePassword();

      $this->connector = new PostgreSQLConnector($connection_config, $this->logger);
      $this->fieldMapper = new FieldMapper($this->configuration);
      $this->indexManager = new IndexManager($this->connector, $this->fieldMapper, $this->configuration);
      $this->queryBuilder = new QueryBuilder($this->connector, $this->fieldMapper, $this->configuration);

      // Initialize embedding service if enabled.
      if ($this->configuration['ai_embeddings']['enabled']) {
        $azure_config = $this->configuration['ai_embeddings']['azure_ai'];
        $api_key = $this->getAzureApiKey();
        $this->embeddingService = new EmbeddingService($azure_config, $api_key, $this->logger);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Check if Key module is available
    if (!$this->moduleHandler->moduleExists('key')) {
      $form['key_module_error'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--error">' . 
          $this->t('The Key module is required for secure credential storage. Please install and enable the <a href="@url">Key module</a>.', [
            '@url' => 'https://www.drupal.org/project/key',
          ]) . 
          '</div>',
      ];
      return $form;
    }

    $form['connection'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Database Connection'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database Host'),
      '#default_value' => $this->configuration['connection']['host'],
      '#required' => TRUE,
      '#description' => $this->t('Azure Database for PostgreSQL server name (e.g., myserver.postgres.database.azure.com) or localhost for local installations.'),
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Database Port'),
      '#default_value' => $this->configuration['connection']['port'],
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 65535,
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database Name'),
      '#default_value' => $this->configuration['connection']['database'],
      '#required' => TRUE,
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'],
      '#required' => TRUE,
    ];

    // Get available keys for database password
    $key_options = $this->getAvailableKeys();
    
    $form['connection']['password_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Database Password Key'),
      '#options' => $key_options,
      '#empty_option' => $this->t('- Select a key -'),
      '#default_value' => $this->configuration['connection']['password_key'],
      '#required' => TRUE,
      '#description' => $this->t('Select the key containing your database password. <a href="@url">Create a new key</a> if needed.', [
        '@url' => '/admin/config/system/keys/add',
      ]),
    ];

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
      '#default_value' => $this->configuration['connection']['ssl_mode'],
      '#description' => $this->t('SSL connection mode. "Require" is recommended for Azure Database for PostgreSQL.'),
    ];

    $form['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Table Prefix'),
      '#default_value' => $this->configuration['index_prefix'],
      '#description' => $this->t('Prefix for database tables created by this backend.'),
    ];

    $form['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Text Search Configuration'),
      '#options' => [
        'english' => $this->t('English'),
        'simple' => $this->t('Simple'),
        'french' => $this->t('French'),
        'german' => $this->t('German'),
        'spanish' => $this->t('Spanish'),
        'portuguese' => $this->t('Portuguese'),
        'italian' => $this->t('Italian'),
        'dutch' => $this->t('Dutch'),
        'russian' => $this->t('Russian'),
      ],
      '#default_value' => $this->configuration['fts_configuration'],
      '#description' => $this->t('PostgreSQL text search configuration for stemming and stop word filtering.'),
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Indexing Batch Size'),
      '#default_value' => $this->configuration['batch_size'],
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to process in each indexing batch.'),
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#default_value' => $this->configuration['debug'],
      '#description' => $this->t('Enable detailed logging of database queries.'),
    ];

    // AI Embeddings Configuration
    $form['ai_embeddings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI Text Embeddings'),
      '#collapsible' => TRUE,
      '#collapsed' => !$this->configuration['ai_embeddings']['enabled'],
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Text Embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'],
      '#description' => $this->t('Enable vector-based semantic search using Azure AI services.'),
    ];

    $form['ai_embeddings']['hybrid_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search'],
      '#description' => $this->t('Combine traditional full-text search with vector similarity search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Azure AI Services Configuration
    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure AI Services'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure AI Services Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'],
      '#description' => $this->t('Your Azure AI Services endpoint URL (e.g., https://yourservice.openai.azure.com/).'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['api_key_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure AI Services API Key'),
      '#options' => $key_options,
      '#empty_option' => $this->t('- Select a key -'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'],
      '#description' => $this->t('Select the key containing your Azure AI Services API key. <a href="@url">Create a new key</a> if needed.', [
        '@url' => '/admin/config/system/keys/add',
      ]),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
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
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'],
      '#description' => $this->t('The embedding model to use for generating vectors.'),
    ];

    $form['ai_embeddings']['azure_ai']['dimensions'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Dimensions'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['dimensions'],
      '#min' => 128,
      '#max' => 3072,
      '#description' => $this->t('Number of dimensions for the embedding vectors.'),
    ];

    $form['ai_embeddings']['azure_ai']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Embedding Batch Size'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['batch_size'],
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to process in each embedding API call.'),
    ];

    // Advanced vector search settings
    $form['ai_embeddings']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#default_value' => $this->configuration['ai_embeddings']['similarity_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#description' => $this->t('Minimum similarity score for vector search results (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['weight_vector'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['weight_vector'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity in hybrid search (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[ai_embeddings][hybrid_search]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['weight_fulltext'] = [
      '#type' => 'number',
      '#title' => $this->t('Full-text Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['weight_fulltext'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for full-text search in hybrid search (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[ai_embeddings][hybrid_search]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Test connection buttons
    $form['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Database Connection'),
      '#ajax' => [
        'callback' => [$this, 'testConnectionAjax'],
        'wrapper' => 'connection-test-result',
      ],
    ];

    $form['test_azure_ai'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Azure AI Connection'),
      '#ajax' => [
        'callback' => [$this, 'testAzureAiAjax'],
        'wrapper' => 'azure-ai-test-result',
      ],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['connection_test_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    $form['azure_ai_test_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'azure-ai-test-result'],
    ];

    return $form;
  }

  /**
   * Gets available keys for selection.
   *
   * @return array
   *   Array of key options.
   */
  protected function getAvailableKeys() {
    $key_options = [];
    
    if ($this->keyRepository) {
      $keys = $this->keyRepository->getKeys();
      foreach ($keys as $key_id => $key) {
        $key_options[$key_id] = sprintf('%s (%s)', $key->label(), $key_id);
      }
    }

    return $key_options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Test database connection with secure password
    $connection_config = [
      'host' => $form_state->getValue(['connection', 'host']),
      'port' => $form_state->getValue(['connection', 'port']),
      'database' => $form_state->getValue(['connection', 'database']),
      'username' => $form_state->getValue(['connection', 'username']),
      'ssl_mode' => $form_state->getValue(['connection', 'ssl_mode']),
    ];

    // Get password from selected key
    $password_key = $form_state->getValue(['connection', 'password_key']);
    if (empty($password_key)) {
      $form_state->setErrorByName('connection][password_key', $this->t('Database password key is required.'));
      return;
    }

    try {
      $connection_config['password'] = $this->getSecureKey($password_key);
      $test_connector = new PostgreSQLConnector($connection_config, $this->logger);
      $test_connector->testConnection();
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('connection', $this->t('Database connection failed: @message', ['@message' => $e->getMessage()]));
    }

    // Validate AI embeddings configuration if enabled
    if ($form_state->getValue(['ai_embeddings', 'enabled'])) {
      $azure_config = $form_state->getValue(['ai_embeddings', 'azure_ai']);
      
      if (empty($azure_config['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', $this->t('Azure AI Services endpoint is required when embeddings are enabled.'));
      }

      if (empty($azure_config['api_key_name'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][api_key_name', $this->t('Azure AI API key is required when embeddings are enabled.'));
      }

      // Test Azure AI connection
      try {
        $api_key = $this->getSecureKey($azure_config['api_key_name']);
        // Perform test connection here if needed
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][api_key_name', $this->t('Failed to retrieve Azure AI API key: @message', ['@message' => $e->getMessage()]));
      }

      // Validate hybrid search weights sum to 1
      if ($form_state->getValue(['ai_embeddings', 'hybrid_search'])) {
        $vector_weight = $form_state->getValue(['ai_embeddings', 'weight_vector']);
        $fulltext_weight = $form_state->getValue(['ai_embeddings', 'weight_fulltext']);
        
        if (abs(($vector_weight + $fulltext_weight) - 1.0) > 0.01) {
          $form_state->setError($form['ai_embeddings']['weight_vector'], $this->t('Vector and full-text search weights must sum to 1.0.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Save connection settings (no plain text passwords)
    $this->configuration['connection']['host'] = $form_state->getValue(['connection', 'host']);
    $this->configuration['connection']['port'] = $form_state->getValue(['connection', 'port']);
    $this->configuration['connection']['database'] = $form_state->getValue(['connection', 'database']);
    $this->configuration['connection']['username'] = $form_state->getValue(['connection', 'username']);
    $this->configuration['connection']['password_key'] = $form_state->getValue(['connection', 'password_key']);
    $this->configuration['connection']['ssl_mode'] = $form_state->getValue(['connection', 'ssl_mode']);
    
    $this->configuration['index_prefix'] = $form_state->getValue('index_prefix');
    $this->configuration['fts_configuration'] = $form_state->getValue('fts_configuration');
    $this->configuration['batch_size'] = $form_state->getValue('batch_size');
    $this->configuration['debug'] = $form_state->getValue('debug');

    // Save AI embeddings settings (no plain text API keys)
    $this->configuration['ai_embeddings']['enabled'] = $form_state->getValue(['ai_embeddings', 'enabled']);
    $this->configuration['ai_embeddings']['hybrid_search'] = $form_state->getValue(['ai_embeddings', 'hybrid_search']);
    
    // Save Azure AI settings (only key references)
    $azure_ai = $form_state->getValue(['ai_embeddings', 'azure_ai']);
    $this->configuration['ai_embeddings']['azure_ai']['endpoint'] = $azure_ai['endpoint'];
    $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] = $azure_ai['api_key_name'];
    $this->configuration['ai_embeddings']['azure_ai']['model'] = $azure_ai['model'];
    $this->configuration['ai_embeddings']['azure_ai']['dimensions'] = $azure_ai['dimensions'];
    $this->configuration['ai_embeddings']['azure_ai']['batch_size'] = $azure_ai['batch_size'];

    $this->configuration['ai_embeddings']['similarity_threshold'] = $form_state->getValue(['ai_embeddings', 'similarity_threshold']);
    $this->configuration['ai_embeddings']['weight_vector'] = $form_state->getValue(['ai_embeddings', 'weight_vector']);
    $this->configuration['ai_embeddings']['weight_fulltext'] = $form_state->getValue(['ai_embeddings', 'weight_fulltext']);
  }

  /**
   * AJAX callback for testing database connection.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state) {
    $connection_config = [
      'host' => $form_state->getValue(['connection', 'host']),
      'port' => $form_state->getValue(['connection', 'port']),
      'database' => $form_state->getValue(['connection', 'database']),
      'username' => $form_state->getValue(['connection', 'username']),
      'ssl_mode' => $form_state->getValue(['connection', 'ssl_mode']),
    ];

    try {
      $password_key = $form_state->getValue(['connection', 'password_key']);
      $connection_config['password'] = $this->getSecureKey($password_key);
      
      $test_connector = new PostgreSQLConnector($connection_config, $this->logger);
      $test_connector->testConnection();
      
      $form['connection_test_result']['#markup'] = '<div class="messages messages--status">' . $this->t('Database connection successful!') . '</div>';
    }
    catch (\Exception $e) {
      $form['connection_test_result']['#markup'] = '<div class="messages messages--error">' . $this->t('Database connection failed: @message', ['@message' => $e->getMessage()]) . '</div>';
    }

    return $form['connection_test_result'];
  }

  /**
   * AJAX callback for testing Azure AI connection.
   */
  public function testAzureAiAjax(array &$form, FormStateInterface $form_state) {
    $azure_config = $form_state->getValue(['ai_embeddings', 'azure_ai']);
    
    try {
      $api_key = $this->getSecureKey($azure_config['api_key_name']);

      // Test the connection with a simple embedding request
      $test_service = new EmbeddingService($azure_config, $api_key, $this->logger);
      $test_embedding = $test_service->generateEmbeddings(['test text']);
      
      $form['azure_ai_test_result']['#markup'] = '<div class="messages messages--status">' . $this->t('Azure AI connection successful! Generated @count-dimensional embedding.', ['@count' => count($test_embedding[0])]) . '</div>';
    }
    catch (\Exception $e) {
      $form['azure_ai_test_result']['#markup'] = '<div class="messages messages--error">' . $this->t('Azure AI connection failed: @message', ['@message' => $e->getMessage()]) . '</div>';
    }

    return $form['azure_ai_test_result'];
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    $this->connect();
    return $this->indexManager->createIndex($index);
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
    return $this->indexManager->dropIndex($index);
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
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $this->connect();
    return $this->indexManager->deleteItems($index, $item_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->connect();
    return $this->indexManager->deleteAllItems($index, $datasource_id);
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $this->connect();
    
    // Build the search query
    $query_info = $this->queryBuilder->buildSearchQuery($query);
    
    // Execute the query
    $stmt = $this->connector->executeQuery($query_info['sql'], $query_info['params']);
    
    // Create result set
    $results = new ResultSet($query);
    $items = [];
    
    while ($row = $stmt->fetch()) {
      $item = $this->fieldMapper->createResultItem($query->getIndex(), $row);
      $items[] = $item;
    }
    
    $results->setResultItems($items);
    
    // Get total count if needed
    if ($query->getOption('skip result count') !== TRUE) {
      $count_info = $this->queryBuilder->buildCountQuery($query);
      $count_stmt = $this->connector->executeQuery($count_info['sql'], $count_info['params']);
      $results->setResultCount($count_stmt->fetchColumn());
    }
    
    return $results;
  }

}