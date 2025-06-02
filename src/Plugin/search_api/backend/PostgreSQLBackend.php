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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL backend for Search API.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("Index items using PostgreSQL native full-text search with Azure Database compatibility")
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
        'ssl_mode' => 'require',
        'options' => [],
      ],
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      'debug' => FALSE,
      'batch_size' => 100,
    ];
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
    try {
      $this->connect();
      $this->indexManager->createIndex($index);
      $this->logger->info('Created index table for index @index', ['@index' => $index->label()]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create index @index: @message', [
        '@index' => $index->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    try {
      $this->connect();
      $this->indexManager->updateIndex($index);
      $this->logger->info('Updated index table for index @index', ['@index' => $index->label()]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update index @index: @message', [
        '@index' => $index->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    try {
      $this->connect();
      $this->indexManager->dropIndex($index);
      $this->logger->info('Removed index table for index @index', ['@index' => $index->label()]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to remove index @index: @message', [
        '@index' => $index->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    if (empty($items)) {
      return [];
    }

    try {
      $this->connect();
      $indexed_items = [];
      $batch_size = $this->configuration['batch_size'];

      // Process items in batches.
      $batches = array_chunk($items, $batch_size, TRUE);
      foreach ($batches as $batch) {
        $batch_result = $this->indexManager->indexItems($index, $batch);
        $indexed_items = array_merge($indexed_items, $batch_result);
      }

      $this->logger->info('Indexed @count items for index @index', [
        '@count' => count($indexed_items),
        '@index' => $index->label(),
      ]);

      return $indexed_items;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to index items for @index: @message', [
        '@index' => $index->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    if (empty($item_ids)) {
      return;
    }

    try {
      $this->connect();
      $this->indexManager->deleteItems($index, $item_ids);
      $this->logger->info('Deleted @count items from index @index', [
        '@count' => count($item_ids),
        '@index' => $index->label(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete items from @index: @message', [
        '@index' => $index->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    try {
      $this->connect();
      $this->indexManager->deleteAllItems($index, $datasource_id);
      $this->logger->info('Deleted all items from index @index', ['@index' => $index->label()]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete all items from @index: @message', [
        '@index' => $index->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    try {
      $this->connect();
      
      // Build and execute the search query.
      $sql_query = $this->queryBuilder->buildSearchQuery($query);
      $results = $this->connector->executeQuery($sql_query['sql'], $sql_query['params']);
      
      // Handle facets if enabled.
      $facet_results = [];
      if ($query->getOption('search_api_facets')) {
        $facet_results = $this->queryBuilder->executeFacetQueries($query);
      }
      
      // Build result set.
      $result_set = $this->buildResultSet($query, $results, $facet_results);
      
      $this->logger->debug('Executed search query for index @index: @query', [
        '@index' => $query->getIndex()->label(),
        '@query' => $sql_query['sql'],
      ]);
      
      return $result_set;
    }
    catch (\Exception $e) {
      $this->logger->error('Search failed for index @index: @message', [
        '@index' => $query->getIndex()->label(),
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAutocompleteSuggestions(QueryInterface $query, $incomplete_key, $user_input) {
    try {
      $this->connect();
      return $this->queryBuilder->getAutocompleteSuggestions($query, $incomplete_key, $user_input);
    }
    catch (\Exception $e) {
      $this->logger->error('Autocomplete failed for index @index: @message', [
        '@index' => $query->getIndex()->label(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Initializes the PostgreSQL connection and related services.
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
   * Builds a result set from search results.
   */
  protected function buildResultSet(QueryInterface $query, $results, array $facet_results = []) {
    $result_set = $query->getResults();
    $index = $query->getIndex();
    
    // Set result count.
    $count_query = $this->queryBuilder->buildCountQuery($query);
    $count_result = $this->connector->executeQuery($count_query['sql'], $count_query['params']);
    $total_count = $count_result->fetchColumn();
    $result_set->setResultCount($total_count);
    
    // Add result items.
    $result_items = [];
    while ($row = $results->fetch()) {
      $item = $this->fieldMapper->createResultItem($index, $row);
      $result_items[] = $item;
    }
    $result_set->setResultItems($result_items);
    
    // Add facet results.
    if (!empty($facet_results)) {
      foreach ($facet_results as $facet_id => $facet_data) {
        $result_set->setExtraData("search_api_facets.{$facet_id}", $facet_data);
      }
    }
    
    return $result_set;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
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

    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Leave empty to keep current password.'),
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

    // Test connection button.
    $form['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => [$this, 'testConnectionAjax'],
        'wrapper' => 'connection-test-result',
      ],
    ];

    $form['connection_test_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Test database connection.
    $connection_config = [
      'host' => $form_state->getValue(['connection', 'host']),
      'port' => $form_state->getValue(['connection', 'port']),
      'database' => $form_state->getValue(['connection', 'database']),
      'username' => $form_state->getValue(['connection', 'username']),
      'password' => $form_state->getValue(['connection', 'password']) ?: $this->configuration['connection']['password'],
      'ssl_mode' => $form_state->getValue(['connection', 'ssl_mode']),
    ];

    try {
      $test_connector = new PostgreSQLConnector($connection_config, $this->logger);
      $test_connector->testConnection();
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('connection', $this->t('Database connection failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['connection']['host'] = $form_state->getValue(['connection', 'host']);
    $this->configuration['connection']['port'] = $form_state->getValue(['connection', 'port']);
    $this->configuration['connection']['database'] = $form_state->getValue(['connection', 'database']);
    $this->configuration['connection']['username'] = $form_state->getValue(['connection', 'username']);
    
    if ($password = $form_state->getValue(['connection', 'password'])) {
      $this->configuration['connection']['password'] = $password;
    }
    
    $this->configuration['connection']['ssl_mode'] = $form_state->getValue(['connection', 'ssl_mode']);
    $this->configuration['index_prefix'] = $form_state->getValue('index_prefix');
    $this->configuration['fts_configuration'] = $form_state->getValue('fts_configuration');
    $this->configuration['batch_size'] = $form_state->getValue('batch_size');
    $this->configuration['debug'] = $form_state->getValue('debug');
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
      'password' => $form_state->getValue(['connection', 'password']) ?: $this->configuration['connection']['password'],
      'ssl_mode' => $form_state->getValue(['connection', 'ssl_mode']),
    ];

    try {
      $test_connector = new PostgreSQLConnector($connection_config, $this->logger);
      $test_connector->testConnection();
      
      $form['connection_test_result']['#markup'] = '<div class="messages messages--status">' . $this->t('Connection successful!') . '</div>';
    }
    catch (\Exception $e) {
      $form['connection_test_result']['#markup'] = '<div class="messages messages--error">' . $this->t('Connection failed: @message', ['@message' => $e->getMessage()]) . '</div>';
    }

    return $form['connection_test_result'];
  }

}