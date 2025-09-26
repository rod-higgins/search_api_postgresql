<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\search_api_postgresql\Exception\DatabaseConnectionException;
use Drupal\search_api_postgresql\Exception\MemoryExhaustedException;
use Drupal\search_api_postgresql\Exception\VectorSearchDegradedException;
use Drupal\search_api_postgresql\Exception\CacheDegradedException;
use Drupal\search_api_postgresql\Exception\VectorIndexCorruptedException;
use Drupal\search_api_postgresql\Exception\ApiKeyExpiredException;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceUnavailableException;
use Drupal\search_api_postgresql\Exception\RateLimitException;
use Drupal\search_api_postgresql\Exception\PartialBatchFailureException;
use Drupal\search_api_postgresql\Exception\TemporaryApiException;

/**
 * Intelligent error classification and context extraction service.
 */
class ErrorClassificationService
{
  /**
   * Severity level mappings.
   */
  const SEVERITY_LEVELS = [
    'LOW' => 1,
    'MEDIUM' => 2,
    'HIGH' => 3,
    'CRITICAL' => 4,
  ];

  /**
   * Impact scope definitions.
   */
  const IMPACT_SCOPES = [
    'USER' => 'Single user or request affected',
    'INDEX' => 'Specific search index affected',
    'SERVER' => 'Server-wide impact',
    'SYSTEM' => 'System-wide critical failure',
  ];

  /**
   * Recovery strategies.
   */
  const RECOVERY_STRATEGIES = [
    'no_action' => 'No recovery action needed',
    'retry_operation' => 'Retry the failed operation',
    'fallback_mode' => 'Switch to fallback functionality',
    'cache_clear' => 'Clear relevant caches',
    'service_restart' => 'Restart affected services',
    'index_rebuild' => 'Rebuild search indexes',
    'manual_intervention' => 'Requires manual intervention',
    'system_maintenance' => 'System maintenance required',
  ];

  /**
   * User notification levels.
   */
  const NOTIFICATION_LEVELS = [
    'none' => 'No user notification needed',
    'minimal' => 'Silent logging only',
    'info' => 'Informational message to user',
    'warning' => 'Warning message to user',
    'error' => 'Error message to user',
    'critical' => 'Critical alert to user and admin',
  ];

  /**
   * Classifies error severity and impact.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception to classify.
   * @param array $context
   *   Additional context information.
   *   {@inheritdoc}.
   *
   * @return array
   *   Classification results.
   */
  public function classifyError(\Exception $exception, array $context = [])
  {
    $classification = [
      'severity' => $this->determineSeverity($exception, $context),
      'impact_scope' => $this->determineImpactScope($exception, $context),
      'recovery_strategy' => $this->determineRecoveryStrategy($exception, $context),
      'user_notification_level' => $this->determineNotificationLevel($exception, $context),
      'escalation_required' => $this->requiresEscalation($exception, $context),
      'business_impact' => $this->assessBusinessImpact($exception, $context),
      'technical_details' => $this->extractTechnicalDetails($exception, $context),
      'error_patterns' => $this->identifyErrorPatterns($exception, $context),
      'remediation_priority' => $this->calculateRemediationPriority($exception, $context),
    ];

    // Add time-sensitive context.
    $classification['temporal_context'] = $this->analyzeTemporalContext($exception, $context);

    // Add correlation with historical data.
    $classification['historical_correlation'] = $this->correlateWithHistory($exception, $context);

    return $classification;
  }

  /**
   * Determines error severity (LOW, MEDIUM, HIGH, CRITICAL).
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return string
   *   Severity level.
   */
  protected function determineSeverity(\Exception $exception, array $context)
  {
    // Critical failures that affect system availability.
    if ($exception instanceof DatabaseConnectionException) {
      return 'CRITICAL';
    }

    // High impact failures affecting major functionality.
    if ($exception instanceof MemoryExhaustedException) {
      return 'HIGH';
    }

    if ($exception instanceof VectorIndexCorruptedException) {
      return 'HIGH';
    }

    if ($exception instanceof ApiKeyExpiredException) {
      return 'HIGH';
    }

    // Medium impact failures with degraded functionality.
    if ($exception instanceof EmbeddingServiceUnavailableException) {
      return 'MEDIUM';
    }

    if ($exception instanceof VectorSearchDegradedException) {
      return 'MEDIUM';
    }

    if ($exception instanceof RateLimitException) {
      return 'MEDIUM';
    }

    if ($exception instanceof TemporaryApiException) {
      return 'MEDIUM';
    }

    // Low impact failures with minimal user impact.
    if ($exception instanceof CacheDegradedException) {
      return 'LOW';
    }

    // Assess based on context.
    return $this->assessSeverityFromContext($exception, $context);
  }

  /**
   * Determines impact scope (USER, INDEX, SERVER, SYSTEM).
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return string
   *   Impact scope.
   */
  protected function determineImpactScope(\Exception $exception, array $context)
  {
    // System-wide impacts.
    if ($exception instanceof DatabaseConnectionException) {
      return 'SYSTEM';
    }

    if ($exception instanceof MemoryExhaustedException) {
      $memory_usage = $context['memory_usage'] ?? 0;
      $memory_limit = $context['memory_limit'] ?? 0;

      if ($memory_usage > ($memory_limit * 0.95)) {
        return 'SYSTEM';
      }
      return 'SERVER';
    }

    // Index-specific impacts.
    if ($exception instanceof VectorIndexCorruptedException) {
      return 'INDEX';
    }

    // Server-wide impacts.
    if ($exception instanceof ApiKeyExpiredException) {
      return 'SERVER';
    }

    if ($exception instanceof EmbeddingServiceUnavailableException) {
      return 'SERVER';
    }

    // User-level impacts.
    if ($exception instanceof VectorSearchDegradedException) {
      return 'USER';
    }

    if ($exception instanceof RateLimitException) {
      return 'USER';
    }

    if ($exception instanceof PartialBatchFailureException) {
      $failure_rate = $exception->getSuccessRate();
      if ($failure_rate < 0.5) {
        return 'SERVER';
      }
      return 'USER';
    }

    // Default based on context.
    return $this->assessImpactFromContext($exception, $context);
  }

  /**
   * Determines recovery strategy.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return string
   *   Recovery strategy.
   */
  protected function determineRecoveryStrategy(\Exception $exception, array $context)
  {
    if ($exception instanceof DatabaseConnectionException) {
      return 'service_restart';
    }

    if ($exception instanceof MemoryExhaustedException) {
      return 'cache_clear';
    }

    if ($exception instanceof VectorIndexCorruptedException) {
      return 'index_rebuild';
    }

    if ($exception instanceof ApiKeyExpiredException) {
      return 'manual_intervention';
    }

    if ($exception instanceof EmbeddingServiceUnavailableException) {
      return 'fallback_mode';
    }

    if ($exception instanceof RateLimitException) {
      return 'retry_operation';
    }

    if ($exception instanceof TemporaryApiException) {
      $retry_count = $context['retry_attempts'] ?? 0;
      if ($retry_count < 3) {
        return 'retry_operation';
      }
      return 'fallback_mode';
    }

    if ($exception instanceof CacheDegradedException) {
      return 'cache_clear';
    }

    return 'no_action';
  }

  /**
   * Determines user notification level.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return string
   *   Notification level.
   */
  protected function determineNotificationLevel(\Exception $exception, array $context)
  {
    $severity = $this->determineSeverity($exception, $context);
    $impact_scope = $this->determineImpactScope($exception, $context);

    // Critical system failures.
    if ($severity === 'CRITICAL' || $impact_scope === 'SYSTEM') {
      return 'critical';
    }

    // High impact failures.
    if ($severity === 'HIGH' || $impact_scope === 'SERVER') {
      return 'error';
    }

    // Medium impact with user-visible effects.
    if ($severity === 'MEDIUM') {
      if ($impact_scope === 'USER') {
        return 'warning';
      }
      return 'info';
    }

    // Low impact failures.
    if ($severity === 'LOW') {
      return 'minimal';
    }

    return 'none';
  }

  /**
   * Determines if escalation is required.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if escalation is required.
   */
  protected function requiresEscalation(\Exception $exception, array $context)
  {
    $severity = $this->determineSeverity($exception, $context);
    $impact_scope = $this->determineImpactScope($exception, $context);

    // Always escalate critical and system-wide failures.
    if ($severity === 'CRITICAL' || $impact_scope === 'SYSTEM') {
      return true;
    }

    // Escalate high-severity failures.
    if ($severity === 'HIGH') {
      return true;
    }

    // Escalate repeated failures.
    $failure_count = $context['failure_count'] ?? 0;
    if ($failure_count > 5) {
      return true;
    }

    // Escalate during business hours for medium severity.
    if ($severity === 'MEDIUM' && $this->isBusinessHours()) {
      return true;
    }

    return false;
  }

  /**
   * Assesses business impact of the error.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return array
   *   Business impact assessment.
   */
  protected function assessBusinessImpact(\Exception $exception, array $context)
  {
    $impact = [
      'revenue_impact' => 'none',
      'user_experience_impact' => 'minimal',
      'compliance_risk' => 'none',
      'reputation_risk' => 'low',
      'data_integrity_risk' => 'none',
    ];

    if ($exception instanceof DatabaseConnectionException) {
      $impact['revenue_impact'] = 'high';
      $impact['user_experience_impact'] = 'severe';
      $impact['reputation_risk'] = 'high';
    }

    if ($exception instanceof VectorIndexCorruptedException) {
      $impact['data_integrity_risk'] = 'medium';
      $impact['user_experience_impact'] = 'moderate';
    }

    if ($exception instanceof EmbeddingServiceUnavailableException) {
      $impact['user_experience_impact'] = 'moderate';
      $impact['revenue_impact'] = 'low';
    }

    // Factor in timing.
    if ($this->isBusinessHours()) {
      $impact = $this->amplifyBusinessHoursImpact($impact);
    }

    return $impact;
  }

  /**
   * Extracts technical details for debugging.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return array
   *   Technical details.
   */
  protected function extractTechnicalDetails(\Exception $exception, array $context)
  {
    return [
      'exception_class' => get_class($exception),
      'exception_message' => $exception->getMessage(),
      'exception_code' => $exception->getCode(),
      'exception_file' => $exception->getFile(),
      'exception_line' => $exception->getLine(),
      'stack_trace' => $exception->getTraceAsString(),
      'context_data' => $this->sanitizeContext($context),
      'system_state' => $this->captureSystemState(),
      'error_fingerprint' => $this->generateErrorFingerprint($exception),
    ];
  }

  /**
   * Identifies error patterns for correlation.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return array
   *   Identified error patterns.
   */
  protected function identifyErrorPatterns(\Exception $exception, array $context)
  {
    $patterns = [];

    // Time-based patterns.
    $hour = (int) date('H');
    if ($hour >= 9 && $hour <= 17) {
      $patterns[] = 'business_hours_failure';
    }

    // Load-based patterns.
    if (isset($context['concurrent_users']) && $context['concurrent_users'] > 100) {
      $patterns[] = 'high_load_failure';
    }

    // Service-specific patterns.
    if ($exception instanceof EmbeddingServiceUnavailableException) {
      $patterns[] = 'external_service_dependency';
    }

    if ($exception instanceof MemoryExhaustedException) {
      $patterns[] = 'resource_exhaustion';
    }

    // Frequency patterns.
    if (isset($context['recent_failures']) && $context['recent_failures'] > 3) {
      $patterns[] = 'recurring_failure';
    }

    return $patterns;
  }

  /**
   * Calculates remediation priority.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return int
   *   Priority score (1-100, higher is more urgent).
   */
  protected function calculateRemediationPriority(\Exception $exception, array $context)
  {
    $priority = 0;

    // Base priority from severity.
    $severity = $this->determineSeverity($exception, $context);
    $severity_scores = [
      'CRITICAL' => 80,
      'HIGH' => 60,
      'MEDIUM' => 40,
      'LOW' => 20,
    ];
    $priority += $severity_scores[$severity] ?? 20;

    // Impact scope modifier.
    $impact_scope = $this->determineImpactScope($exception, $context);
    $impact_modifiers = [
      'SYSTEM' => 20,
      'SERVER' => 15,
      'INDEX' => 10,
      'USER' => 5,
    ];
    $priority += $impact_modifiers[$impact_scope] ?? 0;

    // Business hours modifier.
    if ($this->isBusinessHours()) {
      $priority += 10;
    }

    // Frequency modifier.
    $failure_count = $context['failure_count'] ?? 0;
    $priority += min($failure_count * 2, 20);

    return min($priority, 100);
  }

  /**
   * Analyzes temporal context of the error.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return array
   *   Temporal context analysis.
   */
  protected function analyzeTemporalContext(\Exception $exception, array $context)
  {
    return [
      'time_of_day' => date('H:i:s'),
      'day_of_week' => date('N'),
      'is_business_hours' => $this->isBusinessHours(),
      'is_weekend' => date('N') >= 6,
      'time_since_last_similar' => $this->getTimeSinceLastSimilar($exception),
      'frequency_trend' => $this->getFrequencyTrend($exception),
    ];
  }

  /**
   * Correlates with historical error data.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return array
   *   Historical correlation data.
   */
  protected function correlateWithHistory(\Exception $exception, array $context)
  {
    // In a real implementation, this would query historical error data.
    return [
      'similar_errors_24h' => 0,
      'similar_errors_7d' => 0,
      'resolution_patterns' => [],
      'success_rate_trend' => 'stable',
      'seasonal_correlation' => 'none',
    ];
  }

  /**
   * Assesses severity from context when exception type is unknown.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return string
   *   Severity level.
   */
  protected function assessSeverityFromContext(\Exception $exception, array $context)
  {
    $code = $exception->getCode();
    $message = strtolower($exception->getMessage());

    // HTTP-style error codes.
    if ($code >= 500) {
      return 'HIGH';
    }
    if ($code >= 400) {
      return 'MEDIUM';
    }

    // Message-based assessment.
    $critical_keywords = ['fatal', 'critical', 'crash', 'corruption'];
    $high_keywords = ['timeout', 'unavailable', 'connection', 'memory'];
    $medium_keywords = ['rate limit', 'quota', 'permission'];

    foreach ($critical_keywords as $keyword) {
      if (strpos($message, $keyword) !== false) {
        return 'CRITICAL';
      }
    }

    foreach ($high_keywords as $keyword) {
      if (strpos($message, $keyword) !== false) {
        return 'HIGH';
      }
    }

    foreach ($medium_keywords as $keyword) {
      if (strpos($message, $keyword) !== false) {
        return 'MEDIUM';
      }
    }

    // Default fallback.
    return 'MEDIUM';
  }

  /**
   * Assesses impact scope from context.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   * @param array $context
   *   Context information.
   *   {@inheritdoc}.
   *
   * @return string
   *   Impact scope.
   */
  protected function assessImpactFromContext(\Exception $exception, array $context)
  {
    // Check if specific services are mentioned.
    if (isset($context['affected_service'])) {
      $service = $context['affected_service'];
      if (in_array($service, ['database', 'cache', 'file_system'])) {
        return 'SYSTEM';
      }
      if (in_array($service, ['search_api', 'embeddings', 'vectors'])) {
        return 'SERVER';
      }
    }

    // Check if specific indexes are mentioned.
    if (isset($context['index_id'])) {
      return 'INDEX';
    }

    // Check user context.
    if (isset($context['user_id']) || isset($context['session_id'])) {
      return 'USER';
    }

    // Default fallback.
    return 'SERVER';
  }

  /**
   * Checks if current time is during business hours.
   * {@inheritdoc}
   *
   * @return bool
   *   true if during business hours.
   */
  protected function isBusinessHours()
  {
    $hour = (int) date('H');
    // 1-7, Monday to Sunday
    $day = (int) date('N');

    // Monday to Friday, 9 AM to 5 PM.
    return ($day >= 1 && $day <= 5) && ($hour >= 9 && $hour <= 17);
  }

  /**
   * Amplifies business impact during business hours.
   * {@inheritdoc}
   *
   * @param array $impact
   *   Current impact assessment.
   *   {@inheritdoc}.
   *
   * @return array
   *   Amplified impact assessment.
   */
  protected function amplifyBusinessHoursImpact(array $impact)
  {
    $amplification_map = [
      'none' => 'low',
      'low' => 'medium',
      'medium' => 'high',
      'high' => 'critical',
    ];

    foreach ($impact as $key => $value) {
      if (isset($amplification_map[$value])) {
        $impact[$key] = $amplification_map[$value];
      }
    }

    return $impact;
  }

  /**
   * Sanitizes context data for logging.
   * {@inheritdoc}
   *
   * @param array $context
   *   Raw context data.
   *   {@inheritdoc}.
   *
   * @return array
   *   Sanitized context data.
   */
  protected function sanitizeContext(array $context)
  {
    $sensitive_keys = ['password', 'api_key', 'token', 'secret'];

    foreach ($context as $key => $value) {
      foreach ($sensitive_keys as $sensitive_key) {
        if (stripos($key, $sensitive_key) !== false) {
          $context[$key] = '[REDACTED]';
          break;
        }
      }
    }

    return $context;
  }

  /**
   * Captures current system state.
   * {@inheritdoc}
   *
   * @return array
   *   System state information.
   */
  protected function captureSystemState()
  {
    return [
      'memory_usage' => memory_get_usage(true),
      'memory_peak' => memory_get_peak_usage(true),
      'php_version' => PHP_VERSION,
      'timestamp' => time(),
      'timezone' => date_default_timezone_get(),
    ];
  }

  /**
   * Generates unique error fingerprint for correlation.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   *   {@inheritdoc}.
   *
   * @return string
   *   Error fingerprint.
   */
  protected function generateErrorFingerprint(\Exception $exception)
  {
    $components = [
      get_class($exception),
      $exception->getCode(),
      $exception->getFile(),
      $exception->getLine(),
    ];

    return md5(implode('|', $components));
  }

  /**
   * Gets time since last similar error.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   *   {@inheritdoc}.
   *
   * @return int
   *   Time in seconds since last similar error.
   */
  protected function getTimeSinceLastSimilar(\Exception $exception)
  {
    // In a real implementation, this would query error logs.
    return 0;
  }

  /**
   * Gets frequency trend for this type of error.
   * {@inheritdoc}
   *
   * @param \Exception $exception
   *   The exception.
   *   {@inheritdoc}.
   *
   * @return string
   *   Trend description (increasing, decreasing, stable).
   */
  protected function getFrequencyTrend(\Exception $exception)
  {
    // In a real implementation, this would analyze historical data.
    return 'stable';
  }
}
