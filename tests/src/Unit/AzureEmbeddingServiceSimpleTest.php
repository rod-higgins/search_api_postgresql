<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService;

/**
 * Simple tests for Azure OpenAI Embedding Service.
 *
 * @group              search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService
 */
class AzureEmbeddingServiceSimpleTest extends UnitTestCase
{

  /**
   * Test service instantiation and basic methods.
   */
  public function testServiceInstantiation()
  {
    $service = new AzureOpenAIEmbeddingService(
        'https://test.openai.azure.com',
        'test-key',
        'test-deployment'
    );

    $this->assertInstanceOf(AzureOpenAIEmbeddingService::class, $service);
    $this->assertEquals(1536, $service->getDimension());
    $this->assertTrue($service->isAvailable());
  }

  /**
   * Test service with missing parameters.
   */
  public function testServiceUnavailableWithMissingParams()
  {
    $service = new AzureOpenAIEmbeddingService('', '', '');
    $this->assertFalse($service->isAvailable());
  }

  /**
   * Test cache statistics when no cache manager.
   */
  public function testCacheStatsWithoutManager()
  {
    $service = new AzureOpenAIEmbeddingService(
        'https://test.openai.azure.com',
        'test-key',
        'test-deployment'
    );

    $stats = $service->getCacheStats();
    $this->assertArrayHasKey('cache_enabled', $stats);
    $this->assertFalse($stats['cache_enabled']);
  }

  /**
   * Test cache invalidation when no cache manager.
   */
  public function testCacheInvalidateWithoutManager()
  {
    $service = new AzureOpenAIEmbeddingService(
        'https://test.openai.azure.com',
        'test-key',
        'test-deployment'
    );

    $result = $service->invalidateCache();
    $this->assertFalse($result);
  }
}
