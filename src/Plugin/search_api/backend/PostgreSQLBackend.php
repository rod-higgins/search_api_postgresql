<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL search backend with AI vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("PostgreSQL backend with Azure OpenAI embeddings for semantic search.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements ContainerFactoryPluginInterface {

  use SecureKeyManagementTrait;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
      $instance->logger->info('Key module not available, using direct password entry only.');
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
        'provider' => 'azure_ai',
        'azure_ai' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_name' => '',
          'deployment_name' => '',
          'model' => 'text-embedding-ada-002',
          'dimension' => 1536,
          'batch_size' => 50,
          'rate_limit_delay' => 100,
          'max_retries' => 3,
          'timeout' => 30,
        ],
        'vector_index' => [
          'method' => 'ivfflat',
          'ivfflat_lists' => 100,
          'hnsw_m' => 16,
          'hnsw_ef_construction' => 64,
        ],
        'hybrid_search' => [
          'text_weight' => 0.6,
          'vector_weight' => 0.4,
          'similarity_threshold' => 0.15,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    \Drupal::logger('search_api_postgresql')->notice('buildConfigurationForm called for @class', [
      '@class' => static::class
    ]);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Connection settings
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('PostgreSQL Connection'),
      '#open' => TRUE,
      '#description' => $this->t('Configure the database connection settings.'),
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
      '#required' => TRUE,
      '#description' => $this->t('Database server port (typically 5432 for PostgreSQL).'),
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Name of the database to connect to.'),
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
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['connection']['password'] ?? '',
      '#description' => $this->t('Database password. Leave empty for passwordless authentication or if using a key below.'),
    ];

    // Add key selection if Key module is available
    if ($this->getKeyRepository()) {
      $keys = [];
      foreach ($this->getKeyRepository()->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form['connection']['password_key'] = [
        '#type' => 'select',
        '#title' => $this->t('Database Password Key (Optional)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or leave empty -'),
        '#default_value' => $this->configuration['connection']['password_key'] ?? '',
        '#description' => $this->t('Select a key to use for the database password. This field is hidden when a key is selected above.'),
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
      '#default_value' => $this->configuration['connection']['ssl_mode'] ?? 'require',
      '#description' => $this->t('SSL connection mode for database security.'),
    ];

    // Index settings
    $form['index_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Index Settings'),
      '#open' => FALSE,
    ];

    $form['index_settings']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
      '#description' => $this->t('Prefix for database table names.'),
    ];

    $form['index_settings']['fts_configuration'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full-text search configuration'),
      '#default_value' => $this->configuration['fts_configuration'] ?? 'english',
      '#description' => $this->t('PostgreSQL text search configuration to use.'),
    ];

    // AI Embeddings
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
      '#description' => $this->t('Configure AI embeddings for semantic search.'),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using AI embeddings.'),
    ];

    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AI Configuration'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
      '#states' => [
        'visible' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure AI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'] ?? '',
      '#description' => $this->t('Azure AI service endpoint URL.'),
      '#states' => [
        'required' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key'] ?? '',
      '#description' => $this->t('Azure AI API key. Leave empty if using key management below.'),
    ];

    // Add API key selection if Key module is available
    if ($this->getKeyRepository()) {
      $keys = [];
      foreach ($this->getKeyRepository()->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form['ai_embeddings']['azure_ai']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('API Key (Key Management)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct API key above -'),
        '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key from the Key module for secure API key storage.'),
      ];
    }

    $form['ai_embeddings']['azure_ai']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'] ?? '',
      '#description' => $this->t('Azure AI deployment name.'),
      '#states' => [
        'required' => [
          ':input[name="ai_embeddings[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'] ?? 'text-embedding-ada-002',
      '#description' => $this->t('Choose the embedding model.'),
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
      '#default_value' => $this->configuration['debug'] ?? FALSE,
      '#description' => $this->t('Enable debug logging.'),
    ];

    $form['advanced']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#description' => $this->t('Number of items to process in batches.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Validate database connection
    if (empty($values['connection']['host'])) {
      $form_state->setErrorByName('connection][host', $this->t('Database host is required.'));
    }
    
    if (empty($values['connection']['database'])) {
      $form_state->setErrorByName('connection][database', $this->t('Database name is required.'));
    }
    
    if (empty($values['connection']['username'])) {
      $form_state->setErrorByName('connection][username', $this->t('Database username is required.'));
    }

    // Validate AI embeddings if enabled
    if (!empty($values['ai_embeddings']['enabled'])) {
      $azure_config = $values['ai_embeddings']['azure_ai'] ?? [];
      
      if (empty($azure_config['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', $this->t('Azure AI endpoint is required when AI embeddings are enabled.'));
      }
      
      if (empty($azure_config['api_key']) && empty($azure_config['api_key_name'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][api_key', $this->t('Either API key or key name must be provided when AI embeddings are enabled.'));
      }
      
      if (empty($azure_config['deployment_name'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][deployment_name', $this->t('Deployment name is required when AI embeddings are enabled.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Save connection settings
    if (isset($values['connection'])) {
      $this->configuration['connection'] = $values['connection'];
    }

    // Save index settings
    if (isset($values['index_settings'])) {
      $this->configuration['index_prefix'] = $values['index_settings']['index_prefix'] ?? 'search_api_';
      $this->configuration['fts_configuration'] = $values['index_settings']['fts_configuration'] ?? 'english';
    }

    // Save AI embeddings settings
    if (isset($values['ai_embeddings'])) {
      $this->configuration['ai_embeddings'] = $values['ai_embeddings'];
    }

    // Save advanced settings
    if (isset($values['advanced'])) {
      $this->configuration['debug'] = !empty($values['advanced']['debug']);
      $this->configuration['batch_size'] = $values['advanced']['batch_size'] ?? 100;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
      'search_api_facets_operator_or',
      'search_api_grouping',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_spellcheck',
    ];
  }

  // ========================================================================
  // REQUIRED ABSTRACT METHOD IMPLEMENTATIONS
  // ========================================================================

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    // Implementation for adding an index
    try {
      $this->logger->info('Adding index: @index', ['@index' => $index->id()]);
      // Add index implementation here
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to add index @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    // Implementation for updating an index
    try {
      $this->logger->info('Updating index: @index', ['@index' => $index->id()]);
      // Update index implementation here
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to update index @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    // Implementation for removing an index
    try {
      $index_id = is_string($index) ? $index : $index->id();
      $this->logger->info('Removing index: @index', ['@index' => $index_id]);
      // Remove index implementation here
    } catch (\Exception $e) {
      $this->logger->error('Failed to remove index @index: @error', [
        '@index' => $index_id ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    // Implementation for indexing items
    try {
      $this->logger->info('Indexing @count items for index @index', [
        '@count' => count($items),
        '@index' => $index->id(),
      ]);
      // Index items implementation here
      return array_keys($items);
    } catch (\Exception $e) {
      $this->logger->error('Failed to index items for @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    // Implementation for deleting items
    try {
      $this->logger->info('Deleting @count items from index @index', [
        '@count' => count($item_ids),
        '@index' => $index->id(),
      ]);
      // Delete items implementation here
    } catch (\Exception $e) {
      $this->logger->error('Failed to delete items from @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    // Implementation for deleting all items
    try {
      $this->logger->info('Deleting all items from index @index', ['@index' => $index->id()]);
      // Delete all items implementation here
    } catch (\Exception $e) {
      $this->logger->error('Failed to delete all items from @index: @error', [
        '@index' => $index->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    // Implementation for searching
    try {
      $this->logger->info('Executing search query on index @index', [
        '@index' => $query->getIndex()->id(),
      ]);
      // Search implementation here
      $results = $query->getResults();
      return $results;
    } catch (\Exception $e) {
      $this->logger->error('Search failed on @index: @error', [
        '@index' => $query->getIndex()->id(),
        '@error' => $e->getMessage(),
      ]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

}