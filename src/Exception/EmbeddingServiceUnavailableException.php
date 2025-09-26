<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Exception thrown when embedding service is unavailable.
 */
class EmbeddingServiceUnavailableException extends GracefulDegradationException
{

  /**
   * Constructs an EmbeddingServiceUnavailableException.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   * @param \Exception $previous
   *   Previous exception.
   */
  public function __construct($service_name = 'Embedding service', ?\Exception $previous = null)
  {
    $message = "Embedding service '{$service_name}' is currently unavailable";
    $this->userMessage = 'AI-powered search is temporarily unavailable. Using traditional search instead.';
    $this->fallbackStrategy = 'text_search_only';
    parent::__construct($message, 503, $previous);
  }
}
