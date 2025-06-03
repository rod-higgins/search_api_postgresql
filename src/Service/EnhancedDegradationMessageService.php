<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api_postgresql\Exception\GracefulDegradationException;
use Psr\Log\LoggerInterface;

/**
 * Enhanced service for generating user-friendly degradation messages.
 */
class EnhancedDegradationMessageService {

  use StringTranslationTrait;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Message templates for different degradation scenarios.
   *
   * @var array
   */
  protected $messageTemplates = [
    'embedding_service_unavailable' => [
      'title' => 'AI Search Temporarily Unavailable',
      'message' => 'Our AI-powered search is taking a short break. We\'ve switched to traditional text search to keep you searching smoothly.',
      'icon' => 'warning',
      'action' => 'Continue searching with text-based results.',
    ],
    'vector_search_degraded' => [
      'title' => 'Smart Search in Limited Mode',
      'message' => 'Semantic search features are temporarily limited. Your searches will still work, but may be less contextually aware.',
      'icon' => 'info',
      'action' => 'Results are still accurate using traditional search methods.',
    ],
    'partial_batch_failure' => [
      'title' => 'Content Update Partially Complete',
      'message' => 'Most of your content has been processed successfully. Some items are being retried in the background.',
      'icon' => 'warning',
      'action' => 'Search functionality remains fully available.',
    ],
    'cache_degraded' => [
      'title' => 'Search Performance Temporarily Slower',
      'message' => 'Our search cache is being refreshed. You might notice slightly slower response times.',
      'icon' => 'info',
      'action' => 'All features remain available, just a bit slower.',
    ],
    'rate_limit_exceeded' => [
      'title' => 'High Search Volume Detected',
      'message' => 'We\'re experiencing high search traffic. Results may take a moment longer to appear.',
      'icon' => 'warning',
      'action' => 'Please wait a moment before searching again.',
    ],
    'configuration_degraded' => [
      'title' => 'Advanced Features Temporarily Disabled',
      'message' => 'Some advanced search features are temporarily unavailable due to configuration updates.',
      'icon' => 'warning',
      'action' => 'Basic search functionality remains fully operational.',
    ],
    'queue_degraded' => [
      'title' => 'Background Processing Delayed',
      'message' => 'Content updates are being processed more slowly than usual. Your current search results remain accurate.',
      'icon' => 'info',
      'action' => 'New content may take longer to appear in search results.',
    ],
    'circuit_breaker_open' => [
      'title' => 'Service Protection Mode Active',
      'message' => 'We\'ve temporarily disabled some features to protect system stability during high load.',
      'icon' => 'warning',
      'action' => 'Core search functionality continues to work normally.',
    ],
  ];

  /**
   * Context-specific message variations.
   *
   * @var array
   */
  protected $contextVariations = [
    'time_of_day' => [
      'business_hours' => 'Our team is working to restore full functionality.',
      'after_hours' => 'This will be automatically resolved, or our team will address it first thing in the morning.',
      'weekend' => 'Our automated systems are working to resolve this. Full functionality should return soon.',
    ],
    'user_impact' => [
      'minimal' => 'This shouldn\'t significantly affect your search experience.',
      'moderate' => 'You may notice some differences in search behavior.',
      'high' => 'Your search experience may be temporarily limited.',
    ],
    'duration_estimate' => [
      'short' => 'This should be resolved within a few minutes.',
      'medium' => 'We expect this to be resolved within the hour.',
      'long' => 'This may take several hours to fully resolve.',
      'unknown' => 'We\'re working to resolve this as quickly as possible.',
    ],
  ];

  /**
   * Constructs an EnhancedDegradationMessageService.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Generates a user-friendly degradation message.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The degradation exception.
   * @param array $context
   *   Additional context for message customization.
   *
   * @return array
   *   Formatted message array with title, message, icon, and actions.
   */
  public function generateMessage(GracefulDegradationException $exception, array $context = []) {
    $fallback_strategy = $exception->getFallbackStrategy();
    $template_key = $this->mapFallbackToTemplate($fallback_strategy);
    
    $base_template = $this->messageTemplates[$template_key] ?? $this->getDefaultTemplate();
    
    // Enhance message with context
    $enhanced_message = $this->enhanceWithContext($base_template, $context, $exception);
    
    // Add technical details for admin users
    if (!empty($context['show_technical_details'])) {
      $enhanced_message['technical_details'] = $this->generateTechnicalDetails($exception, $context);
    }
    
    // Add estimated resolution time
    $enhanced_message['estimated_resolution'] = $this->estimateResolutionTime($exception, $context);
    
    // Add alternative actions
    $enhanced_message['alternatives'] = $this->generateAlternativeActions($exception, $context);
    
    return $enhanced_message;
  }

  /**
   * Maps fallback strategy to message template.
   *
   * @param string $fallback_strategy
   *   The fallback strategy.
   *
   * @return string
   *   The template key.
   */
  protected function mapFallbackToTemplate($fallback_strategy) {
    $mapping = [
      'text_search_only' => 'embedding_service_unavailable',
      'text_search_fallback' => 'vector_search_degraded',
      'continue_with_partial_results' => 'partial_batch_failure',
      'direct_processing' => 'cache_degraded',
      'rate_limit_backoff' => 'rate_limit_exceeded',
      'basic_functionality_only' => 'configuration_degraded',
      'synchronous_processing' => 'queue_degraded',
      'circuit_breaker_fallback' => 'circuit_breaker_open',
    ];
    
    return $mapping[$fallback_strategy] ?? 'embedding_service_unavailable';
  }

  /**
   * Enhances base template with contextual information.
   *
   * @param array $template
   *   Base message template.
   * @param array $context
   *   Context information.
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   *
   * @return array
   *   Enhanced message.
   */
  protected function enhanceWithContext(array $template, array $context, GracefulDegradationException $exception) {
    $enhanced = $template;
    
    // Add time-sensitive context
    $time_context = $this->getTimeContext();
    $enhanced['additional_info'] = $this->contextVariations['time_of_day'][$time_context];
    
    // Add impact assessment
    $impact_level = $this->assessImpactLevel($exception, $context);
    $enhanced['impact_message'] = $this->contextVariations['user_impact'][$impact_level];
    
    // Add personalization if user context available
    if (!empty($context['user_role'])) {
      $enhanced = $this->personalizeForUserRole($enhanced, $context['user_role']);
    }
    
    // Add search tips if appropriate
    if ($this->shouldShowSearchTips($exception)) {
      $enhanced['search_tips'] = $this->generateSearchTips($exception);
    }
    
    return $enhanced;
  }

  /**
   * Generates technical details for administrators.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *
   * @return array
   *   Technical details.
   */
  protected function generateTechnicalDetails(GracefulDegradationException $exception, array $context) {
    return [
      'exception_type' => get_class($exception),
      'fallback_strategy' => $exception->getFallbackStrategy(),
      'timestamp' => date('c'),
      'error_code' => $exception->getCode(),
      'should_log' => $exception->shouldLog(),
      'context' => $context,
      'trace_id' => $context['trace_id'] ?? uniqid('trace_', TRUE),
    ];
  }

  /**
   * Estimates resolution time based on exception type and context.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *
   * @return string
   *   Estimated resolution time message.
   */
  protected function estimateResolutionTime(GracefulDegradationException $exception, array $context) {
    $fallback_strategy = $exception->getFallbackStrategy();
    
    $time_estimates = [
      'text_search_only' => 'medium',
      'rate_limit_backoff' => 'short',
      'direct_processing' => 'short',
      'circuit_breaker_fallback' => 'medium',
      'basic_functionality_only' => 'long',
    ];
    
    $estimate_key = $time_estimates[$fallback_strategy] ?? 'unknown';
    return $this->contextVariations['duration_estimate'][$estimate_key];
  }

  /**
   * Generates alternative actions users can take.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *
   * @return array
   *   Alternative actions.
   */
  protected function generateAlternativeActions(GracefulDegradationException $exception, array $context) {
    $fallback_strategy = $exception->getFallbackStrategy();
    
    $actions = [
      'text_search_only' => [
        'Try using more specific keywords',
        'Use exact phrases in quotes for precise matches',
        'Check back in a few minutes for AI search',
      ],
      'rate_limit_backoff' => [
        'Wait a moment before searching again',
        'Try refining your search terms',
        'Browse categories instead of searching',
      ],
      'circuit_breaker_fallback' => [
        'Use simpler search terms',
        'Try browsing by category',
        'Check back in a few minutes',
      ],
    ];
    
    return $actions[$fallback_strategy] ?? [
      'Try refreshing the page',
      'Use simpler search terms',
      'Contact support if the issue persists',
    ];
  }

  /**
   * Gets time context for contextual messaging.
   *
   * @return string
   *   Time context (business_hours, after_hours, weekend).
   */
  protected function getTimeContext() {
    $hour = (int) date('H');
    $day = date('N'); // 1-7, Monday to Sunday
    
    if ($day >= 6) { // Weekend
      return 'weekend';
    }
    
    if ($hour >= 9 && $hour <= 17) { // Business hours
      return 'business_hours';
    }
    
    return 'after_hours';
  }

  /**
   * Assesses the impact level of the degradation.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *
   * @return string
   *   Impact level (minimal, moderate, high).
   */
  protected function assessImpactLevel(GracefulDegradationException $exception, array $context) {
    $fallback_strategy = $exception->getFallbackStrategy();
    
    $impact_levels = [
      'direct_processing' => 'minimal',
      'text_search_fallback' => 'minimal',
      'rate_limit_backoff' => 'moderate',
      'text_search_only' => 'moderate',
      'basic_functionality_only' => 'high',
      'circuit_breaker_fallback' => 'high',
    ];
    
    return $impact_levels[$fallback_strategy] ?? 'moderate';
  }

  /**
   * Personalizes message for different user roles.
   *
   * @param array $message
   *   Base message.
   * @param string $user_role
   *   User role.
   *
   * @return array
   *   Personalized message.
   */
  protected function personalizeForUserRole(array $message, $user_role) {
    if ($user_role === 'administrator') {
      $message['admin_note'] = 'Check the system logs for more detailed information about this degradation.';
    } elseif ($user_role === 'editor') {
      $message['editor_note'] = 'Content editing and publishing are not affected by this search issue.';
    }
    
    return $message;
  }

  /**
   * Determines if search tips should be shown.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   *
   * @return bool
   *   TRUE if tips should be shown.
   */
  protected function shouldShowSearchTips(GracefulDegradationException $exception) {
    $show_tips_for = [
      'text_search_only',
      'text_search_fallback',
      'basic_functionality_only',
    ];
    
    return in_array($exception->getFallbackStrategy(), $show_tips_for);
  }

  /**
   * Generates helpful search tips for degraded functionality.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   *
   * @return array
   *   Search tips.
   */
  protected function generateSearchTips(GracefulDegradationException $exception) {
    return [
      'Use specific keywords rather than full sentences',
      'Put exact phrases in quotation marks',
      'Try different synonyms for your search terms',
      'Use AND, OR, NOT operators for complex searches',
      'Check spelling and try simpler terms',
    ];
  }

  /**
   * Gets default template for unknown degradation types.
   *
   * @return array
   *   Default template.
   */
  protected function getDefaultTemplate() {
    return [
      'title' => 'Search Service Notice',
      'message' => 'We\'re experiencing a temporary issue with our search service. Functionality may be limited.',
      'icon' => 'warning',
      'action' => 'Please try again in a few moments.',
    ];
  }

  /**
   * Generates a status report for multiple degradations.
   *
   * @param array $exceptions
   *   Array of degradation exceptions.
   * @param array $context
   *   Context information.
   *
   * @return array
   *   Status report.
   */
  public function generateStatusReport(array $exceptions, array $context = []) {
    if (empty($exceptions)) {
      return [
        'status' => 'healthy',
        'title' => 'All Systems Operational',
        'message' => 'Search services are running normally.',
        'icon' => 'success',
      ];
    }
    
    $severity_levels = [];
    $affected_features = [];
    
    foreach ($exceptions as $exception) {
      $severity_levels[] = $this->assessImpactLevel($exception, $context);
      $affected_features[] = $this->getFeatureName($exception);
    }
    
    $overall_severity = $this->determineOverallSeverity($severity_levels);
    $unique_features = array_unique($affected_features);
    
    return [
      'status' => $overall_severity,
      'title' => $this->getStatusTitle($overall_severity, count($exceptions)),
      'message' => $this->getStatusMessage($overall_severity, $unique_features),
      'icon' => $this->getStatusIcon($overall_severity),
      'affected_features' => $unique_features,
      'total_issues' => count($exceptions),
      'estimated_resolution' => $this->getWorstCaseResolution($exceptions, $context),
    ];
  }

  /**
   * Determines overall severity from multiple severity levels.
   *
   * @param array $severity_levels
   *   Array of severity levels.
   *
   * @return string
   *   Overall severity.
   */
  protected function determineOverallSeverity(array $severity_levels) {
    if (in_array('high', $severity_levels)) {
      return 'degraded';
    }
    if (in_array('moderate', $severity_levels)) {
      return 'partial';
    }
    return 'minor';
  }

  /**
   * Gets feature name from exception.
   *
   * @param \Drupal\search_api_postgresql\Exception\GracefulDegradationException $exception
   *   The exception.
   *
   * @return string
   *   Feature name.
   */
  protected function getFeatureName(GracefulDegradationException $exception) {
    $feature_map = [
      'text_search_only' => 'AI Search',
      'text_search_fallback' => 'Vector Search',
      'rate_limit_backoff' => 'API Services',
      'direct_processing' => 'Search Cache',
      'basic_functionality_only' => 'Advanced Features',
    ];
    
    return $feature_map[$exception->getFallbackStrategy()] ?? 'Search Service';
  }

  /**
   * Gets status title based on severity and count.
   *
   * @param string $severity
   *   Overall severity.
   * @param int $count
   *   Number of issues.
   *
   * @return string
   *   Status title.
   */
  protected function getStatusTitle($severity, $count) {
    $titles = [
      'degraded' => 'Search Services Significantly Impacted',
      'partial' => 'Some Search Features Limited',
      'minor' => 'Minor Search Service Issues',
    ];
    
    return $titles[$severity] ?? 'Search Service Status';
  }

  /**
   * Gets status message based on severity and affected features.
   *
   * @param string $severity
   *   Overall severity.
   * @param array $features
   *   Affected features.
   *
   * @return string
   *   Status message.
   */
  protected function getStatusMessage($severity, array $features) {
    $feature_list = implode(', ', $features);
    
    $messages = [
      'degraded' => "Multiple search features are currently limited: {$feature_list}. Core search functionality remains available.",
      'partial' => "Some search features are temporarily limited: {$feature_list}. Most functionality continues to work normally.",
      'minor' => "Minor issues detected with: {$feature_list}. Impact on search experience should be minimal.",
    ];
    
    return $messages[$severity] ?? 'Search services are experiencing issues.';
  }

  /**
   * Gets status icon based on severity.
   *
   * @param string $severity
   *   Overall severity.
   *
   * @return string
   *   Icon name.
   */
  protected function getStatusIcon($severity) {
    $icons = [
      'degraded' => 'error',
      'partial' => 'warning',
      'minor' => 'info',
    ];
    
    return $icons[$severity] ?? 'warning';
  }

  /**
   * Gets worst case resolution time from multiple exceptions.
   *
   * @param array $exceptions
   *   Array of exceptions.
   * @param array $context
   *   Context information.
   *
   * @return string
   *   Worst case resolution time.
   */
  protected function getWorstCaseResolution(array $exceptions, array $context) {
    $resolution_times = [];
    
    foreach ($exceptions as $exception) {
      $resolution_times[] = $this->estimateResolutionTime($exception, $context);
    }
    
    // Return the longest estimated time
    if (in_array($this->contextVariations['duration_estimate']['long'], $resolution_times)) {
      return $this->contextVariations['duration_estimate']['long'];
    }
    if (in_array($this->contextVariations['duration_estimate']['medium'], $resolution_times)) {
      return $this->contextVariations['duration_estimate']['medium'];
    }
    
    return $this->contextVariations['duration_estimate']['short'];
  }
}