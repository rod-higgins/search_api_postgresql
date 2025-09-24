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

    // Create test server with UNIFIED backend.
    $this->server = Server::create([
      'id' => 'postgresql_test_server',
      'name' => 'PostgreSQL Test Server',
    // SINGLE BACKEND ID.
      'backend' => 'postgresql',
      'backend_config' => [
        'connection' => [
          'host' => 'localhost',
          'port' => 5432,
          'database' => 'test_db',
          'username' => 'test_user',
          'password' => 'test_password',
          'ssl_mode' => 'disable',
        ],
        'search_settings' => [
          'index_prefix' => 'test_',
          'fts_configuration' => 'english',
          'batch_size' => 50,
          'debug' => TRUE,
        ],
        // Test AI features.
        'ai_features' => [
          'enabled' => TRUE,
          'provider' => 'azure_openai',
          'azure_openai' => [
            'endpoint' => 'https://test.openai.azure.com/',
            'api_key' => 'test-key',
            'deployment_name' => 'test-deployment',
            'model' => 'text-embedding-3-small',
            'dimension' => 1536,
          ],
          'batch_size' => 25,
          'rate_limit_delay' => 100,
          'max_retries' => 3,
          'timeout' => 30,
          'enable_cache' => TRUE,
          'cache_ttl' => 3600,
        ],
        'vector_index' => [
          'enabled' => TRUE,
          'method' => 'ivfflat',
          'ivfflat_lists' => 100,
          'distance' => 'cosine',
          'probes' => 10,
        ],
        'hybrid_search' => [
          'enabled' => TRUE,
          'text_weight' => 0.6,
          'vector_weight' => 0.4,
          'similarity_threshold' => 0.15,
          'max_results' => 1000,
          'boost_exact_matches' => TRUE,
        ],
        'performance' => [
          'azure_optimized' => TRUE,
          'connection_pool_size' => 10,
          'statement_timeout' => 30000,
          'work_mem' => '256MB',
          'effective_cache_size' => '2GB',
        ],
      ],
    ]);
    $this->server->save();

    $this->index = Index::create([
      'id' => 'postgresql_test_index',
      'name' => 'PostgreSQL Test Index',
      'server' => $this->server->id(),
      'datasource_settings' => [
        'entity:node' => [],
      ],
    ]);
    $this->index->save();
  }

  /**
   * Tests unified PostgreSQL backend instantiation.
   */
  public function testUnifiedBackendInstantiation() {
    $backend = $this->server->getBackend();
    $this->assertInstanceOf('Drupal\search_api_postgresql\Plugin\search_api\backend\PostgreSQLBackend', $backend);
    $this->assertEquals('postgresql', $backend->getPluginId());
  }

  /**
   * Tests all features are available in unified backend.
   */
  public function testUnifiedBackendFeatures() {
    $backend = $this->server->getBackend();
    $features = $backend->getSupportedFeatures();

    $expected_features = [
      'search_api_facets',
      'search_api_autocomplete',
      'search_api_spellcheck',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_grouping',
      // AI & Vector features now in single backend.
      'search_api_vector_search',
      'search_api_semantic_search',
      'search_api_hybrid_search',
      'search_api_ai_embeddings',
    ];

    foreach ($expected_features as $feature) {
      $this->assertContains($feature, $features, "Feature '{$feature}' should be supported");
    }
  }

  /**
   * Tests AI provider switching in unified backend.
   */
  public function testAiProviderSwitching() {
    $backend = $this->server->getBackend();
    $config = $backend->getConfiguration();

    // Test Azure OpenAI provider.
    $this->assertEquals('azure_openai', $config['ai_features']['provider']);
    $this->assertTrue($config['ai_features']['enabled']);

    // Test configuration structure.
    $this->assertArrayHasKey('azure_openai', $config['ai_features']);
    $this->assertArrayHasKey('endpoint', $config['ai_features']['azure_openai']);
    $this->assertArrayHasKey('deployment_name', $config['ai_features']['azure_openai']);
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

    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($service);
    $method = $reflection->getMethod('preprocessText');
    $method->setAccessible(TRUE);

    // Test whitespace normalization.
    $input = "This  has   multiple    spaces\n\nand\tlines";
    $expected = "This has multiple spaces and lines";
    $result = $method->invokeArgs($service, [$input]);
    $this->assertEquals($expected, $result);

    // Test length limiting.
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

    // Test setting different modes.
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

    // Test valid configuration.
    $config = $backend->defaultConfiguration();
    $this->assertArrayHasKey('azure_embedding', $config);
    $this->assertArrayHasKey('vector_index', $config);
    $this->assertArrayHasKey('hybrid_search', $config);

    // Test weight validation.
    $text_weight = $config['hybrid_search']['text_weight'];
    $vector_weight = $config['hybrid_search']['vector_weight'];
    $this->assertEquals(1.0, $text_weight + $vector_weight, 'Weights should sum to 1.0', 0.01);
  }

  /**
   * Tests vector statistics collection.
   */
  public function testVectorStatistics() {
    $backend = $this->server->getBackend();

    // Get stats for empty index.
    $stats = $backend->getAzureVectorStats($this->index);

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('azure_service', $stats);
    $this->assertArrayHasKey('embedding_model', $stats);
    $this->assertArrayHasKey('vector_dimension', $stats);

    $this->assertEquals('azure_openai', $stats['azure_service']);
    $this->assertEquals(1536, $stats['vector_dimension']);
  }

}
