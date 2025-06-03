<?php

namespace Drupal\search_api_postgresql\Service;

/**
 * Enhanced user messaging with actionable guidance.
 */
class EnhancedDegradationMessageService extends DegradationMessageService {

  /**
   * Creates contextual error messages with user actions.
   */
  public function createContextualMessage(\Exception $exception, array $context = []) {
    $classification = \Drupal::service('search_api_postgresql.error_classifier')
      ->classifyError($exception, $context);
    
    return [
      'primary_message' => $this->getPrimaryMessage($exception),
      'impact_explanation' => $this->getImpactExplanation($classification),
      'user_actions' => $this->getSuggestedActions($exception, $context),
      'estimated_resolution' => $this->getEstimatedResolution($exception),
      'alternative_workflows' => $this->getAlternativeWorkflows($exception),
      'support_information' => $this->getSupportInformation($classification),
    ];
  }
  
  /**
   * Gets suggested user actions based on error type.
   */
  protected function getSuggestedActions(\Exception $exception, array $context) {
    if ($exception instanceof QueryPerformanceDegradedException) {
      return [
        'Use more specific search terms',
        'Try fewer keywords',  
        'Use filters to narrow results',
        'Wait a moment and try again',
      ];
    }
    
    if ($exception instanceof VectorSearchDegradedException) {
      return [
        'Search using exact phrases in quotes',
        'Try different keywords',
        'Use advanced search filters',
        'Check back in a few minutes',
      ];
    }
    
    return [];
  }
}