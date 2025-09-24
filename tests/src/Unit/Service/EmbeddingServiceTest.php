<?php

namespace Drupal\Tests\search_api_postgresql\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Real implementation tests for the embedding service.
 *
 * @group search_api_postgresql
 */
class EmbeddingServiceTest extends TestCase {
  /**
   * The embedding service under test.
   *
   * @var \Drupal\search_api_postgresql\Service\AzureEmbeddingService
   */
  protected $embeddingService;

  /**
   * Real logger implementation.
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->cache = $this->createMock(EmbeddingCacheInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->configFactory->method('get')->willReturn($this->config);

    $this->config->method('get')->willReturnMap([
      ['endpoint', 'https://test.openai.azure.com/'],
      ['api_key', 'test_api_key'],
      ['deployment_name', 'test_deployment'],
      ['api_version', '2024-02-01'],
      ['timeout', 30],
      ['max_retries', 3],
    ]);

    $this->embeddingService = new AzureOpenAIEmbeddingService(
          $this->httpClient,
          $this->configFactory,
          $this->cache,
          $this->logger
      );
  }

  /**
   * Tests successful single embedding generation.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingSuccess() {
    $text = 'This is a test text for embedding generation.';
    $expectedEmbedding = array_fill(0, 1536, 0.1);

    // Mock cache miss.
    $this->cache->method('get')->willReturn(NULL);

    // Mock successful API response.
    $responseBody = json_encode([
      'data' => [
      [
        'embedding' => $expectedEmbedding,
        'index' => 0,
      ],
      ],
      'usage' => [
        'prompt_tokens' => 10,
        'total_tokens' => 10,
      ],
    ]);

    $response = new Response(200, ['Content-Type' => 'application/json'], $responseBody);
    $this->httpClient->method('request')->willReturn($response);

    // Mock cache set.
    $this->cache->expects($this->once())->method('set');

    $result = $this->embeddingService->generateEmbedding($text);

    $this->assertIsArray($result);
    $this->assertCount(1536, $result);
    $this->assertEquals($expectedEmbedding, $result);
  }

  /**
   * Tests embedding generation with cache hit.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingCacheHit() {
    $text = 'Cached text';
    $cachedEmbedding = array_fill(0, 1536, 0.2);

    // Mock cache hit.
    $this->cache->method('get')->willReturn($cachedEmbedding);

    // HTTP client should not be called.
    $this->httpClient->expects($this->never())->method('request');

    $result = $this->embeddingService->generateEmbedding($text);

    $this->assertEquals($cachedEmbedding, $result);
  }

  /**
   * Tests batch embedding generation.
   *
   * @covers ::generateBatchEmbeddings
   */
  public function testGenerateBatchEmbeddings() {
    $texts = [
      'First text for embedding',
      'Second text for embedding',
      'Third text for embedding',
    ];

    $expectedEmbeddings = [
      array_fill(0, 1536, 0.1),
      array_fill(0, 1536, 0.2),
      array_fill(0, 1536, 0.3),
    ];

    // Mock cache misses.
    $this->cache->method('getMultiple')->willReturn([]);

    // Mock successful API response.
    $responseBody = json_encode([
      'data' => [
      ['embedding' => $expectedEmbeddings[0], 'index' => 0],
      ['embedding' => $expectedEmbeddings[1], 'index' => 1],
      ['embedding' => $expectedEmbeddings[2], 'index' => 2],
      ],
      'usage' => [
        'prompt_tokens' => 30,
        'total_tokens' => 30,
      ],
    ]);

    $response = new Response(200, ['Content-Type' => 'application/json'], $responseBody);
    $this->httpClient->method('request')->willReturn($response);

    // Mock cache set.
    $this->cache->expects($this->once())->method('setMultiple');

    $result = $this->embeddingService->generateBatchEmbeddings($texts);

    $this->assertIsArray($result);
    $this->assertCount(3, $result);
    $this->assertEquals($expectedEmbeddings, array_values($result));
  }

  /**
   * Tests API error handling.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingApiError() {
    $text = 'Text that will cause API error';

    // Mock cache miss.
    $this->cache->method('get')->willReturn(NULL);

    // Mock API error response.
    $response = new Response(400, [], '{"error": {"message": "Invalid request"}}');
    $this->httpClient->method('request')->willReturn($response);

    $this->expectException(\Exception::class);
    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests rate limiting handling.
   *
   * @covers ::generateEmbedding
   */
  public function testGenerateEmbeddingRateLimit() {
    $text = 'Text that will hit rate limit';

    // Mock cache miss.
    $this->cache->method('get')->willReturn(NULL);

    // Mock rate limit response.
    $response = new Response(429, ['Retry-After' => '60'], '{"error": {"message": "Rate limit exceeded"}}');
    $this->httpClient->method('request')->willReturn($response);

    $this->expectException(\Exception::class);
    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests text preprocessing.
   *
   * @covers ::preprocessText
   */
  public function testPreprocessText() {
    $text = "  This is text with   multiple   spaces\n\nand newlines.  ";
    $processed = $this->embeddingService->preprocessText($text);

    $this->assertIsString($processed);
    $this->assertStringNotContainsString('  ', $processed);
    $this->assertStringNotContainsString("\n\n", $processed);
    $this->assertEquals(trim($text), trim($processed));
  }

  /**
   * Tests text chunking for large inputs.
   *
   * @covers ::chunkText
   */
  public function testChunkText() {
    $longText = str_repeat('This is a very long text that needs to be chunked. ', 100);
    $chunks = $this->embeddingService->chunkText($longText, 500);

    $this->assertIsArray($chunks);
    $this->assertGreaterThan(1, count($chunks));

    foreach ($chunks as $chunk) {
      $this->assertLessThanOrEqual(500, strlen($chunk));
    }
  }

  /**
   * Tests embedding validation.
   *
   * @covers ::validateEmbedding
   */
  public function testValidateEmbedding() {
    // Valid embedding.
    $validEmbedding = array_fill(0, 1536, 0.5);
    $this->assertTrue($this->embeddingService->validateEmbedding($validEmbedding));

    // Invalid embedding - wrong dimensions.
    $invalidEmbedding = array_fill(0, 100, 0.5);
    $this->assertFalse($this->embeddingService->validateEmbedding($invalidEmbedding));

    // Invalid embedding - non-numeric values.
    $invalidEmbedding = array_fill(0, 1536, 'not_a_number');
    $this->assertFalse($this->embeddingService->validateEmbedding($invalidEmbedding));
  }

  /**
   * Tests service availability check.
   *
   * @covers ::isServiceAvailable
   */
  public function testIsServiceAvailable() {
    // Mock successful health check.
    $response = new Response(200, [], '{"status": "ok"}');
    $this->httpClient->method('request')->willReturn($response);

    $this->assertTrue($this->embeddingService->isServiceAvailable());
  }

  /**
   * Tests usage statistics tracking.
   *
   * @covers ::getUsageStatistics
   */
  public function testGetUsageStatistics() {
    $stats = $this->embeddingService->getUsageStatistics();

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('total_requests', $stats);
    $this->assertArrayHasKey('total_tokens', $stats);
    $this->assertArrayHasKey('cache_hits', $stats);
    $this->assertArrayHasKey('cache_misses', $stats);
  }

}
