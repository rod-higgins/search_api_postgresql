<?php

/**
 * @file
 * Simple test validation script.
 *
 * This script validates that test files have proper syntax and structure
 * without requiring a full Drupal environment.
 */

function validateTestFile($file) {
  echo "Validating: $file\n";

  // Check syntax
  $output = [];
  $return_code = 0;
  exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_code);

  if ($return_code !== 0) {
    echo "  SYNTAX ERROR: " . implode("\n", $output) . "\n";
    return false;
  }

  // Check basic structure
  $content = file_get_contents($file);

  // Check namespace
  if (!preg_match('/namespace\s+Drupal\\\\Tests\\\\search_api_postgresql/', $content)) {
    echo "  WARNING: Incorrect namespace\n";
  }

  // Check for test methods
  if (!preg_match('/public\s+function\s+test[A-Z]/', $content)) {
    echo "  WARNING: No test methods found\n";
  }

  // Check for proper docblocks
  if (!preg_match('/@group\s+search_api_postgresql/', $content)) {
    echo "  WARNING: Missing @group annotation\n";
  }

  echo "  SYNTAX OK\n";
  return true;
}

function main() {
  echo "Search API PostgreSQL Test Validation\n";
  echo "=====================================\n\n";

  $test_dirs = [
    'tests/src/Unit',
    'tests/src/Kernel',
    'tests/src/Integration',
  ];

  $all_valid = true;
  $total_tests = 0;

  foreach ($test_dirs as $dir) {
    if (!is_dir($dir)) {
      continue;
    }

    echo "Checking directory: $dir\n";

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($iterator as $file) {
      if ($file->isFile() && preg_match('/Test\.php$/', $file->getFilename())) {
        $files[] = $file->getPathname();
      }
    }
    foreach ($files as $file) {
      $total_tests++;
      if (!validateTestFile($file)) {
        $all_valid = false;
      }
    }

    echo "\n";
  }

  echo "Total test files checked: $total_tests\n";

  if ($all_valid) {
    echo "All tests have valid syntax!\n";
    exit(0);
  } else {
    echo "Some tests have issues.\n";
    exit(1);
  }
}

main();