<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;

/**
 * PostgreSQL search backend with AI vector search support.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL with AI & Vector Search"),
 *   description = @Translation("Unified PostgreSQL backend supporting traditional search, AI embeddings, and vector search with multiple providers.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase {

  use SecureKeyManagementTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // Core PostgreSQL connection
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
      
      // Basic search settings
      'search_settings' => [
        'index_prefix' => 'search_api_',
        'fts_configuration' => 'english',
        'batch_size' => 100,
        'debug' => FALSE,
      ],

      // AI & Vector Search Features
      'ai_features' => [
        'enabled' => FALSE,
        'provider' => 'openai', // openai, azure_openai, huggingface, local
        
        // OpenAI Configuration
        'openai' => [
          'api_key' => '',
          'api_key_name' => '',
          'model' => 'text-embedding-3-small',
          'organization' => '',
          'base_url' => 'https://api.openai.com/v1',
          'dimension' => 1536,
        ],
        
        // Azure OpenAI Configuration
        'azure_openai' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_name' => '',
          'deployment_name' => '',
          'model' => 'text-embedding-3-small',
          'dimension' => 1536,
          'api_version' => '2023-05-15',
        ],
        
        // Hugging Face Configuration
        'huggingface' => [
          'api_key' => '',
          'api_key_name' => '',
          'model' => 'sentence-transformers/all-MiniLM-L6-v2',
          'dimension' => 384,
        ],
        
        // Local Model Configuration
        'local' => [
          'model_path' => '',
          'model_type' => 'sentence_transformers',
          'dimension' => 384,
        ],
        
        // Common AI settings
        'batch_size' => 25,
        'rate_limit_delay' => 100,
        'max_retries' => 3,
        'timeout' => 30,
        'enable_cache' => TRUE,
        'cache_ttl' => 3600,
      ],

      // Vector Index Configuration
      'vector_index' => [
        'enabled' => FALSE,
        'method' => 'ivfflat', // ivfflat, hnsw
        'ivfflat_lists' => 100,
        'hnsw_m' => 16,
        'hnsw_ef_construction' => 64,
        'distance' => 'cosine', // cosine, euclidean, manhattan
        'probes' => 10,
      ],

      // Hybrid Search Configuration
      'hybrid_search' => [
        'enabled' => FALSE,
        'text_weight' => 0.6,
        'vector_weight' => 0.4,
        'similarity_threshold' => 0.15,
        'max_results' => 1000,
        'boost_exact_matches' => TRUE,
      ],

      // Performance & Azure Optimizations
      'performance' => [
        'azure_optimized' => FALSE,
        'connection_pool_size' => 10,
        'statement_timeout' => 30000,
        'work_mem' => '256MB',
        'effective_cache_size' => '2GB',
        'shared_buffers' => '128MB',
        'maintenance_work_mem' => '64MB',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'search_api_postgresql/admin';
    
    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // === Core PostgreSQL Connection ===
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('PostgreSQL Connection'),
      '#open' => TRUE,
      '#description' => $this->t('Configure the database connection settings.'),
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->configuration['connection']['host'],
      '#required' => TRUE,
      '#description' => $this->t('Database server hostname or IP address.'),
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['connection']['port'],
      '#min' => 1,
      '#max' => 65535,
      '#required' => TRUE,
      '#description' => $this->t('Database server port (typically 5432).'),
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#default_value' => $this->configuration['connection']['database'],
      '#required' => TRUE,
      '#description' => $this->t('Name of the database to connect to.'),
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'],
      '#required' => TRUE,
      '#description' => $this->t('Database username.'),
    ];

    // Secure password handling
    $form['connection']['password_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Database Password (Secure)'),
      '#default_value' => $this->configuration['connection']['password_key'],
      '#description' => $this->t('Select a key containing the database password. Recommended for security.'),
      '#empty_option' => $this->t('- Use direct password below -'),
    ];

    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Database Password (Direct)'),
      '#description' => $this->t('Only use this if you cannot use the Key module above. Not recommended for production.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
        ],
      ],
    ];

    $form['connection']['ssl_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('SSL Mode'),
      '#default_value' => $this->configuration['connection']['ssl_mode'],
      '#options' => [
        'disable' => $this->t('Disable'),
        'allow' => $this->t('Allow'),
        'prefer' => $this->t('Prefer'),
        'require' => $this->t('Require'),
        'verify-ca' => $this->t('Verify CA'),
        'verify-full' => $this->t('Verify Full'),
      ],
      '#description' => $this->t('SSL connection mode. Azure requires "require" or higher.'),
    ];

    // === Basic Search Settings ===
    $form['search_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Search Settings'),
      '#open' => FALSE,
    ];

    $form['search_settings']['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index Prefix'),
      '#default_value' => $this->configuration['search_settings']['index_prefix'],
      '#description' => $this->t('Prefix for database tables and indexes.'),
    ];

    $form['search_settings']['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Full-text Search Configuration'),
      '#default_value' => $this->configuration['search_settings']['fts_configuration'],
      '#options' => [
        'english' => $this->t('English'),
        'simple' => $this->t('Simple'),
        'arabic' => $this->t('Arabic'),
        'danish' => $this->t('Danish'),
        'dutch' => $this->t('Dutch'),
        'finnish' => $this->t('Finnish'),
        'french' => $this->t('French'),
        'german' => $this->t('German'),
        'hungarian' => $this->t('Hungarian'),
        'italian' => $this->t('Italian'),
        'norwegian' => $this->t('Norwegian'),
        'portuguese' => $this->t('Portuguese'),
        'romanian' => $this->t('Romanian'),
        'russian' => $this->t('Russian'),
        'spanish' => $this->t('Spanish'),
        'swedish' => $this->t('Swedish'),
        'turkish' => $this->t('Turkish'),
      ],
      '#description' => $this->t('PostgreSQL text search configuration for stemming and language-specific features.'),
    ];

    // === AI Features ===
    $form['ai_features'] = [
      '#type' => 'details',
      '#title' => $this->t('AI & Vector Search Features'),
      '#open' => !empty($this->configuration['ai_features']['enabled']),
      '#description' => $this->t('Enable AI-powered semantic search using embeddings from various providers.'),
    ];

    $form['ai_features']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Features'),
      '#default_value' => $this->configuration['ai_features']['enabled'],
      '#description' => $this->t('Enable semantic search using AI embeddings. Requires pgvector extension.'),
    ];

    $form['ai_features']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#default_value' => $this->configuration['ai_features']['provider'],
      '#options' => [
        'openai' => $this->t('OpenAI'),
        'azure_openai' => $this->t('Azure OpenAI'),
        'huggingface' => $this->t('Hugging Face'),
        'local' => $this->t('Local Model'),
      ],
      '#description' => $this->t('Choose your embedding provider.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_features][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // OpenAI Configuration
    $this->buildProviderConfig($form, 'openai', [
      'api_key_name' => 'OpenAI API Key (Secure)',
      'model' => 'text-embedding-3-small',
      'organization' => '',
      'base_url' => 'https://api.openai.com/v1',
    ]);

    // Azure OpenAI Configuration  
    $this->buildProviderConfig($form, 'azure_openai', [
      'endpoint' => 'Azure Endpoint',
      'api_key_name' => 'Azure API Key (Secure)', 
      'deployment_name' => 'Deployment Name',
      'model' => 'text-embedding-3-small',
    ]);

    // Hugging Face Configuration
    $this->buildProviderConfig($form, 'huggingface', [
      'api_key_name' => 'Hugging Face API Key (Secure)',
      'model' => 'sentence-transformers/all-MiniLM-L6-v2',
    ]);

    // Local Model Configuration
    $this->buildProviderConfig($form, 'local', [
      'model_path' => 'Model Path',
      'model_type' => 'sentence_transformers',
    ]);

    // === Vector Index Configuration ===
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Configuration'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_features][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_index']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Vector Indexing'),
      '#default_value' => $this->configuration['vector_index']['enabled'],
      '#description' => $this->t('Create optimized vector indexes for faster similarity search.'),
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#default_value' => $this->configuration['vector_index']['method'],
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Good for most cases)'),
        'hnsw' => $this->t('HNSW (Better for high-dimensional data)'),
      ],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // === Hybrid Search ===
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_features][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['hybrid_search']['enabled'],
      '#description' => $this->t('Combine traditional text search with vector similarity search for better results.'),
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional text search (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for vector similarity search (0-1).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // === Performance & Azure Optimizations ===
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance & Cloud Optimizations'),
      '#open' => FALSE,
    ];

    $form['performance']['azure_optimized'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure Database Optimizations'),
      '#default_value' => $this->configuration['performance']['azure_optimized'],
      '#description' => $this->t('Apply Azure-specific connection and query optimizations.'),
    ];

    $form['performance']['connection_pool_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection Pool Size'),
      '#default_value' => $this->configuration['performance']['connection_pool_size'],
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Maximum number of database connections to maintain.'),
    ];

    $form['search_settings']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Logging'),
      '#default_value' => $this->configuration['search_settings']['debug'],
      '#description' => $this->t('Log detailed information about queries and operations.'),
    ];

    return $form;
  }

  /**
   * Helper method to build provider-specific configuration.
   */
  private function buildProviderConfig(array &$form, string $provider, array $fields) {
    $form['ai_features'][$provider] = [
      '#type' => 'details',
      '#title' => $this->t('@provider Configuration', ['@provider' => ucfirst(str_replace('_', ' ', $provider))]),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_features][enabled]"]' => ['checked' => TRUE],
          ':input[name="backend_config[ai_features][provider]"]' => ['value' => $provider],
        ],
      ],
    ];

    foreach ($fields as $field_key => $field_title) {
      $field_type = str_contains($field_key, 'key') ? 'key_select' : 'textfield';
      
      $form['ai_features'][$provider][$field_key] = [
        '#type' => $field_type,
        '#title' => $this->t($field_title),
        '#default_value' => $this->configuration['ai_features'][$provider][$field_key] ?? '',
      ];

      if ($field_type === 'key_select') {
        $form['ai_features'][$provider][$field_key]['#empty_option'] = $this->t('- Select a key -');
        $form['ai_features'][$provider][$field_key]['#description'] = $this->t('Use the Key module to securely store this credential.');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Validate database connection
    if (empty($values['connection']['password_key']) && empty($values['connection']['password'])) {
      $form_state->setErrorByName('connection][password', 
        $this->t('Database password is required (either via Key module or direct entry).'));
    }

    // Validate AI features if enabled
    if (!empty($values['ai_features']['enabled'])) {
      $provider = $values['ai_features']['provider'];
      $provider_config = $values['ai_features'][$provider] ?? [];

      // Validate API keys
      if (in_array($provider, ['openai', 'azure_openai', 'huggingface'])) {
        if (empty($provider_config['api_key_name']) && empty($provider_config['api_key'])) {
          $form_state->setErrorByName("ai_features][$provider][api_key_name", 
            $this->t('@provider API key is required.', ['@provider' => ucfirst($provider)]));
        }
      }

      // Validate Azure-specific fields
      if ($provider === 'azure_openai') {
        if (empty($provider_config['endpoint'])) {
          $form_state->setErrorByName("ai_features][$provider][endpoint", 
            $this->t('Azure OpenAI endpoint is required.'));
        }
        if (empty($provider_config['deployment_name'])) {
          $form_state->setErrorByName("ai_features][$provider][deployment_name", 
            $this->t('Azure OpenAI deployment name is required.'));
        }
      }

      // Validate hybrid search weights
      if (!empty($values['hybrid_search']['enabled'])) {
        $text_weight = (float) ($values['hybrid_search']['text_weight'] ?? 0.6);
        $vector_weight = (float) ($values['hybrid_search']['vector_weight'] ?? 0.4);
        
        if (abs(($text_weight + $vector_weight) - 1.0) > 0.01) {
          $form_state->setErrorByName('hybrid_search][text_weight', 
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
    $this->configuration = $form_state->getValues();
  }

  // Additional methods for search operations...
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
      'search_api_autocomplete', 
      'search_api_grouping',
      'search_api_mlt',
    ];
  }

  public function addIndex(IndexInterface $index) {
    // Implementation for adding index
  }

  public function updateIndex(IndexInterface $index) {
    // Implementation for updating index
  }

  public function removeIndex($index) {
    // Implementation for removing index
  }

  public function indexItems(IndexInterface $index, array $items) {
    // Implementation for indexing items
  }

  public function deleteItems(IndexInterface $index, array $item_ids) {
    // Implementation for deleting items
  }

  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    // Implementation for deleting all items
  }

  public function search(QueryInterface $query) {
    // Implementation for search operations
  }

  public function isAvailable() {
    return extension_loaded('pdo_pgsql');
  }
}