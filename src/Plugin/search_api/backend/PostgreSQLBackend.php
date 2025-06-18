<?php

namespace Drupal\search_api_postgresql\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PostgreSQL search backend.
 *
 * @SearchApiBackend(
 *   id = "postgresql",
 *   label = @Translation("PostgreSQL with Azure AI Vector Search"),
 *   description = @Translation("PostgreSQL backend with Azure OpenAI embeddings for semantic search.")
 * )
 */
class PostgreSQLBackend extends BackendPluginBase implements PluginFormInterface, ContainerFactoryPluginInterface {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface|null
   */
  protected $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    
    // Key repository is optional
    if ($container->has('key.repository')) {
      $instance->keyRepository = $container->get('key.repository');
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
        'azure_ai' => [
          'endpoint' => '',
          'api_key' => '',
          'api_key_name' => '',
          'deployment_name' => '',
          'model' => 'text-embedding-ada-002',
          'dimension' => 1536,
        ],
        'vector_index' => [
          'method' => 'ivfflat',
          'ivfflat_lists' => 100,
          'hnsw_m' => 16,
          'hnsw_ef_construction' => 64,
        ],
        'hybrid_search' => [
          'enabled' => TRUE,
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
    // Connection settings - matches schema structure
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Database Connection'),
      '#open' => TRUE,
    ];

    $form['connection']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database host'),
      '#default_value' => $this->configuration['connection']['host'],
      '#required' => TRUE,
    ];

    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Database port'),
      '#default_value' => $this->configuration['connection']['port'],
      '#min' => 1,
      '#max' => 65535,
      '#required' => TRUE,
    ];

    $form['connection']['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database name'),
      '#default_value' => $this->configuration['connection']['database'],
      '#required' => TRUE,
    ];

    $form['connection']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['connection']['username'],
      '#required' => TRUE,
    ];

    $form['connection']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#default_value' => '',
      '#description' => $this->t('Leave empty for passwordless connections.'),
    ];

    // Add Key module integration if available
    if ($this->keyRepository) {
      $key_options = [];
      foreach ($this->keyRepository->getKeys() as $key) {
        $key_options[$key->id()] = $key->label();
      }

      if (!empty($key_options)) {
        $form['connection']['password_key'] = [
          '#type' => 'select',
          '#title' => $this->t('Password key (Key module)'),
          '#options' => $key_options,
          '#empty_option' => $this->t('- Use direct password -'),
          '#default_value' => $this->configuration['connection']['password_key'],
        ];

        $form['connection']['password']['#states'] = [
          'visible' => [
            ':input[name="backend_config[connection][password_key]"]' => ['value' => ''],
          ],
        ];
      }
    }

    $form['connection']['ssl_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('SSL Mode'),
      '#options' => [
        'disable' => $this->t('Disable'),
        'require' => $this->t('Require'),
        'verify-ca' => $this->t('Verify CA'),
        'verify-full' => $this->t('Verify Full'),
      ],
      '#default_value' => $this->configuration['connection']['ssl_mode'],
    ];

    // Index settings
    $form['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index prefix'),
      '#default_value' => $this->configuration['index_prefix'],
      '#description' => $this->t('Prefix for search index table names.'),
    ];

    $form['fts_configuration'] = [
      '#type' => 'select',
      '#title' => $this->t('Full-text search configuration'),
      '#options' => [
        'english' => $this->t('English'),
        'simple' => $this->t('Simple'),
        'german' => $this->t('German'),
        'french' => $this->t('French'),
        'spanish' => $this->t('Spanish'),
      ],
      '#default_value' => $this->configuration['fts_configuration'],
    ];

    // AI Embeddings - matches schema structure exactly
    $form['ai_embeddings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Embeddings'),
      '#open' => !empty($this->configuration['ai_embeddings']['enabled']),
    ];

    $form['ai_embeddings']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI embeddings'),
      '#default_value' => $this->configuration['ai_embeddings']['enabled'],
    ];

    // Azure AI section - matches schema azure_ai mapping
    $form['ai_embeddings']['azure_ai'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure OpenAI Configuration'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure OpenAI endpoint'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['endpoint'],
      '#description' => $this->t('Your Azure OpenAI service endpoint.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['deployment_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment name'),
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['deployment_name'],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_embeddings']['azure_ai']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large'),
        'text-embedding-ada-002' => $this->t('text-embedding-ada-002'),
      ],
      '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['model'],
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // API Key fields
    $form['ai_embeddings']['azure_ai']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#default_value' => '',
      '#states' => [
        'visible' => [
          ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    if ($this->keyRepository) {
      $key_options = [];
      foreach ($this->keyRepository->getKeys() as $key) {
        $key_options[$key->id()] = $key->label();
      }

      if (!empty($key_options)) {
        $form['ai_embeddings']['azure_ai']['api_key_name'] = [
          '#type' => 'select',
          '#title' => $this->t('API key (Key module)'),
          '#options' => $key_options,
          '#empty_option' => $this->t('- Use direct API key -'),
          '#default_value' => $this->configuration['ai_embeddings']['azure_ai']['api_key_name'],
          '#states' => [
            'visible' => [
              ':input[name="backend_config[ai_embeddings][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];

        $form['ai_embeddings']['azure_ai']['api_key']['#states']['visible'][] = [
          ':input[name="backend_config[ai_embeddings][azure_ai][api_key_name]"]' => ['value' => ''],
        ];
      }
    }

    // Advanced settings
    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#default_value' => $this->configuration['debug'],
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $this->configuration['batch_size'],
      '#min' => 1,
      '#max' => 1000,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    
    // Validate connection
    if (empty($values['connection']['host'])) {
      $form_state->setErrorByName('connection][host', $this->t('Host is required.'));
    }
    
    if (empty($values['connection']['database'])) {
      $form_state->setErrorByName('connection][database', $this->t('Database name is required.'));
    }
    
    if (empty($values['connection']['username'])) {
      $form_state->setErrorByName('connection][username', $this->t('Username is required.'));
    }

    // Validate AI settings if enabled
    if (!empty($values['ai_embeddings']['enabled'])) {
      if (empty($values['ai_embeddings']['azure_ai']['endpoint'])) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][endpoint', 
          $this->t('Azure OpenAI endpoint is required when AI embeddings are enabled.'));
      }
      
      $has_direct_key = !empty($values['ai_embeddings']['azure_ai']['api_key']);
      $has_key_module_key = !empty($values['ai_embeddings']['azure_ai']['api_key_name']);
      
      if (!$has_direct_key && !$has_key_module_key) {
        $form_state->setErrorByName('ai_embeddings][azure_ai][api_key', 
          $this->t('API key is required when AI embeddings are enabled.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = [];

    $info[] = [
      'label' => $this->t('Database host'),
      'info' => $this->configuration['connection']['host'] ?? 'localhost',
    ];

    $info[] = [
      'label' => $this->t('Database name'),
      'info' => $this->configuration['connection']['database'] ?? '',
    ];

    if (!empty($this->configuration['ai_embeddings']['enabled'])) {
      $info[] = [
        'label' => $this->t('AI Embeddings'),
        'info' => $this->t('Enabled'),
      ];
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return extension_loaded('pdo_pgsql');
  }

  /**
   * {@inheritdoc}
   */
  public function supportsFeature($feature) {
    $supported = [
      'search_api_autocomplete',
      'search_api_facets',
    ];

    return in_array($feature, $supported);
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
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
    return TRUE;
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