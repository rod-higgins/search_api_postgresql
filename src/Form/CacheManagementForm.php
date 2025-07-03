<?php

namespace Drupal\search_api_postgresql\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;
use Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing embedding cache.
 */
class CacheManagementForm extends FormBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The embedding cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

  /**
   * The embedding analytics service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService
   */
  protected $analyticsService;

  /**
   * Constructs a CacheManagementForm.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EmbeddingCacheManager $cache_manager,
    EmbeddingAnalyticsService $analytics_service
  ) {
    $this->configFactory = $config_factory;
    $this->cacheManager = $cache_manager;
    $this->analyticsService = $analytics_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('search_api_postgresql.cache_manager'),
      $container->get('search_api_postgresql.analytics')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_postgresql_cache_management';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'search_api_postgresql/admin';

    // Page header
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cache-management-header']],
    ];

    $form['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Embedding Cache Management'),
    ];

    $form['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage the embedding cache to improve performance and reduce API costs.'),
    ];

    // Cache statistics
    $cache_stats = $this->cacheManager->getStatistics();
    
    $form['stats'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Statistics'),
      '#open' => TRUE,
    ];

    $form['stats']['table'] = [
      '#theme' => 'table',
      '#header' => [
        $this->t('Metric'),
        $this->t('Value'),
      ],
      '#rows' => [
        [$this->t('Cache hits'), number_format($cache_stats['hits'] ?? 0)],
        [$this->t('Cache misses'), number_format($cache_stats['misses'] ?? 0)],
        [$this->t('Hit rate'), sprintf('%.2f%%', ($cache_stats['hit_rate'] ?? 0) * 100)],
        [$this->t('Total entries'), number_format($cache_stats['total_entries'] ?? 0)],
        [$this->t('Cache size'), $this->formatBytes($cache_stats['cache_size'] ?? 0)],
        [$this->t('Estimated savings'), '$' . number_format($cache_stats['estimated_savings'] ?? 0, 2)],
      ],
    ];

    // Cache actions
    $form['actions_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Actions'),
      '#open' => TRUE,
    ];

    $form['actions_section']['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => [
        'clear_all' => $this->t('Clear all cache'),
        'clear_expired' => $this->t('Clear expired entries'),
        'clear_by_age' => $this->t('Clear entries older than specified age'),
        'optimize' => $this->t('Optimize cache (remove fragmentation)'),
        'export_stats' => $this->t('Export statistics'),
      ],
      '#default_value' => 'clear_expired',
      '#required' => TRUE,
    ];

    $form['actions_section']['age_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Age threshold (days)'),
      '#description' => $this->t('Only used for "Clear entries older than specified age" action.'),
      '#default_value' => 30,
      '#min' => 1,
      '#max' => 365,
      '#states' => [
        'visible' => [
          ':input[name="action"]' => ['value' => 'clear_by_age'],
        ],
      ],
    ];

    $form['actions_section']['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this action cannot be undone'),
      '#states' => [
        'visible' => [
          ':input[name="action"]' => ['value' => 'clear_all'],
        ],
      ],
    ];

    // Cache configuration
    $form['config'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache Configuration'),
      '#open' => FALSE,
    ];

    $config = $this->configFactory->get('search_api_postgresql.cache');

    $form['config']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable embedding cache'),
      '#default_value' => $config->get('enabled') ?? TRUE,
      '#description' => $this->t('Disable to turn off all embedding caching.'),
    ];

    $form['config']['default_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Default TTL (seconds)'),
      '#description' => $this->t('Default time-to-live for cached embeddings.'),
      '#default_value' => $config->get('default_ttl') ?? 604800, // 7 days
      '#min' => 3600, // 1 hour
      '#max' => 2592000, // 30 days
    ];

    $form['config']['max_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum cache size (MB)'),
      '#description' => $this->t('Maximum size of the cache in megabytes.'),
      '#default_value' => $config->get('max_size') ?? 100,
      '#min' => 10,
      '#max' => 10000,
    ];

    $form['config']['cleanup_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Cleanup frequency'),
      '#description' => $this->t('How often to automatically clean up expired entries.'),
      '#options' => [
        'never' => $this->t('Never (manual only)'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
        'monthly' => $this->t('Monthly'),
      ],
      '#default_value' => $config->get('cleanup_frequency') ?? 'weekly',
    ];

    // Submit buttons
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
    
    if ($action === 'clear_all') {
      $confirm = $form_state->getValue('confirm');
      if (!$confirm) {
        $form_state->setErrorByName('confirm', 
          $this->t('You must confirm that you understand this action cannot be undone.')
        );
      }
    }

    if ($action === 'clear_by_age') {
      $age_threshold = $form_state->getValue('age_threshold');
      if ($age_threshold < 1 || $age_threshold > 365) {
        $form_state->setErrorByName('age_threshold', 
          $this->t('Age threshold must be between 1 and 365 days.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getValue('action');
    $age_threshold = $form_state->getValue('age_threshold');

    switch ($action) {
      case 'clear_all':
        $result = $this->cacheManager->clearAll();
        $this->messenger()->addStatus($this->t('All cache entries have been cleared.'));
        break;

      case 'clear_expired':
        $result = $this->cacheManager->clearExpired();
        $this->messenger()->addStatus($this->t('Expired cache entries have been cleared.'));
        break;

      case 'clear_by_age':
        $result = $this->cacheManager->clearByAge($age_threshold);
        $this->messenger()->addStatus($this->t('Cache entries older than @days days have been cleared.', [
          '@days' => $age_threshold,
        ]));
        break;

      case 'optimize':
        $result = $this->cacheManager->optimize();
        $this->messenger()->addStatus($this->t('Cache has been optimized.'));
        break;

      case 'export_stats':
        $this->exportStatistics();
        return;
    }

    // Log the action
    $this->getLogger('search_api_postgresql')->info('Cache management action "@action" executed.', [
      '@action' => $action,
    ]);
  }

  /**
   * Submit handler for configuration form.
   */
  public function submitConfiguration(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('search_api_postgresql.cache');
    
    $config->set('enabled', $form_state->getValue('enabled'));
    $config->set('default_ttl', $form_state->getValue('default_ttl'));
    $config->set('max_size', $form_state->getValue('max_size'));
    $config->set('cleanup_frequency', $form_state->getValue('cleanup_frequency'));
    
    $config->save();

    $this->messenger()->addStatus($this->t('Cache configuration has been saved.'));
  }

  /**
   * Export cache statistics.
   */
  protected function exportStatistics() {
    $stats = $this->cacheManager->getStatistics();
    $analytics = $this->analyticsService->getCacheAnalytics();
    
    $export_data = [
      'timestamp' => date('Y-m-d H:i:s'),
      'cache_statistics' => $stats,
      'analytics' => $analytics,
    ];

    $json_data = json_encode($export_data, JSON_PRETTY_PRINT);
    
    $response = new \Symfony\Component\HttpFoundation\Response($json_data);
    $response->headers->set('Content-Type', 'application/json');
    $response->headers->set('Content-Disposition', 'attachment; filename="cache_statistics_' . date('Y-m-d_H-i-s') . '.json"');
    
    $response->send();
  }

  /**
   * Format bytes to human readable format.
   */
  protected function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
      $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
  }

}