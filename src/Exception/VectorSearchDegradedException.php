<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Vector search degradation exception.
 */
class VectorSearchDegradedException extends GracefulDegradationException
{
  public function __construct(
      $message = 'Vector search is temporarily unavailable',
      $code = 503,
      ?\Exception $previous = null
  ) {
    $this->userMessage = 'Advanced search features are temporarily unavailable. Using basic search instead.';
    $this->fallbackStrategy = 'text_search_only';
    parent::__construct($message, $code, $previous);
  }
}
