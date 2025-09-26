<?php

namespace Drupal\search_api_postgresql\Config;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Configuration service for search_api_postgresql module.
 */
class SearchApiPostgresqlConfig
{
  protected $config;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory)
  {
    $this->config = $config_factory->get('search_api_postgresql.settings');
  }

  /**
   * Get configuration value.
   */
  public function get($key, $default = null)
  {
    return $this->config->get($key) ?? $default;
  }

  /**
   * Get the configuration object.
   */
  public function getConfig()
  {
    return $this->config;
  }

  /**
   * Check if configuration key exists.
   */
  public function has($key)
  {
    return $this->config->get($key) !== null;
  }
}
