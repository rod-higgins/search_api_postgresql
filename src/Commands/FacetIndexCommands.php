<?php

namespace Drupal\search_api_postgresql\Commands;

use Drush\Commands\DrushCommands;
use Drupal\search_api\Entity\Index;

/**
 * Drush commands for facet index management.
 */
class FacetIndexCommands extends DrushCommands {

  /**
   * Clean up orphaned facet indexes.
   *
   * @param string $index_id
   *   The Search API index ID.
   *
   * @command search-api-postgresql:cleanup-facet-indexes
   * @aliases sapg-cleanup
   * @usage drush search-api-postgresql:cleanup-facet-indexes document_index
   *   Clean up orphaned facet indexes for the document_index.
   */
  public function cleanupFacetIndexes($index_id) {
    $index = Index::load($index_id);

    if (!$index) {
      $this->output()->writeln("<error>Index '{$index_id}' not found.</error>");
      return 1;
    }

    $server = $index->getServer();
    if (!$server || $server->getBackendId() !== 'postgresql') {
      $this->output()->writeln("<error>Index '{$index_id}' does not use PostgreSQL backend.</error>");
      return 1;
    }

    /** @var \Drupal\search_api_postgresql\Plugin\search_api\backend\PostgreSQLBackend $backend */
    $backend = $server->getBackend();

    $this->output()->writeln("Analyzing facet indexes for '{$index_id}'...");

    $cleaned_count = $backend->cleanupAllOrphanedFacetIndexes($index);

    if ($cleaned_count > 0) {
      $this->output()->writeln("<info>Cleaned up {$cleaned_count} orphaned facet indexes.</info>");
    }
    else {
      $this->output()->writeln("<comment>No orphaned facet indexes found.</comment>");
    }

    return 0;
  }

  /**
   * List all facet indexes for an index.
   *
   * @param string $index_id
   *   The Search API index ID.
   *
   * @command search-api-postgresql:list-facet-indexes
   * @aliases sapg-list
   * @usage drush search-api-postgresql:list-facet-indexes document_index
   *   List all facet indexes for the document_index.
   */
  public function listFacetIndexes($index_id) {
    $index = Index::load($index_id);

    if (!$index) {
      $this->output()->writeln("<error>Index '{$index_id}' not found.</error>");
      return 1;
    }

    $server = $index->getServer();
    if (!$server || $server->getBackendId() !== 'postgresql') {
      $this->output()->writeln("<error>Index '{$index_id}' does not use PostgreSQL backend.</error>");
      return 1;
    }

    /** @var \Drupal\search_api_postgresql\Plugin\search_api\backend\PostgreSQLBackend $backend */
    $backend = $server->getBackend();

    $table_name = $backend->getIndexTableNameForManager($index);
    $unquoted_table = $backend->getUnquotedTableName($table_name);
    $indexes = $backend->getAllFacetIndexes($unquoted_table);

    if (empty($indexes)) {
      $this->output()->writeln("<comment>No facet indexes found for '{$index_id}'.</comment>");
      return 0;
    }

    $this->output()->writeln("Facet indexes for '{$index_id}':");
    $this->output()->writeln("");

    foreach ($indexes as $index_info) {
      $field_name = $backend->extractFieldNameFromIndex($index_info['indexname']);
      $this->output()->writeln("  • {$index_info['indexname']} (field: {$field_name})");
    }

    $this->output()->writeln("");
    $this->output()->writeln("Total: " . count($indexes) . " indexes");

    return 0;
  }

}
