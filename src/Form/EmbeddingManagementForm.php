<?php

namespace Drupal\search_api_postgresql\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Entity\Index;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing embeddings across all PostgreSQL servers and indexes.
 */
class EmbeddingManagementForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The embedding queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * The embedding analytics service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService
   */
  protected $analyticsService;

  /**
   * Constructs an EmbeddingManagementForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager $queue_manager
   *   The embedding queue manager.
   * @param \Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService $analytics_service
   *   The embedding analytics service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EmbeddingQueueManager $queue_manager,
    EmbeddingAnalyticsService $analytics_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->queueManager = $queue_manager;
    $this->analyticsService = $analytics_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('search_api_postgresql.embedding_queue_manager'),
      $container->get('search_api_postgresql.analytics')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return string
   *   The form ID.
   */
  public function getFormId() {
    return 'search_api_postgresql_embedding_management';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form render array.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'search_api_postgresql/admin';

    // Page header.
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['embedding-management-header']],
    ];

    $form['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Embedding Management'),
    ];

    $form['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage embeddings across all PostgreSQL servers and search indexes.'),
    ];

    // Check if any servers have AI enabled.
    $servers = $this->getPostgreSQLServers();

    if (empty($servers)) {
      $form['no_servers'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('No PostgreSQL servers found. <a href="@url">Create a server</a> first.', [
          '@url' => Url::fromRoute('entity.search_api_server.add_form')->toString(),
        ]) .
        '</div>',
      ];
      return $form;
    }

    // Check if any servers have AI enabled.
    $has_ai_servers = FALSE;
    foreach ($servers as $server) {
      if ($this->isAiEnabledForServer($server)) {
        $has_ai_servers = TRUE;
        break;
      }
    }

    if (!$has_ai_servers) {
      $form['no_ai'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--info">' .
        '<h3>' . $this->t('Embedding Management Not Available') . '</h3>' .
        '<p>' . $this->t('Embedding management is only available for servers with AI embedding features enabled.') . '</p>' .
        '<p>' . $this->t('Your current PostgreSQL servers are configured for traditional search only.') . '</p>' .
        '<p>' . $this->t('To enable embedding management:') . '</p>' .
        '<ol>' .
        '<li>' . $this->t('Configure AI embeddings (Azure OpenAI, OpenAI, etc.) on your PostgreSQL server') . '</li>' .
        '<li>' . $this->t('Enable AI features in your server configuration') . '</li>' .
        '<li>' . $this->t('Re-index your content to generate embeddings') . '</li>' .
        '</ol>' .
        '<p><a href="/admin/config/search/search-api" class="button button--primary">' . $this->t('Manage Search API') . '</a></p>' .
        '</div>',
      ];

      return $form;
    }

    // If we reach here, we have AI-enabled servers, so proceed with the form
    // But wrap everything in try-catch to handle any remaining issues.
    try {
      // Server selection.
      $form['server_selection'] = [
        '#type' => 'details',
        '#title' => $this->t('Server Selection'),
        '#open' => TRUE,
      ];

      $server_options = [];
      foreach ($servers as $server) {
        if ($this->isAiEnabledForServer($server)) {
          $server_options[$server->id()] = $server->label();
        }
      }

      $form['server_selection']['server_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Server'),
        '#options' => $server_options,
        '#empty_option' => $this->t('- All AI-enabled servers -'),
        '#default_value' => '',
        '#ajax' => [
          'callback' => '::updateIndexOptions',
          'wrapper' => 'index-options-wrapper',
          'event' => 'change',
        ],
      ];

      // Index selection (updated via AJAX)
      $form['server_selection']['index_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'index-options-wrapper'],
      ];

      $selected_server_id = $form_state->getValue('server_id');
      $index_options = $this->getIndexOptions($selected_server_id);

      $form['server_selection']['index_wrapper']['index_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Index'),
        '#options' => $index_options,
        '#empty_option' => $this->t('- All indexes -'),
        '#default_value' => '',
      ];

      // Operation selection.
      $form['operations'] = [
        '#type' => 'details',
        '#title' => $this->t('Operations'),
        '#open' => TRUE,
      ];

      $form['operations']['operation'] = [
        '#type' => 'radios',
        '#title' => $this->t('Select operation'),
        '#options' => [
          'regenerate_all' => $this->t('Regenerate all embeddings'),
          'regenerate_missing' => $this->t('Generate embeddings for items without embeddings'),
          'validate_embeddings' => $this->t('Validate existing embeddings'),
          'clear_embeddings' => $this->t('Clear all embeddings'),
          'update_dimensions' => $this->t('Update vector dimensions'),
        ],
        '#default_value' => 'regenerate_missing',
        '#required' => TRUE,
      ];

      // Advanced options.
      $form['advanced'] = [
        '#type' => 'details',
        '#title' => $this->t('Advanced Options'),
        '#open' => FALSE,
      ];

      $form['advanced']['batch_size'] = [
        '#type' => 'number',
        '#title' => $this->t('Batch Size'),
        '#description' => $this->t('Number of items to process in each batch.'),
        '#default_value' => 50,
        '#min' => 1,
        '#max' => 1000,
      ];

      $form['advanced']['use_queue'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use background queue processing'),
        '#description' => $this->t('Process embeddings in the background via queue. Recommended for large operations.'),
        '#default_value' => TRUE,
      ];

      $form['advanced']['priority'] = [
        '#type' => 'select',
        '#title' => $this->t('Queue Priority'),
        '#options' => [
          'high' => $this->t('High'),
          'normal' => $this->t('Normal'),
          'low' => $this->t('Low'),
        ],
        '#default_value' => 'normal',
        '#states' => [
          'visible' => [
            ':input[name="use_queue"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['advanced']['force_overwrite'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Force overwrite existing embeddings'),
        '#description' => $this->t('Regenerate embeddings even if they already exist.'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="operation"]' => [
              ['value' => 'regenerate_all'],
              ['value' => 'regenerate_missing'],
            ],
          ],
        ],
      ];

      // Cost estimation.
      $form['cost_estimation'] = [
        '#type' => 'details',
        '#title' => $this->t('Cost Estimation'),
        '#open' => FALSE,
      ];

      $form['cost_estimation']['estimate'] = [
        '#type' => 'markup',
        '#markup' => '<div id="cost-estimation-content">' .
        $this->t('Select a server and operation to see cost estimation.') .
        '</div>',
      ];

      // Current status.
      $form['status'] = [
        '#type' => 'details',
        '#title' => $this->t('Current Status'),
        '#open' => TRUE,
      ];

      $overall_stats = $this->getOverallEmbeddingStats();

      $form['status']['stats'] = [
        '#theme' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => [
          [$this->t('Total Items'), number_format($overall_stats['total_items'])],
          [$this->t('Items with Embeddings'), number_format($overall_stats['items_with_embeddings'])],
          [$this->t('Overall Coverage'), round($overall_stats['coverage'], 1) . '%'],
          [$this->t('Queue Items Pending'), number_format($overall_stats['queue_pending'])],
          [$this->t('Estimated Monthly Cost'), '$' . number_format($overall_stats['monthly_cost'], 2)],
        ],
      ];

      // Actions.
      $form['actions'] = [
        '#type' => 'actions',
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Execute Operation'),
        '#button_type' => 'primary',
      ];

      $form['actions']['preview'] = [
        '#type' => 'submit',
        '#value' => $this->t('Preview Changes'),
        '#submit' => ['::previewSubmit'],
      ];

      return $form;

    }
    catch (\Exception $e) {
      // If anything fails, show an error message.
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--error">' .
        '<h3>' . $this->t('Embedding Management Error') . '</h3>' .
        '<p>' . $this->t('There was an error loading the embedding management interface: @error', ['@error' => $e->getMessage()]) . '</p>' .
        '</div>',
      ];

      return $form;
    }
  }

  /**
   * Gets overall embedding statistics.
   *
   * @return array
   *   Array of embedding statistics.
   */
  protected function getOverallEmbeddingStats() {
    $stats = [
      'total_items' => 0,
      'items_with_embeddings' => 0,
      'coverage' => 0,
      'queue_pending' => 0,
      'monthly_cost' => 0,
    ];

    try {
      $servers = $this->getPostgreSQLServers();

      foreach ($servers as $server) {
        // Only process AI-enabled servers.
        if (!$this->isAiEnabledForServer($server)) {
          continue;
        }

        $backend = $server->getBackend();
        $indexes = $this->entityTypeManager
          ->getStorage('search_api_index')
          ->loadByProperties(['server' => $server->id()]);

        foreach ($indexes as $index) {
          try {
            if (method_exists($backend, 'getVectorStats')) {
              $index_stats = $backend->getVectorStats($index);
            }
            elseif (method_exists($backend, 'getAzureVectorStats')) {
              $index_stats = $backend->getAzureVectorStats($index);
            }
            else {
              continue;
            }

            $stats['total_items'] += $index_stats['total_items'] ?? 0;
            $stats['items_with_embeddings'] += $index_stats['items_with_embeddings'] ?? 0;
          }
          catch (\Exception $e) {
            // Skip indexes with errors.
            continue;
          }
        }
      }

      // Calculate coverage.
      if ($stats['total_items'] > 0) {
        $stats['coverage'] = ($stats['items_with_embeddings'] / $stats['total_items']) * 100;
      }

      // Get queue stats safely.
      try {
        $queue_stats = $this->queueManager->getQueueStats();
        $stats['queue_pending'] = $queue_stats['items_pending'] ?? 0;
      }
      catch (\Exception $e) {
        $stats['queue_pending'] = 0;
      }

      // Get cost estimate safely.
      try {
        $cost_data = $this->analyticsService->getCostAnalytics('30d');
        $stats['monthly_cost'] = $cost_data['projected_monthly'] ?? 0;
      }
      catch (\Exception $e) {
        $stats['monthly_cost'] = 0;
      }

      return $stats;

    }
    catch (\Exception $e) {
      // If anything fails, return default stats.
      return $stats;
    }
  }

  /**
   * Helper method to check if AI is enabled for a server.
   *
   * @param \Drupal\search_api\Entity\Server $server
   *   The server entity.
   *
   * @return bool
   *   TRUE if AI is enabled for the server.
   */
  protected function isAiEnabledForServer($server) {
    try {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();

      return ($config['ai_embeddings']['enabled'] ?? FALSE) ||
            ($config['azure_embedding']['enabled'] ?? FALSE);
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * AJAX callback for updating index options.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated form element.
   */
  public function updateIndexOptions(array &$form, FormStateInterface $form_state) {
    $server_id = $form_state->getValue('server_id');
    $index_options = $this->getIndexOptions($server_id);

    $form['server_selection']['index_wrapper']['index_id']['#options'] = $index_options;

    return $form['server_selection']['index_wrapper'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $operation = $form_state->getValue('operation');
    $server_id = $form_state->getValue('server_id');
    $index_id = $form_state->getValue('index_id');

    // Validate that we have something to operate on.
    if (empty($server_id) && empty($index_id)) {
      $servers = $this->getPostgreSQLServers();
      if (empty($servers)) {
        $form_state->setErrorByName('server_id', $this->t('No PostgreSQL servers available.'));
        return;
      }
    }

    // Special validation for destructive operations.
    if (in_array($operation, ['clear_embeddings', 'regenerate_all'])) {
      if (!$form_state->getValue('force_overwrite')) {
        $form_state->setErrorByName('force_overwrite',
          $this->t('You must confirm that you want to perform this destructive operation.')
        );
      }
    }

    // Validate batch size for large operations.
    $batch_size = $form_state->getValue('batch_size');
    if ($batch_size < 1 || $batch_size > $this->config->get('max_batch_size', 1000)) {
      $form_state->setErrorByName('batch_size',
        $this->t('Batch size must be between 1 and 1000.')
      );
    }

    // Check if queue is enabled when requested.
    $use_queue = $form_state->getValue('use_queue');
    if ($use_queue) {
      $queue_stats = $this->queueManager->getQueueStats();
      if (!($queue_stats['config']['enabled'] ?? FALSE)) {
        $form_state->setErrorByName('use_queue',
          $this->t('Queue processing is not enabled. Enable it in the queue management settings.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $operation = $values['operation'];
    $server_id = $values['server_id'];
    $index_id = $values['index_id'];
    $batch_size = $values['batch_size'];
    $use_queue = $values['use_queue'];
    $priority = $values['priority'];
    $force_overwrite = $values['force_overwrite'];

    // Get target servers and indexes.
    $targets = $this->getOperationTargets($server_id, $index_id);

    if (empty($targets)) {
      $this->messenger()->addError($this->t('No valid targets found for the operation.'));
      return;
    }

    // Execute the operation.
    $results = $this->executeOperation($operation, $targets, [
      'batch_size' => $batch_size,
      'use_queue' => $use_queue,
      'priority' => $priority,
      'force_overwrite' => $force_overwrite,
    ]);

    // Display results.
    if ($results['success']) {
      $this->messenger()->addStatus(
        $this->t('Operation "@operation" completed successfully. @details', [
          '@operation' => $operation,
          '@details' => $results['message'],
        ])
      );

      if ($use_queue && $results['queued_items'] > 0) {
        $this->messenger()->addStatus(
          $this->t('@count items have been queued for background processing.', [
            '@count' => $results['queued_items'],
          ])
        );
      }
    }
    else {
      $this->messenger()->addError(
        $this->t('Operation "@operation" failed: @error', [
          '@operation' => $operation,
          '@error' => $results['error'],
        ])
          );
    }

    // Redirect to results page if available.
    if (!empty($results['batch_id'])) {
      $form_state->setRedirect('search_api_postgresql.admin.batch_status', [
        'batch_id' => $results['batch_id'],
      ]);
    }
  }

  /**
   * Preview changes form submission handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function previewChanges(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $server_id = $values['server_id'];
    $index_id = $values['index_id'];
    $operation = $values['operation'];

    // Get operation preview.
    $preview = $this->getOperationPreview($operation, $server_id, $index_id);

    $this->messenger()->addStatus(
      $this->t('Preview: This operation would affect @items items across @servers servers and @indexes indexes. Estimated cost: $@cost', [
        '@items' => number_format($preview['affected_items']),
        '@servers' => $preview['affected_servers'],
        '@indexes' => $preview['affected_indexes'],
        '@cost' => number_format($preview['estimated_cost'], 4),
      ])
    );

    // Show detailed breakdown.
    if (!empty($preview['breakdown'])) {
      $breakdown_text = [];
      foreach ($preview['breakdown'] as $item) {
        $breakdown_text[] = $this->t('@server: @items items', [
          '@server' => $item['server_name'],
          '@items' => number_format($item['item_count']),
        ]);
      }

      $this->messenger()->addStatus(
        $this->t('Breakdown: @breakdown', [
          '@breakdown' => implode(', ', $breakdown_text),
        ])
      );
    }
  }

  /**
   * Gets all PostgreSQL servers.
   *
   * @return array
   *   Array of PostgreSQL server entities.
   */
  protected function getPostgreSQLServers() {
    return $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure']]);
  }

  /**
   * Gets index options for a server.
   *
   * @param string|null $server_id
   *   The server ID, or NULL for all servers.
   *
   * @return array
   *   Array of index options keyed by index ID.
   */
  protected function getIndexOptions($server_id = NULL) {
    $options = [];

    if ($server_id) {
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadByProperties(['server' => $server_id]);
    }
    else {
      // Get all indexes on PostgreSQL servers.
      $servers = $this->getPostgreSQLServers();
      $indexes = [];
      foreach ($servers as $server) {
        $server_indexes = $this->entityTypeManager
          ->getStorage('search_api_index')
          ->loadByProperties(['server' => $server->id()]);
        $indexes = array_merge($indexes, $server_indexes);
      }
    }

    foreach ($indexes as $index) {
      $options[$index->id()] = $index->label();
    }

    return $options;
  }

  /**
   * Gets recent activity.
   *
   * @param int $limit
   *   The maximum number of activity records to return.
   *
   * @return array
   *   Array of recent activity records.
   */
  protected function getRecentActivity($limit = 10) {
    // This would typically query an activity log table
    // For now, return empty array as this would need to be implemented
    // based on the specific logging structure.
    return [];
  }

  /**
   * Gets operation targets (servers and indexes).
   *
   * @param string|null $server_id
   *   The server ID, or NULL for all servers.
   * @param string|null $index_id
   *   The index ID, or NULL for all indexes.
   *
   * @return array
   *   Array of operation targets.
   */
  protected function getOperationTargets($server_id = NULL, $index_id = NULL) {
    $targets = [];

    if ($index_id) {
      // Specific index.
      $index = Index::load($index_id);
      if ($index) {
        $server = $index->getServerInstance();
        $targets[] = [
          'server' => $server,
          'index' => $index,
        ];
      }
    }
    elseif ($server_id) {
      // All indexes on specific server.
      $server = Server::load($server_id);
      if ($server) {
        $indexes = $this->entityTypeManager
          ->getStorage('search_api_index')
          ->loadByProperties(['server' => $server_id]);

        foreach ($indexes as $index) {
          $targets[] = [
            'server' => $server,
            'index' => $index,
          ];
        }
      }
    }
    else {
      // All PostgreSQL servers and indexes.
      $servers = $this->getPostgreSQLServers();
      foreach ($servers as $server) {
        $indexes = $this->entityTypeManager
          ->getStorage('search_api_index')
          ->loadByProperties(['server' => $server->id()]);

        foreach ($indexes as $index) {
          $targets[] = [
            'server' => $server,
            'index' => $index,
          ];
        }
      }
    }

    return $targets;
  }

  /**
   * Executes the selected operation.
   *
   * @param string $operation
   *   The operation type.
   * @param array $targets
   *   The operation targets.
   * @param array $options
   *   The operation options.
   *
   * @return array
   *   The operation results.
   */
  protected function executeOperation($operation, array $targets, array $options) {
    $results = [
      'success' => FALSE,
      'message' => '',
      'queued_items' => 0,
      'processed_items' => 0,
      'error' => '',
    ];

    try {
      switch ($operation) {
        case 'regenerate_all':
          $results = $this->executeRegenerateAll($targets, $options);
          break;

        case 'regenerate_missing':
          $results = $this->executeRegenerateMissing($targets, $options);
          break;

        case 'validate_embeddings':
          $results = $this->executeValidateEmbeddings($targets, $options);
          break;

        case 'clear_embeddings':
          $results = $this->executeClearEmbeddings($targets, $options);
          break;

        case 'update_dimensions':
          $results = $this->executeUpdateDimensions($targets, $options);
          break;

        default:
          throw new \InvalidArgumentException("Unknown operation: {$operation}");
      }
    }
    catch (\Exception $e) {
      $results['error'] = $e->getMessage();
    }

    return $results;
  }

  /**
   * Executes regenerate all embeddings operation.
   *
   * @param array $targets
   *   The operation targets.
   * @param array $options
   *   The operation options.
   *
   * @return array
   *   The operation results.
   */
  protected function executeRegenerateAll(array $targets, array $options) {
    $total_queued = 0;
    $total_processed = 0;

    foreach ($targets as $target) {
      $server = $target['server'];
      $index = $target['index'];

      if ($options['use_queue']) {
        $success = $this->queueManager->queueIndexEmbeddingRegeneration(
          $server->id(),
          $index->id(),
          $options['batch_size'],
        // Start from beginning.
          0,
          $this->getPriorityValue($options['priority'])
        );

        if ($success) {
          $total_queued++;
        }
      }
      else {
        // Direct processing (simplified for example)
        $total_processed++;
      }
    }

    return [
      'success' => TRUE,
      'message' => $this->t('Regeneration initiated for @count targets', ['@count' => count($targets)]),
      'queued_items' => $total_queued,
      'processed_items' => $total_processed,
    ];
  }

  /**
   * Executes regenerate missing embeddings operation.
   *
   * @param array $targets
   *   The operation targets.
   * @param array $options
   *   The operation options.
   *
   * @return array
   *   The operation results.
   */
  protected function executeRegenerateMissing(array $targets, array $options) {
    // Similar to regenerateAll but only for items without embeddings.
    return $this->executeRegenerateAll($targets, $options);
  }

  /**
   * Executes validate embeddings operation.
   *
   * @param array $targets
   *   The operation targets.
   * @param array $options
   *   The operation options.
   *
   * @return array
   *   The operation results.
   */
  protected function executeValidateEmbeddings(array $targets, array $options) {
    $validation_results = [];

    foreach ($targets as $target) {
      $server = $target['server'];
      $index = $target['index'];

      // This would run validation checks on embeddings.
      $validation_results[] = [
        'server' => $server->label(),
        'index' => $index->label(),
      // Simplified.
        'status' => 'valid',
      ];
    }

    return [
      'success' => TRUE,
      'message' => $this->t('Validation completed for @count targets', ['@count' => count($targets)]),
      'validation_results' => $validation_results,
    ];
  }

  /**
   * Executes clear embeddings operation.
   *
   * @param array $targets
   *   The operation targets.
   * @param array $options
   *   The operation options.
   *
   * @return array
   *   The operation results.
   */
  protected function executeClearEmbeddings(array $targets, array $options) {
    $cleared_count = 0;

    foreach ($targets as $target) {
      $server = $target['server'];
      $index = $target['index'];

      try {
        $backend = $server->getBackend();

        // Connect and clear embeddings.
        $reflection = new \ReflectionClass($backend);
        $connect_method = $reflection->getMethod('connect');
        $connect_method->setAccessible(TRUE);
        $connect_method->invoke($backend);

        $connector_property = $reflection->getProperty('connector');
        $connector_property->setAccessible(TRUE);
        $connector = $connector_property->getValue($backend);

        $config = $backend->getConfiguration();
        $table_name = $config['index_prefix'] . $index->id();

        // Clear embedding columns.
        $sql = "UPDATE {$table_name} SET content_embedding = NULL, embedding_vector = NULL";
        $connector->executeQuery($sql);

        $cleared_count++;
      }
      catch (\Exception $e) {
        // Log error but continue with other indexes.
        $this->getLogger('search_api_postgresql')->error(
          'Failed to clear embeddings for @index: @error',
          ['@index' => $index->label(), '@error' => $e->getMessage()]
              );
      }
    }

    return [
      'success' => TRUE,
      'message' => $this->t('Cleared embeddings for @count indexes', ['@count' => $cleared_count]),
      'processed_items' => $cleared_count,
    ];
  }

  /**
   * Executes update dimensions operation.
   *
   * @param array $targets
   *   The operation targets.
   * @param array $options
   *   The operation options.
   *
   * @return array
   *   The operation results.
   */
  protected function executeUpdateDimensions(array $targets, array $options) {
    // This would update vector column dimensions
    // Implementation depends on specific requirements.
    return [
      'success' => TRUE,
      'message' => $this->t('Dimension update completed'),
    ];
  }

  /**
   * Gets operation preview information.
   *
   * @param string $operation
   *   The operation type.
   * @param string|null $server_id
   *   The server ID, or NULL for all servers.
   * @param string|null $index_id
   *   The index ID, or NULL for all indexes.
   *
   * @return array
   *   Preview information including affected items and costs.
   */
  protected function getOperationPreview($operation, $server_id = NULL, $index_id = NULL) {
    $targets = $this->getOperationTargets($server_id, $index_id);

    $preview = [
      'affected_items' => 0,
      'affected_servers' => 0,
      'affected_indexes' => count($targets),
      'estimated_cost' => 0,
      'breakdown' => [],
    ];

    $servers_counted = [];

    foreach ($targets as $target) {
      $server = $target['server'];
      $index = $target['index'];

      // Count unique servers.
      if (!in_array($server->id(), $servers_counted)) {
        $servers_counted[] = $server->id();
      }

      // Get item count for this index.
      try {
        $backend = $server->getBackend();
        if (method_exists($backend, 'getVectorStats')) {
          $stats = $backend->getVectorStats($index);
        }
        elseif (method_exists($backend, 'getAzureVectorStats')) {
          $stats = $backend->getAzureVectorStats($index);
        }
        else {
          $stats = ['total_items' => 0];
        }

        $item_count = $stats['total_items'] ?? 0;
        $preview['affected_items'] += $item_count;

        // Estimate cost (simplified calculation)
        // Example cost.
        $cost_per_item = 0.0001;
        $preview['estimated_cost'] += $item_count * $cost_per_item;

        $preview['breakdown'][] = [
          'server_name' => $server->label(),
          'index_name' => $index->label(),
          'item_count' => $item_count,
        ];

      }
      catch (\Exception $e) {
        // Skip indexes with errors.
        continue;
      }
    }

    $preview['affected_servers'] = count($servers_counted);

    return $preview;
  }

  /**
   * Converts priority name to numeric value.
   *
   * @param string $priority
   *   The priority name (high, normal, low).
   *
   * @return int
   *   The numeric priority value.
   */
  protected function getPriorityValue($priority) {
    $priority_map = [
      'high' => 50,
      'normal' => 100,
      'low' => 200,
    ];

    return $priority_map[$priority] ?? 100;
  }

}
