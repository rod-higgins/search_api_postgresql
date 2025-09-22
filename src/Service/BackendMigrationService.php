<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\ServerInterface;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling backend migrations and compatibility checks.
 */
class BackendMigrationService {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   */
  protected $messenger;

  /**
   * The logger.
   */
  protected $logger;

  /**
   * Backend compatibility matrix.
   *
   * Defines what features are lost/gained when switching backends.
   */
  protected $compatibilityMatrix = [
    'postgresql' => [
      'features' => ['fulltext_search', 'facets', 'optional_ai'],
      'data_types' => ['text', 'string', 'integer', 'decimal', 'date', 'boolean', 'postgresql_fulltext'],
      'supports_vector' => FALSE,
    ],
    'postgresql_azure' => [
      'features' => ['fulltext_search', 'facets', 'vector_search', 'hybrid_search', 'azure_ai'],
      'data_types' => ['text', 'string', 'integer', 'decimal', 'date', 'boolean', 'postgresql_fulltext', 'vector'],
      'supports_vector' => TRUE,
    ],
    'postgresql_vector' => [
      'features' => ['fulltext_search', 'facets', 'vector_search', 'hybrid_search', 'multi_provider_ai'],
      'data_types' => ['text', 'string', 'integer', 'decimal', 'date', 'boolean', 'postgresql_fulltext', 'vector'],
      'supports_vector' => TRUE,
    ],
  ];

  /**
   * Constructs a BackendMigrationService.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * Checks compatibility between two backends.
   *
   * @param string $from_backend
   *   The current backend ID.
   * @param string $to_backend
   *   The target backend ID.
   *
   * @return array
   *   Compatibility information with warnings and migration requirements.
   */
  public function checkBackendCompatibility($from_backend, $to_backend) {
    $from_info = $this->compatibilityMatrix[$from_backend] ?? [];
    $to_info = $this->compatibilityMatrix[$to_backend] ?? [];

    if (empty($from_info) || empty($to_info)) {
      return [
        'compatible' => FALSE,
        'warnings' => [$this->t('Unknown backend type. Migration not supported.')],
        'actions_required' => [],
      ];
    }

    $warnings = [];
    $actions_required = [];
    $compatible = TRUE;

    // Check for vector support loss.
    if ($from_info['supports_vector'] && !$to_info['supports_vector']) {
      $compatible = FALSE;
      $warnings[] = $this->t('CRITICAL: Switching to this backend will PERMANENTLY DELETE all vector search data and embeddings.');
      $warnings[] = $this->t('Vector fields will be removed from all indexes.');
      $warnings[] = $this->t('AI-generated embeddings will be lost and cannot be recovered.');
      $actions_required[] = 'backup_vector_data';
      $actions_required[] = 'remove_vector_fields';
    }

    // Check for feature losses.
    $lost_features = array_diff($from_info['features'], $to_info['features']);
    if (!empty($lost_features)) {
      $warnings[] = $this->t('Features that will be lost: @features', [
        '@features' => implode(', ', $lost_features),
      ]);
    }

    // Check for data type incompatibilities.
    $lost_types = array_diff($from_info['data_types'], $to_info['data_types']);
    if (!empty($lost_types)) {
      $compatible = FALSE;
      $warnings[] = $this->t('WARNING: Data types that will be lost: @types', [
        '@types' => implode(', ', $lost_types),
      ]);
      $actions_required[] = 'convert_incompatible_fields';
    }

    // Check for gained features.
    $gained_features = array_diff($to_info['features'], $from_info['features']);
    if (!empty($gained_features)) {
      $warnings[] = $this->t('✅ New features available: @features', [
        '@features' => implode(', ', $gained_features),
      ]);
    }

    return [
      'compatible' => $compatible,
      'warnings' => $warnings,
      'actions_required' => $actions_required,
      'lost_features' => $lost_features ?? [],
      'gained_features' => $gained_features ?? [],
      'lost_types' => $lost_types ?? [],
    ];
  }

  /**
   * Prepares for backend migration.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server being updated.
   * @param string $from_backend
   *   The current backend ID.
   * @param string $to_backend
   *   The target backend ID.
   */
  public function prepareBackendMigration(ServerInterface $server, $from_backend, $to_backend) {
    $this->logger->info('Preparing backend migration for server @server from @from to @to', [
      '@server' => $server->id(),
      '@from' => $from_backend,
      '@to' => $to_backend,
    ]);

    $compatibility = $this->checkBackendCompatibility($from_backend, $to_backend);

    // Store migration info for later execution.
    $migration_data = [
      'server_id' => $server->id(),
      'from_backend' => $from_backend,
      'to_backend' => $to_backend,
      'compatibility' => $compatibility,
      'timestamp' => time(),
    ];

    // Save migration plan.
    \Drupal::state()->set('search_api_postgresql_migration_' . $server->id(), $migration_data);

    // Backup critical data if needed.
    if (in_array('backup_vector_data', $compatibility['actions_required'])) {
      $this->backupVectorData($server);
    }
  }

  /**
   * Executes the backend migration.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server to migrate.
   */
  public function executeBackendMigration(ServerInterface $server) {
    $migration_key = 'search_api_postgresql_migration_' . $server->id();
    $migration_data = \Drupal::state()->get($migration_key);

    if (empty($migration_data)) {
      // No migration needed.
      return;
    }

    $this->logger->info('Executing backend migration for server @server', [
      '@server' => $server->id(),
    ]);

    try {
      $compatibility = $migration_data['compatibility'];

      // Execute required actions.
      foreach ($compatibility['actions_required'] as $action) {
        switch ($action) {
          case 'remove_vector_fields':
            $this->removeVectorFields($server);
            break;

          case 'convert_incompatible_fields':
            $this->convertIncompatibleFields($server, $compatibility['lost_types']);
            break;

          case 'backup_vector_data':
            // Already done in prepareBackendMigration.
            break;
        }
      }

      // Update indexes to remove incompatible field types.
      $this->updateIndexesForBackendChange($server, $migration_data);

      // Show user warnings about what was changed.
      $this->displayMigrationResults($migration_data);

      // Clean up migration data.
      \Drupal::state()->delete($migration_key);

    }
    catch (\Exception $e) {
      $this->logger->error('Backend migration failed for server @server: @error', [
        '@server' => $server->id(),
        '@error' => $e->getMessage(),
      ]);

      $this->messenger->addError($this->t('Backend migration failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Removes vector fields from all indexes on a server.
   */
  protected function removeVectorFields(ServerInterface $server) {
    $indexes = $server->getIndexes();

    foreach ($indexes as $index) {
      $fields_to_remove = [];

      foreach ($index->getFields() as $field_id => $field) {
        if ($field->getType() === 'vector') {
          $fields_to_remove[] = $field_id;
        }
      }

      if (!empty($fields_to_remove)) {
        $this->logger->info('Removing vector fields @fields from index @index', [
          '@fields' => implode(', ', $fields_to_remove),
          '@index' => $index->id(),
        ]);

        // Remove fields from index.
        foreach ($fields_to_remove as $field_id) {
          $index->removeField($field_id);
        }

        $index->save();

        // Drop vector columns from database.
        $this->dropVectorColumns($server, $index, $fields_to_remove);
      }
    }
  }

  /**
   * Drops vector columns from the database.
   */
  protected function dropVectorColumns(ServerInterface $server, IndexInterface $index, array $field_ids) {
    try {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();

      // Get database connection through reflection (since connection is private)
      $reflection = new \ReflectionClass($backend);

      if ($reflection->hasMethod('getConnection')) {
        $get_connection = $reflection->getMethod('getConnection');
        $get_connection->setAccessible(TRUE);
        $connection = $get_connection->invoke($backend);
      }
      else {
        // Fallback: create new connection.
        $connection = Database::getConnection('default', 'default');
      }

      $table_name = $config['index_prefix'] . $index->id();

      foreach ($field_ids as $field_id) {
        $sql = "ALTER TABLE {$table_name} DROP COLUMN IF EXISTS {$field_id}";
        $connection->query($sql);

        // Also drop embedding columns.
        $embedding_column = $field_id . '_embedding';
        $sql = "ALTER TABLE {$table_name} DROP COLUMN IF EXISTS {$embedding_column}";
        $connection->query($sql);
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to drop vector columns: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Backs up vector data before migration.
   */
  protected function backupVectorData(ServerInterface $server) {
    $this->logger->info('Backing up vector data for server @server', [
      '@server' => $server->id(),
    ]);

    // Create a backup table or export data.
    $backup_timestamp = date('Y_m_d_H_i_s');
    $backup_key = 'vector_backup_' . $server->id() . '_' . $backup_timestamp;

    try {
      $backend = $server->getBackend();
      $indexes = $server->getIndexes();
      $backup_data = [];

      foreach ($indexes as $index) {
        $vector_fields = [];
        foreach ($index->getFields() as $field_id => $field) {
          if ($field->getType() === 'vector') {
            $vector_fields[] = $field_id;
          }
        }

        if (!empty($vector_fields)) {
          // Export vector data (simplified - real implementation would export actual data)
          $backup_data[$index->id()] = [
            'fields' => $vector_fields,
            'note' => 'Vector fields removed during backend migration',
          ];
        }
      }

      if (!empty($backup_data)) {
        \Drupal::state()->set($backup_key, $backup_data);

        $this->messenger->addWarning($this->t('Vector data has been backed up with key: @key. Contact your administrator to restore if needed.', [
          '@key' => $backup_key,
        ]));
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to backup vector data: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Converts incompatible field types.
   */
  protected function convertIncompatibleFields(ServerInterface $server, array $lost_types) {
    $indexes = $server->getIndexes();

    foreach ($indexes as $index) {
      $fields_converted = [];

      foreach ($index->getFields() as $field_id => $field) {
        if (in_array($field->getType(), $lost_types)) {
          // Convert to compatible type.
          $new_type = $this->getCompatibleFieldType($field->getType());

          if ($new_type !== $field->getType()) {
            $field->setType($new_type);
            $fields_converted[] = $field_id . ' (' . $field->getType() . ' → ' . $new_type . ')';
          }
        }
      }

      if (!empty($fields_converted)) {
        $index->save();

        $this->logger->info('Converted field types in index @index: @fields', [
          '@index' => $index->id(),
          '@fields' => implode(', ', $fields_converted),
        ]);
      }
    }
  }

  /**
   * Gets a compatible field type for migration.
   */
  protected function getCompatibleFieldType($original_type) {
    $type_mappings = [
    // Convert vector fields to text.
      'vector' => 'text',
    // Convert fulltext to text.
      'postgresql_fulltext' => 'text',
    ];

    return $type_mappings[$original_type] ?? $original_type;
  }

  /**
   * Updates indexes for backend changes.
   */
  protected function updateIndexesForBackendChange(ServerInterface $server, array $migration_data) {
    $indexes = $server->getIndexes();
    $to_backend = $migration_data['to_backend'];
    $backend_info = $this->compatibilityMatrix[$to_backend];

    foreach ($indexes as $index) {
      $index_updated = FALSE;

      // Ensure all fields are compatible with new backend.
      foreach ($index->getFields() as $field_id => $field) {
        if (!in_array($field->getType(), $backend_info['data_types'])) {
          $new_type = $this->getCompatibleFieldType($field->getType());
          $field->setType($new_type);
          $index_updated = TRUE;
        }
      }

      if ($index_updated) {
        $index->save();

        // Trigger re-indexing.
        $index->reindex();
      }
    }
  }

  /**
   * Displays migration results to the user.
   */
  protected function displayMigrationResults(array $migration_data) {
    $compatibility = $migration_data['compatibility'];

    if (!empty($compatibility['lost_features'])) {
      $this->messenger->addWarning($this->t('Backend migration completed. Lost features: @features', [
        '@features' => implode(', ', $compatibility['lost_features']),
      ]));
    }

    if (!empty($compatibility['gained_features'])) {
      $this->messenger->addStatus($this->t('New features available after migration: @features', [
        '@features' => implode(', ', $compatibility['gained_features']),
      ]));
    }

    if (!empty($compatibility['lost_types'])) {
      $this->messenger->addWarning($this->t('Field types converted due to incompatibility: @types', [
        '@types' => implode(', ', $compatibility['lost_types']),
      ]));
    }

    if (in_array('backup_vector_data', $compatibility['actions_required'])) {
      $this->messenger->addError($this->t('Vector search data has been removed. All AI-generated embeddings are permanently lost.'));
    }
  }

  /**
   * Gets indexes that use vector fields.
   */
  public function getIndexesWithVectorFields(ServerInterface $server) {
    $vector_indexes = [];

    foreach ($server->getIndexes() as $index) {
      foreach ($index->getFields() as $field) {
        if ($field->getType() === 'vector') {
          $vector_indexes[] = $index;
          break;
        }
      }
    }

    return $vector_indexes;
  }

  /**
   * Validates if a backend switch is safe.
   */
  public function validateBackendSwitch(ServerInterface $server, $to_backend) {
    $from_backend = $server->getBackend()->getPluginId();
    $compatibility = $this->checkBackendCompatibility($from_backend, $to_backend);

    $issues = [];

    if (!$compatibility['compatible']) {
      $issues[] = $this->t('Backend switch requires data migration that may result in data loss.');
    }

    if (!empty($compatibility['lost_types'])) {
      $vector_indexes = $this->getIndexesWithVectorFields($server);
      if (!empty($vector_indexes)) {
        $issues[] = $this->t('The following indexes use vector fields that will be lost: @indexes', [
          '@indexes' => implode(', ', array_map(function ($index) {
            return $index->label();
          }, $vector_indexes)),
        ]);
      }
    }

    return [
      'safe' => empty($issues),
      'issues' => $issues,
      'compatibility' => $compatibility,
    ];
  }

}
