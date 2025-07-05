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
    try {
      $this->ensureConnector();
      
      // Follow search_api_db pattern exactly
      $index = $query->getIndex();
      
      // Get field information (like search_api_db does)
      $fields = $this->getFieldInfo($index);
      $fields['search_api_id'] = [
        'column' => 'search_api_id',  // Our PostgreSQL table uses search_api_id
      ];

      // Create database query (like search_api_db does)
      $db_query = $this->createDbQuery($query, $fields);
      
      // Get results object (like search_api_db)
      $results = $query->getResults();

      // Handle result count first (exactly like search_api_db)
      $skip_count = $query->getOption('skip result count');
      $count = NULL;
      
      if (!$skip_count) {
        try {
          $count_query = $db_query->countQuery();
          $count = $count_query->execute()->fetchField();
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
          $db_query->range($offset, $limit);
        }

        // Apply sorting (like search_api_db)
        $this->setQuerySort($query, $db_query, $fields);

        // Execute query (like search_api_db)
        $result = $db_query->execute();

        $indexed_fields = $index->getFields(TRUE);
        $retrieved_field_names = $query->getOption('search_api_retrieved_field_values', []);
        
        // Process results (like search_api_db)
        $item = NULL; // Initialize for skip_count check
        foreach ($result as $row) {
          $item = $this->getFieldsHelper()->createItem($index, $row->search_api_id);
          $item->setScore($row->search_api_relevance ?? 1.0);
          $this->extractRetrievedFieldValuesWhereAvailable($row, $indexed_fields, $retrieved_field_names, $item);
          $results->addResultItem($item);
        }
        
        // Handle skip_count case (exactly like search_api_db)
        if ($skip_count && !empty($item)) {
          $results->setResultCount(1);
        }
      }
      
      return $results;
      
    } catch (\PDOException $e) {
      // Follow search_api_db error handling pattern exactly
      if ($query instanceof \Drupal\Core\Cache\RefinableCacheableDependencyInterface) {
        $query->mergeCacheMaxAge(0);
      }
      throw new SearchApiException('A database exception occurred while searching.', $e->getCode(), $e);
    } catch (\Exception $e) {
      $this->logger->error('Search execution failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new SearchApiException('Search execution failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Extract retrieved field values where available.
   *
   * @param array $row
   *   The database row.
   * @param array $indexed_fields
   *   The indexed fields.
   * @param array $retrieved_field_names
   *   The retrieved field names.
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item to populate.
   */
  protected function extractRetrievedFieldValuesWhereAvailable($row, array $indexed_fields, array $retrieved_field_names, $item) {
    // Convert row array to object if needed (for consistency)
    if (is_array($row)) {
      $row = (object) $row;
    }
    
    foreach ($indexed_fields as $field_id => $field) {
      // Skip if field not in retrieved fields and retrieved fields are specified
      if (!empty($retrieved_field_names) && !in_array($field_id, $retrieved_field_names)) {
        continue;
      }
      
      // Skip if field not in row
      if (!isset($row->{$field_id})) {
        continue;
      }
      
      try {
        $field_value = $this->processFieldValue($row->{$field_id}, $field->getType());
        $item_field = $item->getField($field_id);
        if ($item_field) {
          $item_field->setValues([$field_value]);
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed to set field @field on item @item: @error', [
          '@field' => $field_id,
          '@item' => $item->getId(),
          '@error' => $e->getMessage(),
        ]);
      }
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
    // Build SQL query using our existing connector approach
    // but return an object that search() can work with
    
    $index = $query->getIndex();
    $table_name = $this->getIndexTableNameForManager($index);
    
    // Build SQL parts
    $select_fields = ['search_api_id'];
    foreach ($fields as $field_id => $field_info) {
      if ($field_id !== 'search_api_id') {
        $select_fields[] = $field_id;
      }
    }
    
    // Add relevance calculation
    $keys = $query->getKeys();
    if ($keys) {
      $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
      $fts_config = $this->configuration['fts_configuration'] ?? 'english';
      $processed_keys = $this->processSearchKeys($search_text);
      
      $select_fields[] = "ts_rank(search_vector, to_tsquery('{$fts_config}', '{$processed_keys}')) AS search_api_relevance";
      $where_clause = "search_vector @@ to_tsquery('{$fts_config}', '{$processed_keys}')";
    } else {
      $select_fields[] = "1.0 AS search_api_relevance";
      $where_clause = "1=1";
    }
    
    // Build base SQL
    $sql = "SELECT " . implode(', ', $select_fields) . " FROM {$table_name} WHERE {$where_clause}";
    
    // Add conditions
    $condition_sql = $this->buildConditionsFromQuery($query, $fields);
    if ($condition_sql) {
      $sql .= " AND " . $condition_sql;
    }
    
    // Create a query object that mimics what search() expects
    return new class($sql, $this->connector, $keys ? $processed_keys : NULL, $fts_config ?? 'english') {
      private $sql;
      private $connector;
      private $searchKeys;
      private $ftsConfig;
      private $offset = 0;
      private $limit = NULL;
      private $orderBy = [];
      
      public function __construct($sql, $connector, $searchKeys, $ftsConfig) {
        $this->sql = $sql;
        $this->connector = $connector;
        $this->searchKeys = $searchKeys;
        $this->ftsConfig = $ftsConfig;
      }
      
      public function countQuery() {
        $count_sql = preg_replace('/^SELECT .+ FROM/', 'SELECT COUNT(*) FROM', $this->sql);
        return new class($count_sql, $this->connector) {
          private $sql;
          private $connector;
          
          public function __construct($sql, $connector) {
            $this->sql = $sql;
            $this->connector = $connector;
          }
          
          public function execute() {
            $stmt = $this->connector->executeQuery($this->sql);
            return new class($stmt) {
              private $stmt;
              public function __construct($stmt) { $this->stmt = $stmt; }
              public function fetchField() { return $this->stmt->fetchColumn(); }
            };
          }
        };
      }
      
      public function range($offset, $limit) {
        $this->offset = $offset;
        $this->limit = $limit;
        return $this;
      }
      
      public function orderBy($field, $direction = 'ASC') {
        $this->orderBy[] = "{$field} {$direction}";
        return $this;
      }
      
      public function execute() {
        $final_sql = $this->sql;
        
        if (!empty($this->orderBy)) {
          $final_sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }
        
        if ($this->limit !== NULL) {
          $final_sql .= " LIMIT {$this->limit}";
          if ($this->offset > 0) {
            $final_sql .= " OFFSET {$this->offset}";
          }
        }
        
        $stmt = $this->connector->executeQuery($final_sql);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
      }
    };
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
   * Processes search keys for PostgreSQL tsquery.
   *
   * @param string $keys
   *   The search keys.
   *
   * @return string
   *   Processed search string for tsquery.
   */
  protected function processSearchKeys($keys) {
    // Simple processing - escape special characters for tsquery
    $processed = preg_replace('/[&|!():\'"<>]/', ' ', $keys);
    $processed = preg_replace('/\s+/', ' & ', trim($processed));
    return $processed;
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
   * Sets the query sort (like search_api_db).
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   * @param object $db_query
   *   The database query object.
   * @param array $fields
   *   Field information.
   */
  protected function setQuerySort(QueryInterface $query, $db_query, array $fields) {
    $sorts = $query->getSorts();
    
    if (empty($sorts)) {
      // Default sort by relevance if no sorts specified
      $db_query->orderBy('search_api_relevance', 'DESC');
      return;
    }
    
    foreach ($sorts as $field => $order) {
      $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
      $db_query->orderBy($field, $direction);
    }
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
}