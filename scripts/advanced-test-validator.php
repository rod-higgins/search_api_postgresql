<?php

/**
 * @file
 * Advanced test validator that checks for common issues.
 */

function validateAllTests() {
  echo "Advanced Test Validation\n";
  echo "========================\n\n";

  $testDirs = [
    'tests/src/Unit',
    'tests/src/Kernel',
    'tests/src/Integration',
  ];

  $issues = [];
  $totalFiles = 0;
  $totalMethods = 0;

  foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
      continue;
    }

    echo "Checking directory: $dir\n";

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile() && preg_match('/Test\.php$/', $file->getFilename())) {
        $totalFiles++;
        $filePath = $file->getPathname();
        echo "  Validating: $filePath\n";

        $content = file_get_contents($filePath);
        $fileIssues = [];

        // Check 1: Proper namespace
        if (!preg_match('/namespace\s+Drupal\\\\Tests\\\\search_api_postgresql\\\\/', $content)) {
          $fileIssues[] = "Missing or incorrect namespace";
        }

        // Check 2: Group annotation
        if (!preg_match('/@group\s+search_api_postgresql/', $content)) {
          $fileIssues[] = "Missing @group annotation";
        }

        // Check 3: Extends proper base class
        if (!preg_match('/extends\s+(UnitTestCase|KernelTestBase|BrowserTestBase)/', $content)) {
          $fileIssues[] = "Not extending proper test base class";
        }

        // Check 4: Has test methods
        preg_match_all('/public\s+function\s+test[A-Za-z0-9_]+/', $content, $testMatches);
        $methodCount = count($testMatches[0]);
        $totalMethods += $methodCount;

        if ($methodCount === 0) {
          $fileIssues[] = "No test methods found";
        }

        // Check 5: Has setUp method if needed
        $hasSetUp = preg_match('/protected\s+function\s+setUp/', $content);
        $hasProperties = preg_match('/protected\s+\$\w+/', $content);

        if ($hasProperties && !$hasSetUp) {
          $fileIssues[] = "Has properties but no setUp method";
        }

        // Check 6: Proper use statements
        $useStatements = [];
        preg_match_all('/use\s+([^;]+);/', $content, $useMatches);
        foreach ($useMatches[1] as $use) {
          $useStatements[] = trim($use);
        }

        // Check for common missing imports
        $needsAssertions = preg_match('/\$this->assert/', $content);
        $needsMockObject = preg_match('/createMock|getMockBuilder/', $content);

        // Check 7: Method documentation
        preg_match_all('/\/\*\*[\s\S]*?\*\/\s*public\s+function\s+test/', $content, $docMatches);
        $documentedMethods = count($docMatches[0]);

        if ($documentedMethods < $methodCount) {
          $fileIssues[] = "Some test methods lack documentation";
        }

        // Check 8: Assertion usage
        preg_match_all('/\$this->assert\w+/', $content, $assertMatches);
        $assertionCount = count($assertMatches[0]);

        if ($methodCount > 0 && $assertionCount === 0) {
          $fileIssues[] = "No assertions found in test methods";
        }

        // Check 9: Proper mock usage
        $mockUsage = preg_match_all('/createMock|getMockBuilder/', $content);
        $expectCalls = preg_match_all('/expects\(\$this->/', $content);

        // Check 10: Exception testing
        $expectsException = preg_match('/expectException/', $content);

        if ($fileIssues) {
          $issues[$filePath] = $fileIssues;
          echo "    Issues found:\n";
          foreach ($fileIssues as $issue) {
            echo "       - $issue\n";
          }
        } else {
          echo "    No issues found\n";
        }

        echo "    Methods: $methodCount, Assertions: $assertionCount\n";
      }
    }
    echo "\n";
  }

  // Summary
  echo "Validation Summary\n";
  echo "==================\n";
  echo "Total test files: $totalFiles\n";
  echo "Total test methods: $totalMethods\n";
  echo "Files with issues: " . count($issues) . "\n";
  echo "Clean files: " . ($totalFiles - count($issues)) . "\n\n";

  if ($issues) {
    echo "Files Requiring Attention:\n";
    echo "---------------------------\n";
    foreach ($issues as $file => $fileIssues) {
      echo "$file\n";
      foreach ($fileIssues as $issue) {
        echo "  • $issue\n";
      }
      echo "\n";
    }
  }

  // Quality score
  $cleanFiles = $totalFiles - count($issues);
  $qualityScore = $totalFiles > 0 ? round(($cleanFiles / $totalFiles) * 100, 1) : 0;

  echo "Quality Score: $qualityScore%\n";

  if ($qualityScore >= 90) {
    echo "EXCELLENT - Test suite quality is outstanding\n";
  } elseif ($qualityScore >= 75) {
    echo "GOOD - Test suite quality is solid\n";
  } elseif ($qualityScore >= 50) {
    echo "FAIR - Test suite needs some improvements\n";
  } else {
    echo "POOR - Test suite requires significant work\n";
  }

  return count($issues) === 0;
}

validateAllTests();