<?php

namespace Drupal\Tests\search_api_postgresql\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;

/**
 * Tests the PostgreSQL backend functionality.
 *
 * @group search_api_postgresql
 */
class PostgreSQLBackendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_postgresql',
    'user',
    'system',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('user');
    $this->installConfig(['search_api']);

    // Create a test server with PostgreSQL backend.
    $this->server = Server::create([
      'id' => 'postgresql_test_server',
      'name' => 'PostgreSQL Test Server',
      'backend' => 'postgresql',
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
      ],
    ]);
    $this->server->save();

    // Create a test index.
    $this->index = Index::create([
      'id' => 'postgresql_test_index',
      'name' => 'PostgreSQL Test Index',
      'server' => $this->server->id(),
      'datasource_settings' => [
        'entity:user' => [],
      ],
    ]);
    $this->index->save();
  }

  /**
   * Tests server backend retrieval.
   */
  public function testServerBackend() {
    $backend = $this->server->getBackend();
    $this->assertInstanceOf('Drupal\search_api_postgresql\Plugin\search_api\backend\PostgreSQLBackend', $backend);
  }

  /**
   * Tests supported features.
   */
  public function testSupportedFeatures() {
    $backend = $this->server->getBackend();
    $features = $backend->getSupportedFeatures();

    $expected_features = [
      'search_api_facets',
      'search_api_autocomplete',
      'search_api_spellcheck',
      'search_api_mlt',
      'search_api_random_sort',
      'search_api_grouping',
    ];

    foreach ($expected_features as $feature) {
      $this->assertContains($feature, $features);
    }
  }

  /**
   * Tests supported data types.
   */
  public function testSupportedDataTypes() {
    $backend = $this->server->getBackend();

    $supported_types = [
      'text',
      'string',
      'integer',
      'decimal',
      'date',
      'boolean',
      'postgresql_fulltext',
    ];

    foreach ($supported_types as $type) {
      $this->assertTrue($backend->supportsDataType($type));
    }

    $this->assertFalse($backend->supportsDataType('unsupported_type'));
  }

}
