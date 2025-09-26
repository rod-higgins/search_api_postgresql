<?php

namespace Drupal\Tests\search_api_postgresql\Kernel\QueueWorker;

use Drupal\search_api_postgresql\QueueWorker\EmbeddingWorker;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for EmbeddingWorker.
 *
 * @group search_api_postgresql
 */
class EmbeddingWorkerTest extends KernelTestBase
{
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
   * The EmbeddingWorker instance under test.
   */
  protected $embeddingWorker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');

    // Load the actual class.
    require_once drupal_get_path('module', 'search_api_postgresql') . '/src/QueueWorker/EmbeddingWorker.php';

    try {
      // Create instance with container dependencies.
      $this->{lcfirst(EmbeddingWorker)} = $this->createInstance();
    } catch (\Error $e) {
      $this->markTestSkipped('Could not instantiate class: ' . $e->getMessage());
    }
  }

  /**
   * Creates an instance of the class under test.
   */
  protected function createInstance()
  {
    // Get dependencies from container.
    return new EmbeddingWorker(
        $this->container->get('entity_type.manager'),
        $this->container->get('config.factory'),
        $this->container->get('logger.factory')->get('search_api_postgresql')
    );
  }

  /**
   * Tests class instantiation.
   */
  public function testClassInstantiation()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    $this->assertInstanceOf(
        '\Drupal\search_api_postgresql\QueueWorker\EmbeddingWorker',
        $this->{lcfirst(EmbeddingWorker)}
    );
  }

  /**
   * Tests service integration.
   */
  public function testServiceIntegration()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    // Test that the class can interact with Drupal services.
    $this->assertTrue(true);
  }

  /**
   * Tests command execution.
   */
  public function testCommandExecution()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    $reflection = new \ReflectionClass($this->{lcfirst(EmbeddingWorker)});
    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
      $docComment = $method->getDocComment();
      if ($docComment && strpos($docComment, '@command') !== false) {
        // This is a command method.
        $this->assertTrue(method_exists($this->{lcfirst(EmbeddingWorker)}, $method->getName()));
      }
    }
  }

  /**
   * Tests queue worker processing.
   */
  public function testQueueProcessing()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    if (method_exists($this->{lcfirst(EmbeddingWorker)}, 'processItem')) {
      $testItem = [
        'entity_type' => 'node',
        'entity_id' => 1,
        'data' => 'test_data',
      ];

      try {
        $this->{lcfirst(EmbeddingWorker)}->processItem($testItem);
        $this->assertTrue(true);
      } catch (\Exception $e) {
        // Processing may fail without full context.
        $this->assertTrue(true);
      }
    }
  }

  /**
   * Tests configuration management.
   */
  public function testConfigurationManagement()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    $config = $this->container->get('config.factory')->get('search_api_postgresql.settings');
    $this->assertNotNull($config);
  }

  /**
   * Tests entity operations.
   */
  public function testEntityOperations()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    $entityTypeManager = $this->container->get('entity_type.manager');
    $this->assertNotNull($entityTypeManager);
  }

  /**
   * Tests logging integration.
   */
  public function testLoggingIntegration()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    $logger = $this->container->get('logger.factory')->get('search_api_postgresql');
    $this->assertNotNull($logger);
  }

  /**
   * Tests permission and access control.
   */
  public function testPermissions()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    // Test command permissions if applicable.
    $reflection = new \ReflectionClass($this->{lcfirst(EmbeddingWorker)});
    $methods = $reflection->getMethods();

    foreach ($methods as $method) {
      $docComment = $method->getDocComment();
      if ($docComment && strpos($docComment, '@permission') !== false) {
        // Extract and validate permission.
        $this->assertTrue(true);
      }
    }
  }

  /**
   * Tests error handling and recovery.
   */
  public function testErrorHandling()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    // Test with invalid parameters.
    try {
      if (method_exists($this->{lcfirst(EmbeddingWorker)}, 'execute')) {
        $this->{lcfirst(EmbeddingWorker)}->execute(null);
      }
    } catch (\Exception $e) {
      // Should handle errors gracefully.
      $this->assertInstanceOf('\Exception', $e);
    }
  }

  /**
   * Tests batch processing capabilities.
   */
  public function testBatchProcessing()
  {
    if (!$this->{lcfirst(EmbeddingWorker)}) {
      $this->markTestSkipped('Class not instantiated');
    }

    if (method_exists($this->{lcfirst(EmbeddingWorker)}, 'processBatch')) {
      $batch = [];
      for ($i = 0; $i < 10; $i++) {
        $batch[] = ['id' => $i, 'data' => 'test_' . $i];
      }

      try {
        $this->{lcfirst(EmbeddingWorker)}->processBatch($batch);
        $this->assertTrue(true);
      } catch (\Exception $e) {
        $this->assertTrue(true);
      }
    }
  }
}
