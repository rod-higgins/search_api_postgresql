<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\search_api_postgresql\PostgreSQL\EnhancedIndexManager;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\search_api\Query\ConditionGroupInterface;
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
use Drupal\search_api_postgresql\PostgreSQL\FieldMapper;
use Drupal\search_api_postgresql\PostgreSQL\IndexManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL search backend with optional AI vector search support.
 *
 * {@inheritdoc}
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL Database"),
 *   description = @Translation("PostgreSQL backend supporting standard " .
 *     "database search with optional AI embedding providers for semantic search.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements ContainerFactoryPluginInterface, PluginFormInterface
{
  use SecurityManagementTrait;
  use PluginFormTrait;

  /**
   * The logger.
   *
   * {@inheritdoc}
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The PostgreSQL connector.
   *
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The embedding service.
   *
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingService
   */
  protected $embeddingService;

  /**
   * The vector search service.
   *
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\Service\VectorSearchService
   */
  protected $vectorSearchService;

  /**
   * The field mapper.
   *
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\FieldMapper
   */
  protected $fieldMapper;

  /**
   * The index manager.
   *
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\IndexManager
   */
  protected $indexManager;

  /**
   * Array of warnings that occurred during the query.
   *
   * {@inheritdoc}
   *
   * @var array
   */
  protected $warnings = [];

  /**
   * Array of ignored search keys.
   *
   * {@inheritdoc}
   *
   * @var array
   */
  protected $ignored = [];

  /**
   * The current index context for multi-value detection.
   *
   * {@inheritdoc}
   *
   * @var \Drupal\search_api\IndexInterface|null
   */
  protected $currentIndex;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->logger = $container->get('logger.factory')->get('search_api_postgresql');

    // Key repository is optional - don't fail if not available.
    try {
      if ($container->has('key.repository')) {
        $instance->keyRepository = $container->get('key.repository');
      }
    } catch (\Exception $e) {
      // Key module not available, continue without it.
      $instance->logger->info('Key module not available, using direct credential entry only.');
    }

    // Initialize services.
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
  public function defaultConfiguration()
  {
    return [
      // Database Connection Configuration.
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

      // Index Configuration.
      'fts_configuration' => 'english',
      'index_prefix' => 'search_api_',
      'debug' => false,
      'batch_size' => 100,

      // AI Embeddings Configuration.
      'ai_embeddings' => [
        'enabled' => false,
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
        'cache' => true,
        'cache_ttl' => 3600,
      ],

      // Vector Index Configuration.
      'vector_index' => [
        'method' => 'ivfflat',
        'lists' => 100,
        'probes' => 10,
        'distance' => 'cosine',
      ],

      // Hybrid Search Configuration.
      'hybrid_search' => [
        'enabled' => true,
        'text_weight' => 0.6,
        'vector_weight' => 0.4,
        'similarity_threshold' => 0.15,
        'max_results' => 1000,
      ],

      // Performance Configuration.
      'performance' => [
        'connection_pool_size' => 10,
        'statement_timeout' => 30000,
        'work_mem' => '256MB',
        'effective_cache_size' => '2GB',
      ],

      // Multi facet dependency.
      'multi_value' => [
      // Prefer arrays over auto-detection.
        'format' => 'array',
        'separator' => '|',
        'detection_cache_ttl' => 3600,
        'prefer_arrays_for_taxonomy' => true,
      // Optimized for integers.
        'gin_index_operator_class' => 'gin__int_ops',
      ],

    ];
  }

  /**
   * Conservative update - only add missing form elements for existing schema.
   *
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {

    // Database Connection (expand existing)
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Database Connection'),
      '#open' => true,
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->configuration['connection']['host'] ?? 'localhost',
      '#required' => true,
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['connection']['port'] ?? 5432,
      '#required' => true,
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'] ?? '',
      '#required' => true,
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'] ?? '',
      '#required' => true,
    ];

    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#default_value' => '',
      '#description' => $this->t('Leave empty to keep existing password.'),
    ];

    $form['connection']['ssl_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('SSL Mode'),
      '#options' => [
        'disable' => $this->t('Disable'),
        'prefer' => $this->t('Prefer'),
        'require' => $this->t('Require'),
      ],
      '#default_value' => $this->configuration['connection']['ssl_mode'] ?? 'prefer',
    ];

    // FTS Configuration.
    $form['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('PostgreSQL Full-Text Search Configuration'),
      '#options' => [
        'simple' => $this->t('Simple'),
        'english' => $this->t('English'),
        'spanish' => $this->t('Spanish'),
        'french' => $this->t('French'),
        'german' => $this->t('German'),
      ],
      '#default_value' => $this->configuration['fts_configuration'] ?? 'english',
    ];

    // AI Embeddings (use existing schema paths)
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings'),
      '#open' => false,
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? false,
    ];

    $form['ai_embeddings']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => [
        'azure' => $this->t('Azure OpenAI'),
        'openai' => $this->t('OpenAI'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['provider'] ?? 'azure',
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => true],
        ],
      ],
    ];

    // Azure config (using exact existing schema paths)
    $form['ai_embeddings']['azure'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Azure OpenAI'),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => true],
          ':input[name="ai_embeddings[provider]"]' => ['value' => 'azure'],
        ],
      ],
    ];

    $form['ai_embeddings']['azure']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['endpoint'] ?? '',
    ];

    $form['ai_embeddings']['azure']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#default_value' => '',
      '#description' => $this->t('Leave empty to keep existing key.'),
    ];

    $form['ai_embeddings']['azure']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['deployment_name'] ?? '',
    ];

    $form['ai_embeddings']['azure']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure']['model'] ?? 'text-embedding-3-small',
    ];

    $form['ai_embeddings']['azure']['dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Dimensions'),
      '#default_value' => $this->configuration['ai_embeddings']['azure']['dimension'] ?? 1536,
      '#min' => 1,
      '#max' => 10000,
    ];

    // Vector Index Configuration (using existing schema)
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index'),
      '#open' => false,
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => true],
        ],
      ],
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat'),
        'hnsw' => $this->t('HNSW'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
    ];

    $form['vector_index']['distance'] = [
      '#type' => 'select',
      '#title' => $this->t('Distance Metric'),
      '#options' => [
        'cosine' => $this->t('Cosine'),
        'l2' => $this->t('L2'),
        'inner_product' => $this->t('Inner Product'),
      ],
      '#default_value' => $this->configuration['vector_index']['distance'] ?? 'cosine',
    ];

    $form['vector_index']['lists'] = [
      '#type' => 'number',
      '#title' => $this->t('Lists (IVFFlat)'),
      '#default_value' => $this->configuration['vector_index']['lists'] ?? 100,
      '#min' => 1,
      '#max' => 10000,
    ];

    $form['vector_index']['probes'] = [
      '#type' => 'number',
      '#title' => $this->t('Probes (IVFFlat)'),
      '#default_value' => $this->configuration['vector_index']['probes'] ?? 10,
      '#min' => 1,
      '#max' => 1000,
    ];

    // Hybrid Search (using existing schema)
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search'),
      '#open' => false,
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => true],
        ],
      ],
    ];

    $form['hybrid_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['hybrid_search']['enabled'] ?? true,
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'] ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    // Basic settings (existing schema paths)
    $form['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#default_value' => $this->configuration['debug'] ?? false,
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
    ];

    // Optional: Add JavaScript enhancement library.
    $form['#attached']['library'][] = 'search_api_postgresql/admin';

    return $form;
  }

  /**
   * Validation method - only essential checks.
   *
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
  {

    $values = $form_state->getValues();

    // Validate database connection basics.
    if (isset($values['connection'])) {
      $connection = $values['connection'];

      // Check required fields.
      if (empty($connection['host'])) {
        $form_state->setErrorByName('connection][host', $this->t('Database host is required.'));
      }

      if (empty($connection['database'])) {
        $form_state->setErrorByName('connection][database', $this->t('Database name is required.'));
      }

      if (empty($connection['username'])) {
        $form_state->setErrorByName('connection][username', $this->t('Database username is required.'));
      }

      // Validate port range.
      if (!empty($connection['port'])) {
        $port = (int) $connection['port'];
        if ($port < 1 || $port > 65535) {
          $form_state->setErrorByName('connection][port', $this->t('Port must be between 1 and 65535.'));
        }
      }
    }

    // Validate AI embeddings if enabled.
    if (!empty($values['ai_embeddings']['enabled'])) {
      $ai_config = $values['ai_embeddings'];

      if ($ai_config['provider'] === 'azure' && isset($ai_config['azure'])) {
        $azure = $ai_config['azure'];

        if (empty($azure['endpoint'])) {
          $form_state->setErrorByName('ai_embeddings][azure][endpoint', $this->t('Azure endpoint is required.'));
        }

        if (empty($azure['deployment_name'])) {
          $form_state->setErrorByName(
              'ai_embeddings][azure][deployment_name',
              $this->t('Deployment name is required.')
          );
        }

        // Validate endpoint format.
        if (!empty($azure['endpoint']) && !filter_var($azure['endpoint'], FILTER_VALIDATE_URL)) {
          $form_state->setErrorByName('ai_embeddings][azure][endpoint', $this->t('Endpoint must be a valid URL.'));
        }
      }
    }

    // Validate vector index settings.
    if (!empty($values['ai_embeddings']['enabled']) && isset($values['vector_index'])) {
      $vector = $values['vector_index'];

      if (!empty($vector['lists'])) {
        $lists = (int) $vector['lists'];
        if ($lists < 1 || $lists > 10000) {
          $form_state->setErrorByName('vector_index][lists', $this->t('Lists must be between 1 and 10000.'));
        }
      }

      if (!empty($vector['probes'])) {
        $probes = (int) $vector['probes'];
        $lists = (int) ($vector['lists'] ?? 100);
        if ($probes < 1 || $probes > $lists) {
          $form_state->setErrorByName(
              'vector_index][probes',
              $this->t('Probes must be between 1 and @lists.', ['@lists' => $lists])
          );
        }
      }
    }

    // Validate hybrid search weights.
    if (!empty($values['hybrid_search']['enabled'])) {
      $hybrid = $values['hybrid_search'];

      if (isset($hybrid['text_weight'])) {
        $text_weight = (float) $hybrid['text_weight'];
        if ($text_weight < 0 || $text_weight > 1) {
          $form_state->setErrorByName('hybrid_search][text_weight', $this->t('Text weight must be between 0 and 1.'));
        }
      }

      if (isset($hybrid['vector_weight'])) {
        $vector_weight = (float) $hybrid['vector_weight'];
        if ($vector_weight < 0 || $vector_weight > 1) {
          $form_state->setErrorByName(
              'hybrid_search][vector_weight',
              $this->t('Vector weight must be between 0 and 1.')
          );
        }
      }
    }

    // Validate basic settings.
    if (!empty($values['batch_size'])) {
      $batch_size = (int) $values['batch_size'];
      if ($batch_size < 1 || $batch_size > 1000) {
        $form_state->setErrorByName('batch_size', $this->t('Batch size must be between 1 and 1000.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    $values = $form_state->getValues();

    // Handle connection configuration.
    if (isset($values['connection'])) {
      foreach ($values['connection'] as $key => $value) {
        // Only update password if provided.
        if ($key === 'password' && !empty($value)) {
          $this->configuration['connection'][$key] = $value;
        } elseif ($key !== 'password') {
          $this->configuration['connection'][$key] = $value;
        }
      }
    }

    // Handle AI embeddings configuration.
    if (isset($values['ai_embeddings'])) {
      $ai_values = $values['ai_embeddings'];

      // Basic AI settings.
      $this->configuration['ai_embeddings']['enabled'] = $ai_values['enabled'] ?? false;
      $this->configuration['ai_embeddings']['provider'] = $ai_values['provider'] ?? 'azure';

      // Azure configuration - keep existing password handling logic.
      if (isset($ai_values['azure'])) {
        foreach ($ai_values['azure'] as $key => $value) {
          if ($key === 'api_key' && !empty($value)) {
            $this->configuration['ai_embeddings']['azure'][$key] = $value;
          } elseif ($key !== 'api_key') {
            $this->configuration['ai_embeddings']['azure'][$key] = $value;
          }
        }

        // Auto-set dimensions based on model (keep existing logic)
        if (!empty($ai_values['azure']['model'])) {
          $model = $ai_values['azure']['model'];
          $dimensions = [
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
          ];

          if (isset($dimensions[$model])) {
            $this->configuration['ai_embeddings']['azure']['dimension'] = $dimensions[$model];
          }
        }
      }

      // OpenAI configuration (keep existing logic)
      if (isset($ai_values['openai'])) {
        foreach ($ai_values['openai'] as $key => $value) {
          if ($key === 'api_key' && !empty($value)) {
            $this->configuration['ai_embeddings']['openai'][$key] = $value;
          } elseif ($key !== 'api_key') {
            $this->configuration['ai_embeddings']['openai'][$key] = $value;
          }
        }
      }
    }

    // Handle vector index configuration (add this section)
    if (isset($values['vector_index'])) {
      $this->configuration['vector_index'] = $values['vector_index'];
    }

    // Handle hybrid search configuration (add this section)
    if (isset($values['hybrid_search'])) {
      $this->configuration['hybrid_search'] = $values['hybrid_search'];
    }

    // Handle root-level configuration values.
    $simple_mappings = [
      'fts_configuration',
      'index_prefix',
      'debug',
      'batch_size',
    ];

    foreach ($simple_mappings as $key) {
      if (isset($values[$key])) {
        $this->configuration[$key] = $values[$key];
      }
    }

    // Keep existing cleanup logic
    // Clear the connector to force reinitialization with new settings.
    $this->connector = null;

    // Clear any cached embedding service.
    $this->embeddingService = null;

    // Clear index manager to force recreation with new configuration.
    $this->indexManager = null;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type)
  {
    $supported_types = [
      'text',
      'string',
      'integer',
      'decimal',
      'date',
      'boolean',
    ];

    // Add PostgreSQL-specific data types with caching.
    if ($this->isPostgreSqlSupportedCached()) {
      $supported_types[] = 'postgresql_fulltext';
    }

    // Add vector type if pgvector is available with caching.
    if ($this->isVectorSupportedCached()) {
      $supported_types[] = 'vector';
    }

    return in_array($type, $supported_types);
  }

  /**
   * Generic multi-value entity reference detection.
   */
  protected function isMultiValueEntityReference($field)
  {
    // Check if field has multiple cardinality.
    if (!$this->isFieldMultiValue($field)) {
      return false;
    }

    // Check if it's an entity reference field through proper field definition.
    try {
      $datasource_id = $field->getDatasourceId();
      if ($datasource_id && $this->currentIndex) {
        $datasource = $this->currentIndex->getDatasource($datasource_id);
        if ($datasource) {
          $property_path = $field->getPropertyPath();
          $properties = $datasource->getPropertyDefinitions();

          if (isset($properties[$property_path])) {
            $property = $properties[$property_path];

            // Check if it's an entity reference type.
            if (method_exists($property, 'getDataType')) {
              return $property->getDataType() === 'entity_reference';
            }

            // Alternative check via field type definition.
            if (method_exists($property, 'getType')) {
              return in_array($property->getType(), ['entity_reference', 'entity_reference_revisions']);
            }
          }
        }
      }
    } catch (\Exception $e) {
      // Continue with field type check.
    }

    // Fallback: check if field stores integer values (entity IDs are always integers)
    return $field->getType() === 'integer';
  }

  /**
   * Add method to force reindexing with array storage.
   */
  public function forceArrayStorageReindex(IndexInterface $index)
  {
    try {
      // Clear any cached detection results.
      \Drupal::cache()->deleteMultiple([
        'search_api_postgresql:multivalue:*',
        'search_api_postgresql:field_stats:*',
      ]);

      // Force array storage for all taxonomy fields.
      $this->configuration['multi_value']['format'] = 'array';
      $this->configuration['multi_value']['prefer_arrays_for_taxonomy'] = true;

      // Clear the index manager to force reconfiguration.
      $this->indexManager = null;

      // Recreate the index with array storage.
      $this->updateIndex($index);

      $this->logger->info('Forced array storage reindexing for @index', ['@index' => $index->id()]);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to force array storage reindex: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Cached version of isPostgreSqlSupported().
   */
  protected function isPostgreSqlSupportedCached()
  {
    $cache_key = 'search_api_postgresql:support:' . md5(serialize($this->configuration['connection']));
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    try {
      $this->ensureConnector();
      $pdo = $this->connector->connect();
      $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
      $stmt = $pdo->query("SELECT to_tsvector('english', 'test')");
      $result = true;
    } catch (\Exception $e) {
      $this->logger->debug('PostgreSQL FTS check failed: @error', ['@error' => $e->getMessage()]);
      $result = false;
    }

    \Drupal::cache()->set($cache_key, $result, \Drupal::time()->getRequestTime() + 3600);
    return $result;
  }

  /**
   * Cached version of isVectorSupported().
   */
  protected function isVectorSupportedCached()
  {
    $cache_key = 'search_api_postgresql:vector:' . md5(serialize($this->configuration['connection']));
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    try {
      $this->ensureConnector();
      $pdo = $this->connector->connect();
      $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 3);
      $stmt = $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')");
      $result = (bool) $stmt->fetchColumn();
    } catch (\Exception $e) {
      $this->logger->debug('Vector support check failed: @error', ['@error' => $e->getMessage()]);
      $result = false;
    }

    \Drupal::cache()->set($cache_key, $result, \Drupal::time()->getRequestTime() + 3600);
    return $result;
  }

  /**
   * Check if PostgreSQL full-text search is supported.
   */
  protected function isPostgreSqlSupported()
  {
    try {
      $this->ensureConnector();
      // Use the existing validation logic from PostgreSQLFulltext::validatePostgreSQLSupport()
      $pdo = $this->connector->connect();
      $stmt = $pdo->query("SELECT to_tsvector('english', 'test')");
      return true;
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Check if vector search is supported.
   */
  protected function isVectorSupported()
  {
    try {
      $this->ensureConnector();
      $pdo = $this->connector->connect();
      $stmt = $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector')");
      return (bool) $stmt->fetchColumn();
    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures()
  {
    return [
    // Standard Search API feature.
      'search_api_autocomplete',
    // Standard Search API feature.
      'search_api_facets',
    // Standard Search API feature.
      'search_api_facets_operator_or',
    // Implemented AND operator support.
      'search_api_facets_operator_and',
    // Standard Search API feature.
      'search_api_random_sort',
    // PostgreSQL can support this.
      'search_api_grouping',
    // With AI embeddings (More Like This)
      'search_api_mlt',
    // With AI embeddings.
      'search_api_spellcheck',
    ];
  }

  /**
   * Clean up viewSettings() - remove timing debug noise.
   */
  public function viewSettings()
  {
    $info = [];

    // Database connection info.
    $connection_string = $this->configuration['connection']['host'] . ':' . $this->configuration['connection']['port'];
    $info[] = [
      'label' => $this->t('Database'),
      'info' => $this->configuration['connection']['database'] . ' @ ' . $connection_string,
    ];

    $info[] = [
      'label' => $this->t('SSL Mode'),
      'info' => $this->configuration['connection']['ssl_mode'] ?? 'prefer',
    ];

    $info[] = [
      'label' => $this->t('FTS Configuration'),
      'info' => $this->configuration['fts_configuration'] ?? 'english',
    ];

    // Show connection status.
    try {
      $this->ensureConnector();
      $result = $this->connector->testConnection();

      if ($result && $result['success']) {
        $info[] = [
          'label' => $this->t('Database Status'),
          'info' => $this->t('Connected to @db', ['@db' => $result['database'] ?? 'database']),
        ];
      } else {
        $info[] = [
          'label' => $this->t('Database Status'),
          'info' => $this->t('Connection failed'),
        ];
      }
    } catch (\Exception $e) {
      $info[] = [
        'label' => $this->t('Database Status'),
        'info' => $this->t('Error: @error', ['@error' => $e->getMessage()]),
      ];
    }

    $info[] = [
      'label' => $this->t('Indexed Items'),
      'info' => $this->t('View counts on individual indexes'),
    ];

    return $info;
  }

  /**
   * Helper to get unquoted table name for Drupal database API.
   */
  protected function getUnquotedTableNameForDrupal($index)
  {
    $table_name = $this->getIndexTableNameForManager($index);
    return $this->getUnquotedTableName($table_name);
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable()
  {
    try {
      $this->ensureConnector();
      $result = $this->connector->testConnection();
      return is_array($result) && !empty($result['success']);
    } catch (\Exception $e) {
      $this->logger->error('PostgreSQL backend availability check failed: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Helper method to get table name using IndexManager's logic.
   * {@inheritdoc}
   * This ensures consistency with IndexManager's table name construction.
   */
  protected function getIndexTableNameForManager(IndexInterface $index)
  {
    $index_id = $index->id();

    // Use the same validation as IndexManager.
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $index_id)) {
      throw new \InvalidArgumentException("Invalid index ID: {$index_id}");
    }

    // Use the same prefix logic as IndexManager.
    $prefix = $this->configuration['index_prefix'] ?? 'search_api_';
    $table_name_unquoted = $prefix . $index_id;

    // Use connector's quoting method.
    return $this->connector->quoteTableName($table_name_unquoted);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index)
  {
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
  public function updateIndex(IndexInterface $index)
  {
    try {
      $this->ensureConnector();
      $this->ensureFieldMapper();

      // Get current and target field definitions.
      $current_definitions = $this->getCurrentFieldDefinitions($index);
      $target_definitions = $this->fieldMapper->getFieldDefinitions($index);

      // Detect changes.
      $field_changes = $this->detectFieldChanges($current_definitions, $target_definitions);

      if (!empty($field_changes)) {
        $this->logger->info('Detected @count field changes for index @index', [
          '@count' => count($field_changes),
          '@index' => $index->id(),
        ]);

        // Apply field changes.
        $this->applyFieldChanges($index, $field_changes);
      }

      // Update the index schema normally.
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
   * Gets current field definitions from the database schema.
   *
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *
   * @return array
   *   Array of current field definitions keyed by field name.
   */
  protected function getCurrentFieldDefinitions(IndexInterface $index)
  {
    $table_name = $this->getIndexTableNameForManager($index);
    $unquoted_table = $this->getUnquotedTableName($table_name);

    $sql = "SELECT column_name, data_type, udt_name 
            FROM information_schema.columns 
            WHERE table_name = ? 
            AND column_name NOT IN ('search_api_id', 'search_api_datasource', 'search_api_language', 'search_vector')
            ORDER BY ordinal_position";

    $stmt = $this->connector->executeQuery($sql, [$unquoted_table]);

    $definitions = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $column_name = $row['column_name'];
      $pg_type = $this->mapDatabaseTypeToStandard($row['data_type'], $row['udt_name']);

      $definitions[$column_name] = [
        'type' => $pg_type,
        'raw_type' => $row['data_type'],
        'udt_name' => $row['udt_name'],
      ];
    }

    return $definitions;
  }

  /**
   * Detects changes between current and target field definitions.
   *
   * {@inheritdoc}
   *
   * @param array $current_definitions
   *   Current field definitions from database.
   * @param array $target_definitions
   *   Target field definitions from FieldMapper.
   *
   * @return array
   *   Array of field changes keyed by field ID.
   */
  protected function detectFieldChanges(array $current_definitions, array $target_definitions)
  {
    $changes = [];

    foreach ($target_definitions as $field_id => $target_def) {
      $current_def = $current_definitions[$field_id] ?? null;

      if (!$current_def) {
        // New field.
        $changes[$field_id] = [
          'action' => 'add',
          'target_type' => $target_def['type'],
        ];
      } elseif ($current_def['type'] !== $target_def['type']) {
        // Type change.
        $changes[$field_id] = [
          'action' => 'modify',
          'current_type' => $current_def['type'],
          'target_type' => $target_def['type'],
        ];

        $this->logger->info('Detected type change for field @field: @current -> @target', [
          '@field' => $field_id,
          '@current' => $current_def['type'],
          '@target' => $target_def['type'],
        ]);
      }
    }

    // Check for removed fields.
    foreach ($current_definitions as $field_id => $current_def) {
      if (!isset($target_definitions[$field_id])) {
        $changes[$field_id] = [
          'action' => 'remove',
          'current_type' => $current_def['type'],
        ];
      }
    }

    return $changes;
  }

  /**
   * Applies detected field changes to the database schema.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param array $field_changes
   *   Array of field changes to apply.
   */
  protected function applyFieldChanges(IndexInterface $index, array $field_changes)
  {
    $table_name = $this->getIndexTableNameForManager($index);

    foreach ($field_changes as $field_id => $change) {
      try {
        switch ($change['action']) {
          case 'add':
            $this->addFieldColumn($table_name, $field_id, $change['target_type']);
              break;

          case 'modify':
            if ($this->needsColumnTypeConversion($change['current_type'], $change['target_type'])) {
              $this->alterColumnType($table_name, $field_id, $change['current_type'], $change['target_type']);
            }
              break;

          case 'remove':
            $this->removeFieldColumn($table_name, $field_id);
              break;
        }
      } catch (\Exception $e) {
        $this->logger->error('Failed to apply change to field @field: @error', [
          '@field' => $field_id,
          '@error' => $e->getMessage(),
        ]);
        throw $e;
      }
    }
  }

  /**
   * Adds a new field column to the index table.
   * {@inheritdoc}
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param string $field_id
   *   The field ID.
   * @param string $target_type
   *   The target PostgreSQL type.
   */
  protected function addFieldColumn($table_name, $field_id, $target_type)
  {
    $safe_field_id = $this->connector->quoteColumnName($field_id);
    $sql = "ALTER TABLE {$table_name} ADD COLUMN {$safe_field_id} {$target_type}";
    $this->connector->executeQuery($sql);

    $this->logger->info('Added column @field with type @type', [
      '@field' => $field_id,
      '@type' => $target_type,
    ]);
  }

  /**
   * Removes a field column from the index table.
   * {@inheritdoc}
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param string $field_id
   *   The field ID.
   */
  protected function removeFieldColumn($table_name, $field_id)
  {
    $safe_field_id = $this->connector->quoteColumnName($field_id);
    $sql = "ALTER TABLE {$table_name} DROP COLUMN IF EXISTS {$safe_field_id}";
    $this->connector->executeQuery($sql);

    $this->logger->info('Removed column @field', ['@field' => $field_id]);
  }

  /**
   * {@inheritdoc}
   * {@inheritdoc}
   * Enhanced to cleanup facet indexes before removing main index.
   */
  public function removeIndex($index)
  {
    try {
      $this->ensureConnector();
      $index_id = is_string($index) ? $index : $index->id();

      // STEP 1: Clean up all facet indexes first.
      if (!is_string($index)) {
        $this->cleanupAllOrphanedFacetIndexes($index);
      }

      // STEP 2: Remove main index tables.
      $this->dropIndexTables($index_id);

      $this->logger->info('Successfully removed index and facet indexes for @index', ['@index' => $index_id]);
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
  public function indexItems(IndexInterface $index, array $items)
  {
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
  public function deleteItems(IndexInterface $index, array $item_ids)
  {
    if (empty($item_ids)) {
      return;
    }

    try {
      $this->ensureConnector();
      $indexManager = $this->getIndexManager();

      // Get table name using the same logic as IndexManager.
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
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = null)
  {
    try {
      $this->ensureConnector();
      // Use IndexManager for clearing items instead of connector directly.
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
  public function search(QueryInterface $query)
  {
    $this->ignored = $this->warnings = [];

    try {
      $this->ensureConnector();
      $index = $query->getIndex();
      $this->setCurrentIndexContext($index);

      $fields = $this->getFieldInfo($index);
      $fields['search_api_id'] = ['column' => 'search_api_id'];

      // Process incoming facet filters.
      $this->processIncomingFacetFilters($query);

      // Get query data (now returns array, not object)
      $db_query_data = $this->createDbQuery($query, $fields);
      $results = $query->getResults();

      // Handle result count - build count query directly.
      if (!$query->getOption('skip result count')) {
        try {
          $count_sql = preg_replace('/^SELECT .+ FROM/', 'SELECT COUNT(*) FROM', $db_query_data['sql']);
          if (!empty($db_query_data['where'])) {
            $count_sql .= " WHERE " . $db_query_data['where'];
          }

          $stmt = $this->connector->executeQuery($count_sql, $db_query_data['params']);
          $count = $stmt->fetchColumn();
          $results->setResultCount($count);
        } catch (\Exception $e) {
          $this->logger->warning('Count query failed: @message', ['@message' => $e->getMessage()]);
        }
      }

      // Build main query with sorting and pagination.
      $main_sql = $db_query_data['sql'];
      if (!empty($db_query_data['where'])) {
        $main_sql .= " WHERE " . $db_query_data['where'];
      }

      // Add sorting.
      $main_sql .= $this->buildOrderByClause($query);

      // Add pagination.
      $offset = $query->getOption('offset', 0);
      $limit = $query->getOption('limit');
      if ($limit !== null) {
        $main_sql .= " LIMIT " . (int) $limit;
      }
      if ($offset > 0) {
        $main_sql .= " OFFSET " . (int) $offset;
      }

      // Execute main query.
      $stmt = $this->connector->executeQuery($main_sql, $db_query_data['params']);

      // Process results.
      $indexed_fields = $index->getFields(true);
      $retrieved_field_names = $query->getOption('search_api_retrieved_field_values', []);

      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        if (!$row || !isset($row['item_id'])) {
          continue;
        }

        $result_obj = new \stdClass();
        $result_obj->item_id = $row['item_id'];
        $result_obj->score = ($row['score'] ?? 1000.0) / 1000;

        // Add other fields to object.
        foreach ($row as $key => $value) {
          if ($key !== 'item_id' && $key !== 'score') {
            $result_obj->$key = $value;
          }
        }

        $item = $this->getFieldsHelper()->createItem($index, $row['item_id']);
        $item->setScore($result_obj->score);
        $this->extractRetrievedFieldValuesWhereAvailable($result_obj, $indexed_fields, $retrieved_field_names, $item);
        $results->addResultItem($item);
      }

      // Process facets.
      $this->processFacets($query, $results, $db_query_data['table_name']);

      // Add warnings and ignored keys.
      foreach (['warnings', 'ignored'] as $type) {
        $method = $type === 'warnings' ? 'addWarning' : 'addIgnoredSearchKey';
        foreach (array_keys($this->$type) as $value) {
          $results->$method($value);
        }
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('Search failed on @index: @error', [
        '@index' => $index->id() ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException('Search failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Build ORDER BY clause.
   */
  protected function buildOrderByClause(QueryInterface $query)
  {
    $sorts = $query->getSorts();
    if (!$sorts) {
      if ($query->getKeys()) {
        return " ORDER BY score DESC";
      }
      return "";
    }

    $order_parts = [];
    foreach ($sorts as $field_id => $direction) {
      $direction = strtoupper($direction);

      if ($field_id === 'search_api_relevance') {
        $order_parts[] = "score {$direction}";
      } elseif ($field_id === 'search_api_id') {
        $order_parts[] = "search_api_id {$direction}";
      } else {
        $quoted_field = $this->connector->quoteColumnName($field_id);
        $order_parts[] = "{$quoted_field} {$direction}";
      }
    }

    return !empty($order_parts) ? " ORDER BY " . implode(', ', $order_parts) : "";
  }

  /**
   * Build LIMIT clause  .
   */
  protected function buildLimitClause(QueryInterface $query)
  {
    $offset = $query->getOption('offset', 0);
    $limit = $query->getOption('limit');

    $clause = "";
    if ($limit !== null) {
      $clause .= " LIMIT " . (int) $limit;
    }
    if ($offset > 0) {
      $clause .= " OFFSET " . (int) $offset;
    }

    return $clause;
  }

  /**
   * Apply sorting to query.
   */
  protected function applySorting($db_query, QueryInterface $query)
  {
    $sorts = $query->getSorts();
    if ($sorts) {
      foreach ($sorts as $field_id => $direction) {
        $direction = strtoupper($direction);

        if ($field_id === 'search_api_relevance') {
          $db_query->orderBy('score', $direction);
        } elseif ($field_id === 'search_api_id') {
          $db_query->orderBy('search_api_id', $direction);
        } else {
          $db_query->orderBy($field_id, $direction);
        }
      }
    } elseif ($query->getKeys()) {
      $db_query->orderBy('score', 'DESC');
    }
  }

  /**
   * Apply pagination and sorting using Drupal API.
   */
  protected function applyPaginationAndSorting($db_query, QueryInterface $query)
  {
    // Apply pagination.
    $offset = $query->getOption('offset', 0);
    $limit = $query->getOption('limit');
    if ($limit !== null) {
      $db_query->range($offset, $limit);
    }

    // Apply sorting.
    $sorts = $query->getSorts();
    if ($sorts) {
      foreach ($sorts as $field_id => $direction) {
        $direction = strtoupper($direction);

        if ($field_id === 'search_api_relevance') {
          $db_query->orderBy('score', $direction);
        } elseif ($field_id === 'search_api_id') {
          $db_query->orderBy('search_api_id', $direction);
        } else {
          $db_query->orderBy("t.{$field_id}", $direction);
        }
      }
    } elseif ($query->getKeys()) {
      // Default sort by relevance for searches with keys.
      $db_query->orderBy('score', 'DESC');
    }
  }

  /**
   * Execute query and process results.
   */
  protected function executeAndProcessResults($db_query, QueryInterface $query, $results, array $fields)
  {
    $result = $db_query->execute();

    if (!$result) {
      throw new SearchApiException('Query execution returned no results object');
    }

    $index = $query->getIndex();
    $indexed_fields = $index->getFields(true);
    $retrieved_field_names = $query->getOption('search_api_retrieved_field_values', []);

    foreach ($result as $row) {
      if (!$row || !isset($row->item_id)) {
        continue;
      }

      $item = $this->getFieldsHelper()->createItem($index, $row->item_id);
      // Normalize score.
      $item->setScore(($row->score ?? 1000.0) / 1000);

      $this->extractRetrievedFieldValuesWhereAvailable($row, $indexed_fields, $retrieved_field_names, $item);
      $results->addResultItem($item);
    }
  }

  /**
   * Builds secure value expression with proper parameterization.
   */
  protected function buildSecureValueExpression($field, $field_type, &$params)
  {
    $column_name = $this->connector->quoteColumnName($this->getColumnName($field->getFieldIdentifier()));

    // Detect if this is a multi-value field that uses array storage.
    if ($this->isFieldMultiValue($field) && in_array($field_type, ['integer', 'entity_reference'])) {
      // Use PostgreSQL array unnesting for facet value extraction.
      $this->logger->debug('Using unnest() for multi-value field: @field', ['@field' => $field->getFieldIdentifier()]);
      return "unnest({$column_name})";
    }

    // Handle single-value fields by type.
    switch ($field_type) {
      case 'integer':
      case 'entity_reference':
          return $column_name;

      case 'date':
          return "CASE 
          WHEN {$column_name}::text ~ ? THEN to_char(to_timestamp({$column_name}::bigint), ?)
          ELSE {$column_name}::text
        END";

      $params[] = '^[0-9]+$';
      $params[] = 'YYYY-MM-DD';
      break;

      case 'boolean':
          return "CASE WHEN {$column_name}::text = ANY(?) THEN ? ELSE ? END";

      $params[] = ['1', 'true', 't'];
      $params[] = 'true';
      $params[] = 'false';
      break;

      case 'decimal':
          return "{$column_name}::text";

      default:
          return $column_name;
    }
  }

  /**
   * Validates facet configuration structure.
   */
  protected function validateFacetConfig($facet_id, $facet_config, IndexInterface $index)
  {
    try {
      if (!is_array($facet_config)) {
        $this->logger->warning('Invalid facet configuration for @facet: not an array', ['@facet' => $facet_id]);
        // Don't throw - just skip this facet.
        return false;
      }

      // Validate required 'field' parameter.
      if (!isset($facet_config['field']) || !is_string($facet_config['field']) || empty($facet_config['field'])) {
        $this->logger->warning(
            'Invalid facet configuration for @facet: missing or invalid field parameter',
            ['@facet' => $facet_id]
        );
        return false;
      }

      // Validate field exists in index.
      $fields = $index->getFields();
      if (!isset($fields[$facet_config['field']])) {
        $this->logger->warning('Invalid facet configuration for @facet: field @field does not exist in index', [
          '@facet' => $facet_id,
          '@field' => $facet_config['field'],
        ]);
        return false;
      }

      // Validate optional parameters with reasonable bounds.
      if (isset($facet_config['limit'])) {
        if (!is_int($facet_config['limit']) || $facet_config['limit'] < 0 || $facet_config['limit'] > 10000) {
          $this->logger->warning('Invalid limit @limit for facet @facet, using default', [
            '@limit' => $facet_config['limit'],
            '@facet' => $facet_id,
          ]);
          // Set reasonable default.
          $facet_config['limit'] = 50;
        }
      }

      if (isset($facet_config['min_count'])) {
        if (!is_int($facet_config['min_count']) || $facet_config['min_count'] < 0) {
          // Set reasonable default.
          $facet_config['min_count'] = 1;
        }
      }

      if (isset($facet_config['operator'])) {
        if (!in_array($facet_config['operator'], ['and', 'or'], true)) {
          // Set reasonable default.
          $facet_config['operator'] = 'and';
        }
      }

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Error validating facet config for @facet: @error', [
        '@facet' => $facet_id,
        '@error' => $e->getMessage(),
      ]);
      // Skip problematic facets rather than breaking search.
      return false;
    }
  }

  /**
   * Builds secure facet query with full parameterization.
   */
  protected function buildSecureFacetQuery(
      QueryInterface $query,
      $table_name,
      $field_name,
      $field,
      $limit,
      $min_count,
      $missing
  ) {
    $params = [];

    // Get secure value expression.
    $value_expression = $this->buildSecureValueExpression($field, $field->getType(), $params);

    // Build WHERE conditions securely.
    $where_conditions = $this->buildSecureNonFacetWhereConditions($query, $table_name, $field_name);
    $where_clauses = $where_conditions['clauses'];
    $params = array_merge($params, $where_conditions['params']);

    // Handle missing values securely.
    $column_name = $this->connector->quoteColumnName($this->getColumnName($field_name));
    if (!$missing) {
      $where_clauses[] = "{$column_name} IS NOT NULL";
    }

    $where_clause = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Build final SQL with all parameters.
    $sql = "
      SELECT 
        {$value_expression} as value,
        COUNT(*) as count,
        ({$column_name} IS NULL)::int as is_missing
      FROM " . $this->connector->quoteTableName($table_name) . "
      {$where_clause}
      GROUP BY {$value_expression}, ({$column_name} IS NULL)
      HAVING COUNT(*) >= ?
      ORDER BY count DESC, {$value_expression} ASC
    ";

    $params[] = $min_count;

    if ($limit > 0) {
      $sql .= " LIMIT ?";
      $params[] = $limit;
    }

    return [
      'sql' => $sql,
      'params' => $params,
    ];
  }

  /**
   * Builds WHERE conditions excluding the current facet field - SECURE VERSION.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param string $table_name
   *   The database table name.
   * @param string $exclude_field
   *   The field to exclude from conditions.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array with 'clauses' and 'params' keys.
   */
  protected function buildSecureNonFacetWhereConditions(QueryInterface $query, $table_name, $exclude_field)
  {
    $where_clauses = [];
    $params = [];

    // Add fulltext search conditions if keys exist.
    $keys = $query->getKeys();
    if ($keys) {
      $processed_keys = $this->processSearchKeys(is_string($keys) ? $keys : $this->extractTextFromKeys($keys));
      if (!empty(trim($processed_keys))) {
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';

        // Validate FTS config against allowed values.
        $allowed_configs = ['simple', 'english', 'spanish', 'french', 'german'];
        if (!in_array($fts_config, $allowed_configs)) {
          $fts_config = 'english';
        }

        $where_clauses[] = "search_vector @@ to_tsquery(?, ?)";
        $params[] = $fts_config;
        $params[] = $processed_keys;
      }
    }

    // Add filter conditions with complex exclusion.
    $conditions = $query->getConditionGroup();
    $this->addSecureConditionExclusion($conditions, $where_clauses, $params, $exclude_field);

    return [
      'clauses' => $where_clauses,
      'params' => $params,
    ];
  }

  /**
   * Securely handles condition exclusion with proper parameterization.
   */
  protected function addSecureConditionExclusion($condition_group, &$where_clauses, &$params, $exclude_field)
  {
    $conjunction = $condition_group->getConjunction();
    $group_clauses = [];

    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_clauses = [];
        $nested_params = [];
        $this->addSecureConditionExclusion($condition, $nested_clauses, $nested_params, $exclude_field);

        if (!empty($nested_clauses)) {
          $nested_conjunction = $condition->getConjunction();
          $connector = $nested_conjunction === 'OR' ? ' OR ' : ' AND ';
          $group_clauses[] = '(' . implode($connector, $nested_clauses) . ')';
          $params = array_merge($params, $nested_params);
        }
      } else {
        $field = $condition->getField();

        // Skip conditions for excluded field and related fields.
        if ($this->shouldExcludeCondition($field, $exclude_field, $condition)) {
          continue;
        }

        $condition_sql = $this->buildParameterizedConditionSql(
            $field,
            $condition->getValue(),
            $condition->getOperator(),
            $params
        );
        if ($condition_sql) {
          $group_clauses[] = $condition_sql;
        }
      }
    }

    if (!empty($group_clauses)) {
      $connector = $conjunction === 'OR' ? ' OR ' : ' AND ';
      $where_clauses[] = implode($connector, $group_clauses);
    }
  }

  /**
   * Simplified facet processing using Drupal database API.
   */
  protected function processFacets(QueryInterface $query, $results, $table_name)
  {
    $facets_option = $query->getOption('search_api_facets');

    if (!$facets_option || !is_array($facets_option)) {
      $this->logger->debug('No facets requested');
      return;
    }

    $this->logger->debug('Processing @count facets: @facets', [
      '@count' => count($facets_option),
      '@facets' => array_keys($facets_option),
    ]);

    $index = $query->getIndex();
    $facet_results = [];

    foreach ($facets_option as $facet_id => $facet_config) {
      $this->logger->debug('Processing facet @facet with config: @config', [
        '@facet' => $facet_id,
        '@config' => print_r($facet_config, true),
      ]);

      if (!$this->validateFacetConfig($facet_id, $facet_config, $index)) {
        $this->logger->warning('Facet validation failed for @facet', ['@facet' => $facet_id]);
        continue;
      }

      $field_name = $facet_config['field'];
      $limit = $facet_config['limit'] ?? 50;
      $min_count = $facet_config['min_count'] ?? 1;
      $missing = $facet_config['missing'] ?? false;

      try {
        $facet_values = $this->buildAndExecuteSimpleFacetQuery(
            $query,
            $table_name,
            $field_name,
            $limit,
            $min_count,
            $missing
        );

        $this->logger->debug('Facet @facet returned @count values', [
          '@facet' => $facet_id,
          '@count' => count($facet_values),
        ]);

        if (!empty($facet_values)) {
          $facet_results[$facet_id] = $facet_values;
        }
      } catch (\Exception $e) {
        $this->logger->error('Error processing facet @facet: @error', [
          '@facet' => $facet_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    if (!empty($facet_results)) {
      $results->setExtraData('search_api_facets', $facet_results);
      $this->logger->debug('Set facet results: @results', [
        '@results' => array_keys($facet_results),
      ]);
    } else {
      $this->logger->warning('No facet results to set');
    }
  }

  /**
   * Format facet filters for text fields.
   */
  protected function formatTextFacetFilter($value, $is_missing = false)
  {
    // Handle missing values.
    if ($is_missing || $value === null || $value === '') {
      return '!';
    }

    // Escape quotes and special characters in string values.
    $escaped_value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
    return '"' . $escaped_value . '"';
  }

  /**
   * Simple facet query builder that works with both single values and arrays.
   */
  protected function buildAndExecuteSimpleFacetQuery(
      QueryInterface $query,
      $table_name,
      $field_name,
      $limit,
      $min_count,
      $missing
  ) {
    $index = $query->getIndex();
    $fields = $index->getFields();
    $field = $fields[$field_name] ?? null;

    if (!$field) {
      $this->logger->error('Field @field not found in index for facet query', ['@field' => $field_name]);
      return [];
    }

    $field_type = $field->getType();
    $is_multi_value = $this->isFieldMultiValue($field);
    $is_array_field = $is_multi_value && in_array($field_type, ['integer', 'entity_reference', 'text', 'string']);

    $quoted_field = $this->connector->quoteColumnName($field_name);
    $params = [];

    // Build appropriate value expression.
    if ($is_array_field) {
      $value_expression = "unnest({$quoted_field})";
    } else {
      $value_expression = $quoted_field;
    }

    // Build WHERE conditions excluding current facet filters.
    $where_conditions = $this->buildBaseFacetWhereConditions($query, $field_name, $params);
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Handle missing values.
    if (!$missing) {
      if ($is_array_field) {
        $not_null_condition = "{$quoted_field} IS NOT NULL AND array_length({$quoted_field}, 1) > 0";
      } elseif ($field_type === 'entity_reference' || $field_type === 'integer') {
        // For single-value entity references/integers: not null and not zero.
        $not_null_condition = "{$quoted_field} IS NOT NULL AND {$quoted_field} > 0";
      } else {
        // For other single values.
        $not_null_condition = "{$quoted_field} IS NOT NULL AND {$quoted_field} != ''";
      }

      if (!empty($where_conditions)) {
        $where_clause .= " AND {$not_null_condition}";
      } else {
        $where_clause = "WHERE {$not_null_condition}";
      }
    }

    // Build the facet query.
    $sql = "
      SELECT 
        {$value_expression} as value,
        COUNT(*) as count
      FROM {$table_name}
      {$where_clause}
      GROUP BY {$value_expression}
      HAVING COUNT(*) >= ?
      ORDER BY count DESC, {$value_expression} ASC
    ";

    $params[] = $min_count;

    if ($limit > 0) {
      $sql .= " LIMIT ?";
      $params[] = $limit;
    }

    // Execute the query.
    $stmt = $this->connector->executeQuery($sql, $params);

    $facet_values = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $value = $row['value'];
      $count = (int) $row['count'];

      // Skip null, zero, or empty values unless missing is enabled.
      if (($value === null || $value === 0 || $value === '') && !$missing) {
        continue;
      }

      // CONSISTENT: Always use the main formatFacetFilter method.
      $filter = $this->formatFacetFilter($value, $missing, $field_type);

      $facet_values[] = [
        'filter' => $filter,
        'count' => $count,
      ];

      // Debug logging for entity reference fields.
      if ($field_type === 'entity_reference') {
        $this->logger->debug('Entity ref facet value: @value -> filter: @filter', [
          '@value' => $value,
          '@filter' => $filter,
        ]);
      }
    }

    return $facet_values;
  }

  /**
   * Build base WHERE conditions for facet queries.
   */
  protected function buildBaseFacetWhereConditions(QueryInterface $query, $exclude_field, &$params)
  {
    $where_clauses = [];

    // Add fulltext search conditions.
    $keys = $query->getKeys();
    if ($keys && $this->hasValidSearchKeys($keys)) {
      $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
      $processed_keys = $this->processSearchKeys($search_text);

      if (!empty(trim($processed_keys))) {
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';
        $where_clauses[] = "search_vector @@ to_tsquery(?, ?)";
        $params[] = $fts_config;
        $params[] = $processed_keys;
      }
    }

    // Add filter conditions excluding current facet field.
    $conditions = $query->getConditionGroup();
    if ($conditions && count($conditions->getConditions()) > 0) {
      $condition_sql = $this->buildFacetConditionGroup($conditions, $exclude_field, $params);
      if ($condition_sql) {
        $where_clauses[] = $condition_sql;
      }
    }

    return $where_clauses;
  }

  /**
   * Build condition groups for facet queries.
   */
  protected function buildFacetConditionGroup($condition_group, $exclude_field, &$params)
  {
    $conditions = $condition_group->getConditions();

    if (empty($conditions)) {
      return null;
    }

    $conjunction = $condition_group->getConjunction();
    $sql_conditions = [];

    foreach ($conditions as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_sql = $this->buildFacetConditionGroup($condition, $exclude_field, $params);
        if ($nested_sql) {
          $sql_conditions[] = "({$nested_sql})";
        }
      } else {
        $field = $condition->getField();

        // Skip conditions for the excluded facet field.
        if ($field === $exclude_field) {
          continue;
        }

        $value = $condition->getValue();
        $operator = $condition->getOperator();

        $condition_sql = $this->buildSimpleConditionSql($field, $value, $operator, $params);
        if ($condition_sql) {
          $sql_conditions[] = $condition_sql;
        }
      }
    }

    if (empty($sql_conditions)) {
      return null;
    }

    $conjunction_sql = $conjunction === 'OR' ? ' OR ' : ' AND ';
    return implode($conjunction_sql, $sql_conditions);
  }

  /**
   * Build simple condition SQL for facet queries - handles both single values and arrays.
   */
  protected function buildSimpleConditionSql($field, $value, $operator, &$params)
  {
    $quoted_field = $this->connector->quoteColumnName($field);

    // Get field definition to determine if it's an array field.
    $index = $this->currentIndex;
    $is_array_field = false;

    if ($index) {
      $fields = $index->getFields();
      if (isset($fields[$field])) {
        $field_obj = $fields[$field];
        $is_array_field = $this->isFieldMultiValue($field_obj) &&
                in_array($field_obj->getType(), ['integer', 'entity_reference']);
      }
    }

    switch ($operator) {
      case '=':
        if ($is_array_field) {
          // For array fields, use ANY operator.
          $params[] = (int) $value;
          return "? = ANY({$quoted_field})";
        } else {
          // For single-value fields, use direct comparison.
          $params[] = $value;
          return "{$quoted_field} = ?";
        }

      case 'IN':
        if (is_array($value) && !empty($value)) {
          if ($is_array_field) {
            // For array fields with multiple values, use overlap operator.
            $array_literal = '{' . implode(',', array_map('intval', $value)) . '}';
            $params[] = $array_literal;
            return "{$quoted_field} && ?::integer[]";
          } else {
            // For single-value fields, use standard IN.
            $placeholders = str_repeat('?,', count($value) - 1) . '?';
            $params = array_merge($params, $value);
            return "{$quoted_field} IN ({$placeholders})";
          }
        }
          return '1=0';

      case 'IS NULL':
        if ($is_array_field) {
          return "({$quoted_field} IS NULL OR array_length({$quoted_field}, 1) = 0)";
        } else {
          return "{$quoted_field} IS NULL";
        }

      case 'IS NOT NULL':
        if ($is_array_field) {
          return "({$quoted_field} IS NOT NULL AND array_length({$quoted_field}, 1) > 0)";
        } else {
          return "{$quoted_field} IS NOT NULL";
        }

      case '>':
        $params[] = $value;
          return "{$quoted_field} > ?";

      case '>=':
        $params[] = $value;
          return "{$quoted_field} >= ?";

      case '<':
        $params[] = $value;
          return "{$quoted_field} < ?";

      case '<=':
        $params[] = $value;
          return "{$quoted_field} <= ?";

      case '!=':
      case '<>':
        if ($is_array_field) {
          // For array fields, check value is not in array.
          $params[] = (int) $value;
          return "NOT (? = ANY({$quoted_field}))";
        } else {
          $params[] = $value;
          return "{$quoted_field} <> ?";
        }

      default:
        if ($is_array_field) {
          $params[] = (int) $value;
          return "? = ANY({$quoted_field})";
        } else {
          $params[] = $value;
          return "{$quoted_field} = ?";
        }
    }
  }

  /**
   * Build and execute facet query using Drupal database API.
   */
  protected function buildAndExecuteFacetQuery(QueryInterface $query, $table_name, $facet_config, IndexInterface $index)
  {
    $field_name = $facet_config['field'];
    $limit = $facet_config['limit'] ?? 50;
    $min_count = $facet_config['min_count'] ?? 1;
    $missing = $facet_config['missing'] ?? false;

    $field = $index->getFields()[$field_name] ?? null;
    if (!$field) {
      return [];
    }

    // Build secure facet query.
    $facet_query_data = $this->buildSecureFacetQueryNew(
        $query,
        $table_name,
        $field_name,
        $field,
        $limit,
        $min_count,
        $missing
    );

    // Execute using YOUR connector.
    $stmt = $this->connector->executeQuery($facet_query_data['sql'], $facet_query_data['params']);

    $facet_values = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
      $facet_values[] = [
        'filter' => $this->formatFacetFilter($row['value'], (bool) ($row['is_missing'] ?? false), $field->getType()),
        'count' => (int) $row['count'],
      ];
    }

    return $facet_values;
  }

  /**
   * Build secure facet query with proper table name handling.
   */
  protected function buildSecureFacetQueryNew(
      QueryInterface $query,
      $table_name,
      $field_name,
      $field,
      $limit,
      $min_count,
      $missing
  ) {
    $params = [];

    // Get proper table name (already quoted)
    // This comes from getIndexTableNameForManager()
    $quoted_table = $table_name;
    $quoted_field = $this->connector->quoteColumnName($field_name);

    // Build value expression for multi-value fields.
    $value_expression = $this->buildSecureValueExpression($field, $field->getType(), $params);

    // Build WHERE conditions excluding current facet.
    $where_conditions = $this->buildNonFacetWhereConditions($query, $field_name);
    $where_clauses = $where_conditions['clauses'];
    $params = array_merge($params, $where_conditions['params']);

    // Handle missing values.
    if (!$missing) {
      $where_clauses[] = "{$quoted_field} IS NOT NULL";
    }

    $where_clause = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $sql = "
      SELECT 
        {$value_expression} as value,
        COUNT(*) as count,
        ({$quoted_field} IS NULL)::int as is_missing
      FROM {$quoted_table}
      {$where_clause}
      GROUP BY {$value_expression}, ({$quoted_field} IS NULL)
      HAVING COUNT(*) >= ?
      ORDER BY count DESC, {$value_expression} ASC
    ";

    $params[] = $min_count;

    if ($limit > 0) {
      $sql .= " LIMIT ?";
      $params[] = $limit;
    }

    return [
      'sql' => $sql,
      'params' => $params,
    ];
  }

  /**
   * Build non-facet WHERE conditions using YOUR connector.
   */
  protected function buildNonFacetWhereConditions(QueryInterface $query, $exclude_field)
  {
    $where_clauses = [];
    $params = [];

    // Add fulltext search conditions.
    $keys = $query->getKeys();
    if ($keys && $this->hasValidSearchKeys($keys)) {
      $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
      $processed_keys = $this->processSearchKeys($search_text);

      if (!empty(trim($processed_keys))) {
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';
        $where_clauses[] = "search_vector @@ to_tsquery(?, ?)";
        $params[] = $fts_config;
        $params[] = $processed_keys;
      }
    }

    // Add filter conditions.
    $conditions = $query->getConditionGroup();
    if ($conditions) {
      $condition_sql = $this->buildFilteredConditionGroupSql($conditions, $exclude_field, $params);
      if ($condition_sql) {
        $where_clauses[] = $condition_sql;
      }
    }

    return [
      'clauses' => $where_clauses,
      'params' => $params,
    ];
  }

  /**
   * Build condition group SQL excluding specific field.
   */
  protected function buildFilteredConditionGroupSql($condition_group, $exclude_field, &$params)
  {
    $conjunction = $condition_group->getConjunction();
    $group_clauses = [];

    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_sql = $this->buildFilteredConditionGroupSql($condition, $exclude_field, $params);
        if ($nested_sql) {
          $nested_conjunction = $condition->getConjunction();
          $connector = $nested_conjunction === 'OR' ? ' OR ' : ' AND ';
          $group_clauses[] = '(' . $nested_sql . ')';
        }
      } else {
        $field = $condition->getField();

        if ($this->shouldExcludeCondition($field, $exclude_field, $condition)) {
          continue;
        }

        $condition_sql = $this->buildSecureConditionSql(
            $field,
            $condition->getValue(),
            $condition->getOperator(),
            $params
        );
        if ($condition_sql) {
          $group_clauses[] = $condition_sql;
        }
      }
    }

    if (!empty($group_clauses)) {
      $connector = $conjunction === 'OR' ? ' OR ' : ' AND ';
      return implode($connector, $group_clauses);
    }

    return null;
  }

  /**
   * Handle multi-value field selection for facets.
   */
  protected function addMultiValueFacetSelect($facet_query, $field_name, $field)
  {
    $storage_format = $this->detectMultiValueStorageFormat($field);

    switch ($storage_format) {
      case 'array':
        $facet_query->addExpression("unnest(t.{$field_name})", 'value');
          break;

      case 'json':
        $facet_query->addExpression("json_array_elements_text(t.{$field_name}::json)", 'value');
          break;

      case 'separated':
        $separator = $this->getMultiValueSeparator($field);
        $facet_query->addExpression("unnest(string_to_array(nullif(t.{$field_name}, ''), :separator))", 'value', [
          ':separator' => $separator,
        ]);
          break;

      default:
        $facet_query->addField('t', $field_name, 'value');
    }
  }

  /**
   * Add non-facet conditions to facet query.
   */
  protected function addNonFacetConditions($facet_query, QueryInterface $query, $exclude_field)
  {
    // Add fulltext search conditions.
    $keys = $query->getKeys();
    if ($keys && $this->hasValidSearchKeys($keys)) {
      $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
      $processed_keys = $this->processSearchKeys($search_text);

      if (!empty(trim($processed_keys))) {
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';
        $facet_query->where('search_vector @@ to_tsquery(:config, :keys)', [
          ':config' => $fts_config,
          ':keys' => $processed_keys,
        ]);
      }
    }

    // Add filter conditions excluding current facet field.
    $condition_group = $query->getConditionGroup();
    if ($condition_group) {
      $this->addFilteredConditionGroup($facet_query, $condition_group, $exclude_field);
    }
  }

  /**
   * Add condition group while excluding specific field.
   */
  protected function addFilteredConditionGroup($facet_query, $condition_group, $exclude_field)
  {
    $conjunction = $condition_group->getConjunction();
    $drupal_group = $conjunction === 'OR' ? $facet_query->orConditionGroup() : $facet_query->andConditionGroup();

    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_group = $conjunction === 'OR' ?
                \Drupal::database()->orConditionGroup() :
                \Drupal::database()->andConditionGroup();
        $this->addFilteredConditionGroup($nested_group, $condition, $exclude_field);
        if (count($nested_group->getConditions()) > 0) {
          $drupal_group->condition($nested_group);
        }
      } else {
        $field = $condition->getField();

        // Skip excluded field and related fields.
        if ($this->shouldExcludeCondition($field, $exclude_field, $condition)) {
          continue;
        }

        $this->addSingleCondition($drupal_group, $condition, []);
      }
    }

    if (count($drupal_group->getConditions()) > 0) {
      $facet_query->condition($drupal_group);
    }
  }

  /**
   * Ensures facet indexes exist with intelligent lazy creation.
   */
  protected function ensureFacetIndexesLazily($table_name, array $facets_option, IndexInterface $index)
  {
    static $processed_indexes = [];
    static $pending_indexes = [];

    $index_id = $index->id();

    foreach ($facets_option as $facet_config) {
      $field_name = $facet_config['field'];
      $cache_key = "{$index_id}:{$field_name}";

      // Skip if already processed in this request.
      if (isset($processed_indexes[$cache_key])) {
        continue;
      }

      // Check if index exists (fast cache check only)
      if ($this->facetIndexExistsWithCache($table_name, $field_name)) {
        $processed_indexes[$cache_key] = true;
        continue;
      }

      // Index creation to be queued for background creation.
      if (!isset($pending_indexes[$cache_key])) {
        $this->queueFacetIndexCreation($table_name, $field_name, $index);
        $pending_indexes[$cache_key] = true;

        $this->logger->info('Queued facet index creation for @field (will create in background)', [
          '@field' => $field_name,
        ]);
      }

      $processed_indexes[$cache_key] = true;
    }
  }

  /**
   * Queue facet index for background creation.
   */
  protected function queueFacetIndexCreation($table_name, $field_name, IndexInterface $index)
  {
    try {
      // Use Drupal's queue system for background processing.
      $queue = \Drupal::queue('search_api_postgresql_facet_indexes');
      $item = [
        'table_name' => $table_name,
        'field_name' => $field_name,
        'index_id' => $index->id(),
        'server_id' => $this->getServerId(),
        'created' => time(),
      ];

      // Only queue if not already queued recently.
      $cache_key = "facet_queue:{$index->id()}:{$field_name}";
      $cached = \Drupal::cache()->get($cache_key);

      // 5 minutes
      if (!$cached || (time() - $cached->data) > 300) {
        $queue->createItem($item);
        // Cache for 1 hour.
        \Drupal::cache()->set($cache_key, time(), time() + 3600);
      }
    } catch (\Exception $e) {
      $this->logger->warning('Failed to queue facet index creation: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Check facet index existence with static caching.
   */
  protected function facetIndexExistsWithCache($table_name, $field_name)
  {
    $unquoted_table = $this->getUnquotedTableName($table_name);
    $cache_key = 'search_api_postgresql:facet_index:' . md5($unquoted_table . ':' . $field_name);
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    try {
      $index_name = $unquoted_table . '_' . $field_name . '_facet_idx';
      $sql = "SELECT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE tablename = ? AND indexname = ?
      )";

      $stmt = $this->connector->connect()->prepare($sql);
      $stmt->execute([$unquoted_table, $index_name]);
      $exists = (bool) $stmt->fetchColumn();

      \Drupal::cache()->set($cache_key, $exists, \Drupal::time()->getRequestTime() + 1800);
      return $exists;
    } catch (\Exception $e) {
      $this->logger->debug('Index existence check failed: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Use Drupal database API for facet index creation.
   */
  public function createOptimalFacetIndex($table_name, $field_name, IndexInterface $index)
  {
    $field = $index->getFields()[$field_name] ?? null;
    if (!$field || !$this->shouldCreateFacetIndex($field, $table_name)) {
      return;
    }

    $start_time = microtime(true);
    $max_execution_time = 30;

    try {
      $database = \Drupal::database();
      $unquoted_table = $this->getUnquotedTableName($table_name);
      $index_name = $unquoted_table . '_' . $field_name . '_facet_idx';

      // Check if index already exists.
      if ($database->schema()->indexExists($unquoted_table, $index_name)) {
        return;
      }

      $strategy = $this->determineFacetIndexStrategy($field, $table_name, $field_name);

      // Set timeout before creating index.
      $database->query("SET statement_timeout = '30s'");

      // Use appropriate index creation strategy.
      $this->createFacetIndexByStrategy($database, $unquoted_table, $field_name, $index_name, $strategy);

      $elapsed = microtime(true) - $start_time;
      $this->logger->info('Created facet index @index for field @field in @seconds seconds', [
        '@index' => $index_name,
        '@field' => $field_name,
        '@seconds' => round($elapsed, 2),
      ]);
    } catch (\Exception $e) {
      $elapsed = microtime(true) - $start_time;

      if ($elapsed > $max_execution_time) {
        $this->logger->warning('Facet index creation timed out for @field after @seconds seconds', [
          '@field' => $field_name,
          '@seconds' => round($elapsed, 2),
        ]);
      } else {
        $this->logger->notice('Could not create facet index for @field: @error', [
          '@field' => $field_name,
          '@error' => $e->getMessage(),
        ]);
      }
    } finally {
      try {
        $database->query("RESET statement_timeout");
      } catch (\Exception $e) {
        // Ignore reset errors.
      }
    }
  }

  /**
   * Create facet index based on strategy.
   */
  protected function createFacetIndexByStrategy($database, $table_name, $field_name, $index_name, $strategy)
  {
    switch ($strategy) {
      case 'gin_array':
        $sql = "CREATE INDEX CONCURRENTLY {$index_name} ON {$table_name} USING gin ({$field_name})";
          break;

      case 'partial':
        $sql = "CREATE INDEX CONCURRENTLY {$index_name} ON {$table_name} " .
               "({$field_name}) WHERE {$field_name} IS NOT NULL";
          break;

      case 'hash':
        $sql = "CREATE INDEX CONCURRENTLY {$index_name} ON {$table_name} " .
               "USING hash ({$field_name}) WHERE {$field_name} IS NOT NULL";
          break;

      default:
        $sql = "CREATE INDEX CONCURRENTLY {$index_name} ON {$table_name} ({$field_name})";
    }

    $database->query($sql);
  }

  /**
   * Determines optimal indexing strategy for a facet field.
   */
  protected function determineFacetIndexStrategy($field, $table_name, $field_name)
  {
    $field_type = $field->getType();

    // Multi-value fields need GIN indexes.
    if ($this->isFieldMultiValue($field)) {
      return 'gin_array';
    }

    // For taxonomy/reference fields (common in document management)
    if ($field_type === 'integer' && (strpos($field_name, 'taxonomy') !== false ||
                                strpos($field_name, 'members') !== false)
      ) {
      // B-tree works well for taxonomy IDs.
      return 'standard';
    }

    // For high-cardinality string fields.
    if (in_array($field_type, ['string', 'text'])) {
      $stats = $this->getFieldStatistics($table_name, $field_name);

      if ($stats['distinctness'] > 0.8) {
        // Hash index for very high cardinality.
        return 'hash';
      } else {
        // Partial index excluding nulls.
        return 'partial';
      }
    }

    return 'standard';
  }

  /**
   * Quick field statistics for index decision making.
   */
  protected function getFieldStatistics($table_name, $field_name)
  {
    $cache_key = 'search_api_postgresql:field_stats:' . md5($table_name . ':' . $field_name);
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    try {
      $database = \Drupal::database();
      $unquoted_table = $this->getUnquotedTableName($table_name);

      $subquery = $database->select($unquoted_table, 't')
        ->fields('t', [$field_name])
        ->condition($field_name, null, 'IS NOT NULL')
        ->range(0, 1000);

      $query = $database->select($subquery, 'sample');
      $query->addExpression('COUNT(DISTINCT ' . $field_name . ')::float / GREATEST(COUNT(*), 1)', 'distinctness');
      $query->addExpression('COUNT(*)', 'total_rows');
      $query->addExpression('AVG(LENGTH(' . $field_name . '::text))', 'avg_length');

      $result = $query->execute()->fetchAssoc();

      $stats = [
        'distinctness' => (float) ($result['distinctness'] ?? 0.5),
        'total_rows' => (int) ($result['total_rows'] ?? 0),
        'avg_length' => (float) ($result['avg_length'] ?? 0),
      ];

      \Drupal::cache()->set($cache_key, $stats, \Drupal::time()->getRequestTime() + 1800);
      return $stats;
    } catch (\Exception $e) {
      $default_stats = ['distinctness' => 0.5, 'total_rows' => 0, 'avg_length' => 0];
      \Drupal::cache()->set($cache_key, $default_stats, \Drupal::time()->getRequestTime() + 300);
      return $default_stats;
    }
  }

  /**
   * Determines if facet index should be created for this field.
   */
  protected function shouldCreateFacetIndex($field, $table_name)
  {
    $field_type = $field->getType();

    // Skip text fields that are too large.
    if ($field_type === 'text') {
      $stats = $this->getFieldStatistics($table_name, $field->getFieldIdentifier());
      if ($stats['avg_length'] > 200) {
        // Too large for effective faceting.
        return false;
      }
    }

    // Skip if table is very small.
    $row_count = $this->getQuickRowCount($table_name);
    if ($row_count < 500) {
      // Not worth indexing small tables.
      return false;
    }

    return true;
  }

  /**
   * Cleans up a specific facet index.
   */
  public function cleanupFacetIndex($table_name, $field_name, IndexInterface $index)
  {
    try {
      $unquoted_table = $this->getUnquotedTableName($table_name);
      $index_name = $unquoted_table . '_' . $field_name . '_facet_idx';

      // Verify the index exists before dropping.
      if ($this->facetIndexExistsWithCache($table_name, $field_name)) {
        $quoted_index_name = $this->connector->quoteIndexName($index_name);

        // Use DROP INDEX CONCURRENTLY to avoid locking.
        $sql = "DROP INDEX CONCURRENTLY IF EXISTS {$quoted_index_name}";
        $this->connector->executeQuery($sql);

        // Clear static cache.
        $this->invalidateFacetIndexCache($index_name);

        $this->logger->info('Dropped facet index @index for field @field', [
          '@index' => $index_name,
          '@field' => $field_name,
        ]);

        return true;
      }
    } catch (\Exception $e) {
      $this->logger->warning('Failed to cleanup facet index for field @field: @error', [
        '@field' => $field_name,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }

    return false;
  }

  /**
   * Batch cleanup of all orphaned facet indexes for an index.
   */
  public function cleanupAllOrphanedFacetIndexes(IndexInterface $index)
  {
    try {
      $table_name = $this->getIndexTableNameForManager($index);
      $unquoted_table = $this->getUnquotedTableName($table_name);

      // Get all existing facet indexes.
      $existing_indexes = $this->getAllFacetIndexes($unquoted_table);

      // Get all fields used by active facets.
      $active_facet_fields = $this->getActiveFacetFields($index);

      $cleaned_count = 0;

      foreach ($existing_indexes as $index_info) {
        $field_name = $this->extractFieldNameFromIndex($index_info['indexname']);

        if ($field_name && !in_array($field_name, $active_facet_fields)) {
          // Orphaned index - remove it.
          if ($this->dropSingleFacetIndex($index_info['indexname'])) {
            $cleaned_count++;

            $this->logger->info('Removed orphaned facet index @index (field: @field)', [
              '@index' => $index_info['indexname'],
              '@field' => $field_name,
            ]);
          }
        }
      }

      return $cleaned_count;
    } catch (\Exception $e) {
      $this->logger->error('Failed to cleanup orphaned facet indexes for @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets all facet-related indexes for a table.
   */
  protected function getAllFacetIndexes($unquoted_table_name)
  {
    $sql = "SELECT 
      indexname, 
      tablename,
      indexdef
    FROM pg_indexes 
    WHERE tablename = ? 
    AND (indexname LIKE '%_facet_idx' OR indexname LIKE '%_nn_idx')
    ORDER BY indexname";

    $stmt = $this->connector->connect()->prepare($sql);
    $stmt->execute([$unquoted_table_name]);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Remove static cache from getActiveFacetFields.
   */
  protected function getActiveFacetFields(IndexInterface $index)
  {
    $cache_key = 'search_api_postgresql:active_facets:' . $index->id();
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    $active_fields = [];

    // Check via facet entities.
    try {
      if (\Drupal::moduleHandler()->moduleExists('facets')) {
        $facet_storage = \Drupal::entityTypeManager()->getStorage('facet');

        $possible_sources = [
          'search_api:' . $index->id(),
          'search_api:views_page__' . $index->id() . '__page_1',
          'search_api:views_block__' . $index->id() . '__block_1',
        ];

        foreach ($possible_sources as $source_id) {
          $facets = $facet_storage->loadByProperties([
            'facet_source_id' => $source_id,
            'status' => true,
          ]);

          foreach ($facets as $facet) {
            $active_fields[] = $facet->getFieldIdentifier();
          }
        }
      }
    } catch (\Exception $e) {
      $this->logger->debug('Could not load facets via entity storage: @error', ['@error' => $e->getMessage()]);
    }

    $unique_fields = array_unique($active_fields);
    \Drupal::cache()->set($cache_key, $unique_fields, \Drupal::time()->getRequestTime() + 1800);

    return $unique_fields;
  }

  /**
   * Standardized error handling method.
   */
  protected function handleBackendError(\Exception $e, string $operation, array $context = [])
  {
    $this->logger->error('PostgreSQL @operation failed: @error', [
      '@operation' => $operation,
      '@error' => $e->getMessage(),
    ] + $context);

    throw new SearchApiException("Failed to {$operation}: " . $e->getMessage(), 0, $e);
  }

  /**
   * Extracts field name from facet index name.
   */
  protected function extractFieldNameFromIndex($index_name)
  {
    // Patterns to match:
    // table_prefix_FIELD_NAME_facet_idx
    // table_prefix_FIELD_NAME_nn_idx (partial indexes)
    $patterns = [
    // Standard facet index.
      '/.+_(.+?)_facet_idx$/',
    // Non-null partial index.
      '/.+_(.+?)_nn_idx$/',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $index_name, $matches)) {
        return $matches[1];
      }
    }

    return null;
  }

  /**
   * Drops a single facet index by name.
   */
  protected function dropSingleFacetIndex($index_name)
  {
    try {
      $quoted_index_name = $this->connector->quoteIndexName($index_name);
      $sql = "DROP INDEX CONCURRENTLY IF EXISTS {$quoted_index_name}";
      $this->connector->executeQuery($sql);

      // Clear cache.
      $this->invalidateFacetIndexCache($index_name);

      return true;
    } catch (\Exception $e) {
      $this->logger->warning('Failed to drop facet index @index: @error', [
        '@index' => $index_name,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Invalidates facet index cache.
   */
  protected function invalidateFacetIndexCache($index_name)
  {
    // Clear Drupal cache tags.
    \Drupal::service('cache_tags.invalidator')->invalidateTags([
      'search_api_postgresql_facet_indexes',
      'search_api_postgresql_index:' . $index_name,
    ]);
  }

  /**
   * Quick row count estimate.
   */
  protected function getQuickRowCount($table_name)
  {
    $cache_key = 'search_api_postgresql:row_count:' . md5($table_name);
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    try {
      $database = \Drupal::database();
      $unquoted_table = $this->getUnquotedTableName($table_name);

      $query = $database->select('pg_class', 'c')
        ->fields('c', ['reltuples'])
        ->condition('relname', $unquoted_table);

      $count = (int) $query->execute()->fetchField();

      \Drupal::cache()->set($cache_key, $count, \Drupal::time()->getRequestTime() + 900);
      return $count;
    } catch (\Exception $e) {
      return 1000;
    }
  }

  /**
   * Helper to extract unquoted table name.
   */
  protected function getUnquotedTableName($quoted_table_name)
  {
    return str_replace(['"', "'", '`'], '', $quoted_table_name);
  }

  /**
   * Helper method to consistently detect multi-value fields that use arrays.
   */
  protected function isFieldMultiValue($field)
  {
    if (!$field) {
      return false;
    }

    // Use the fieldMapper's detection logic.
    $this->ensureFieldMapper();
    return $this->fieldMapper->isFieldMultiValue($field);
  }

  /**
   * Function buildBaseConditions().
   */
  protected function buildBaseConditions(QueryInterface $query, $table_name)
  {
    $where_clauses = [];
    $params = [];

    // Add search key conditions.
    $keys = $query->getKeys();
    if ($keys) {
      $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
      $processed_keys = $this->processSearchKeys($search_text);

      if (!empty(trim($processed_keys))) {
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';

        // Validate FTS configuration.
        $allowed_configs = ['simple', 'english', 'spanish', 'french', 'german'];
        if (!in_array($fts_config, $allowed_configs)) {
          $fts_config = 'english';
        }

        $where_clauses[] = "search_vector @@ to_tsquery(?, ?)";
        $params[] = $fts_config;
        $params[] = $processed_keys;
      }
    }

    // Add condition group filters.
    $conditions = $query->getConditionGroup();
    if ($conditions) {
      $condition_sql = $this->buildConditionGroupSqlSecure($conditions, $params);
      if ($condition_sql) {
        $where_clauses[] = $condition_sql;
      }
    }

    return [
      'where' => !empty($where_clauses) ? implode(' AND ', $where_clauses) : '1=1',
      'params' => $params,
    ];
  }

  /**
   * Secure condition group SQL builder.
   */
  protected function buildConditionGroupSqlSecure($condition_group, &$params)
  {
    $conditions = $condition_group->getConditions();

    if (empty($conditions)) {
      return null;
    }

    $conjunction = $condition_group->getConjunction();
    $sql_conditions = [];

    foreach ($conditions as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_sql = $this->buildConditionGroupSqlSecure($condition, $params);
        if ($nested_sql) {
          $sql_conditions[] = "({$nested_sql})";
        }
      } else {
        $field = $condition->getField();
        $value = $condition->getValue();
        $operator = $condition->getOperator();

        $condition_sql = $this->buildParameterizedConditionSql($field, $value, $operator, $params);
        if ($condition_sql) {
          $sql_conditions[] = $condition_sql;
        }
      }
    }

    if (empty($sql_conditions)) {
      return null;
    }

    $conjunction_sql = $conjunction === 'OR' ? ' OR ' : ' AND ';
    return implode($conjunction_sql, $sql_conditions);
  }

  /**
   * Check if facet set contains complex multi-value fields.
   */
  protected function hasComplexMultiValueFields(array $facets_option, IndexInterface $index)
  {
    foreach ($facets_option as $facet_config) {
      $field = $index->getFields()[$facet_config['field']];
      if ($this->isFieldMultiValue($field) &&
            $this->detectMultiValueStorageFormat($field) !== 'single'
        ) {
        return true;
      }
    }
    return false;
  }

  /**
   * Build query hash for caching context.
   */
  protected function buildQueryHash(QueryInterface $query, $table_name)
  {
    $hash_components = [
      'keys' => $query->getKeys(),
      'conditions' => $this->serializeConditions($query->getConditionGroup()),
      'table' => $table_name,
      'filters' => $query->getOption('search_api_filters', []),
    ];

    return md5(serialize($hash_components));
  }

  /**
   * {@inheritdoc}
   */
  public function fieldsUpdated(IndexInterface $index, array $updated_fields)
  {
    $this->logger->info('BACKEND METHOD CALLED: fieldsUpdated for @index', ['@index' => $index->id()]);
  }

  /**
   * Convert entity reference field to array if it has unlimited cardinality.
   */
  protected function convertEntityReferenceToArray($table_name, $field_id, $field)
  {
    try {
      // Get property path to extract actual field name.
      $property_path = $field->getPropertyPath();
      $path_parts = explode(':', $property_path);
      $drupal_field_name = $path_parts[0];

      // Get field storage to check cardinality.
      $field_storage = FieldStorageConfig::loadByName('node', $drupal_field_name);

      if (!$field_storage) {
        // Skip if field storage not found.
        return;
      }

      $cardinality = $field_storage->getCardinality();

      // Only convert if unlimited cardinality (-1) or multiple values (> 1)
      if ($cardinality !== -1 && $cardinality <= 1) {
        // Skip single-value fields.
        return;
      }

      // Check current column type.
      $sql = "SELECT data_type FROM information_schema.columns 
              WHERE table_name = :table AND column_name = :column";

      $stmt = $this->connector->executeQuery($sql, [
        ':table' => $table_name,
        ':column' => $field_id,
      ]);

      $row = $stmt->fetch(\PDO::FETCH_ASSOC);
      if (!$row || $row['data_type'] !== 'integer') {
        // Skip if not integer type.
        return;
      }

      // Convert INTEGER to INTEGER[].
      $alter_sql = "ALTER TABLE {$table_name} 
                    ALTER COLUMN {$field_id} TYPE INTEGER[] 
                    USING CASE 
                      WHEN {$field_id} IS NULL THEN NULL 
                      ELSE ARRAY[{$field_id}] 
                    END";

      $this->connector->executeQuery($alter_sql);

      \Drupal::logger('search_api_postgresql')->info('Converted @field from INTEGER to INTEGER[]', [
        '@field' => $field_id,
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('search_api_postgresql')->error('Failed to convert @field: @error', [
        '@field' => $field_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Updates database schema for ALL fields, checking each one.
   */
  protected function updateAllFieldSchemas(IndexInterface $index)
  {
    // Get what the field definitions SHOULD be.
    $field_mapper = new FieldMapper($this->configuration);
    $target_field_definitions = $field_mapper->getFieldDefinitions($index);

    $table_name = $this->getIndexTableNameForManager($index);

    $this->logger->info('Checking schema for table: @table', ['@table' => $table_name]);

    // Check every field in the index.
    foreach ($index->getFields() as $field_id => $field) {
      $field_type = $field->getType();

      // Only check integer and entity_reference fields (they can become arrays)
      if (!in_array($field_type, ['integer', 'entity_reference'])) {
        continue;
      }

      // Get what the column type should be.
      $target_definition = $target_field_definitions[$field_id] ?? null;
      if (!$target_definition) {
        continue;
      }

      $target_type = $target_definition['type'];
      $current_type = $this->getCurrentColumnType($table_name, $field_id);

      $this->logger->info('Field @field: current=@current, target=@target', [
        '@field' => $field_id,
        '@current' => $current_type ?: 'NULL',
        '@target' => $target_type,
      ]);

      // Skip if column doesn't exist or types match.
      if (!$current_type || $current_type === $target_type) {
        continue;
      }

      // Check if this needs conversion.
      if ($this->needsColumnTypeConversion($current_type, $target_type)) {
        $this->alterColumnType($table_name, $field_id, $current_type, $target_type);
      }
    }
  }

  /**
   * Checks if column type conversion is needed and supported.
   */
  protected function needsColumnTypeConversion($current_type, $target_type)
  {
    // Supported conversions.
    $supported_conversions = [
      'INTEGER' => ['INTEGER[]'],
      'INTEGER[]' => ['INTEGER'],
      'BIGINT' => ['BIGINT[]'],
      'BIGINT[]' => ['BIGINT'],
      'TEXT' => ['TEXT[]'],
      'TEXT[]' => ['TEXT'],
      'VARCHAR(255)' => ['TEXT[]'],
    ];

    $is_supported = isset($supported_conversions[$current_type]) &&
                in_array($target_type, $supported_conversions[$current_type]);

    $this->logger->info('needsColumnTypeConversion @current -> @target = @result', [
      '@current' => $current_type,
      '@target' => $target_type,
      '@result' => $is_supported ? 'true' : 'false',
    ]);

    return $is_supported;
  }

  /**
   * Gets the current PostgreSQL column type.
   */
  protected function getCurrentColumnType($table_name, $column_name)
  {
    try {
      $sql = "SELECT data_type, udt_name 
              FROM information_schema.columns 
              WHERE table_name = :table AND column_name = :column";

      $stmt = $this->connector->executeQuery($sql, [
        ':table' => $table_name,
        ':column' => $column_name,
      ]);

      $row = $stmt->fetch(\PDO::FETCH_ASSOC);
      if ($row) {
        $data_type = strtoupper($row['data_type']);
        $udt_name = strtoupper($row['udt_name']);

        // Handle array types.
        if ($data_type === 'ARRAY') {
          // Convert udt_name to standard type.
          $base_type = $this->mapUdtNameToStandardType($udt_name);
          return $base_type . '[]';
        }

        // Convert udt_name to standard type for non-arrays.
        return $this->mapUdtNameToStandardType($udt_name);
      }

      return null;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get column type for @table.@column: @error', [
        '@table' => $table_name,
        '@column' => $column_name,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * Maps PostgreSQL udt_name to standard type names.
   * {@inheritdoc}
   *
   * @param string $udt_name
   *   The PostgreSQL udt_name.
   *   {@inheritdoc}.
   *
   * @return string
   *   The standard type name.
   */
  protected function mapUdtNameToStandardType($udt_name)
  {
    $type_map = [
      'int4' => 'INTEGER',
      'int8' => 'BIGINT',
      'varchar' => 'VARCHAR(255)',
      'text' => 'TEXT',
      '_text' => 'TEXT[]',
      'bool' => 'BOOLEAN',
      'timestamp' => 'TIMESTAMP',
      'timestamptz' => 'TIMESTAMP',
      'numeric' => 'DECIMAL(10,2)',
      'float4' => 'REAL',
      'float8' => 'DOUBLE PRECISION',
    ];

    $standard_type = $type_map[strtolower($udt_name)] ?? strtoupper($udt_name);

    $this->logger->debug('Mapped udt_name @udt to standard type @standard', [
      '@udt' => $udt_name,
      '@standard' => $standard_type,
    ]);

    return $standard_type;
  }

  /**
   * Alters column type with proper data conversion and transaction safety.
   * {@inheritdoc}
   *
   * @param string $table_name
   *   The table name (quoted).
   * @param string $column_name
   *   The column name.
   * @param string $current_type
   *   Current PostgreSQL type.
   * @param string $target_type
   *   Target PostgreSQL type.
   */
  protected function alterColumnType($table_name, $column_name, $current_type, $target_type)
  {
    $this->logger->info('Converting column @table.@column: @current -> @target', [
      '@table' => $table_name,
      '@column' => $column_name,
      '@current' => $current_type,
      '@target' => $target_type,
    ]);

    // Start transaction for safety.
    $this->connector->executeQuery('BEGIN');

    try {
      $sql = $this->buildColumnConversionSql($table_name, $column_name, $current_type, $target_type);

      if ($sql) {
        $this->connector->executeQuery($sql);
        $this->connector->executeQuery('COMMIT');

        $this->logger->info('Successfully converted column: @column', ['@column' => $column_name]);
      } else {
        $this->connector->executeQuery('ROLLBACK');
        $this->logger->warning('No conversion SQL available for @current -> @target', [
          '@current' => $current_type,
          '@target' => $target_type,
        ]);
      }
    } catch (\Exception $e) {
      $this->connector->executeQuery('ROLLBACK');
      $this->logger->error('Failed to convert column @column: @error', [
        '@column' => $column_name,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Maps PostgreSQL database types to standardized type names.
   * {@inheritdoc}
   *
   * @param string $data_type
   *   The data_type from information_schema.columns.
   * @param string $udt_name
   *   The udt_name from information_schema.columns.
   *   {@inheritdoc}.
   *
   * @return string
   *   The standardized type name.
   */
  protected function mapDatabaseTypeToStandard($data_type, $udt_name)
  {
    // Handle array types.
    if (strtoupper($data_type) === 'ARRAY') {
      // For arrays, udt_name is like "_int4" - remove the underscore and map base type.
      $base_udt = ltrim($udt_name, '_');
      $base_type = $this->mapUdtNameToStandardType($base_udt);
      return $base_type . '[]';
    }

    // Handle regular types.
    return $this->mapUdtNameToStandardType($udt_name);
  }

  /**
   * Builds SQL for column type conversion with data transformation.
   */
  protected function buildColumnConversionSql($table_name, $column_name, $current_type, $target_type)
  {
    // INTEGER -> INTEGER[] (single value to array)
    if ($current_type === 'INTEGER' && $target_type === 'INTEGER[]') {
      return "ALTER TABLE {$table_name} 
              ALTER COLUMN {$column_name} TYPE INTEGER[] 
              USING CASE 
                WHEN {$column_name} IS NULL THEN NULL 
                ELSE ARRAY[{$column_name}] 
              END";
    }

    // INTEGER[] -> INTEGER (array to single value - take first element)
    if ($current_type === 'INTEGER[]' && $target_type === 'INTEGER') {
      return "ALTER TABLE {$table_name} 
              ALTER COLUMN {$column_name} TYPE INTEGER 
              USING CASE 
                WHEN {$column_name} IS NULL THEN NULL 
                WHEN array_length({$column_name}, 1) > 0 THEN {$column_name}[1]
                ELSE NULL 
              END";
    }

    // BIGINT -> BIGINT[] (for large entity reference fields)
    if ($current_type === 'BIGINT' && $target_type === 'BIGINT[]') {
      return "ALTER TABLE {$table_name} 
              ALTER COLUMN {$column_name} TYPE BIGINT[] 
              USING CASE 
                WHEN {$column_name} IS NULL THEN NULL 
                ELSE ARRAY[{$column_name}] 
              END";
    }

    // BIGINT[] -> BIGINT.
    if ($current_type === 'BIGINT[]' && $target_type === 'BIGINT') {
      return "ALTER TABLE {$table_name} 
              ALTER COLUMN {$column_name} TYPE BIGINT 
              USING CASE 
                WHEN {$column_name} IS NULL THEN NULL 
                WHEN array_length({$column_name}, 1) > 0 THEN {$column_name}[1]
                ELSE NULL 
              END";
    }

    $this->logger->warning('No conversion SQL available for @current -> @target', [
      '@current' => $current_type,
      '@target' => $target_type,
    ]);

    return null;
  }

  /**
   * Ensures the field mapper is initialized.
   */
  protected function ensureFieldMapper()
  {
    if (!$this->fieldMapper) {
      // Enhanced configuration for array storage.
      $enhanced_config = $this->configuration;
      $enhanced_config['multi_value_storage'] = 'array';
      $enhanced_config['prefer_arrays_for_taxonomy'] = true;

      $this->fieldMapper = new FieldMapper($enhanced_config);
    }
  }

  /**
   * Serialize conditions for consistent hashing.
   */
  protected function serializeConditions($condition_group)
  {
    $conditions = [];
    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $conditions[] = ['group' => $this->serializeConditions($condition)];
      } else {
        $conditions[] = [
          'field' => $condition->getField(),
          'value' => $condition->getValue(),
          'operator' => $condition->getOperator(),
        ];
      }
    }
    return $conditions;
  }

  /**
   * Fallback method for individual facet processing.
   */
  protected function processFacetsIndividually(QueryInterface $query, $results, $table_name, array $facets_option)
  {
    // Keep the existing individual processing logic as fallback.
    $facet_results = [];
    $index = $query->getIndex();
    $fields = $index->getFields();

    foreach ($facets_option as $facet_id => $facet_config) {
      $field_name = $facet_config['field'] ?? $facet_id;
      $limit = $facet_config['limit'] ?? 50;
      $min_count = $facet_config['min_count'] ?? 1;
      $missing = $facet_config['missing'] ?? false;

      if (!isset($fields[$field_name])) {
        continue;
      }

      try {
        $field = $fields[$field_name];
        $field_type = $field->getType();

        $facet_query = $this->buildOptimizedFacetQuery(
            $query,
            $table_name,
            $field_name,
            $field,
            $limit,
            $min_count,
            $missing
        );

        if (!$facet_query) {
          continue;
        }

        $this->ensureConnector();
        $pdo = $this->connector->connect();
        $stmt = $pdo->prepare($facet_query['sql']);
        $stmt->execute($facet_query['params']);

        $facet_values = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
          $filter = $this->formatFacetFilter(
              $row['value'],
              (bool) ($row['is_missing'] ?? false),
              $field_type
          );

          $facet_values[] = [
            'filter' => $filter,
            'count' => (int) $row['count'],
          ];
        }

        if (!empty($facet_values)) {
          $facet_results[$facet_id] = $facet_values;
        }
      } catch (\Exception $e) {
        $this->logger->error('Error processing facet @facet: @error', [
          '@facet' => $facet_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    if (!empty($facet_results)) {
      $results->setExtraData('search_api_facets', $facet_results);
    }
  }

  /**
   * Builds optimized single facet query.
   */
  protected function buildOptimizedFacetQuery(
      QueryInterface $query,
      $table_name,
      $field_name,
      $field,
      $limit,
      $min_count,
      $missing
  ) {
    $column_name = $this->connector->quoteColumnName($this->getColumnName($field_name));
    $field_type = $field->getType();

    // Get base conditions excluding current facet.
    $where_conditions = $this->buildSecureNonFacetWhereConditions($query, $table_name, $field_name);
    $where_clauses = $where_conditions['clauses'];

    // Handle missing values.
    if (!$missing) {
      $where_clauses[] = "{$column_name} IS NOT NULL";
    }

    $where_clause = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Build value expression.
    $params_for_expression = [];
    $value_expression = $this->buildSecureValueExpression($field, $field_type, $params_for_expression);
    // Start with where condition params.
    $params = $where_conditions['params'];
    $params = array_merge($params, $params_for_expression);

    // Build optimized SQL.
    $sql = "
      SELECT 
        {$value_expression} as value,
        COUNT(*) as count,
        ({$column_name} IS NULL)::int as is_missing
      FROM {$table_name}
      {$where_clause}
      GROUP BY {$value_expression}, ({$column_name} IS NULL)
      HAVING COUNT(*) >= ?
      ORDER BY count DESC, {$value_expression} ASC
    ";

    $params = $where_conditions['params'];
    $params[] = $min_count;

    if ($limit > 0) {
      $sql .= " LIMIT ?";
      $params[] = $limit;
    }

    return [
      'sql' => $sql,
      'params' => $params,
    ];
  }

  /**
   * Generic detection - force arrays for multi-value integer fields  .
   */
  protected function detectMultiValueStorageFormat($field)
  {
    $field_identifier = method_exists($field, 'getFieldIdentifier') ?
        $field->getFieldIdentifier() : $field->getFieldId();

    // FORCE array storage for multi-value integer fields (entity references)
    if ($this->shouldUseArrayStorage($field)) {
      $this->logger->debug(
          'Forcing array storage for multi-value integer field: @field',
          ['@field' => $field_identifier]
      );
      return 'array';
    }

    // Use existing detection for other field types.
    $table_name = $this->getCurrentTableName();
    if (!$table_name) {
      return $this->getConfiguredMultiValueFormat($field);
    }

    $cache_key = 'search_api_postgresql:multivalue:' . md5($table_name . ':' . $field_identifier);
    $cache = \Drupal::cache()->get($cache_key);

    if ($cache && $cache->valid) {
      return $cache->data;
    }

    $format = $this->analyzeFieldStorageFormat($field_identifier, $table_name);
    \Drupal::cache()->set($cache_key, $format, \Drupal::time()->getRequestTime() + 3600);

    return $format;
  }

  /**
   * Simplified field format analysis without static caching.
   */
  protected function analyzeFieldStorageFormat($field_identifier, $table_name)
  {
    try {
      $database = \Drupal::database();
      $unquoted_table = $this->getUnquotedTableName($table_name);

      $query = $database->select($unquoted_table, 't')
        ->fields('t', [$field_identifier])
        ->condition($field_identifier, null, 'IS NOT NULL')
        ->condition($field_identifier, '', '<>')
        ->range(0, 2);

      $result = $query->execute();
      $samples = [];

      foreach ($result as $row) {
        $value = $row->{$field_identifier};
        if (!empty($value) && strlen($value) > 1) {
          $samples[] = $value;
        }
      }

      return $this->analyzeValueFormat($samples);
    } catch (\Exception $e) {
      $this->logger->warning('Field format analysis failed for @field: @error', [
        '@field' => $field_identifier,
        '@error' => $e->getMessage(),
      ]);
      return 'single';
    }
  }

  /**
   * Performs batch multi-value format detection for all fields at once.
   */
  protected function performBatchMultiValueDetection($table_name, &$storage_cache = null)
  {
    static $last_detection_time = [];

    // Initialize cache if not passed.
    if ($storage_cache === null) {
      static $default_cache = [];
      $storage_cache = &$default_cache;
    }

    $cache_key = $table_name;
    $current_time = time();

    // Only run detection once per hour per table.
    if (isset($last_detection_time[$cache_key]) &&
          ($current_time - $last_detection_time[$cache_key]) < 3600
      ) {
      return;
    }

    try {
      $this->ensureConnector();
      $pdo = $this->connector->connect();

      $table_name_clean = $this->getUnquotedTableName($table_name);
      $columns_sql = "
        SELECT column_name, data_type
        FROM information_schema.columns 
        WHERE table_name = ? 
        AND column_name NOT IN ('search_api_id', 'search_vector')
        AND data_type IN ('text', 'character varying', 'jsonb')
        ORDER BY ordinal_position
        LIMIT 5
      ";

      $stmt = $pdo->prepare($columns_sql);
      $stmt->execute([$table_name_clean]);

      $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

      if (empty($columns)) {
        $last_detection_time[$cache_key] = $current_time;
        return;
      }

      // Sample efficiently - limit to 2 rows for performance.
      $quoted_columns = [];
      foreach ($columns as $column) {
        $quoted_columns[] = $this->connector->quoteColumnName($column['column_name']);
      }

      if (empty($quoted_columns)) {
        $last_detection_time[$cache_key] = $current_time;
        return;
      }

      $select_fields = implode(', ', $quoted_columns);
      $sample_sql = "SELECT {$select_fields} FROM {$table_name} 
                    WHERE (" . implode(' IS NOT NULL OR ', $quoted_columns) . ") 
                    LIMIT 2";

      $stmt = $pdo->query($sample_sql);
      $samples_by_column = [];

      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        foreach ($columns as $column) {
          $column_name = $column['column_name'];
          $value = $row[$column_name];
          if (!empty($value) && strlen($value) > 1) {
            $samples_by_column[$column_name][] = $value;
          }
        }
      }

      // Analyze and cache results using the passed cache reference.
      foreach ($columns as $column) {
        $column_name = $column['column_name'];
        if (isset($samples_by_column[$column_name])) {
          $format = $this->analyzeValueFormat($samples_by_column[$column_name]);
          $storage_cache[$column_name] = $format;
        } else {
          $storage_cache[$column_name] = 'single';
        }
      }

      $last_detection_time[$cache_key] = $current_time;
    } catch (\Exception $e) {
      $this->logger->warning('Batch multi-value detection failed for @table: @error', [
        '@table' => $table_name,
        '@error' => $e->getMessage(),
      ]);
      $last_detection_time[$cache_key] = $current_time;
    }
  }

  /**
   * Analyzes sample values to determine storage format.
   */
  protected function analyzeValueFormat(array $samples)
  {
    foreach ($samples as $sample) {
      // Check for JSON array format.
      if (is_string($sample) && (strpos($sample, '[') === 0 || strpos($sample, '{') === 0)) {
        $decoded = json_decode($sample, true);
        if (is_array($decoded)) {
          return 'json';
        }
      }

      // Check for PostgreSQL array format.
      if (is_string($sample) && preg_match('/^\{.*\}$/', $sample)) {
        return 'array';
      }

      // Check for delimited strings.
      $separators = ['|', ',', ';', ':', '~'];
      foreach ($separators as $sep) {
        if (strpos($sample, $sep) !== false) {
          // Verify it's likely a multi-value delimiter.
          $parts = explode($sep, $sample);
          if (count($parts) > 1 && strlen(trim($parts[0])) > 0) {
            return 'separated';
          }
        }
      }
    }

    // If no multi-value patterns detected, assume single value.
    return 'single';
  }

  /**
   * Determines the separator used for delimited multi-value fields.
   */
  protected function getMultiValueSeparator($field)
  {
    // Check field configuration first.
    $field_config = $field->getConfiguration();
    if (!empty($field_config['multi_value_separator'])) {
      return $field_config['multi_value_separator'];
    }

    // Check backend configuration.
    if (!empty($this->configuration['multi_value_separator'])) {
      return $this->configuration['multi_value_separator'];
    }

    // Try to auto-detect from sample data.
    $detected = $this->detectSeparatorFromSamples($field);
    if ($detected) {
      return $detected;
    }

    // Default fallback.
    return '|';
  }

  /**
   * Auto-detects separator from field samples.
   */
  protected function detectSeparatorFromSamples($field)
  {
    try {
      $this->ensureConnector();
      $pdo = $this->connector->connect();

      $field_identifier = method_exists($field, 'getFieldIdentifier') ?
            $field->getFieldIdentifier() : $field->getFieldId();

      // SECURITY: Properly quote column name and validate table name.
      $column_name = $this->connector->quoteColumnName($this->getColumnName($field_identifier));
      $table_name = $this->getCurrentTableName();

      if (!$table_name) {
        return null;
      }

      // PERFORMANCE: Limit sample size and add proper WHERE clause.
      $sample_sql = "SELECT {$column_name} FROM {$table_name} 
                    WHERE {$column_name} IS NOT NULL 
                    AND length({$column_name}) > 3
                    AND {$column_name} != ''
                    LIMIT 2";

      $stmt = $pdo->query($sample_sql);

      $separator_counts = ['|' => 0, ',' => 0, ';' => 0];
      $sample_count = 0;

      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $sample_count++;

        // LOGIC FIX: Get column value properly.
        $column_key = $this->getUnquotedColumnName($column_name);
        $value = $row[$column_key] ?? '';

        if (empty($value)) {
          continue;
        }

        foreach (array_keys($separator_counts) as $sep) {
          $count = substr_count($value, $sep);
          if ($count > 0) {
            $separator_counts[$sep] += $count;
          }
        }
      }

      if ($sample_count === 0) {
        return null;
      }

      // Return most frequent separator (minimum 2 occurrences)
      $max_count = max($separator_counts);
      if ($max_count >= 2) {
        return array_search($max_count, $separator_counts);
      }
    } catch (\Exception $e) {
      $this->logger->debug('Separator detection failed for @field: @error', [
        '@field' => $field_identifier ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
    }

    return null;
  }

  /**
   * Helper to get unquoted column name for array access.
   */
  protected function getUnquotedColumnName($quoted_column_name)
  {
    return trim($quoted_column_name, '"\'`');
  }

  /**
   * Gets configured multi-value format or returns default.
   */
  protected function getConfiguredMultiValueFormat($field)
  {
    // Check field-specific configuration.
    $field_config = $field->getConfiguration();
    if (!empty($field_config['multi_value_format'])) {
      return $field_config['multi_value_format'];
    }

    // Check backend configuration.
    if (!empty($this->configuration['multi_value_format'])) {
      return $this->configuration['multi_value_format'];
    }

    // Default to separated (pipe-delimited) for backward compatibility.
    return 'separated';
  }

  /**
   * Builds expression for normalized multi-value storage.
   */
  protected function buildNormalizedMultiValueExpression($column_name, $field)
  {
    // This would require knowledge of the multi-value table structure
    // For now, return a placeholder that could be extended.
    $field_id = $field->getFieldIdentifier();
    $multi_table = $this->getCurrentTableName() . '_' . $field_id;

    // This would need to be part of a more complex JOIN query.
    return "{$multi_table}.value";
  }

  /**
   * Helper to get current table name - FIXED VERSION.
   */
  protected function getCurrentTableName()
  {
    // Get the current index from context - this requires passing it through the call chain
    // For now, we'll use a more reliable approach by getting it from the search query context.
    static $current_table_name = null;

    if ($current_table_name === null) {
      // Try to get from the most recent search context.
      if (isset($this->currentIndex)) {
        $current_table_name = $this->getIndexTableNameForManager($this->currentIndex);
      } else {
        // Fallback: we can't detect format without a table, so return null to disable detection.
        return null;
      }
    }

    return $current_table_name;
  }

  /**
   * Debug method to help troubleshoot facet issues.
   */
  public function debugFacetField(IndexInterface $index, $field_name)
  {
    $this->logger->info('=== FACET FIELD DEBUG ===');
    $this->logger->info('Field name: @field', ['@field' => $field_name]);

    $fields = $index->getFields();
    if (isset($fields[$field_name])) {
      $field = $fields[$field_name];
      $this->logger->info('Field type: @type', ['@type' => $field->getType()]);
      $this->logger->info('Field config: @config', ['@config' => print_r($field->getConfiguration(), true)]);

      // Check what column name we're using.
      $column_name = $this->getColumnName($field_name);
      $this->logger->info('Resolved column name: @column', ['@column' => $column_name]);

      // Check if data exists in the table.
      $table_name = $this->getIndexTableNameForManager($index);
      $unquoted_table = $this->getUnquotedTableName($table_name);

      try {
        $sql = "SELECT {$column_name}, COUNT(*) as cnt FROM {$table_name} 
                WHERE {$column_name} IS NOT NULL 
                GROUP BY {$column_name} 
                LIMIT 5";
        $stmt = $this->connector->executeQuery($sql);

        $this->logger->info('Sample data from table:');
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
          $this->logger->info('Value: @value, Count: @count', [
            '@value' => $row[$this->getUnquotedColumnName($column_name)] ?? 'NULL',
            '@count' => $row['cnt'],
          ]);
        }
      } catch (\Exception $e) {
        $this->logger->error('Failed to query sample data: @error', ['@error' => $e->getMessage()]);
      }
    } else {
      $this->logger->error('Field @field not found in index', ['@field' => $field_name]);
    }
    $this->logger->info('=== END FACET FIELD DEBUG ===');
  }

  /**
   * Sets current index context for multi-value detection.
   */
  protected function setCurrentIndexContext(IndexInterface $index)
  {
    $this->currentIndex = $index;
  }

  /**
   * Formats facet filter value with proper handling for entity references.
   */
  protected function formatFacetFilter($value, $is_missing = false, $field_type = 'string', $all_values = [])
  {
    // Handle missing values.
    if ($is_missing || $value === null || $value === '') {
      return '!';
    }

    // Handle entity reference fields - both single and multi-value need same format.
    if ($field_type === 'entity_reference') {
      if (is_numeric($value) && $value > 0) {
        return '"' . (int) $value . '"';
      }
      // Invalid entity ID.
      return '!';
    }

    // Handle integer fields (including single-value entity refs stored as integers)
    if ($field_type === 'integer') {
      if (is_numeric($value)) {
        return '"' . (int) $value . '"';
      }
      // Invalid integer.
      return '!';
    }

    // Handle boolean values.
    if ($value === true || $value === false || $value === 'true' || $value === 'false') {
      $bool_string = ($value === true || $value === 'true') ? 'true' : 'false';
      return '"' . $bool_string . '"';
    }

    // Handle pre-formatted range values.
    if (preg_match('/^(\[|\()(.*?)(\]|\))$/', $value)) {
      return $value;
    }

    // Generate range filters for numeric/date fields.
    if (in_array($field_type, ['decimal', 'date'])) {
      $range_filter = $this->generateRangeFilter($value, $field_type, $all_values);
      if ($range_filter) {
        return $range_filter;
      }
    }

    // Handle numeric values (still quote per spec)
    if (is_numeric($value)) {
      return '"' . $value . '"';
    }

    // Escape quotes and special characters in string values.
    $escaped_value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
    return '"' . $escaped_value . '"';
  }

  /**
   * Complete Facets 3.0 compliant range filter implementation.
   */
  protected function generateRangeFilter($value, $field_type, $all_values = [])
  {
    switch ($field_type) {
      case 'date':
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
          $year = $matches[1];
          $month = $matches[2];

          // Generate month range with proper bracket notation.
          $start_date = $year . '-' . $month . '-01';
          $next_month = date('Y-m-01', strtotime($start_date . ' +1 month'));
          // Include start, exclude end.
          return '[' . $start_date . ' ' . $next_month . ')';
        }

        // Support for wildcard ranges.
        if ($value === '*') {
          // Wildcard value.
          return '*';
        }
          break;

      case 'integer':
      case 'decimal':
        if (is_numeric($value)) {
          $num_value = (float) $value;

          // Generate intelligent ranges based on data distribution.
          if (count($all_values) > 0) {
            $ranges = $this->calculateOptimalRanges($all_values, $num_value);
            if ($ranges) {
              return $ranges;
            }
          }

          // Fallback to magnitude-based ranges.
          if ($num_value >= 1000) {
            $range_start = floor($num_value / 1000) * 1000;
            $range_end = $range_start + 1000;
            return '[' . $range_start . ' ' . $range_end . ')';
          } elseif ($num_value >= 100) {
            $range_start = floor($num_value / 100) * 100;
            $range_end = $range_start + 100;
            return '[' . $range_start . ' ' . $range_end . ')';
          } elseif ($num_value >= 10) {
            $range_start = floor($num_value / 10) * 10;
            $range_end = $range_start + 10;
            return '[' . $range_start . ' ' . $range_end . ')';
          }
        }

        // Support wildcard ranges for numeric fields.
        if ($value === '*') {
          return '*';
        }
          break;
    }

    return null;
  }

  /**
   * Calculates optimal ranges based on actual data distribution.
   */
  protected function calculateOptimalRanges(array $all_values, $target_value)
  {
    if (empty($all_values)) {
      return null;
    }

    $numeric_values = array_filter($all_values, 'is_numeric');
    if (count($numeric_values) < 3) {
      // Not enough data for meaningful ranges.
      return null;
    }

    sort($numeric_values);
    $count = count($numeric_values);

    // Create quartile-based ranges.
    $q1_index = floor($count * 0.25);
    $q2_index = floor($count * 0.50);
    $q3_index = floor($count * 0.75);

    $q1 = $numeric_values[$q1_index];
    $q2 = $numeric_values[$q2_index];
    $q3 = $numeric_values[$q3_index];
    $min = $numeric_values[0];
    $max = $numeric_values[$count - 1];

    // Determine which range the target falls into.
    if ($target_value <= $q1) {
      return '[' . $min . ' ' . $q1 . ']';
    } elseif ($target_value <= $q2) {
      return '(' . $q1 . ' ' . $q2 . ']';
    } elseif ($target_value <= $q3) {
      return '(' . $q2 . ' ' . $q3 . ']';
    } else {
      return '(' . $q3 . ' ' . $max . ']';
    }
  }

  /**
   * Simplified incoming facet filter processing - array-aware.
   */
  protected function processIncomingFacetFilters(QueryInterface $query)
  {
    $filters = $query->getOption('search_api_filters', []);

    if (empty($filters)) {
      return;
    }

    $index = $query->getIndex();
    $fields = $index->getFields();
    $filter_conditions = $query->createConditionGroup('AND');

    foreach ($filters as $field_name => $filter_values) {
      if (!isset($fields[$field_name])) {
        continue;
      }

      $field = $fields[$field_name];
      $is_array_field = $this->isFieldMultiValue($field) &&
                  in_array($field->getType(), ['integer', 'entity_reference']);

      $field_filter_group = $query->createConditionGroup('OR');

      foreach ((array) $filter_values as $filter_value) {
        if ($filter_value === '!') {
          // Missing value filter.
          $field_filter_group->addCondition($field_name, null, 'IS NULL');
        } elseif (preg_match('/^(\[|\()(.+?)\s+(.+?)(\]|\))$/', $filter_value, $matches)) {
          // Range filter - add range conditions.
          $this->addRangeFilter($field_filter_group, $field_name, $matches, $field->getType());
        } else {
          // Literal value filter - remove quotes and handle appropriately.
          $literal_value = trim($filter_value, '"');

          if ($is_array_field) {
            // For array fields, the condition will be handled by buildParameterizedConditionSql
            // which will convert this to "value = ANY(column)"
            $field_filter_group->addCondition($field_name, (int) $literal_value, '=');
          } else {
            // For single-value fields, use direct comparison.
            $field_filter_group->addCondition($field_name, $literal_value, '=');
          }
        }
      }

      if ($field_filter_group->getConditions()) {
        $filter_conditions->addConditionGroup($field_filter_group);
      }
    }

    if ($filter_conditions->getConditions()) {
      $query->addConditionGroup($filter_conditions);
    }
  }

  /**
   * Add range filter conditions.
   */
  protected function addRangeFilter($filter_group, $field_name, $matches, $field_type)
  {
    $start_bracket = $matches[1];
    $start_value = $matches[2];
    $end_value = $matches[3];
    $end_bracket = $matches[4];

    if ($start_value !== '*') {
      $operator = $start_bracket === '[' ? '>=' : '>';
      $filter_group->addCondition($field_name, $this->convertFilterValue($start_value, $field_type), $operator);
    }

    if ($end_value !== '*') {
      $operator = $end_bracket === ']' ? '<=' : '<';
      $filter_group->addCondition($field_name, $this->convertFilterValue($end_value, $field_type), $operator);
    }
  }

  /**
   * Builds individual filter condition with proper security.
   */
  protected function buildFilterCondition($column_name, $filter_value, $field_type, $field, &$params)
  {
    // Handle missing value filter.
    if ($filter_value === '!') {
      return "{$column_name} IS NULL";
    }

    // Handle range filters: [start end], (start end], etc.
    if (preg_match('/^(\[|\()(.+?)\s+(.+?)(\]|\))$/', $filter_value, $matches)) {
      $start_bracket = $matches[1];
      $start_value = $matches[2];
      $end_value = $matches[3];
      $end_bracket = $matches[4];

      $conditions = [];

      // Handle wildcards.
      if ($start_value !== '*') {
        $operator = $start_bracket === '[' ? '>=' : '>';
        $conditions[] = "{$column_name} {$operator} ?";
        $params[] = $this->convertFilterValue($start_value, $field_type);
      }

      if ($end_value !== '*') {
        $operator = $end_bracket === ']' ? '<=' : '<';
        $conditions[] = "{$column_name} {$operator} ?";
        $params[] = $this->convertFilterValue($end_value, $field_type);
      }

      return implode(' AND ', $conditions);
    }

    // Handle quoted literal values: "VALUE".
    if (preg_match('/^"(.*)"$/', $filter_value, $matches)) {
      $literal_value = $matches[1];

      // Handle multi-value fields.
      if ($this->isFieldMultiValue($field)) {
        return $this->buildMultiValueFilterCondition($column_name, $literal_value, $field, $params);
      } else {
        $params[] = $literal_value;
        return "{$column_name} = ?";
      }
    }

    // Fallback for unquoted values (should be rare)
    $params[] = $filter_value;
    return "{$column_name} = ?";
  }

  /**
   * Array-aware facet filtering for multi-value fields.
   */
  protected function buildMultiValueFilterCondition($column_name, $value, $field, &$params)
  {
    // Check if this field uses array storage.
    if ($this->isFieldMultiValue($field) && in_array($field->getType(), ['integer', 'entity_reference'])) {
      // Use PostgreSQL array contains operator.
      $params[] = (int) $value;
      $this->logger->debug('Using array filter: ? = ANY(@column)', ['@column' => $column_name]);
      return "? = ANY({$column_name})";
    }

    // Fallback to existing logic for other multi-value formats.
    $storage_format = $this->detectMultiValueStorageFormat($field);

    switch ($storage_format) {
      case 'json':
        $params[] = json_encode($value);
          return "{$column_name}::jsonb @> ?::jsonb";

      case 'separated':
        $separator = $this->getMultiValueSeparator($field);
        $escaped_value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $escaped_separator = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $separator);

        $conditions = [];
        $params[] = $escaped_value . $escaped_separator . '%';
        $conditions[] = "{$column_name} LIKE ?";

        $params[] = '%' . $escaped_separator . $escaped_value . $escaped_separator . '%';
        $conditions[] = "{$column_name} LIKE ?";

        $params[] = '%' . $escaped_separator . $escaped_value;
        $conditions[] = "{$column_name} LIKE ?";

        $params[] = $escaped_value;
        $conditions[] = "{$column_name} = ?";

          return '(' . implode(' OR ', $conditions) . ')';

      default:
        $params[] = $value;
          return "{$column_name} = ?";
    }
  }

  /**
   * Converts filter values to appropriate database format.
   */
  protected function convertFilterValue($value, $field_type)
  {
    switch ($field_type) {
      case 'integer':
          return (int) $value;

      case 'decimal':
          return (float) $value;

      case 'date':
          return strtotime($value);

      case 'boolean':
          return $value === 'true' ? 1 : 0;

      default:
          return $value;
    }
  }

  /**
   * Gets the database column name for a Search API field.
   * {@inheritdoc}
   *
   * @param string $field_name
   *   The Search API field name.
   *   {@inheritdoc}.
   *
   * @return string
   *   The database column name.
   */
  protected function getColumnName($field_name)
  {
    // Check if this is an entity reference field by examining the field definition.
    $index = $this->currentIndex;
    if ($index) {
      $fields = $index->getFields();
      if (isset($fields[$field_name])) {
        $field = $fields[$field_name];

        // For entity reference fields, we need to access the target_id column.
        if ($field->getType() === 'entity_reference') {
          // The column name should be the field name itself since Search API
          // should already be storing the target_id value directly.
          return $field_name;
        }
      }
    }

    // Use the existing field mapping logic for other field types.
    $fieldMapper = new FieldMapper($this->configuration);
    return $fieldMapper->getColumnName($field_name);
  }

  /**
   * Builds the SQL query for a single facet.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search query.
   * @param string $table_name
   *   The database table name.
   * @param string $field_name
   *   The field to facet on.
   * @param int $limit
   *   Maximum number of facet values to return.
   * @param int $min_count
   *   Minimum count for facet values.
   * @param bool $missing
   *   Whether to include missing values.
   *   {@inheritdoc}.
   *
   * @return array
   *   Array with 'sql' and 'params' keys.
   */
  protected function buildFacetQuery(
      QueryInterface $query,
      $table_name,
      $field_name,
      $limit = 0,
      $min_count = 1,
      $missing = false
  ) {
    // Get the database column name for this field and quote it properly.
    $column_name = $this->connector->quoteColumnName($this->getColumnName($field_name));

    // Base facet query - use quoted column name throughout.
    $sql = "SELECT {$column_name} AS value, COUNT(*) AS count FROM {$table_name}";
    $params = [];

    // Apply the same WHERE conditions as the main query (excluding facet filters)
    $where_conditions = $this->buildSecureNonFacetWhereConditions($query, $table_name, $field_name);

    if (!empty($where_conditions['clauses'])) {
      $sql .= " WHERE " . implode(' AND ', $where_conditions['clauses']);
      $params = array_merge($params, $where_conditions['params']);
    }

    // Group by the quoted field value.
    $sql .= " GROUP BY {$column_name}";

    // Apply minimum count filter.
    if ($min_count > 1) {
      $sql .= " HAVING COUNT(*) >= ?";
      $params[] = $min_count;
    }

    // Order by count descending, then by value - use quoted column name.
    $sql .= " ORDER BY count DESC, {$column_name} ASC";

    // Apply limit.
    if ($limit > 0) {
      $sql .= " LIMIT ?";
      $params[] = $limit;
    }

    return [
      'sql' => $sql,
      'params' => $params,
    ];
  }

  /**
   * Handles complex condition exclusion including nested groups - FIXED VERSION.
   */
  protected function addComplexConditionExclusion($condition_group, &$where_clauses, &$params, $exclude_field)
  {
    $conjunction = $condition_group->getConjunction();
    $group_clauses = [];

    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_clauses = [];
        $nested_params = [];
        $this->addComplexConditionExclusion($condition, $nested_clauses, $nested_params, $exclude_field);

        if (!empty($nested_clauses)) {
          // FIX: Get conjunction from the nested condition group, not the individual condition.
          $nested_conjunction = $condition->getConjunction();
          $connector = $nested_conjunction === 'OR' ? ' OR ' : ' AND ';
          $group_clauses[] = '(' . implode($connector, $nested_clauses) . ')';
          $params = array_merge($params, $nested_params);
        }
      } else {
        $field = $condition->getField();

        // Skip conditions for excluded field.
        if ($this->shouldExcludeCondition($field, $exclude_field, $condition)) {
          continue;
        }

        $condition_sql = $this->buildParameterizedConditionSql(
            $field,
            $condition->getValue(),
            $condition->getOperator(),
            $params
        );
        if ($condition_sql) {
          $group_clauses[] = $condition_sql;
        }
      }
    }

    if (!empty($group_clauses)) {
      $connector = $conjunction === 'OR' ? ' OR ' : ' AND ';
      $where_clauses[] = implode($connector, $group_clauses);
    }
  }

  /**
   * Determines if a condition should be excluded from facet queries - ENHANCED VERSION.
   */
  protected function shouldExcludeCondition($field, $exclude_field, $condition)
  {
    // Exclude direct field matches.
    if ($field === $exclude_field) {
      return true;
    }

    // Handle taxonomy hierarchy exclusions.
    if ($this->isRelatedTaxonomyField($field, $exclude_field)) {
      return true;
    }

    // Handle entity reference exclusions.
    if ($this->isRelatedEntityReferenceField($field, $exclude_field)) {
      return true;
    }

    // Handle field group exclusions (same base field name)
    if ($this->isFieldGroupRelated($field, $exclude_field)) {
      return true;
    }

    return false;
  }

  /**
   * Checks if two fields are related taxonomy fields.
   */
  protected function isRelatedTaxonomyField($field1, $field2)
  {
    // Extract base field names (remove suffixes like _name, _id, _hierarchy)
    $base1 = preg_replace('/_(?:name|id|hierarchy|parents|children)$/', '', $field1);
    $base2 = preg_replace('/_(?:name|id|hierarchy|parents|children)$/', '', $field2);

    // If base names match, they're related taxonomy fields.
    return $base1 === $base2 && $base1 !== $field1;
  }

  /**
   * Checks if two fields are related entity reference fields.
   */
  protected function isRelatedEntityReferenceField($field1, $field2)
  {
    // Check for entity reference patterns: field_name vs field_name_target_id.
    $patterns = [
      '/^(.+)_target_id$/' => '$1',
      '/^(.+)_entity$/' => '$1',
      '/^(.+)_label$/' => '$1',
    ];

    foreach ($patterns as $pattern => $replacement) {
      if (preg_match($pattern, $field1, $matches)) {
        if ($matches[1] === $field2) {
          return true;
        }
      }
      if (preg_match($pattern, $field2, $matches)) {
        if ($matches[1] === $field1) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Checks if fields belong to the same field group.
   */
  protected function isFieldGroupRelated($field1, $field2)
  {
    // Extract base field names (common prefixes)
    $common_prefixes = ['field_', 'node_', 'user_', 'taxonomy_'];

    foreach ($common_prefixes as $prefix) {
      if (strpos($field1, $prefix) === 0 && strpos($field2, $prefix) === 0) {
        // Check if they share a common base after the prefix.
        $base1 = substr($field1, strlen($prefix));
        $base2 = substr($field2, strlen($prefix));

        // Split by underscore and compare first part.
        $parts1 = explode('_', $base1);
        $parts2 = explode('_', $base2);

        if (count($parts1) > 1 && count($parts2) > 1 && $parts1[0] === $parts2[0]) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Builds parameterized condition SQL to prevent injection - array-aware version.
   */
  protected function buildParameterizedConditionSql($field, $value, $operator, &$params)
  {
    $quoted_field = $this->connector->quoteColumnName($field);

    // Determine if this is an array field.
    $index = $this->currentIndex;
    $is_array_field = false;

    if ($index) {
      $fields = $index->getFields();
      if (isset($fields[$field])) {
        $field_obj = $fields[$field];
        $is_array_field = $this->isFieldMultiValue($field_obj) &&
                in_array($field_obj->getType(), ['integer', 'entity_reference']);
      }
    }

    $this->logger->debug('Building condition for field @field (array: @array): @value @op', [
      '@field' => $field,
      '@array' => $is_array_field ? 'yes' : 'no',
      '@value' => $value,
      '@op' => $operator,
    ]);

    switch ($operator) {
      case '=':
        if ($is_array_field) {
          $params[] = (int) $value;
          return "? = ANY({$quoted_field})";
        } else {
          $params[] = $value;
          return "{$quoted_field} = ?";
        }

      case 'IN':
        if (is_array($value) && !empty($value)) {
          if ($is_array_field) {
            // Use array overlap operator for array fields.
            $int_values = array_map('intval', $value);
            $array_literal = '{' . implode(',', $int_values) . '}';
            $params[] = $array_literal;
            return "{$quoted_field} && ?::integer[]";
          } else {
            // Standard IN clause for single-value fields.
            $placeholders = str_repeat('?,', count($value) - 1) . '?';
            $params = array_merge($params, $value);
            return "{$quoted_field} IN ({$placeholders})";
          }
        }
          return '1=0';

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          $params[] = $value[0];
          $params[] = $value[1];
          return "{$quoted_field} BETWEEN ? AND ?";
        }
          break;

      case 'IS NULL':
        if ($is_array_field) {
          return "({$quoted_field} IS NULL OR array_length({$quoted_field}, 1) = 0)";
        } else {
          return "{$quoted_field} IS NULL";
        }

      case 'IS NOT NULL':
        if ($is_array_field) {
          return "({$quoted_field} IS NOT NULL AND array_length({$quoted_field}, 1) > 0)";
        } else {
          return "{$quoted_field} IS NOT NULL";
        }

      case '>':
      case '>=':
      case '<':
      case '<=':
        $params[] = $value;
          return "{$quoted_field} {$operator} ?";

      case '!=':
      case '<>':
        if ($is_array_field) {
          $params[] = (int) $value;
          return "NOT (? = ANY({$quoted_field}))";
        } else {
          $params[] = $value;
          return "{$quoted_field} <> ?";
        }

      default:
        if ($is_array_field) {
          $params[] = (int) $value;
          return "? = ANY({$quoted_field})";
        } else {
          $params[] = $value;
          return "{$quoted_field} = ?";
        }
    }

    return null;
  }

  /**
   * Adds condition group to facet query, excluding specified field.
   */
  protected function addConditionGroupToFacetQuery($condition_group, &$where_clauses, &$params, $exclude_field)
  {
    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $this->addConditionGroupToFacetQuery($condition, $where_clauses, $params, $exclude_field);
      } else {
        $field = $condition->getField();
        if ($field !== $exclude_field) {
          $where_clauses[] = $this->buildParameterizedConditionSql(
              $field,
              $condition->getValue(),
              $condition->getOperator()
          );
        }
      }
    }
  }

  /**
   * Sets the query sort (adapted from search_api_db).
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   * @param object $db_query
   *   The database query object.
   * @param array $fields
   *   Field information.
   */
  protected function setQuerySort(QueryInterface $query, $db_query, array $fields)
  {
    $sort = $query->getSorts();
    if (!$sort) {
      // Default sort by relevance for searches with keys.
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
  public function extractRetrievedFieldValuesWhereAvailable(
      $result_row,
      array $indexed_fields,
      array $retrieved_fields,
      ItemInterface $item
  ) {
    foreach ($retrieved_fields as $retrieved_field_name) {
      $retrieved_field_value = $result_row->{$retrieved_field_name} ?? null;
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
   * {@inheritdoc}
   *
   * @return \Drupal\search_api\Utility\FieldsHelperInterface
   *   The fields helper.
   */
  public function getFieldsHelper()
  {
    return \Drupal::service('search_api.fields_helper');
  }

  /**
   * Process field value based on field type.
   * {@inheritdoc}
   *
   * @param mixed $value
   *   The raw field value from database.
   * @param string $field_type
   *   The Search API field type.
   *   {@inheritdoc}.
   *
   * @return mixed
   *   The processed field value.
   */
  protected function processFieldValue($value, $field_type)
  {
    if ($value === null) {
      return null;
    }

    switch ($field_type) {
      case 'boolean':
          return (bool) $value;

      case 'date':
        // Convert timestamp to ISO date string.
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
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler.
   */
  public function getModuleHandler()
  {
    return $this->moduleHandler ?: \Drupal::moduleHandler();
  }

  /**
   * Gets field information for an index.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   *   {@inheritdoc}.
   *
   * @return array
   *   Field information array.
   */
  protected function getFieldInfo(IndexInterface $index)
  {
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
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   * @param array $fields
   *   Field information.
   *   {@inheritdoc}.
   *
   * @return object
   *   A query result object that mimics Drupal's SelectInterface.
   */
  protected function createDbQuery(QueryInterface $query, array $fields)
  {
    $index = $query->getIndex();
    $table_name = $this->getIndexTableNameForManager($index);

    // Build field list.
    $select_fields = ['search_api_id AS item_id'];
    foreach ($fields as $field_id => $field_info) {
      if ($field_id !== 'search_api_id') {
        $select_fields[] = "{$field_id}";
      }
    }

    // Handle search keys and scoring.
    $keys = $query->getKeys();
    if ($keys && $this->hasValidSearchKeys($keys)) {
      $relevance_field = $this->buildRelevanceField($query, $fields);
      $select_fields[] = "{$relevance_field} AS score";
    } else {
      $select_fields[] = "1000.0 AS score";
    }

    // Build WHERE conditions.
    $where_parts = $this->buildWhereConditions($query, $fields);

    // Return query data structure instead of custom class.
    return [
      'sql' => "SELECT " . implode(', ', $select_fields) . " FROM {$table_name} t",
      'where' => $where_parts['where'],
      'params' => $where_parts['params'],
      'table_name' => $table_name,
    ];
  }

  /**
   * Build relevance field for scoring.
   */
  protected function buildRelevanceField(QueryInterface $query, array $fields)
  {
    $keys = $query->getKeys();
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    $processed_keys = $this->processSearchKeys($search_text);

    if ($this->isAiSearchEnabled()) {
      $fts_config = $this->configuration['fts_configuration'] ?? 'english';
      return "ts_rank(search_vector, to_tsquery('{$fts_config}', '{$processed_keys}')) * 1000";
    }

    return "1000.0";
  }

  /**
   * Validate search keys  .
   */
  protected function hasValidSearchKeys($keys)
  {
    if (is_scalar($keys)) {
      return !empty(trim($keys));
    }

    if (!is_array($keys)) {
      return false;
    }

    $search_terms = 0;
    foreach ($keys as $key => $value) {
      if ($key !== '#conjunction' && $key !== '#negation' && !empty($value)) {
        $search_terms++;
      }
    }

    return $search_terms > 0;
  }

  /**
   * Build WHERE conditions with proper parameterization.
   */
  protected function buildWhereConditions(QueryInterface $query, array $fields)
  {
    $where_clauses = [];
    $params = [];

    // Add search key conditions.
    $keys = $query->getKeys();
    if ($keys && $this->hasValidSearchKeys($keys)) {
      $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
      $processed_keys = $this->processSearchKeys($search_text);

      if (!empty(trim($processed_keys))) {
        $fts_config = $this->configuration['fts_configuration'] ?? 'english';
        $where_clauses[] = "search_vector @@ to_tsquery(?, ?)";
        $params[] = $fts_config;
        $params[] = $processed_keys;
      }
    }

    // Add filter conditions.
    $conditions = $query->getConditionGroup();
    if ($conditions && count($conditions->getConditions()) > 0) {
      $condition_sql = $this->buildConditionGroupSql($conditions, $params);
      if ($condition_sql) {
        $where_clauses[] = $condition_sql;
      }
    }

    return [
      'where' => implode(' AND ', $where_clauses),
      'params' => $params,
    ];
  }

  /**
   * Prepares search keys for PostgreSQL (similar to search_api_db prepareKeys).
   */
  protected function prepareKeysForPostgreSql($keys)
  {
    try {
      error_log('STEP 1: Method called with keys: ' . print_r($keys, true));

      // Test 1: Check if keys is scalar.
      if (is_scalar($keys)) {
        error_log('STEP 2A: Keys is scalar: ' . var_export($keys, true));
        $trimmed = trim($keys);
        error_log('STEP 2B: After trim: ' . var_export($trimmed, true));
        $result = $trimmed !== '' ? $keys : null;
        error_log('STEP 2C: Scalar result: ' . var_export($result, true));
        return $result;
      }

      error_log('STEP 3: Keys is not scalar, checking if empty...');

      // Test 2: Check if keys is empty.
      if (!$keys) {
        error_log('STEP 4: Keys is empty, returning NULL');
        return null;
      }

      error_log('STEP 5: Keys is not empty, checking if array...');

      // Test 3: Check if keys is array.
      if (is_array($keys)) {
        error_log('STEP 6: Keys is array, starting to count search terms...');

        $search_terms = 0;
        error_log('STEP 7: Initial search_terms count: ' . $search_terms);

        foreach ($keys as $key => $value) {
          error_log('STEP 8: Processing key=' . var_export($key, true) . ', value=' . var_export($value, true));

          $is_conjunction = ($key === '#conjunction');
          $is_negation = ($key === '#negation');
          $is_empty = empty($value);

          error_log('STEP 9: is_conjunction=' . var_export($is_conjunction, true) .
            ', is_negation=' . var_export($is_negation, true) .
            ', is_empty=' . var_export($is_empty, true));

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
          return null;
        }

        error_log('STEP 14: Found search terms, returning original keys');
      } else {
        error_log('STEP 15: Keys is not an array, type is: ' . gettype($keys));
      }

      error_log('STEP 16: About to return original keys: ' . print_r($keys, true));
      return $keys;
    } catch (\Exception $e) {
      error_log('EXCEPTION in prepareKeysForPostgreSql: ' . $e->getMessage());
      error_log('Exception trace: ' . $e->getTraceAsString());
      return null;
    } catch (\Error $e) {
      error_log('ERROR in prepareKeysForPostgreSql: ' . $e->getMessage());
      error_log('Error trace: ' . $e->getTraceAsString());
      return null;
    }
  }

  /**
   * Add fulltext search using Drupal database API.
   */
  protected function addFullTextSearch($select, QueryInterface $query, array $fields)
  {
    $keys = $query->getKeys();
    $search_text = is_string($keys) ? $keys : $this->extractTextFromKeys($keys);
    $processed_keys = $this->processSearchKeys($search_text);

    if (empty(trim($processed_keys))) {
      $select->addExpression('1000.0', 'score');
      return;
    }

    $fts_config = $this->configuration['fts_configuration'] ?? 'english';

    if ($this->isAiSearchEnabled()) {
      // AI-enhanced search with proper parameterization.
      $select->addExpression('ts_rank(search_vector, to_tsquery(:config, :keys)) * 1000', 'score', [
        ':config' => $fts_config,
        ':keys' => $processed_keys,
      ]);
      $select->where('search_vector @@ to_tsquery(:config, :keys)', [
        ':config' => $fts_config,
        ':keys' => $processed_keys,
      ]);
    } else {
      // Traditional PostgreSQL full-text search.
      $select->addExpression('1000.0', 'score');
      $this->addTraditionalFullTextConditions($select, $processed_keys, $fields, $fts_config);
    }
  }

  /**
   * Add traditional full-text conditions using Drupal API.
   */
  protected function addTraditionalFullTextConditions($select, $search_text, $fields, $fts_config)
  {
    $or_group = $select->orConditionGroup();

    foreach ($fields as $field_id => $field_info) {
      if (isset($field_info['type']) &&
            in_array($field_info['type'], ['text', 'string', 'postgresql_fulltext']) &&
            $field_id !== 'search_api_id'
        ) {
        $or_group->where("to_tsvector(:config, COALESCE(t.{$field_id}, '')) @@ plainto_tsquery(:config, :text)", [
          ':config' => $fts_config,
          ':text' => $search_text,
        ]);
      }
    }

    if (count($or_group->getConditions()) > 0) {
      $select->condition($or_group);
    }
  }

  /**
   * Add filter conditions using proper Drupal database API.
   */
  protected function addFilterConditions($select, QueryInterface $query, array $fields)
  {
    $condition_group = $query->getConditionGroup();
    if ($condition_group && count($condition_group->getConditions()) > 0) {
      $this->addConditionGroup($select, $condition_group, $fields);
    }
  }

  /**
   * Recursively add condition groups using Drupal API.
   */
  protected function addConditionGroup($select, $condition_group, array $fields)
  {
    $conditions = $condition_group->getConditions();
    if (empty($conditions)) {
      return;
    }

    $conjunction = $condition_group->getConjunction();
    $drupal_group = $conjunction === 'OR' ? $select->orConditionGroup() : $select->andConditionGroup();

    foreach ($conditions as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_group = $conjunction === 'OR' ? $select->orConditionGroup() : $select->andConditionGroup();
        $this->addNestedConditionGroup($nested_group, $condition, $fields);
        if (count($nested_group->getConditions()) > 0) {
          $drupal_group->condition($nested_group);
        }
      } else {
        $this->addSingleCondition($drupal_group, $condition, $fields);
      }
    }

    if (count($drupal_group->getConditions()) > 0) {
      $select->condition($drupal_group);
    }
  }

  /**
   * Add nested condition groups.
   */
  protected function addNestedConditionGroup($drupal_group, $condition_group, array $fields)
  {
    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_conjunction = $condition->getConjunction();
        $nested_group = $nested_conjunction === 'OR' ?
                \Drupal::database()->orConditionGroup() :
                \Drupal::database()->andConditionGroup();
        $this->addNestedConditionGroup($nested_group, $condition, $fields);
        $drupal_group->condition($nested_group);
      } else {
        $this->addSingleCondition($drupal_group, $condition, $fields);
      }
    }
  }

  /**
   * Add single condition with proper validation.
   */
  protected function addSingleCondition($drupal_group, $condition, array $fields)
  {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = $condition->getOperator();

    // Validate field exists.
    if (!isset($fields[$field]) && !in_array(
        $field,
        ['search_api_id', 'search_api_datasource', 'search_api_language']
    )) {
      return;
    }

    // Use Drupal's condition methods with proper operators.
    switch ($operator) {
      case '=':
        $drupal_group->condition("t.{$field}", $value, '=');
          break;

      case 'IN':
        if (is_array($value) && !empty($value)) {
          $drupal_group->condition("t.{$field}", $value, 'IN');
        }
          break;

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          $drupal_group->condition("t.{$field}", $value, 'BETWEEN');
        }
          break;

      case 'IS NULL':
        $drupal_group->isNull("t.{$field}");
          break;

      case 'IS NOT NULL':
        $drupal_group->isNotNull("t.{$field}");
          break;

      case '!=':
      case '<>':
        $drupal_group->condition("t.{$field}", $value, '<>');
          break;

      case '>':
        $drupal_group->condition("t.{$field}", $value, '>');
          break;

      case '>=':
        $drupal_group->condition("t.{$field}", $value, '>=');
          break;

      case '<':
        $drupal_group->condition("t.{$field}", $value, '<');
          break;

      case '<=':
        $drupal_group->condition("t.{$field}", $value, '<=');
          break;

      default:
        $drupal_group->condition("t.{$field}", $value, '=');
    }
  }

  /**
   * Enhanced processSearchKeys with empty string validation.
   */
  protected function processSearchKeys($keys)
  {
    if (empty($keys) || empty(trim($keys))) {
      return '';
    }

    // Simple processing - escape special characters for tsquery.
    $processed = preg_replace('/[&|!():\'"<>]/', ' ', $keys);
    $processed = preg_replace('/\s+/', ' & ', trim($processed));

    // Final validation - ensure we have actual content.
    return trim($processed);
  }

  /**
   * Builds conditions SQL from Search API query.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   * @param array $fields
   *   Field information.
   *   {@inheritdoc}.
   *
   * @return string|null
   *   SQL condition string or NULL.
   */
  protected function buildConditionsFromQuery(QueryInterface $query, array $fields)
  {
    $condition_group = $query->getConditionGroup();
    return $this->buildConditionGroupSql($condition_group, $fields);
  }

  /**
   * Builds SQL for a condition group.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group.
   * @param array $params
   *   Parameters array passed by reference.
   *   {@inheritdoc}.
   *
   * @return string|null
   *   SQL condition string or NULL.
   */
  protected function buildConditionGroupSql($condition_group, &$params)
  {
    $conditions = $condition_group->getConditions();

    if (empty($conditions)) {
      return null;
    }

    $conjunction = $condition_group->getConjunction();
    $sql_conditions = [];

    foreach ($conditions as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested_sql = $this->buildConditionGroupSql($condition, $params);
        if ($nested_sql) {
          $sql_conditions[] = "({$nested_sql})";
        }
      } else {
        $field = $condition->getField();
        $value = $condition->getValue();
        $operator = $condition->getOperator();

        $condition_sql = $this->buildSecureConditionSql($field, $value, $operator, $params);
        if ($condition_sql) {
          $sql_conditions[] = $condition_sql;
        }
      }
    }

    if (empty($sql_conditions)) {
      return null;
    }

    $conjunction_sql = $conjunction === 'OR' ? ' OR ' : ' AND ';
    return implode($conjunction_sql, $sql_conditions);
  }

  /**
   * Build secure condition SQL with proper parameterization - array-aware version.
   */
  protected function buildSecureConditionSql($field, $value, $operator, &$params)
  {
    $quoted_field = $this->connector->quoteColumnName($field);

    // Determine if this is an array field by checking current index.
    $is_array_field = false;
    if ($this->currentIndex) {
      $fields = $this->currentIndex->getFields();
      if (isset($fields[$field])) {
        $field_obj = $fields[$field];
        $is_array_field = $this->isFieldMultiValue($field_obj) &&
                in_array($field_obj->getType(), ['integer', 'entity_reference', 'text', 'string']);
      }
    }

    switch ($operator) {
      case '=':
        if ($is_array_field) {
          if ($field_obj->getType() === 'integer' || $field_obj->getType() === 'entity_reference') {
            $params[] = (int) $value;
          } else {
            $params[] = (string) $value;
          }
          return "? = ANY({$quoted_field})";
        } else {
          $params[] = $value;
          return "{$quoted_field} = ?";
        }

      case 'IN':
        if (is_array($value) && !empty($value)) {
          if ($is_array_field) {
            // For array fields, use overlap operator.
            $int_values = array_map('intval', $value);
            $array_literal = '{' . implode(',', $int_values) . '}';
            $params[] = $array_literal;
            return "{$quoted_field} && ?::integer[]";
          } else {
            // Standard IN for single-value fields.
            $placeholders = str_repeat('?,', count($value) - 1) . '?';
            $params = array_merge($params, $value);
            return "{$quoted_field} IN ({$placeholders})";
          }
        }
          return '1=0';

      case 'BETWEEN':
        if (is_array($value) && count($value) === 2) {
          if ($is_array_field) {
            // For arrays, we can't do BETWEEN directly - this would need special handling
            // For now, treat as range conditions on individual array elements.
            $params[] = $value[0];
            $params[] = $value[1];
            return "EXISTS(SELECT 1 FROM unnest({$quoted_field}) AS arr_val WHERE arr_val BETWEEN ? AND ?)";
          } else {
            $params[] = $value[0];
            $params[] = $value[1];
            return "{$quoted_field} BETWEEN ? AND ?";
          }
        }
          break;

      case 'IS NULL':
        if ($is_array_field) {
          return "({$quoted_field} IS NULL OR array_length({$quoted_field}, 1) IS NULL)";
        } else {
          return "{$quoted_field} IS NULL";
        }

      case 'IS NOT NULL':
        if ($is_array_field) {
          return "({$quoted_field} IS NOT NULL AND array_length({$quoted_field}, 1) > 0)";
        } else {
          return "{$quoted_field} IS NOT NULL";
        }

      case '>':
        if ($is_array_field) {
          $params[] = $value;
          return "EXISTS(SELECT 1 FROM unnest({$quoted_field}) AS arr_val WHERE arr_val > ?)";
        } else {
          $params[] = $value;
          return "{$quoted_field} > ?";
        }

      case '>=':
        if ($is_array_field) {
          $params[] = $value;
          return "EXISTS(SELECT 1 FROM unnest({$quoted_field}) AS arr_val WHERE arr_val >= ?)";
        } else {
          $params[] = $value;
          return "{$quoted_field} >= ?";
        }

      case '<':
        if ($is_array_field) {
          $params[] = $value;
          return "EXISTS(SELECT 1 FROM unnest({$quoted_field}) AS arr_val WHERE arr_val < ?)";
        } else {
          $params[] = $value;
          return "{$quoted_field} < ?";
        }

      case '<=':
        if ($is_array_field) {
          $params[] = $value;
          return "EXISTS(SELECT 1 FROM unnest({$quoted_field}) AS arr_val WHERE arr_val <= ?)";
        } else {
          $params[] = $value;
          return "{$quoted_field} <= ?";
        }

      case '!=':
      case '<>':
        if ($is_array_field) {
          $params[] = (int) $value;
          return "NOT (? = ANY({$quoted_field}))";
        } else {
          $params[] = $value;
          return "{$quoted_field} <> ?";
        }

      default:
        if ($is_array_field) {
          $params[] = (int) $value;
          return "? = ANY({$quoted_field})";
        } else {
          $params[] = $value;
          return "{$quoted_field} = ?";
        }
    }

    return null;
  }

  /**
   * Extracts text from complex search keys structure.
   * {@inheritdoc}
   *
   * @param mixed $keys
   *   The search keys.
   *   {@inheritdoc}.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractTextFromKeys($keys)
  {
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
  protected function getDrupalConnection()
  {
    // Use the same connection as our connector.
    return $this->connector->connect();
  }

  /**
   * Add quote method to connector if needed.
   */
  protected function quote($value)
  {
    if (method_exists($this->connector, 'quote')) {
      return $this->connector->quote($value);
    }

    // Fallback: use PDO quote method.
    $pdo = $this->connector->connect();
    return $pdo->quote($value);
  }

  /**
   * AJAX callback for testing database connection.
   * {@inheritdoc}
   * Updated for Drupal 11 with proper translation and improved user feedback.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state)
  {
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

    // Debug logging.
    $this->logger->info('AJAX Connection Test: @message', ['@message' => $message]);

    // Return the exact element that should replace the wrapper.
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'connection-test-result',
        'class' => ['connection-test-wrapper'],
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
  protected function ensureConnector()
  {
    if (!$this->connector) {
      $this->connector = new PostgreSQLConnector($this->configuration['connection'], $this->logger);
    }
  }

  /**
   * Override to fix schema creation with proper array types.
   */
  protected function createIndexTables(IndexInterface $index)
  {
    $this->ensureConnector();

    // Get field definitions (this already works and returns INTEGER[] correctly)
    $field_mapper = new FieldMapper($this->configuration);
    $field_definitions = $field_mapper->getFieldDefinitions($index);

    $table_name = $this->getIndexTableNameForManager($index);

    // Build CREATE TABLE SQL with correct column types.
    $columns = ['search_api_id VARCHAR(255) PRIMARY KEY'];

    foreach ($field_definitions as $field_id => $definition) {
      $sql_type = $definition['type'];
      $nullable = $definition['null'] ? '' : ' NOT NULL';
      $columns[] = "{$field_id} {$sql_type}{$nullable}";
    }

    $create_sql = "CREATE TABLE IF NOT EXISTS {$table_name} (" . implode(', ', $columns) . ")";

    try {
      $this->connector->executeQuery("DROP TABLE IF EXISTS {$table_name}");
      $this->connector->executeQuery($create_sql);

      $this->logger->info('Created table @table with correct array types', ['@table' => $table_name]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to create table: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Updates the schema for an existing index.
   */
  protected function updateIndexSchema(IndexInterface $index)
  {
    // Use the IndexManager to update the index.
    $this->getIndexManager()->updateIndex($index);
  }

  /**
   * Drops all tables associated with an index.
   */
  protected function dropIndexTables($index_id)
  {
    // Use the IndexManager to drop the index.
    $this->getIndexManager()->dropIndex($index_id);
  }

  /**
   * Indexes a single item.
   * {@inheritdoc}
   * Updated to use IndexManager's table name construction method.
   */
  protected function indexItem(IndexInterface $index, ItemInterface $item)
  {
    try {
      $indexManager = $this->getIndexManager();

      // Get table name using the same logic as IndexManager.
      $table_name = $this->getIndexTableNameForManager($index);
      $indexManager->indexItem($table_name, $index, $item);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Failed to index item @item: @error', [
        '@item' => $item->getId(),
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Determines if a field needs its own table.
   */
  protected function needsFieldTable($field)
  {
    // Complex multi-value fields might need separate tables.
    return $field->getType() === 'string' && ($field->getConfiguration()['multi_value'] ?? false);
  }

  /**
   * Gets or creates an IndexManager instance.
   * {@inheritdoc}
   * Updated to use shared FieldMapper instance.
   */
  protected function getIndexManager()
  {
    if (!$this->indexManager) {
      $this->ensureConnector();
      // Use shared fieldMapper.
      $this->ensureFieldMapper();

      $embedding_service = null;

      // Check AI configuration.
      $ai_enabled = !empty($this->configuration['ai_embeddings']['enabled']);

      if ($ai_enabled) {
        try {
          $embedding_service = \Drupal::service('search_api_postgresql.queued_embedding_service');
        } catch (\Exception $e) {
          $this->logger->warning('Embedding service not available: @error', ['@error' => $e->getMessage()]);
        }
      }

      // Use EnhancedIndexManager with shared fieldMapper.
      if ($ai_enabled && class_exists('\Drupal\search_api_postgresql\PostgreSQL\EnhancedIndexManager')) {
        $this->indexManager = new EnhancedIndexManager(
            $this->connector,
            // Use the shared instance.
              $this->fieldMapper,
            $this->configuration,
            $embedding_service,
            $this->getServerId()
        );
      } else {
        $this->indexManager = new IndexManager(
            $this->connector,
            // Use the shared instance.
              $this->fieldMapper,
            $this->configuration
        );
      }

      // Configure the IndexManager for array storage.
      if (method_exists($this->indexManager, 'setMultiValueStoragePreference')) {
        $this->indexManager->setMultiValueStoragePreference('array');
      }
    }

    return $this->indexManager;
  }

  /**
   * Gets the server ID for this backend instance.
   */
  protected function getServerId()
  {
    // Try to get server ID from the server entity.
    if ($this->server) {
      return $this->server->id();
    }

    // Fallback: try to find it from the configuration.
    return $this->configuration['server_id'] ?? 'default';
  }

  /**
   * Checks if AI search enhancements are enabled in the server configuration.
   */
  protected function isAiSearchEnabled()
  {
    // Check various configuration keys that indicate AI is enabled.
    $ai_config_keys = [
      'ai_embeddings.enabled',
      'ai_embeddings.azure_ai.enabled',
      'azure_embedding.enabled',
      'vector_search.enabled',
    ];

    foreach ($ai_config_keys as $config_key) {
      if (!empty($this->configuration[$config_key])) {
        $this->logger->debug('AI search enabled via config key: @key', ['@key' => $config_key]);
        return true;
      }
    }

    // Also check nested configuration structures.
    if (!empty($this->configuration['ai_embeddings']['enabled']) ||
          !empty($this->configuration['vector_search']['enabled']) ||
          !empty($this->configuration['azure_embedding']['enabled'])
      ) {
      $this->logger->debug('AI search enabled via nested configuration');
      return true;
    }

    $this->logger->debug('AI search not enabled in configuration');
    return false;
  }

  /**
   * Builds traditional PostgreSQL full-text search using text fields.
   */
  protected function buildPostgreSqlTextSearch($fields, $search_text, $fts_config = 'english')
  {
    $search_conditions = [];
    $params = [];

    // Validate and quote the FTS config (only allow known configs)
    $allowed_configs = ['simple', 'english', 'spanish', 'french', 'german'];
    if (!in_array($fts_config, $allowed_configs)) {
      $fts_config = 'english';
    }

    foreach ($fields as $field_id => $field_info) {
      if (isset($field_info['type']) &&
            in_array($field_info['type'], ['text', 'string', 'postgresql_fulltext']) &&
            $field_id !== 'search_api_id'
        ) {
        $safe_field = $this->connector->quoteColumnName($field_id);
        // Use parameterized queries instead of string interpolation.
        $search_conditions[] = "to_tsvector(?, COALESCE({$safe_field}, '')) @@ plainto_tsquery(?, ?)";
        $params[] = $fts_config;
        $params[] = $fts_config;
        $params[] = $search_text;
      }
    }

    if (empty($search_conditions)) {
      // Fallback to ILIKE with proper escaping.
      $search_term = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search_text) . '%';

      // Check if title field exists.
      $title_field = null;
      foreach ($fields as $field_id => $field_info) {
        if (in_array($field_id, ['title', 'name', 'label'])) {
          $title_field = $this->connector->quoteColumnName($field_id);
          break;
        }
      }

      if ($title_field) {
        $search_conditions[] = "{$title_field} ILIKE ?";
        $params[] = $search_term;
      } else {
        // Ultra fallback - just return empty condition.
        return [
          'sql' => '1=1',
          'params' => [],
        ];
      }
    }

    return [
      'sql' => '(' . implode(' OR ', $search_conditions) . ')',
      'params' => $params,
    ];
  }
}
