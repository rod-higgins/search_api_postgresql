<?php

namespace Drupal\Tests\search_api_postgresql\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService;

/**
 * Tests the PostgreSQL vector search functionality.
 *
 * @group search_api_postgresql
 */
class VectorSearchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_postgresql',
    'user',
    'system',
    'node',
    'field',
    'text',
  ];

  /**
   * The search server used for testing.
   *
   * @var \Drupal\search_api\ServerInterface
   */
  protected $server;

  /**
   * The search index used for testing.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * Mock embedding service.
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $mockEmbeddingService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['search_api', 'node', 'field']);

    // Create a test server with Azure PostgreSQL backend.
    $this->server = Server::create([
      'id' => 'azure_postgresql_test_server',
      'name' => 'Azure PostgreSQL Test Server',
      'backend' => 'postgresql_azure',
      'backend_config' => [
        'connection' => [
          'host' => 'localhost',
          'port' => 5432,
          'database' => 'drupal_test',
          'username' => 'drupal',
          'password' => 'drupal',
          'ssl_mode' => 'disable',
        ],
        'index_prefix' => 'test_search_api_',
        'fts_configuration' => 'english',
        'debug' => TRUE,
        'azure_embedding' => [
          'enabled' => TRUE,
          'service_type' => 'azure_openai',
          'endpoint' => 'https://test.openai.azure.com/',
          'api_key' => 'test-key',
          'deployment_name' => 'test-deployment',
          'dimension' => 1536,
        ],
        'hybrid_search' => [
          'text_weight' => 0.6,
          'vector_weight' => 0.4,
          'similarity_threshold' => 0.1,
        ],
      ],
    ]);
    $this->server->save();

    // Create a test index.
    $this->index = Index::create([
      'id' => 'azure_postgresql_test_index',
      'name' => 'Azure PostgreSQL Test Index',
      'server' => $this->server->id(),
      'datasource_settings' => [
        'entity:node' => [],
      ],
    ]);
    $this->index->save();
  }

  /**
   * Tests Azure PostgreSQL backend instantiation.
   */
  public function testAzureBackendInstantiation() {
    $backend = $this->server->getBackend();
    $this->assertInstanceOf('Drupal\search_api_postgresql\Plugin\search_api\backend\AzurePostgreSQLBackend', $backend);
  }

  /**
   * Tests supported features include vector search capabilities.
   */
  public function testVectorSearchFeatures() {
    $backend = $this->server->getBackend();
    $features = $backend->getSupportedFeatures();
    
    $expected_features = [
      'search_api_facets',
      'search_api_autocomplete',
      'search_api_spellcheck',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_grouping',
      'search_api_azure_vector_search',
      'search_api_semantic_search',
      'search_api_hybrid_search',
      'search_api_azure_ai',
    ];

    foreach ($expected_features as $feature) {
      $this->assertContains($feature, $features, "Feature '{$feature}' should be supported");
    }
  }

  /**
   * Tests embedding service availability check.
   */
  public function testEmbeddingServiceAvailability() {
    // Test with valid configuration
    $service = new AzureOpenAIEmbeddingService(
      'https://test.openai.azure.com/',
      'test-key',
      'test-deployment'
    );
    $this->assertTrue($service->isAvailable());

    // Test with missing configuration
    $service = new AzureOpenAIEmbeddingService('', '', '');
    $this->assertFalse($service->isAvailable());
  }

  /**
   * Tests text preprocessing for embeddings.
   */
  public function testTextPreprocessing() {
    $service = new AzureOpenAIEmbeddingService(
      'https://test.openai.azure.com/',
      'test-key',
      'test-deployment'
    );

    // Use reflection to access protected method
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('preprocessText');
    $method->setAccessible(TRUE);

    // Test whitespace normalization
    $input = "This  has   multiple    spaces\n\nand\tlines";
    $expected = "This has multiple spaces and lines";
    $result = $method->invokeArgs($service, [$input]);
    $this->assertEquals($expected, $result);

    // Test length limiting
    $long_text = str_repeat('a', 9000);
    $result = $method->invokeArgs($service, [$long_text]);
    $this->assertLessThanOrEqual(8000, strlen($result));
  }

  /**
   * Tests query mode detection and handling.
   */
  public function testQueryModeHandling() {
    $query = $this->index->query();
    
    // Test default mode (should be hybrid)
    $this->assertNull($query->getOption('search_mode'));
    
    // Test setting different modes
    $query->setOption('search_mode', 'vector_only');
    $this->assertEquals('vector_only', $query->getOption('search_mode'));
    
    $query->setOption('search_mode', 'text_only');
    $this->assertEquals('text_only', $query->getOption('search_mode'));
    
    $query->setOption('search_mode', 'hybrid');
    $this->assertEquals('hybrid', $query->getOption('search_mode'));
  }

  /**
   * Tests configuration validation.
   */
  public function testConfigurationValidation() {
    $backend = $this->server->getBackend();
    
    // Test valid configuration
    $config = $backend->defaultConfiguration();
    $this->assertArrayHasKey('azure_embedding', $config);
    $this->assertArrayHasKey('vector_index', $config);
    $this->assertArrayHasKey('hybrid_search', $config);
    
    // Test weight validation
    $text_weight = $config['hybrid_search']['text_weight'];
    $vector_weight = $config['hybrid_search']['vector_weight'];
    $this->assertEquals(1.0, $text_weight + $vector_weight, 'Weights should sum to 1.0', 0.01);
  }

  /**
   * Tests vector statistics collection.
   */
  public function testVectorStatistics() {
    $backend = $this->server->getBackend();
    
    // Get stats for empty index
    $stats = $backend->getAzureVectorStats($this->index);
    
    $this->assertIsArray($stats);
    $this->assertArrayHasKey('azure_service', $stats);
    $this->assertArrayHasKey('embedding_model', $stats);
    $this->assertArrayHasKey('vector_dimension', $stats);
    
    $this->assertEquals('azure_openai', $stats['azure_service']);
    $this->assertEquals(1536, $stats['vector_dimension']);
  }

}