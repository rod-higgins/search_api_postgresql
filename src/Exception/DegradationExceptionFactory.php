<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Factory for creating degradation exceptions.
 */
class DegradationExceptionFactory
{
  /**
   * Creates an appropriate degradation exception based on the error type.
   *
   * @param string $error_type
   *   The type of error.
   * @param string $message
   *   The error message.
   * @param int $code
   *   The error code.
   * @param \Exception|null $previous
   *   The previous exception.
   *
   * @return \Drupal\search_api_postgresql\Exception\GracefulDegradationException
   *   The appropriate exception.
   */
  public static function create(
      $error_type,
      $message,
      $code = 500,
      ?\Exception $previous = null
  ) {
    switch ($error_type) {
      case 'vector_search':
          return new VectorSearchDegradedException($message, $code, $previous);

      case 'configuration':
          return new ConfigurationDegradedException($message, $code, $previous);

      case 'queue':
          return new QueueDegradedException($message, $code, $previous);

      case 'cache':
          return new CacheDegradedException($message, $code, $previous);

      case 'rate_limit':
          return new RateLimitException($message, $code, $previous);

      case 'circuit_breaker':
          return new CircuitBreakerException($message, $code, $previous);

      default:
          return new ConfigurationDegradedException($message, $code, $previous);
    }
  }
}
