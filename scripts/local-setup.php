<?php

/**
 * @file
 * Local development setup script for search_api_postgresql module.
 *
 * This script creates the necessary Search API server configuration
 * for testing the search_api_postgresql module in a local environment.
 *
 * Usage: drush php:script scripts/local-setup.php
 */

use Drupal\search_api\Entity\Server;
use Drupal\Core\Config\ConfigFactoryInterface;

// Ensure we're in a Drupal context
if (!function_exists('drupal_get_path')) {
  echo "Error: This script must be run in a Drupal context via Drush.\n";
  echo "Usage: drush php:script scripts/local-setup.php\n";
  exit(1);
}

/**
 * Create PostgreSQL Search API server configuration.
 */
function create_postgresql_server() {
  echo "Setting up PostgreSQL Search API server...\n";

  // Check if server already exists
  $server = Server::load('postgresql_server');
  if ($server) {
    echo "PostgreSQL server configuration already exists. Updating...\n";
    $server->delete();
  }

  // Create new server configuration
  $server_config = [
    'id' => 'postgresql_server',
    'name' => 'PostgreSQL Search Server',
    'description' => 'PostgreSQL backend search server for enhanced search capabilities',
    'backend' => 'search_api_postgresql',
    'backend_config' => [
      'database' => 'default:default',
      'min_chars' => 3,
      'autocomplete' => [
        'suggest_suffix' => TRUE,
        'suggest_words' => TRUE,
      ],
    ],
    'status' => TRUE,
  ];

  try {
    $server = Server::create($server_config);
    $server->save();
    echo "PostgreSQL Search API server created successfully!\n";
    echo "   - Server ID: postgresql_server\n";
    echo "   - Backend: search_api_postgresql\n";
    echo "   - Database: default:default\n";
    echo "   - Min chars: 3\n";
    echo "   - Autocomplete: enabled\n";
  } catch (Exception $e) {
    echo "Error creating PostgreSQL server: " . $e->getMessage() . "\n";
    return FALSE;
  }

  return TRUE;
}

/**
 * Verify module is enabled and requirements are met.
 */
function verify_requirements() {
  echo "Verifying requirements...\n";

  // Check if search_api_postgresql module is enabled
  if (!\Drupal::moduleHandler()->moduleExists('search_api_postgresql')) {
    echo "search_api_postgresql module is not enabled.\n";
    echo "   Run: drush en search_api_postgresql -y\n";
    return FALSE;
  }

  // Check if search_api module is enabled
  if (!\Drupal::moduleHandler()->moduleExists('search_api')) {
    echo "search_api module is not enabled.\n";
    echo "   Run: drush en search_api -y\n";
    return FALSE;
  }

  echo "All required modules are enabled.\n";
  return TRUE;
}

/**
 * Main setup function.
 */
function main() {
  echo "=== Search API PostgreSQL Local Setup ===\n\n";

  if (!verify_requirements()) {
    echo "\nSetup failed: Requirements not met.\n";
    exit(1);
  }

  if (!create_postgresql_server()) {
    echo "\nSetup failed: Could not create PostgreSQL server.\n";
    exit(1);
  }

  echo "\nLocal setup completed successfully!\n";
  echo "\nNext steps:\n";
  echo "1. Visit /admin/config/search/search-api to manage your search configuration\n";
  echo "2. Create search indexes using the PostgreSQL server\n";
  echo "3. Test search functionality\n\n";
}

// Run the setup
main();