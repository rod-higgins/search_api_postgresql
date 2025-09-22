<?php

namespace Drupal\search_api_postgresql\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing embedding queue processing.
 */
class QueueManagementForm extends FormBase {

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
   * Constructs a QueueManagementForm.
   */
  public function __construct(
    EmbeddingQueueManager $queue_manager,
    EmbeddingAnalyticsService $analytics_service,
  ) {
    $this->queueManager = $queue_manager;
    $this->analyticsService = $analytics_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('search_api_postgresql.embedding_queue_manager'),
      $container->get('search_api_postgresql.analytics')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_postgresql_queue_management';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'search_api_postgresql/admin';

    // Page header.
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-management-header']],
    ];

    $form['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Queue Management'),
    ];

    $form['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage the embedding processing queue for background operations.'),
    ];

    // Queue statistics.
    $queue_stats = $this->queueManager->getQueueStats();

    $form['stats'] = [
      '#type' => 'details',
      '#title' => $this->t('Queue Statistics'),
      '#open' => TRUE,
    ];

    $form['stats']['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Metric'),
        $this->t('Value'),
      ],
      '#rows' => [
        [$this->t('Items in queue'), number_format($queue_stats['total_items'] ?? 0)],
        [$this->t('Items being processed'), number_format($queue_stats['processing_items'] ?? 0)],
        [$this->t('Failed items'), number_format($queue_stats['failed_items'] ?? 0)],
        [$this->t('Completed today'), number_format($queue_stats['completed_today'] ?? 0)],
        [$this->t('Average processing time'), ($queue_stats['avg_processing_time'] ?? 0) . ' seconds'],
        [$this->t('Queue enabled'), ($queue_stats['config']['enabled'] ?? FALSE) ? $this->t('Yes') : $this->t('No')],
      ],
    ];

    // Queue actions.
    $form['actions_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Queue Actions'),
      '#open' => TRUE,
    ];

    $form['actions_section']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => [
        'process_now' => $this->t('Process queue now'),
        'clear_failed' => $this->t('Clear failed items'),
        'clear_all' => $this->t('Clear all items'),
        'pause_queue' => $this->t('Pause queue processing'),
        'resume_queue' => $this->t('Resume queue processing'),
        'requeue_failed' => $this->t('Requeue failed items'),
      ],
      '#default_value' => 'process_now',
      '#required' => TRUE,
    ];

    $form['actions_section']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#description' => $this->t('Number of items to process at once.'),
      '#default_value' => $queue_stats['config']['batch_size'] ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#states' => [
        'visible' => [
          ':input[name="action"]' => ['value' => 'process_now'],
        ],
      ],
    ];

    $form['actions_section']['confirm_destructive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this action cannot be undone'),
      '#states' => [
        'visible' => [
          ':input[name="action"]' => [
            ['value' => 'clear_failed'],
            ['value' => 'clear_all'],
          ],
        ],
      ],
    ];

    // Queue configuration.
    $form['config'] = [
      '#type' => 'details',
      '#title' => $this->t('Queue Configuration'),
      '#open' => FALSE,
    ];

    $config = $queue_stats['config'] ?? [];

    $form['config']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable queue processing'),
      '#default_value' => $config['enabled'] ?? FALSE,
      '#description' => $this->t('Enable background queue processing for embeddings.'),
    ];

    $form['config']['default_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Default batch size'),
      '#description' => $this->t('Default number of items to process in each batch.'),
      '#default_value' => $config['batch_size'] ?? 10,
      '#min' => 1,
      '#max' => 100,
    ];

    $form['config']['max_processing_time'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum processing time (seconds)'),
      '#description' => $this->t('Maximum time to spend processing queue items in each run.'),
      '#default_value' => $config['max_processing_time'] ?? 50,
      '#min' => 10,
      '#max' => 300,
    ];

    $form['config']['retry_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Retry attempts'),
      '#description' => $this->t('Number of times to retry failed items.'),
      '#default_value' => $config['retry_attempts'] ?? 3,
      '#min' => 0,
      '#max' => 10,
    ];

    $form['config']['retry_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Retry delay (seconds)'),
      '#description' => $this->t('Delay between retry attempts.'),
      '#default_value' => $config['retry_delay'] ?? 60,
      '#min' => 30,
      '#max' => 3600,
    ];

    // Recent queue activity.
    $form['activity'] = [
      '#type' => 'details',
      '#title' => $this->t('Recent Activity'),
      '#open' => FALSE,
    ];

    $recent_activity = $this->queueManager->getRecentActivity(20);

    if (!empty($recent_activity)) {
      $activity_rows = [];
      foreach ($recent_activity as $activity) {
        $activity_rows[] = [
          date('Y-m-d H:i:s', $activity['timestamp']),
          $activity['operation'],
          $activity['items_processed'] ?? 0,
          $activity['status'],
          ($activity['duration'] ?? 0) . ' ms',
        ];
      }

      $form['activity']['table'] = [
        '#theme' => 'table',
        '#header' => [
          $this->t('Time'),
          $this->t('Operation'),
          $this->t('Items'),
          $this->t('Status'),
          $this->t('Duration'),
        ],
        '#rows' => $activity_rows,
      ];
    }
    else {
      $form['activity']['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No recent activity found.') . '</p>',
      ];
    }

    // Submit buttons.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Execute Action'),
      '#button_type' => 'primary',
    ];

    $form['actions']['save_config'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Configuration'),
      '#submit' => ['::submitConfiguration'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getValue('action');

    // Validate destructive actions.
    if (in_array($action, ['clear_failed', 'clear_all'])) {
      $confirm = $form_state->getValue('confirm_destructive');
      if (!$confirm) {
        $form_state->setErrorByName('confirm_destructive',
          $this->t('You must confirm that you understand this action cannot be undone.')
        );
      }
    }

    // Validate batch size.
    if ($action === 'process_now') {
      $batch_size = $form_state->getValue('batch_size');
      if ($batch_size < 1 || $batch_size > 100) {
        $form_state->setErrorByName('batch_size',
          $this->t('Batch size must be between 1 and 100.')
        );
      }
    }

    // Validate configuration values.
    $max_processing_time = $form_state->getValue('max_processing_time');
    if ($max_processing_time < 10 || $max_processing_time > 300) {
      $form_state->setErrorByName('max_processing_time',
        $this->t('Maximum processing time must be between 10 and 300 seconds.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getValue('action');
    $batch_size = $form_state->getValue('batch_size');

    switch ($action) {
      case 'process_now':
        $result = $this->queueManager->processQueue($batch_size);
        $this->messenger()->addStatus($this->t('Processed @count items from the queue.', [
          '@count' => $result['processed'],
        ]));
        break;

      case 'clear_failed':
        $result = $this->queueManager->clearFailedItems();
        $this->messenger()->addStatus($this->t('Cleared @count failed items from the queue.', [
          '@count' => $result['cleared'],
        ]));
        break;

      case 'clear_all':
        $result = $this->queueManager->clearAllItems();
        $this->messenger()->addStatus($this->t('Cleared all items from the queue.'));
        break;

      case 'pause_queue':
        $this->queueManager->pauseQueue();
        $this->messenger()->addStatus($this->t('Queue processing has been paused.'));
        break;

      case 'resume_queue':
        $this->queueManager->resumeQueue();
        $this->messenger()->addStatus($this->t('Queue processing has been resumed.'));
        break;

      case 'requeue_failed':
        $result = $this->queueManager->requeueFailedItems();
        $this->messenger()->addStatus($this->t('Requeued @count failed items.', [
          '@count' => $result['requeued'],
        ]));
        break;
    }

    // Log the action.
    $this->getLogger('search_api_postgresql')->info('Queue management action "@action" executed.', [
      '@action' => $action,
    ]);
  }

  /**
   * Submit handler for configuration form.
   */
  public function submitConfiguration(array &$form, FormStateInterface $form_state) {
    $config = [
      'enabled' => $form_state->getValue('enabled'),
      'batch_size' => $form_state->getValue('default_batch_size'),
      'max_processing_time' => $form_state->getValue('max_processing_time'),
      'retry_attempts' => $form_state->getValue('retry_attempts'),
      'retry_delay' => $form_state->getValue('retry_delay'),
    ];

    $this->queueManager->updateConfiguration($config);

    $this->messenger()->addStatus($this->t('Queue configuration has been saved.'));
  }

}
