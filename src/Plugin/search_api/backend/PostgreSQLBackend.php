<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api_postgresql\Traits\SecurityManagementTrait;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\search_api_postgresql\Service\EmbeddingService;
use Drupal\search_api_postgresql\Service\VectorSearchService;
use Drupal\search_api_postgresql\PostgreSQL\FieldMapper;
use Drupal\search_api_postgresql\PostgreSQL\IndexManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL search backend with optional AI vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL Database"),
 *   description = @Translation("PostgreSQL backend supporting standard database search with optional AI embedding providers for semantic search.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements ContainerFactoryPluginInterface, PluginFormInterface {

  use SecurityManagementTrait;
  use PluginFormTrait;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The PostgreSQL connector.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingService
   */
  protected $embeddingService;

  /**
   * The vector search service.
   *
   * @var \Drupal\search_api_postgresql\Service\VectorSearchService
   */
  protected $vectorSearchService;

  /**
   * The index manager.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\IndexManager
   */
  protected $indexManager;

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
      $instance->logger->info('Key module not available, using direct credential entry only.');
    }
    
    // Initialize services
    try {
      if ($container->has('search_api_postgresql.embedding_service')) {
        $instance->embeddingService = $container->get('search_api_postgresql.embedding_service');
      }
      if ($container->has('search_api_postgresql.vector_search_service')) {
        $instance->vectorSearchService = $container->get('search_api_postgresql.vector_search_service');
      }
    } catch (\Exception $e) {
      $instance->logger->notice('Optional services not available: @error', ['@error' => $e->getMessage()]);
    }
    
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // Database Connection Configuration
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
      
      // Index Configuration
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      
      // AI Embeddings Configuration
      'ai_embeddings' => [
        'enabled' => FALSE,
        'provider' => 'azure',
        'azure' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_key' => '',
          'deployment_name' => '',
          'model' => 'text-embedding-3-small',
          'dimension' => 1536,
          'api_version' => '2024-02-01',
        ],
        'openai' => [
          'api_key' => '',
          'api_key_key' => '',
          'model' => 'text-embedding-3-small',
          'dimension' => 1536,
          'organization' => '',
        ],
        'cache' => TRUE,
        'cache_ttl' => 3600,
      ],
      
      // Vector Index Configuration
      'vector_index' => [
        'method' => 'ivfflat',
        'lists' => 100,
        'probes' => 10,
        'distance' => 'cosine',
      ],
      
      // Hybrid Search Configuration
      'hybrid_search' => [
        'enabled' => TRUE,
        'text_weight' => 0.6,
        'vector_weight' => 0.4,
        'similarity_threshold' => 0.15,
        'max_results' => 1000,
      ],
      
      // Performance Configuration
      'performance' => [
        'connection_pool_size' => 10,
        'statement_timeout' => 30000,
        'work_mem' => '256MB',
        'effective_cache_size' => '2GB',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Database Connection Configuration
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Database Connection'),
      '#open' => TRUE,
      '#description' => $this->t('Configure PostgreSQL database connection. Password is optional for passwordless authentication methods.'),
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
      '#description' => $this->t('Database server port.'),
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Database name.'),
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
      '#title' => $this->t('Password (Optional)'),
      '#default_value' => '',
      '#description' => $this->t('Database password. Leave empty for passwordless authentication or if using Key module.'),
    ];

    // Add key fields if Key module is available
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
      '#default_value' => $this->configuration['connection']['ssl_mode'] ?? 'prefer',
      '#description' => $this->t('SSL connection mode. Use "require" for Azure Database for PostgreSQL.'),
    ];

    // PostgreSQL Full-Text Search Configuration
    $form['fts'] = [
      '#type' => 'details',
      '#title' => $this->t('Full-Text Search'),
      '#open' => FALSE,
    ];

    $form['fts']['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Text Search Configuration'),
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
      '#description' => $this->t('PostgreSQL text search configuration for language-specific processing.'),
    ];

    // AI Embeddings Configuration
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings (Advanced)'),
      '#description' => $this->t('Configure AI-powered semantic search capabilities using vector embeddings.'),
      '#open' => FALSE,
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable AI-powered semantic search using vector embeddings.'),
    ];

    $form['ai_embeddings']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#options' => [
        'azure' => $this->t('Azure OpenAI'),
        'openai' => $this->t('OpenAI'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['provider'] ?? 'azure',
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Common model configuration
    $form['ai_embeddings']['common'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Model Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['common']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Choose the embedding model. Larger models may provide better accuracy but cost more.'),
    ];

    // Azure OpenAI Configuration
    $form['ai_embeddings']['azure'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure OpenAI Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ':input[name="ai_embeddings[provider]"]' => ['value' => 'azure'],
        ],
      ],
    ];

    $form['ai_embeddings']['azure']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint URL.'),
      '#placeholder' => 'https://your-resource.openai.azure.com/',
    ];

    $form['ai_embeddings']['azure']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#default_value' => '',
      '#description' => $this->t('Azure OpenAI API key. Leave empty to keep existing key.'),
    ];

    $form['ai_embeddings']['azure']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['deployment_name'] ?? '',
      '#description' => $this->t('Name of your embedding model deployment in Azure.'),
    ];

    // OpenAI Configuration
    $form['ai_embeddings']['openai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OpenAI Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ':input[name="ai_embeddings[provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['ai_embeddings']['openai']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#default_value' => '',
      '#description' => $this->t('OpenAI API key. Leave empty to keep existing key.'),
    ];

    $form['ai_embeddings']['openai']['organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID (Optional)'),
      '#default_value' => $this->configuration['ai_embeddings']['openai']['organization'] ?? '',
      '#description' => $this->t('OpenAI organization ID if required.'),
    ];

    $form['connection']['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => [$this, 'testConnectionAjax'],
        'wrapper' => 'connection-test-result',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Testing connection...'),
        ],
      ],
      '#limit_validation_errors' => [], // Prevents form validation
      '#suffix' => '<div class="test-connection-help">Click to verify database connectivity.</div>',
    ];

    $form['connection']['test_result'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'connection-test-result',
        'class' => ['connection-test-wrapper'],
      ],
      // Always include some content so the div renders
      '#markup' => '<div class="test-result-placeholder" style="min-height: 1px;"></div>',
    ];

    // Advanced Configuration
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
      '#description' => $this->t('Prefix for database table names. Use underscore suffix for clean separation.'),
    ];

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#default_value' => $this->configuration['debug'] ?? FALSE,
      '#description' => $this->t('Log all database queries for debugging.'),
    ];

    $form['advanced']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Indexing Batch Size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to process in each indexing batch.'),
    ];

    // Add the required libraries
    $form['#attached']['library'][] = 'core/drupal.ajax';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Store the current password if provided
    if (!empty($values['connection']['password'])) {
      $this->configuration['connection']['password'] = $values['connection']['password'];
    }

    // Store Azure AI key if provided
    if (!empty($values['ai_embeddings']['azure']['api_key'])) {
      $this->configuration['ai_embeddings']['azure']['api_key'] = $values['ai_embeddings']['azure']['api_key'];
    }

    // Store OpenAI key if provided
    if (!empty($values['ai_embeddings']['openai']['api_key'])) {
      $this->configuration['ai_embeddings']['openai']['api_key'] = $values['ai_embeddings']['openai']['api_key'];
    }

    // Apply model setting to both providers
    if (!empty($values['ai_embeddings']['common']['model'])) {
      $this->configuration['ai_embeddings']['azure']['model'] = $values['ai_embeddings']['common']['model'];
      $this->configuration['ai_embeddings']['openai']['model'] = $values['ai_embeddings']['common']['model'];
      
      // Set dimensions based on model
      $dimensions = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
      ];
      
      $model = $values['ai_embeddings']['common']['model'];
      if (isset($dimensions[$model])) {
        $this->configuration['ai_embeddings']['azure']['dimension'] = $dimensions[$model];
        $this->configuration['ai_embeddings']['openai']['dimension'] = $dimensions[$model];
      }
    }

    // Process other form values
    foreach ($values as $key => $value) {
      if ($key !== 'connection' || $key !== 'ai_embeddings') {
        $this->configuration[$key] = $value;
      }
    }

    // Process connection values (except passwords which are handled above)
    foreach ($values['connection'] as $key => $value) {
      if ($key !== 'password' && $key !== 'test_connection' && $key !== 'test_result') {
        $this->configuration['connection'][$key] = $value;
      }
    }

    // Process AI embeddings values (except API keys which are handled above)
    if (isset($values['ai_embeddings'])) {
      foreach ($values['ai_embeddings'] as $key => $value) {
        if ($key === 'azure') {
          foreach ($value as $azure_key => $azure_value) {
            if ($azure_key !== 'api_key') {
              $this->configuration['ai_embeddings']['azure'][$azure_key] = $azure_value;
            }
          }
        } elseif ($key === 'openai') {
          foreach ($value as $openai_key => $openai_value) {
            if ($openai_key !== 'api_key') {
              $this->configuration['ai_embeddings']['openai'][$openai_key] = $openai_value;
            }
          }
        } elseif ($key !== 'common') {
          $this->configuration['ai_embeddings'][$key] = $value;
        }
      }
    }

    // Clear the connector to force reinitialization with new settings
    $this->connector = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_autocomplete',          // Standard Search API feature
      'search_api_facets',               // Standard Search API feature
      'search_api_facets_operator_or',   // Standard Search API feature
      'search_api_random_sort',          // Standard Search API feature
      'search_api_grouping',             // PostgreSQL can support this
      'search_api_mlt',                  // With AI embeddings (More Like This)
      'search_api_spellcheck',           // With AI embeddings
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = [];
    
    // Database connection info
    $connection_string = $this->configuration['connection']['host'] . ':' . $this->configuration['connection']['port'];
    $info[] = [
      'label' => $this->t('Database'),
      'info' => $this->configuration['connection']['database'] . ' @ ' . $connection_string,
    ];
    
    // SSL mode
    $info[] = [
      'label' => $this->t('SSL Mode'),
      'info' => $this->configuration['connection']['ssl_mode'] ?? 'prefer',
    ];
    
    // Full-text search configuration
    $info[] = [
      'label' => $this->t('FTS Configuration'),
      'info' => $this->configuration['fts_configuration'] ?? 'english',
    ];
    
    // AI embeddings status
    if (!empty($this->configuration['ai_embeddings']['enabled'])) {
      $provider = $this->configuration['ai_embeddings']['provider'] ?? 'azure';
      $model = $this->configuration['ai_embeddings'][$provider]['model'] ?? 'text-embedding-3-small';
      $info[] = [
        'label' => $this->t('AI Embeddings'),
        'info' => $this->t('Enabled (@provider, @model)', [
          '@provider' => ucfirst($provider),
          '@model' => $model,
        ]),
      ];
    } else {
      $info[] = [
        'label' => $this->t('AI Embeddings'),
        'info' => $this->t('Disabled'),
      ];
    }
    
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    try {
      $this->ensureConnector();
      $result = $this->connector->testConnection();
      return is_array($result) && !empty($result['success']);
    } catch (\Exception $e) {
      $this->logger->error('PostgreSQL backend availability check failed: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Helper method to get table name using IndexManager's logic.
   * This ensures consistency with IndexManager's table name construction.
   */
  protected function getIndexTableNameForManager(IndexInterface $index) {
    $index_id = $index->id();
    
    // Use the same validation as IndexManager
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $index_id)) {
      throw new \InvalidArgumentException("Invalid index ID: {$index_id}");
    }
    
    // Use the same prefix logic as IndexManager
    $prefix = $this->configuration['index_prefix'] ?? 'search_api_';
    $table_name_unquoted = $prefix . $index_id;
    
    // Use connector's quoting method
    return $this->connector->quoteTableName($table_name_unquoted);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    try {
      $this->ensureConnector();
      $this->createIndexTables($index);
      $this->logger->info('Successfully created tables for index @index', ['@index' => $index->id()]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to add index @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to add index: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    try {
      $this->ensureConnector();
      $this->updateIndexSchema($index);
      $this->logger->info('Successfully updated schema for index @index', ['@index' => $index->id()]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to update index @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to update index: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    try {
      $this->ensureConnector();
      $index_id = is_string($index) ? $index : $index->id();
      $this->dropIndexTables($index_id);
      $this->logger->info('Successfully removed tables for index @index', ['@index' => $index_id]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to remove index @index: @error', [
        '@index' => is_string($index) ? $index : $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to remove index: ' . $e->getMessage(), 0, $e);
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
      $this->ensureConnector();
      $indexed_items = [];
      
      foreach ($items as $item) {
        if ($this->indexItem($index, $item)) {
          $indexed_items[] = $item->getId();
        }
      }
      
      return $indexed_items;
    } catch (\Exception $e) {
      $this->logger->error('Failed to index items for @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to index items: ' . $e->getMessage(), 0, $e);
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
      $this->ensureConnector();
      $indexManager = $this->getIndexManager();
      
      // Get table name using the same logic as IndexManager
      $table_name = $this->getIndexTableNameForManager($index);
      $indexManager->deleteItems($table_name, $item_ids);
    } catch (\Exception $e) {
      $this->logger->error('Failed to delete items from @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to delete items: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    try {
      $this->ensureConnector();
      // Use IndexManager for clearing items instead of connector directly
      $indexManager = $this->getIndexManager();
      $indexManager->clearIndex($index, $datasource_id);
    } catch (\Exception $e) {
      $this->logger->error('Failed to delete all items from @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to delete all items: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    try {
      $this->ensureConnector();
      return $this->connector->search($query);
    } catch (\Exception $e) {
      $this->logger->error('Search failed for query @query: @error', [
        '@query' => $query->getOriginalKeys(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Search failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Returns the module handler to use for this plugin.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  public function getModuleHandler() {
    return $this->moduleHandler ?: \Drupal::moduleHandler();
  }

  /**
   * AJAX callback for testing database connection.
   * Updated for Drupal 11 with proper translation and improved user feedback.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state) {
    try {
      $values = $form_state->getValues();
      $connection_config = $values['backend_config']['connection'] ?? $this->configuration['connection'];
      $test_connector = new PostgreSQLConnector($connection_config, $this->logger);
      $result = $test_connector->testConnection();
      
      if ($result['success']) {
        $message = $this->t('Connection successful! Database: @db, Version: @version', [
          '@db' => $result['database'] ?? 'Unknown',
          '@version' => $result['version'] ?? 'Unknown',
        ]);
        $message_type = 'status';
      } else {
        $message = $this->t('Connection failed: @error', ['@error' => $result['error']]);
        $message_type = 'error';
      }
      
    } catch (\Exception $e) {
      $message = $this->t('Connection test failed: @error', ['@error' => $e->getMessage()]);
      $message_type = 'error';
    }

    // Debug logging
    $this->logger->info('AJAX Connection Test: @message', ['@message' => $message]);

    // Return the exact element that should replace the wrapper
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'connection-test-result',
        'class' => ['connection-test-wrapper']
      ],
      'message' => [
        '#theme' => 'status_messages',
        '#message_list' => [
          $message_type => [$message],
        ],
      ],
    ];
  }

  /**
   * Ensures the PostgreSQL connector is initialized.
   */
  protected function ensureConnector() {
    if (!$this->connector) {
      $this->connector = new PostgreSQLConnector($this->configuration['connection'], $this->logger);
    }
  }

  /**
   * Creates the necessary database tables for an index.
   */
  protected function createIndexTables(IndexInterface $index) {
    // Use the IndexManager to create the index
    $this->getIndexManager()->createIndex($index);
  }

  /**
   * Updates the schema for an existing index.
   */
  protected function updateIndexSchema(IndexInterface $index) {
    // Use the IndexManager to update the index
    $this->getIndexManager()->updateIndex($index);
  }

  /**
   * Drops all tables associated with an index.
   */
  protected function dropIndexTables($index_id) {
    // Use the IndexManager to drop the index
    $this->getIndexManager()->dropIndex($index_id);
  }

  /**
   * Indexes a single item.
   * 
   * Updated to use IndexManager's table name construction method.
   */
  protected function indexItem(IndexInterface $index, ItemInterface $item) {
    try {
      $indexManager = $this->getIndexManager();
      
      // Get table name using the same logic as IndexManager
      $table_name = $this->getIndexTableNameForManager($index);
      $indexManager->indexItem($table_name, $index, $item);
      
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to index item @item: @error', [
        '@item' => $item->getId(),
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Determines if a field needs its own table.
   */
  protected function needsFieldTable($field) {
    // Complex multi-value fields might need separate tables
    return $field->getType() === 'string' && ($field->getConfiguration()['multi_value'] ?? FALSE);
  }

  /**
   * Gets or creates an IndexManager instance.
   * 
   * Updated to ensure consistent configuration passing.
   */
  protected function getIndexManager() {
    if (!$this->indexManager) {
      // Ensure we have a connector
      $this->ensureConnector();
      
      // Create the field mapper
      $fieldMapper = new FieldMapper($this->configuration);
      $embedding_service = null;
      
      // Initialize embedding service if vector search is enabled
      if (!empty($this->configuration['ai_embeddings']['enabled'])) {
        try {
          $embedding_service = \Drupal::service('search_api_postgresql.embedding_service');
        } catch (\Exception $e) {
          $this->logger->warning('Embedding service not available: @error', ['@error' => $e->getMessage()]);
        }
      }
      
      // Pass the complete configuration to IndexManager
      $this->indexManager = new IndexManager(
        $this->connector,
        $fieldMapper,
        $this->configuration, // Pass full configuration, not just connection config
        $embedding_service
      );
    }
    
    return $this->indexManager;
  }
}