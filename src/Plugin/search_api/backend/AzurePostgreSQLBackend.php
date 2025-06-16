<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_postgresql\Traits\SecureKeyManagementTrait;

/**
 * Azure PostgreSQL search backend with optimized AI vector search.
 *
 * @SearchApiBackend(
 *   id = "postgresql_azure",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("Optimized PostgreSQL backend for Azure Database with Azure OpenAI integration.")
 * )
 */
class AzurePostgreSQLBackend extends PostgreSQLBackend {

  use SecureKeyManagementTrait;

  /**
   * The Azure embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\AzureEmbeddingService
   */
  protected $embeddingService;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    
    // Override defaults for Azure optimization
    $config['connection']['ssl_mode'] = 'require';
    $config['connection']['port'] = 5432;
    
    // Azure-specific configuration
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

    $config['vector_index'] = [
      'method' => 'ivfflat',
      'lists' => 100,
      'probes' => 10,
    ];

    $config['hybrid_search'] = [
      'enabled' => TRUE,
      'text_weight' => 0.6,
      'vector_weight' => 0.4,
      'similarity_threshold' => 0.15,
      'max_results' => 1000,
    ];

    $config['performance'] = [
      'connection_pool_size' => 10,
      'statement_timeout' => 30000,
      'work_mem' => '256MB',
      'effective_cache_size' => '2GB',
    ];

    // Remove the generic ai_embeddings config
    unset($config['ai_embeddings']);

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    \Drupal::logger('search_api_postgresql')->notice('buildConfigurationForm called for @class', [
      '@class' => static::class
    ]);

    // Get base form from parent but modify for Azure specifics
    $form = parent::buildConfigurationForm($form, $form_state);

    // Remove the generic AI embeddings section
    unset($form['ai_embeddings']);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Modify connection section for Azure
    $form['connection']['#description'] = $this->t('Configure Azure Database for PostgreSQL connection. SSL is required for Azure.');

    // Force SSL requirement for Azure
    $form['connection']['ssl_mode']['#default_value'] = 'require';
    $form['connection']['ssl_mode']['#description'] = $this->t('SSL is required for Azure Database for PostgreSQL connections.');

    // Azure Embedding Configuration (replaces generic AI embeddings)
    $form['azure_embedding'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Embedding Configuration'),
      '#open' => !empty($this->configuration['azure_embedding']['enabled']),
      '#description' => $this->t('Configure Azure OpenAI integration optimized for Azure Database for PostgreSQL.'),
    ];

    $form['azure_embedding']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure AI Embeddings'),
      '#default_value' => $this->configuration['azure_embedding']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings with Azure Database optimizations.'),
    ];

    $form['azure_embedding']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['azure_embedding']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint URL.'),
      '#states' => [
        'required' => [
          ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
        ],
        'visible' => [
          ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['azure_embedding']['api_key'] ?? '',
      '#description' => $this->t('Azure OpenAI API key. Leave empty if using key management below.'),
      '#states' => [
        'visible' => [
          ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add API key selection if Key module is available
    if ($this->getKeyRepository()) {
      $keys = [];
      foreach ($this->getKeyRepository()->getKeys() as $key) {
        $keys[$key->id()] = $key->label();
      }

      $form['azure_embedding']['api_key_name'] = [
        '#type' => 'select',
        '#title' => $this->t('API Key (Key Management)'),
        '#options' => $keys,
        '#empty_option' => $this->t('- Select a key or use direct API key above -'),
        '#default_value' => $this->configuration['azure_embedding']['api_key_name'] ?? '',
        '#description' => $this->t('Select a key from the Key module for secure API key storage.'),
        '#states' => [
          'visible' => [
            ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['azure_embedding']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['azure_embedding']['deployment_name'] ?? '',
      '#description' => $this->t('Azure OpenAI deployment name for the embedding model.'),
      '#states' => [
        'required' => [
          ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
        ],
        'visible' => [
          ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions, faster)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions, higher quality)'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions, legacy)'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Choose the embedding model. text-embedding-3-small is recommended for most use cases.'),
      '#states' => [
        'visible' => [
          ':input[name="azure_embedding[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance settings
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure Database Performance'),
      '#open' => FALSE,
      '#description' => $this->t('Performance optimizations for Azure Database for PostgreSQL.'),
    ];

    $form['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $this->configuration['azure_embedding']['batch_size'] ?? 25,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of items to process in each batch.'),
    ];

    $form['performance']['connection_pool_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection Pool Size'),
      '#default_value' => $this->configuration['performance']['connection_pool_size'] ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Maximum number of concurrent database connections.'),
    ];

    $form['performance']['work_mem'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Work Memory'),
      '#default_value' => $this->configuration['performance']['work_mem'] ?? '256MB',
      '#description' => $this->t('Memory allocation for sort and hash operations (e.g., "256MB").'),
    ];

    // Hybrid search settings
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Configuration'),
      '#open' => FALSE,
      '#description' => $this->t('Combine traditional text search with semantic vector search.'),
    ];

    $form['hybrid_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['hybrid_search']['enabled'] ?? TRUE,
      '#description' => $this->t('Combine text and vector search results for better relevance.'),
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.6,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for traditional text search results (0.0 to 1.0).'),
      '#states' => [
        'visible' => [
          ':input[name="hybrid_search[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['vector_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Vector Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'] ?? 0.4,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Weight for semantic vector search results (0.0 to 1.0).'),
      '#states' => [
        'visible' => [
          ':input[name="hybrid_search[enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    
    $values = $form_state->getValues();
    
    // Validate Azure-specific settings
    if (!empty($values['azure_embedding']['enabled'])) {
      $azure_config = $values['azure_embedding'] ?? [];
      
      if (empty($azure_config['endpoint'])) {
        $form_state->setErrorByName('azure_embedding][endpoint', $this->t('Azure OpenAI endpoint is required when Azure embeddings are enabled.'));
      }
      
      // Check that either direct API key or key name is provided
      if (empty($azure_config['api_key']) && empty($azure_config['api_key_name'])) {
        $form_state->setErrorByName('azure_embedding][api_key', $this->t('Either API key or key name must be provided when Azure embeddings are enabled.'));
      }
      
      if (empty($azure_config['deployment_name'])) {
        $form_state->setErrorByName('azure_embedding][deployment_name', $this->t('Deployment name is required when Azure embeddings are enabled.'));
      }
    }

    // Validate Azure connection requires SSL
    $connection = $values['connection'] ?? [];
    if (isset($connection['ssl_mode']) && $connection['ssl_mode'] === 'disable') {
      $form_state->setErrorByName('connection][ssl_mode', $this->t('SSL cannot be disabled for Azure Database for PostgreSQL connections.'));
    }

    // Validate performance settings
    $performance = $values['performance'] ?? [];
    if (!empty($performance['work_mem']) && !preg_match('/^\d+[kKmMgG]?[bB]?$/', $performance['work_mem'])) {
      $form_state->setErrorByName('performance][work_mem', $this->t('Work memory must be in format like "256MB" or "1GB".'));
    }

    // Validate hybrid search weights
    if (!empty($values['hybrid_search']['enabled'])) {
      $text_weight = $values['hybrid_search']['text_weight'] ?? 0;
      $vector_weight = $values['hybrid_search']['vector_weight'] ?? 0;
      
      if (($text_weight + $vector_weight) > 1.0) {
        $form_state->setErrorByName('hybrid_search][text_weight', $this->t('Combined text and vector weights cannot exceed 1.0.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();
    
    // Save Azure embedding configuration
    if (isset($values['azure_embedding'])) {
      $this->configuration['azure_embedding'] = $values['azure_embedding'];
    }

    // Save performance configuration
    if (isset($values['performance'])) {
      $this->configuration['performance'] = $values['performance'];
    }

    // Save hybrid search configuration
    if (isset($values['hybrid_search'])) {
      $this->configuration['hybrid_search'] = $values['hybrid_search'];
    }

    // Ensure SSL is required for Azure
    $this->configuration['connection']['ssl_mode'] = 'require';
  }

  /**
   * Gets Azure OpenAI API key using secure key management.
   *
   * @return string
   *   The Azure OpenAI API key.
   */
  protected function getAzureApiKey() {
    $api_key_name = $this->configuration['azure_embedding']['api_key_name'] ?? '';
    $direct_key = $this->configuration['azure_embedding']['api_key'] ?? '';

    return $this->getSecureKey($api_key_name, $direct_key, 'Azure OpenAI API key', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    // Add Azure-specific features
    $features[] = 'search_api_azure_ai_embeddings';
    $features[] = 'search_api_azure_performance';
    
    return $features;
  }

}