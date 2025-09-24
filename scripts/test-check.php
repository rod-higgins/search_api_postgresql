<?php

/**
 * @file
 * Comprehensive test validation script.
 */

function checkTestFile($file) {
  $content = file_get_contents($file);
  $issues = [];

  // Check for proper namespace
  if (!preg_match('/namespace\s+Drupal\\\\Tests\\\\search_api_postgresql\\\\(Unit|Kernel|Integration)/', $content)) {
    $issues[] = "Incorrect namespace";
  }

  // Check for proper @group annotation
  if (!preg_match('/@group\s+search_api_postgresql/', $content)) {
    $issues[] = "Missing @group search_api_postgresql annotation";
  }

  // Check for proper test class structure
  if (!preg_match('/class\s+\w+Test\s+extends/', $content)) {
    $issues[] = "Test class doesn't follow naming convention";
  }

  // Check for test methods
  preg_match_all('/public\s+function\s+(test\w+)/', $content, $matches);
  $test_methods = $matches[1];

  if (empty($test_methods)) {
    $issues[] = "No test methods found";
  }

  // Check for proper assertions in test methods
  foreach ($test_methods as $method) {
    if (!preg_match("/function\\s+" . preg_quote($method) . ".*?\\{.*?\\\$this->assert/s", $content)) {
      $issues[] = "Test method '$method' might not contain assertions";
    }
  }

  // Check for mock usage patterns
  if (preg_match('/createMock/', $content) && !preg_match('/setUp.*createMock/s', $content)) {
    // This is fine, just checking patterns
  }

  return [
    'methods' => $test_methods,
    'issues' => $issues
  ];
}

function main() {
  echo "Comprehensive Test Analysis\n";
  echo "===========================\n\n";

  $test_files = array_merge(
    glob('tests/src/Unit/*Test.php'),
    glob('tests/src/Kernel/*Test.php'),
    glob('tests/src/Integration/*Test.php')
  );

  $total_methods = 0;
  $total_issues = 0;

  foreach ($test_files as $file) {
    echo "Analyzing: " . basename($file) . "\n";
    $result = checkTestFile($file);

    echo "  Test methods: " . count($result['methods']) . "\n";
    if (!empty($result['methods'])) {
      foreach ($result['methods'] as $method) {
        echo "    - $method\n";
      }
    }

    if (!empty($result['issues'])) {
      echo "  Issues:\n";
      foreach ($result['issues'] as $issue) {
        echo "    - $issue\n";
      }
      $total_issues += count($result['issues']);
    } else {
      echo "  No issues found\n";
    }

    $total_methods += count($result['methods']);
    echo "\n";
  }

  echo "Summary:\n";
  echo "Total test files: " . count($test_files) . "\n";
  echo "Total test methods: $total_methods\n";
  echo "Total issues: $total_issues\n";

  if ($total_issues === 0) {
    echo "\nAll tests look good!\n";
  }
}

main();