<?php

namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService;
use Drupal\search_api_postgresql\Exception\EmbeddingServiceUnavailableException;
use Drupal\search_api_postgresql\Exception\RateLimitException;
use Drupal\search_api_postgresql\Exception\ApiKeyExpiredException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Tests the Azure OpenAI Embedding Service.
 *
 * @group search_api_postgresql
 * @coversDefaultClass \Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService
 */
class AzureEmbeddingServiceTest extends UnitTestCase {
  /**
   * The HTTP client mock.
   *
   * @var \GuzzleHttp\Client|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The logger mock.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The embedding service under test.
   *
   * @var \Drupal\search_api_postgresql\Service\AzureOpenAIEmbeddingService
   */
  protected $embeddingService;

  /**
   * Test configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(Client::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->config = [
      'endpoint' => 'https://test-resource.openai.azure.com/',
      'api_key' => 'test-api-key',
      'deployment_name' => 'text-embedding-ada-002',
      'api_version' => '2023-05-15',
      'max_batch_size' => 16,
      'timeout' => 30,
      'retry_attempts' => 3,
      'retry_delay' => 1,
    ];

    $this->embeddingService = $this->getMockBuilder(AzureOpenAIEmbeddingService::class)
      ->setConstructorArgs([
        $this->config['endpoint'],
        $this->config['api_key'],
        $this->config['deployment_name'],
        $this->config,
        $this->httpClient,
        $this->logger,
      ])
      ->onlyMethods(['makeHttpRequest'])
      ->getMock();
  }

  /**
   * Tests service availability check.
   *
   * @covers ::isAvailable
   */
  public function testServiceAvailability() {
    // Test with valid configuration.
    $this->assertTrue($this->embeddingService->isAvailable());

    // Test with missing endpoint.
    $service_no_endpoint = new AzureOpenAIEmbeddingService('', $this->config['api_key'], $this->config['deployment_name']);
    $this->assertFalse($service_no_endpoint->isAvailable());

    // Test with missing API key.
    $service_no_key = new AzureOpenAIEmbeddingService($this->config['endpoint'], '', $this->config['deployment_name']);
    $this->assertFalse($service_no_key->isAvailable());

    // Test with missing deployment name.
    $service_no_deployment = new AzureOpenAIEmbeddingService($this->config['endpoint'], $this->config['api_key'], '');
    $this->assertFalse($service_no_deployment->isAvailable());
  }

  /**
   * Tests successful single embedding generation.
   *
   * @covers ::generateEmbedding
   */
  public function testSuccessfulSingleEmbeddingGeneration() {
    $text = 'This is a test document for embedding generation.';
    // Mock 1536-dimensional embedding.
    $expected_embedding = array_fill(0, 1536, 0.1);

    $mock_response = [
      'data' => [
      [
        'object' => 'embedding',
        'index' => 0,
        'embedding' => $expected_embedding,
      ],
      ],
      'model' => 'text-embedding-ada-002',
      'usage' => [
        'prompt_tokens' => 10,
        'total_tokens' => 10,
      ],
    ];

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willReturn($mock_response);

    $result = $this->embeddingService->generateEmbedding($text);

    $this->assertEquals($expected_embedding, $result);
  }

  /**
   * Tests successful batch embedding generation.
   *
   * @covers ::generateEmbeddings
   */
  public function testSuccessfulBatchEmbeddingGeneration() {
    $texts = [
      'First test document.',
      'Second test document.',
      'Third test document.',
    ];

    $expected_embeddings = [
      array_fill(0, 1536, 0.1),
      array_fill(0, 1536, 0.2),
      array_fill(0, 1536, 0.3),
    ];

    $mock_response = [
      'data' => [
      ['object' => 'embedding', 'index' => 0, 'embedding' => $expected_embeddings[0]],
      ['object' => 'embedding', 'index' => 1, 'embedding' => $expected_embeddings[1]],
      ['object' => 'embedding', 'index' => 2, 'embedding' => $expected_embeddings[2]],
      ],
      'model' => 'text-embedding-ada-002',
      'usage' => [
        'prompt_tokens' => 30,
        'total_tokens' => 30,
      ],
    ];

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willReturn($mock_response);

    $result = $this->embeddingService->generateEmbeddings($texts);

    $this->assertCount(3, $result);
    $this->assertEquals($expected_embeddings[0], $result[0]);
    $this->assertEquals($expected_embeddings[1], $result[1]);
    $this->assertEquals($expected_embeddings[2], $result[2]);
  }

  /**
   * Tests batch size limiting.
   *
   * @covers ::generateEmbeddings
   */
  public function testBatchSizeLimiting() {
    // Create more texts than max batch size.
    $texts = array_fill(0, 20, 'Test document');

    $mock_response = [
      'data' => array_map(function ($i) {
          return [
            'object' => 'embedding',
            'index' => $i,
            'embedding' => array_fill(0, 1536, 0.1),
          ];
          // Only first 16 (max batch size)
      }, range(0, 15)),
      'model' => 'text-embedding-ada-002',
      'usage' => ['prompt_tokens' => 160, 'total_tokens' => 160],
    ];

    // Should make multiple requests due to batch size limit.
    $this->embeddingService->expects($this->exactly(2))
      ->method('makeHttpRequest')
      ->willReturn($mock_response);

    $result = $this->embeddingService->generateEmbeddings($texts);

    $this->assertCount(20, $result);
  }

  /**
   * Tests API key expiration handling.
   *
   * @covers ::generateEmbedding
   */
  public function testApiKeyExpirationHandling() {
    $text = 'Test text';

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willThrowException(new RequestException(
          'Unauthorized',
          new Request('POST', 'test'),
          new Response(401, [], '{"error": {"code": "invalid_api_key", "message": "Invalid API key"}}')
          ));

    $this->expectException(ApiKeyExpiredException::class);
    $this->expectExceptionMessage('API key expired for service: Azure OpenAI');

    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests rate limiting handling.
   *
   * @covers ::generateEmbedding
   */
  public function testRateLimitingHandling() {
    $text = 'Test text';

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willThrowException(new RequestException(
          'Too Many Requests',
          new Request('POST', 'test'),
          new Response(429, ['Retry-After' => '60'], '{"error": {"code": "rate_limit_exceeded"}}')
          ));

    $this->expectException(RateLimitException::class);

    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests service unavailable handling.
   *
   * @covers ::generateEmbedding
   */
  public function testServiceUnavailableHandling() {
    $text = 'Test text';

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willThrowException(new RequestException(
          'Service Unavailable',
          new Request('POST', 'test'),
          new Response(503)
          ));

    $this->expectException(EmbeddingServiceUnavailableException::class);

    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests text preprocessing functionality.
   *
   * @covers ::preprocessText
   */
  public function testTextPreprocessing() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->embeddingService);
    $method = $reflection->getMethod('preprocessText');
    $method->setAccessible(TRUE);

    // Test whitespace normalization.
    $input = "This  has   multiple    spaces\n\nand\tlines";
    $expected = "This has multiple spaces and lines";
    $result = $method->invokeArgs($this->embeddingService, [$input]);
    $this->assertEquals($expected, $result);

    // Test length limiting.
    $long_text = str_repeat('a', 9000);
    $result = $method->invokeArgs($this->embeddingService, [$long_text]);
    $this->assertLessThanOrEqual(8000, strlen($result));

    // Test HTML tag removal.
    $html_text = '<p>This has <strong>HTML</strong> tags</p>';
    $expected = 'This has HTML tags';
    $result = $method->invokeArgs($this->embeddingService, [$html_text]);
    $this->assertEquals($expected, $result);

    // Test empty text handling.
    $result = $method->invokeArgs($this->embeddingService, ['']);
    $this->assertEquals('', $result);

    // Test special character handling.
    $special_text = 'Text with émojis [ROCKET] and spëcial chars';
    $result = $method->invokeArgs($this->embeddingService, [$special_text]);
    $this->assertStringContainsString('émojis', $result);
    $this->assertStringContainsString('[ROCKET]', $result);
  }

  /**
   * Tests request payload formatting.
   *
   * @covers ::formatRequestPayload
   */
  public function testRequestPayloadFormatting() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->embeddingService);
    $method = $reflection->getMethod('formatRequestPayload');
    $method->setAccessible(TRUE);

    // Test single text.
    $texts = ['Test document'];
    $payload = $method->invokeArgs($this->embeddingService, [$texts]);

    $this->assertIsArray($payload);
    $this->assertArrayHasKey('input', $payload);
    $this->assertArrayHasKey('model', $payload);
    $this->assertEquals(['Test document'], $payload['input']);
    $this->assertEquals('text-embedding-ada-002', $payload['model']);

    // Test multiple texts.
    $texts = ['First document', 'Second document'];
    $payload = $method->invokeArgs($this->embeddingService, [$texts]);

    $this->assertEquals(['First document', 'Second document'], $payload['input']);

    // Test empty input handling.
    $this->expectException(\InvalidArgumentException::class);
    $method->invokeArgs($this->embeddingService, [[]]);
  }

  /**
   * Tests response validation.
   *
   * @covers ::validateResponse
   */
  public function testResponseValidation() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->embeddingService);
    $method = $reflection->getMethod('validateResponse');
    $method->setAccessible(TRUE);

    // Test valid response.
    $valid_response = [
      'data' => [
      ['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 1536, 0.1)],
      ],
      'model' => 'text-embedding-ada-002',
    ];

    $this->assertTrue($method->invokeArgs($this->embeddingService, [$valid_response]));

    // Test missing data field.
    $invalid_response = ['model' => 'text-embedding-ada-002'];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid response format');
    $method->invokeArgs($this->embeddingService, [$invalid_response]);
  }

  /**
   * Tests retry mechanism with exponential backoff.
   *
   * @covers ::generateEmbedding
   */
  public function testRetryMechanismWithExponentialBackoff() {
    $text = 'Test text';

    // Mock temporary failure followed by success.
    $this->embeddingService->expects($this->exactly(2))
      ->method('makeHttpRequest')
      ->will($this->onConsecutiveCalls(
          $this->throwException(new RequestException(
              'Service Unavailable',
              new Request('POST', 'test'),
              new Response(503)
          )),
          ['data' => [['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 1536, 0.1)]]]
          ));

    $result = $this->embeddingService->generateEmbedding($text);

    $this->assertIsArray($result);
    $this->assertCount(1536, $result);
  }

  /**
   * Tests usage statistics tracking.
   *
   * @covers ::getUsageStatistics
   */
  public function testUsageStatisticsTracking() {
    $mock_response = [
      'data' => [
      ['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 1536, 0.1)],
      ],
      'usage' => [
        'prompt_tokens' => 10,
        'total_tokens' => 10,
      ],
    ];

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willReturn($mock_response);

    // Generate embedding to trigger usage tracking.
    $this->embeddingService->generateEmbedding('Test text');

    // Get usage statistics.
    $stats = $this->embeddingService->getUsageStatistics();

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('total_requests', $stats);
    $this->assertArrayHasKey('total_tokens', $stats);
    $this->assertArrayHasKey('total_embeddings', $stats);
    $this->assertArrayHasKey('average_tokens_per_request', $stats);

    $this->assertEquals(1, $stats['total_requests']);
    $this->assertEquals(10, $stats['total_tokens']);
    $this->assertEquals(1, $stats['total_embeddings']);
  }

  /**
   * Tests configuration validation.
   *
   * @covers ::__construct
   */
  public function testConfigurationValidation() {
    // Test valid configuration.
    $service = new AzureOpenAIEmbeddingService(
          'https://test.openai.azure.com/',
          'test-key',
          'test-deployment'
      );
    $this->assertTrue($service->isAvailable());

    // Test invalid endpoint format.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid endpoint format');

    new AzureOpenAIEmbeddingService(
          'invalid-endpoint',
          'test-key',
          'test-deployment'
      );
  }

  /**
   * Tests embedding dimension validation.
   *
   * @covers ::validateEmbeddingDimensions
   */
  public function testEmbeddingDimensionValidation() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->embeddingService);
    $method = $reflection->getMethod('validateEmbeddingDimensions');
    $method->setAccessible(TRUE);

    // Test valid dimensions.
    $valid_embedding = array_fill(0, 1536, 0.1);
    $this->assertTrue($method->invokeArgs($this->embeddingService, [$valid_embedding]));

    // Test invalid dimensions.
    $invalid_embedding = array_fill(0, 100, 0.1);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unexpected embedding dimensions');
    $method->invokeArgs($this->embeddingService, [$invalid_embedding]);
  }

  /**
   * Tests error message extraction from API responses.
   *
   * @covers ::extractErrorMessage
   */
  public function testErrorMessageExtraction() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->embeddingService);
    $method = $reflection->getMethod('extractErrorMessage');
    $method->setAccessible(TRUE);

    // Test OpenAI error format.
    $response_body = '{"error": {"code": "invalid_api_key", "message": "Invalid API key provided"}}';
    $error_message = $method->invokeArgs($this->embeddingService, [$response_body]);
    $this->assertEquals('Invalid API key provided', $error_message);

    // Test generic error format.
    $response_body = '{"message": "Service temporarily unavailable"}';
    $error_message = $method->invokeArgs($this->embeddingService, [$response_body]);
    $this->assertEquals('Service temporarily unavailable', $error_message);

    // Test invalid JSON.
    $response_body = 'Invalid JSON response';
    $error_message = $method->invokeArgs($this->embeddingService, [$response_body]);
    $this->assertEquals('Unknown error occurred', $error_message);

    // Test empty response.
    $error_message = $method->invokeArgs($this->embeddingService, ['']);
    $this->assertEquals('Unknown error occurred', $error_message);
  }

  /**
   * Tests connection timeout handling.
   *
   * @covers ::generateEmbedding
   */
  public function testConnectionTimeoutHandling() {
    $text = 'Test text';

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willThrowException(new RequestException(
          'Connection timeout',
          new Request('POST', 'test')
          ));

    $this->expectException(EmbeddingServiceUnavailableException::class);
    $this->expectExceptionMessage('temporarily unavailable');

    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests malformed response handling.
   *
   * @covers ::generateEmbedding
   */
  public function testMalformedResponseHandling() {
    $text = 'Test text';

    // Mock malformed response (missing required fields)
    $malformed_response = [
      'model' => 'text-embedding-ada-002',
      // Missing 'data' field.
    ];

    $this->embeddingService->expects($this->once())
      ->method('makeHttpRequest')
      ->willReturn($malformed_response);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid response format');

    $this->embeddingService->generateEmbedding($text);
  }

  /**
   * Tests concurrent request handling.
   */
  public function testConcurrentRequestHandling() {
    // This test would require actual threading/async support
    // For now, we verify that the service can handle multiple sequential requests.
    $texts = ['Text 1', 'Text 2', 'Text 3'];
    $mock_response = [
      'data' => [
      ['object' => 'embedding', 'index' => 0, 'embedding' => array_fill(0, 1536, 0.1)],
      ],
    ];

    $this->embeddingService->expects($this->exactly(3))
      ->method('makeHttpRequest')
      ->willReturn($mock_response);

    $results = [];
    foreach ($texts as $text) {
      $results[] = $this->embeddingService->generateEmbedding($text);
    }

    $this->assertCount(3, $results);
    foreach ($results as $result) {
      $this->assertIsArray($result);
      $this->assertCount(1536, $result);
    }
  }

}
