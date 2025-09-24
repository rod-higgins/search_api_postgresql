<?php

/**
 * @file
 * Comprehensive integration test suite for search_api_postgresql module.
 */

echo "Search API PostgreSQL - Comprehensive Integration Test Suite\n";
echo "============================================================\n\n";

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

// Create Drupal base classes if not exists
if (!class_exists('Drupal\search_api\DataType\DataTypePluginBase')) {
  class DataTypePluginBase {
    protected $configuration;
    protected $pluginId;
    protected $pluginDefinition;

    public function __construct(array $configuration, $plugin_id, $plugin_definition) {
      $this->configuration = $configuration;
      $this->pluginId = $plugin_id;
      $this->pluginDefinition = $plugin_definition;
    }
  }
  class_alias('DataTypePluginBase', 'Drupal\search_api\DataType\DataTypePluginBase');
}

// Test Results Tracker
class TestResults {
  private $tests = [];
  private $totalAssertions = 0;

  public function addTest($name, $passed, $assertions = 0, $message = '') {
    $this->tests[] = [
      'name' => $name,
      'passed' => $passed,
      'assertions' => $assertions,
      'message' => $message
    ];
    $this->totalAssertions += $assertions;
  }

  public function getSummary() {
    $passed = count(array_filter($this->tests, fn($t) => $t['passed']));
    $failed = count($this->tests) - $passed;

    return [
      'total' => count($this->tests),
      'passed' => $passed,
      'failed' => $failed,
      'assertions' => $this->totalAssertions
    ];
  }

  public function getFailures() {
    return array_filter($this->tests, fn($t) => !$t['passed']);
  }
}

$results = new TestResults();

// Test 1: Memory Embedding Cache
echo "1. Testing Memory Embedding Cache\n";
echo "==================================\n";

try {
  require_once __DIR__ . '/../src/Cache/EmbeddingCacheInterface.php';
  require_once __DIR__ . '/../src/Cache/MemoryEmbeddingCache.php';

  $logger = new class implements Psr\Log\LoggerInterface {
    public function emergency($message, array $context = []) {}
    public function alert($message, array $context = []) {}
    public function critical($message, array $context = []) {}
    public function error($message, array $context = []) {}
    public function warning($message, array $context = []) {}
    public function notice($message, array $context = []) {}
    public function info($message, array $context = []) {}
    public function debug($message, array $context = []) {}
    public function log($level, $message, array $context = []) {}
  };

  $cache = new Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache($logger, [
    'max_entries' => 5,
    'default_ttl' => 3600,
    'cleanup_probability' => 0.0,
  ]);

  // Test basic operations
  $hash1 = hash('sha256', 'test1');
  $embedding1 = [1.0, 2.0, 3.0];

  $cache->set($hash1, $embedding1);
  $retrieved = $cache->get($hash1);

  if ($retrieved === $embedding1) {
    echo "  Basic operations: PASSED\n";
    $results->addTest('Cache Basic Operations', true, 1);
  } else {
    echo "  Basic operations: FAILED\n";
    $results->addTest('Cache Basic Operations', false, 1, 'Retrieved value did not match stored value');
  }

  // Test eviction
  $cache2 = new Drupal\search_api_postgresql\Cache\MemoryEmbeddingCache($logger, ['max_entries' => 2]);
  $hash1 = hash('sha256', 'evict1');
  $hash2 = hash('sha256', 'evict2');
  $hash3 = hash('sha256', 'evict3');

  $cache2->set($hash1, [1.0]);
  $cache2->set($hash2, [2.0]);
  $cache2->get($hash1); // Make hash1 recently used
  $cache2->set($hash3, [3.0]); // Should evict hash2

  if ($cache2->get($hash1) !== null && $cache2->get($hash2) === null && $cache2->get($hash3) !== null) {
    echo "  Eviction: PASSED\n";
    $results->addTest('Cache Eviction', true, 3);
  } else {
    echo "  Eviction: FAILED\n";
    $results->addTest('Cache Eviction', false, 3, 'LRU eviction not working correctly');
  }

} catch (Exception $e) {
  echo "  ERROR: " . $e->getMessage() . "\n";
  $results->addTest('Memory Embedding Cache', false, 0, $e->getMessage());
}

echo "\n";

// Test 2: Vector Data Type
echo "2. Testing Vector Data Type\n";
echo "============================\n";

try {
  require_once __DIR__ . '/../src/Plugin/search_api/data_type/Vector.php';

  $vector = new Drupal\search_api_postgresql\Plugin\search_api\data_type\Vector([], 'vector', []);

  // Test array input
  $result1 = $vector->getValue([1, 2, 3.5]);
  if ($result1 === [1.0, 2.0, 3.5]) {
    echo "  Array input: PASSED\n";
    $results->addTest('Vector Array Input', true, 1);
  } else {
    echo "  Array input: FAILED\n";
    $results->addTest('Vector Array Input', false, 1, 'Array conversion failed');
  }

  // Test string input
  $result2 = $vector->getValue("[1.0,2.0,3.5]");
  if ($result2 === [1.0, 2.0, 3.5]) {
    echo "  String input: PASSED\n";
    $results->addTest('Vector String Input', true, 1);
  } else {
    echo "  String input: FAILED\n";
    $results->addTest('Vector String Input', false, 1, 'String parsing failed');
  }

  // Test empty input
  $result3 = $vector->getValue("");
  if ($result3 === []) {
    echo "  Empty input: PASSED\n";
    $results->addTest('Vector Empty Input', true, 1);
  } else {
    echo "  Empty input: FAILED\n";
    $results->addTest('Vector Empty Input', false, 1, 'Empty input handling failed');
  }

} catch (Exception $e) {
  echo "  ERROR: " . $e->getMessage() . "\n";
  $results->addTest('Vector Data Type', false, 0, $e->getMessage());
}

echo "\n";

// Test 3: Module File Structure Validation
echo "3. Testing Module File Structure\n";
echo "=================================\n";

$requiredFiles = [
  'src/Cache/EmbeddingCacheInterface.php',
  'src/Cache/MemoryEmbeddingCache.php',
  'src/Cache/DatabaseEmbeddingCache.php',
  'src/Plugin/search_api/backend/PostgreSQLBackend.php',
  'src/Plugin/search_api/data_type/Vector.php',
  'src/PostgreSQL/PostgreSQLConnector.php',
  'search_api_postgresql.info.yml',
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
  if (!file_exists(__DIR__ . '/../' . $file)) {
    $missingFiles[] = $file;
  }
}

if (empty($missingFiles)) {
  echo "  File structure: PASSED\n";
  $results->addTest('Module File Structure', true, count($requiredFiles));
} else {
  echo "  File structure: FAILED - Missing files: " . implode(', ', $missingFiles) . "\n";
  $results->addTest('Module File Structure', false, count($requiredFiles), 'Missing required files');
}

echo "\n";

// Test 4: Code Syntax Validation
echo "4. Testing Code Syntax\n";
echo "=======================\n";

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
  if ($file->isFile() && $file->getExtension() === 'php') {
    $phpFiles[] = $file->getPathname();
  }
}

$syntaxErrors = [];
foreach ($phpFiles as $file) {
  $output = [];
  $returnCode = 0;
  exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnCode);

  if ($returnCode !== 0) {
    $syntaxErrors[] = basename($file) . ': ' . implode(' ', $output);
  }
}

if (empty($syntaxErrors)) {
  echo "  Syntax validation: PASSED (" . count($phpFiles) . " files checked)\n";
  $results->addTest('Code Syntax', true, count($phpFiles));
} else {
  echo "  Syntax validation: FAILED\n";
  foreach ($syntaxErrors as $error) {
    echo "    " . $error . "\n";
  }
  $results->addTest('Code Syntax', false, count($phpFiles), 'Syntax errors found');
}

echo "\n";

// Test 5: Configuration Validation
echo "5. Testing Configuration Files\n";
echo "===============================\n";

// Check module info file
$infoFile = __DIR__ . '/../search_api_postgresql.info.yml';
if (file_exists($infoFile)) {
  $infoContent = file_get_contents($infoFile);
  if (strpos($infoContent, 'name:') !== false && strpos($infoContent, 'type: module') !== false) {
    echo "  Module info file: PASSED\n";
    $results->addTest('Module Info File', true, 1);
  } else {
    echo "  Module info file: FAILED - Invalid format\n";
    $results->addTest('Module Info File', false, 1, 'Invalid module info format');
  }
} else {
  echo "  Module info file: FAILED - Missing\n";
  $results->addTest('Module Info File', false, 1, 'Module info file missing');
}

echo "\n";

// Final Summary
echo "Test Summary\n";
echo "============\n";

$summary = $results->getSummary();
echo "Total tests: {$summary['total']}\n";
echo "Passed: {$summary['passed']}\n";
echo "Failed: {$summary['failed']}\n";
echo "Total assertions: {$summary['assertions']}\n";

$failures = $results->getFailures();
if (!empty($failures)) {
  echo "\nFailures:\n";
  echo "---------\n";
  foreach ($failures as $failure) {
    echo "- {$failure['name']}: {$failure['message']}\n";
  }
}

echo "\nOverall Result: " . ($summary['failed'] === 0 ? "PASSED" : "FAILED") . "\n";

// Exit with appropriate code
exit($summary['failed'] === 0 ? 0 : 1);