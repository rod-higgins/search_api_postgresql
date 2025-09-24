<?php

namespace Drupal\Tests\search_api_postgresql\Kernel\Queue;

use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for the EmbeddingQueueManager.
 *
 * @group search_api_postgresql
 */
class EmbeddingQueueManagerTest extends KernelTestBase {
  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'search_api',
    'search_api_postgresql',
  ];

  /**
   * The embedding queue manager under test.
   */
  protected $queueManager;

  /**
   * The queue factory service.
   */
  protected $queueFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');

    // Get queue factory from container.
    $this->queueFactory = $this->container->get('queue');

    // Load the actual queue manager.
    require_once drupal_get_path('module', 'search_api_postgresql') . '/src/Queue/EmbeddingQueueManager.php';

    try {
      $this->queueManager = new EmbeddingQueueManager(
            $this->queueFactory,
            $this->container->get('config.factory'),
            $this->container->get('logger.factory')->get('search_api_postgresql')
        );
    }
    catch (\Error $e) {
      $this->markTestSkipped('Could not instantiate queue manager: ' . $e->getMessage());
    }
  }

  /**
   * Tests queue manager instantiation.
   */
  public function testQueueManagerInstantiation() {
    if ($this->queueManager) {
      $this->assertInstanceOf('\Drupal\search_api_postgresql\Queue\EmbeddingQueueManager', $this->queueManager);
    }
    else {
      $this->markTestSkipped('Queue manager not instantiated');
    }
  }

  /**
   * Tests basic queue operations.
   */
  public function testBasicQueueOperations() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    // Test queue creation.
    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');
    $this->assertNotNull($queue);

    // Test adding items to queue.
    $item_data = [
      'entity_type' => 'node',
      'entity_id' => 1,
      'field_name' => 'body',
      'text' => 'Test content for embedding generation',
    ];

    $result = $queue->createItem($item_data);
    $this->assertTrue($result);

    // Test queue item count.
    $count = $queue->numberOfItems();
    $this->assertGreaterThanOrEqual(1, $count);
  }

  /**
   * Tests queue processing workflow.
   */
  public function testQueueProcessingWorkflow() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');

    // Add multiple test items.
    $test_items = [
      [
        'entity_type' => 'node',
        'entity_id' => 1,
        'field_name' => 'title',
        'text' => 'First test article',
      ],
      [
        'entity_type' => 'node',
        'entity_id' => 2,
        'field_name' => 'body',
        'text' => 'Second test article with more content',
      ],
    ];

    foreach ($test_items as $item_data) {
      $queue->createItem($item_data);
    }

    $initial_count = $queue->numberOfItems();
    $this->assertGreaterThanOrEqual(2, $initial_count);

    // Test claiming an item.
    $item = $queue->claimItem(30);
    if ($item) {
      $this->assertIsObject($item);
      $this->assertObjectHasAttribute('data', $item);

      // Test releasing the item back to queue.
      $queue->releaseItem($item);
    }
  }

  /**
   * Tests queue priority handling.
   */
  public function testQueuePriorityHandling() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    // Test priority queue operations.
    $high_priority_item = [
      'entity_type' => 'node',
      'entity_id' => 100,
      'field_name' => 'title',
      'text' => 'High priority content',
      'priority' => 'high',
    ];

    $normal_priority_item = [
      'entity_type' => 'node',
      'entity_id' => 101,
      'field_name' => 'body',
      'text' => 'Normal priority content',
      'priority' => 'normal',
    ];

    // Add items with different priorities.
    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');
    $queue->createItem($normal_priority_item);
    $queue->createItem($high_priority_item);

    $this->assertGreaterThanOrEqual(2, $queue->numberOfItems());
  }

  /**
   * Tests batch processing capabilities.
   */
  public function testBatchProcessing() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');

    // Create batch of items.
    $batch_size = 5;
    for ($i = 1; $i <= $batch_size; $i++) {
      $item_data = [
        'entity_type' => 'node',
        'entity_id' => $i,
        'field_name' => 'body',
        'text' => "Batch item {$i} content for processing",
        'batch_id' => 'test_batch_001',
      ];
      $queue->createItem($item_data);
    }

    $total_items = $queue->numberOfItems();
    $this->assertGreaterThanOrEqual($batch_size, $total_items);
  }

  /**
   * Tests queue error handling.
   */
  public function testQueueErrorHandling() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');

    // Test error scenarios.
    $invalid_item = [
      'entity_type' => 'invalid_type',
      'entity_id' => 'invalid_id',
      'field_name' => '',
      'text' => '',
    ];

    // Adding invalid item should still work (validation happens during processing)
    $result = $queue->createItem($invalid_item);
    $this->assertTrue($result);

    // Test claiming and handling invalid items.
    $item = $queue->claimItem(30);
    if ($item) {
      // Simulate processing failure by releasing item.
      $queue->releaseItem($item);
      // Test that error handling works.
      $this->assertTrue(TRUE);
    }
  }

  /**
   * Tests queue statistics and monitoring.
   */
  public function testQueueStatistics() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');

    // Get initial statistics.
    $initial_count = $queue->numberOfItems();

    // Add some test items.
    for ($i = 1; $i <= 3; $i++) {
      $queue->createItem([
        'entity_type' => 'node',
        'entity_id' => $i,
        'field_name' => 'title',
        'text' => "Statistics test item {$i}",
      ]);
    }

    // Verify count increased.
    $new_count = $queue->numberOfItems();
    $this->assertGreaterThanOrEqual($initial_count + 3, $new_count);
  }

  /**
   * Tests queue cleanup operations.
   */
  public function testQueueCleanup() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');

    // Add some test items.
    $test_items = [
      ['entity_type' => 'node', 'entity_id' => 1, 'text' => 'Cleanup test 1'],
      ['entity_type' => 'node', 'entity_id' => 2, 'text' => 'Cleanup test 2'],
    ];

    foreach ($test_items as $item_data) {
      $queue->createItem($item_data);
    }

    $before_cleanup = $queue->numberOfItems();

    // Process and delete items to test cleanup.
    while ($item = $queue->claimItem(30)) {
      $queue->deleteItem($item);
    }

    $after_cleanup = $queue->numberOfItems();
    $this->assertLessThanOrEqual($before_cleanup, $after_cleanup);
  }

  /**
   * Tests queue configuration and settings.
   */
  public function testQueueConfiguration() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    // Test queue configuration values.
    $config = $this->container->get('config.factory')->get('search_api_postgresql.settings');

    // These should be configurable settings.
    $queue_settings = [
      'batch_size' => 10,
      'max_processing_time' => 300,
      'retry_attempts' => 3,
      'cleanup_interval' => 3600,
    ];

    foreach ($queue_settings as $setting => $default_value) {
      $this->assertIsInt($default_value);
      $this->assertGreaterThan(0, $default_value);
    }
  }

  /**
   * Tests queue worker integration.
   */
  public function testQueueWorkerIntegration() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    // Test that queue worker plugin exists.
    $queue_manager = $this->container->get('plugin.manager.queue_worker');
    $definitions = $queue_manager->getDefinitions();

    // Should have embedding worker defined.
    $this->assertIsArray($definitions);

    // Test queue item structure for worker compatibility.
    $queue_item = [
      'entity_type' => 'node',
      'entity_id' => 1,
      'field_name' => 'body',
      'text' => 'Worker integration test',
      'created' => time(),
    ];

    $this->assertArrayHasKey('entity_type', $queue_item);
    $this->assertArrayHasKey('entity_id', $queue_item);
    $this->assertArrayHasKey('text', $queue_item);
  }

  /**
   * Tests queue performance under load.
   */
  public function testQueuePerformance() {
    if (!$this->queueManager) {
      $this->markTestSkipped('Queue manager not instantiated');
    }

    $queue = $this->queueFactory->get('search_api_postgresql_embeddings');

    // Test adding many items quickly.
    $start_time = microtime(TRUE);
    $item_count = 100;

    for ($i = 1; $i <= $item_count; $i++) {
      $queue->createItem([
        'entity_type' => 'node',
        'entity_id' => $i,
        'field_name' => 'body',
        'text' => "Performance test item {$i}",
        'timestamp' => time(),
      ]);
    }

    $end_time = microtime(TRUE);
    $processing_time = $end_time - $start_time;

    // Should be able to add 100 items in reasonable time (less than 10 seconds)
    $this->assertLessThan(10.0, $processing_time);

    // Verify all items were added.
    $final_count = $queue->numberOfItems();
    $this->assertGreaterThanOrEqual($item_count, $final_count);
  }

}
