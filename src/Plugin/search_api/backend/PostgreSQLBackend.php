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
use Drupal\search_api_postgresql\PostgreSQL\EmbeddingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * PostgreSQL search backend.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("Index items using PostgreSQL full-text search with optional AI embeddings.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements PluginFormInterface, ContainerFactoryPluginInterface {

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
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\EmbeddingService
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    
    // Only set key repository if Key module is enabled
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
   * Establishes database connection and initializes components.
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
    
    // Resolve password from key if configured
    if (!empty($config['password_key']) && !empty($this->keyRepository)) {
      $key = $this->keyRepository->getKey($config['password_key']);
      if ($key) {
        $config['password'] = $key->getKeyValue();
      }
    }

    // Create connector - it connects automatically in constructor
    $this->connector = new PostgreSQLConnector($config, $this->getLogger());

    // FIXED: Create field mapper with configuration
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
      'label' => $this->t('AI Embeddings'),
      'info' => $this->configuration['ai_embeddings']['enabled'] ? $this->t('Enabled') : $this->t('Disabled'),
    ];

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    try {
      $this->connect();
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
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
  public function indexItems(IndexInterface $index, array $items) {
    $this->connect();
    $indexed = [];

    foreach ($items as $item) {
      try {
        $this->indexManager->indexItem($this->getIndexTableName($index), $index, $item);
        $indexed[] = $item->getId();
      }
      catch (\Exception $e) {
        $this->getLogger()->error('Failed to index item @id: @message', [
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
    try {
      $this->connect();
      $this->indexManager->deleteItems($this->getIndexTableName($index), $item_ids);
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

    // Add key module fields for secure password storage if available
    if (!empty($this->keyRepository)) {
      $this->addKeyFieldsToForm($form);
    }
    else {
      // Fallback to direct password field
      $form['connection']['password'] = [
        '#type' => 'password',
        '#title' => $this->t('Password'),
        '#description' => $this->t('The password will not be shown. Leave empty to keep current password.'),
      ];
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

    // AI Embeddings settings
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings (Vector Search)'),
      '#open' => FALSE,
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'],
      '#description' => $this->t('Enable vector search using AI-generated embeddings.'),
    ];

    // Azure AI settings (shown only when embeddings are enabled)
    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure AI Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure AI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'],
      '#description' => $this->t('Your Azure OpenAI endpoint URL.'),
      '#states' => [
        'required' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add Azure AI API key fields
    if (!empty($this->keyRepository)) {
      $this->addAzureKeyFieldsToForm($form);
    }
    else {
      $form['ai_embeddings']['azure_ai']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('Azure AI API Key'),
        '#description' => $this->t('Your Azure OpenAI API key.'),
        '#states' => [
          'required' => [
            ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['ai_embeddings']['azure_ai']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'],
      '#description' => $this->t('The name of your Azure OpenAI deployment.'),
      '#states' => [
        'required' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
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

    // Validate AI embeddings if enabled
    if (!empty($values['ai_embeddings']['enabled'])) {
      if (empty($values['ai_embeddings']['azure_ai']['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', $this->t('Azure AI endpoint is required when embeddings are enabled.'));
      }

      if (empty($values['ai_embeddings']['azure_ai']['deployment_name'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][deployment_name', $this->t('Deployment name is required when embeddings are enabled.'));
      }
    }

    // Test connection if possible
    if (!$form_state->hasAnyErrors()) {
      try {
        $config = $values['connection'];
        $test_connector = new PostgreSQLConnector($config, $this->getLogger());
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

    // Process password if not using key module
    if (empty($this->keyRepository) && !empty($values['connection']['password'])) {
      $this->configuration['connection']['password'] = $values['connection']['password'];
    }

    // Save other configuration
    $this->configuration['connection']['host'] = $values['connection']['host'];
    $this->configuration['connection']['port'] = $values['connection']['port'];
    $this->configuration['connection']['database'] = $values['connection']['database'];
    $this->configuration['connection']['username'] = $values['connection']['username'];
    $this->configuration['connection']['ssl_mode'] = $values['connection']['ssl_mode'];

    $this->configuration['index_prefix'] = $values['index_settings']['index_prefix'];
    $this->configuration['fts_configuration'] = $values['index_settings']['fts_configuration'];

    $this->configuration['debug'] = $values['advanced']['debug'];
    $this->configuration['batch_size'] = $values['advanced']['batch_size'];

    $this->configuration['ai_embeddings'] = $values['ai_embeddings'];
  }
}