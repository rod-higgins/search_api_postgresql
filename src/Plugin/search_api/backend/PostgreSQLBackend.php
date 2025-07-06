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
use Drupal\search_api_postgresql\PostgreSQL\EnhancedIndexManager;
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
   * Cached results for support checks.
   *
   * @var array
   */
  protected static $supportCache = [];

  /**
   * Array of warnings that occurred during the query.
   *
   * @var array
   */
  protected $warnings = [];

  /**
   * Array of ignored search keys.
   *
   * @var array  
   */
  protected $ignored = [];

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
  public function supportsDataType($type) {
    $supported_types = [
      'text',
      'string',
      'integer',
      'decimal',
      'date',
      'boolean',
    ];
    
    // Add PostgreSQL-specific data types with caching
    if ($this->isPostgreSQLSupportedCached()) {
      $supported_types[] = 'postgresql_fulltext';
    }
    
    // Add vector type if pgvector is available with caching
    if ($this->isVectorSupportedCached()) {
      $supported_types[] = 'vector';
    }
    
    return in_array($type, $supported_types);
  }

  /**
   * Cached version of isPostgreSQLSupported().
   */
  protected function isPostgreSQLSupportedCached() {
    $cache_key = 'postgresql_supported_' . md5(serialize($this->configuration['connection']));
    
    if (!isset(self::$supportCache[$cache_key])) {
      try {
        $this->ensureConnector();
        $pdo = $this->connector->connect();
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
        $stmt = $pdo->query("SELECT to_tsvector('english', 'test')");
        self::$supportCache[$cache_key] = TRUE;
      } catch (\Exception $e) {
        $this->logger->debug('PostgreSQL FTS check failed: @error', ['@error' => $e->getMessage()]);
        self::$supportCache[$cache_key] = FALSE;
      }
    }
    
    return self::$supportCache[$cache_key];
  }

  /**
   * Cached version of isVectorSupported().
   */
  protected function isVectorSupportedCached() {
    $cache_key = 'vector_supported_' . md5(serialize($this->configuration['connection']));
    
    if (!isset(self::$supportCache[$cache_key])) {
      try {
        $this->ensureConnector();
        $pdo = $this->connector->connect();
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
        $stmt = $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')");
        self::$supportCache[$cache_key] = (bool) $stmt->fetchColumn();
      } catch (\Exception $e) {
        $this->logger->debug('Vector support check failed: @error', ['@error' => $e->getMessage()]);
        self::$supportCache[$cache_key] = FALSE;
      }
    }
    
    return self::$supportCache[$cache_key];
  }

  /**
   * Check if PostgreSQL full-text search is supported.
   */
  protected function isPostgreSQLSupported() {
    try {
      $this->ensureConnector();
      // Use the existing validation logic from PostgreSQLFulltext::validatePostgreSQLSupport()
      $pdo = $this->connector->connect();
      $stmt = $pdo->query("SELECT to_tsvector('english', 'test')");
      return TRUE;
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Check if vector search is supported.
   */
  protected function isVectorSupported() {
    try {
      $this->ensureConnector();
      $pdo = $this->connector->connect();
      $stmt = $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')");
      return (bool) $stmt->fetchColumn();
    } catch (\Exception $e) {
      return FALSE;
    }
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
    // Add timing debug
    $start_time = microtime(true);
    $this->logger->info('viewSettings() called - starting');
    
    $info = [];
    
    // Database connection info
    $connection_string = $this->configuration['connection']['host'] . ':' . $this->configuration['connection']['port'];
    $info[] = [
      'label' => $this->t('Database'),
      'info' => $this->configuration['connection']['database'] . ' @ ' . $connection_string,
    ];
    
    $this->logger->info('viewSettings() - connection info added');
    
    // SSL mode
    $info[] = [
      'label' => $this->t('SSL Mode'),
      'info' => $this->configuration['connection']['ssl_mode'] ?? 'prefer',
    ];
    
    $this->logger->info('viewSettings() - SSL mode added');
    
    // Full-text search configuration
    $info[] = [
      'label' => $this->t('FTS Configuration'),
      'info' => $this->configuration['fts_configuration'] ?? 'english',
    ];
    
    $this->logger->info('viewSettings() - FTS config added');
    
    // Simple item count
    try {
      $this->ensureConnector();
      $sql = "SELECT COUNT(*) FROM \"search_api_search_index\"";
      $stmt = $this->connector->executeQuery($sql);
      $count = $stmt->fetchColumn();
      
      $info[] = [
        'label' => $this->t('Indexed Items'),
        'info' => number_format($count),
      ];
      
      $this->logger->info('viewSettings() - item count added: @count', ['@count' => $count]);
    } catch (\Exception $e) {
      $info[] = [
        'label' => $this->t('Indexed Items'),
        'info' => $this->t('Error loading count'),
      ];
      $this->logger->error('viewSettings() - count failed: @error', ['@error' => $e->getMessage()]);
    }
    
    $elapsed = microtime(true) - $start_time;
    $this->logger->info('viewSettings() completed in @seconds seconds', ['@seconds' => round($elapsed, 2)]);
    
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
    // Initialize warnings/ignored arrays like search_api_db does
    $this->ignored = $this->warnings = [];
    
    try {
      $this->ensureConnector();
      
      // Debug logging
      $this->logger->debug('Starting search for index: @index', ['@index' => $query->getIndex()->id()]);
      
      // Follow search_api_db pattern exactly
      $index = $query->getIndex();
      
      // Get field information (like search_api_db does)
      $fields = $this->getFieldInfo($index);
      $fields['search_api_id'] = [
        'column' => 'search_api_id',  // Our PostgreSQL table uses search_api_id
      ];

      $this->logger->debug('Fields: @fields', ['@fields' => print_r($fields, TRUE)]);

      // Create database query (like search_api_db does)
      $db_query = $this->createDbQuery($query, $fields);
      
      if (!$db_query) {
        throw new SearchApiException('Failed to create database query');
      }
      
      // Get results object (like search_api_db)
      $results = $query->getResults();

      // Handle result count first (exactly like search_api_db)
      $skip_count = $query->getOption('skip result count');
      $count = NULL;
      
      if (!$skip_count) {
        try {
          $this->logger->debug('Getting result count...');
          $count_query = $db_query->countQuery();
          $count = $count_query->execute()->fetchField();
          $this->logger->debug('Result count: @count', ['@count' => $count]);
          $results->setResultCount($count);
        } catch (\Exception $e) {
          $this->logger->warning('Count query failed: @message', [
            '@message' => $e->getMessage(),
          ]);
          // Continue without count - don't fail completely
        }
      }
      
      // Everything else can be skipped if the count is 0 (exactly like search_api_db)
      if ($skip_count || $count) {
        // Apply pagination (like search_api_db)
        $query_options = $query->getOptions();
        if (isset($query_options['offset']) || isset($query_options['limit'])) {
          $offset = $query_options['offset'] ?? 0;
          $limit = $query_options['limit'] ?? 1000000;
          $this->logger->debug('Applying pagination: offset=@offset, limit=@limit', [
            '@offset' => $offset,
            '@limit' => $limit
          ]);
          $db_query->range($offset, $limit);
        }

        // Apply sorting
        $this->setQuerySort($query, $db_query, $fields);

        // Execute query and process results
        $this->logger->debug('Executing main query...');
        $result = $db_query->execute();
        
        if (!$result) {
          throw new SearchApiException('Query execution returned no results object');
        }

        $indexed_fields = $index->getFields(TRUE);
        $retrieved_field_names = $query->getOption('search_api_retrieved_field_values', []);
        
        $item_count = 0;
        foreach ($result as $row) {
          $item_count++;
          
          if (!$row || !isset($row->item_id)) {
            $this->logger->warning('Invalid result row @count: @row', [
              '@count' => $item_count,
              '@row' => print_r($row, TRUE)
            ]);
            continue;
          }
          
          $item = $this->getFieldsHelper()->createItem($index, $row->item_id);
          $item->setScore($row->score / 1000); // Divide by 1000 like search_api_db
          $this->extractRetrievedFieldValuesWhereAvailable($row, $indexed_fields, $retrieved_field_names, $item);
          $results->addResultItem($item);
        }
        
        $this->logger->debug('Processed @count result items', ['@count' => $item_count]);
        
        if ($skip_count && !empty($item)) {
          $results->setResultCount(1);
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to execute search on @index: @error', [
        '@index' => $index->id() ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      
      // Log the full stack trace for debugging
      $this->logger->error('Stack trace: @trace', ['@trace' => $e->getTraceAsString()]);
      
      throw new SearchApiException('Search failed: ' . $e->getMessage(), 0, $e);
    }

    // Add additional warnings and ignored keys (like search_api_db)
    $metadata = [
      'warnings' => 'addWarning',
      'ignored' => 'addIgnoredSearchKey',
    ];
    foreach ($metadata as $property => $method) {
      foreach (array_keys($this->$property) as $value) {
        $results->$method($value);
      }
    }
    
    return $results;
  }

  /**
   * Sets the query sort (adapted from search_api_db).
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   * @param object $db_query
   *   The database query object.
   * @param array $fields
   *   Field information.
   */
  protected function setQuerySort(QueryInterface $query, $db_query, array $fields) {
    $sort = $query->getSorts();
    if (!$sort) {
      // Default sort by relevance for searches with keys
      if ($query->getKeys()) {
        $db_query->orderBy('search_api_relevance', 'DESC');
      }
      return;
    }

    foreach ($sort as $field_id => $direction) {
      $direction = strtoupper($direction);
      
      if ($field_id === 'search_api_relevance') {
        $db_query->orderBy('search_api_relevance', $direction);
      } elseif ($field_id === 'search_api_id') {
        $db_query->orderBy('search_api_id', $direction);
      } elseif (isset($fields[$field_id])) {
        $db_query->orderBy($field_id, $direction);
      }
    }
  }

  /**
   * Extracts retrieved field values where available (adapted from search_api_db).
   */
  public function extractRetrievedFieldValuesWhereAvailable($result_row, array $indexed_fields, array $retrieved_fields, ItemInterface $item) {
    foreach ($retrieved_fields as $retrieved_field_name) {
      $retrieved_field_value = $result_row->{$retrieved_field_name} ?? NULL;
      if (!isset($retrieved_field_value)) {
        continue;
      }

      if (!array_key_exists($retrieved_field_name, $indexed_fields)) {
        continue;
      }
      $retrieved_field = clone $indexed_fields[$retrieved_field_name];

      $retrieved_field->addValue($retrieved_field_value);
      $item->setField($retrieved_field_name, $retrieved_field);
    }
  }

  /**
   * Get the fields helper service.
   *
   * @return \Drupal\search_api\Utility\FieldsHelperInterface
   *   The fields helper.
   */
  public function getFieldsHelper() {
    return \Drupal::service('search_api.fields_helper');
  }

  /**
   * Process field value based on field type.
   *
   * @param mixed $value
   *   The raw field value from database.
   * @param string $field_type
   *   The Search API field type.
   *
   * @return mixed
   *   The processed field value.
   */
  protected function processFieldValue($value, $field_type) {
    if ($value === NULL) {
      return NULL;
    }

    switch ($field_type) {
      case 'boolean':
        return (bool) $value;

      case 'date':
        // Convert timestamp to ISO date string
        return is_numeric($value) ? date('c', $value) : $value;

      case 'decimal':
        return (float) $value;

      case 'integer':
        return (int) $value;

      case 'text':
      case 'string':
      default:
        return (string) $value;
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
   * Gets field information for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Field information array.
   */
  protected function getFieldInfo(IndexInterface $index) {
    $fields = [];
    
    foreach ($index->getFields() as $field_id => $field) {
      $fields[$field_id] = [
        'column' => $field_id,
        'type' => $field->getType(),
        'boost' => $field->getBoost(),
      ];
    }
    
    return $fields;
  }

  /**
 * Creates a database query for the given Search API query (like search_api_db).
 *
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   The Search API query.
 * @param array $fields
 *   Field information.
 *
 * @return object
 *   A query result object that mimics Drupal's SelectInterface.
 */
protected function createDbQuery(QueryInterface $query, array $fields) {
  $index = $query->getIndex();
  $table_name = $this->getIndexTableNameForManager($index);
  
  // Debug logging
  $this->logger->debug('Creating query for table: @table', ['@table' => $table_name]);
  
  // Build SQL parts
  $select_fields = ['search_api_id'];
  foreach ($fields as $field_id => $field_info) {
    if ($field_id !== 'search_api_id') {
      $select_fields[] = $field_id;
    }
  }
  
  // Get and validate keys (like search_api_db does)
  $keys = $query->getKeys();
  $keys_set = (boolean) $keys;
  
  // Debug logging
  $this->logger->debug('Search keys: @keys', ['@keys' => print_r($keys, TRUE)]);
  
  /// Process keys if they exist
  if ($keys) {
    $original_keys = $keys;
    
    $this->logger->debug('About to process keys inline: @keys', [
      '@keys' => print_r($keys, TRUE)
    ]);
    
    // Inline key preparation logic (same as prepareKeysForPostgreSQL but direct)
    $processed_keys = NULL;
    
    if (is_scalar($keys)) {
      $processed_keys = trim($keys) !== '' ? $keys : NULL;
    } elseif ($keys && is_array($keys)) {
      // Count actual search terms (non-metadata keys)
      $search_terms = 0;
      foreach ($keys as $key => $value) {
        if ($key !== '#conjunction' && $key !== '#negation' && !empty($value)) {
          $search_terms++;
        }
      }
      
      // Only set to NULL if we have no actual search terms
      if ($search_terms > 0) {
        $processed_keys = $keys;  // Keep original keys
      }
    }
    
    $keys = $processed_keys;
    
    $this->logger->debug('Inline key processing: original=@original, processed=@processed', [
      '@original' => print_r($original_keys, TRUE),
      '@processed' => print_r($keys, TRUE)
    ]);
  } else {
    $this->logger->debug('Keys is falsy: @keys', ['@keys' => print_r($keys, TRUE)]);
  }
  
  $where_clauses = [];
  $query_params = [];
  
  // Only create fulltext search if we have valid, meaningful keys
  // This is the critical check that was missing!
  $key_validation_result = $keys && (!is_array($keys) || count($keys) > 2 || (!isset($keys['#negation']) && count($keys) > 1));
  $this->logger->debug('Key validation: keys_exist=@exist, validation_result=@result', [
    '@exist' => $keys ? 'true' : 'false',
    '@result' => $key_validation_result ? 'true' : 'false'
  ]);
  
  if ($key_validation_result) {
    // We have valid search keys - create fulltext search
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    $processed_keys = $this->processSearchKeys($search_text);
    
    $this->logger->debug('Fulltext search: search_text=@text, processed_keys=@keys', [
      '@text' => $search_text,
      '@keys' => $processed_keys
    ]);
    
    // Only proceed if processed keys are not empty
    if (!empty(trim($processed_keys))) {
      
      // Check server configuration for AI enablement
      $ai_enabled = $this->isAiSearchEnabled();
      
      if ($ai_enabled) {
        // Use AI-enhanced vector search
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';
        $select_fields[] = "ts_rank(search_vector, to_tsquery(?, ?)) AS search_api_relevance";
        $where_clauses[] = "search_vector @@ to_tsquery(?, ?)";
        $query_params[] = $fts_config;
        $query_params[] = $processed_keys;
        $query_params[] = $fts_config;
        $query_params[] = $processed_keys;
        $has_fulltext = TRUE;
        $this->logger->debug('Using AI-enhanced vector search with config=@config', ['@config' => $fts_config]);
      } else {
        // Use traditional PostgreSQL full-text search
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';
        $text_search_sql = $this->buildPostgreSQLTextSearch($fields, $search_text, $fts_config);
        $select_fields[] = "1.0 AS search_api_relevance";  // Could enhance with text ranking later
        $where_clauses[] = $text_search_sql;
        $has_fulltext = FALSE; // Not using AI vectors
        $this->logger->debug('Using traditional PostgreSQL text search');
      }
    } else {
      // Empty processed keys - fall back to basic query
      $select_fields[] = "1.0 AS search_api_relevance";
      $has_fulltext = FALSE;
      $this->logger->debug('Processed keys were empty, falling back to basic query');
    }
  } elseif ($keys_set) {
    // Keys were set but invalid/empty - add warning like search_api_db does
    $msg = $this->t('No valid search keys were present in the query.');
    $this->warnings[(string) $msg] = 1;
    // Fall back to basic query
    $select_fields[] = "1.0 AS search_api_relevance";
    $has_fulltext = FALSE;
    $this->logger->debug('Keys were set but invalid, falling back to basic query');
  } else {
    // No keys at all - basic query
    $select_fields[] = "1.0 AS search_api_relevance";
    $has_fulltext = FALSE;
    $this->logger->debug('No keys provided, using basic query');
  }
  
  // Add conditions from query filters
  $condition_sql = $this->buildConditionsFromQuery($query, $fields);
  if ($condition_sql) {
    $where_clauses[] = $condition_sql;
  }
  
  // Build WHERE clause
  $where_clause = !empty($where_clauses) ? implode(' AND ', $where_clauses) : '1=1';
  
  // Build base SQL
  $base_sql = "SELECT " . implode(', ', $select_fields) . " FROM {$table_name} WHERE {$where_clause}";
  
  // Debug logging
  $this->logger->debug('Generated SQL: @sql with params: @params', [
    '@sql' => $base_sql,
    '@params' => print_r($query_params, TRUE)
  ]);
  
  // Validate connector before proceeding
  if (!$this->connector) {
    throw new \Drupal\search_api\SearchApiException('Database connector is not available');
  }
  
  // Create a query object that mimics what search() expects
  $connector = $this->connector;
  $logger = $this->logger;
  
  return new class($base_sql, $query_params, $connector, $logger, $has_fulltext ?? FALSE) {
    private $sql;
    private $params;
    private $connector;
    private $logger;
    private $hasFulltext;
    private $offset = 0;
    private $limit = NULL;
    private $orderBy = [];
    
    public function __construct($sql, $params, $connector, $logger, $hasFulltext) {
      $this->sql = $sql;
      $this->params = $params;
      $this->connector = $connector;
      $this->logger = $logger;
      $this->hasFulltext = $hasFulltext;
      
      // Validate inputs
      if (!$this->connector) {
        throw new \Drupal\search_api\SearchApiException('Connector is null in query object');
      }
      if (empty($this->sql)) {
        throw new \Drupal\search_api\SearchApiException('SQL is empty in query object');
      }
    }
    
    /**
     * Creates a count query from this query.
     */
    public function countQuery() {
      // Extract the FROM and WHERE parts for counting
      $count_sql = preg_replace('/^SELECT .+ FROM/', 'SELECT COUNT(*) FROM', $this->sql);
      
      // For count queries, we need to adjust parameters since we're removing the SELECT part
      // The original query might have parameters in both SELECT and WHERE clauses
      // For fulltext queries, the SELECT has ts_rank(search_vector, to_tsquery(?, ?))
      // and the WHERE has search_vector @@ to_tsquery(?, ?)
      // The count query only needs the WHERE parameters
      
      $count_params = $this->params;
      
      // If this is a fulltext query, we need to remove the first 2 parameters 
      // (which were used for ts_rank in the SELECT clause)
      if ($this->hasFulltext && count($this->params) >= 4) {
        // Remove the first 2 parameters (used by ts_rank)
        $count_params = array_slice($this->params, 2);
      }
      
      return new class($count_sql, $count_params, $this->connector, $this->logger) {
        private $sql;
        private $params;
        private $connector;
        private $logger;
        
        public function __construct($sql, $params, $connector, $logger) {
          $this->sql = $sql;
          $this->params = $params;
          $this->connector = $connector;
          $this->logger = $logger;
        }
        
        public function execute() {
          try {
            $this->logger->debug('Executing count query: @sql with params: @params', [
              '@sql' => $this->sql,
              '@params' => print_r($this->params, TRUE)
            ]);
            
            $stmt = $this->connector->executeQuery($this->sql, $this->params);
            
            if (!$stmt) {
              $this->logger->error('Count query execution returned null statement: @sql', ['@sql' => $this->sql]);
              throw new \Drupal\search_api\SearchApiException('Count query execution failed: No statement returned');
            }
            
            return new class($stmt, $this->logger) {
              private $stmt;
              private $logger;
              
              public function __construct($stmt, $logger) { 
                $this->stmt = $stmt; 
                $this->logger = $logger;
              }
              
              public function fetchField() { 
                if (!$this->stmt) {
                  $this->logger->error('Statement is null when trying to fetchField');
                  return 0;
                }
                try {
                  $result = $this->stmt->fetchColumn();
                  $this->logger->debug('Count result: @result', ['@result' => $result]);
                  return $result !== FALSE ? (int)$result : 0;
                } catch (\Exception $e) {
                  $this->logger->error('Error fetching count: @error', ['@error' => $e->getMessage()]);
                  return 0;
                }
              }
            };
          } catch (\Exception $e) {
            $this->logger->error('Count query failed: @message. SQL: @sql', [
              '@message' => $e->getMessage(),
              '@sql' => $this->sql
            ]);
            throw new \Drupal\search_api\SearchApiException('Count query failed: ' . $e->getMessage(), 0, $e);
          }
        }
      };
    }
    
    /**
     * Adds a range (LIMIT/OFFSET) to the query.
     */
    public function range($start = NULL, $length = NULL) {
      $this->offset = $start ?? 0;
      $this->limit = $length;
      return $this;
    }
    
    /**
     * Adds ordering to the query.
     */
    public function orderBy($field, $direction = 'ASC') {
      $this->orderBy[] = "{$field} {$direction}";
      return $this;
    }
    
    /**
     * Executes the query and returns results.
     */
    public function execute() {
      try {
        $sql = $this->sql;
        
        // Add ORDER BY
        if (!empty($this->orderBy)) {
          $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        } elseif ($this->hasFulltext) {
          // Default to relevance ordering for fulltext searches
          $sql .= " ORDER BY search_api_relevance DESC";
        }
        
        // Add LIMIT/OFFSET
        if ($this->limit !== NULL) {
          $sql .= " LIMIT " . (int)$this->limit;
        }
        if ($this->offset > 0) {
          $sql .= " OFFSET " . (int)$this->offset;
        }
        
        $this->logger->debug('Executing main query: @sql with params: @params', [
          '@sql' => $sql,
          '@params' => print_r($this->params, TRUE)
        ]);
        
        // Execute the query and validate the result
        $stmt = $this->connector->executeQuery($sql, $this->params);
        
        if (!$stmt) {
          $this->logger->error('Query execution returned null statement: @sql', ['@sql' => $sql]);
          throw new \Drupal\search_api\SearchApiException('Query execution failed: No statement returned');
        }
        
        $this->logger->debug('Statement created successfully, fetching results...');
        
        // Fetch all results and return an iterable object
        $results = [];
        $row_count = 0;
        
        try {
          // Make sure we're using $stmt, not $this->stmt
          while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $row_count++;
            
            // Validate row data
            if (!$row || !isset($row['search_api_id'])) {
              $this->logger->warning('Invalid row data at row @count: @row', [
                '@count' => $row_count,
                '@row' => print_r($row, TRUE)
              ]);
              continue;
            }
            
            // Convert to object with expected properties
            $result_obj = new \stdClass();
            $result_obj->item_id = $row['search_api_id'];
            $result_obj->score = ($row['search_api_relevance'] ?? 1.0) * 1000; // Multiply by 1000 like search_api_db
            
            // Add other fields
            foreach ($row as $key => $value) {
              if ($key !== 'search_api_id' && $key !== 'search_api_relevance') {
                $result_obj->$key = $value;
              }
            }
            
            $results[] = $result_obj;
          }
        } catch (\Exception $e) {
          $this->logger->error('Error during result fetch: @error', ['@error' => $e->getMessage()]);
          throw $e;
        }
        
        $this->logger->debug('Query completed successfully. Found @count results', ['@count' => count($results)]);
        
        // Return an ArrayObject which is iterable and compatible with foreach
        return new \ArrayObject($results);
        
      } catch (\Exception $e) {
        $this->logger->error('Query execution failed: @message. SQL: @sql', [
          '@message' => $e->getMessage(),
          '@sql' => $sql ?? 'Unknown SQL'
        ]);
        throw new \Drupal\search_api\SearchApiException('Query execution failed: ' . $e->getMessage(), 0, $e);
      }
    }
  };
}

  /**
 * Prepares search keys for PostgreSQL (similar to search_api_db prepareKeys).
 */
protected function prepareKeysForPostgreSQL($keys) {
  try {
    error_log('STEP 1: Method called with keys: ' . print_r($keys, TRUE));
    
    // Test 1: Check if keys is scalar
    if (is_scalar($keys)) {
      error_log('STEP 2A: Keys is scalar: ' . var_export($keys, TRUE));
      $trimmed = trim($keys);
      error_log('STEP 2B: After trim: ' . var_export($trimmed, TRUE));
      $result = $trimmed !== '' ? $keys : NULL;
      error_log('STEP 2C: Scalar result: ' . var_export($result, TRUE));
      return $result;
    }
    
    error_log('STEP 3: Keys is not scalar, checking if empty...');
    
    // Test 2: Check if keys is empty
    if (!$keys) {
      error_log('STEP 4: Keys is empty, returning NULL');
      return NULL;
    }
    
    error_log('STEP 5: Keys is not empty, checking if array...');
    
    // Test 3: Check if keys is array
    if (is_array($keys)) {
      error_log('STEP 6: Keys is array, starting to count search terms...');
      
      $search_terms = 0;
      error_log('STEP 7: Initial search_terms count: ' . $search_terms);
      
      foreach ($keys as $key => $value) {
        error_log('STEP 8: Processing key=' . var_export($key, TRUE) . ', value=' . var_export($value, TRUE));
        
        $is_conjunction = ($key === '#conjunction');
        $is_negation = ($key === '#negation');
        $is_empty = empty($value);
        
        error_log('STEP 9: is_conjunction=' . var_export($is_conjunction, TRUE) . 
                  ', is_negation=' . var_export($is_negation, TRUE) . 
                  ', is_empty=' . var_export($is_empty, TRUE));
        
        if ($key !== '#conjunction' && $key !== '#negation' && !empty($value)) {
          $search_terms++;
          error_log('STEP 10: Found valid search term! Count now: ' . $search_terms);
        } else {
          error_log('STEP 11: Skipping this key/value pair');
        }
      }
      
      error_log('STEP 12: Final search_terms count: ' . $search_terms);
      
      if ($search_terms === 0) {
        error_log('STEP 13: No search terms found, returning NULL');
        return NULL;
      }
      
      error_log('STEP 14: Found search terms, returning original keys');
    } else {
      error_log('STEP 15: Keys is not an array, type is: ' . gettype($keys));
    }
    
    error_log('STEP 16: About to return original keys: ' . print_r($keys, TRUE));
    return $keys;
    
  } catch (\Exception $e) {
    error_log('EXCEPTION in prepareKeysForPostgreSQL: ' . $e->getMessage());
    error_log('Exception trace: ' . $e->getTraceAsString());
    return NULL;
  } catch (\Error $e) {
    error_log('ERROR in prepareKeysForPostgreSQL: ' . $e->getMessage());
    error_log('Error trace: ' . $e->getTraceAsString());
    return NULL;
  }
}

  /**
   * Enhanced processSearchKeys with empty string validation.
   */
  protected function processSearchKeys($keys) {
    if (empty($keys) || empty(trim($keys))) {
      return '';
    }
    
    // Simple processing - escape special characters for tsquery
    $processed = preg_replace('/[&|!():\'"<>]/', ' ', $keys);
    $processed = preg_replace('/\s+/', ' & ', trim($processed));
    
    // Final validation - ensure we have actual content
    return trim($processed);
  }

  /**
   * Builds conditions SQL from Search API query.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   * @param array $fields
   *   Field information.
   *
   * @return string|null
   *   SQL condition string or NULL.
   */
  protected function buildConditionsFromQuery(QueryInterface $query, array $fields) {
    $condition_group = $query->getConditionGroup();
    return $this->buildConditionGroupSql($condition_group, $fields);
  }

  /**
   * Builds SQL for a condition group.
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   * @param array $fields
   *   Field information.
   *
   * @return string|null
   *   SQL condition string or NULL.
   */
  protected function buildConditionGroupSql($condition_group, array $fields) {
    $conditions = $condition_group->getConditions();
    
    if (empty($conditions)) {
      return NULL;
    }
    
    $conjunction = $condition_group->getConjunction();
    $sql_conditions = [];
    
    foreach ($conditions as $condition) {
      if ($condition instanceof \Drupal\search_api\Query\ConditionGroupInterface) {
        $nested_sql = $this->buildConditionGroupSql($condition, $fields);
        if ($nested_sql) {
          $sql_conditions[] = "({$nested_sql})";
        }
      } else {
        $field = $condition->getField();
        $value = $condition->getValue();
        $operator = $condition->getOperator();
        
        if (isset($fields[$field]) || in_array($field, ['search_api_id', 'search_api_datasource', 'search_api_language'])) {
          $sql_conditions[] = $this->buildConditionSql($field, $value, $operator);
        }
      }
    }
    
    if (empty($sql_conditions)) {
      return NULL;
    }
    
    $conjunction_sql = $conjunction === 'OR' ? ' OR ' : ' AND ';
    return implode($conjunction_sql, $sql_conditions);
  }

  /**
   * Builds SQL for a single condition.
   *
   * @param string $field
   *   Field name.
   * @param mixed $value
   *   Field value.
   * @param string $operator
   *   Comparison operator.
   *
   * @return string
   *   SQL condition string.
   */
  protected function buildConditionSql($field, $value, $operator) {
    $quoted_field = $this->connector->quoteColumnName($field);
    
    switch ($operator) {
      case '=':
        return "{$quoted_field} = " . $this->quote($value);
      
      case '<>':
      case '!=':
        return "{$quoted_field} != " . $this->quote($value);
      
      case '<':
        return "{$quoted_field} < " . $this->quote($value);
      
      case '<=':
        return "{$quoted_field} <= " . $this->quote($value);
      
      case '>':
        return "{$quoted_field} > " . $this->quote($value);
      
      case '>=':
        return "{$quoted_field} >= " . $this->quote($value);
      
      case 'IN':
        if (is_array($value) && !empty($value)) {
          $quoted_values = array_map([$this, 'quote'], $value);
          return "{$quoted_field} IN (" . implode(', ', $quoted_values) . ")";
        }
        return '1=0'; // Empty IN should match nothing
      
      case 'NOT IN':
        if (is_array($value) && !empty($value)) {
          $quoted_values = array_map([$this, 'quote'], $value);
          return "{$quoted_field} NOT IN (" . implode(', ', $quoted_values) . ")";
        }
        return '1=1'; // Empty NOT IN should match everything
      
      case 'IS NULL':
        return "{$quoted_field} IS NULL";
      
      case 'IS NOT NULL':
        return "{$quoted_field} IS NOT NULL";
      
      default:
        // Default to equality
        return "{$quoted_field} = " . $this->quote($value);
    }
  }

  /**
   * Extracts text from complex search keys structure.
   *
   * @param mixed $keys
   *   The search keys.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractTextFromKeys($keys) {
    if (is_string($keys)) {
      return $keys;
    }

    $text_parts = [];
    foreach ($keys as $key => $value) {
      if ($key === '#conjunction') {
        continue;
      }
      
      if (is_array($value)) {
        $text_parts[] = $this->extractTextFromKeys($value);
      } else {
        $text_parts[] = $value;
      }
    }

    return implode(' ', $text_parts);
  }

  /**
   * Get Drupal database connection for query building.
   */
  protected function getDrupalConnection() {
    // Use the same connection as our connector
    return $this->connector->connect();
  }

  /**
   * Add quote method to connector if needed.
   */
  protected function quote($value) {
    if (method_exists($this->connector, 'quote')) {
      return $this->connector->quote($value);
    }
    
    // Fallback: use PDO quote method
    $pdo = $this->connector->connect();
    return $pdo->quote($value);
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
   * Updated to use EnhancedIndexManager when AI embeddings are enabled.
   */
  protected function getIndexManager() {
    if (!$this->indexManager) {
      // Ensure we have a connector
      $this->ensureConnector();
      
      // Create the field mapper
      $fieldMapper = new FieldMapper($this->configuration);
      $embedding_service = null;
      
      // Check if AI is enabled via ANY configuration path
      $ai_enabled = ($this->configuration['ai_embeddings']['enabled'] ?? FALSE) || 
                    ($this->configuration['azure_embedding']['enabled'] ?? FALSE);
      
      // Initialize embedding service if vector search is enabled
      if (isset($ai_enabled) && $ai_enabled) {
        try {
          $embedding_service = \Drupal::service('search_api_postgresql.embedding_service');
        } catch (\Exception $e) {
          $this->logger->warning('Embedding service not available: @error', ['@error' => $e->getMessage()]);
        }
      }
      
      // Use EnhancedIndexManager when AI embeddings are enabled
      if (isset($ai_enabled) && $ai_enabled && class_exists('\Drupal\search_api_postgresql\PostgreSQL\EnhancedIndexManager')) {
        $this->indexManager = new \Drupal\search_api_postgresql\PostgreSQL\EnhancedIndexManager(
          $this->connector,
          $fieldMapper,
          $this->configuration,
          $embedding_service,
          $this->getServerId()
        );
      } else {
        // Fall back to basic IndexManager
        $this->indexManager = new IndexManager(
          $this->connector,
          $fieldMapper,
          $this->configuration,
          $embedding_service
        );
      }
    }
    
    return $this->indexManager;
  }

  /**
   * Gets the server ID for this backend instance.
   */
  protected function getServerId() {
    // Try to get server ID from the server entity
    if ($this->server) {
      return $this->server->id();
    }
    
    // Fallback: try to find it from the configuration
    return $this->configuration['server_id'] ?? 'default';
  }

  /**
   * Checks if AI search enhancements are enabled in the server configuration.
   */
  protected function isAiSearchEnabled() {
    // Check various configuration keys that indicate AI is enabled
    $ai_config_keys = [
      'ai_embeddings.enabled',
      'ai_embeddings.azure_ai.enabled', 
      'azure_embedding.enabled',
      'vector_search.enabled'
    ];
    
    foreach ($ai_config_keys as $config_key) {
      if (!empty($this->configuration[$config_key])) {
        $this->logger->debug('AI search enabled via config key: @key', ['@key' => $config_key]);
        return TRUE;
      }
    }
    
    // Also check nested configuration structures
    if (!empty($this->configuration['ai_embeddings']['enabled']) ||
        !empty($this->configuration['vector_search']['enabled']) ||
        !empty($this->configuration['azure_embedding']['enabled'])) {
      $this->logger->debug('AI search enabled via nested configuration');
      return TRUE;
    }
    
    $this->logger->debug('AI search not enabled in configuration');
    return FALSE;
  }

  /**
   * Builds traditional PostgreSQL full-text search using text fields.
   */
  protected function buildPostgreSQLTextSearch($fields, $search_text, $fts_config = 'english') {
    $search_conditions = [];
    
    // Option 1: Use PostgreSQL built-in text search on individual fields
    foreach ($fields as $field_id => $field_info) {
      if (in_array($field_info['type'], ['text', 'string', 'postgresql_fulltext']) && $field_id !== 'search_api_id') {
        // Use PostgreSQL's to_tsvector and to_tsquery for built-in text search
        $search_conditions[] = "to_tsvector('{$fts_config}', COALESCE({$field_id}, '')) @@ plainto_tsquery('{$fts_config}', " . $this->quote($search_text) . ")";
      }
    }
    
    // Option 2: Fallback to ILIKE if no text fields found
    if (empty($search_conditions)) {
      $search_term = '%' . str_replace(['%', '_'], ['\%', '\_'], $search_text) . '%';
      $search_conditions[] = "title ILIKE " . $this->quote($search_term);
      $search_conditions[] = "processed ILIKE " . $this->quote($search_term);
      $this->logger->debug('Falling back to ILIKE search');
    }
    
    $result = '(' . implode(' OR ', $search_conditions) . ')';
    $this->logger->debug('PostgreSQL text search SQL: @sql', ['@sql' => $result]);
    
    return $result;
  }

}