<?php

namespace Drupal\search_api_postgresql\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Psr\Log\LoggerInterface;

/**
 * Wrapper service that can process embeddings either synchronously or via queue.
 */
class QueuedEmbeddingService implements EmbeddingServiceInterface
{
  /**
   * The embedding queue manager.
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * The logger.
   * {@inheritdoc}
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   * {@inheritdoc}
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The actual embedding service for synchronous processing.
   * {@inheritdoc}
   *
   * @var \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface
   */
  protected $embeddingService;

  /**
   * Service configuration.
   * {@inheritdoc}
   *
   * @var array
   */
  protected $config;

  /**
   * Queue context for current operation.
   * {@inheritdoc}
   *
   * @var array|null
   */
  protected $queueContext;

  /**
   * Track ongoing embedding generations to prevent recursion.
   * {@inheritdoc}
   *
   * @var array
   */
  protected static $processingTexts = [];

  /**
   * Constructs a QueuedEmbeddingService.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager $queue_manager
   *   The embedding queue manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
      EmbeddingQueueManager $queue_manager,
      LoggerInterface $logger,
      ConfigFactoryInterface $config_factory
  ) {
    $this->queueManager = $queue_manager;
    $this->logger = $logger;
    $this->configFactory = $config_factory;

    $this->config = $this->configFactory->get('search_api_postgresql.queued_embedding')->get() ?: [];
    $this->config += $this->getDefaultConfig();
  }

  /**
   * Sets the actual embedding service for synchronous processing.
   * {@inheritdoc}
   *
   * @param \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface $embedding_service
   *   The embedding service.
   */
  public function setEmbeddingService(EmbeddingServiceInterface $embedding_service)
  {
    $this->embeddingService = $embedding_service;
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbedding($text)
  {
    // Create a hash of the text to track duplicates.
    $text_hash = md5($text);

    // Check if we're already processing this text (recursion guard)
    if (isset(self::$processingTexts[$text_hash])) {
      $this->logger->warning('Recursive embedding generation detected for text hash @hash, skipping', [
        '@hash' => $text_hash,
      ]);
      return null;
    }

    // Mark this text as being processed.
    self::$processingTexts[$text_hash] = true;

    try {
      // Your existing debug code...
      $this->logger->debug('generateEmbedding called with text length: @length', [
        '@length' => strlen($text),
      ]);

      $should_queue = $this->shouldUseQueue();
      $has_context = $this->hasQueueContext();

      if ($should_queue && $has_context) {
        $this->logger->debug('Taking QUEUED path');
        $result = $this->generateEmbeddingQueued($text);
      } else {
        $this->logger->debug('Taking SYNCHRONOUS path');
        if ($this->embeddingService) {
          $result = $this->embeddingService->generateEmbedding($text);
        } else {
          throw new \Exception('No embedding service available for synchronous processing');
        }
      }

      return $result;
    } finally {
      // Always clean up the processing flag.
      unset(self::$processingTexts[$text_hash]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function generateBatchEmbeddings(array $texts)
  {
    if ($this->shouldUseQueue() && $this->hasQueueContext()) {
      return $this->generateBatchEmbeddingsQueued($texts);
    }

    // Fall back to synchronous processing.
    if ($this->embeddingService) {
      return $this->embeddingService->generateBatchEmbeddings($texts);
    }

    throw new \Exception('No embedding service available for synchronous processing');
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension()
  {
    if ($this->embeddingService) {
      return $this->embeddingService->getDimension();
    }

    return $this->config['default_dimension'] ?? 1536;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable()
  {
    // Always available if queue is enabled.
    if ($this->shouldUseQueue()) {
      return true;
    }

    // Check if synchronous service is available.
    return $this->embeddingService && $this->embeddingService->isAvailable();
  }

  /**
   * Generates embedding using queue processing.
   * {@inheritdoc}
   *
   * @param string $text
   *   The text to embed.
   *   {@inheritdoc}.
   *
   * @return array|null
   *   The embedding vector, or NULL if queued.
   */
  protected function generateEmbeddingQueued($text)
  {
    $context = $this->getQueueContext();

    if (!$context) {
      throw new \Exception('No queue context available for embedding generation');
    }

    $queued = $this->queueManager->queueEmbeddingGeneration(
        $context['server_id'],
        $context['index_id'],
        $context['item_id'],
        $text,
        $this->getQueuePriority($context)
    );

    if (!$queued) {
      throw new \Exception('Failed to queue embedding generation');
    }

    $this->logger->debug('Queued embedding generation for item @item', [
      '@item' => $context['item_id'],
    ]);

    // Return null to indicate processing is queued.
    return null;
  }

  /**
   * Generates batch embeddings using queue processing.
   * {@inheritdoc}
   *
   * @param array $texts
   *   Array of texts to embed.
   *   {@inheritdoc}.
   *
   * @return array
   *   Empty array indicating processing is queued.
   */
  protected function generateBatchEmbeddingsQueued(array $texts)
  {
    $context = $this->getQueueContext();

    if (!$context || !isset($context['items'])) {
      throw new \Exception('No batch queue context available for embedding generation');
    }

    $queued = $this->queueManager->queueBatchEmbeddingGeneration(
        $context['server_id'],
        $context['index_id'],
        $context['items'],
        $this->getQueuePriority($context)
    );

    if (!$queued) {
      throw new \Exception('Failed to queue batch embedding generation');
    }

    $this->logger->debug('Queued batch embedding generation for @count items', [
      '@count' => count($texts),
    ]);

    // Return empty array to indicate processing is queued.
    return [];
  }

  /**
   * Queues index items for embedding generation.
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   * @param string $index_id
   *   The index ID.
   * @param array $items
   *   Array of search API items.
   * @param array $options
   *   Processing options.
   *   {@inheritdoc}.
   *
   * @return bool
   *   true if successfully queued.
   */
  public function queueIndexItems($server_id, $index_id, array $items, array $options = [])
  {
    if (!$this->queueManager->isQueueEnabledForServer($server_id)) {
      return false;
    }

    // Set queue context for this operation.
    $this->setQueueContext([
      'server_id' => $server_id,
      'index_id' => $index_id,
      'batch_mode' => $options['batch_mode'] ?? true,
      'priority' => $options['priority'] ?? 'normal',
    ]);

    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);

    if (!$index) {
      throw new \Exception("Index not found: {$index_id}");
    }

    $use_batch = $options['batch_mode'] ?? true;
    return $this->queueManager->queueIndexItems($index, $items, $use_batch);
  }

  /**
   * Processes embeddings synchronously (fallback mode).
   * {@inheritdoc}
   *
   * @param string $server_id
   *   The server ID.
   * @param array $items
   *   Array of items to process.
   * @param array $options
   *   Processing options.
   *   {@inheritdoc}.
   *
   * @return array
   *   Processing results.
   */
  public function processSynchronously($server_id, array $items, array $options = [])
  {
    if (!$this->embeddingService) {
      throw new \Exception('No embedding service available for synchronous processing');
    }

    // Disable queue for this operation.
    $this->clearQueueContext();

    $results = ['processed' => 0, 'failed' => 0, 'errors' => []];

    foreach ($items as $item_id => $text_content) {
      try {
        $embedding = $this->embeddingService->generateEmbedding($text_content);

        if ($embedding) {
          $results['processed']++;
        } else {
          $results['failed']++;
          $results['errors'][] = "Failed to generate embedding for item: {$item_id}";
        }
      } catch (\Exception $e) {
        $results['failed']++;
        $results['errors'][] = "Error processing item {$item_id}: " . $e->getMessage();
      }
    }

    return $results;
  }

  /**
   * Gets queue processing status.
   * {@inheritdoc}
   *
   * @return array
   *   Queue status information.
   */
  public function getQueueStatus()
  {
    return [
      'queue_enabled' => $this->shouldUseQueue(),
      'queue_stats' => $this->queueManager->getQueueStats(),
      'synchronous_available' => $this->embeddingService && $this->embeddingService->isAvailable(),
      'config' => $this->config,
    ];
  }

  /**
   * Processes the queue manually.
   * {@inheritdoc}
   *
   * @param array $options
   *   Processing options.
   *   {@inheritdoc}.
   *
   * @return array
   *   Processing results.
   */
  public function processQueue(array $options = [])
  {
    $max_items = $options['max_items'] ?? 50;
    $time_limit = $options['time_limit'] ?? 60;

    return $this->queueManager->processQueue($max_items, $time_limit);
  }

  /**
   * Clears the embedding queue.
   * {@inheritdoc}
   *
   * @return bool
   *   true if successfully cleared.
   */
  public function clearQueue()
  {
    return $this->queueManager->clearQueue();
  }

  /**
   * Sets queue context for current operation.
   * {@inheritdoc}
   *
   * @param array $context
   *   Queue context data.
   */
  public function setQueueContext(array $context)
  {
    $this->queueContext = $context;
  }

  /**
   * Gets current queue context.
   * {@inheritdoc}
   *
   * @return array|null
   *   Queue context or NULL if not set.
   */
  public function getQueueContext()
  {
    return $this->queueContext ?? null;
  }

  /**
   * Clears queue context.
   */
  public function clearQueueContext()
  {
    $this->queueContext = null;
  }

  /**
   * Checks if queue processing should be used.
   * {@inheritdoc}
   *
   * @return bool
   *   true if queue should be used.
   */
  protected function shouldUseQueue()
  {
    // Check global queue setting.
    if (!($this->config['enabled'] ?? false)) {
      return false;
    }

    // Check if we're in a queue context.
    $context = $this->getQueueContext();
    if (!$context) {
      return false;
    }

    // Check server-specific setting.
    $server_id = $context['server_id'] ?? null;
    if ($server_id && !$this->queueManager->isQueueEnabledForServer($server_id)) {
      return false;
    }

    return true;
  }

  /**
   * Checks if we have proper queue context.
   * {@inheritdoc}
   *
   * @return bool
   *   true if queue context is available.
   */
  protected function hasQueueContext()
  {
    $context = $this->getQueueContext();

    if (!$context) {
      return false;
    }

    // Check required context fields.
    $required = ['server_id', 'index_id'];
    foreach ($required as $field) {
      if (empty($context[$field])) {
        return false;
      }
    }

    return true;
  }

  /**
   * Gets queue priority for the current context.
   * {@inheritdoc}
   *
   * @param array $context
   *   Queue context.
   *   {@inheritdoc}.
   *
   * @return int
   *   Queue priority.
   */
  protected function getQueuePriority(array $context)
  {
    $priority_name = $context['priority'] ?? 'normal';

    $priority_map = [
      'high' => 50,
      'normal' => 100,
      'low' => 200,
    ];

    return $priority_map[$priority_name] ?? 100;
  }

  /**
   * Gets default configuration.
   * {@inheritdoc}
   *
   * @return array
   *   Default configuration.
   */
  protected function getDefaultConfig()
  {
    return [
      'enabled' => false,
      'default_dimension' => 1536,
      'fallback_to_sync' => true,
    // Use batch processing for 5+ items.
      'batch_threshold' => 5,
      'priority_mapping' => [
        'realtime' => 'high',
        'index' => 'normal',
        'bulk' => 'low',
      ],
    ];
  }
}
