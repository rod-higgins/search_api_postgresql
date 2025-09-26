<?php

namespace Drupal\search_api_postgresql\PostgreSQL;

use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating PostgreSQL connectors.
 */
class PostgreSQLConnectorFactory
{
  /**
   * The logger.
   * {@inheritdoc}
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a PostgreSQLConnectorFactory.
   * {@inheritdoc}
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(LoggerInterface $logger)
  {
    $this->logger = $logger;
  }

  /**
   * Creates a PostgreSQL connector.
   * {@inheritdoc}
   *
   * @param array $config
   *   Connection configuration.
   *   {@inheritdoc}.
   *
   * @return \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   *   A configured PostgreSQL connector.
   */
  public function create(array $config)
  {
    return new PostgreSQLConnector($config, $this->logger);
  }

  /**
   * Creates a connector from a server entity.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The search server.
   *   {@inheritdoc}.
   *
   * @return \Drupal\search_api_postgresql\PostgreSQL\PostgreSQLConnector
   *   A configured PostgreSQL connector.
   *   {@inheritdoc}
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If the server doesn't use a PostgreSQL backend.
   */
  public function createFromServer($server)
  {
    $backend = $server->getBackend();

    if (!in_array($backend->getPluginId(), ['postgresql'])) {
      throw new SearchApiException('Server does not use a PostgreSQL backend.');
    }

    $config = $backend->getConfiguration()['connection'] ?? [];

    // Handle secure password retrieval.
    if (!empty($config['password_key']) &&
        method_exists($backend, 'getDatabasePassword')) {
      $config['password'] = $backend->getDatabasePassword();
    }

    return $this->create($config);
  }
}
