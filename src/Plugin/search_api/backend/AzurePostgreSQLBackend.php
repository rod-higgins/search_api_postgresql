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
 *   label = @Translation("Azure PostgreSQL with AI Vector Search"),
 *   description = @Translation("Optimized PostgreSQL backend for Azure Database with Azure OpenAI integration.")
 * )
 */
class AzurePostgreSQLBackend extends PostgreSQLBackend {

  use SecureKeyManagementTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    
    // Override defaults for Azure optimization
    $config['connection']['ssl_mode'] = 'require';
    $config['connection']['port'] = 5432;
    
    // Remove the generic ai_embeddings config and replace with Azure-specific
    unset($config['ai_embeddings']);
    
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

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Get base form from parent but modify for Azure specifics
    $form = parent::buildConfigurationForm($form, $form_state);

    // Remove the generic AI embeddings section
    unset($form['ai_embeddings']);

    // Ensure we have default configuration
    $this->configuration = $this->configuration + $this->defaultConfiguration();

    // Modify connection section for Azure
    $form['connection']['#description'] = $this->t('Configure Azure Database for PostgreSQL connection. SSL is required for Azure.');

    // Force SSL mode to require for Azure
    $form['connection']['ssl_mode']['#default_value'] = 'require';
    $form['connection']['ssl_mode']['#description'] = $this->t('SSL is required for Azure Database for PostgreSQL.');
    $form['connection']['ssl_mode']['#disabled'] = TRUE; // Don't allow changing this for Azure

    // Azure Embedding Configuration
    $form['azure_embedding'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AI Embeddings'),
      '#open' => !empty($this->configuration['azure_embedding']['enabled']),
      '#description' => $this->t('Configure Azure OpenAI embeddings for semantic search.'),
    ];

    $form['azure_embedding']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Azure AI Embeddings'),
      '#default_value' => $this->configuration['azure_embedding']['enabled'] ?? FALSE,
      '#description' => $this->t('Enable semantic search using Azure OpenAI embeddings.'),
    ];

    // Azure AI Service Configuration
    $form['azure_embedding']['service'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Service'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['service']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI Endpoint'),
      '#default_value' => $this->configuration['azure_embedding']['endpoint'] ?? '',
      '#description' => $this->t('Your Azure OpenAI service endpoint (e.g., https://your-resource.openai.azure.com/).'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Add API key fields for Azure AI using the trait
    $this->addApiKeyFields($form['azure_embedding']['service'], 'azure_embedding][service', 'azure_embedding');

    $form['azure_embedding']['service']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment Name'),
      '#default_value' => $this->configuration['azure_embedding']['deployment_name'] ?? '',
      '#description' => $this->t('Azure OpenAI deployment name for embeddings.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['azure_embedding']['service']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small (1536 dimensions)'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large (3072 dimensions)'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002 (1536 dimensions)'),
      ],
      '#default_value' => $this->configuration['azure_embedding']['model'] ?? 'text-embedding-3-small',
      '#description' => $this->t('Azure OpenAI embedding model to use.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Vector Index Configuration
    $form['vector_index'] = [
      '#type' => 'details',
      '#title' => $this->t('Vector Index Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['vector_index']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Index Method'),
      '#options' => [
        'ivfflat' => $this->t('IVFFlat (Inverted File with Flat compression)'),
        'hnsw' => $this->t('HNSW (Hierarchical Navigable Small World)'),
      ],
      '#default_value' => $this->configuration['vector_index']['method'] ?? 'ivfflat',
      '#description' => $this->t('Vector index method. HNSW is more accurate but uses more memory.'),
    ];

    $form['vector_index']['lists'] = [
      '#type' => 'number',
      '#title' => $this->t('Lists (IVFFlat only)'),
      '#default_value' => $this->configuration['vector_index']['lists'] ?? 100,
      '#min' => 1,
      '#max' => 32768,
      '#description' => $this->t('Number of inverted lists for IVFFlat index.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    $form['vector_index']['probes'] = [
      '#type' => 'number',
      '#title' => $this->t('Probes (IVFFlat only)'),
      '#default_value' => $this->configuration['vector_index']['probes'] ?? 10,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Number of probes for IVFFlat search.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[vector_index][method]"]' => ['value' => 'ivfflat'],
        ],
      ],
    ];

    // Hybrid Search Configuration
    $form['hybrid_search'] = [
      '#type' => 'details',
      '#title' => $this->t('Hybrid Search Settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[azure_embedding][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hybrid_search']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Hybrid Search'),
      '#default_value' => $this->configuration['hybrid_search']['enabled'] ?? TRUE,
      '#description' => $this->t('Combine traditional text search with vector similarity search.'),
    ];

    $form['hybrid_search']['text_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Text Search Weight'),
      '#default_value' => $this->configuration['hybrid_search']['text_weight'] ?? 0.6,
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
      '#default_value' => $this->configuration['hybrid_search']['vector_weight'] ?? 0.4,
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

    $form['hybrid_search']['similarity_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity Threshold'),
      '#default_value' => $this->configuration['hybrid_search']['similarity_threshold'] ?? 0.15,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#description' => $this->t('Minimum similarity score for vector search results.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[hybrid_search][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance Settings
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure Performance Settings'),
      '#open' => FALSE,
    ];

    $form['performance']['connection_pool_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection Pool Size'),
      '#default_value' => $this->configuration['performance']['connection_pool_size'] ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Maximum number of database connections to maintain.'),
    ];

    $form['performance']['statement_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Statement Timeout (ms)'),
      '#default_value' => $this->configuration['performance']['statement_timeout'] ?? 30000,
      '#min' => 1000,
      '#max' => 300000,
      '#description' => $this->t('Maximum time to wait for a database query to complete.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Azure-specific validations
    if (!empty($values['azure_embedding']['enabled'])) {
      $azure_config = $values['azure_embedding']['service'] ?? [];
      
      if (empty($azure_config['endpoint'])) {
        $form_state->setErrorByName('azure_embedding][service][endpoint', 
          $this->t('Azure OpenAI endpoint is required when AI embeddings are enabled.'));
      }

      // Validate endpoint format
      if (!empty($azure_config['endpoint']) && !preg_match('/^https:\/\/.*\.openai\.azure\.com\/?$/', $azure_config['endpoint'])) {
        $form_state->setErrorByName('azure_embedding][service][endpoint', 
          $this->t('Azure OpenAI endpoint must be in the format: https://your-resource.openai.azure.com/'));
      }

      // Validate API key configuration
      $api_key_name = $azure_config['api_key_name'] ?? '';
      $api_key = $azure_config['api_key'] ?? '';
      
      if (empty($api_key_name) && empty($api_key)) {
        $form_state->setErrorByName('azure_embedding][service][api_key', 
          $this->t('Either an API key or a key reference is required when AI embeddings are enabled.'));
      }

      if (!empty($api_key_name) && !empty($api_key)) {
        $form_state->setErrorByName('azure_embedding][service][api_key', 
          $this->t('Please use either a key reference OR direct API key, not both.'));
      }

      if (empty($azure_config['deployment_name'])) {
        $form_state->setErrorByName('azure_embedding][service][deployment_name', 
          $this->t('Deployment name is required when AI embeddings are enabled.'));
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

    // SSL mode must be 'require' for Azure
    if (($values['connection']['ssl_mode'] ?? '') !== 'require') {
      $form_state->setErrorByName('connection][ssl_mode', 
        $this->t('SSL mode must be set to "require" for Azure Database for PostgreSQL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Save Azure-specific configuration
    if (isset($values['azure_embedding'])) {
      $this->configuration['azure_embedding'] = $values['azure_embedding'];
      
      // Flatten service config into main azure_embedding config
      if (isset($values['azure_embedding']['service'])) {
        foreach ($values['azure_embedding']['service'] as $key => $value) {
          $this->configuration['azure_embedding'][$key] = $value;
        }
        unset($this->configuration['azure_embedding']['service']);
      }
    }

    // Save vector index configuration
    if (isset($values['vector_index'])) {
      $this->configuration['vector_index'] = $values['vector_index'];
    }

    // Save hybrid search configuration
    if (isset($values['hybrid_search'])) {
      $this->configuration['hybrid_search'] = $values['hybrid_search'];
    }

    // Save performance configuration
    if (isset($values['performance'])) {
      $this->configuration['performance'] = $values['performance'];
    }

    // Force SSL mode to require for Azure
    $this->configuration['connection']['ssl_mode'] = 'require';
  }

  /**
   * Get Azure API key considering key configuration.
   *
   * @return string
   *   The Azure API key.
   */
  protected function getAzureApiKey() {
    $azure_config = $this->configuration['azure_embedding'] ?? [];
    $api_key_name = $azure_config['api_key_name'] ?? '';
    $direct_key = $azure_config['api_key'] ?? '';

    return $this->getSecureKey($api_key_name, $direct_key, 'Azure API key', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    $features = parent::getSupportedFeatures();
    
    // Add Azure-specific features
    if (!empty($this->configuration['azure_embedding']['enabled'])) {
      $features[] = 'semantic_search';
      $features[] = 'vector_search';
      $features[] = 'hybrid_search';
    }
    
    return $features;
  }

}