<?php

require_once __DIR__ . '/../src/Cache/EmbeddingCacheInterface.php';
require_once __DIR__ . '/../src/Cache/MemoryEmbeddingCache.php';

// Create PSR Logger interface if not exists
if (!interface_exists('Psr\Log\LoggerInterface')) {
  interface LoggerInterface {
    public function emergency($message, array $context = []);
    public function alert($message, array $context = []);
    public function critical($message, array $context = []);
    public function error($message, array $context = []);
    public function warning($message, array $context = []);
    public function notice($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
    public function log($level, $message, array $context = []);
  }
  class_alias('LoggerInterface', 'Psr\Log\LoggerInterface');
}

// Create a debugging logger
$logger = new class implements Psr\Log\LoggerInterface {
  public function emergency($message, array $context = []) { echo "EMERGENCY: $message\n"; }
  public function alert($message, array $context = []) { echo "ALERT: $message\n"; }
  public function critical($message, array $context = []) { echo "CRITICAL: $message\n"; }
  public function error($message, array $context = []) { echo "ERROR: $message\n"; }
  public function warning($message, array $context = []) { echo "WARNING: $message\n"; }
  public function notice($message, array $context = []) { echo "NOTICE: $message\n"; }
  public function info($message, array $context = []) { echo "INFO: $message\n"; }
  public function debug($message, array $context = []) {
    echo "DEBUG: " . strtr($message, $context) . "\n";
  }
  public function log($level, $message, array $context = []) {
    echo "$level: $message\n";
  }
};

echo "Debug Cache Size Limits Test\n";
echo "============================\n";

// Create cache with small limit
$cache = new Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache(
  $logger,
  ['max_entries' => 2, 'cleanup_threshold' => 0.1]
);

echo "\nStep 1: Adding first item\n";
$hash1 = hash('sha256', 'item1');
$result1 = $cache->set($hash1, [1.0]);
echo "Set hash1: " . ($result1 ? 'success' : 'failed') . "\n";

echo "\nStep 2: Adding second item\n";
$hash2 = hash('sha256', 'item2');
$result2 = $cache->set($hash2, [2.0]);
echo "Set hash2: " . ($result2 ? 'success' : 'failed') . "\n";

echo "\nStep 3: Accessing hash1 to make it recently used\n";
$get1 = $cache->get($hash1);
echo "Get hash1: " . (is_null($get1) ? 'null' : 'found') . "\n";

echo "\nStep 4: Adding third item (should trigger cleanup)\n";
$hash3 = hash('sha256', 'item3');
$result3 = $cache->set($hash3, [3.0]);
echo "Set hash3: " . ($result3 ? 'success' : 'failed') . "\n";

echo "\nStep 5: Checking what's still in cache\n";
$get1_after = $cache->get($hash1);
$get2_after = $cache->get($hash2);
$get3_after = $cache->get($hash3);

echo "hash1 exists: " . (is_null($get1_after) ? 'no' : 'yes') . "\n";
echo "hash2 exists: " . (is_null($get2_after) ? 'no' : 'yes') . "\n";
echo "hash3 exists: " . (is_null($get3_after) ? 'no' : 'yes') . "\n";

echo "\nCache stats:\n";
$stats = $cache->getStats();
print_r($stats);