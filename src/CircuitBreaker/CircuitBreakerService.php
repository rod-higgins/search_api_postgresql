<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker pattern implementation for external service resilience.
 */
class CircuitBreakerService
{
  /**
   * Circuit breaker states.
   */
  const STATE_CLOSED = 'closed';
  const STATE_OPEN = 'open';
  const STATE_HALF_OPEN = 'half_open';

  /**
   * The state service.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The cache backend.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger.
   * {@inheritdoc}
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Circuit breaker configuration.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a CircuitBreakerService.
   * {@inheritdoc}
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(StateInterface $state, CacheBackendInterface $cache, LoggerInterface $logger)
  {
    $this->state = $state;
    $this->cache = $cache;
    $this->logger = $logger;

    $this->config = [
      'failure_threshold' => 5,
    // Seconds.
      'recovery_timeout' => 60,
    // For half-open state.
      'success_threshold' => 3,
    // 5 minutes
      'monitor_window' => 300,
    ];
  }

  /**
   * Executes a callable with circuit breaker protection.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   Name of the service being protected.
   * @param callable $operation
   *   The operation to execute.
   * @param callable $fallback
   *   Fallback operation if circuit is open.
   * @param array $context
   *   Additional context for logging.
   *   {@inheritdoc}.
   *
   * @return mixed
   *   Result of operation or fallback.
   *   {@inheritdoc}
   *
   * @throws \Drupal\search_api_postgresql\Exception\CircuitBreakerException
   *   When circuit is open and no fallback provided.
   */
  public function execute($service_name, callable $operation, ?callable $fallback = null, array $context = [])
  {
    $circuit_state = $this->getCircuitState($service_name);

    // If circuit is open, try fallback or throw exception.
    if ($circuit_state === self::STATE_OPEN) {
      if ($this->shouldAttemptRecovery($service_name)) {
        $this->setState($service_name, self::STATE_HALF_OPEN);
        $this->logger->info('Circuit breaker for @service entering half-open state', [
          '@service' => $service_name,
        ]);
      } else {
        return $this->handleOpenCircuit($service_name, $fallback, $context);
      }
    }

    try {
      $result = $operation();
      $this->recordSuccess($service_name);
      return $result;
    } catch (\Exception $e) {
      $this->recordFailure($service_name, $e);

      // If we have a fallback, use it.
      if ($fallback) {
        $this->logger->warning('Circuit breaker for @service: operation failed, using fallback. Error: @error', [
          '@service' => $service_name,
          '@error' => $e->getMessage(),
        ]);
        return $fallback($e);
      }

      throw $e;
    }
  }

  /**
   * Checks if a service is available (circuit closed or half-open).
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if service is available.
   */
  public function isServiceAvailable($service_name)
  {
    return $this->getCircuitState($service_name) !== self::STATE_OPEN;
  }

  /**
   * Gets the current circuit state for a service.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return string
   *   The circuit state.
   */
  public function getCircuitState($service_name)
  {
    $state_key = "circuit_breaker.{$service_name}.state";
    return $this->state->get($state_key, self::STATE_CLOSED);
  }

  /**
   * Gets circuit breaker statistics for a service.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return array
   *   Circuit breaker statistics.
   */
  public function getServiceStats($service_name)
  {
    $state = $this->getCircuitState($service_name);
    $failures = $this->getFailureCount($service_name);
    $last_failure = $this->state->get("circuit_breaker.{$service_name}.last_failure");
    $last_success = $this->state->get("circuit_breaker.{$service_name}.last_success");

    return [
      'service' => $service_name,
      'state' => $state,
      'failure_count' => $failures,
      'last_failure' => $last_failure,
      'last_success' => $last_success,
      'next_attempt' => $state === self::STATE_OPEN ? $last_failure + $this->config['recovery_timeout'] : null,
    ];
  }

  /**
   * Manually resets a circuit breaker.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   */
  public function resetCircuit($service_name)
  {
    $this->setState($service_name, self::STATE_CLOSED);
    $this->clearFailures($service_name);

    $this->logger->info('Circuit breaker for @service manually reset', [
      '@service' => $service_name,
    ]);
  }

  /**
   * Gets all circuit breaker states.
   * {@inheritdoc}
   *
   * @return array
   *   All circuit breaker states.
   */
  public function getAllServiceStats()
  {
    // This would need to track service names, for now return empty
    // In a real implementation, you'd maintain a list of monitored services.
    return [];
  }

  /**
   * Records a successful operation.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   */
  protected function recordSuccess($service_name)
  {
    $current_state = $this->getCircuitState($service_name);

    if ($current_state === self::STATE_HALF_OPEN) {
      $success_count = $this->incrementSuccessCount($service_name);

      if ($success_count >= $this->config['success_threshold']) {
        $this->setState($service_name, self::STATE_CLOSED);
        $this->clearFailures($service_name);
        $this->clearSuccessCount($service_name);

        $this->logger->info('Circuit breaker for @service recovered to closed state', [
          '@service' => $service_name,
        ]);
      }
    }

    $this->state->set("circuit_breaker.{$service_name}.last_success", time());
  }

  /**
   * Records a failed operation.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   * @param \Exception $exception
   *   The exception that occurred.
   */
  protected function recordFailure($service_name, \Exception $exception)
  {
    $failure_count = $this->incrementFailureCount($service_name);
    $this->state->set("circuit_breaker.{$service_name}.last_failure", time());
    $this->state->set("circuit_breaker.{$service_name}.last_error", $exception->getMessage());

    $current_state = $this->getCircuitState($service_name);

    if ($current_state !== self::STATE_OPEN && $failure_count >= $this->config['failure_threshold']) {
      $this->setState($service_name, self::STATE_OPEN);

      $this->logger->error('Circuit breaker for @service opened due to @count failures. Last error: @error', [
        '@service' => $service_name,
        '@count' => $failure_count,
        '@error' => $exception->getMessage(),
      ]);
    } elseif ($current_state === self::STATE_HALF_OPEN) {
      // Failed during recovery attempt, back to open.
      $this->setState($service_name, self::STATE_OPEN);
      $this->clearSuccessCount($service_name);

      $this->logger->warning('Circuit breaker for @service failed during recovery, returning to open state', [
        '@service' => $service_name,
      ]);
    }
  }

  /**
   * Handles open circuit scenario.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   * @param callable $fallback
   *   Optional fallback operation.
   * @param array $context
   *   Additional context.
   *   {@inheritdoc}.
   *
   * @return mixed
   *   Result of fallback operation.
   *   {@inheritdoc}
   *
   * @throws \Drupal\search_api_postgresql\Exception\CircuitBreakerException
   *   When no fallback is available.
   */
  protected function handleOpenCircuit($service_name, ?callable $fallback = null, array $context = [])
  {
    if ($fallback) {
      $this->logger->info('Circuit breaker for @service is open, using fallback', [
        '@service' => $service_name,
      ]);
      return $fallback(new \Exception("Service {$service_name} circuit breaker is open"));
    }

    throw new \Exception("Service {$service_name} is currently unavailable (circuit breaker open)");
  }

  /**
   * Checks if recovery should be attempted.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if recovery should be attempted.
   */
  protected function shouldAttemptRecovery($service_name)
  {
    $last_failure = $this->state->get("circuit_breaker.{$service_name}.last_failure");
    return $last_failure && (time() - $last_failure) >= $this->config['recovery_timeout'];
  }

  /**
   * Sets the circuit state.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   * @param string $state
   *   The new state.
   */
  protected function setState($service_name, $state)
  {
    $this->state->set("circuit_breaker.{$service_name}.state", $state);
    $this->state->set("circuit_breaker.{$service_name}.state_changed", time());
  }

  /**
   * Gets the current failure count.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return int
   *   The failure count.
   */
  protected function getFailureCount($service_name)
  {
    return $this->state->get("circuit_breaker.{$service_name}.failures", 0);
  }

  /**
   * Increments the failure count.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return int
   *   The new failure count.
   */
  protected function incrementFailureCount($service_name)
  {
    $count = $this->getFailureCount($service_name) + 1;
    $this->state->set("circuit_breaker.{$service_name}.failures", $count);
    return $count;
  }

  /**
   * Clears the failure count.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   */
  protected function clearFailures($service_name)
  {
    $this->state->delete("circuit_breaker.{$service_name}.failures");
  }

  /**
   * Increments the success count during half-open state.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   *   {@inheritdoc}.
   *
   * @return int
   *   The new success count.
   */
  protected function incrementSuccessCount($service_name)
  {
    $count = $this->state->get("circuit_breaker.{$service_name}.successes", 0) + 1;
    $this->state->set("circuit_breaker.{$service_name}.successes", $count);
    return $count;
  }

  /**
   * Clears the success count.
   * {@inheritdoc}
   *
   * @param string $service_name
   *   The service name.
   */
  protected function clearSuccessCount($service_name)
  {
    $this->state->delete("circuit_breaker.{$service_name}.successes");
  }
}
