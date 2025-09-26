<?php

namespace Drupal\Tests\search_api_postgresql\Functional;

use Drupal\Tests\search_api\Functional\SearchApiBrowserTestBase;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Entity\Index;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Comprehensive functional tests for PostgreSQL backend.
 *
 * @group search_api_postgresql
 */
class PostgreSQLBackendFunctionalTest extends SearchApiBrowserTestBase
{
  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api',
    'search_api_postgresql',
    'node',
    'field',
    'text',
    'user',
    'views',
    'views_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The test server.
   *
   * @var \Drupal\search_api\ServerInterface
   */
  protected $server;

  /**
   * The test index.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * Test node type.
   *
   * @var \Drupal\node\NodeTypeInterface
   */
  protected $nodeType;

  /**
   * Test nodes.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected $testNodes = [];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void
  {
    parent::setUp();

    // Create admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer search_api',
      'administer nodes',
      'access content overview',
      'create article content',
      'edit own article content',
      'view published content',
      'access content',
      'administer content types',
      'administer views',
    ]);

    $this->drupalLogin($this->adminUser);

    // Create content type.
    $this->createContentType();

    // Create test server.
    $this->createTestServer();

    // Create test index.
    $this->createTestIndex();

    // Create test content.
    $this->createTestContent();
  }

  /**
   * Creates a content type for testing.
   */
  protected function createContentType(array $values = [])
  {
    $this->nodeType = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $this->nodeType->save();

    // Add body field (usually comes with article content type)
    node_add_body_field($this->nodeType);
  }

  /**
   * Creates a PostgreSQL search server for testing.
   */
  protected function createTestServer()
  {
    $server_config = [
      'database' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '5432',
        'database' => getenv('DB_NAME') ?: 'drupal_test',
        'username' => getenv('DB_USER') ?: 'drupal',
        'password' => getenv('DB_PASS') ?: 'drupal',
      ],
      'vector_search' => [
        'enabled' => false,
      ],
      'cache' => [
        'enabled' => true,
        'cache_type' => 'memory',
      ],
    ];

    $this->server = Server::create([
      'id' => 'postgresql_test_server',
      'name' => 'PostgreSQL Test Server',
      'backend' => 'postgresql',
      'backend_config' => $server_config,
    ]);
    $this->server->save();
  }

  /**
   * Creates a search index for testing.
   */
  protected function createTestIndex()
  {
    $this->index = Index::create([
      'id' => 'test_index',
      'name' => 'Test Index',
      'server' => $this->server->id(),
      'datasource_settings' => [
        'entity:node' => [
          'plugin_id' => 'entity:node',
          'settings' => [
            'bundles' => [
              'default' => true,
              'selected' => ['article'],
            ],
          ],
        ],
      ],
      'field_settings' => [
        'title' => [
          'label' => 'Title',
          'type' => 'text',
          'datasource_id' => 'entity:node',
          'property_path' => 'title',
          'boost' => 5.0,
        ],
        'body' => [
          'label' => 'Body',
          'type' => 'text',
          'datasource_id' => 'entity:node',
          'property_path' => 'body',
          'boost' => 1.0,
        ],
        'created' => [
          'label' => 'Created',
          'type' => 'date',
          'datasource_id' => 'entity:node',
          'property_path' => 'created',
        ],
        'status' => [
          'label' => 'Published',
          'type' => 'boolean',
          'datasource_id' => 'entity:node',
          'property_path' => 'status',
        ],
      ],
      'processor_settings' => [
        'html_filter' => [
          'plugin_id' => 'html_filter',
          'settings' => [
            'all_fields' => false,
            'fields' => ['body'],
            'title' => true,
            'alt' => true,
            'tags' => [
              'h1' => 5,
              'h2' => 3,
              'h3' => 2,
              'strong' => 2,
              'b' => 2,
            ],
          ],
        ],
        'tokenizer' => [
          'plugin_id' => 'tokenizer',
          'settings' => [
            'all_fields' => false,
            'fields' => ['title', 'body'],
            'spaces' => '[\\s]+',
            'overlap_cjk' => 1,
            'minimum_word_size' => 3,
          ],
        ],
        'stopwords' => [
          'plugin_id' => 'stopwords',
          'settings' => [
            'all_fields' => false,
            'fields' => ['title', 'body'],
            'stopwords' => [
              'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by',
              'for', 'if', 'in', 'into', 'is', 'it',
              'no', 'not', 'of', 'on', 'or', 'such',
              'that', 'the', 'their', 'then', 'there', 'these',
              'they', 'this', 'to', 'was', 'will', 'with',
            ],
          ],
        ],
      ],
    ]);
    $this->index->save();
  }

  /**
   * Creates test content for searching.
   */
  protected function createTestContent()
  {
    $test_data = [
      [
        'title' => 'Machine Learning and Artificial Intelligence',
        'body' => 'Machine learning is a method of data analysis that automates ' .
          'analytical model building. It is a branch of artificial intelligence ' .
          'based on the idea that systems can learn from data, identify patterns ' .
          'and make decisions with minimal human intervention.',
      ],
      [
        'title' => 'PostgreSQL Database Management',
        'body' => 'PostgreSQL is a powerful, open source object-relational database ' .
          'system that uses and extends the SQL language combined with many ' .
          'features that safely store and scale the most complicated data workloads.',
      ],
      [
        'title' => 'Drupal Content Management System',
        'body' => 'Drupal is a free and open-source content management framework ' .
          'written in PHP and distributed under the GNU General Public License. ' .
          'It provides a back-end framework for at least 14% of the top 10,000 ' .
          'websites worldwide.',
      ],
      [
        'title' => 'Search API and Vector Databases',
        'body' => 'Search API provides a unified framework for implementing search ' .
          'services in Drupal. With vector databases, we can implement semantic ' .
          'search that understands context and meaning rather than just exact ' .
          'keyword matches.',
      ],
      [
        'title' => 'Full-Text Search Implementation',
        'body' => 'Full-text search allows users to search for documents based on ' .
          'the content within them. Modern implementations include relevance ' .
          'scoring, highlighting, faceted search, and advanced query capabilities.',
      ],
    ];

    foreach ($test_data as $data) {
      $node = Node::create([
        'type' => 'article',
        'title' => $data['title'],
        'body' => [
          'value' => $data['body'],
          'format' => 'basic_html',
        ],
        'status' => 1,
        'uid' => $this->adminUser->id(),
      ]);
      $node->save();
      $this->testNodes[] = $node;
    }
  }

  /**
   * Tests server creation and configuration.
   */
  public function testServerCreation()
  {
    // Check that server was created.
    $this->assertNotNull($this->server);
    $this->assertEquals('postgresql', $this->server->getBackendId());

    // Test server backend availability.
    $backend = $this->server->getBackend();
    $this->assertNotNull($backend);

    // Check server status page.
    $this->drupalGet('/admin/config/search/search-api/server/' . $this->server->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('PostgreSQL Test Server');
  }

  /**
   * Tests index creation and configuration.
   */
  public function testIndexCreation()
  {
    // Check that index was created.
    $this->assertNotNull($this->index);
    $this->assertEquals($this->server->id(), $this->index->getServerId());

    // Check index status page.
    $this->drupalGet('/admin/config/search/search-api/index/' . $this->index->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test Index');

    // Verify fields are configured.
    $fields = $this->index->getFields();
    $this->assertArrayHasKey('title', $fields);
    $this->assertArrayHasKey('body', $fields);
    $this->assertArrayHasKey('created', $fields);
    $this->assertArrayHasKey('status', $fields);

    // Check field types.
    $this->assertEquals('text', $fields['title']->getType());
    $this->assertEquals('text', $fields['body']->getType());
    $this->assertEquals('date', $fields['created']->getType());
    $this->assertEquals('boolean', $fields['status']->getType());
  }

  /**
   * Tests content indexing process.
   */
  public function testContentIndexing()
  {
    // Initially, no items should be indexed.
    $this->assertEquals(0, $this->index->getTrackerInstance()->getTotalItemsCount());

    // Index the content.
    $this->index->indexItems();

    // Check that items were indexed.
    $indexed_count = $this->index->getTrackerInstance()->getIndexedItemsCount();
    $this->assertEquals(count($this->testNodes), $indexed_count);

    // Verify index status page shows correct counts.
    $this->drupalGet('/admin/config/search/search-api/index/' . $this->index->id());
    $this->assertSession()->pageTextContains('5 items indexed out of 5 items total');
  }

  /**
   * Tests search functionality through the API.
   */
  public function testSearchFunctionality()
  {
    // Index the content first.
    $this->index->indexItems();

    // Create a query.
    $query = $this->index->query();

    // Test basic text search.
    $query->keys('machine learning');
    $results = $query->execute();

    $this->assertGreaterThan(0, $results->getResultCount());

    // Check that the correct item was found.
    $result_items = $results->getResultItems();
    $found_machine_learning = false;
    foreach ($result_items as $item) {
      $entity = $item->getOriginalObject()->getValue();
      if ($entity && $entity->getTitle() === 'Machine Learning and Artificial Intelligence') {
        $found_machine_learning = true;
        break;
      }
    }
    $this->assertTrue($found_machine_learning, 'Machine learning article was found in search results');
  }

  /**
   * Tests advanced search features.
   */
  public function testAdvancedSearchFeatures()
  {
    // Index the content first.
    $this->index->indexItems();

    // Test phrase search.
    $query = $this->index->query();
    $query->keys('"PostgreSQL database"');
    $results = $query->execute();
    $this->assertGreaterThan(0, $results->getResultCount());

    // Test field-specific search.
    $query = $this->index->query();
    $query->addCondition('title', 'Drupal', '=');
    $results = $query->execute();
    $this->assertGreaterThan(0, $results->getResultCount());

    // Test date range search.
    $query = $this->index->query();
    // Within last hour.
    $query->addCondition('created', time() - 3600, '>');
    $results = $query->execute();
    $this->assertEquals(count($this->testNodes), $results->getResultCount());

    // Test status filter.
    $query = $this->index->query();
    $query->addCondition('status', true);
    $results = $query->execute();
    $this->assertEquals(count($this->testNodes), $results->getResultCount());
  }

  /**
   * Tests search result sorting and paging.
   */
  public function testSearchSortingAndPaging()
  {
    // Index the content first.
    $this->index->indexItems();

    // Test sorting by relevance (default)
    $query = $this->index->query();
    $query->keys('database');
    $query->sort('search_api_relevance', 'DESC');
    $results = $query->execute();
    $this->assertGreaterThan(0, $results->getResultCount());

    // Test sorting by creation date.
    $query = $this->index->query();
    $query->sort('created', 'DESC');
    $results = $query->execute();
    $this->assertEquals(count($this->testNodes), $results->getResultCount());

    // Test paging.
    $query = $this->index->query();
    // First 2 results.
    $query->range(0, 2);
    $results = $query->execute();
    $this->assertEquals(2, count($results->getResultItems()));

    // Test offset.
    $query = $this->index->query();
    // Next 2 results.
    $query->range(2, 2);
    $results = $query->execute();
    $this->assertEquals(2, count($results->getResultItems()));
  }

  /**
   * Tests search with filters and facets.
   */
  public function testSearchFiltering()
  {
    // Index the content first.
    $this->index->indexItems();

    // Test multiple conditions (AND)
    $query = $this->index->query();
    $query->keys('search');
    $query->addCondition('status', true);
    $results = $query->execute();
    $this->assertGreaterThan(0, $results->getResultCount());

    // Test condition groups (OR)
    $query = $this->index->query();
    $condition_group = $query->createConditionGroup('OR');
    $condition_group->addCondition('title', 'Machine Learning', 'CONTAINS');
    $condition_group->addCondition('title', 'PostgreSQL', 'CONTAINS');
    $query->addConditionGroup($condition_group);
    $results = $query->execute();
    $this->assertGreaterThanOrEqual(2, $results->getResultCount());
  }

  /**
   * Tests search highlighting and excerpts.
   */
  public function testSearchHighlighting()
  {
    // Index the content first.
    $this->index->indexItems();

    // Search with highlighting.
    $query = $this->index->query();
    $query->keys('machine learning');
    $query->setOption('search_api_excerpt', true);
    $results = $query->execute();

    $this->assertGreaterThan(0, $results->getResultCount());

    // Check if excerpts are generated.
    $result_items = $results->getResultItems();
    foreach ($result_items as $item) {
      $excerpt = $item->getExcerpt();
      if ($excerpt) {
        $this->assertStringContainsString('machine', strtolower($excerpt));
        break;
      }
    }
  }

  /**
   * Tests error handling and edge cases.
   */
  public function testErrorHandling()
  {
    // Index the content first.
    $this->index->indexItems();

    // Test empty search.
    $query = $this->index->query();
    $query->keys('');
    $results = $query->execute();
    // Should not crash, may return all results or none.
    // Test very long search term.
    $query = $this->index->query();
    $query->keys(str_repeat('verylongterm', 100));
    $results = $query->execute();
    $this->assertEquals(0, $results->getResultCount());

    // Test special characters.
    $query = $this->index->query();
    $query->keys('test@#$%^&*()');
    $results = $query->execute();
    // Should not crash.
    // Test SQL injection attempt.
    $query = $this->index->query();
    $query->keys("'; DROP TABLE search_api_item; --");
    $results = $query->execute();
    // Should not crash and should not execute the SQL.
  }

  /**
   * Tests cache integration.
   */
  public function testCacheIntegration()
  {
    // Index the content first.
    $this->index->indexItems();

    // Perform same search twice to test caching.
    $query1 = $this->index->query();
    $query1->keys('machine learning');
    $results1 = $query1->execute();

    $query2 = $this->index->query();
    $query2->keys('machine learning');
    $results2 = $query2->execute();

    // Results should be identical.
    $this->assertEquals($results1->getResultCount(), $results2->getResultCount());
  }

  /**
   * Tests index maintenance operations.
   */
  public function testIndexMaintenance()
  {
    // Index the content first.
    $this->index->indexItems();
    $initial_count = $this->index->getTrackerInstance()->getIndexedItemsCount();

    // Clear index.
    $this->index->clear();
    $this->assertEquals(0, $this->index->getTrackerInstance()->getIndexedItemsCount());

    // Reindex.
    $this->index->indexItems();
    $final_count = $this->index->getTrackerInstance()->getIndexedItemsCount();
    $this->assertEquals($initial_count, $final_count);

    // Test index rebuild.
    $this->index->rebuildTracker();
    $this->assertEquals($initial_count, $this->index->getTrackerInstance()->getTotalItemsCount());
  }

  /**
   * Tests vector search functionality if available.
   */
  public function testVectorSearchIfAvailable()
  {
    // Check if vector data type is available.
    $data_type_manager = \Drupal::service('plugin.manager.search_api.data_type');
    if (!$data_type_manager->hasDefinition('vector')) {
      $this->markTestSkipped('Vector data type not available');
    }

    // Add vector field to index.
    $vector_field = \Drupal::getContainer()
      ->get('plugin.manager.search_api.data_type')
      ->createInstance('vector');

    $this->index->addField([
      'label' => 'Body Vector',
      'type' => 'vector',
      'datasource_id' => 'entity:node',
      'property_path' => 'body',
    ]);
    $this->index->save();

    // Index with vector field.
    $this->index->indexItems();

    // Test vector search (this would require actual embedding generation)
    $query = $this->index->query();
    $query->keys('artificial intelligence concepts');
    $results = $query->execute();

    // Should find semantically related content.
    $this->assertGreaterThan(0, $results->getResultCount());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void
  {
    // Clean up test data.
    foreach ($this->testNodes as $node) {
      $node->delete();
    }

    if ($this->index) {
      $this->index->delete();
    }

    if ($this->server) {
      $this->server->delete();
    }

    parent::tearDown();
  }
}
