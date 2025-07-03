<?php

namespace Drupal\search_api_postgresql\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Entity\Index;
use Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService;
use Drupal\search_api_postgresql\Service\ConfigurationValidationService;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheManager;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for Search API PostgreSQL administration pages.
 */
class EmbeddingAdminController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The embedding analytics service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingAnalyticsService
   */
  protected $analyticsService;

  /**
   * The configuration validation service.
   *
   * @var \Drupal\search_api_postgresql\Service\ConfigurationValidationService
   */
  protected $validationService;

  /**
   * The embedding cache manager.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheManager
   */
  protected $cacheManager;

  /**
   * The embedding queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * Constructs an EmbeddingAdminController object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EmbeddingAnalyticsService $analytics_service,
    ConfigurationValidationService $validation_service,
    EmbeddingCacheManager $cache_manager,
    EmbeddingQueueManager $queue_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->analyticsService = $analytics_service;
    $this->validationService = $validation_service;
    $this->cacheManager = $cache_manager;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('search_api_postgresql.analytics'),
      $container->get('search_api_postgresql.configuration_validator'),
      $container->get('search_api_postgresql.cache_manager'),
      $container->get('search_api_postgresql.embedding_queue_manager')
    );
  }

  /**
   * Main dashboard page.
   */
  public function dashboard() {
    $build = [];

    // Page header
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-api-postgresql-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Search API PostgreSQL Administration'),
      '#attributes' => ['class' => ['page-title']],
    ];

    $build['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Manage PostgreSQL search servers with AI embeddings and vector search capabilities.'),
      '#attributes' => ['class' => ['page-description']],
    ];

    // Quick stats overview
    $build['overview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['overview-cards']],
    ];

    $overview_stats = $this->getOverviewStatistics();
    
    $build['overview']['servers'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('PostgreSQL Servers'),
      '#value' => $overview_stats['total_servers'],
      '#subtitle' => $this->t('@enabled enabled', ['@enabled' => $overview_stats['enabled_servers']]),
      '#icon' => 'server',
      '#color' => 'blue',
    ];

    $build['overview']['indexes'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('Search Indexes'),
      '#value' => $overview_stats['total_indexes'],
      '#subtitle' => $this->t('@ai with AI', ['@ai' => $overview_stats['ai_indexes']]),
      '#icon' => 'index',
      '#color' => 'green',
    ];

    $build['overview']['embeddings'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('Total Embeddings'),
      '#value' => number_format($overview_stats['total_embeddings']),
      '#subtitle' => $this->t('@coverage% coverage', ['@coverage' => round($overview_stats['embedding_coverage'], 1)]),
      '#icon' => 'brain',
      '#color' => 'purple',
    ];

    $build['overview']['queue'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('Queue Items'),
      '#value' => $overview_stats['queue_items'],
      '#subtitle' => $this->t('pending processing'),
      '#icon' => 'queue',
      '#color' => 'orange',
    ];

    // Server status table
    $build['servers'] = [
      '#type' => 'details',
      '#title' => $this->t('Server Status'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['server-status-section']],
    ];

    $build['servers']['table'] = $this->buildServerStatusTable();

    // Index status table
    $build['indexes'] = [
      '#type' => 'details',
      '#title' => $this->t('Index Status'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['index-status-section']],
    ];

    $build['indexes']['table'] = $this->buildIndexStatusTable();

    // System health
    $build['health'] = [
      '#type' => 'details',
      '#title' => $this->t('System Health'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['system-health-section']],
    ];

    $build['health']['checks'] = $this->buildHealthChecks();

    // Quick actions
    $build['actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Quick Actions'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['quick-actions-section']],
    ];

    $build['actions']['links'] = $this->buildQuickActionLinks();

    // Attach CSS and JS
    $build['#attached']['library'][] = 'search_api_postgresql/admin';
    
    return $build;
  }

  /**
   * Analytics page.
   */
  public function analytics() {
    $build = [];

    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Embedding Analytics'),
    ];

    // Check if any servers have AI enabled
    $servers = $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure']]);

    $has_ai_servers = FALSE;
    foreach ($servers as $server) {
      if ($this->isAiEnabledForServer($server)) {
        $has_ai_servers = TRUE;
        break;
      }
    }

    if (!$has_ai_servers) {
      $build['no_ai'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '<div class="messages messages--info">' . 
          '<h3>' . $this->t('Analytics Not Available') . '</h3>' .
          '<p>' . $this->t('Analytics are only available for servers with AI embedding features enabled.') . '</p>' .
          '<p>' . $this->t('To enable analytics:') . '</p>' .
          '<ol>' .
            '<li>' . $this->t('Configure AI embeddings (Azure OpenAI, OpenAI, etc.) on your PostgreSQL server') . '</li>' .
            '<li>' . $this->t('Enable AI features in your server configuration') . '</li>' .
            '<li>' . $this->t('Re-index your content to generate embeddings') . '</li>' .
          '</ol>' .
          '<p><a href="/admin/config/search/search-api/server/search/edit" class="button button--primary">' . $this->t('Configure Server') . '</a></p>' .
          '</div>',
      ];
      
      return $build;
    }

    // If we reach here, we have AI-enabled servers, so proceed with analytics
    // But wrap everything in try-catch to handle any remaining issues
    try {
      // Date range filter
      $build['filters'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['analytics-filters']],
      ];

      $build['filters']['date_range'] = [
        '#type' => 'select',
        '#title' => $this->t('Time Period'),
        '#options' => [
          '24h' => $this->t('Last 24 hours'),
          '7d' => $this->t('Last 7 days'),
          '30d' => $this->t('Last 30 days'),
          '90d' => $this->t('Last 90 days'),
        ],
        '#default_value' => '7d',
        '#attributes' => [
          'id' => 'analytics-date-range',
          'onchange' => 'updateAnalytics(this.value)',
        ],
      ];

      // Try to get cost data, but handle failures gracefully
      try {
        $cost_data = $this->analyticsService->getCostAnalytics('7d');
      } catch (\Exception $e) {
        $cost_data = [
          'current_cost' => 0,
          'tokens_used' => 0,
          'api_calls' => 0,
          'projected_monthly' => 0,
          'projected_calls' => 0,
          'projected_tokens' => 0,
          'trend' => 0,
          'by_operation' => [],
        ];
      }
      
      $build['cost_overview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cost-overview']],
      ];

      $build['cost_overview']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Cost Analysis'),
      ];

      $build['cost_overview']['current_period'] = [
        '#theme' => 'search_api_postgresql_cost_card',
        '#title' => $this->t('Current Period'),
        '#cost' => $cost_data['current_cost'],
        '#api_calls' => $cost_data['api_calls'],
        '#tokens' => $cost_data['tokens_used'],
        '#trend' => $cost_data['trend'],
      ];

      $build['cost_overview']['projected'] = [
        '#theme' => 'search_api_postgresql_cost_card',
        '#title' => $this->t('Monthly Projection'),
        '#cost' => $cost_data['projected_monthly'],
        '#api_calls' => $cost_data['projected_calls'],
        '#tokens' => $cost_data['projected_tokens'],
        '#is_projection' => TRUE,
      ];

      // Try to get performance data, but handle failures gracefully
      try {
        $perf_data = $this->analyticsService->getPerformanceMetrics('7d');
      } catch (\Exception $e) {
        $perf_data = [
          'search_latency' => [],
          'cache_hit_rate' => [],
          'api_response_time' => [],
          'throughput' => [],
        ];
      }
      
      $build['performance'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['performance-metrics']],
      ];

      $build['performance']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Performance Metrics'),
      ];

      $build['performance']['search_latency'] = [
        '#theme' => 'search_api_postgresql_metric_chart',
        '#title' => $this->t('Search Latency'),
        '#data' => $perf_data['search_latency'],
        '#type' => 'line',
        '#unit' => 'ms',
      ];

      $build['performance']['cache_hit_rate'] = [
        '#theme' => 'search_api_postgresql_metric_chart',
        '#title' => $this->t('Cache Hit Rate'),
        '#data' => $perf_data['cache_hit_rate'],
        '#type' => 'area',
        '#unit' => '%',
      ];

      // Try to get usage data, but handle failures gracefully
      try {
        $usage_data = $this->analyticsService->getUsagePatterns('7d');
      } catch (\Exception $e) {
        $usage_data = [
          'query_volume' => [],
          'embedding_generation' => [],
        ];
      }
      
      $build['usage'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['usage-patterns']],
      ];

      $build['usage']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Usage Patterns'),
      ];

      $build['usage']['query_volume'] = [
        '#theme' => 'search_api_postgresql_metric_chart',
        '#title' => $this->t('Search Query Volume'),
        '#data' => $usage_data['query_volume'],
        '#type' => 'bar',
        '#unit' => 'queries',
      ];

      $build['usage']['embedding_generation'] = [
        '#theme' => 'search_api_postgresql_metric_chart',
        '#title' => $this->t('Embedding Generation'),
        '#data' => $usage_data['embedding_generation'],
        '#type' => 'line',
        '#unit' => 'embeddings',
      ];

      $build['#attached']['library'][] = 'search_api_postgresql/analytics';
      
      return $build;
      
    } catch (\Exception $e) {
      // If anything fails, show an error message instead of crashing
      $build['error'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '<div class="messages messages--error">' . 
          '<h3>' . $this->t('Analytics Error') . '</h3>' .
          '<p>' . $this->t('There was an error loading analytics data. This may be because:') . '</p>' .
          '<ul>' .
            '<li>' . $this->t('Analytics tables are not properly configured') . '</li>' .
            '<li>' . $this->t('Database connection issues') . '</li>' .
            '<li>' . $this->t('AI features are not fully set up') . '</li>' .
          '</ul>' .
          '<p>' . $this->t('Error: @error', ['@error' => $e->getMessage()]) . '</p>' .
          '</div>',
      ];
      
      return $build;
    }
  }

  /**
   * Server status page.
   */
  public function serverStatus($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw $this->createNotFoundException();
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      $this->messenger()->addError($this->t('Server @server is not using a PostgreSQL backend.', [
        '@server' => $server->label(),
      ]));
      return $this->redirect('search_api_postgresql.admin.dashboard');
    }

    $build = [];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['server-status-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Server Status: @server', ['@server' => $server->label()]),
    ];

    $build['header']['breadcrumb'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Dashboard'),
      '#url' => Url::fromRoute('search_api_postgresql.admin.dashboard'),
      '#attributes' => ['class' => ['breadcrumb-link']],
    ];

    // Server information
    $server_info = $this->getServerInformation($server);
    
    $build['info'] = [
      '#type' => 'details',
      '#title' => $this->t('Server Information'),
      '#open' => TRUE,
    ];

    $build['info']['table'] = [
      '#theme' => 'table',
      '#header' => [$this->t('Property'), $this->t('Value')],
      '#rows' => [
        [$this->t('Server ID'), $server->id()],
        [$this->t('Server Name'), $server->label()],
        [$this->t('Backend'), $backend->getPluginDefinition()['label']],
        [$this->t('Status'), $server->status() ? $this->t('Enabled') : $this->t('Disabled')],
        [$this->t('Database Host'), $server_info['host'] ?? $this->t('N/A')],
        [$this->t('Database Name'), $server_info['database'] ?? $this->t('N/A')],
        [$this->t('PostgreSQL Version'), $server_info['pg_version'] ?? $this->t('Unknown')],
        [$this->t('pgvector Extension'), $server_info['has_pgvector'] ? $this->t('Available') : $this->t('Not Available')],
        [$this->t('AI Embeddings'), $server_info['ai_enabled'] ? $this->t('Enabled') : $this->t('Disabled')],
        [$this->t('Vector Search'), $server_info['vector_search'] ? $this->t('Enabled') : $this->t('Disabled')],
      ],
    ];

    // Configuration validation
    $validation_results = $this->validationService->validateServerConfiguration($server);
    
    $build['validation'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration Validation'),
      '#open' => !empty($validation_results['errors']),
    ];

    if (!empty($validation_results['errors'])) {
      $build['validation']['errors'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Errors'),
        '#items' => $validation_results['errors'],
        '#list_type' => 'ul',
        '#wrapper_attributes' => ['class' => ['validation-errors']],
      ];
    }

    if (!empty($validation_results['warnings'])) {
      $build['validation']['warnings'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Warnings'),
        '#items' => $validation_results['warnings'],
        '#list_type' => 'ul',
        '#wrapper_attributes' => ['class' => ['validation-warnings']],
      ];
    }

    if (empty($validation_results['errors']) && empty($validation_results['warnings'])) {
      $build['validation']['success'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('✓ Configuration is valid.'),
        '#attributes' => ['class' => ['validation-success']],
      ];
    }

    // Performance metrics for this server
    $server_metrics = $this->analyticsService->getServerMetrics($server_id, '24h');
    
    $build['metrics'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Metrics (24h)'),
      '#open' => TRUE,
    ];

    $build['metrics']['stats'] = [
      '#theme' => 'table',
      '#header' => [$this->t('Metric'), $this->t('Value')],
      '#rows' => [
        [$this->t('Search Queries'), number_format($server_metrics['search_queries'])],
        [$this->t('Average Query Time'), $server_metrics['avg_query_time'] . ' ms'],
        [$this->t('Embedding API Calls'), number_format($server_metrics['api_calls'])],
        [$this->t('Cache Hit Rate'), round($server_metrics['cache_hit_rate'], 1) . '%'],
        [$this->t('Vector Similarity Searches'), number_format($server_metrics['vector_searches'])],
        [$this->t('Hybrid Searches'), number_format($server_metrics['hybrid_searches'])],
        [$this->t('Total Embeddings Generated'), number_format($server_metrics['embeddings_generated'])],
        [$this->t('API Cost (USD)'), '$' . number_format($server_metrics['api_cost'], 4)],
      ],
    ];

    // Indexes on this server
    $indexes = $this->getServerIndexes($server);
    
    $build['indexes'] = [
      '#type' => 'details',
      '#title' => $this->t('Indexes on this Server'),
      '#open' => TRUE,
    ];

    if (empty($indexes)) {
      $build['indexes']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No indexes found on this server.'),
      ];
    } else {
      $index_rows = [];
      foreach ($indexes as $index) {
        $index_stats = $this->getIndexEmbeddingStats($index);
        $index_rows[] = [
          Link::createFromRoute($index->label(), 'search_api_postgresql.admin.index_embeddings', ['index_id' => $index->id()]),
          $index->status() ? $this->t('Enabled') : $this->t('Disabled'),
          number_format($index_stats['total_items']),
          number_format($index_stats['items_with_embeddings']),
          round($index_stats['embedding_coverage'], 1) . '%',
          Link::createFromRoute($this->t('Manage'), 'search_api_postgresql.admin.index_embeddings', ['index_id' => $index->id()]),
        ];
      }

      $build['indexes']['table'] = [
        '#theme' => 'table',
        '#header' => [
          $this->t('Index'),
          $this->t('Status'),
          $this->t('Total Items'),
          $this->t('With Embeddings'),
          $this->t('Coverage'),
          $this->t('Actions'),
        ],
        '#rows' => $index_rows,
      ];
    }

    $build['#attached']['library'][] = 'search_api_postgresql/admin';
    
    return $build;
  }

  /**
   * Index embeddings page.
   */
  public function indexEmbeddings($index_id) {
    $index = Index::load($index_id);
    
    if (!$index) {
      throw $this->createNotFoundException();
    }

    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      $this->messenger()->addError($this->t('Index @index is not using a PostgreSQL backend.', [
        '@index' => $index->label(),
      ]));
      return $this->redirect('search_api_postgresql.admin.dashboard');
    }

    $build = [];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['index-embeddings-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Embedding Status: @index', ['@index' => $index->label()]),
    ];

    $build['header']['breadcrumb'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Dashboard'),
      '#url' => Url::fromRoute('search_api_postgresql.admin.dashboard'),
      '#attributes' => ['class' => ['breadcrumb-link']],
    ];

    // Embedding statistics
    $stats = $this->getIndexEmbeddingStats($index);
    
    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['embedding-stats-cards']],
    ];

    $build['stats']['total'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('Total Items'),
      '#value' => number_format($stats['total_items']),
      '#icon' => 'items',
      '#color' => 'blue',
    ];

    $build['stats']['embedded'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('With Embeddings'),
      '#value' => number_format($stats['items_with_embeddings']),
      '#subtitle' => $this->t('@coverage% coverage', ['@coverage' => round($stats['embedding_coverage'], 1)]),
      '#icon' => 'embedded',
      '#color' => 'green',
    ];

    $build['stats']['pending'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('Pending'),
      '#value' => number_format($stats['pending_embeddings']),
      '#subtitle' => $this->t('in queue'),
      '#icon' => 'pending',
      '#color' => 'orange',
    ];

    $build['stats']['dimensions'] = [
      '#theme' => 'search_api_postgresql_stat_card',
      '#title' => $this->t('Vector Dimensions'),
      '#value' => $stats['vector_dimensions'] ?? 'N/A',
      '#icon' => 'dimensions',
      '#color' => 'purple',
    ];

    // Embedding progress
    if ($stats['embedding_coverage'] < 100) {
      $build['progress'] = [
        '#type' => 'details',
        '#title' => $this->t('Embedding Progress'),
        '#open' => TRUE,
      ];

      $build['progress']['bar'] = [
        '#theme' => 'progress_bar',
        '#percent' => $stats['embedding_coverage'],
        '#message' => $this->t('@embedded of @total items have embeddings', [
          '@embedded' => number_format($stats['items_with_embeddings']),
          '@total' => number_format($stats['total_items']),
        ]),
      ];

      if ($stats['pending_embeddings'] > 0) {
        $build['progress']['queue_info'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('@pending items are queued for embedding generation.', [
            '@pending' => number_format($stats['pending_embeddings']),
          ]),
        ];
      }
    }

    // Field analysis
    $field_stats = $this->getFieldEmbeddingStats($index);
    
    $build['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Field Analysis'),
      '#open' => TRUE,
    ];

    if (!empty($field_stats)) {
      $field_rows = [];
      foreach ($field_stats as $field_id => $field_data) {
        $field_rows[] = [
          $field_data['label'],
          $field_data['type'],
          $field_data['searchable'] ? $this->t('Yes') : $this->t('No'),
          $field_data['included_in_embeddings'] ? $this->t('Yes') : $this->t('No'),
          number_format($field_data['avg_length']) . ' chars',
        ];
      }

      $build['fields']['table'] = [
        '#theme' => 'table',
        '#header' => [
          $this->t('Field'),
          $this->t('Type'),
          $this->t('Searchable'),
          $this->t('In Embeddings'),
          $this->t('Avg Length'),
        ],
        '#rows' => $field_rows,
      ];
    }

    // Recent embedding activity
    $recent_activity = $this->getRecentEmbeddingActivity($index, 10);
    
    $build['activity'] = [
      '#type' => 'details',
      '#title' => $this->t('Recent Activity'),
      '#open' => FALSE,
    ];

    if (!empty($recent_activity)) {
      $activity_rows = [];
      foreach ($recent_activity as $activity) {
        $activity_rows[] = [
          $activity['timestamp'],
          $activity['operation'],
          $activity['item_count'],
          $activity['status'],
          $activity['duration'] ? $activity['duration'] . ' ms' : 'N/A',
        ];
      }

      $build['activity']['table'] = [
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
    } else {
      $build['activity']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No recent activity found.'),
      ];
    }

    // Actions
    $build['actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Actions'),
      '#open' => TRUE,
    ];

    $build['actions']['regenerate'] = [
      '#type' => 'link',
      '#title' => $this->t('Regenerate All Embeddings'),
      '#url' => Url::fromRoute('search_api_postgresql.admin.bulk_regenerate', [], [
        'query' => ['index' => $index_id],
      ]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['actions']['clear_cache'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear Embedding Cache'),
      '#url' => Url::fromRoute('search_api_postgresql.admin.cache_management', [], [
        'query' => ['action' => 'clear', 'index' => $index_id],
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    $build['#attached']['library'][] = 'search_api_postgresql/admin';
    
    return $build;
  }

  /**
   * Test configuration page.
   */
  public function testConfiguration() {
    $build = [];

    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Configuration Test'),
    ];

    $build['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This page tests the configuration of all PostgreSQL servers and their embedding services.'),
    ];

    // Get all PostgreSQL servers
    $servers = $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure', 'postgresql_vector']]);

    if (empty($servers)) {
      $build['no_servers'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No PostgreSQL servers found.'),
      ];
      return $build;
    }

    $build['results'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['test-results']],
    ];

    foreach ($servers as $server) {
      $test_results = $this->validationService->runComprehensiveTests($server);
      
      // Check if test results are valid
      if (!is_array($test_results)) {
        $test_results = [
          'configuration' => ['success' => false, 'errors' => ['Failed to run tests'], 'warnings' => []],
          'health' => ['overall' => false],
          'overall' => ['success' => false, 'message' => 'Test execution failed'],
        ];
      }
      
      $build['results'][$server->id()] = [
        '#type' => 'details',
        '#title' => $this->t('Server: @server', ['@server' => $server->label()]),
        // Fix: Use the correct key structure from runComprehensiveTests
        '#open' => !($test_results['overall']['success'] ?? true),
      ];

      // Fix: Use the correct key structure
      $overall_status = ($test_results['overall']['success'] ?? false) ? 'success' : 'error';
      $status_text = ($test_results['overall']['success'] ?? false) ? 
        $this->t('All tests passed') : 
        $this->t('Some tests failed');

      $build['results'][$server->id()]['status'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['test-status', 'test-status--' . $overall_status]],
        '#value' => $status_text,
      ];

      // Configuration tests
      if (!empty($test_results['configuration'])) {
        $config_result = $test_results['configuration'];
        $build['results'][$server->id()]['configuration'] = [
          '#type' => 'details',
          '#title' => $this->t('Configuration Tests'),
          '#open' => !($config_result['success'] ?? true),
        ];
        
        if (!empty($config_result['errors'])) {
          $build['results'][$server->id()]['configuration']['errors'] = [
            '#theme' => 'item_list',
            '#title' => $this->t('Errors'),
            '#items' => $config_result['errors'],
            '#attributes' => ['class' => ['test-errors']],
          ];
        }
        
        if (!empty($config_result['warnings'])) {
          $build['results'][$server->id()]['configuration']['warnings'] = [
            '#theme' => 'item_list',
            '#title' => $this->t('Warnings'),
            '#items' => $config_result['warnings'],
            '#attributes' => ['class' => ['test-warnings']],
          ];
        }
      }

      // Health tests
      if (!empty($test_results['health'])) {
        $health_result = $test_results['health'];
        $build['results'][$server->id()]['health'] = [
          '#type' => 'details',
          '#title' => $this->t('Health Tests'),
          '#open' => !($health_result['overall'] ?? true),
        ];
        
        // Add health check details if available
        if (is_array($health_result)) {
          foreach ($health_result as $check_name => $check_result) {
            if ($check_name === 'overall') continue;
            
            $build['results'][$server->id()]['health'][$check_name] = [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#attributes' => ['class' => ['health-check']],
              '#value' => $this->t('@check: @status', [
                '@check' => ucfirst(str_replace('_', ' ', $check_name)),
                '@status' => is_array($check_result) ? 
                  (($check_result['success'] ?? false) ? $this->t('Passed') : $this->t('Failed')) :
                  ($check_result ? $this->t('Passed') : $this->t('Failed'))
              ]),
            ];
          }
        }
      }

      // Overall result
      if (!empty($test_results['overall']['message'])) {
        $build['results'][$server->id()]['overall_message'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $test_results['overall']['message'],
          '#attributes' => ['class' => ['test-overall-message']],
        ];
      }
    }

    $build['#attached']['library'][] = 'search_api_postgresql/admin';
    
    return $build;
  }

  /**
   * Ajax endpoint for server statistics.
   */
  public function ajaxServerStats($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      return new JsonResponse(['error' => 'Server not found'], 404);
    }

    $stats = $this->analyticsService->getServerMetrics($server_id, '1h');
    
    return new JsonResponse([
      'server_id' => $server_id,
      'stats' => $stats,
      'timestamp' => time(),
    ]);
  }

  /**
   * Ajax endpoint for embedding progress.
   */
  public function ajaxEmbeddingProgress($index_id) {
    $index = Index::load($index_id);
    
    if (!$index) {
      return new JsonResponse(['error' => 'Index not found'], 404);
    }

    $stats = $this->getIndexEmbeddingStats($index);
    
    return new JsonResponse([
      'index_id' => $index_id,
      'progress' => $stats,
      'timestamp' => time(),
    ]);
  }

  /**
   * Gets overview statistics for the dashboard.
   */
  /**
 * Gets overview statistics for the dashboard.
 * FIXED: Only calls AI methods when AI is enabled.
 */
protected function getOverviewStatistics() {
  $servers = $this->entityTypeManager
    ->getStorage('search_api_server')
    ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure']]);

  $indexes = [];
  $total_embeddings = 0;
  $total_items = 0;
  $enabled_servers = 0;
  $ai_indexes = 0;

  foreach ($servers as $server) {
    if ($server->status()) {
      $enabled_servers++;
    }

    $server_indexes = $this->getServerIndexes($server);
    $indexes = array_merge($indexes, $server_indexes);

    foreach ($server_indexes as $index) {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();
      
      // Check if AI is enabled for this index
      $ai_enabled = ($config['ai_embeddings']['enabled'] ?? FALSE) || 
                    ($config['azure_embedding']['enabled'] ?? FALSE);
      
      if ($ai_enabled) {
        $ai_indexes++;
        
        // ✅ ONLY call AI methods when AI is enabled
        try {
          $stats = $this->getIndexEmbeddingStats($index);
          $total_embeddings += $stats['items_with_embeddings'];
          $total_items += $stats['total_items'];
        } catch (\Exception $e) {
          // Log error but don't break the dashboard
          \Drupal::logger('search_api_postgresql')->warning(
            'Failed to get embedding stats for index @index: @error', 
            ['@index' => $index->id(), '@error' => $e->getMessage()]
          );
        }
      }
    }
  }

  // Get queue stats safely
  $queue_items = 0;
  try {
    if ($this->queueManager) {
      $queue_stats = $this->queueManager->getQueueStats();
      $queue_items = $queue_stats['items_pending'] ?? 0;
    }
  } catch (\Exception $e) {
    // Queue might not be available
    $queue_items = 0;
  }

  return [
    'total_servers' => count($servers),
    'enabled_servers' => $enabled_servers,
    'total_indexes' => count($indexes),
    'ai_indexes' => $ai_indexes,
    'total_embeddings' => $total_embeddings,
    'embedding_coverage' => $total_items > 0 ? ($total_embeddings / $total_items) * 100 : 0,
    'queue_items' => $queue_items,
  ];
}

protected function buildIndexStatusTable() {
  $servers = $this->entityTypeManager
    ->getStorage('search_api_server')
    ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure']]);

  $indexes = [];
  foreach ($servers as $server) {
    $server_indexes = $this->getServerIndexes($server);
    $indexes = array_merge($indexes, $server_indexes);
  }

  if (empty($indexes)) {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('No indexes found on PostgreSQL servers.'),
    ];
  }

  $rows = [];
  foreach ($indexes as $index) {
    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    $config = $backend->getConfiguration();
    
    // Check if AI is enabled for this index
    $ai_enabled = ($config['ai_embeddings']['enabled'] ?? FALSE) || 
                  ($config['azure_embedding']['enabled'] ?? FALSE);
    
    $status = $index->status() ? $this->t('Enabled') : $this->t('Disabled');
    
    // GET BASIC STATS FOR ALL INDEXES (AI and non-AI)
    try {
      // Get basic index statistics from Search API
      $tracker = $index->getTrackerInstance();
      $basic_total = $tracker->getTotalItemsCount();
      $basic_indexed = $tracker->getIndexedItemsCount();
      
      if ($ai_enabled) {
        // Get AI-specific stats for AI-enabled indexes
        $stats = $this->getIndexEmbeddingStats($index);
        $total_items = number_format($stats['total_items'] ?: $basic_total);
        $items_with_embeddings = number_format($stats['items_with_embeddings']);
        $coverage = round($stats['embedding_coverage'], 1) . '%';
        $ai_status = $this->t('Enabled');
      } else {
        // Show basic stats for non-AI indexes
        $total_items = number_format($basic_total);
        $items_with_embeddings = $this->t('N/A');
        $coverage = $this->t('N/A');
        $ai_status = $this->t('Disabled');
      }
    } catch (\Exception $e) {
      // Handle errors gracefully
      $total_items = $this->t('Error');
      $items_with_embeddings = $this->t('Error');
      $coverage = $this->t('Error');
      $ai_status = $this->t('Error');
      
      \Drupal::logger('search_api_postgresql')->warning(
        'Failed to get stats for index @index: @error', 
        ['@index' => $index->id(), '@error' => $e->getMessage()]
      );
    }
    
    $rows[] = [
      Link::createFromRoute($index->label(), 'search_api_postgresql.admin.index_embeddings', ['index_id' => $index->id()]),
      Link::createFromRoute($server->label(), 'search_api_postgresql.admin.server_status', ['server_id' => $server->id()]),
      $status,
      $ai_status,
      $total_items,
      $items_with_embeddings,
      $coverage,
    ];
  }

  return [
    '#theme' => 'table',
    '#header' => [
      $this->t('Index'),
      $this->t('Server'),
      $this->t('Status'),
      $this->t('AI Features'),
      $this->t('Total Items'),
      $this->t('With Embeddings'),
      $this->t('Coverage'),
    ],
    '#rows' => $rows,
    '#attributes' => ['class' => ['index-status-table']],
  ];
}

  /**
   * Helper method to safely check if AI is enabled for a server.
   */
  protected function isAiEnabledForServer($server) {
    try {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();
      
      return ($config['ai_embeddings']['enabled'] ?? FALSE) || 
            ($config['azure_embedding']['enabled'] ?? FALSE);
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Helper method to safely check if AI is enabled for an index.
   */
  protected function isAiEnabledForIndex($index) {
    try {
      $server = $index->getServerInstance();
      return $this->isAiEnabledForServer($server);
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Gets embedding statistics for an index.
   * FIXED: Only calls AI methods when AI is enabled.
   */
  protected function getIndexEmbeddingStats($index) {
    try {
      $server = $index->getServerInstance();
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();
      
      // Check AI is enabled
      $ai_enabled = ($config['ai_embeddings']['enabled'] ?? FALSE) || 
                    ($config['azure_embedding']['enabled'] ?? FALSE);
      
      if (!$ai_enabled) {
        // Return default stats for non-AI indexes
        return [
          'total_items' => 0,
          'items_with_embeddings' => 0,
          'embedding_coverage' => 0,
          'pending_embeddings' => 0,
        ];
      }
      
      // Only call AI methods if AI is enabled
      if (method_exists($backend, 'getVectorStats')) {
        return $backend->getVectorStats($index);
      } elseif (method_exists($backend, 'getAzureVectorStats')) {
        return $backend->getAzureVectorStats($index);
      }
      
      return [
        'total_items' => 0,
        'items_with_embeddings' => 0,
        'embedding_coverage' => 0,
        'pending_embeddings' => 0,
      ];
    } catch (\Exception $e) {
      return [
        'error' => $e->getMessage(),
        'total_items' => 0,
        'items_with_embeddings' => 0,
        'embedding_coverage' => 0,
        'pending_embeddings' => 0,
      ];
    }
  }

  /**
   * Builds the server status table.
   */
  protected function buildServerStatusTable() {
    $servers = $this->entityTypeManager
      ->getStorage('search_api_server')
      ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure']]);

    if (empty($servers)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No PostgreSQL servers configured.'),
      ];
    }

    $rows = [];
    foreach ($servers as $server) {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();
      
      $ai_enabled = ($config['ai_embeddings']['enabled'] ?? FALSE) || ($config['azure_embedding']['enabled'] ?? FALSE);
      $status = $server->status() ? $this->t('Enabled') : $this->t('Disabled');
      
      $health = $this->validationService->checkServerHealth($server);
      $health_status = $health['overall'] ? '✓' : '✗';
      
      $rows[] = [
        Link::createFromRoute($server->label(), 'search_api_postgresql.admin.server_status', ['server_id' => $server->id()]),
        $backend->getPluginDefinition()['label'],
        $status,
        $ai_enabled ? $this->t('Yes') : $this->t('No'),
        $health_status,
        count($this->getServerIndexes($server)),
      ];
    }

    return [
      '#theme' => 'table',
      '#header' => [
        $this->t('Server'),
        $this->t('Backend'),
        $this->t('Status'),
        $this->t('AI Embeddings'),
        $this->t('Health'),
        $this->t('Indexes'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['server-status-table']],
    ];
  }

  /**
   * Builds system health checks.
   */
  protected function buildHealthChecks() {
    $health_checks = $this->validationService->runSystemHealthChecks();
    
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['health-checks']],
    ];

    foreach ($health_checks as $check_name => $check_result) {
      // FIX: Use 'success' key instead of 'status'
      $status_class = $check_result['success'] ? 'success' : 'error';
      $status_icon = $check_result['success'] ? '✓' : '✗';
      
      $build[$check_name] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $status_icon . ' ' . $check_result['message'],
        '#attributes' => ['class' => ['health-check', 'health-' . $status_class]],
      ];

      if (!empty($check_result['details'])) {
        $build[$check_name . '_details'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $check_result['details'],
          '#attributes' => ['class' => ['health-details']],
        ];
      }
    }

    return $build;
  }

  /**
   * Builds quick action links.
   */
  protected function buildQuickActionLinks() {
    return [
      '#theme' => 'item_list',
      '#items' => [
        Link::createFromRoute($this->t('Manage Embeddings'), 'search_api_postgresql.admin.embedding_management'),
        Link::createFromRoute($this->t('View Analytics'), 'search_api_postgresql.admin.analytics'),
        Link::createFromRoute($this->t('Bulk Regenerate'), 'search_api_postgresql.admin.bulk_regenerate'),
        Link::createFromRoute($this->t('Cache Management'), 'search_api_postgresql.admin.cache_management'),
        Link::createFromRoute($this->t('Queue Management'), 'search_api_postgresql.admin.queue_management'),
        Link::createFromRoute($this->t('Test Configuration'), 'search_api_postgresql.admin.configuration_test'),
      ],
      '#attributes' => ['class' => ['quick-actions']],
    ];
  }

  /**
   * Gets server information.
   */
  protected function getServerInformation($server) {
    $backend = $server->getBackend();
    $config = $backend->getConfiguration();
    
    // Always available from config
    $result = [
      'host' => $config['connection']['host'] ?? 'Unknown',
      'database' => $config['connection']['database'] ?? 'Unknown',
      'ai_enabled' => ($config['ai_embeddings']['enabled'] ?? FALSE) || ($config['azure_embedding']['enabled'] ?? FALSE),
      'vector_search' => !empty($config['vector_search']['enabled']) || !empty($config['azure_embedding']['enabled']),
    ];

    try {
      // Only database-dependent operations in try block
      $reflection = new \ReflectionClass($backend);
      $connect_method = $reflection->getMethod('connect');
      $connect_method->setAccessible(TRUE);
      $connect_method->invoke($backend);
      
      $connector_property = $reflection->getProperty('connector');
      $connector_property->setAccessible(TRUE);
      $connector = $connector_property->getValue($backend);
      
      $result['pg_version'] = $connector->getVersion();
      $result['has_pgvector'] = $this->validationService->checkPgVectorExtension($connector);
    } catch (\Exception $e) {
      // Only set defaults for database-dependent values
      $result['pg_version'] = 'Unknown';
      $result['has_pgvector'] = FALSE;
    }

    return $result;
  }

  /**
   * Gets indexes for a server.
   */
  protected function getServerIndexes($server) {
    return $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadByProperties(['server' => $server->id()]);
  }

  /**
   * Gets field embedding statistics for an index.
   */
  protected function getFieldEmbeddingStats($index) {
    $field_stats = [];
    
    foreach ($index->getFields() as $field_id => $field) {
      $field_stats[$field_id] = [
        'label' => $field->getLabel(),
        'type' => $field->getType(),
        'searchable' => in_array($field->getType(), ['text', 'postgresql_fulltext', 'string']),
        'included_in_embeddings' => in_array($field->getType(), ['text', 'postgresql_fulltext']),
        'avg_length' => 0, // Would need to query database for actual stats
      ];
    }
    
    return $field_stats;
  }

  /**
   * Gets recent embedding activity for an index.
   */
  protected function getRecentEmbeddingActivity($index, $limit = 10) {
    // This would typically query a log table or activity table
    // For now, return mock data
    return [];
  }

}