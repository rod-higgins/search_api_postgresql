<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\search_api_postgresql\PostgreSQL\IndexManager;
use Drupal\search_api_postgresql\PostgreSQL\QueryBuilder;
use Drupal\search_api_postgresql\PostgreSQL\FieldMapper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL search backend.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("Index items using PostgreSQL full-text search with optional AI embeddings.")
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
   * The field mapper.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\FieldMapper
   */
  protected $fieldMapper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setKeyRepository($container->get('key.repository'));
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
        'ssl_mode' => 'prefer',
        'options' => [],
      ],
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      'debug' => FALSE,
      'batch_size' => 50,
      'ai_embeddings' => [
        'enabled' => FALSE,
        'azure_ai' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_name' => '',
          'deployment_name' => '',
          'api_version' => '2024-02-01',
          'model' => 'text-embedding-ada-002',
          'dimensions' => 1536,
          'batch_size' => 16,
          'timeout' => 30,
          'retry_attempts' => 3,
          'retry_delay' => 1000,
        ],
      ],
    ];
  }

  /**
   * Establishes database connection.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function connect() {
    if (!$this->connector) {
      $config = $this->configuration['connection'];
      
      // Use Key module for password if configured
      if (!empty($config['password_key'])) {
        $config['password'] = $this->getDatabasePassword();
      }
      
      $this->connector = new PostgreSQLConnector($config, $this->logger);
      $this->fieldMapper = new FieldMapper();
      $this->indexManager = new IndexManager($this->connector, $this->fieldMapper, $this->configuration);
      $this->queryBuilder = new QueryBuilder($this->connector, $this->fieldMapper, $this->configuration);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = [];

    $info[] = [
      'label' => $this->t('Host'),
      'info' => $this->configuration['connection']['host'],
    ];

    $info[] = [
      'label' => $this->t('Database'),
      'info' => $this->configuration['connection']['database'],
    ];

    $info[] = [
      'label' => $this->t('FTS Configuration'),
      'info' => $this->configuration['fts_configuration'],
    ];

    if ($this->configuration['ai_embeddings']['enabled']) {
      $info[] = [
        'label' => $this->t('AI Embeddings'),
        'info' => $this->t('Enabled (Azure AI)'),
      ];
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
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
  public function supportsDataType($type) {
    return in_array($type, [
      'text',
      'string',
      'integer',
      'decimal',
      'date',
      'boolean',
      'postgresql_fulltext',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    $this->connect();
    $this->indexManager->createIndexTable($index);
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    $this->connect();
    $this->indexManager->updateIndexTable($index);
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    $this->connect();
    $this->indexManager->dropIndexTable($index);
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $this->connect();
    $indexed = [];

    foreach ($items as $item) {
      try {
        $this->indexManager->indexItem($item, $index);
        $indexed[] = $item->getId();
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to index item @id: @message', [
          '@id' => $item->getId(),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $indexed;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $this->connect();
    $this->indexManager->deleteItems($index, $item_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->connect();
    $this->indexManager->clearIndex($index, $datasource_id);
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

    // Add key module fields for secure password storage
    $this->addKeyFieldsToForm($form);

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
        'arabic' => $this->t('Arabic'),
        'danish' => $this->t('Danish'),
        'dutch' => $this->t('Dutch'),
        'finnish' => $this->t('Finnish'),
        'hungarian' => $this->t('Hungarian'),
        'norwegian' => $this->t('Norwegian'),
        'romanian' => $this->t('Romanian'),
        'swedish' => $this->t('Swedish'),
        'turkish' => $this->t('Turkish'),
      ],
      '#default_value' => $this->configuration['fts_configuration'],
      '#description' => $this->t('PostgreSQL text search configuration to use.'),
    ];

    $form['index_settings']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $this->configuration['batch_size'],
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to index per batch.'),
    ];

    // AI Embeddings (Optional)
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Text Embeddings (Optional)'),
      '#open' => $this->configuration['ai_embeddings']['enabled'],
      '#description' => $this->t('Enable semantic search using AI-generated text embeddings.'),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI text embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'],
      '#description' => $this->t('Requires pgvector extension and Azure AI services.'),
    ];

    // Azure AI settings
    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'container',
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
      '#description' => $this->t('Your Azure OpenAI endpoint (e.g., https://myresource.openai.azure.com/)'),
    ];

    // Add secure API key field
    $keys = [];
    foreach ($this->getKeyRepository()->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }

    $form['ai_embeddings']['azure_ai']['api_key_name'] = [
      '#type' => 'select',
      '#title' => $this->t('API Key'),
      '#options' => $keys,
      '#empty_option' => $this->t('- Select a key -'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'],
      '#description' => $this->t('Select a key containing your Azure AI API key.'),
    ];

    $form['ai_embeddings']['azure_ai']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'],
      '#description' => $this->t('Your Azure OpenAI deployment name for embeddings.'),
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
      '#description' => $this->t('Log all queries and operations. Disable in production.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Test database connection
    $values = $form_state->getValues();
    $config = $values['connection'];
    
    // Get password from key if configured
    if (!empty($config['password_key'])) {
      try {
        $config['password'] = $this->getDatabasePassword();
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('connection][password_key', $e->getMessage());
        return;
      }
    }
    
    try {
      $test_connector = new PostgreSQLConnector($config, $this->logger);
      $test_connector->testConnection();
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('connection', $this->t('Database connection failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Save all configuration
    $this->configuration = $values + $this->configuration;
    
    // Clear connection to force reconnect with new settings
    $this->connector = NULL;
  }
}