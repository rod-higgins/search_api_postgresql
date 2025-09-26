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
class PostgreSQLBackendUnitTest extends TestCase
{
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
  protected function setUp(): void
  {
    parent::setUp();

    // Load the actual module files.
    require_once __DIR__ . '/../../../../../src/Plugin/search_api/backend/PostgreSQLBackend.php';

    // Create a simple logger.
    $this->logger = new class implements LoggerInterface {

      /**
       * {@inheritdoc}
       */
      public function emergency($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function alert($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function critical($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function error($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function warning($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function notice($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function info($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function debug($message, array $context = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = [])
      {
      }

    };

    // Create logger factory.
    $loggerFactory = new class ($this->logger) implements LoggerChannelFactoryInterface {
      private $logger;

      public function __construct($logger)
      {
        $this->logger = $logger;
      }

      /**
       * {@inheritdoc}
       */
      public function get($channel)
      {
        return $this->logger;
      }

    };

    // Create minimal config factory.
    $configFactory = new class implements ConfigFactoryInterface {

      /**
       * {@inheritdoc}
       */
      public function get($name)
      {
        return new class implements Config {

          /**
           * {@inheritdoc}
           */
          public function get($key = '')
          {
            return [];
          }

          /**
           * {@inheritdoc}
           */
          public function set($key, $value)
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function save($has_trusted_data = false)
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function delete()
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function getName()
          {
            return 'test';
          }

          /**
           * {@inheritdoc}
           */
          public function getCacheTags()
          {
            return [];
          }

          /**
           * {@inheritdoc}
           */
          public function getCacheMaxAge()
          {
            return 0;
          }

          /**
           * {@inheritdoc}
           */
          public function getCacheContexts()
          {
            return [];
          }

          /**
           * {@inheritdoc}
           */
          public function merge(array $data_to_merge)
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function clear($key)
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function setData(array $data)
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function getRawData()
          {
            return [];
          }

          /**
           * {@inheritdoc}
           */
          public function isNew()
          {
            return false;
          }

          /**
           * {@inheritdoc}
           */
          public function getOriginal($key = '', $apply_overrides = true)
          {
            return null;
          }

          /**
           * {@inheritdoc}
           */
          public function setModuleOverride(array $data)
          {
            return $this;
          }

          /**
           * {@inheritdoc}
           */
          public function setSettingsOverride(array $data)
          {
            return $this;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function getEditable($name)
      {
        return $this->get($name);
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheKeys()
      {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function clearStaticCache()
      {
      }

      /**
       * {@inheritdoc}
       */
      public function listAll($prefix = '')
      {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function rename($old_name, $new_name)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function reset($name = null)
      {
        return $this;
      }

    };

    // Create minimal database connection.
    $database = new class implements Connection {

      public function __construct()
      {
      }

      /**
       * {@inheritdoc}
       */
      public function query($query, array $args = [], array $options = [])
      {
        return true;
      }

      /**
       * {@inheritdoc}
       */
      public function select($table, $alias = null, array $options = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function insert($table, array $options = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function update($table, array $options = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function delete($table, array $options = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function merge($table, array $options = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function upsert($table, array $options = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function condition($field, $value = null, $operator = '=')
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function isNull($field)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function isNotNull($field)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function exists($table)
      {
        return true;
      }

      /**
       * {@inheritdoc}
       */
      public function range($start = null, $length = null)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function union($query, $type = '')
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function addExpression($expression, $alias = null, $arguments = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function orderBy($field, $direction = 'ASC')
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function orderRandom()
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function groupBy($field)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function havingCondition($field, $value = null, $operator = '=')
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function fields($table_alias, array $fields = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function addField($table_alias, $field, $alias = null)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function removeField($field)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function getFields()
      {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function hasTag($tag)
      {
        return false;
      }

      /**
       * {@inheritdoc}
       */
      public function hasAllTags()
      {
        return false;
      }

      /**
       * {@inheritdoc}
       */
      public function hasAnyTag()
      {
        return false;
      }

      /**
       * {@inheritdoc}
       */
      public function addTag($tag)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function innerJoin($table, $alias = null, $condition = null)
      {
        return $alias;
      }

      /**
       * {@inheritdoc}
       */
      public function leftJoin($table, $alias = null, $condition = null)
      {
        return $alias;
      }

      /**
       * {@inheritdoc}
       */
      public function rightJoin($table, $alias = null, $condition = null)
      {
        return $alias;
      }

      /**
       * {@inheritdoc}
       */
      public function join($table, $alias = null, $condition = null)
      {
        return $alias;
      }

      /**
       * {@inheritdoc}
       */
      public function where($snippet, $args = [])
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function compile($connection, $queryPlaceholder)
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function compiled()
      {
        return false;
      }

      /**
       * {@inheritdoc}
       */
      public function __toString()
      {
        return '';
      }

      /**
       * {@inheritdoc}
       */
      public function execute()
      {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function getConnectionOptions()
      {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function setConnectionOptions(array $options)
      {
      }

      /**
       * {@inheritdoc}
       */
      public static function open(array &$connection_options = [])
      {
      }

      /**
       * {@inheritdoc}
       */
      public function destroy()
      {
      }

      /**
       * {@inheritdoc}
       */
      public function getTarget()
      {
        return 'default';
      }

      /**
       * {@inheritdoc}
       */
      public function getKey()
      {
        return 'default';
      }

      /**
       * {@inheritdoc}
       */
      public function getLogger()
      {
        return null;
      }

      /**
       * {@inheritdoc}
       */
      public function setLogger($logger)
      {
      }

      /**
       * {@inheritdoc}
       */
      public function schema()
      {
        return null;
      }

      /**
       * {@inheritdoc}
       */
      public function startTransaction($name = '')
      {
        return null;
      }

      /**
       * {@inheritdoc}
       */
      public function rollBack($savepoint_name = '')
      {
      }

      /**
       * {@inheritdoc}
       */
      public function pushTransaction($name)
      {
        return '';
      }

      /**
       * {@inheritdoc}
       */
      public function popTransaction($name)
      {
      }

      /**
       * {@inheritdoc}
       */
      public function inTransaction()
      {
        return false;
      }

      /**
       * {@inheritdoc}
       */
      public function transactionDepth()
      {
        return 0;
      }

      /**
       * {@inheritdoc}
       */
      public function commit()
      {
      }

      /**
       * {@inheritdoc}
       */
      public function supportsTransactions()
      {
        return true;
      }

      /**
       * {@inheritdoc}
       */
      public function supportsTransactionalDDL()
      {
        return true;
      }

      /**
       * {@inheritdoc}
       */
      public function databaseType()
      {
        return 'pgsql';
      }

      /**
       * {@inheritdoc}
       */
      public function version()
      {
        return '13.0';
      }

      /**
       * {@inheritdoc}
       */
      public function isEventSupported($event)
      {
        return false;
      }

      /**
       * {@inheritdoc}
       */
      public function addConnectionOptions(array $options)
      {
      }

      /**
       * {@inheritdoc}
       */
      public function driver()
      {
        return 'pgsql';
      }

      /**
       * {@inheritdoc}
       */
      public function clientVersion()
      {
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
  public function testBackendIdentification()
  {
    $this->assertEquals('postgresql', $this->backend->getPluginId());
    $this->assertNotEmpty($this->backend->label());
  }

  /**
   * Tests supported features.
   *
   * @covers ::getSupportedFeatures
   */
  public function testSupportedFeatures()
  {
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
  public function testSupportedDataTypes()
  {
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
  public function testSupportsDataType()
  {
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
  public function testBuildConfigurationForm()
  {
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
  public function testValidateConfigurationForm()
  {
    $form = [];
    $form_state = $this->createMock('\Drupal\Core\Form\FormStateInterface');

    // Should not throw any exceptions with valid form state.
    $this->backend->validateConfigurationForm($form, $form_state);
    $this->assertTrue(true);
  }

  /**
   * Tests configuration submission.
   *
   * @covers ::submitConfigurationForm
   */
  public function testSubmitConfigurationForm()
  {
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
    $this->assertTrue(true);
  }

  /**
   * Tests server availability check.
   *
   * @covers ::isAvailable
   */
  public function testIsAvailable()
  {
    // Mock successful database connection.
    $this->database->method('query')->willReturn(true);

    $this->assertTrue($this->backend->isAvailable());
  }

  /**
   * Tests getting server description.
   *
   * @covers ::getServerDescription
   */
  public function testGetServerDescription()
  {
    $description = $this->backend->getServerDescription();

    $this->assertIsArray($description);
    $this->assertArrayHasKey('#markup', $description);
  }

  /**
   * Tests viewing settings.
   *
   * @covers ::viewSettings
   */
  public function testViewSettings()
  {
    $settings = $this->backend->viewSettings();

    $this->assertIsArray($settings);
  }

  /**
   * Tests default configuration.
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfiguration()
  {
    $config = $this->backend->defaultConfiguration();

    $this->assertIsArray($config);
    $this->assertArrayHasKey('database', $config);
  }
}
