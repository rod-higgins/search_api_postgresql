<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\search_api_postgresql\PostgreSQL\FieldMapper;
use Drupal\search_api_postgresql\PostgreSQL\IndexManager;
use Drupal\search_api_postgresql\PostgreSQL\QueryBuilder;
use Drupal\search_api_postgresql\Service\EmbeddingService;

/**
 * PostgreSQL Search API backend.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("PostgreSQL database backend with full-text search and optional AI embeddings")
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
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

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
        'password' => '', // Optional - can be empty
        'password_key' => '', // Optional - Key module integration
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
        'azure_ai' => [
          'endpoint' => '',
          'api_key' => '', // Deprecated: use api_key_name instead
          'api_key_name' => '', // Key module integration
          'deployment_name' => '',
          'model' => 'text-embedding-ada-002',
          'dimension' => 1536,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function postInsert() {
    // Initialize connection on server creation
    try {
      $this->connect();
      $this->connector->testConnection();
    }
    catch (\Exception $e) {
      $this->getLogger()->warning('Failed to test connection during server creation: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    try {
      $this->connect();
      $this->indexManager->createIndex($index);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to create index: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    try {
      $this->connect();
      $this->indexManager->updateIndex($index);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to update index: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    try {
      $this->connect();
      $this->indexManager->dropIndex($index);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to remove index: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    try {
      $this->connect();
      return $this->indexManager->indexItems($index, $items);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to index items: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    try {
      $this->connect();
      $this->indexManager->deleteItems($index, $item_ids);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to delete items: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    try {
      $this->connect();
      $this->indexManager->clearIndex($index, $datasource_id);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Failed to clear index: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    try {
      $this->connect();
      return $this->queryBuilder->buildSearchQuery($query);
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Search failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Drupal\search_api\SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Gets the table name for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   *
   * @return string
   *   The table name.
   */
  protected function getIndexTableName(IndexInterface $index) {
    return $this->configuration['index_prefix'] . $index->id();
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
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->configuration['connection']['host'],
      '#required' => TRUE,
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['connection']['port'],
      '#min' => 1,
      '#max' => 65535,
      '#required' => TRUE,
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'],
      '#required' => TRUE,
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'],
      '#required' => TRUE,
    ];

    // Password fields - both optional
    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Database password. Leave empty for passwordless connections (e.g., Lando development). Not shown when editing existing configuration.'),
      '#required' => FALSE, // Password is optional
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
      '#default_value' => $this->configuration['connection']['ssl_mode'],
      '#description' => $this->t('SSL connection mode. Use "require" or higher for production.'),
    ];

    // Index settings
    $form['index_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Index Settings'),
      '#open' => TRUE,
    ];

    $form['index_settings']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index table prefix'),
      '#default_value' => $this->configuration['index_prefix'],
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
      '#default_value' => $this->configuration['fts_configuration'],
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
      '#title' => $this->t('Enable debug mode'),
      '#default_value' => $this->configuration['debug'],
      '#description' => $this->t('Log queries and debug information.'),
    ];

    $form['advanced']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $this->configuration['batch_size'],
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to process in each batch.'),
    ];

    // AI Embeddings configuration
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings (Azure OpenAI)'),
      '#open' => FALSE,
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'],
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings.'),
    ];

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
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'],
      '#description' => $this->t('Your Azure OpenAI resource endpoint (e.g., https://your-resource.openai.azure.com/)'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add Azure AI key fields if Key module is available
    if (!empty($this->keyRepository)) {
      $this->addAzureKeyFieldsToForm($form);
    }
    else {
      // Fallback to direct API key field
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
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'],
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
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'],
      '#description' => $this->t('The embedding model to use.'),
      '#states' => [
        'required' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
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
    // Only validate if both are empty AND we're not in a development environment
    $password_key = $values['connection']['password_key'] ?? '';
    $direct_password = $values['connection']['password'] ?? '';
    
    if (empty($password_key) && empty($direct_password)) {
      // Allow empty passwords but show a warning for security
      \Drupal::messenger()->addWarning($this->t('No database password configured. This may be acceptable for development environments (like Lando) but is not recommended for production.'));
    }

    // Validate AI embeddings if enabled
    if (!empty($values['ai_embeddings']['enabled'])) {
      if (empty($values['ai_embeddings']['azure_ai']['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', $this->t('Azure AI endpoint is required when embeddings are enabled.'));
      }

      if (empty($values['ai_embeddings']['azure_ai']['deployment_name'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][deployment_name', $this->t('Deployment name is required when embeddings are enabled.'));
      }

      // Check that either API key or API key name is provided
      $api_key = $values['ai_embeddings']['azure_ai']['api_key'] ?? '';
      $api_key_name = $values['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
      
      if (empty($api_key) && empty($api_key_name)) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][api_key', $this->t('Azure AI API key is required when embeddings are enabled.'));
      }
    }

    // Test connection if possible and no other errors
    if (!$form_state->hasAnyErrors()) {
      try {
        $config = $values['connection'];
        
        // Handle password resolution for testing
        if (!empty($config['password_key']) && !empty($this->keyRepository)) {
          $key = $this->keyRepository->getKey($config['password_key']);
          if ($key) {
            $config['password'] = $key->getKeyValue();
          }
        }
        
        $test_connector = new PostgreSQLConnector($config, $this->getLogger());
        // No need to call connect() explicitly as constructor handles it
        $test_connector->testConnection();
        \Drupal::messenger()->addStatus($this->t('Successfully connected to PostgreSQL database.'));
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
    // If both are empty, preserve existing configuration or set empty

    // Save other configuration
    $this->configuration['index_prefix'] = $values['index_settings']['index_prefix'];
    $this->configuration['fts_configuration'] = $values['index_settings']['fts_configuration'];

    $this->configuration['debug'] = $values['advanced']['debug'];
    $this->configuration['batch_size'] = $values['advanced']['batch_size'];

    $this->configuration['ai_embeddings'] = $values['ai_embeddings'];
  }

  /**
   * Establishes connection to the database.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If connection fails.
   */
  protected function connect() {
    // Check if already initialized
    if ($this->connector) {
      return;
    }

    // Get connection configuration
    $config = $this->configuration['connection'];
    
    // Resolve password from key if configured, otherwise use direct password
    $config['password'] = $this->getDatabasePassword();

    // Create connector - it connects automatically in constructor
    $this->connector = new PostgreSQLConnector($config, $this->getLogger());

    // Create field mapper with configuration
    $this->fieldMapper = new FieldMapper($this->configuration);

    // Create embedding service if AI embeddings are enabled
    if (!empty($this->configuration['ai_embeddings']['enabled'])) {
      $this->embeddingService = new EmbeddingService($this->configuration['ai_embeddings']);
    }

    // Create index manager with embedding service
    $this->indexManager = new IndexManager(
      $this->connector,
      $this->fieldMapper,
      $this->configuration,
      $this->embeddingService
    );

    // Create query builder
    $this->queryBuilder = new QueryBuilder(
      $this->connector,
      $this->fieldMapper,
      $this->configuration
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = [];

    $info[] = [
      'label' => $this->t('Host'),
      'info' => $this->configuration['connection']['host'] . ':' . $this->configuration['connection']['port'],
    ];

    $info[] = [
      'label' => $this->t('Database'),
      'info' => $this->configuration['connection']['database'],
    ];

    $info[] = [
      'label' => $this->t('Table prefix'),
      'info' => $this->configuration['index_prefix'],
    ];

    $info[] = [
      'label' => $this->t('Full-text configuration'),
      'info' => $this->configuration['fts_configuration'],
    ];

    $info[] = [
      'label' => $this->t('Password Configuration'),
      'info' => !empty($this->configuration['connection']['password_key']) 
        ? $this->t('Using Key module (@key)', ['@key' => $this->configuration['connection']['password_key']])
        : (!empty($this->configuration['connection']['password']) 
          ? $this->t('Direct password (configured)')
          : $this->t('No password (passwordless connection)')),
    ];

    $info[] = [
      'label' => $this->t('AI Embeddings'),
      'info' => $this->configuration['ai_embeddings']['enabled'] 
        ? $this->t('Enabled (@model)', ['@model' => $this->configuration['ai_embeddings']['azure_ai']['model']])
        : $this->t('Disabled'),
    ];

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
      'search_api_facets_operator_or',
      'search_api_granular',
      'search_api_grouping',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_spellcheck',
    ];
  }

  /**
   * Gets the logger for this backend.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  public function getLogger() {
    return \Drupal::logger('search_api_postgresql');
  }
}