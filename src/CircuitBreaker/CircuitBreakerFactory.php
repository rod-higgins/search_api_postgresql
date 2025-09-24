<?php

namespace Drupal\search_api_postgresql\CircuitBreaker;

/**
 * Factory for creating circuit breakers.
 */
class CircuitBreakerFactory {
  /**
   * The state service.
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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Created circuit breakers.
   *
   * @var array
   */
  protected static $instances = [];

  /**
   * Constructs a CircuitBreakerFactory.
   */
  public function __construct($state, $cache, $logger_factory) {
    $this->state = $state;
    $this->cache = $cache;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Creates or gets a circuit breaker for a service.
   *
   * @param string $service_id
   *   The service identifier.
   * @param array $config
   *   Configuration options.
   *
   * @return \Drupal\search_api_postgresql\CircuitBreaker\CircuitBreaker
   *   The circuit breaker instance.
   */
  public function getCircuitBreaker($service_id, array $config = []) {
    if (!isset(self::$instances[$service_id])) {
      $logger = $this->loggerFactory->get('search_api_postgresql.circuit_breaker');

      self::$instances[$service_id] = new CircuitBreaker(
            $service_id,
            $this->state,
            $this->cache,
            $logger,
            $config
        );
    }

    return self::$instances[$service_id];
  }

  /**
   * Gets statistics for all circuit breakers.
   *
   * @return array
   *   Statistics for all circuit breakers.
   */
  public function getAllStatistics() {
    $stats = [];
    foreach (self::$instances as $service_id => $circuit_breaker) {
      $stats[$service_id] = $circuit_breaker->getStatistics();
    }
    return $stats;
  }

}
