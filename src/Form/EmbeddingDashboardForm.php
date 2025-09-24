<?php

namespace Drupal\search_api_postgresql\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for the Search API PostgreSQL dashboard.
 */
class EmbeddingDashboardForm extends ConfigFormBase {
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The embedding analytics service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService
   */
  protected $analyticsService;

  /**
   * Constructs an EmbeddingDashboardForm.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    EmbeddingAnalyticsService $analytics_service,
  ) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
    $this->analyticsService = $analytics_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('config.factory'),
          $container->get('entity_type.manager'),
          $container->get('cache.default'),
          $container->get('search_api_postgresql.analytics')
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['search_api_postgresql.dashboard'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_postgresql_dashboard_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('search_api_postgresql.dashboard');

    $form['#attached']['library'][] = 'search_api_postgresql/admin';

    // Page header.
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dashboard-settings-header']],
    ];

    $form['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Dashboard Settings'),
    ];

    $form['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the Search API PostgreSQL administration dashboard display and behavior.'),
    ];

    // Display settings.
    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display Settings'),
      '#open' => TRUE,
    ];

    $form['display']['auto_refresh'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable auto-refresh'),
      '#description' => $this->t('Automatically refresh dashboard statistics and status information.'),
      '#default_value' => $config->get('display.auto_refresh') ?? TRUE,
    ];

    $form['display']['refresh_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Auto-refresh interval'),
      '#description' => $this->t('How often to refresh the dashboard data.'),
      '#options' => [
        10 => $this->t('10 seconds'),
        30 => $this->t('30 seconds'),
        60 => $this->t('1 minute'),
        300 => $this->t('5 minutes'),
        600 => $this->t('10 minutes'),
      ],
      '#default_value' => $config->get('display.refresh_interval') ?? 30,
      '#states' => [
        'visible' => [
          ':input[name="auto_refresh"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['display']['items_per_table'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per table'),
      '#description' => $this->t('Maximum number of items to show in server and index tables.'),
      '#default_value' => $config->get('display.items_per_table') ?? 20,
      '#min' => 5,
      '#max' => 100,
      '#step' => 5,
    ];

    $form['display']['show_health_checks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show health checks by default'),
      '#description' => $this->t('Display the system health checks section expanded by default.'),
      '#default_value' => $config->get('display.show_health_checks') ?? FALSE,
    ];

    $form['display']['show_quick_actions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show quick actions by default'),
      '#description' => $this->t('Display the quick actions section expanded by default.'),
      '#default_value' => $config->get('display.show_quick_actions') ?? FALSE,
    ];

    // Cards and widgets.
    $form['cards'] = [
      '#type' => 'details',
      '#title' => $this->t('Dashboard Cards'),
      '#open' => TRUE,
    ];

    $available_cards = $this->getAvailableCards();
    $enabled_cards = $config->get('cards.enabled') ?? array_keys($available_cards);

    $form['cards']['enabled'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled cards'),
      '#description' => $this->t('Select which statistics cards to display on the dashboard.'),
      '#options' => $available_cards,
      '#default_value' => array_combine($enabled_cards, $enabled_cards),
    ];

    $form['cards']['card_order'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Card display order'),
      '#description' => $this->t('Enter card IDs in the order they should appear, one per line. Leave empty for default order.'),
      '#default_value' => implode("\n", $config->get('cards.order') ?? []),
      '#rows' => 8,
    ];

    // Notifications and alerts.
    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications & Alerts'),
      '#open' => TRUE,
    ];

    $form['notifications']['enable_alerts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable dashboard alerts'),
      '#description' => $this->t('Show alert messages for important system status changes.'),
      '#default_value' => $config->get('notifications.enable_alerts') ?? TRUE,
    ];

    $form['notifications']['alert_thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Alert Thresholds'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enable_alerts"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['notifications']['alert_thresholds']['low_coverage_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Low embedding coverage threshold (%)'),
      '#description' => $this->t('Show alert when embedding coverage falls below this percentage.'),
      '#default_value' => $config->get('notifications.alert_thresholds.low_coverage_threshold') ?? 50,
      '#min' => 0,
      '#max' => 100,
      '#step' => 5,
      '#field_suffix' => '%',
    ];

    $form['notifications']['alert_thresholds']['high_queue_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('High queue items threshold'),
      '#description' => $this->t('Show alert when queue has more than this many pending items.'),
      '#default_value' => $config->get('notifications.alert_thresholds.high_queue_threshold') ?? 1000,
      '#min' => 1,
      '#max' => 10000,
      '#step' => 100,
    ];

    $form['notifications']['alert_thresholds']['high_cost_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('High daily cost threshold (USD)'),
      '#description' => $this->t('Show alert when daily embedding costs exceed this amount.'),
      '#default_value' => $config->get('notifications.alert_thresholds.high_cost_threshold') ?? 10.00,
      '#min' => 0.01,
      '#max' => 1000.00,
      '#step' => 0.01,
      '#field_prefix' => '$',
    ];

    $form['notifications']['alert_thresholds']['server_down_alert'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Alert on server connection failures'),
      '#description' => $this->t('Show alert when PostgreSQL servers are unreachable.'),
      '#default_value' => $config->get('notifications.alert_thresholds.server_down_alert') ?? TRUE,
    ];

    // Analytics settings.
    $form['analytics'] = [
      '#type' => 'details',
      '#title' => $this->t('Analytics Settings'),
      '#open' => TRUE,
    ];

    $form['analytics']['enable_analytics'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable analytics collection'),
      '#description' => $this->t('Collect and display usage analytics and performance metrics.'),
      '#default_value' => $config->get('analytics.enable_analytics') ?? TRUE,
    ];

    $form['analytics']['default_time_range'] = [
      '#type' => 'select',
      '#title' => $this->t('Default analytics time range'),
      '#description' => $this->t('Default time period for analytics charts and data.'),
      '#options' => [
        '24h' => $this->t('Last 24 hours'),
        '7d' => $this->t('Last 7 days'),
        '30d' => $this->t('Last 30 days'),
        '90d' => $this->t('Last 90 days'),
      ],
      '#default_value' => $config->get('analytics.default_time_range') ?? '7d',
      '#states' => [
        'visible' => [
          ':input[name="enable_analytics"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['analytics']['data_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Analytics data retention (days)'),
      '#description' => $this->t('How long to keep analytics data. Older data will be automatically deleted.'),
      '#default_value' => $config->get('analytics.data_retention_days') ?? 90,
      '#min' => 7,
      '#max' => 365,
      '#step' => 1,
      '#states' => [
        'visible' => [
          ':input[name="enable_analytics"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Performance settings.
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => TRUE,
    ];

    $form['performance']['cache_stats'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache dashboard statistics'),
      '#description' => $this->t('Cache expensive statistics calculations to improve dashboard load times.'),
      '#default_value' => $config->get('performance.cache_stats') ?? TRUE,
    ];

    $form['performance']['cache_duration'] = [
      '#type' => 'select',
      '#title' => $this->t('Statistics cache duration'),
      '#description' => $this->t('How long to cache statistics before recalculating.'),
      '#options' => [
        30 => $this->t('30 seconds'),
        60 => $this->t('1 minute'),
        300 => $this->t('5 minutes'),
        600 => $this->t('10 minutes'),
        1800 => $this->t('30 minutes'),
      ],
      '#default_value' => $config->get('performance.cache_duration') ?? 300,
      '#states' => [
        'visible' => [
          ':input[name="cache_stats"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['performance']['lazy_load_charts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Lazy load analytics charts'),
      '#description' => $this->t('Load analytics charts only when their sections are expanded.'),
      '#default_value' => $config->get('performance.lazy_load_charts') ?? TRUE,
    ];

    // Custom CSS.
    $form['customization'] = [
      '#type' => 'details',
      '#title' => $this->t('Customization'),
      '#open' => FALSE,
    ];

    $form['customization']['custom_css'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom CSS'),
      '#description' => $this->t('Add custom CSS styles for the dashboard. Use with caution.'),
      '#default_value' => $config->get('customization.custom_css') ?? '',
      '#rows' => 8,
      '#attributes' => [
        'placeholder' => '/* Your custom CSS here */
.stat-card {
  /* Custom card styling */
}',
      ],
    ];

    $form['customization']['hide_branding'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide module branding'),
      '#description' => $this->t('Remove "Powered by Search API PostgreSQL" text from dashboard.'),
      '#default_value' => $config->get('customization.hide_branding') ?? FALSE,
    ];

    // Actions.
    $form['actions']['clear_cache'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Dashboard Cache'),
      '#submit' => ['::clearCache'],
      '#validate' => [],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button--secondary']],
    ];

    $form['actions']['reset_analytics'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Analytics Data'),
      '#submit' => ['::resetAnalytics'],
      '#validate' => [],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button--danger'],
        'onclick' => 'return confirm("Are you sure you want to reset all analytics data? This cannot be undone.");',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate refresh interval.
    if ($form_state->getValue('auto_refresh')) {
      $interval = $form_state->getValue('refresh_interval');
      if ($interval < 10 || $interval > 600) {
        $form_state->setErrorByName(
            'refresh_interval',
            $this->t('Refresh interval must be between 10 seconds and 10 minutes.')
        );
      }
    }

    // Validate card order.
    $card_order = $form_state->getValue('card_order');
    if (!empty($card_order)) {
      $available_cards = array_keys($this->getAvailableCards());
      $order_lines = array_filter(array_map('trim', explode("\n", $card_order)));

      foreach ($order_lines as $card_id) {
        if (!in_array($card_id, $available_cards)) {
          $form_state->setErrorByName(
              'card_order',
              $this->t('Invalid card ID "@card_id". Available cards: @available', [
                '@card_id' => $card_id,
                '@available' => implode(', ', $available_cards),
              ])
          );
          break;
        }
      }
    }

    // Validate thresholds.
    $low_coverage = $form_state->getValue(['alert_thresholds', 'low_coverage_threshold']);
    if ($low_coverage < 0 || $low_coverage > 100) {
      $form_state->setErrorByName(
            'alert_thresholds][low_coverage_threshold',
            $this->t('Coverage threshold must be between 0 and 100.')
        );
    }

    $high_cost = $form_state->getValue(['alert_thresholds', 'high_cost_threshold']);
    if ($high_cost < 0) {
      $form_state->setErrorByName(
            'alert_thresholds][high_cost_threshold',
            $this->t('Cost threshold must be a positive number.')
        );
    }

    // Validate retention period.
    $retention = $form_state->getValue('data_retention_days');
    if ($retention < 1 || $retention > 365) {
      $form_state->setErrorByName(
            'data_retention_days',
            $this->t('Data retention must be between 1 and 365 days.')
        );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('search_api_postgresql.dashboard');

    // Save display settings.
    $config->set('display.auto_refresh', $form_state->getValue('auto_refresh'));
    $config->set('display.refresh_interval', $form_state->getValue('refresh_interval'));
    $config->set('display.items_per_table', $form_state->getValue('items_per_table'));
    $config->set('display.show_health_checks', $form_state->getValue('show_health_checks'));
    $config->set('display.show_quick_actions', $form_state->getValue('show_quick_actions'));

    // Save card settings.
    $enabled_cards = array_filter($form_state->getValue('enabled'));
    $config->set('cards.enabled', array_keys($enabled_cards));

    $card_order = $form_state->getValue('card_order');
    if (!empty($card_order)) {
      $order_lines = array_filter(array_map('trim', explode("\n", $card_order)));
      $config->set('cards.order', $order_lines);
    }
    else {
      $config->clear('cards.order');
    }

    // Save notification settings.
    $config->set('notifications.enable_alerts', $form_state->getValue('enable_alerts'));
    $config->set(
          'notifications.alert_thresholds.low_coverage_threshold',
          $form_state->getValue(['alert_thresholds', 'low_coverage_threshold'])
      );
    $config->set(
          'notifications.alert_thresholds.high_queue_threshold',
          $form_state->getValue(['alert_thresholds', 'high_queue_threshold'])
      );
    $config->set(
          'notifications.alert_thresholds.high_cost_threshold',
          $form_state->getValue(['alert_thresholds', 'high_cost_threshold'])
      );
    $config->set(
          'notifications.alert_thresholds.server_down_alert',
          $form_state->getValue(['alert_thresholds', 'server_down_alert'])
      );

    // Save analytics settings.
    $config->set('analytics.enable_analytics', $form_state->getValue('enable_analytics'));
    $config->set('analytics.default_time_range', $form_state->getValue('default_time_range'));
    $config->set('analytics.data_retention_days', $form_state->getValue('data_retention_days'));

    // Save performance settings.
    $config->set('performance.cache_stats', $form_state->getValue('cache_stats'));
    $config->set('performance.cache_duration', $form_state->getValue('cache_duration'));
    $config->set('performance.lazy_load_charts', $form_state->getValue('lazy_load_charts'));

    // Save customization settings.
    $config->set('customization.custom_css', $form_state->getValue('custom_css'));
    $config->set('customization.hide_branding', $form_state->getValue('hide_branding'));

    $config->save();

    // Clear dashboard cache when settings change.
    $this->clearDashboardCache();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for clearing dashboard cache.
   */
  public function clearCache(array &$form, FormStateInterface $form_state) {
    $this->clearDashboardCache();
    $this->messenger()->addStatus($this->t('Dashboard cache has been cleared.'));
  }

  /**
   * Submit handler for resetting analytics data.
   */
  public function resetAnalytics(array &$form, FormStateInterface $form_state) {
    try {
      $this->analyticsService->resetAnalyticsData();
      $this->messenger()->addStatus($this->t('Analytics data has been reset.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to reset analytics data: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Get available dashboard cards.
   */
  protected function getAvailableCards() {
    return [
      'servers' => $this->t('PostgreSQL Servers'),
      'indexes' => $this->t('Search Indexes'),
      'embeddings' => $this->t('Total Embeddings'),
      'queue' => $this->t('Queue Items'),
      'cost' => $this->t('Daily Cost'),
      'performance' => $this->t('Search Performance'),
      'cache_hits' => $this->t('Cache Hit Rate'),
      'api_calls' => $this->t('API Calls Today'),
      'vector_searches' => $this->t('Vector Searches'),
      'hybrid_searches' => $this->t('Hybrid Searches'),
      'error_rate' => $this->t('Error Rate'),
      'avg_response_time' => $this->t('Avg Response Time'),
    ];
  }

  /**
   * Clear dashboard-related caches.
   */
  protected function clearDashboardCache() {
    // Clear dashboard statistics cache.
    $this->cache->deleteMultiple([
      'search_api_postgresql:dashboard:overview_stats',
      'search_api_postgresql:dashboard:server_stats',
      'search_api_postgresql:dashboard:index_stats',
      'search_api_postgresql:dashboard:health_checks',
    ]);

    // Clear analytics caches.
    $cache_tags = [
      'search_api_postgresql:analytics',
      'search_api_postgresql:dashboard',
    ];

    foreach ($cache_tags as $tag) {
      \Drupal::service('cache_tags.invalidator')->invalidateTags([$tag]);
    }

    // Clear render caches for dashboard pages.
    \Drupal::service('cache.render')->deleteAll();
  }

}
