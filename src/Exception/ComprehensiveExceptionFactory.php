<?php

namespace Drupal\search_api_postgresql\Exception;

/**
 * Comprehensive exception factory with intelligent error classification.
 */
class ComprehensiveExceptionFactory extends DegradationExceptionFactory
{
  /**
   * Error pattern matching for intelligent classification.
   */
  protected static $errorPatterns = [
    // Database patterns.
    '/connection.*refused|host.*unreachable/i' => DatabaseConnectionException::class,
    '/transaction.*aborted|deadlock/i' => TransactionFailedException::class,
    '/query.*timeout|execution.*timeout/i' => QueryPerformanceDegradedException::class,

    // Authentication patterns.
    '/api.*key.*invalid|authentication.*failed/i' => ApiKeyExpiredException::class,
    '/permission.*denied|access.*denied/i' => InsufficientPermissionsException::class,
    '/unauthorized|forbidden/i' => InsufficientPermissionsException::class,

    // Resource patterns.
    '/memory.*exhausted|out.*of.*memory/i' => MemoryExhaustedException::class,
    '/disk.*full|no.*space.*left/i' => DiskSpaceExhaustedException::class,
    '/index.*corrupt|vector.*index.*error/i' => VectorIndexCorruptedException::class,

    // Network patterns.
    '/dns.*resolution|name.*resolution/i' => NetworkException::class,
    '/ssl.*certificate|tls.*handshake/i' => SecurityException::class,
    '/network.*timeout|connection.*timeout/i' => NetworkTimeoutException::class,
  ];

  /**
   * Creates exception with context-aware classification.
   */
  public static function createFromException(\Exception $original_exception, array $context = [])
  {
    $message = $original_exception->getMessage();
    $code = $original_exception->getCode();

    // Try pattern matching first.
    foreach (self::$errorPatterns as $pattern => $exception_class) {
      if (preg_match($pattern, $message)) {
        return self::createSpecificException($exception_class, $original_exception, $context);
      }
    }

    // Fallback to original logic.
    return parent::createFromException($original_exception, $context);
  }

  /**
   * Creates specific exception with context.
   */
  protected static function createSpecificException($exception_class, \Exception $original, array $context)
  {
    switch ($exception_class) {
      case DatabaseConnectionException::class:
          return new DatabaseConnectionException($context['connection_params'] ?? [], $original);

      case QueryPerformanceDegradedException::class:
          return new QueryPerformanceDegradedException(
              $context['query_time'] ?? 0,
              $context['threshold'] ?? 5000
          );

      case MemoryExhaustedException::class:
          return new MemoryExhaustedException(
              $context['memory_usage'] ?? 0,
              $context['memory_limit'] ?? 0,
              $original
          );

      default:
          return parent::createFromException($original, $context);
    }
  }
}
