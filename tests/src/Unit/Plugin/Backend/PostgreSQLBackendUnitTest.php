<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Plugin\Backend;

use Drupal\search_api_postgresql\Plugin\search_api\backend\PostgreSQLBackend;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for the PostgreSQL backend plugin.
 *
 * @group search_api_postgresql
 */
class PostgreSQLBackendUnitTest extends TestCase {
  /**
   * The PostgreSQL backend plugin under test.
   *
   * @var \Drupal\search_api_postgresql\Plugin\search_api\backend\PostgreSQLBackend
   */
  protected $backend;

  /**
   * Simple logger implementation for testing.
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Load the actual module files.
    require_once __DIR__ . '/../../../../../src/Plugin/search_api/backend/PostgreSQLBackend.php';

    // Create a simple logger.
    $this->logger = new class implements LoggerInterface {

      /**
       *
       */
      public function emergency($message, array $context = []) {
      }

      /**
       *
       */
      public function alert($message, array $context = []) {
      }

      /**
       *
       */
      public function critical($message, array $context = []) {
      }

      /**
       *
       */
      public function error($message, array $context = []) {
      }

      /**
       *
       */
      public function warning($message, array $context = []) {
      }

      /**
       *
       */
      public function notice($message, array $context = []) {
      }

      /**
       *
       */
      public function info($message, array $context = []) {
      }

      /**
       *
       */
      public function debug($message, array $context = []) {
      }

      /**
       *
       */
      public function log($level, $message, array $context = []) {
      }

    };

    // Create logger factory.
    $loggerFactory = new class ($this->logger) implements LoggerChannelFactoryInterface {
      private $logger;

      public function __construct($logger) {
        $this->logger = $logger;
      }

      /**
       *
       */
      public function get($channel) {
        return $this->logger;
      }

    };

    // Create minimal config factory.
    $configFactory = new class implements ConfigFactoryInterface {

      /**
       *
       */
      public function get($name) {
        return new class implements Config {

          /**
           *
           */
          public function get($key = '') {
            return [];
          }

          /**
           *
           */
          public function set($key, $value) {
            return $this;
          }

          /**
           *
           */
          public function save($has_trusted_data = FALSE) {
            return $this;
          }

          /**
           *
           */
          public function delete() {
            return $this;
          }

          /**
           *
           */
          public function getName() {
            return 'test';
          }

          /**
           *
           */
          public function getCacheTags() {
            return [];
          }

          /**
           *
           */
          public function getCacheMaxAge() {
            return 0;
          }

          /**
           *
           */
          public function getCacheContexts() {
            return [];
          }

          /**
           *
           */
          public function merge(array $data_to_merge) {
            return $this;
          }

          /**
           *
           */
          public function clear($key) {
            return $this;
          }

          /**
           *
           */
          public function setData(array $data) {
            return $this;
          }

          /**
           *
           */
          public function getRawData() {
            return [];
          }

          /**
           *
           */
          public function isNew() {
            return FALSE;
          }

          /**
           *
           */
          public function getOriginal($key = '', $apply_overrides = TRUE) {
            return NULL;
          }

          /**
           *
           */
          public function setModuleOverride(array $data) {
            return $this;
          }

          /**
           *
           */
          public function setSettingsOverride(array $data) {
            return $this;
          }

        };
      }

      /**
       *
       */
      public function getEditable($name) {
        return $this->get($name);
      }

      /**
       *
       */
      public function getCacheKeys() {
        return [];
      }

      /**
       *
       */
      public function clearStaticCache() {
      }

      /**
       *
       */
      public function listAll($prefix = '') {
        return [];
      }

      /**
       *
       */
      public function rename($old_name, $new_name) {
        return $this;
      }

      /**
       *
       */
      public function reset($name = NULL) {
        return $this;
      }

    };

    // Create minimal database connection.
    $database = new class implements Connection {

      public function __construct() {
      }

      /**
       *
       */
      public function query($query, array $args = [], array $options = []) {
        return TRUE;
      }

      /**
       *
       */
      public function select($table, $alias = NULL, array $options = []) {
        return $this;
      }

      /**
       *
       */
      public function insert($table, array $options = []) {
        return $this;
      }

      /**
       *
       */
      public function update($table, array $options = []) {
        return $this;
      }

      /**
       *
       */
      public function delete($table, array $options = []) {
        return $this;
      }

      /**
       *
       */
      public function merge($table, array $options = []) {
        return $this;
      }

      /**
       *
       */
      public function upsert($table, array $options = []) {
        return $this;
      }

      /**
       *
       */
      public function condition($field, $value = NULL, $operator = '=') {
        return $this;
      }

      /**
       *
       */
      public function isNull($field) {
        return $this;
      }

      /**
       *
       */
      public function isNotNull($field) {
        return $this;
      }

      /**
       *
       */
      public function exists($table) {
        return TRUE;
      }

      /**
       *
       */
      public function range($start = NULL, $length = NULL) {
        return $this;
      }

      /**
       *
       */
      public function union($query, $type = '') {
        return $this;
      }

      /**
       *
       */
      public function addExpression($expression, $alias = NULL, $arguments = []) {
        return $this;
      }

      /**
       *
       */
      public function orderBy($field, $direction = 'ASC') {
        return $this;
      }

      /**
       *
       */
      public function orderRandom() {
        return $this;
      }

      /**
       *
       */
      public function groupBy($field) {
        return $this;
      }

      /**
       *
       */
      public function havingCondition($field, $value = NULL, $operator = '=') {
        return $this;
      }

      /**
       *
       */
      public function fields($table_alias, array $fields = []) {
        return $this;
      }

      /**
       *
       */
      public function addField($table_alias, $field, $alias = NULL) {
        return $this;
      }

      /**
       *
       */
      public function removeField($field) {
        return $this;
      }

      /**
       *
       */
      public function getFields() {
        return [];
      }

      /**
       *
       */
      public function hasTag($tag) {
        return FALSE;
      }

      /**
       *
       */
      public function hasAllTags() {
        return FALSE;
      }

      /**
       *
       */
      public function hasAnyTag() {
        return FALSE;
      }

      /**
       *
       */
      public function addTag($tag) {
        return $this;
      }

      /**
       *
       */
      public function innerJoin($table, $alias = NULL, $condition = NULL) {
        return $alias;
      }

      /**
       *
       */
      public function leftJoin($table, $alias = NULL, $condition = NULL) {
        return $alias;
      }

      /**
       *
       */
      public function rightJoin($table, $alias = NULL, $condition = NULL) {
        return $alias;
      }

      /**
       *
       */
      public function join($table, $alias = NULL, $condition = NULL) {
        return $alias;
      }

      /**
       *
       */
      public function where($snippet, $args = []) {
        return $this;
      }

      /**
       *
       */
      public function compile($connection, $queryPlaceholder) {
        return $this;
      }

      /**
       *
       */
      public function compiled() {
        return FALSE;
      }

      /**
       *
       */
      public function __toString() {
        return '';
      }

      /**
       *
       */
      public function execute() {
        return $this;
      }

      /**
       *
       */
      public function getConnectionOptions() {
        return [];
      }

      /**
       *
       */
      public function setConnectionOptions(array $options) {
      }

      /**
       *
       */
      public static function open(array &$connection_options = []) {
      }

      /**
       *
       */
      public function destroy() {
      }

      /**
       *
       */
      public function getTarget() {
        return 'default';
      }

      /**
       *
       */
      public function getKey() {
        return 'default';
      }

      /**
       *
       */
      public function getLogger() {
        return NULL;
      }

      /**
       *
       */
      public function setLogger($logger) {
      }

      /**
       *
       */
      public function schema() {
        return NULL;
      }

      /**
       *
       */
      public function startTransaction($name = '') {
        return NULL;
      }

      /**
       *
       */
      public function rollBack($savepoint_name = '') {
      }

      /**
       *
       */
      public function pushTransaction($name) {
        return '';
      }

      /**
       *
       */
      public function popTransaction($name) {
      }

      /**
       *
       */
      public function inTransaction() {
        return FALSE;
      }

      /**
       *
       */
      public function transactionDepth() {
        return 0;
      }

      /**
       *
       */
      public function commit() {
      }

      /**
       *
       */
      public function supportsTransactions() {
        return TRUE;
      }

      /**
       *
       */
      public function supportsTransactionalDDL() {
        return TRUE;
      }

      /**
       *
       */
      public function databaseType() {
        return 'pgsql';
      }

      /**
       *
       */
      public function version() {
        return '13.0';
      }

      /**
       *
       */
      public function isEventSupported($event) {
        return FALSE;
      }

      /**
       *
       */
      public function addConnectionOptions(array $options) {
      }

      /**
       *
       */
      public function driver() {
        return 'pgsql';
      }

      /**
       *
       */
      public function clientVersion() {
        return '13.0';
      }

    };

    $configuration = [
      'database' => [
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
      ],
    ];
    $plugin_id = 'postgresql';
    $plugin_definition = [
      'id' => 'postgresql',
      'label' => 'PostgreSQL',
      'description' => 'PostgreSQL backend for Search API',
    ];

    $this->backend = new PostgreSQLBackend(
          $configuration,
          $plugin_id,
          $plugin_definition,
          $database,
          $configFactory,
          $loggerFactory
      );
  }

  /**
   * Tests backend plugin ID and label.
   *
   * @covers ::getPluginId
   * @covers ::label
   */
  public function testBackendIdentification() {
    $this->assertEquals('postgresql', $this->backend->getPluginId());
    $this->assertNotEmpty($this->backend->label());
  }

  /**
   * Tests supported features.
   *
   * @covers ::getSupportedFeatures
   */
  public function testSupportedFeatures() {
    $features = $this->backend->getSupportedFeatures();

    $this->assertIsArray($features);
    $this->assertContains('search_api_facets', $features);
    $this->assertContains('search_api_autocomplete', $features);
  }

  /**
   * Tests supported data types.
   *
   * @covers ::getSupportedDataTypes
   */
  public function testSupportedDataTypes() {
    $dataTypes = $this->backend->getSupportedDataTypes();

    $this->assertIsArray($dataTypes);
    $this->assertContains('text', $dataTypes);
    $this->assertContains('string', $dataTypes);
    $this->assertContains('integer', $dataTypes);
    $this->assertContains('decimal', $dataTypes);
    $this->assertContains('date', $dataTypes);
    $this->assertContains('boolean', $dataTypes);
  }

  /**
   * Tests data type support checking.
   *
   * @covers ::supportsDataType
   */
  public function testSupportsDataType() {
    $this->assertTrue($this->backend->supportsDataType('text'));
    $this->assertTrue($this->backend->supportsDataType('string'));
    $this->assertTrue($this->backend->supportsDataType('integer'));
    $this->assertFalse($this->backend->supportsDataType('unsupported_type'));
  }

  /**
   * Tests configuration form structure.
   *
   * @covers ::buildConfigurationForm
   */
  public function testBuildConfigurationForm() {
    $form = [];
    $form_state = $this->createMock('\Drupal\Core\Form\FormStateInterface');

    $result = $this->backend->buildConfigurationForm($form, $form_state);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('#type', $result);
  }

  /**
   * Tests configuration validation.
   *
   * @covers ::validateConfigurationForm
   */
  public function testValidateConfigurationForm() {
    $form = [];
    $form_state = $this->createMock('\Drupal\Core\Form\FormStateInterface');

    // Should not throw any exceptions with valid form state.
    $this->backend->validateConfigurationForm($form, $form_state);
    $this->assertTrue(TRUE);
  }

  /**
   * Tests configuration submission.
   *
   * @covers ::submitConfigurationForm
   */
  public function testSubmitConfigurationForm() {
    $form = [];
    $form_state = $this->createMock('\Drupal\Core\Form\FormStateInterface');
    $form_state->method('getValues')->willReturn([
      'database' => [
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
      ],
    ]);

    // Should not throw any exceptions.
    $this->backend->submitConfigurationForm($form, $form_state);
    $this->assertTrue(TRUE);
  }

  /**
   * Tests server availability check.
   *
   * @covers ::isAvailable
   */
  public function testIsAvailable() {
    // Mock successful database connection.
    $this->database->method('query')->willReturn(TRUE);

    $this->assertTrue($this->backend->isAvailable());
  }

  /**
   * Tests getting server description.
   *
   * @covers ::getServerDescription
   */
  public function testGetServerDescription() {
    $description = $this->backend->getServerDescription();

    $this->assertIsArray($description);
    $this->assertArrayHasKey('#markup', $description);
  }

  /**
   * Tests viewing settings.
   *
   * @covers ::viewSettings
   */
  public function testViewSettings() {
    $settings = $this->backend->viewSettings();

    $this->assertIsArray($settings);
  }

  /**
   * Tests default configuration.
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfiguration() {
    $config = $this->backend->defaultConfiguration();

    $this->assertIsArray($config);
    $this->assertArrayHasKey('database', $config);
  }

}
