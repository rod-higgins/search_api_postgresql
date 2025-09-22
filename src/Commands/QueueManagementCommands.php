<?php

namespace Drupal\search_api_postgresql\Commands;

use Drupal\search_api\Entity\Server;
use Drupal\search_api\Entity\Index;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Helper\Table;

/**
 * Drush commands for managing embedding queues.
 */
class QueueManagementCommands extends DrushCommands {

  /**
   * Shows embedding queue status.
   *
   * @command search-api-postgresql:queue-status
   * @aliases sapg-queue-status
   * @usage search-api-postgresql:queue-status
   *   Show embedding queue status and statistics.
   */
  public function queueStatus() {
    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');
    $stats = $queue_manager->getQueueStats();

    $this->output()->writeln('<info>Embedding Queue Status</info>');
    $this->output()->writeln('======================');

    if (isset($stats['error'])) {
      $this->output()->writeln('<error>Error getting queue stats: ' . $stats['error'] . '</error>');
      return;
    }

    $this->output()->writeln("Queue Name: {$stats['queue_name']}");
    $this->output()->writeln("Pending Items: {$stats['items_pending']}");

    if (isset($stats['operation_distribution'])) {
      $this->output()->writeln("\nOperation Distribution:");
      foreach ($stats['operation_distribution'] as $operation => $count) {
        $this->output()->writeln("  {$operation}: {$count}");
      }
    }

    if (isset($stats['priority_distribution'])) {
      $this->output()->writeln("\nPriority Distribution:");
      foreach ($stats['priority_distribution'] as $priority => $count) {
        $this->output()->writeln("  Priority {$priority}: {$count}");
      }
    }

    // Show configuration.
    $config = $stats['config'] ?? [];
    $this->output()->writeln("\nQueue Configuration:");
    $this->output()->writeln("  Enabled: " . ($config['enabled'] ? 'Yes' : 'No'));
    $this->output()->writeln("  Batch Size: " . ($config['batch_size'] ?? 'Not set'));
    $this->output()->writeln("  Max Processing Time: " . ($config['max_processing_time'] ?? 'Not set') . " seconds");
  }

  /**
   * Processes embedding queue items manually.
   *
   * @param array $options
   *   Command options.
   *
   * @command search-api-postgresql:queue-process
   * @aliases sapg-queue-process
   * @option max-items
   *   Maximum number of items to process (default: 50)
   * @option time-limit
   *   Time limit in seconds (default: 60)
   * @option continuous
   *   Keep processing until queue is empty
   * @usage search-api-postgresql:queue-process --max-items=100
   *   Process up to 100 items from the embedding queue.
   */
  public function processQueue(array $options = ['max-items' => 50, 'time-limit' => 60, 'continuous' => FALSE]) {
    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');

    $max_items = (int) $options['max-items'];
    $time_limit = (int) $options['time-limit'];
    $continuous = $options['continuous'];

    if ($continuous) {
      $this->output()->writeln('<info>Starting continuous queue processing (Ctrl+C to stop)...</info>');

      $total_processed = 0;
      $rounds = 0;

      while (TRUE) {
        $rounds++;
        $this->output()->writeln("\n--- Processing Round {$rounds} ---");

        $results = $queue_manager->processQueue($max_items, $time_limit);

        $this->displayProcessingResults($results);
        $total_processed += $results['processed'];

        if ($results['remaining_items'] == 0) {
          $this->output()->writeln('<info>Queue is empty. Processing complete.</info>');
          break;
        }

        if ($results['processed'] == 0) {
          $this->output()->writeln('<comment>No items processed in this round. Waiting 5 seconds...</comment>');
          sleep(5);
        }
      }

      $this->output()->writeln("\n<info>Total items processed: {$total_processed} in {$rounds} rounds</info>");
    }
    else {
      $this->output()->writeln('<info>Processing embedding queue...</info>');

      $results = $queue_manager->processQueue($max_items, $time_limit);
      $this->displayProcessingResults($results);
    }
  }

  /**
   * Clears the embedding queue.
   *
   * @command search-api-postgresql:queue-clear
   * @aliases sapg-queue-clear
   * @usage search-api-postgresql:queue-clear
   *   Clear all items from the embedding queue.
   */
  public function clearQueue() {
    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');

    // Get current count for confirmation.
    $stats = $queue_manager->getQueueStats();
    $item_count = $stats['items_pending'] ?? 0;

    if ($item_count == 0) {
      $this->output()->writeln('<comment>Queue is already empty.</comment>');
      return;
    }

    if (!$this->confirm("This will remove {$item_count} items from the embedding queue. Continue?")) {
      throw new UserAbortException();
    }

    $success = $queue_manager->clearQueue();

    if ($success) {
      $this->output()->writeln('<info>Queue cleared successfully</info>');
    }
    else {
      $this->output()->writeln('<error>Failed to clear queue</error>');
    }
  }

  /**
   * Enables or disables queue processing for a server.
   *
   * @param string $server_id
   *   The server ID.
   * @param string $action
   *   Action: enable or disable.
   *
   * @command search-api-postgresql:queue-server
   * @aliases sapg-queue-server
   * @usage search-api-postgresql:queue-server my_server enable
   *   Enable queue processing for the specified server.
   */
  public function manageServerQueue($server_id, $action) {
    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');

    $server = Server::load($server_id);
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    switch (strtolower($action)) {
      case 'enable':
        $queue_manager->setQueueEnabledForServer($server_id, TRUE);
        $this->output()->writeln("<info>Queue processing enabled for server '{$server_id}'</info>");
        break;

      case 'disable':
        $queue_manager->setQueueEnabledForServer($server_id, FALSE);
        $this->output()->writeln("<info>Queue processing disabled for server '{$server_id}'</info>");
        break;

      case 'status':
        $enabled = $queue_manager->isQueueEnabledForServer($server_id);
        $status = $enabled ? 'enabled' : 'disabled';
        $this->output()->writeln("Queue processing for server '{$server_id}': {$status}");
        break;

      default:
        throw new \Exception("Invalid action '{$action}'. Use: enable, disable, or status");
    }
  }

  /**
   * Queues an index for embedding regeneration.
   *
   * @param string $index_id
   *   The index ID.
   * @param array $options
   *   Command options.
   *
   * @command search-api-postgresql:queue-regenerate
   * @aliases sapg-queue-regen
   * @option batch-size
   *   Batch size for processing (default: 50)
   * @option priority
   *   Priority level: high, normal, low (default: normal)
   * @usage search-api-postgresql:queue-regenerate my_index --batch-size=100
   *   Queue the index for embedding regeneration with batch size 100.
   */
  public function queueRegenerateEmbeddings($index_id, array $options = ['batch-size' => 50, 'priority' => 'normal']) {
    $index = Index::load($index_id);

    if (!$index) {
      throw new \Exception("Index '{$index_id}' not found.");
    }

    $server = $index->getServerInstance();
    $backend = $server->getBackend();

    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Index '{$index_id}' is not using PostgreSQL backend.");
    }

    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');

    if (!$queue_manager->isQueueEnabledForServer($server->id())) {
      throw new \Exception("Queue processing is not enabled for server '{$server->id()}'.");
    }

    $batch_size = (int) $options['batch-size'];
    $priority_name = $options['priority'];

    $priority_map = ['high' => 50, 'normal' => 100, 'low' => 200];
    $priority = $priority_map[$priority_name] ?? 100;

    $success = $queue_manager->queueIndexEmbeddingRegeneration(
      $server->id(),
      $index_id,
      $batch_size,
    // Start from offset 0.
      0,
      $priority
    );

    if ($success) {
      $this->output()->writeln("<info>Queued embedding regeneration for index '{$index_id}'</info>");
      $this->output()->writeln("Batch size: {$batch_size}");
      $this->output()->writeln("Priority: {$priority_name} ({$priority})");
    }
    else {
      $this->output()->writeln("<error>Failed to queue embedding regeneration</error>");
    }
  }

  /**
   * Shows queue configuration for all servers.
   *
   * @command search-api-postgresql:queue-config
   * @aliases sapg-queue-config
   * @usage search-api-postgresql:queue-config
   *   Show queue configuration for all PostgreSQL servers.
   */
  public function showQueueConfig() {
    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');

    // Get all PostgreSQL servers.
    $servers = \Drupal::entityTypeManager()
      ->getStorage('search_api_server')
      ->loadByProperties(['backend' => ['postgresql', 'postgresql_azure']]);

    if (empty($servers)) {
      $this->output()->writeln('<comment>No PostgreSQL servers found.</comment>');
      return;
    }

    $this->output()->writeln('<info>Queue Configuration by Server</info>');
    $this->output()->writeln('================================');

    $table = new Table($this->output());
    $table->setHeaders(['Server ID', 'Server Name', 'Backend', 'Queue Enabled', 'AI Embeddings']);

    foreach ($servers as $server) {
      $backend = $server->getBackend();
      $config = $backend->getConfiguration();

      $queue_enabled = $queue_manager->isQueueEnabledForServer($server->id()) ? 'Yes' : 'No';

      $ai_enabled = FALSE;
      if ($config['ai_embeddings']['enabled'] ?? FALSE) {
        $ai_enabled = TRUE;
      }
      if ($config['azure_embedding']['enabled'] ?? FALSE) {
        $ai_enabled = TRUE;
      }

      $table->addRow([
        $server->id(),
        $server->label(),
        $backend->getPluginId(),
        $queue_enabled,
        $ai_enabled ? 'Yes' : 'No',
      ]);
    }

    $table->render();

    // Show global queue settings.
    $this->output()->writeln("\n<info>Global Queue Settings</info>");
    $stats = $queue_manager->getQueueStats();
    $config = $stats['config'] ?? [];

    $this->output()->writeln("Queue globally enabled: " . ($config['enabled'] ? 'Yes' : 'No'));
    $this->output()->writeln("Default batch size: " . ($config['batch_size'] ?? 'Not set'));
    $this->output()->writeln("Max processing time: " . ($config['max_processing_time'] ?? 'Not set') . " seconds");
  }

  /**
   * Tests queue processing with a sample item.
   *
   * @param string $server_id
   *   The server ID.
   *
   * @command search-api-postgresql:queue-test
   * @aliases sapg-queue-test
   * @usage search-api-postgresql:queue-test my_server
   *   Test queue processing with a sample embedding generation.
   */
  public function testQueue($server_id) {
    $server = Server::load($server_id);

    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    $queue_manager = \Drupal::service('search_api_postgresql.embedding_queue_manager');

    if (!$queue_manager->isQueueEnabledForServer($server_id)) {
      throw new \Exception("Queue processing is not enabled for server '{$server_id}'.");
    }

    $this->output()->writeln('<info>Testing queue processing...</info>');

    // Queue a test item.
    $test_text = "This is a test text for embedding generation via queue processing.";
    $success = $queue_manager->queueEmbeddingGeneration(
      $server_id,
      'test_index',
      'test_item_' . time(),
      $test_text,
    // High priority for testing.
      50
    );

    if (!$success) {
      throw new \Exception('Failed to queue test item.');
    }

    $this->output()->writeln('Test item queued successfully');

    // Check queue status.
    $stats = $queue_manager->getQueueStats();
    $this->output()->writeln("Queue items: {$stats['items_pending']}");

    // Process the queue.
    $this->output()->writeln('Processing queue...');
    // Process 1 item, 30s limit.
    $results = $queue_manager->processQueue(1, 30);

    $this->displayProcessingResults($results);

    if ($results['processed'] > 0) {
      $this->output()->writeln('<info>Queue test completed successfully!</info>');
    }
    else {
      $this->output()->writeln('<comment>WARNING: No items were processed. Check logs for details.</comment>');
    }
  }

  /**
   * Displays processing results in a formatted way.
   *
   * @param array $results
   *   Processing results.
   */
  protected function displayProcessingResults(array $results) {
    $this->output()->writeln("\nProcessing Results:");
    $this->output()->writeln("  Processed: {$results['processed']}");
    $this->output()->writeln("  Failed: {$results['failed']}");
    $this->output()->writeln("  Elapsed Time: {$results['elapsed_time']} seconds");
    $this->output()->writeln("  Remaining Items: {$results['remaining_items']}");

    if (!empty($results['errors'])) {
      $this->output()->writeln("\nErrors:");
      foreach (array_slice($results['errors'], 0, 5) as $error) {
        $this->output()->writeln("  • {$error}");
      }

      if (count($results['errors']) > 5) {
        $additional = count($results['errors']) - 5;
        $this->output()->writeln("  ... and {$additional} more errors");
      }
    }
  }

}
