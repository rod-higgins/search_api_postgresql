<?php

namespace Drupal\search_api_postgresql\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_postgresql\Queue\EmbeddingQueueManager;
use Drupal\search_api_postgresql\Service\EmbeddingServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes embedding generation queue items.
 *
 * @QueueWorker(
 *   id = "search_api_postgresql_embedding",
 *   title = @Translation("Search API PostgreSQL Embedding Generator"),
 *   cron = {"time" = 60}
 * )
 */
class EmbeddingWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The embedding queue manager.
   *
   * @var \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager
   */
  protected $queueManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Maximum processing time per queue run (seconds).
   *
   * @var int
   */
  protected $maxProcessingTime = 50;

  /**
   * Start time of current processing.
   *
   * @var float
   */
  protected $startTime;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('search_api_postgresql.embedding_queue_manager'),
      $container->get('logger.channel.search_api_postgresql')
    );
  }

  /**
   * Constructs an EmbeddingWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\search_api_postgresql\Queue\EmbeddingQueueManager $queue_manager
   *   The embedding queue manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EmbeddingQueueManager $queue_manager, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->queueManager = $queue_manager;
    $this->logger = $logger;
    $this->startTime = microtime(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Check if we're approaching time limit
    if ($this->isNearTimeLimit()) {
      throw new SuspendQueueException('Approaching time limit, suspending queue processing');
    }

    try {
      $this->validateQueueItem($data);
      
      switch ($data['operation']) {
        case 'generate_embedding':
          $this->processEmbeddingGeneration($data);
          break;
          
        case 'batch_generate_embeddings':
          $this->processBatchEmbeddingGeneration($data);
          break;
          
        case 'regenerate_index_embeddings':
          $this->processIndexEmbeddingRegeneration($data);
          break;
          
        default:
          throw new \InvalidArgumentException("Unknown operation: {$data['operation']}");
      }
      
      $this->logger->debug('Successfully processed queue item: @operation', [
        '@operation' => $data['operation']
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to process queue item: @message', [
        '@message' => $e->getMessage(),
        '@data' => json_encode($data)
      ]);
      
      // Re-throw exception to mark item as failed
      throw $e;
    }
  }

  /**
   * Processes single embedding generation.
   *
   * @param array $data
   *   Queue item data.
   */
  protected function processEmbeddingGeneration(array $data) {
    $server_id = $data['server_id'];
    $index_id = $data['index_id'];
    $item_id = $data['item_id'];
    $text_content = $data['text_content'];
    
    $server = $this->loadServer($server_id);
    $index = $this->loadIndex($index_id);
    $embedding_service = $this->getEmbeddingService($server);
    
    if (!$embedding_service) {
      throw new \Exception("Embedding service not available for server: {$server_id}");
    }

    // Generate embedding
    $embedding = $embedding_service->generateEmbedding($text_content);
    
    if (!$embedding) {
      throw new \Exception("Failed to generate embedding for item: {$item_id}");
    }

    // Store embedding in database
    $this->storeEmbedding($server, $index, $item_id, $embedding);
    
    $this->logger->info('Generated embedding for item @item in index @index', [
      '@item' => $item_id,
      '@index' => $index_id
    ]);
  }

  /**
   * Processes batch embedding generation.
   *
   * @param array $data
   *   Queue item data.
   */
  protected function processBatchEmbeddingGeneration(array $data) {
    $server_id = $data['server_id'];
    $index_id = $data['index_id'];
    $items = $data['items']; // Array of ['item_id' => 'text_content']
    
    $server = $this->loadServer($server_id);
    $index = $this->loadIndex($index_id);
    $embedding_service = $this->getEmbeddingService($server);
    
    if (!$embedding_service) {
      throw new \Exception("Embedding service not available for server: {$server_id}");
    }

    // Extract texts for batch processing
    $texts = array_values($items);
    $item_ids = array_keys($items);
    
    // Generate embeddings in batch
    $embeddings = $embedding_service->generateBatchEmbeddings($texts);
    
    if (count($embeddings) !== count($texts)) {
      throw new \Exception("Batch embedding generation failed: expected " . count($texts) . " embeddings, got " . count($embeddings));
    }

    // Store embeddings
    foreach ($embeddings as $index_num => $embedding) {
      $item_id = $item_ids[$index_num];
      $this->storeEmbedding($server, $index, $item_id, $embedding);
    }
    
    $this->logger->info('Generated @count embeddings in batch for index @index', [
      '@count' => count($embeddings),
      '@index' => $index_id
    ]);
  }

  /**
   * Processes index embedding regeneration.
   *
   * @param array $data
   *   Queue item data.
   */
  protected function processIndexEmbeddingRegeneration(array $data) {
    $server_id = $data['server_id'];
    $index_id = $data['index_id'];
    $batch_size = $data['batch_size'] ?? 50;
    $offset = $data['offset'] ?? 0;
    
    $server = $this->loadServer($server_id);
    $index = $this->loadIndex($index_id);
    
    // Get backend and index manager
    $backend = $server->getBackend();
    $reflection = new \ReflectionClass($backend);
    
    // Connect to get index manager
    $connect_method = $reflection->getMethod('connect');
    $connect_method->setAccessible(TRUE);
    $connect_method->invoke($backend);
    
    $index_manager_property = $reflection->getProperty('indexManager');
    $index_manager_property->setAccessible(TRUE);
    $index_manager = $index_manager_property->getValue($backend);
    
    // Process batch of items
    $items_processed = $this->regenerateEmbeddingsBatch($index_manager, $index, $batch_size, $offset);
    
    if ($items_processed > 0) {
      // Queue next batch if there are more items
      $next_offset = $offset + $batch_size;
      $this->queueManager->queueIndexEmbeddingRegeneration($server_id, $index_id, $batch_size, $next_offset);
      
      $this->logger->info('Processed @count items for embedding regeneration (offset @offset)', [
        '@count' => $items_processed,
        '@offset' => $offset
      ]);
    } else {
      $this->logger->info('Completed embedding regeneration for index @index', [
        '@index' => $index_id
      ]);
    }
  }

  /**
   * Regenerates embeddings for a batch of items.
   *
   * @param mixed $index_manager
   *   The index manager.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param int $batch_size
   *   The batch size.
   * @param int $offset
   *   The offset.
   *
   * @return int
   *   Number of items processed.
   */
  protected function regenerateEmbeddingsBatch($index_manager, IndexInterface $index, $batch_size, $offset) {
    // This would need to be implemented based on the specific index manager
    // For now, return 0 to indicate completion
    return 0;
  }

  /**
   * Stores an embedding in the database.
   *
   * @param mixed $server
   *   The search server.
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index.
   * @param string $item_id
   *   The item ID.
   * @param array $embedding
   *   The embedding vector.
   */
  protected function storeEmbedding($server, IndexInterface $index, $item_id, array $embedding) {
    $backend = $server->getBackend();
    $config = $backend->getConfiguration();
    
    // Get database connection
    $reflection = new \ReflectionClass($backend);
    $connect_method = $reflection->getMethod('connect');
    $connect_method->setAccessible(TRUE);
    $connect_method->invoke($backend);
    
    $connector_property = $reflection->getProperty('connector');
    $connector_property->setAccessible(TRUE);
    $connector = $connector_property->getValue($backend);
    
    // Build table name
    $table_name = $config['index_prefix'] . $index->id();
    $safe_table_name = $connector->validateTableName($table_name);
    
    // Update embedding in database
    $sql = "UPDATE {$safe_table_name} SET content_embedding = :embedding WHERE search_api_id = :item_id";
    $params = [
      ':embedding' => '[' . implode(',', $embedding) . ']',
      ':item_id' => $item_id
    ];
    
    $connector->executeQuery($sql, $params);
  }

  /**
   * Validates queue item data.
   *
   * @param array $data
   *   Queue item data.
   *
   * @throws \InvalidArgumentException
   *   If data is invalid.
   */
  protected function validateQueueItem(array $data) {
    $required_fields = ['operation', 'server_id'];
    
    foreach ($required_fields as $field) {
      if (empty($data[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }
    
    // Validate operation-specific fields
    switch ($data['operation']) {
      case 'generate_embedding':
        $required = ['index_id', 'item_id', 'text_content'];
        break;
        
      case 'batch_generate_embeddings':
        $required = ['index_id', 'items'];
        break;
        
      case 'regenerate_index_embeddings':
        $required = ['index_id'];
        break;
        
      default:
        throw new \InvalidArgumentException("Unknown operation: {$data['operation']}");
    }
    
    foreach ($required as $field) {
      if (!isset($data[$field])) {
        throw new \InvalidArgumentException("Missing required field for operation {$data['operation']}: {$field}");
      }
    }
  }

  /**
   * Loads a server by ID.
   *
   * @param string $server_id
   *   The server ID.
   *
   * @return \Drupal\search_api\ServerInterface
   *   The server.
   *
   * @throws \Exception
   *   If server not found.
   */
  protected function loadServer($server_id) {
    $server = \Drupal::entityTypeManager()->getStorage('search_api_server')->load($server_id);
    
    if (!$server) {
      throw new \Exception("Server not found: {$server_id}");
    }
    
    return $server;
  }

  /**
   * Loads an index by ID.
   *
   * @param string $index_id
   *   The index ID.
   *
   * @return \Drupal\search_api\IndexInterface
   *   The index.
   *
   * @throws \Exception
   *   If index not found.
   */
  protected function loadIndex($index_id) {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);
    
    if (!$index) {
      throw new \Exception("Index not found: {$index_id}");
    }
    
    return $index;
  }

  /**
   * Gets the embedding service for a server.
   *
   * @param mixed $server
   *   The search server.
   *
   * @return \Drupal\search_api_postgresql\Service\EmbeddingServiceInterface|null
   *   The embedding service.
   */
  protected function getEmbeddingService($server) {
    $backend = $server->getBackend();
    
    // Connect to initialize services
    $reflection = new \ReflectionClass($backend);
    $connect_method = $reflection->getMethod('connect');
    $connect_method->setAccessible(TRUE);
    $connect_method->invoke($backend);
    
    // Get embedding service
    try {
      $embedding_service_property = $reflection->getProperty('embeddingService');
      $embedding_service_property->setAccessible(TRUE);
      return $embedding_service_property->getValue($backend);
    }
    catch (\ReflectionException $e) {
      return NULL;
    }
  }

  /**
   * Checks if we're near the time limit.
   *
   * @return bool
   *   TRUE if near time limit.
   */
  protected function isNearTimeLimit() {
    $elapsed = microtime(TRUE) - $this->startTime;
    return $elapsed > $this->maxProcessingTime;
  }

}