<?php

/**
 * @file
 * Bootstrap file for PHPUnit tests.
 */

// Set up basic autoloading for tests that don't require full Drupal.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
  require_once __DIR__ . '/../vendor/autoload.php';
}

// For tests that require Drupal, use the core bootstrap.
if (file_exists(__DIR__ . '/../web/core/tests/bootstrap.php')) {
  require_once __DIR__ . '/../web/core/tests/bootstrap.php';
}

// Define constants that might be needed for standalone unit tests.
if (!defined('DRUPAL_MINIMUM_PHP')) {
  define('DRUPAL_MINIMUM_PHP', '8.1.0');
}

if (!defined('DRUPAL_ROOT')) {
  define('DRUPAL_ROOT', __DIR__ . '/../web');
}
