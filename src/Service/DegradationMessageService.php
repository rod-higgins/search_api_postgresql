<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\search_api_postgresql\Exception\GracefulDegradationException;

/**
 * Service for managing user-facing degradation messages and notifications.
 */
class DegradationMessageService
{
  use StringTranslationTrait;

  /**
   * The messenger service.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The current user.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Message display settings.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $settings;

  /**
   * Constructs a DegradationMessageService.
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
      MessengerInterface $messenger,
      AccountInterface $current_user,
      TranslationInterface $string_translation,
      ConfigFactoryInterface $config_factory,
  ) {
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
    $this->stringTranslation = $string_translation;
    $this->configFactory = $config_factory;

    $this->settings = $this->configFactory->get('search_api_postgresql.degradation_messages')->get() ?: [];
    $this->settings += $this->getDefaultSettings();
  }

  /**
   * Displays a degradation message to the user.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   * @param array $context
   *   Additional context for the message.
   */
  public function displayDegradationMessage(GracefulDegradationException $exception, array $context = [])
  {
    if (!$this->shouldDisplayMessage($exception, $context)) {
      return;
    }

    $message_info = $this->buildMessage($exception, $context);

    if ($message_info) {
      $this->messenger->addMessage(
          $message_info['message'],
          $message_info['type'],
          $message_info['repeat']
      );
    }
  }

  /**
   * Displays a degradation status for search results.
   * {@inheritdoc}
   *
   * @param array $degradation_state
   *   The degradation state from query builder.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   Render array for status display, or NULL if no status to show.
   */
  public function buildSearchStatusMessage(array $degradation_state, array $context = [])
  {
    if (!$degradation_state['is_degraded']) {
      return null;
    }

    $strategy = $degradation_state['fallback_strategy'];
    $user_message = $degradation_state['user_message'];

    // Get appropriate message based on strategy.
    $status_message = $this->getStatusMessage($strategy, $user_message, $context);

    if (!$status_message) {
      return null;
    }

    return [
      '#theme' => 'search_api_postgresql_degradation_status',
      '#message' => $status_message['text'],
      '#type' => $status_message['type'],
      '#icon' => $status_message['icon'],
      '#actions' => $status_message['actions'],
      '#dismissible' => $status_message['dismissible'],
      '#attached' => [
        'library' => ['search_api_postgresql/degradation_messages'],
      ],
    ];
  }

  /**
   * Creates an informational banner for degraded functionality.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service that is degraded.
   * @param string $impact_description
   *   Description of the impact.
   * @param array $options
   *   Additional options.
   *   {@inheritdoc}.
   *
   * @return array
   *   Render array for the banner.
   */
  public function createDegradationBanner($service_name, $impact_description, array $options = [])
  {
    $default_options = [
      'show_details' => false,
      'show_dismiss' => true,
      'estimated_recovery' => null,
      'workaround_text' => null,
    ];
    $options = array_merge($default_options, $options);

    $banner_message = $this->t('@service is currently experiencing issues. @impact', [
      '@service' => $service_name,
      '@impact' => $impact_description,
    ]);

    $actions = [];

    if ($options['workaround_text']) {
      $actions['workaround'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $options['workaround_text'],
        '#attributes' => ['class' => ['degradation-workaround']],
      ];
    }

    if ($options['estimated_recovery']) {
      $actions['recovery'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Estimated recovery time: @time', [
          '@time' => $options['estimated_recovery'],
        ]),
        '#attributes' => ['class' => ['degradation-recovery-time']],
      ];
    }

    return [
      '#theme' => 'search_api_postgresql_degradation_banner',
      '#message' => $banner_message,
      '#actions' => $actions,
      '#dismissible' => $options['show_dismiss'],
      '#show_details' => $options['show_details'],
      '#attributes' => ['class' => ['degradation-banner', 'degradation-banner--warning']],
      '#attached' => [
        'library' => ['search_api_postgresql/degradation_messages'],
      ],
    ];
  }

  /**
   * Builds an appropriate message for the exception.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   Message info array or NULL if no message should be shown.
   */
  protected function buildMessage(GracefulDegradationException $exception, array $context)
  {
    $strategy = $exception->getFallbackStrategy();
    $user_message = $exception->getUserMessage();

    // Determine message type based on degradation severity.
    $message_type = $this->determineMessageType($exception, $context);

    // Get customized message if available.
    $custom_message = $this->getCustomMessage($strategy, $context);
    $final_message = $custom_message ?: $user_message;

    // Add context-specific information.
    if (!empty($context['search_terms'])) {
      $final_message .= ' ' . $this->t('Your search for "@terms" will still return results.', [
        '@terms' => $context['search_terms'],
      ]);
    }

    // Add administrative information for users with appropriate permissions.
    if ($this->currentUser->hasPermission('administer search_api') && $this->settings['show_admin_details']) {
      $final_message .= ' ' . $this->t('(Technical: @technical)', [
        '@technical' => $exception->getMessage(),
      ]);
    }

    return [
      'message' => $final_message,
      'type' => $message_type,
    // Don't repeat the same message multiple times.
      'repeat' => false,
    ];
  }

  /**
   * Gets a status message for search results.
   * {@inheritdoc}
   *
   * @param string $strategy
   *   The fallback strategy.
   * @param string $user_message
   *   The user message from degradation.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   Status message info or NULL.
   */
  protected function getStatusMessage($strategy, $user_message, array $context)
  {
    $messages = [
      'text_search_fallback' => [
        'text' => $this->t('Search results using traditional text matching'),
        'type' => 'info',
        'icon' => 'info',
        'dismissible' => true,
        'actions' => [],
      ],
      'circuit_breaker_fallback' => [
        'text' => $this->t('Some search features temporarily unavailable'),
        'type' => 'warning',
        'icon' => 'warning',
        'dismissible' => true,
        'actions' => [
      [
        'text' => $this->t('Try again'),
        'url' => 'javascript:location.reload()',
      ],
        ],
      ],
      'rate_limit_backoff' => [
        'text' => $this->t('Search processing slower than usual due to high demand'),
        'type' => 'info',
        'icon' => 'clock',
        'dismissible' => false,
        'actions' => [],
      ],
    ];

    if (isset($messages[$strategy])) {
      $message = $messages[$strategy];

      // Add context-specific information.
      if (!empty($context['result_count'])) {
        $message['text'] .= ' ' . $this->formatPlural(
            $context['result_count'],
            '(1 result found)',
            '(@count results found)'
        );
      }

      return $message;
    }

    // Fallback to generic message.
    return [
      'text' => $user_message ?: $this->t('Search functionality is running in limited mode'),
      'type' => 'info',
      'icon' => 'info',
      'dismissible' => true,
      'actions' => [],
    ];
  }

  /**
   * Determines the appropriate message type for an exception.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return string
   *   The message type (status, warning, error).
   */
  protected function determineMessageType(GracefulDegradationException $exception, array $context)
  {
    $strategy = $exception->getFallbackStrategy();

    // High impact degradations are warnings.
    $warning_strategies = [
      'circuit_breaker_fallback',
      'service_unavailable',
      'rate_limit_backoff',
    ];

    if (in_array($strategy, $warning_strategies)) {
      return MessengerInterface::TYPE_WARNING;
    }

    // Check if this is a search context where degradation is less concerning.
    if (!empty($context['is_search_result']) && in_array($strategy, ['text_search_fallback'])) {
      return MessengerInterface::TYPE_STATUS;
    }

    // Default to status for graceful degradations.
    return MessengerInterface::TYPE_STATUS;
  }

  /**
   * Gets a custom message for a strategy if configured.
   * {@inheritdoc}
   *
   * @param string $strategy
   *   The fallback strategy.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return string|null
   *   Custom message or NULL.
   */
  protected function getCustomMessage($strategy, array $context)
  {
    $custom_messages = $this->settings['custom_messages'] ?? [];

    if (isset($custom_messages[$strategy])) {
      $message = $custom_messages[$strategy];

      // Simple token replacement.
      if (!empty($context['search_terms'])) {
        $message = str_replace('[search_terms]', $context['search_terms'], $message);
      }

      return $message;
    }

    return null;
  }

  /**
   * Determines if a message should be displayed.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if message should be displayed.
   */
  protected function shouldDisplayMessage(GracefulDegradationException $exception, array $context)
  {
    // Check global setting.
    if (!$this->settings['enabled']) {
      return false;
    }

    // Check if user wants to see degradation messages.
    if (!$this->settings['show_to_anonymous'] && $this->currentUser->isAnonymous()) {
      return false;
    }

    // Check strategy-specific settings.
    $strategy = $exception->getFallbackStrategy();
    $strategy_settings = $this->settings['strategies'][$strategy] ?? [];

    if (isset($strategy_settings['enabled']) && !$strategy_settings['enabled']) {
      return false;
    }

    // Don't show messages for low-impact degradations in search contexts.
    if (!empty($context['is_search_result']) && $strategy === 'text_search_fallback') {
      return $this->settings['show_search_fallback_messages'] ?? false;
    }

    return true;
  }

  /**
   * Creates a helpful message for administrators about degradation.
   * {@inheritdoc}
   *
   * @param array $degradation_stats
   *   Statistics about current degradation.
   *   {@inheritdoc}.
   *
   * @return array
   *   Render array for admin message.
   */
  public function createAdminDegradationSummary(array $degradation_stats)
  {
    if (!$this->currentUser->hasPermission('administer search_api')) {
      return [];
    }

    $total_services = count($degradation_stats);
    $degraded_services = array_filter($degradation_stats, function ($stats) {
        return $stats['is_degraded'] ?? false;
    });

    if (empty($degraded_services)) {
      return [];
    }

    $degraded_count = count($degraded_services);
    $message = $this->formatPlural(
        $degraded_count,
        '1 search service is currently degraded',
        '@count search services are currently degraded'
    );

    $service_list = [];
    foreach ($degraded_services as $service_name => $stats) {
      $service_list[] = $this->t('@service (@reason)', [
        '@service' => $service_name,
        '@reason' => $stats['reason'] ?? 'Unknown reason',
      ]);
    }

    return [
      '#theme' => 'search_api_postgresql_admin_degradation_summary',
      '#message' => $message,
      '#services' => $service_list,
      '#total_services' => $total_services,
      '#degraded_count' => $degraded_count,
      '#links' => [
      [
        'title' => $this->t('View detailed status'),
        'url' => '/admin/config/search/search-api/postgresql/status',
      ],
      [
        'title' => $this->t('Reset circuit breakers'),
        'url' => '/admin/config/search/search-api/postgresql/reset-circuits',
      ],
      ],
      '#attached' => [
        'library' => ['search_api_postgresql/admin_degradation_summary'],
      ],
    ];
  }

  /**
   * Updates user message settings.
   * {@inheritdoc}
   *
   * @param array $new_settings
   *   New settings to save.
   */
  public function updateSettings(array $new_settings)
  {
    $config = $this->configFactory->getEditable('search_api_postgresql.degradation_messages');
    $current_settings = $config->get() ?: [];
    $updated_settings = array_merge($current_settings, $new_settings);

    $config->setData($updated_settings)->save();
    $this->settings = $updated_settings;
  }

  /**
   * Gets the default message settings.
   * {@inheritdoc}
   *
   * @return array
   *   Default settings.
   */
  protected function getDefaultSettings()
  {
    return [
      'enabled' => true,
      'show_to_anonymous' => true,
      'show_admin_details' => true,
      'show_search_fallback_messages' => false,
      'strategies' => [
        'text_search_fallback' => ['enabled' => true],
        'circuit_breaker_fallback' => ['enabled' => true],
        'rate_limit_backoff' => ['enabled' => true],
        'continue_with_partial_results' => ['enabled' => true],
      ],
      'custom_messages' => [],
    ];
  }

  /**
   * Creates a degradation help message with actionable suggestions.
   * {@inheritdoc}
   *
   * @param string $degradation_type
   *   The type of degradation.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   Render array for help message or NULL.
   */
  public function createHelpMessage($degradation_type, array $context = [])
  {
    $help_messages = [
      'embedding_service_down' => [
        'message' => $this->t('AI-powered semantic search is temporarily unavailable.'),
        'suggestions' => [
          $this->t('Try using more specific keywords in your search'),
          $this->t('Use exact phrases in quotes for better matching'),
          $this->t('Check back in a few minutes as the service may recover'),
        ],
        'type' => 'info',
      ],
      'rate_limited' => [
        'message' => $this->t('Search processing is slower than usual due to high demand.'),
        'suggestions' => [
          $this->t('Wait a moment before searching again'),
          $this->t('Try simpler search terms'),
          $this->t('Consider using filters to narrow your search'),
        ],
        'type' => 'warning',
      ],
      'partial_results' => [
        'message' => $this->t('Some search features are limited, but results are still available.'),
        'suggestions' => [
          $this->t('Try refreshing the page'),
          $this->t('Use alternative search terms'),
          $this->t('Contact support if the issue persists'),
        ],
        'type' => 'status',
      ],
    ];

    if (!isset($help_messages[$degradation_type])) {
      return null;
    }

    $info = $help_messages[$degradation_type];

    return [
      '#theme' => 'search_api_postgresql_help_message',
      '#message' => $info['message'],
      '#suggestions' => $info['suggestions'],
      '#type' => $info['type'],
      '#context' => $context,
      '#attached' => [
        'library' => ['search_api_postgresql/help_messages'],
      ],
    ];
  }
}
