<?php

namespace Drupal\search_api_postgresql\CircuitBreaker;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker pattern implementation to prevent cascading failures.
 */
class CircuitBreaker {
  /**
   * Circuit states.
   */
  public const STATE_CLOSED = 'closed';
  public const STATE_OPEN = 'open';
  public const STATE_HALF_OPEN = 'half_open';

  /**
   * The service identifier.
   *
   * @var string
   */
  protected $serviceId;

  /**
   * The state storage.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Circuit breaker configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Default configuration.
   *
   * @var array
   */
  protected static $defaultConfig = [
    'failure_threshold' => 5,
    'recovery_timeout' => 60,
    'expected_exception_types' => [],
    'success_threshold' => 3,
    'timeout' => 30,
  ];

  /**
   * Constructs a CircuitBreaker.
   *
   * @param string $service_id
   *   The service identifier.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state storage.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param array $config
   *   Configuration options.
   */
  public function __construct($service_id, StateInterface $state, CacheBackendInterface $cache, LoggerInterface $logger, array $config = []) {
    $this->serviceId = $service_id;
    $this->state = $state;
    $this->cache = $cache;
    $this->logger = $logger;
    $this->config = array_merge(self::$defaultConfig, $config);
  }

  /**
   * Executes a callable with circuit breaker protection.
   *
   * @param callable $operation
   *   The operation to execute.
   * @param mixed $fallback
   *   Fallback value or callable to use when circuit is open.
   * @param array $context
   *   Additional context for logging.
   *
   * @return mixed
   *   The operation result or fallback value.
   *
   * @throws \Exception
   *   When circuit is open and no fallback provided.
   */
  public function execute(callable $operation, $fallback = NULL, array $context = []) {
    $state = $this->getState();

    // If circuit is open, check if we can transition to half-open.
    if ($state === self::STATE_OPEN) {
      if ($this->canAttemptReset()) {
        $this->setState(self::STATE_HALF_OPEN);
        $this->logger->info('Circuit breaker @service transitioning to half-open state', [
          '@service' => $this->serviceId,
        ]);
      }
      else {
        return $this->handleOpenCircuit($fallback, $context);
      }
    }

    // Execute the operation.
    try {
      $start_time = microtime(TRUE);
      $result = $operation();
      $duration = (microtime(TRUE) - $start_time) * 1000;

      // Operation succeeded.
      $this->recordSuccess($duration);
      return $result;
    }
    catch (\Throwable $e) {
      // Operation failed.
      $this->recordFailure($e, $context);

      // If we have a fallback, use it instead of throwing.
      if ($fallback !== NULL) {
        $this->logger->warning('Circuit breaker @service operation failed, using fallback', [
          '@service' => $this->serviceId,
          '@error' => $e->getMessage(),
        ]);

        return is_callable($fallback) ? $fallback($e, $context) : $fallback;
      }

      throw $e;
    }
  }

  /**
   * Gets the current circuit state.
   *
   * @return string
   *   The current state.
   */
  public function getState() {
    return $this->state->get($this->getStateKey(), self::STATE_CLOSED);
  }

  /**
   * Sets the circuit state.
   *
   * @param string $state
   *   The new state.
   */
  protected function setState($state) {
    $this->state->set($this->getStateKey(), $state);
    $this->state->set($this->getStateTimeKey(), time());
  }

  /**
   * Records a successful operation.
   *
   * @param float $duration
   *   Operation duration in milliseconds.
   */
  protected function recordSuccess($duration) {
    $current_state = $this->getState();

    if ($current_state === self::STATE_HALF_OPEN) {
      $success_count = $this->incrementSuccessCount();

      if ($success_count >= $this->config['success_threshold']) {
        $this->setState(self::STATE_CLOSED);
        $this->resetCounters();
        $this->logger->info('Circuit breaker @service recovered to closed state', [
          '@service' => $this->serviceId,
        ]);
      }
    }
    elseif ($current_state === self::STATE_CLOSED) {
      // Reset failure count on success.
      $this->resetFailureCount();
    }

    // Record metrics.
    $this->recordMetrics('success', $duration);
  }

  /**
   * Records a failed operation.
   *
   * @param \Throwable $exception
   *   The exception that occurred.
   * @param array $context
   *   Additional context.
   */
  protected function recordFailure(\Throwable $exception, array $context = []) {
    $current_state = $this->getState();

    // Check if this is an expected exception type that shouldn't trigger circuit.
    if ($this->isExpectedException($exception)) {
      $this->logger->debug('Circuit breaker @service: Expected exception, not counting as failure', [
        '@service' => $this->serviceId,
        '@exception' => get_class($exception),
      ]);
      return;
    }

    $failure_count = $this->incrementFailureCount();

    // Record metrics.
    $this->recordMetrics('failure', 0, $exception);

    if ($current_state === self::STATE_CLOSED && $failure_count >= $this->config['failure_threshold']) {
      $this->setState(self::STATE_OPEN);
      $this->logger->error('Circuit breaker @service opened after @count failures', [
        '@service' => $this->serviceId,
        '@count' => $failure_count,
      ]);
    }
    elseif ($current_state === self::STATE_HALF_OPEN) {
      // Single failure in half-open state reopens the circuit.
      $this->setState(self::STATE_OPEN);
      $this->logger->warning('Circuit breaker @service reopened from half-open state', [
        '@service' => $this->serviceId,
      ]);
    }
  }

  /**
   * Handles execution when circuit is open.
   *
   * @param mixed $fallback
   *   Fallback value or callable.
   * @param array $context
   *   Additional context.
   *
   * @return mixed
   *   Fallback value.
   *
   * @throws \Exception
   *   When no fallback is provided.
   */
  protected function handleOpenCircuit($fallback, array $context) {
    if ($fallback !== NULL) {
      $this->logger->debug('Circuit breaker @service is open, using fallback', [
        '@service' => $this->serviceId,
      ]);

      return is_callable($fallback) ? $fallback(NULL, $context) : $fallback;
    }

    throw new \Exception("Circuit breaker for service '{$this->serviceId}' is open");
  }

  /**
   * Checks if we can attempt to reset the circuit.
   *
   * @return bool
   *   TRUE if we can attempt reset.
   */
  protected function canAttemptReset() {
    $state_time = $this->state->get($this->getStateTimeKey(), 0);
    return (time() - $state_time) >= $this->config['recovery_timeout'];
  }

  /**
   * Checks if an exception is expected and shouldn't trigger circuit opening.
   *
   * @param \Throwable $exception
   *   The exception to check.
   *
   * @return bool
   *   TRUE if exception is expected.
   */
  protected function isExpectedException(\Throwable $exception) {
    foreach ($this->config['expected_exception_types'] as $expected_type) {
      if ($exception instanceof $expected_type) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Increments the failure count.
   *
   * @return int
   *   The new failure count.
   */
  protected function incrementFailureCount() {
    $key = $this->getFailureCountKey();
    $count = $this->state->get($key, 0) + 1;
    $this->state->set($key, $count);
    return $count;
  }

  /**
   * Increments the success count.
   *
   * @return int
   *   The new success count.
   */
  protected function incrementSuccessCount() {
    $key = $this->getSuccessCountKey();
    $count = $this->state->get($key, 0) + 1;
    $this->state->set($key, $count);
    return $count;
  }

  /**
   * Resets the failure count.
   */
  protected function resetFailureCount() {
    $this->state->delete($this->getFailureCountKey());
  }

  /**
   * Resets all counters.
   */
  protected function resetCounters() {
    $this->state->delete($this->getFailureCountKey());
    $this->state->delete($this->getSuccessCountKey());
  }

  /**
   * Records metrics for monitoring.
   *
   * @param string $result
   *   success or failure.
   * @param float $duration
   *   Operation duration.
   * @param \Throwable $exception
   *   Exception if failed.
   */
  protected function recordMetrics($result, $duration, ?\Throwable $exception = NULL) {
    $metrics = [
      'service_id' => $this->serviceId,
      'result' => $result,
      'duration' => $duration,
      'state' => $this->getState(),
      'timestamp' => time(),
    ];

    if ($exception) {
      $metrics['exception_type'] = get_class($exception);
      $metrics['exception_message'] = $exception->getMessage();
    }

    // Cache metrics for retrieval.
    $cache_key = 'circuit_breaker:metrics:' . $this->serviceId . ':' . time();
    $this->cache->set($cache_key, $metrics, time() + 3600);
  }

  /**
   * Gets circuit breaker statistics.
   *
   * @return array
   *   Statistics array.
   */
  public function getStatistics() {
    return [
      'service_id' => $this->serviceId,
      'state' => $this->getState(),
      'failure_count' => $this->state->get($this->getFailureCountKey(), 0),
      'success_count' => $this->state->get($this->getSuccessCountKey(), 0),
      'state_changed_at' => $this->state->get($this->getStateTimeKey(), 0),
      'config' => $this->config,
    ];
  }

  /**
   * Manually resets the circuit breaker.
   */
  public function reset() {
    $this->setState(self::STATE_CLOSED);
    $this->resetCounters();

    $this->logger->info('Circuit breaker @service manually reset', [
      '@service' => $this->serviceId,
    ]);
  }

  /**
   * Gets state storage key.
   */
  protected function getStateKey() {
    return 'circuit_breaker:state:' . $this->serviceId;
  }

  /**
   * Gets state time storage key.
   */
  protected function getStateTimeKey() {
    return 'circuit_breaker:state_time:' . $this->serviceId;
  }

  /**
   * Gets failure count storage key.
   */
  protected function getFailureCountKey() {
    return 'circuit_breaker:failures:' . $this->serviceId;
  }

  /**
   * Gets success count storage key.
   */
  protected function getSuccessCountKey() {
    return 'circuit_breaker:successes:' . $this->serviceId;
  }

}
