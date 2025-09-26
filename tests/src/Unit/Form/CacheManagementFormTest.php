<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Form;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Form\CacheManagementForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api_postgresql\Cache\EmbeddingCacheInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests for the Cache Management form.
 *
 * @group              search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Form\CacheManagementForm
 */
class CacheManagementFormTest extends UnitTestCase
{
  /**
   * The form under test.
   *
   * @var \Drupal\search_api_postgresql\Form\CacheManagementForm
   */
  protected $form;

  /**
   * Mock cache service.
   *
   * @var \Drupal\search_api_postgresql\Cache\EmbeddingCacheInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cache;

  /**
   * Mock messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->cache = $this->createMock(EmbeddingCacheInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->form = new CacheManagementForm($this->cache, $this->messenger);

    // Mock string translation.
    $stringTranslation = $this->createMock(TranslationInterface::class);
    $stringTranslation->method('translateString')->willReturnArgument(0);
    $this->form->setStringTranslation($stringTranslation);
  }

  /**
   * Tests form ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId()
  {
    $formId = $this->form->getFormId();
    $this->assertEquals('search_api_postgresql_cache_management', $formId);
  }

  /**
   * Tests form building.
   *
   * @covers ::buildForm
   */
  public function testBuildForm()
  {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    // Mock cache statistics.
    $this->cache->method('getStats')->willReturn([
      'total_entries' => 500,
      'hits' => 1000,
      'misses' => 200,
      'cache_size' => '50MB',
      'hit_rate' => 83.3,
    ]);

    $result = $this->form->buildForm($form, $form_state);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('cache_stats', $result);
    $this->assertArrayHasKey('actions', $result);
    $this->assertArrayHasKey('clear_cache', $result['actions']);
    $this->assertArrayHasKey('run_maintenance', $result['actions']);
  }

  /**
   * Tests cache statistics display.
   *
   * @covers ::buildForm
   */
  public function testCacheStatisticsDisplay()
  {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $mockStats = [
      'total_entries' => 1500,
      'hits' => 5000,
      'misses' => 500,
      'cache_size' => '150MB',
      'hit_rate' => 90.9,
      'oldest_entry' => time() - 86400,
      'newest_entry' => time(),
    ];

    $this->cache->method('getStats')->willReturn($mockStats);

    $result = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('cache_stats', $result);
    $this->assertArrayHasKey('#markup', $result['cache_stats']);
    $this->assertStringContainsString('1500', $result['cache_stats']['#markup']);
    $this->assertStringContainsString('90.9%', $result['cache_stats']['#markup']);
  }

  /**
   * Tests clear cache form submission.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormClearCache()
  {
    $form = [
      'actions' => [
        'clear_cache' => ['#name' => 'clear_cache'],
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);

    // Mock triggering element.
    $form_state->method('getTriggeringElement')->willReturn([
      '#name' => 'clear_cache',
    ]);

    // Mock successful cache clear.
    $this->cache->method('clear')->willReturn(true);

    // Expect success message.
    $this->messenger->expects($this->once())
      ->method('addMessage')
      ->with($this->stringContains('Cache cleared successfully'));

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Tests run maintenance form submission.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormRunMaintenance()
  {
    $form = [
      'actions' => [
        'run_maintenance' => ['#name' => 'run_maintenance'],
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);

    // Mock triggering element.
    $form_state->method('getTriggeringElement')->willReturn([
      '#name' => 'run_maintenance',
    ]);

    // Mock successful maintenance.
    $this->cache->method('maintenance')->willReturn(true);

    // Expect success message.
    $this->messenger->expects($this->once())
      ->method('addMessage')
      ->with($this->stringContains('Maintenance completed'));

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Tests form submission with cache clear failure.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormClearCacheFailure()
  {
    $form = [
      'actions' => [
        'clear_cache' => ['#name' => 'clear_cache'],
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);

    $form_state->method('getTriggeringElement')->willReturn([
      '#name' => 'clear_cache',
    ]);

    // Mock cache clear failure.
    $this->cache->method('clear')->willReturn(false);

    // Expect error message.
    $this->messenger->expects($this->once())
      ->method('addError')
      ->with($this->stringContains('Failed to clear cache'));

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Tests form submission with maintenance failure.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormMaintenanceFailure()
  {
    $form = [
      'actions' => [
        'run_maintenance' => ['#name' => 'run_maintenance'],
      ],
    ];
    $form_state = $this->createMock(FormStateInterface::class);

    $form_state->method('getTriggeringElement')->willReturn([
      '#name' => 'run_maintenance',
    ]);

    // Mock maintenance failure.
    $this->cache->method('maintenance')->willReturn(false);

    // Expect error message.
    $this->messenger->expects($this->once())
      ->method('addError')
      ->with($this->stringContains('Maintenance failed'));

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Tests cache statistics formatting.
   *
   * @covers ::formatCacheStats
   */
  public function testFormatCacheStats()
  {
    $stats = [
      'total_entries' => 2500,
      'hits' => 10000,
      'misses' => 1000,
      'cache_size' => '250MB',
      'hit_rate' => 90.9,
    ];

    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('formatCacheStats');
    $method->setAccessible(true);

    $result = $method->invokeArgs($this->form, [$stats]);

    $this->assertIsString($result);
    $this->assertStringContainsString('2,500', $result);
    $this->assertStringContainsString('90.9%', $result);
    $this->assertStringContainsString('250MB', $result);
  }

  /**
   * Tests cache size formatting.
   *
   * @covers ::formatCacheSize
   */
  public function testFormatCacheSize()
  {
    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('formatCacheSize');
    $method->setAccessible(true);

    // Test bytes.
    $this->assertEquals('1023 B', $method->invokeArgs($this->form, [1023]));

    // Test kilobytes.
    $this->assertEquals('1.0 KB', $method->invokeArgs($this->form, [1024]));

    // Test megabytes.
    $this->assertEquals('1.0 MB', $method->invokeArgs($this->form, [1024 * 1024]));

    // Test gigabytes.
    $this->assertEquals('1.0 GB', $method->invokeArgs($this->form, [1024 * 1024 * 1024]));
  }

  /**
   * Tests container creation.
   *
   * @covers ::create
   */
  public function testCreateFromContainer()
  {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      ['search_api_postgresql.embedding_cache', $this->cache],
      ['messenger', $this->messenger],
    ]);

    $form = CacheManagementForm::create($container);
    $this->assertInstanceOf(CacheManagementForm::class, $form);
  }
}
