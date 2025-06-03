<?php

namespace Drupal\search_api_postgresql\Service;

/**
 * Intelligent error classification and context extraction.
 */
class ErrorClassificationService {

  /**
   * Classifies error severity and impact.
   */
  public function classifyError(\Exception $exception, array $context = []) {
    return [
      'severity' => $this->determineSeverity($exception),
      'impact_scope' => $this->determineImpactScope($exception, $context),
      'recovery_strategy' => $this->determineRecoveryStrategy($exception),
      'user_notification_level' => $this->determineNotificationLevel($exception),
      'escalation_required' => $this->requiresEscalation($exception),
    ];
  }
  
  /**
   * Determines error severity (LOW, MEDIUM, HIGH, CRITICAL).
   */
  protected function determineSeverity(\Exception $exception) {
    if ($exception instanceof DatabaseConnectionException) {
      return 'CRITICAL';
    }
    
    if ($exception instanceof MemoryExhaustedException) {
      return 'HIGH';
    }
    
    if ($exception instanceof VectorSearchDegradedException) {
      return 'MEDIUM';
    }
    
    if ($exception instanceof CacheDegradedException) {
      return 'LOW';
    }
    
    return 'MEDIUM'; // Default
  }
  
  /**
   * Determines impact scope (USER, INDEX, SERVER, SYSTEM).
   */
  protected function determineImpactScope(\Exception $exception, array $context) {
    if ($exception instanceof DatabaseConnectionException) {
      return 'SYSTEM';
    }
    
    if ($exception instanceof VectorIndexCorruptedException) {
      return 'INDEX';
    }
    
    if ($exception instanceof PartialBatchFailureException) {
      return 'USER';
    }
    
    return 'SERVER'; // Default
  }
}