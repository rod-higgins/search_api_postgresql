<?php

namespace Drupal\search_api_postgresql\Commands;

use Drupal\search_api\Entity\Server;
use Drupal\search_api_postgresql\PostgreSQL\EmbeddingService;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

/**
 * Drush commands for Search API PostgreSQL with AI embeddings.
 */
class SearchApiPostgreSQLCommands extends DrushCommands {

  /**
   * Tests the Azure AI Services connection.
   *
   * @param string $server_id
   *   The Search API server ID.
   *
   * @command search-api-postgresql:test-ai
   * @aliases sapg-ai-test
   * @usage search-api-postgresql:test-ai my_server
   *   Test Azure AI connection for the specified server.
   */
  public function testAzureAiConnection($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    $config = $backend->getConfiguration();
    if (!($config['ai_embeddings']['enabled'] ?? FALSE) && !($config['azure_embedding']['enabled'] ?? FALSE)) {
      throw new \Exception("AI embeddings are not enabled for server '{$server_id}'.");
    }

    try {
      // Get API key based on storage method using the backend's secure key retrieval
      $reflection = new \ReflectionClass($backend);
      
      // Check if it's Azure backend or regular backend
      if ($backend->getPluginId() === 'postgresql_azure') {
        $method = $reflection->getMethod('getAzureEmbeddingApiKey');
        $method->setAccessible(TRUE);
        $api_key = $method->invoke($backend);
        $azure_config = $config['azure_embedding'];
      } else {
        $method = $reflection->getMethod('getAzureApiKey');
        $method->setAccessible(TRUE);
        $api_key = $method->invoke($backend);
        $azure_config = $config['ai_embeddings']['azure_ai'];
      }

      if (empty($api_key)) {
        throw new \Exception('API key could not be retrieved from secure storage.');
      }

      // Test the connection.
      $logger = \Drupal::logger('search_api_postgresql');
      $embedding_service = new EmbeddingService($azure_config, $api_key, $logger);
      $test_embedding = $embedding_service->generateEmbedding('This is a test text for Azure AI Services connection.');

      $this->output()->writeln('<info>Azure AI Services connection successful!</info>');
      $this->output()->writeln("Generated embedding with " . count($test_embedding) . " dimensions.");
      $this->output()->writeln("Sample values: " . implode(', ', array_slice($test_embedding, 0, 5)) . "...");
    }
    catch (\Exception $e) {
      throw new \Exception("Azure AI Services connection failed: " . $e->getMessage());
    }
  }

  /**
   * Regenerates embeddings for all items in an index.
   *
   * @param string $index_id
   *   The Search API index ID.
   *
   * @command search-api-postgresql:regenerate-embeddings
   * @aliases sapg-regen
   * @usage search-api-postgresql:regenerate-embeddings my_index
   *   Regenerate embeddings for all items in the specified index.
   */
  public function regenerateEmbeddings($index_id) {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);
    
    if (!$index) {
      throw new \Exception("Index '{$index_id}' not found.");
    }

    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Index '{$index_id}' is not using PostgreSQL backend.");
    }

    $config = $backend->getConfiguration();
    if (!($config['ai_embeddings']['enabled'] ?? FALSE) && !($config['azure_embedding']['enabled'] ?? FALSE)) {
      throw new \Exception("AI embeddings are not enabled for this index.");
    }

    // Confirm with user.
    if (!$this->confirm("This will regenerate embeddings for all items in index '{$index_id}'. This may incur API costs. Continue?")) {
      throw new UserAbortException();
    }

    try {
      // Get the index manager from the backend.
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('connect');
      $method->setAccessible(TRUE);
      $method->invoke($backend);

      $property = $reflection->getProperty('indexManager');
      $property->setAccessible(TRUE);
      $index_manager = $property->getValue($backend);

      $this->output()->writeln('<info>Starting embedding regeneration...</info>');
      $index_manager->regenerateEmbeddings($index);
      $this->output()->writeln('<info>Embedding regeneration completed successfully!</info>');
    }
    catch (\Exception $e) {
      throw new \Exception("Embedding regeneration failed: " . $e->getMessage());
    }
  }

  /**
   * Checks if pgvector extension is available.
   *
   * @param string $server_id
   *   The Search API server ID.
   *
   * @command search-api-postgresql:check-vector-support
   * @aliases sapg-vector-check
   * @usage search-api-postgresql:check-vector-support my_server
   *   Check if pgvector extension is available on the specified server.
   */
  public function checkVectorSupport($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    try {
      // Connect to the database and check for vector extension.
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('connect');
      $method->setAccessible(TRUE);
      $method->invoke($backend);

      $property = $reflection->getProperty('connector');
      $property->setAccessible(TRUE);
      $connector = $property->getValue($backend);

      $sql = "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'vector') as has_vector";
      $stmt = $connector->executeQuery($sql);
      $result = $stmt->fetch();

      if ($result['has_vector']) {
        $this->output()->writeln('<info>pgvector extension is available and enabled.</info>');
        
        // Get version info.
        $version_sql = "SELECT extversion FROM pg_extension WHERE extname = 'vector'";
        $version_stmt = $connector->executeQuery($version_sql);
        $version_result = $version_stmt->fetch();
        
        $this->output()->writeln("pgvector version: " . $version_result['extversion']);
      }
      else {
        $this->output()->writeln('<comment>pgvector extension is not available.</comment>');
        $this->output()->writeln('To enable vector search, install and enable the pgvector extension:');
        $this->output()->writeln('  CREATE EXTENSION vector;');
      }

      // Check PostgreSQL version.
      $pg_version = $connector->getVersion();
      $this->output()->writeln("PostgreSQL version: " . $pg_version);
    }
    catch (\Exception $e) {
      throw new \Exception("Failed to check vector support: " . $e->getMessage());
    }
  }

  /**
   * Shows embedding statistics for an index.
   *
   * @param string $index_id
   *   The Search API index ID.
   *
   * @command search-api-postgresql:embedding-stats
   * @aliases sapg-stats
   * @usage search-api-postgresql:embedding-stats my_index
   *   Show embedding statistics for the specified index.
   */
  public function embeddingStats($index_id) {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);
    
    if (!$index) {
      throw new \Exception("Index '{$index_id}' not found.");
    }

    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Index '{$index_id}' is not using PostgreSQL backend.");
    }

    try {
      // Connect and get statistics.
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('connect');
      $method->setAccessible(TRUE);
      $method->invoke($backend);

      $property = $reflection->getProperty('connector');
      $property->setAccessible(TRUE);
      $connector = $property->getValue($backend);

      $config = $backend->getConfiguration();
      $table_name = $config['index_prefix'] . $index_id;

      // Check if table exists.
      if (!$connector->tableExists($table_name)) {
        throw new \Exception("Index table does not exist. Please create the index first.");
      }

      // Get total items.
      $total_sql = "SELECT COUNT(*) as total FROM {$table_name}";
      $total_stmt = $connector->executeQuery($total_sql);
      $total_result = $total_stmt->fetch();
      $total_items = $total_result['total'];

      $this->output()->writeln("Index: {$index_id}");
      $this->output()->writeln("Total items: {$total_items}");

      // Check if embeddings are enabled.
      $embeddings_enabled = ($config['ai_embeddings']['enabled'] ?? FALSE) || ($config['azure_embedding']['enabled'] ?? FALSE);
      
      if ($embeddings_enabled) {
        // Try both embedding column names for compatibility
        $embedding_columns = ['embedding_vector', 'content_embedding'];
        $embedded_items = 0;
        
        foreach ($embedding_columns as $column) {
          try {
            $embedded_sql = "SELECT COUNT(*) as embedded FROM {$table_name} WHERE {$column} IS NOT NULL";
            $embedded_stmt = $connector->executeQuery($embedded_sql);
            $embedded_result = $embedded_stmt->fetch();
            $embedded_items = max($embedded_items, $embedded_result['embedded']);
            break; // Use the first column that works
          } catch (\Exception $e) {
            // Column might not exist, try the next one
            continue;
          }
        }

        $percentage = $total_items > 0 ? round(($embedded_items / $total_items) * 100, 2) : 0;

        $this->output()->writeln("Items with embeddings: {$embedded_items} ({$percentage}%)");
        $this->output()->writeln("Items without embeddings: " . ($total_items - $embedded_items));

        if ($embedded_items > 0) {
          // Get sample embedding dimensions.
          foreach ($embedding_columns as $column) {
            try {
              $sample_sql = "SELECT {$column} FROM {$table_name} WHERE {$column} IS NOT NULL LIMIT 1";
              $sample_stmt = $connector->executeQuery($sample_sql);
              $sample_result = $sample_stmt->fetch();
              
              if ($sample_result[$column]) {
                $vector_string = trim($sample_result[$column], '[]');
                $dimensions = count(explode(',', $vector_string));
                $this->output()->writeln("Vector dimensions: {$dimensions}");
                break;
              }
            } catch (\Exception $e) {
              continue;
            }
          }
        }
      }
      else {
        $this->output()->writeln('<comment>AI embeddings are not enabled for this index.</comment>');
      }
    }
    catch (\Exception $e) {
      throw new \Exception("Failed to get embedding statistics: " . $e->getMessage());
    }
  }

  /**
   * Shows embedding cache statistics.
   *
   * @param string $server_id
   *   The Search API server ID.
   *
   * @command search-api-postgresql:cache-stats
   * @aliases sapg-cache-stats
   * @usage search-api-postgresql:cache-stats my_server
   *   Show embedding cache statistics for the specified server.
   */
  public function showCacheStats($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    try {
      // Get cache manager from the backend
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('connect');
      $method->setAccessible(TRUE);
      $method->invoke($backend);

      // Check if embedding service exists and has cache
      $property = $reflection->getProperty('embeddingService');
      $property->setAccessible(TRUE);
      $embedding_service = $property->getValue($backend);

      if (!$embedding_service) {
        $this->output()->writeln('<comment>Embedding service is not configured for this server.</comment>');
        return;
      }

      // Get cache stats
      if (method_exists($embedding_service, 'getCacheStats')) {
        $stats = $embedding_service->getCacheStats();
        
        if (!$stats['cache_enabled']) {
          $this->output()->writeln('<comment>Embedding cache is not enabled for this server.</comment>');
          return;
        }

        $this->output()->writeln("Embedding Cache Statistics for server: {$server_id}");
        $this->output()->writeln("=====================================");
        
        // Display basic stats
        $this->output()->writeln("Cache Hits: " . ($stats['hits'] ?? 0));
        $this->output()->writeln("Cache Misses: " . ($stats['misses'] ?? 0));
        $this->output()->writeln("Hit Rate: " . ($stats['hit_rate'] ?? 0) . "%");
        
        if (isset($stats['total_entries'])) {
          $this->output()->writeln("Total Entries: " . $stats['total_entries']);
        }
        
        if (isset($stats['expired_entries'])) {
          $this->output()->writeln("Expired Entries: " . $stats['expired_entries']);
        }
        
        if (isset($stats['average_dimensions'])) {
          $this->output()->writeln("Average Dimensions: " . $stats['average_dimensions']);
        }
        
        // Display cost savings if available
        if (isset($stats['estimated_cost_saved_usd'])) {
          $this->output()->writeln("Estimated Cost Saved: $" . $stats['estimated_cost_saved_usd']);
        }
        
        if (isset($stats['estimated_tokens_saved'])) {
          $this->output()->writeln("Estimated Tokens Saved: " . number_format($stats['estimated_tokens_saved']));
        }
        
        // Display cache performance
        if (isset($stats['oldest_entry'])) {
          $this->output()->writeln("Oldest Entry: " . $stats['oldest_entry']);
        }
        
        if (isset($stats['newest_entry'])) {
          $this->output()->writeln("Newest Entry: " . $stats['newest_entry']);
        }
      } else {
        $this->output()->writeln('<comment>Cache statistics not available for this embedding service.</comment>');
      }
    }
    catch (\Exception $e) {
      throw new \Exception("Failed to get cache statistics: " . $e->getMessage());
    }
  }

  /**
   * Clears the embedding cache.
   *
   * @param string $server_id
   *   The Search API server ID.
   *
   * @command search-api-postgresql:cache-clear
   * @aliases sapg-cache-clear
   * @usage search-api-postgresql:cache-clear my_server
   *   Clear embedding cache for the specified server.
   */
  public function clearCache($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    // Confirm with user
    if (!$this->confirm("This will clear all cached embeddings for server '{$server_id}'. Continue?")) {
      throw new UserAbortException();
    }

    try {
      // Get embedding service from the backend
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('connect');
      $method->setAccessible(TRUE);
      $method->invoke($backend);

      $property = $reflection->getProperty('embeddingService');
      $property->setAccessible(TRUE);
      $embedding_service = $property->getValue($backend);

      if (!$embedding_service) {
        throw new \Exception('Embedding service is not configured for this server.');
      }

      if (method_exists($embedding_service, 'invalidateCache')) {
        $result = $embedding_service->invalidateCache();
        
        if ($result) {
          $this->output()->writeln('<info>✓ Embedding cache cleared successfully</info>');
        } else {
          $this->output()->writeln('<comment>Cache clear operation completed (no entries to clear)</comment>');
        }
      } else {
        throw new \Exception('Cache clearing not supported by this embedding service.');
      }
    }
    catch (\Exception $e) {
      throw new \Exception("Failed to clear cache: " . $e->getMessage());
    }
  }

  /**
   * Performs embedding cache maintenance.
   *
   * @param string $server_id
   *   The Search API server ID.
   *
   * @command search-api-postgresql:cache-maintenance
   * @aliases sapg-cache-maint
   * @usage search-api-postgresql:cache-maintenance my_server
   *   Perform cache maintenance (cleanup expired entries, optimize storage).
   */
  public function cacheMaintenance($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    try {
      // Get cache manager service
      $cache_manager = \Drupal::service('search_api_postgresql.cache_manager');
      
      $this->output()->writeln('<info>Starting cache maintenance...</info>');
      
      $stats_before = $cache_manager->getCacheStatistics();
      $results = $cache_manager->performMaintenance();
      $stats_after = $cache_manager->getCacheStatistics();
      
      if ($results['success']) {
        $this->output()->writeln('<info>✓ Cache maintenance completed successfully</info>');
        
        $entries_cleaned = $results['entries_cleaned'] ?? 0;
        if ($entries_cleaned > 0) {
          $this->output()->writeln("Cleaned up {$entries_cleaned} cache entries");
        }
        
        $this->output()->writeln("Entries before: " . ($stats_before['total_entries'] ?? 0));
        $this->output()->writeln("Entries after: " . ($stats_after['total_entries'] ?? 0));
      } else {
        throw new \Exception('Cache maintenance failed');
      }
    }
    catch (\Exception $e) {
      throw new \Exception("Cache maintenance failed: " . $e->getMessage());
    }
  }

  /**
   * Warms up the embedding cache with common content.
   *
   * @param string $index_id
   *   The Search API index ID.
   * @param array $options
   *   Additional options.
   *
   * @command search-api-postgresql:cache-warmup
   * @aliases sapg-cache-warmup
   * @option limit
   *   Maximum number of items to warm up (default: 100)
   * @option field
   *   Field to use for cache warmup (default: all text fields)
   * @usage search-api-postgresql:cache-warmup my_index --limit=50
   *   Warm up cache with 50 most recent items from the index.
   */
  public function cacheWarmup($index_id, array $options = ['limit' => 100, 'field' => NULL]) {
    $index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load($index_id);
    
    if (!$index) {
      throw new \Exception("Index '{$index_id}' not found.");
    }

    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Index '{$index_id}' is not using PostgreSQL backend.");
    }

    $config = $backend->getConfiguration();
    if (!($config['ai_embeddings']['enabled'] ?? FALSE) && !($config['azure_embedding']['enabled'] ?? FALSE)) {
      throw new \Exception("AI embeddings are not enabled for this index.");
    }

    $limit = (int) $options['limit'];
    if ($limit < 1 || $limit > 1000) {
      $limit = 100;
    }

    try {
      // Get embedding service
      $reflection = new \ReflectionClass($backend);
      $method = $reflection->getMethod('connect');
      $method->setAccessible(TRUE);
      $method->invoke($backend);

      $property = $reflection->getProperty('embeddingService');
      $property->setAccessible(TRUE);
      $embedding_service = $property->getValue($backend);

      if (!$embedding_service || !method_exists($embedding_service, 'warmupCache')) {
        throw new \Exception('Cache warmup not supported by this embedding service.');
      }

      // Get sample content from the index
      $this->output()->writeln("<info>Collecting sample content from index '{$index_id}'...</info>");
      
      $query = $index->query();
      $query->range(0, $limit);
      $query->addField('search_api_excerpt'); // Get text content
      
      $results = $query->execute();
      $items = $results->getResultItems();
      
      if (empty($items)) {
        $this->output()->writeln('<comment>No items found in the index for cache warmup.</comment>');
        return;
      }

      // Extract text content for warmup
      $texts = [];
      foreach ($items as $item) {
        $fields = $item->getFields();
        $text_content = '';
        
        foreach ($fields as $field_id => $field) {
          if ($field->getType() === 'text' || $field->getType() === 'postgresql_fulltext') {
            $values = $field->getValues();
            if (!empty($values)) {
              $text_content .= ' ' . reset($values);
            }
          }
        }
        
        if (!empty(trim($text_content))) {
          $texts[] = trim($text_content);
        }
      }

      if (empty($texts)) {
        $this->output()->writeln('<comment>No text content found for cache warmup.</comment>');
        return;
      }

      $this->output()->writeln("<info>Starting cache warmup for " . count($texts) . " items...</info>");
      
      $results = $embedding_service->warmupCache($texts);
      
      $this->output()->writeln('<info>Cache warmup completed:</info>');
      $this->output()->writeln("✓ Cached: " . $results['cached']);
      $this->output()->writeln("⚠ Failed: " . $results['failed']);
      $this->output()->writeln("- Skipped: " . $results['skipped']);
    }
    catch (\Exception $e) {
      throw new \Exception("Cache warmup failed: " . $e->getMessage());
    }
  }

  /**
   * Validates key configuration for a server.
   *
   * @param string $server_id
   *   The Search API server ID.
   *
   * @command search-api-postgresql:validate-keys
   * @aliases sapg-validate-keys
   * @usage search-api-postgresql:validate-keys my_server
   *   Validate that all required keys are properly configured.
   */
  public function validateKeys($server_id) {
    $server = Server::load($server_id);
    
    if (!$server) {
      throw new \Exception("Server '{$server_id}' not found.");
    }

    $backend = $server->getBackend();
    if (!in_array($backend->getPluginId(), ['postgresql', 'postgresql_azure'])) {
      throw new \Exception("Server '{$server_id}' is not using PostgreSQL backend.");
    }

    $config = $backend->getConfiguration();
    $key_repository = \Drupal::service('key.repository');
    
    $this->output()->writeln("Validating key configuration for server: {$server_id}");
    
    $errors = [];
    $warnings = [];

    // Check database password key
    $password_key = $config['connection']['password_key'] ?? '';
    if (empty($password_key)) {
      $errors[] = 'Database password key is not configured';
    } else {
      $key = $key_repository->getKey($password_key);
      if (!$key) {
        $errors[] = "Database password key '{$password_key}' not found";
      } else {
        $key_value = $key->getKeyValue();
        if (empty($key_value)) {
          $errors[] = "Database password key '{$password_key}' is empty or could not be decrypted";
        } else {
          $this->output()->writeln("<info>✓ Database password key '{$password_key}' is valid</info>");
        }
      }
    }

    // Check AI embedding keys if enabled
    $ai_enabled = ($config['ai_embeddings']['enabled'] ?? FALSE) || ($config['azure_embedding']['enabled'] ?? FALSE);
    
    if ($ai_enabled) {
      // Check for standard AI embeddings config
      if ($config['ai_embeddings']['enabled'] ?? FALSE) {
        $api_key_name = $config['ai_embeddings']['azure_ai']['api_key_name'] ?? '';
        if (empty($api_key_name)) {
          $errors[] = 'Azure AI API key is not configured';
        } else {
          $key = $key_repository->getKey($api_key_name);
          if (!$key) {
            $errors[] = "Azure AI API key '{$api_key_name}' not found";
          } else {
            $key_value = $key->getKeyValue();
            if (empty($key_value)) {
              $errors[] = "Azure AI API key '{$api_key_name}' is empty or could not be decrypted";
            } else {
              $this->output()->writeln("<info>✓ Azure AI API key '{$api_key_name}' is valid</info>");
            }
          }
        }
      }

      // Check for Azure-specific embedding config
      if ($config['azure_embedding']['enabled'] ?? FALSE) {
        $api_key_name = $config['azure_embedding']['api_key_name'] ?? '';
        if (empty($api_key_name)) {
          $errors[] = 'Azure embedding API key is not configured';
        } else {
          $key = $key_repository->getKey($api_key_name);
          if (!$key) {
            $errors[] = "Azure embedding API key '{$api_key_name}' not found";
          } else {
            $key_value = $key->getKeyValue();
            if (empty($key_value)) {
              $errors[] = "Azure embedding API key '{$api_key_name}' is empty or could not be decrypted";
            } else {
              $this->output()->writeln("<info>✓ Azure embedding API key '{$api_key_name}' is valid</info>");
            }
          }
        }
      }
    } else {
      $this->output()->writeln('<comment>AI embeddings are not enabled</comment>');
    }

    // Report results
    if (!empty($errors)) {
      $this->output()->writeln('<error>Validation failed with errors:</error>');
      foreach ($errors as $error) {
        $this->output()->writeln("<error>✗ {$error}</error>");
      }
      throw new \Exception('Key validation failed');
    }

    if (!empty($warnings)) {
      $this->output()->writeln('<comment>Validation completed with warnings:</comment>');
      foreach ($warnings as $warning) {
        $this->output()->writeln("<comment>⚠ {$warning}</comment>");
      }
    }

    if (empty($errors) && empty($warnings)) {
      $this->output()->writeln('<info>✓ All keys are properly configured</info>');
    }
  }

}