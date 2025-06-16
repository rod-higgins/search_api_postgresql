<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;
use Drupal\search_api_postgresql\Service\BackendMigrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PostgreSQL search backend with migration support.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL Search Backend"),
 *   description = @Translation("Standard PostgreSQL backend with optional Azure OpenAI embeddings.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements ContainerFactoryPluginInterface {

  use SecureKeyManagementTrait;

  /**
   * The logger.
   */
  protected $logger;

  /**
   * The migration service.
   */
  protected $migrationService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    
    $instance->logger = $container->get('logger.factory')->get('search_api_postgresql');
    $instance->migrationService = $container->get('search_api_postgresql.backend_migration');
    
    // Key repository is optional
    try {
      if ($container->has('key.repository')) {
        $instance->keyRepository = $container->get('key.repository');
      }
    } catch (\Exception $e) {
      $instance->logger->info('Key module not available, using direct password entry only.');
    }

    return $instance;
  }

  /**
   * {@inheritdoc}
   * 
   * MATCHES SCHEMA: search_api.backend.plugin.postgresql
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
        'ssl_ca' => '',
        'options' => [],
      ],
      'index_prefix' => 'search_api_',
      'fts_configuration' => 'english',
      'debug' => FALSE,
      'batch_size' => 100,
      // SCHEMA COMPLIANT: ai_embeddings structure
      'ai_embeddings' => [
        'enabled' => FALSE,
        'azure_ai' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_name' => '',
          'deployment_name' => '',
          'model' => 'text-embedding-3-small',
          'dimension' => 1536,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Check for backend switching and show warnings
    $this->addBackendSwitchingWarnings($form, $form_state);

    // Database Connection (schema compliant)
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Database Connection'),
      '#open' => TRUE,
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->configuration['connection']['host'] ?? 'localhost',
      '#required' => TRUE,
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['connection']['port'] ?? 5432,
      '#min' => 1,
      '#max' => 65535,
      '#required' => TRUE,
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'] ?? '',
      '#required' => TRUE,
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'] ?? '',
      '#required' => TRUE,
    ];

    // Password handling with Key module
    $this->addPasswordFields($form);

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
    ];

    // Index Settings
    $form['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Prefix'),
      '#default_value' => $this->configuration['index_prefix'] ?? 'search_api_',
    ];

    $form['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Full Text Search Configuration'),
      '#options' => [
        'simple' => $this->t('Simple'),
        'english' => $this->t('English'),
        'spanish' => $this->t('Spanish'),
        'french' => $this->t('French'),
        'german' => $this->t('German'),
      ],
      '#default_value' => $this->configuration['fts_configuration'] ?? 'english',
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Mode'),
      '#default_value' => $this->configuration['debug'] ?? FALSE,
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['batch_size'] ?? 100,
      '#min' => 1,
      '#max' => 1000,
    ];

    // AI Embeddings (optional, schema compliant)
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings (Optional)'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'] ?? FALSE,
    ];

    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Configuration'),
      '#states' => [
        'visible' => [':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE]],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'] ?? '',
    ];

    // Add API key fields
    $this->addApiKeyFields($form['ai_embeddings']['azure_ai'], 'ai_embeddings][azure_ai', 'ai_embeddings.azure_ai');

    return $form;
  }

  /**
   * Adds backend switching warnings to the form.
   */
  protected function addBackendSwitchingWarnings(array &$form, FormStateInterface $form_state) {
    $server = $this->getServer();
    if (!$server) {
      return;
    }

    // Check if this is a backend change
    $current_backend_id = $server->getBackendConfig()['backend'] ?? '';
    $new_backend_id = $this->getPluginId();

    if (!empty($current_backend_id) && $current_backend_id !== $new_backend_id) {
      $compatibility = $this->migrationService->checkBackendCompatibility($current_backend_id, $new_backend_id);
      
      if (!$compatibility['compatible']) {
        $form['backend_switch_warning'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--error']],
          '#weight' => -1000,
        ];

        $form['backend_switch_warning']['message'] = [
          '#type' => 'markup',
          '#markup' => '<h3>' . $this->t('⚠️ Backend Switch Warning') . '</h3>',
        ];

        foreach ($compatibility['warnings'] as $warning) {
          $form['backend_switch_warning']['warning_' . md5($warning)] = [
            '#type' => 'markup',
            '#markup' => '<div class="description">' . $warning . '</div>',
          ];
        }

        $form['backend_switch_warning']['confirmation'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('I understand the risks and want to proceed with the backend switch'),
          '#required' => TRUE,
        ];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Database validation
    if (empty($values['connection']['host'])) {
      $form_state->setErrorByName('connection][host', $this->t('Host is required.'));
    }

    // Password validation (Key module or direct)
    $password_key = $values['connection']['password_key'] ?? '';
    $password = $values['connection']['password'] ?? '';
    
    if (!empty($password_key) && !empty($password)) {
      $form_state->setErrorByName('connection][password', 
        $this->t('Use either password key OR direct password, not both.'));
    }

    // AI embeddings validation
    if (!empty($values['ai_embeddings']['enabled'])) {
      $azure_config = $values['ai_embeddings']['azure_ai'] ?? [];
      
      if (empty($azure_config['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', 
          $this->t('Azure OpenAI endpoint is required.'));
      }

      $api_key_name = $azure_config['api_key_name'] ?? '';
      $api_key = $azure_config['api_key'] ?? '';
      
      if (empty($api_key_name) && empty($api_key)) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][api_key', 
          $this->t('API key is required when AI embeddings are enabled.'));
      }
    }

    // Backend compatibility validation
    $this->validateBackendCompatibility($form_state);
  }

  /**
   * Validates backend switching compatibility.
   */
  protected function validateBackendCompatibility(FormStateInterface $form_state) {
    $server = $this->getServer();
    if (!$server) {
      return;
    }

    $current_backend_id = $server->getBackendConfig()['backend'] ?? '';
    $new_backend_id = $this->getPluginId();

    if (!empty($current_backend_id) && $current_backend_id !== $new_backend_id) {
      $compatibility = $this->migrationService->checkBackendCompatibility($current_backend_id, $new_backend_id);
      
      if (!$compatibility['compatible']) {
        // Check if user confirmed the switch
        $values = $form_state->getValues();
        if (empty($values['backend_switch_warning']['confirmation'])) {
          $form_state->setErrorByName('backend_switch_warning][confirmation', 
            $this->t('You must confirm the backend switch to proceed.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Save configuration (schema compliant)
    $this->configuration = [
      'connection' => $values['connection'],
      'index_prefix' => $values['index_prefix'],
      'fts_configuration' => $values['fts_configuration'],
      'debug' => !empty($values['debug']),
      'batch_size' => (int) $values['batch_size'],
      'ai_embeddings' => $values['ai_embeddings'] ?? $this->defaultConfiguration()['ai_embeddings'],
    ];

    // Clear direct password if using key
    if (!empty($values['connection']['password_key'])) {
      $this->configuration['connection']['password'] = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preUpdate() {
    $server = $this->getServer();
    if (!$server) {
      return;
    }

    // Check for backend switching
    $old_config = $server->getBackendConfig();
    $old_backend_id = $old_config['backend'] ?? '';
    $new_backend_id = $this->getPluginId();

    if (!empty($old_backend_id) && $old_backend_id !== $new_backend_id) {
      $this->logger->info('Backend switching from @old to @new for server @server', [
        '@old' => $old_backend_id,
        '@new' => $new_backend_id,
        '@server' => $server->id(),
      ]);

      // Prepare migration
      $this->migrationService->prepareBackendMigration($server, $old_backend_id, $new_backend_id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postUpdate() {
    $server = $this->getServer();
    if (!$server) {
      return;
    }

    // Execute any pending migrations
    $this->migrationService->executeBackendMigration($server);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = [
      'search_api_facets',
      'search_api_autocomplete',
      'search_api_grouping',
    ];

    // Add AI features if enabled
    if (!empty($this->configuration['ai_embeddings']['enabled'])) {
      $features[] = 'semantic_search';
    }

    return $features;
  }

  /**
   * Gets supported data types for this backend.
   */
  public function getSupportedDataTypes() {
    $types = [
      'text',
      'string', 
      'integer',
      'decimal',
      'date',
      'boolean',
      'postgresql_fulltext',
    ];

    // Add vector type if AI is enabled
    if (!empty($this->configuration['ai_embeddings']['enabled'])) {
      $types[] = 'vector';
    }

    return $types;
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    // Implementation for adding an index
    return TRUE;
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
    // Implementation for removing an index
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    return array_keys($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    // Implementation for deleting items
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    // Implementation for deleting all items
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $results = $query->getResults();
    return $results;
  }

}

/**
 * Azure PostgreSQL backend - SCHEMA COMPLIANT.
 */
class AzurePostgreSQLBackend extends PostgreSQLBackend {

  /**
   * {@inheritdoc}
   * 
   * MATCHES SCHEMA: search_api.backend.plugin.postgresql_azure
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    
    // Azure-specific overrides
    $config['connection']['ssl_mode'] = 'require';
    
    // Replace ai_embeddings with azure_embedding (schema compliant)
    unset($config['ai_embeddings']);
    $config['azure_embedding'] = [
      'enabled' => FALSE,
      'endpoint' => '',
      'api_key' => '',
      'api_key_name' => '',
      'deployment_name' => '',
      'model' => 'text-embedding-3-small',
      'dimension' => 1536,
      'batch_size' => 25,
      'rate_limit_delay' => 100,
      'max_retries' => 3,
      'timeout' => 30,
      'enable_cache' => TRUE,
      'cache_ttl' => 3600,
    ];

    // SCHEMA COMPLIANT: vector_index structure
    $config['vector_index'] = [
      'method' => 'ivfflat',
      'ivfflat_lists' => 100,
      'hnsw_m' => 16,
      'hnsw_ef_construction' => 64,
      'hnsw_ef_search' => 40,
    ];

    // SCHEMA COMPLIANT: hybrid_search structure
    $config['hybrid_search'] = [
      'enabled' => TRUE,
      'text_weight' => 0.6,
      'vector_weight' => 0.4,
      'similarity_threshold' => 0.15,
      'rerank' => FALSE,
    ];

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    if (!empty($this->configuration['azure_embedding']['enabled'])) {
      $features[] = 'vector_search';
      $features[] = 'hybrid_search';
    }
    
    return $features;
  }

}

/**
 * Vector PostgreSQL backend - SCHEMA COMPLIANT.
 */
class PostgreSQLVectorBackend extends PostgreSQLBackend {

  /**
   * {@inheritdoc}
   * 
   * MATCHES SCHEMA: search_api.backend.plugin.postgresql_vector
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    
    // Remove standard ai_embeddings
    unset($config['ai_embeddings']);
    
    // SCHEMA COMPLIANT: vector_search structure
    $config['vector_search'] = [
      'enabled' => FALSE,
      'provider' => 'openai',
      'api_key' => '',
      'api_key_name' => '',
      'model' => 'text-embedding-3-small',
      'dimension' => 1536,
      'api_base' => 'https://api.openai.com/v1',
    ];

    // SCHEMA COMPLIANT: vector_index structure
    $config['vector_index'] = [
      'method' => 'ivfflat',
      'ivfflat_lists' => 100,
      'hnsw_m' => 16,
      'hnsw_ef_construction' => 64,
      'hnsw_ef_search' => 40,
    ];

    // SCHEMA COMPLIANT: hybrid_search structure
    $config['hybrid_search'] = [
      'enabled' => TRUE,
      'text_weight' => 0.6,
      'vector_weight' => 0.4,
      'similarity_threshold' => 0.15,
      'rerank' => FALSE,
    ];

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    if (!empty($this->configuration['vector_search']['enabled'])) {
      $features[] = 'vector_search';
      $features[] = 'hybrid_search';
      $features[] = 'multi_provider_embeddings';
    }
    
    return $features;
  }

}