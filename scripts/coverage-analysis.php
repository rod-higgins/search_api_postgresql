<?php

/**
 * @file
 * Comprehensive coverage analysis for search_api_postgresql module.
 */

echo "Search API PostgreSQL - Coverage Analysis\n";
echo "=========================================\n\n";

// Get all source files
$sourceFiles = [];
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator(__DIR__ . '/../src/')
);

foreach ($iterator as $file) {
  if ($file->isFile() && $file->getExtension() === 'php') {
    $relativePath = str_replace(__DIR__ . '/../', '', $file->getPathname());
    $sourceFiles[] = $relativePath;
  }
}

// Get all test files
$testFiles = [];
$testIterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator(__DIR__ . '/../tests/')
);

foreach ($testIterator as $file) {
  if ($file->isFile() && $file->getExtension() === 'php' && basename($file) !== 'bootstrap.php') {
    $relativePath = str_replace(__DIR__ . '/../', '', $file->getPathname());
    $testFiles[] = $relativePath;
  }
}

echo "Source Files Analysis\n";
echo "=====================\n";
echo "Total source files: " . count($sourceFiles) . "\n";
echo "Total test files: " . count($testFiles) . "\n\n";

// Analyze by category
$categories = [
  'Cache' => [],
  'Service' => [],
  'Plugin' => [],
  'Form' => [],
  'Controller' => [],
  'PostgreSQL' => [],
  'Exception' => [],
  'Config' => [],
  'Commands' => [],
  'Queue' => [],
  'CircuitBreaker' => [],
  'Traits' => [],
];

foreach ($sourceFiles as $file) {
  $category = explode('/', $file)[1] ?? 'Other';
  if (isset($categories[$category])) {
    $categories[$category][] = $file;
  } else {
    $categories['Other'][] = $file;
  }
}

// Analyze test coverage by category
$testCoverage = [];
foreach ($testFiles as $testFile) {
  // Extract the component being tested
  $testBasename = basename($testFile, '.php');
  $testName = str_replace('Test', '', $testBasename);

  // Try to match with source files
  foreach ($sourceFiles as $sourceFile) {
    $sourceBasename = basename($sourceFile, '.php');
    if (strpos($testFile, $sourceBasename) !== false ||
        strpos($sourceFile, $testName) !== false ||
        $sourceBasename === $testName) {
      $testCoverage[$sourceFile] = $testFile;
      break;
    }
  }
}

echo "Coverage Analysis by Category\n";
echo "=============================\n\n";

$totalCovered = 0;
$totalFiles = 0;

foreach ($categories as $category => $files) {
  if (empty($files)) continue;

  $coveredCount = 0;
  $uncoveredFiles = [];

  foreach ($files as $file) {
    $totalFiles++;
    if (isset($testCoverage[$file])) {
      $coveredCount++;
      $totalCovered++;
    } else {
      $uncoveredFiles[] = $file;
    }
  }

  $coverage = count($files) > 0 ? round(($coveredCount / count($files)) * 100, 1) : 0;

  echo "{$category}/\n";
  echo "   Files: " . count($files) . " | Covered: {$coveredCount} | Coverage: {$coverage}%\n";

  if (!empty($uncoveredFiles)) {
    echo "   [WARNING]  Missing tests:\n";
    foreach ($uncoveredFiles as $file) {
      echo "      - " . basename($file) . "\n";
    }
  }
  echo "\n";
}

$overallCoverage = $totalFiles > 0 ? round(($totalCovered / $totalFiles) * 100, 1) : 0;

echo "Overall Coverage Summary\n";
echo "========================\n";
echo "Total files: {$totalFiles}\n";
echo "Covered files: {$totalCovered}\n";
echo "Coverage: {$overallCoverage}%\n\n";

// Critical missing tests
echo "Critical Missing Tests (High Priority)\n";
echo "======================================\n";

$criticalMissing = [
  'src/Controller/EmbeddingAdminController.php',
  'src/Service/AzureOpenAIEmbeddingService.php',
  'src/Service/ConfigurationValidationService.php',
  'src/Service/ErrorRecoveryService.php',
  'src/Commands/SearchApiPostgreSQLCommands.php',
  'src/Queue/EmbeddingQueueManager.php',
  'src/Plugin/QueueWorker/EmbeddingWorker.php',
  'src/Config/SearchApiPostgresqlConfig.php',
];

foreach ($criticalMissing as $file) {
  if (in_array($file, $sourceFiles) && !isset($testCoverage[$file])) {
    echo "[HIGH] MISSING: " . basename($file) . " (in " . dirname($file) . ")\n";
  }
}

echo "\nTest Files Needed\n";
echo "=================\n";

$neededTests = [];
foreach ($sourceFiles as $file) {
  if (!isset($testCoverage[$file])) {
    $category = explode('/', $file)[1];
    $className = basename($file, '.php');

    // Determine test type and location
    $testType = 'Unit';
    $testPath = '';

    switch ($category) {
      case 'Controller':
      case 'Form':
        $testType = 'Functional';
        $testPath = "tests/src/Functional/{$className}Test.php";
        break;
      case 'Plugin':
        $testType = 'Kernel';
        $subDir = str_replace(['src/Plugin/', '/'], ['', '/'], dirname($file));
        $testPath = "tests/src/Kernel/{$subDir}/{$className}Test.php";
        break;
      case 'Commands':
        $testType = 'Kernel';
        $testPath = "tests/src/Kernel/Commands/{$className}Test.php";
        break;
      case 'Queue':
        $testType = 'Kernel';
        $testPath = "tests/src/Kernel/Queue/{$className}Test.php";
        break;
      default:
        $testPath = "tests/src/Unit/{$category}/{$className}Test.php";
    }

    $neededTests[] = [
      'source' => $file,
      'test_path' => $testPath,
      'test_type' => $testType,
      'priority' => in_array($file, $criticalMissing) ? 'HIGH' : 'MEDIUM'
    ];
  }
}

// Sort by priority
usort($neededTests, function($a, $b) {
  if ($a['priority'] === 'HIGH' && $b['priority'] !== 'HIGH') return -1;
  if ($a['priority'] !== 'HIGH' && $b['priority'] === 'HIGH') return 1;
  return strcmp($a['source'], $b['source']);
});

foreach ($neededTests as $test) {
  $priority = $test['priority'] === 'HIGH' ? '[HIGH]' : '[MEDIUM]';
  echo "{$priority} {$test['test_type']}: {$test['test_path']}\n";
  echo "    └─ Tests: {$test['source']}\n\n";
}

echo "Next Steps\n";
echo "==========\n";
echo "1. Create " . count($neededTests) . " missing test files\n";
echo "2. Focus on HIGH priority tests first\n";
echo "3. Target 90%+ coverage (currently {$overallCoverage}%)\n";
echo "4. Add integration tests for workflows\n";
echo "5. Add route testing for controllers\n\n";

// Check for configuration files
echo "Configuration & Routes Analysis\n";
echo "===============================\n";

$configFiles = [
  'search_api_postgresql.info.yml',
  'search_api_postgresql.services.yml',
  'search_api_postgresql.routing.yml',
  'search_api_postgresql.permissions.yml',
  'config/install/search_api_postgresql.settings.yml',
  'config/schema/search_api_postgresql.schema.yml',
];

foreach ($configFiles as $configFile) {
  if (file_exists(__DIR__ . '/../' . $configFile)) {
    echo "[PASS] Found: {$configFile}\n";
  } else {
    echo "[FAIL] Missing: {$configFile}\n";
  }
}

echo "\nCoverage improvement recommendations:\n";
echo "- Add route testing for admin controllers\n";
echo "- Add configuration validation tests\n";
echo "- Add service integration tests\n";
echo "- Add permission and access control tests\n";
echo "- Add CLI command tests\n";
echo "- Add queue worker tests\n";