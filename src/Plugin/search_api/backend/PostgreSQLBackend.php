<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL search backend.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL"),
 *   description = @Translation("Index data on a PostgreSQL database with full-text search and optional AI embeddings.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements ContainerFactoryPluginInterface {

  use SecureKeyManagementTrait;

  /**
   * The database connector.
   *
   * @var \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   */
  protected $connector;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

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
      '#description' => $this->t('Database username for authentication.'),
    ];

    // Password fields - both optional
    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Database password. Leave empty for passwordless connections (e.g., Lando development). Not shown when editing existing configuration.'),
      '#required' => FALSE,
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
      '#default_value' => $this->configuration['connection']['ssl_mode'] ?? 'require',
      '#description' => $this->t('SSL connection mode. Use "require" or higher for production.'),
    ];

    // Index settings
    $form['index_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Index Settings'),
      '#open' => TRUE,
      '#description' => $this->t('Configure how search indexes are managed.'),
    ];

    $form['index_settings']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index table prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
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
      '#default_value' => $this->configuration['fts_configuration'] ?? 'english',
      '#description' => $this->t('PostgreSQL full-text search configuration to use.'),
    ];

    // Advanced settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
      '#description' => $this->t('Advanced configuration options for debugging and performance.'),
    ];

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#default_value' => $this->configuration['debug'] ?? FALSE,
      '#description' => $this->t('Log queries and debug information. Should be disabled in production.'),
    ];

    $form['advanced']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of items to process in each batch during indexing.'),
    ];

    // AI Embeddings configuration
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings (Azure OpenAI)'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
      '#description' => $this->t('Enable semantic search using AI text embeddings. Requires Azure OpenAI Service.'),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings.'),
    ];

    // Azure AI Service configuration
    $form['ai_embeddings']['service'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Service'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['service']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint (e.g., https://your-resource.openai.azure.com/).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add Azure AI API key fields using the trait method
    if (!empty($this->keyRepository)) {
      $this->addApiKeyFields(
        $form['ai_embeddings']['service'],
        'ai_embeddings][service',
        'ai_embeddings.azure_ai'
      );
    } else {
      $form['ai_embeddings']['service']['api_key'] = [
        '#type' => 'password',
        '#title' => $this->t('API Key'),
        '#default_value' => '',
        '#description' => $this->t('Your Azure OpenAI API key.'),
        '#states' => [
          'visible' => [
            ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['ai_embeddings']['service']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'] ?? '',
      '#description' => $this->t('Azure OpenAI deployment name for embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['service']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'] ?? 'text-embedding-ada-002',
      '#description' => $this->t('Azure OpenAI embedding model to use.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance settings
    $form['ai_embeddings']['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['batch_size'] ?? 50,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of texts to process in each batch.'),
    ];

    $form['ai_embeddings']['performance']['rate_limit_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Delay (ms)'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['rate_limit_delay'] ?? 100,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Delay between API requests to avoid rate limiting.'),
    ];

    $form['ai_embeddings']['performance']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Retries'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['max_retries'] ?? 3,
      '#min' => 0,
      '#max' => 10,
      '#description' => $this->t('Maximum number of retries for failed API calls.'),
    ];

    $form['ai_embeddings']['performance']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['timeout'] ?? 30,
      '#min' => 5,
      '#max' => 300,
      '#description' => $this->t('API request timeout in seconds.'),
    ];

    // Hybrid search settings
    $form['ai_embeddings']['hybrid'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['hybrid']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional text search (0-1). Should sum to 1.0 with vector weight.'),
    ];

    $form['ai_embeddings']['hybrid']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['ai_embeddings']['hybrid_search']['vector_weight'] ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity search (0-1). Should sum to 1.0 with text weight.'),
    ];

    // Connection test button
    $form['test_connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
      '#description' => $this->t('Test your database connection settings.'),
    ];

    $form['test_connection']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'connection-test-result',
      ],
    ];

    $form['test_connection']['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    return $form;
  }

  /**
   * AJAX callback to test database connection.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    try {
      $values = $form_state->getValues();
      $connection_config = $values['connection'] ?? [];
      
      // Get password from key if specified
      if (!empty($connection_config['password_key']) && $this->keyRepository) {
        $key = $this->keyRepository->getKey($connection_config['password_key']);
        if ($key) {
          $connection_config['password'] = $key->getKeyValue();
        }
      }
      
      $connector = new PostgreSQLConnector($connection_config, $this->logger);
      $connector->testConnection();
      
      $form['test_connection']['result']['#markup'] = 
        '<div class="messages messages--status">' . 
        $this->t('Connection successful! Connected to @host:@port/@database', [
          '@host' => $connection_config['host'] ?? '',
          '@port' => $connection_config['port'] ?? '',
          '@database' => $connection_config['database'] ?? '',
        ]) . 
        '</div>';
    }
    catch (\Exception $e) {
      $form['test_connection']['result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('Connection failed: @message', ['@message' => $e->getMessage()]) . 
        '</div>';
    }
    
    return $form['test_connection']['result'];
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
      if (empty($values['ai_embeddings']['service']['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][service][endpoint', 
          $this->t('Azure OpenAI endpoint is required when AI embeddings are enabled.'));
      }

      // Validate API key (either from key module or direct)
      $api_key_name = $values['ai_embeddings']['service']['api_key_name'] ?? '';
      $direct_api_key = $values['ai_embeddings']['service']['api_key'] ?? '';
      
      if (empty($api_key_name) && empty($direct_api_key)) {
        $form_state->setErrorByName('ai_embeddings][service][api_key', 
          $this->t('Azure API key is required. Use Key module or direct entry.'));
      }

      // Validate hybrid search weights
      if (isset($values['ai_embeddings']['hybrid'])) {
        $text_weight = (float) ($values['ai_embeddings']['hybrid']['text_weight'] ?? 0.6);
        $vector_weight = (float) ($values['ai_embeddings']['hybrid']['vector_weight'] ?? 0.4);
        
        if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
          $form_state->setErrorByName('ai_embeddings][hybrid][text_weight', 
            $this->t('Text and vector weights should sum to 1.0 (currently: @sum)', [
              '@sum' => number_format($text_weight + $vector_weight, 2)
            ]));
        }
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
      $this->configuration = array_merge($this->configuration, $values['index_settings']);
    }

    // Save advanced settings
    if (isset($values['advanced'])) {
      $this->configuration = array_merge($this->configuration, $values['advanced']);
    }

    // Save AI embeddings configuration
    if (isset($values['ai_embeddings'])) {
      // Flatten service configuration into azure_ai
      if (isset($values['ai_embeddings']['service'])) {
        $this->configuration['ai_embeddings']['azure_ai'] = array_merge(
          $this->configuration['ai_embeddings']['azure_ai'] ?? [],
          $values['ai_embeddings']['service']
        );
      }

      // Save performance settings
      if (isset($values['ai_embeddings']['performance'])) {
        $this->configuration['ai_embeddings']['azure_ai'] = array_merge(
          $this->configuration['ai_embeddings']['azure_ai'] ?? [],
          $values['ai_embeddings']['performance']
        );
      }

      // Save hybrid settings
      if (isset($values['ai_embeddings']['hybrid'])) {
        $this->configuration['ai_embeddings']['hybrid_search'] = $values['ai_embeddings']['hybrid'];
      }

      // Save enabled state
      $this->configuration['ai_embeddings']['enabled'] = !empty($values['ai_embeddings']['enabled']);
    }
  }

  /**
   * Adds API key fields for Azure AI to form.
   */
  protected function addAzureApiKeyFields(array &$form) {
    if (!$this->keyRepository) {
      return;
    }

    $this->addApiKeyFields(
      $form['ai_embeddings']['service'],
      'ai_embeddings][service',
      'ai_embeddings.azure_ai'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = [
      'search_api_autocomplete',
      'search_api_facets',
      'search_api_grouping',
      'search_api_mlt',
      'search_api_random_sort',
    ];

    if (!empty($this->configuration['ai_embeddings']['enabled'])) {
      $features[] = 'search_api_semantic_search';
      $features[] = 'search_api_vector_search';
      $features[] = 'search_api_hybrid_search';
    }

    return $features;
  }

  /**
   * Connect to the database.
   */
  protected function connect() {
    if (!$this->connector) {
      $connection_config = $this->configuration['connection'];
      
      // Get password from key if specified
      if (!empty($connection_config['password_key']) && $this->keyRepository) {
        $key = $this->keyRepository->getKey($connection_config['password_key']);
        if ($key) {
          $connection_config['password'] = $key->getKeyValue();
        }
      }
      
      $this->connector = new PostgreSQLConnector($connection_config, $this->logger);
    }
    
    return $this->connector;
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    try {
      $this->connect();
      // Implementation for adding index
      return TRUE;
    }
    catch (\Exception $e) {
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    return $this->addIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    try {
      $this->connect();
      // Implementation for removing index
    }
    catch (\Exception $e) {
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    try {
      $this->connect();
      // Implementation for indexing items
      return array_keys($items);
    }
    catch (\Exception $e) {
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    try {
      $this->connect();
      // Implementation for deleting items
    }
    catch (\Exception $e) {
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    try {
      $this->connect();
      // Implementation for deleting all items
    }
    catch (\Exception $e) {
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    try {
      $this->connect();
      // Implementation for search
      $results = $query->getResults();
      return $results;
    }
    catch (\Exception $e) {
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

}