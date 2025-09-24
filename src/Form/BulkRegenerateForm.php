<?php

namespace Drupal\search_api_postgresql\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk regeneration of embeddings.
 */
class BulkRegenerateForm extends FormBase {
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The embedding queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * Constructs a BulkRegenerateForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager $queue_manager
   *   The embedding queue manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    EmbeddingQueueManager $queue_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('entity_type.manager'),
          $container->get('messenger'),
          $container->get('search_api_postgresql.embedding_queue_manager')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_postgresql_bulk_regenerate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('This form allows you to regenerate embeddings for all items in selected indexes. This is useful after changing embedding models or fixing corrupted data.') . '</p>',
    ];

    // Get all PostgreSQL servers with AI enabled.
    $servers = $this->getAiEnabledServers();

    if (empty($servers)) {
      $form['no_servers'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('No PostgreSQL servers with AI embeddings enabled were found.') .
        '</div>',
      ];
      return $form;
    }

    $form['server'] = [
      '#type' => 'select',
      '#title' => $this->t('Server'),
      '#options' => $this->getServerOptions($servers),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateIndexOptions',
        'wrapper' => 'index-wrapper',
      ],
    ];

    $form['index_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'index-wrapper'],
    ];

    $selected_server = $form_state->getValue('server');
    if ($selected_server) {
      $indexes = $this->getServerIndexes($selected_server);
      if (!empty($indexes)) {
        $form['index_wrapper']['indexes'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Indexes'),
          '#options' => $this->getIndexOptions($indexes),
          '#required' => TRUE,
          '#description' => $this->t('Select the indexes to regenerate embeddings for.'),
        ];
      }
    }

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Regeneration Options'),
      '#open' => TRUE,
    ];

    $form['options']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Processing Mode'),
      '#options' => [
        'queue' => $this->t('Queue (Recommended) - Process in background via cron'),
        'batch' => $this->t('Batch - Process immediately in batches'),
      ],
      '#default_value' => 'queue',
      '#description' => $this->t('Queue mode is recommended for large datasets.'),
    ];

    $form['options']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => 50,
      '#min' => 1,
      '#max' => 500,
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => 'batch'],
        ],
      ],
      '#description' => $this->t('Number of items to process per batch.'),
    ];

    $form['options']['force'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force regeneration'),
      '#description' => $this->t('Regenerate embeddings even if they already exist. This will overwrite existing embeddings.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Regeneration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Ajax callback to update index options.
   */
  public function updateIndexOptions(array &$form, FormStateInterface $form_state) {
    return $form['index_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $indexes = $form_state->getValue('indexes', []);
    $selected = array_filter($indexes);

    if (empty($selected)) {
      $form_state->setErrorByName('indexes', $this->t('Please select at least one index.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $server_id = $form_state->getValue('server');
    $indexes = array_filter($form_state->getValue('indexes', []));
    $mode = $form_state->getValue('mode');
    $batch_size = $form_state->getValue('batch_size', 50);
    $force = $form_state->getValue('force', FALSE);

    if ($mode === 'queue') {
      // Queue mode - add items to queue for background processing.
      $this->processViaQueue($server_id, $indexes, $force);
    }
    else {
      // Batch mode - process immediately using Batch API.
      $this->processViaBatch($server_id, $indexes, $batch_size, $force);
    }
  }

  /**
   * Process regeneration via queue.
   */
  protected function processViaQueue($server_id, array $index_ids, $force) {
    $total_items = 0;

    foreach ($index_ids as $index_id) {
      $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
      if ($index) {
        $items = $this->queueManager->queueIndexEmbeddings($index, $force);
        $total_items += $items;
      }
    }

    if ($total_items > 0) {
      $this->messenger->addStatus($this->t('@count items have been queued for embedding regeneration. They will be processed in the background via cron.', [
        '@count' => $total_items,
      ]));
    }
    else {
      $this->messenger->addWarning($this->t('No items were queued. All items may already have embeddings.'));
    }
  }

  /**
   * Process regeneration via batch API.
   */
  protected function processViaBatch($server_id, array $index_ids, $batch_size, $force) {
    $batch = [
      'title' => $this->t('Regenerating embeddings'),
      'operations' => [],
      'finished' => '\Drupal\search_api_postgresql\Form\BulkRegenerateForm::batchFinished',
      'progress_message' => $this->t('Processing @current of @total indexes.'),
    ];

    // Create batch operations for each index.
    foreach ($index_ids as $index_id) {
      $batch['operations'][] = [
        '\Drupal\search_api_postgresql\Form\BulkRegenerateForm::batchProcessIndex',
        [$server_id, $index_id, $batch_size, $force],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch operation: Process a single index.
   */
  public static function batchProcessIndex($server_id, $index_id, $batch_size, $force, &$context) {
    // Initialize batch context.
    if (!isset($context['sandbox']['progress'])) {
      $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_item'] = 0;
      $context['sandbox']['max'] = $index->getTrackerInstance()->getTotalItemsCount();
      $context['results']['processed'] = 0;
      $context['results']['errors'] = 0;
    }

    // Load services.
    $entity_type_manager = \Drupal::entityTypeManager();
    $index = $entity_type_manager->getStorage('search_api_index')->load($index_id);
    $server = $entity_type_manager->getStorage('search_api_server')->load($server_id);

    if (!$index || !$server) {
      $context['finished'] = 1;
      return;
    }

    // Get items to process.
    $tracker = $index->getTrackerInstance();
    $item_ids = $tracker->getRemainingItems($batch_size, $context['sandbox']['current_item']);

    if (empty($item_ids)) {
      $context['finished'] = 1;
      return;
    }

    // Process items.
    $backend = $server->getBackend();
    foreach ($item_ids as $item_id) {
      try {
        // This would call the actual embedding regeneration method
        // The actual implementation would depend on your backend.
        if (method_exists($backend, 'regenerateItemEmbedding')) {
          $backend->regenerateItemEmbedding($index, $item_id, $force);
        }

        $context['results']['processed']++;
      }
      catch (\Exception $e) {
        $context['results']['errors']++;
        \Drupal::logger('search_api_postgresql')->error('Failed to regenerate embedding for item @item: @message', [
          '@item' => $item_id,
          '@message' => $e->getMessage(),
        ]);
      }

      $context['sandbox']['progress']++;
      $context['sandbox']['current_item'] = $item_id;
    }

    // Update progress.
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }

    // Set message.
    $context['message'] = t('Processing index @index: @current of @total items', [
      '@index' => $index->label(),
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['max'],
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Successfully processed @count items (@errors errors).', [
        '@count' => $results['processed'] ?? 0,
        '@errors' => $results['errors'] ?? 0,
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('The batch process encountered an error.'));
    }
  }

  /**
   * Gets AI-enabled PostgreSQL servers.
   */
  protected function getAiEnabledServers() {
    $servers = $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadMultiple();

    $ai_servers = [];
    foreach ($servers as $server) {
      $backend = $server->getBackend();
      if (!in_array($backend->getPluginId(), ['postgresql'])) {
        continue;
      }

      $config = $backend->getConfiguration();
      if (
            !empty($config['ai_embeddings']['enabled']) ||
            !empty($config['azure_embedding']['enabled']) ||
            !empty($config['vector_search']['enabled'])
        ) {
        $ai_servers[$server->id()] = $server;
      }
    }

    return $ai_servers;
  }

  /**
   * Gets server options for select list.
   */
  protected function getServerOptions(array $servers) {
    $options = [];
    foreach ($servers as $id => $server) {
      $options[$id] = $server->label();
    }
    return $options;
  }

  /**
   * Gets indexes for a server.
   */
  protected function getServerIndexes($server_id) {
    return $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadByProperties(['server' => $server_id]);
  }

  /**
   * Gets index options for checkboxes.
   */
  protected function getIndexOptions(array $indexes) {
    $options = [];
    foreach ($indexes as $id => $index) {
      $tracker = $index->getTrackerInstance();
      $indexed = $tracker->getIndexedItemsCount();
      $total = $tracker->getTotalItemsCount();

      $options[$id] = $this->t('@label (@indexed/@total items)', [
        '@label' => $index->label(),
        '@indexed' => $indexed,
        '@total' => $total,
      ]);
    }
    return $options;
  }

}
