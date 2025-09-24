<?php

/**
 * @file
 * Comprehensive test coverage analysis for search_api_postgresql module.
 */

function analyzeTestCoverage() {
  echo "Search API PostgreSQL - Comprehensive Test Coverage Analysis\n";
  echo "===========================================================\n\n";

  // Source directories to analyze
  $sourceDirs = [
    'src/Plugin/Backend',
    'src/Plugin/DataType',
    'src/Service',
    'src/Form',
    'src/Cache',
    'src/PostgreSQL',
  ];

  // Test directories
  $testDirs = [
    'tests/src/Unit',
    'tests/src/Kernel',
    'tests/src/Integration',
  ];

  $sourceFiles = [];
  $testFiles = [];
  $coverage = [];

  // Scan source files
  foreach ($sourceDirs as $dir) {
    if (is_dir($dir)) {
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
      );
      foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
          $sourceFiles[] = $file->getPathname();
        }
      }
    }
  }

  // Scan test files
  foreach ($testDirs as $dir) {
    if (is_dir($dir)) {
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
      );
      foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/Test\.php$/', $file->getFilename())) {
          $testFiles[] = $file->getPathname();
        }
      }
    }
  }

  echo "Source Files Analysis:\n";
  echo "----------------------\n";
  foreach ($sourceFiles as $file) {
    echo "$file\n";

    // Try to find corresponding test
    $testName = basename($file, '.php') . 'Test.php';
    $hasTest = false;
    foreach ($testFiles as $testFile) {
      if (strpos($testFile, $testName) !== false) {
        $hasTest = true;
        break;
      }
    }

    $coverage[$file] = $hasTest;
    if (!$hasTest) {
      echo "  No corresponding test found\n";
    }
  }

  echo "\nTest Files Analysis:\n";
  echo "--------------------\n";
  foreach ($testFiles as $file) {
    echo "$file\n";

    // Analyze test methods
    $content = file_get_contents($file);
    preg_match_all('/public\s+function\s+(test[A-Za-z0-9_]+)/', $content, $matches);
    $testMethods = $matches[1];

    echo "  Test methods: " . count($testMethods) . "\n";
    foreach ($testMethods as $method) {
      echo "    - $method\n";
    }

    // Check for @covers annotations
    if (preg_match_all('/@covers\s+::(\w+)/', $content, $coverMatches)) {
      echo "  Covers methods: " . implode(', ', $coverMatches[1]) . "\n";
    }

    echo "\n";
  }

  // Coverage summary
  $totalSources = count($sourceFiles);
  $coveredSources = array_sum($coverage);
  $coveragePercent = $totalSources > 0 ? round(($coveredSources / $totalSources) * 100, 1) : 0;

  echo "Coverage Summary:\n";
  echo "-----------------\n";
  echo "Total source files: $totalSources\n";
  echo "Files with tests: $coveredSources\n";
  echo "Files without tests: " . ($totalSources - $coveredSources) . "\n";
  echo "Coverage percentage: $coveragePercent%\n\n";

  // List files without tests
  echo "Files Missing Test Coverage:\n";
  echo "----------------------------\n";
  foreach ($coverage as $file => $hasCoverage) {
    if (!$hasCoverage) {
      echo "$file\n";
    }
  }

  // Test statistics
  $totalTestMethods = 0;
  foreach ($testFiles as $file) {
    $content = file_get_contents($file);
    preg_match_all('/public\s+function\s+test[A-Za-z0-9_]+/', $content, $matches);
    $totalTestMethods += count($matches[0]);
  }

  echo "\nTest Statistics:\n";
  echo "----------------\n";
  echo "Total test files: " . count($testFiles) . "\n";
  echo "Total test methods: $totalTestMethods\n";
  echo "Average test methods per file: " . round($totalTestMethods / max(count($testFiles), 1), 1) . "\n";

  // Quality metrics
  echo "\nQuality Metrics:\n";
  echo "----------------\n";

  $hasPhpunitXml = file_exists('phpunit.xml');
  echo "PHPUnit configuration: " . ($hasPhpunitXml ? "Present" : "Missing") . "\n";

  $hasTestRunner = file_exists('scripts/test-runner.php');
  echo "Test validation script: " . ($hasTestRunner ? "Present" : "Missing") . "\n";

  // Check test structure
  $properNamespaces = 0;
  $properAnnotations = 0;
  foreach ($testFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match('/namespace\s+Drupal\\\\Tests\\\\search_api_postgresql/', $content)) {
      $properNamespaces++;
    }
    if (preg_match('/@group\s+search_api_postgresql/', $content)) {
      $properAnnotations++;
    }
  }

  echo "Proper namespaces: $properNamespaces/" . count($testFiles) . "\n";
  echo "Proper @group annotations: $properAnnotations/" . count($testFiles) . "\n";

  echo "\nRecommendations:\n";
  echo "----------------\n";
  if ($coveragePercent < 80) {
    echo "• Increase test coverage to at least 80%\n";
  }
  if (!$hasPhpunitXml) {
    echo "• Add PHPUnit configuration file\n";
  }
  if ($properNamespaces < count($testFiles)) {
    echo "• Fix namespace declarations in test files\n";
  }
  if ($properAnnotations < count($testFiles)) {
    echo "• Add @group annotations to all test files\n";
  }

  echo "\nOverall Assessment: ";
  if ($coveragePercent >= 80 && $properNamespaces === count($testFiles) && $properAnnotations === count($testFiles)) {
    echo "EXCELLENT - Test suite is comprehensive and well-structured\n";
  } elseif ($coveragePercent >= 60) {
    echo "GOOD - Test coverage is adequate with room for improvement\n";
  } else {
    echo "NEEDS IMPROVEMENT - Test coverage needs significant enhancement\n";
  }
}

analyzeTestCoverage();