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

    // Initialize PostgreSQL-specific services
    try {
      if ($container->has('search_api_postgresql.connector')) {
        $instance->connector = $container->get('search_api_postgresql.connector');
      }
      if ($container->has('search_api_postgresql.embedding')) {
        $instance->embeddingService = $container->get('search_api_postgresql.embedding');
      }
      if ($container->has('search_api_postgresql.vector_search')) {
        $instance->vectorSearchService = $container->get('search_api_postgresql.vector_search');
      }
    } catch (\Exception $e) {
      $instance->logger->error('Failed to initialize PostgreSQL services: @error', ['@error' => $e->getMessage()]);
    }
    
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    
    // Ensure configuration defaults are applied
    $this->configuration = $this->configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // PostgreSQL-specific connection options
      'connection' => [
        'host' => 'localhost',
        'port' => 5432,
        'database' => '',
        'username' => '',
        'password' => '',
        'password_key' => '',
        'ssl_mode' => 'prefer',
        'ssl_ca' => '',
        'options' => [],
      ],
      
      // PostgreSQL-specific indexing options
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      'debug' => FALSE,
      'batch_size' => 100,
      
      // AI Embeddings Configuration (PostgreSQL-specific)
      'ai_embeddings' => [
        'enabled' => FALSE,
        'provider' => 'azure',
        
        // Azure OpenAI Configuration
        'azure' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_name' => '',
          'deployment_name' => '',
          'api_version' => '2024-02-15-preview',
          'model' => 'text-embedding-3-small',
          'dimension' => 1536,
        ],
        
        // OpenAI Configuration
        'openai' => [
          'api_key' => '',
          'api_key_name' => '',
          'model' => 'text-embedding-3-small',
          'organization' => '',
          'base_url' => 'https://api.openai.com/v1',
          'dimension' => 1536,
        ],
        
        // Common settings
        'batch_size' => 25,
        'rate_limit_delay' => 100,
        'max_retries' => 3,
        'timeout' => 30,
        'enable_cache' => TRUE,
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
    // Initialize form array - don't call parent since BackendPluginBase doesn't have this method

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
        'azure' => $this->t('Azure OpenAI Service'),
        'openai' => $this->t('OpenAI API'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['provider'] ?? 'azure',
      '#description' => $this->t('Choose your AI embedding provider.'),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Common AI settings
    $form['ai_embeddings']['common'] = [
      '#type' => 'details',
      '#title' => $this->t('Model Configuration'),
      '#open' => TRUE,
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
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions, faster)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions, better quality)'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions, legacy)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Choose the embedding model. Larger models provide better quality but cost more.'),
    ];

    // Azure OpenAI Configuration
    $form['ai_embeddings']['azure'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Service'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ':input[name="ai_embeddings[provider]"]' => ['value' => 'azure'],
        ],
      ],
    ];

    $form['ai_embeddings']['azure']['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI endpoint URL (e.g., https://your-resource.openai.azure.com/).'),
      '#placeholder' => 'https://your-resource.openai.azure.com/',
    ];

    $form['ai_embeddings']['azure']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure API Key'),
      '#default_value' => '',
      '#description' => $this->t('Your Azure OpenAI API key.'),
    ];

    $form['ai_embeddings']['azure']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['deployment_name'] ?? '',
      '#description' => $this->t('The name of your Azure OpenAI deployment.'),
    ];

    // OpenAI Configuration
    $form['ai_embeddings']['openai'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI API'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
          ':input[name="ai_embeddings[provider]"]' => ['value' => 'openai'],
        ],
      ],
    ];

    $form['ai_embeddings']['openai']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#default_value' => '',
      '#description' => $this->t('Your OpenAI API key.'),
    ];

    $form['ai_embeddings']['openai']['organization'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID (Optional)'),
      '#default_value' => $this->configuration['ai_embeddings']['openai']['organization'] ?? '',
      '#description' => $this->t('Your OpenAI organization ID (optional).'),
    ];

    // Advanced Configuration
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Table Prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
      '#description' => $this->t('Prefix for PostgreSQL search tables.'),
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

    parent::submitConfigurationForm($form, $form_state);
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
    $connection_string = $this->configuration['connection']['host'] . ':' . 
                        $this->configuration['connection']['port'] . '/' . 
                        $this->configuration['connection']['database'];
    $info[] = [
      'label' => $this->t('Database'),
      'info' => $connection_string,
    ];

    // FTS configuration
    $info[] = [
      'label' => $this->t('Text Search Configuration'),
      'info' => $this->configuration['fts_configuration'],
    ];

    // AI embeddings info
    if ($this->configuration['ai_embeddings']['enabled']) {
      $info[] = [
        'label' => $this->t('AI Embeddings'),
        'info' => $this->t('Enabled (@provider)', [
          '@provider' => ucfirst($this->configuration['ai_embeddings']['provider']),
        ]),
      ];
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    try {
      $this->ensureConnector();
      $this->createIndexTables($index);
      
      $this->logger->info('Created index tables for @index', ['@index' => $index->id()]);
      
    } catch (\Exception $e) {
      $this->logger->error('Failed to create index @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to create index: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    try {
      $this->ensureConnector();
      $this->updateIndexSchema($index);
      
      $this->logger->info('Updated index schema for @index', ['@index' => $index->id()]);
      
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
      
      $index_id = $index->id();
      $prefix = $this->configuration['index_prefix'];
      $main_table = $prefix . $index_id;
      
      // Drop main table
      $this->connector->dropTable($main_table);
      
      // Drop field tables
      foreach ($index->getFields() as $field_id => $field) {
        if ($this->needsFieldTable($field)) {
          $field_table = $prefix . $index_id . '_' . $field_id;
          $this->connector->dropTable($field_table);
        }
      }
      
      $this->logger->info('Removed index tables for @index', ['@index' => $index_id]);
      
    } catch (\Exception $e) {
      $this->logger->error('Failed to remove index @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to remove index: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    try {
      $this->ensureConnector();
      
      $indexed_count = 0;
      $batch_size = $this->configuration['batch_size'];
      $item_batches = array_chunk($items, $batch_size, TRUE);
      
      foreach ($item_batches as $batch) {
        $indexed_count += $this->indexBatch($index, $batch);
      }
      
      $this->logger->debug('Indexed @count items for @index', [
        '@count' => $indexed_count,
        '@index' => $index->id(),
      ]);
      
      return array_keys($items);
      
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
    try {
      $this->ensureConnector();
      
      // Delete from traditional search tables
      $this->deleteFromSearchTables($index, $item_ids);
      
      // Delete from vector search if enabled
      if ($this->configuration['ai_embeddings']['enabled'] && $this->vectorSearchService) {
        $this->vectorSearchService->deleteItems($index, $item_ids);
      }

      $this->logger->debug('Deleted @count items from @index', [
        '@count' => count($item_ids),
        '@index' => $index->id(),
      ]);
      
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
      
      // Clear traditional search tables
      $this->clearSearchTables($index, $datasource_id);
      
      // Clear vector search if enabled
      if ($this->configuration['ai_embeddings']['enabled'] && $this->vectorSearchService) {
        $this->vectorSearchService->deleteAllItems($index, $datasource_id);
      }

      $this->logger->info('Cleared all items from index @index', ['@index' => $index->id()]);
      
    } catch (\Exception $e) {
      $this->logger->error('Failed to clear items from @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Failed to clear index: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    try {
      $this->ensureConnector();
      
      $index = $query->getIndex();
      $keys = $query->getKeys();
      $results = $query->getResults();
      
      // Determine search strategy
      $use_ai = $this->configuration['ai_embeddings']['enabled'] && 
                $this->vectorSearchService && 
                !empty($keys);
      
      if ($use_ai && $this->configuration['hybrid_search']['enabled']) {
        // Hybrid search: combine traditional FTS with vector search
        $vector_results = $this->vectorSearchService->search($query);
        $fts_results = $this->performTraditionalSearch($query);
        $combined_results = $this->combineSearchResults($fts_results, $vector_results, $query);
        $this->populateResults($results, $combined_results, $query);
      } 
      elseif ($use_ai) {
        // Vector search only
        $vector_results = $this->vectorSearchService->search($query);
        $this->populateResults($results, $vector_results, $query);
      } 
      else {
        // Traditional full-text search
        $fts_results = $this->performTraditionalSearch($query);
        $this->populateResults($results, $fts_results, $query);
      }

      // Add facets if requested
      if ($query->getOption('search_api_facets')) {
        $facets = $this->getFacets($query);
        $results->setExtraData('search_api_facets', $facets);
      }

      $this->logger->debug('Search completed for index @index with @count results', [
        '@index' => $index->id(),
        '@count' => $results->getResultCount(),
      ]);

      return $results;
      
    } catch (\Exception $e) {
      $this->logger->error('Search failed for @index: @error', [
        '@index' => $query->getIndex()->id(),
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
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state) {
    try {
      $values = $form_state->getValues();
      $connection_config = $values['connection'] ?? $this->configuration['connection'];
      
      // Test the connection
      $test_connector = new PostgreSQLConnector($connection_config, $this->logger);
      $result = $test_connector->testConnection();
      
      if ($result['success']) {
        $message = '<div class="messages messages--status">' . 
                   $this->t('Connection successful! Database: @db, Version: @version', [
                     '@db' => $result['database'] ?? 'Unknown',
                     '@version' => $result['version'] ?? 'Unknown',
                   ]) . '</div>';
      } else {
        $message = '<div class="messages messages--error">' . 
                   $this->t('Connection failed: @error', ['@error' => $result['error']]) . 
                   '</div>';
      }
      
    } catch (\Exception $e) {
      $message = '<div class="messages messages--error">' . 
                 $this->t('Connection test failed: @error', ['@error' => $e->getMessage()]) . 
                 '</div>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $message,
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
    $index_id = $index->id();
    $prefix = $this->configuration['index_prefix'];
    
    // Create main search table
    $main_table = $prefix . $index_id;
    $this->connector->createSearchTable($main_table, $index);
    
    // Create field-specific tables for complex fields
    foreach ($index->getFields() as $field_id => $field) {
      if ($this->needsFieldTable($field)) {
        $field_table = $prefix . $index_id . '_' . $field_id;
        $this->connector->createFieldTable($field_table, $field);
      }
    }
    
    // Create full-text search indexes
    $this->connector->createFullTextIndexes($main_table, $index, $this->configuration['fts_configuration']);
  }

  /**
   * Updates the schema for an existing index.
   */
  protected function updateIndexSchema(IndexInterface $index) {
    $index_id = $index->id();
    $prefix = $this->configuration['index_prefix'];
    $main_table = $prefix . $index_id;
    
    // Update table schema as needed
    $this->connector->updateTableSchema($main_table, $index);
  }

  /**
   * Indexes a batch of items.
   */
  protected function indexBatch(IndexInterface $index, array $items) {
    $indexed_count = 0;
    
    foreach ($items as $item_id => $item) {
      try {
        $this->indexSingleItem($index, $item);
        $indexed_count++;
      } catch (\Exception $e) {
        $this->logger->error('Failed to index item @item: @error', [
          '@item' => $item_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }
    
    return $indexed_count;
  }

  /**
   * Indexes a single item.
   */
  protected function indexSingleItem(IndexInterface $index, ItemInterface $item) {
    $fields = $item->getFields(TRUE);
    $processed_fields = [];
    
    // Process each field for indexing
    foreach ($fields as $field_id => $field) {
      $processed_fields[$field_id] = $this->processFieldForIndexing($field);
    }
    
    // Store in traditional search tables
    $this->storeInSearchTables($index, $item, $processed_fields);
    
    // Generate and store embeddings if AI is enabled
    if ($this->configuration['ai_embeddings']['enabled'] && $this->embeddingService) {
      $text_content = $this->extractTextContent($item);
      if (!empty($text_content)) {
        $this->embeddingService->generateAndStoreEmbedding($index, $item->getId(), $text_content);
      }
    }
  }

  /**
   * Performs traditional PostgreSQL full-text search.
   */
  protected function performTraditionalSearch(QueryInterface $query) {
    $index = $query->getIndex();
    $index_id = $index->id();
    $prefix = $this->configuration['index_prefix'];
    $main_table = $prefix . $index_id;
    
    return $this->buildSearchQuery($query, $main_table);
  }

  /**
   * Combines traditional and vector search results.
   */
  protected function combineSearchResults($fts_results, $vector_results, QueryInterface $query) {
    $text_weight = $this->configuration['hybrid_search']['text_weight'];
    $vector_weight = $this->configuration['hybrid_search']['vector_weight'];
    
    // Implement hybrid search result combination logic
    return $this->weightAndCombineResults($fts_results, $vector_results, $text_weight, $vector_weight);
  }

  /**
   * Populates search results.
   */
  protected function populateResults($results, $search_results, QueryInterface $query) {
    // Implement result population logic
    foreach ($search_results as $result) {
      $results->addResultItem($result);
    }
  }

  /**
   * Gets facets for a query.
   */
  protected function getFacets(QueryInterface $query) {
    // Implement facet generation
    return [];
  }

  /**
   * Determines if a field needs its own table.
   */
  protected function needsFieldTable($field) {
    // Multi-valued fields or complex data types need separate tables
    return $field->getType() === 'text' || count($field->getValues()) > 1;
  }

  /**
   * Processes a field for indexing.
   */
  protected function processFieldForIndexing($field) {
    $values = $field->getValues();
    $type = $field->getType();
    
    switch ($type) {
      case 'text':
        return $this->processTextFieldForIndexing($values);
      case 'string':
      case 'uri':
        return count($values) === 1 ? reset($values) : $values;
      case 'integer':
      case 'date':
        return count($values) === 1 ? (int) reset($values) : array_map('intval', $values);
      case 'decimal':
        return count($values) === 1 ? (float) reset($values) : array_map('floatval', $values);
      case 'boolean':
        return count($values) === 1 ? (bool) reset($values) : array_map('boolval', $values);
      default:
        return $values;
    }
  }

  /**
   * Processes text field values for indexing.
   */
  protected function processTextFieldForIndexing($values) {
    $processed = [];
    foreach ($values as $value) {
      if (is_string($value)) {
        $processed[] = $value;
      }
    }
    return $processed;
  }

  /**
   * Extracts text content from an item for embedding generation.
   */
  protected function extractTextContent(ItemInterface $item) {
    $text_parts = [];
    
    foreach ($item->getFields() as $field) {
      if ($field->getType() === 'text') {
        foreach ($field->getValues() as $value) {
          if (is_string($value)) {
            $text_parts[] = $value;
          }
        }
      }
    }
    
    return implode(' ', $text_parts);
  }

  /**
   * Stores processed fields in search tables.
   */
  protected function storeInSearchTables(IndexInterface $index, ItemInterface $item, array $processed_fields) {
    $index_id = $index->id();
    $prefix = $this->configuration['index_prefix'];
    $main_table = $prefix . $index_id;
    
    $this->connector->insertItem($main_table, $item->getId(), $processed_fields);
  }

  /**
   * Builds the search query for traditional search.
   */
  protected function buildSearchQuery(QueryInterface $query, $main_table) {
    // This would build a complex PostgreSQL query
    // Including full-text search, filters, sorting, etc.
    return $this->connector->buildSearchQuery($query, $main_table, $this->configuration);
  }

  /**
   * Weights and combines search results.
   */
  protected function weightAndCombineResults($fts_results, $vector_results, $text_weight, $vector_weight) {
    // Implement result combination logic
    return array_merge($fts_results, $vector_results);
  }

  /**
   * Deletes items from search tables.
   */
  protected function deleteFromSearchTables(IndexInterface $index, array $item_ids) {
    $index_id = $index->id();
    $prefix = $this->configuration['index_prefix'];
    $main_table = $prefix . $index_id;
    
    $this->connector->deleteItems($main_table, $item_ids);
    
    // Delete from field tables
    foreach ($index->getFields() as $field_id => $field) {
      if ($this->needsFieldTable($field)) {
        $field_table = $prefix . $index_id . '_' . $field_id;
        $this->connector->deleteItems($field_table, $item_ids);
      }
    }
  }

  /**
   * Clears all items from search tables.
   */
  protected function clearSearchTables(IndexInterface $index, $datasource_id = NULL) {
    $index_id = $index->id();
    $prefix = $this->configuration['index_prefix'];
    $main_table = $prefix . $index_id;
    
    if ($datasource_id) {
      $this->connector->deleteByDatasource($main_table, $datasource_id);
    } else {
      $this->connector->truncateTable($main_table);
    }
    
    // Clear field tables
    foreach ($index->getFields() as $field_id => $field) {
      if ($this->needsFieldTable($field)) {
        $field_table = $prefix . $index_id . '_' . $field_id;
        if ($datasource_id) {
          $this->connector->deleteByDatasource($field_table, $datasource_id);
        } else {
          $this->connector->truncateTable($field_table);
        }
      }
    }
  }

}