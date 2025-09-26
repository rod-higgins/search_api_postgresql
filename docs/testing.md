# Testing Guide

## Overview

The Search API PostgreSQL module includes comprehensive testing coverage with both PHPUnit tests (running in DDEV) and Cypress end-to-end tests (running from macOS against DDEV URLs).

## Testing Architecture

### PHPUnit Tests (Server-side)
- **Location**: `tests/src/`
- **Environment**: Runs within DDEV containers
- **Purpose**: Unit, Kernel, Integration, and Functional tests
- **Coverage**: Backend logic, services, database operations

### Cypress Tests (End-to-end)
- **Location**: `cypress/e2e/`
- **Environment**: Runs from macOS against DDEV URLs
- **Purpose**: Full user workflow testing with screenshots
- **Coverage**: UI, forms, admin routes, search functionality

## Quick Start

### Running All Tests
```bash
# Start DDEV environment
ddev start

# Run PHPUnit tests (in DDEV)
ddev phpunit-tests

# Run Cypress tests (from macOS)
./scripts/run-cypress-tests.sh
```

### Running Specific Tests
```bash
# PHPUnit - specific test suite
ddev phpunit-tests --testsuite=unit
ddev phpunit-tests --testsuite=kernel
ddev phpunit-tests --testsuite=integration

# Cypress - specific test file
./scripts/run-cypress-tests.sh cypress/e2e/search-api-postgresql-complete.cy.js
./scripts/run-cypress-tests.sh cypress/e2e/admin-routes-screenshots.cy.js

# Cypress - interactive mode
./scripts/run-cypress-tests.sh open
```

## Code Quality Testing

### Drupal Coding Standards

The project uses the standard Drupal coding standards provided by drupal/coder:

**Available Standards:**
- **Drupal**: Core Drupal coding standards
- **DrupalPractice**: Additional best practice rules

**Running Code Quality Checks:**
```bash
# Check all source code
ddev exec "phpcs --standard=Drupal,DrupalPractice src/ tests/"

# Check specific directories
ddev exec "phpcs --standard=Drupal src/"
ddev exec "phpcs --standard=DrupalPractice src/"

# Auto-fix violations
ddev exec "phpcbf --standard=Drupal,DrupalPractice src/ tests/"

# Generate detailed report
ddev exec "phpcs --standard=Drupal,DrupalPractice --report=full src/ tests/"

# Check for specific file types
ddev exec "phpcs --standard=Drupal --extensions=php,module,inc,install,test,profile,theme src/"
```

**Integration with Development:**
```bash
# Pre-commit hook example
ddev exec "phpcs --standard=Drupal,DrupalPractice src/ tests/ --report=summary"
echo "Code quality: $? (0 = pass, >0 = violations found)"
```

## PHPUnit Testing

### Test Structure
```
tests/src/
├── Unit/           # Unit tests (isolated, no Drupal bootstrap)
├── Kernel/         # Kernel tests (minimal Drupal bootstrap)
├── Integration/    # Integration tests (database operations)
└── Functional/     # Functional tests (full Drupal)
```

### Running PHPUnit Tests

**Basic Execution:**
```bash
ddev phpunit-tests
```

**With Code Coverage:**
```bash
ddev exec "cd /var/www/html/web/modules/contrib/search_api_postgresql && vendor/bin/phpunit --coverage-html coverage-report"
```

**Specific Test Classes:**
```bash
ddev phpunit-tests tests/src/Unit/PostgreSQL/QueryBuilderTest.php
ddev phpunit-tests --filter testSearchQuery
```

### Test Categories

**Unit Tests:**
- PostgreSQL components
- Service classes
- Exception handling
- Cache management
- Configuration validation

**Kernel Tests:**
- Database backend integration
- Queue worker functionality
- Drush commands
- Vector search operations

**Integration Tests:**
- End-to-end search workflows
- Cache integration
- Error recovery

**Functional Tests:**
- Admin forms
- Controller endpoints
- Full user workflows

## Cypress End-to-End Testing

### Test Files

**Main Test Suite:**
- `search-api-postgresql-complete.cy.js` - Complete functionality testing
- `admin-routes-screenshots.cy.js` - Administrative interface testing with screenshots

### Running Cypress Tests

**Headless Mode (CI/automated):**
```bash
./scripts/run-cypress-tests.sh headless
```

**Interactive Mode (development):**
```bash
./scripts/run-cypress-tests.sh open
```

**Screenshot Testing:**
```bash
./scripts/run-cypress-tests.sh screenshots
```

### Screenshot Capabilities

Cypress tests automatically capture:
- **Full-page screenshots** of all administrative routes
- **Mobile responsive** screenshots (375x812)
- **Interactive element** states (focus, hover, dropdowns)
- **Error pages** (404, 403, access denied)
- **Loading states** (AJAX, page loads)

Screenshots are saved to: `cypress/screenshots/`

### Test Coverage

**Administrative Routes Tested:**
- `/admin/config/search/search-api-postgresql` - Main dashboard
- `/admin/config/search/search-api-postgresql/embeddings` - Embedding management
- `/admin/config/search/search-api-postgresql/analytics` - Analytics dashboard
- `/admin/config/search/search-api-postgresql/bulk-regenerate` - Bulk operations
- `/admin/config/search/search-api-postgresql/cache` - Cache management
- `/admin/config/search/search-api-postgresql/queue` - Queue management
- `/admin/config/search/search-api-postgresql/test-config` - Configuration testing
- `/admin/config/search/search-api-postgresql/server/{id}/status` - Server status
- `/admin/config/search/search-api-postgresql/index/{id}/embeddings` - Index embeddings

**Search Functionality Tested:**
- Basic text search
- Multi-term queries
- Vector/semantic search (if configured)
- Search result highlighting
- Faceted search
- Autocomplete functionality
- Empty and invalid queries
- Performance under load

**User Workflows Tested:**
- Module installation and configuration
- PostgreSQL server creation
- Search index setup and field configuration
- Content creation and indexing
- Search view creation
- End-to-end search operations

## Continuous Integration

### Local CI Simulation
```bash
# Complete testing pipeline
ddev start

# Code quality checks
ddev exec "phpcs --standard=Drupal,DrupalPractice src/ tests/"

# PHPUnit tests
ddev phpunit-tests --testsuite=unit
ddev phpunit-tests --testsuite=kernel
ddev phpunit-tests --testsuite=integration

# End-to-end tests
./scripts/run-cypress-tests.sh headless
```

### GitHub Actions Integration
```yaml
# .github/workflows/testing.yml
name: Tests
on: [push, pull_request]

jobs:
  phpunit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup DDEV
        uses: ddev/github-action-setup-ddev@v1
      - name: Start DDEV
        run: ddev start
      - name: Run PHPUnit Tests
        run: ddev phpunit-tests

  cypress:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup DDEV
        uses: ddev/github-action-setup-ddev@v1
      - name: Start DDEV
        run: ddev start
      - name: Run Cypress Tests
        run: |
          npm install
          npx cypress run
```

## Performance Testing

### Database Performance
```bash
# Test with large datasets
ddev exec "cd /var/www/html && drush generate:content article 1000"
ddev exec "cd /var/www/html && drush search-api:index article_content"

# Run performance tests
ddev phpunit-tests tests/src/Integration/PerformanceTest.php
```

### Load Testing
```javascript
// Cypress load testing
describe('Load Testing', () => {
  it('should handle concurrent searches', () => {
    const searches = Array.from({length: 50}, (_, i) => `test-${i}`)
    searches.forEach(term => {
      cy.visit('/search')
      cy.performSearch(term)
    })
  })
})
```

## Test Data Management

### Sample Content Creation
```bash
# Create test articles
ddev exec "cd /var/www/html && drush generate:content article 20"

# Create taxonomy terms
ddev exec "cd /var/www/html && drush generate:taxonomy tags 50"
```

### Database Cleanup
```bash
# Reset search indexes
ddev exec "cd /var/www/html && drush search-api:clear"

# Clear test content
ddev exec "cd /var/www/html && drush entity:delete node --bundle=article"
```

## Debugging Tests

### PHPUnit Debugging
```bash
# Enable verbose output
ddev phpunit-tests --verbose

# Debug specific test
ddev phpunit-tests --debug tests/src/Unit/SpecificTest.php

# Print test output
ddev phpunit-tests --testdox --colors
```

### Cypress Debugging
```bash
# Run with browser visible
./scripts/run-cypress-tests.sh open

# Debug mode with console logs
npx cypress run --headed --no-exit

# Video recording (enabled by default)
# Videos saved to cypress/videos/
```

### DDEV Debugging
```bash
# Check DDEV logs
ddev logs

# Connect to database
ddev psql

# Check PHP logs
ddev logs | grep php

# Restart DDEV services
ddev restart
```

## Test Environment Configuration

### DDEV Test Settings
```yaml
# .ddev/config.yaml test overrides
hooks:
  post-start:
    - exec: "psql -d db -c 'CREATE EXTENSION IF NOT EXISTS vector;'"
    - exec: "psql -d db -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;'"
    - exec: "psql -d db -c 'CREATE EXTENSION IF NOT EXISTS btree_gin;'"
    - exec: "psql -d db -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'"
```

### Cypress Test Configuration
```javascript
// cypress.config.js
module.exports = defineConfig({
  e2e: {
    baseUrl: 'https://search-api-postgresql.ddev.site',
    video: false,
    screenshotOnRunFailure: true,
    defaultCommandTimeout: 10000,
    viewportWidth: 1280,
    viewportHeight: 720
  }
})
```

## Test Reports and Coverage

### Generating Reports
```bash
# PHPUnit coverage report
ddev phpunit-tests --coverage-html reports/phpunit-coverage

# Cypress test report
npx cypress run --reporter mochawesome

# Combined reporting
./scripts/generate-test-reports.sh
```

### Coverage Targets
- **PHPUnit Code Coverage**: Target 80%+
- **Cypress Route Coverage**: 100% of administrative routes
- **Integration Coverage**: All major workflows

## Troubleshooting

### Common Issues

**PHPUnit "Class not found" errors:**
```bash
ddev composer install
ddev phpunit-tests --bootstrap ../../web/core/tests/bootstrap.php
```

**Cypress connection timeouts:**
```bash
# Check DDEV is running
ddev describe

# Verify URL accessibility
curl -I https://search-api-postgresql.ddev.site
```

**Database connection failures:**
```bash
# Reset database
ddev restart

# Check PostgreSQL extensions
ddev psql -c "SELECT * FROM pg_extension;"
```

**Screenshot comparison failures:**
```bash
# Clear screenshot cache
rm -rf cypress/screenshots/

# Regenerate baseline screenshots
npx cypress run --spec "cypress/e2e/admin-routes-screenshots.cy.js"
```

## Best Practices

### PHPUnit Best Practices
- Use data providers for multiple test scenarios
- Mock external dependencies (Azure API, etc.)
- Test edge cases and error conditions
- Keep tests isolated and repeatable

### Cypress Best Practices
- Use custom commands for common operations
- Wait for elements properly (avoid cy.wait with fixed times)
- Take screenshots at key workflow points
- Test responsive design across viewports
- Clean up test data between runs

### Performance Considerations
- Run unit tests before integration tests
- Use database transactions for test isolation
- Limit screenshot frequency in CI
- Parallel test execution where possible

This comprehensive testing setup ensures the Search API PostgreSQL module is thoroughly validated across all functionality and user workflows.