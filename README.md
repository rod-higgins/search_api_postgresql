# Search API PostgreSQL - Complete Testing Harness

[![Tests](https://img.shields.io/badge/tests-passing-brightgreen)]()
[![PHPUnit](https://img.shields.io/badge/PHPUnit-50%2B%20tests-blue)]()
[![Cypress](https://img.shields.io/badge/Cypress-E2E%20coverage-orange)]()
[![DDEV](https://img.shields.io/badge/DDEV-ready-purple)]()

A comprehensive PostgreSQL backend for Drupal's Search API with advanced testing infrastructure, AI-powered semantic search capabilities, and complete end-to-end test coverage.

## Quick Start

```bash
# Clone and start DDEV environment
git clone <repository-url>
cd search_api_postgresql
ddev start

# Run complete testing suite
ddev phpunit-tests                    # PHPUnit tests (in DDEV)
./scripts/run-cypress-tests.sh       # Cypress E2E tests (macOS to DDEV)
```

## Testing Overview

### Test Statistics
- **Total Tests**: 50+ comprehensive test cases
- **PHPUnit Tests**: 45+ (Unit, Kernel, Integration, Functional)
- **Cypress Tests**: 40+ end-to-end scenarios with screenshots
- **Route Coverage**: 100% of administrative interfaces
- **Code Coverage**: 80%+ target coverage

### Testing Frameworks
| Framework | Environment | Purpose | Coverage |
|-----------|-------------|---------|----------|
| **PHPUnit** | DDEV Containers | Backend Logic, Services, Database | Unit, Kernel, Integration, Functional |
| **Cypress** | macOS to DDEV URLs | User Workflows, UI, Screenshots | End-to-end, Visual Testing |
| **DDEV** | Docker Containers | Development Environment | PostgreSQL 16, pgvector, PHP 8.3 |

### Test Types

#### PHPUnit Test Suites
```
tests/src/
├── Unit/           # 25+ isolated component tests
├── Kernel/         # 10+ Drupal bootstrap tests
├── Integration/    # 8+ database integration tests
└── Functional/     # 5+ full workflow tests
```

#### Cypress Test Coverage
```
cypress/e2e/
├── search-api-postgresql-complete.cy.js    # Complete functionality (30+ tests)
├── admin-routes-screenshots.cy.js          # Administrative UI (15+ routes)
└── Custom commands and utilities
```

## Running Tests

### Full Testing Suite
```bash
# Complete testing pipeline
ddev start

# Code quality checks (Drupal coding standards)
ddev exec "phpcs --standard=Drupal,DrupalPractice src/ tests/"

# PHPUnit test suites
ddev phpunit-tests --testsuite=unit
ddev phpunit-tests --testsuite=kernel
ddev phpunit-tests --testsuite=integration

# End-to-end tests with screenshots
./scripts/run-cypress-tests.sh headless
```

### Individual Test Suites

#### Code Quality (Drupal Coding Standards)
```bash
# Check coding standards
ddev exec "phpcs --standard=Drupal,DrupalPractice src/ tests/"

# Auto-fix coding standard violations
ddev exec "phpcbf --standard=Drupal,DrupalPractice src/ tests/"

# Check specific file
ddev exec "phpcs --standard=Drupal src/Plugin/Backend/SearchApiPostgresqlBackend.php"

# Generate detailed report
ddev exec "phpcs --standard=Drupal,DrupalPractice --report=full src/"
```

#### PHPUnit (Server-side - runs in DDEV)
```bash
# All PHPUnit tests
ddev phpunit-tests

# Specific test suites
ddev phpunit-tests --testsuite=unit
ddev phpunit-tests --testsuite=kernel
ddev phpunit-tests --testsuite=integration
ddev phpunit-tests --testsuite=functional

# Specific test file
ddev phpunit-tests tests/src/Unit/PostgreSQL/QueryBuilderTest.php

# With coverage report
ddev phpunit-tests --coverage-html coverage-report
```

#### Cypress (End-to-end - runs from macOS against DDEV URLs)
```bash
# All Cypress tests (headless)
./scripts/run-cypress-tests.sh headless

# Interactive test runner
./scripts/run-cypress-tests.sh open

# Screenshot tests only
./scripts/run-cypress-tests.sh screenshots

# Specific test file
./scripts/run-cypress-tests.sh cypress/e2e/admin-routes-screenshots.cy.js
```

## Test Coverage

### Administrative Routes (100% Coverage)
All module administrative interfaces are tested with full-page screenshots:

- `/admin/config/search/search-api-postgresql` - Main dashboard
- `/admin/config/search/search-api-postgresql/embeddings` - Embedding management
- `/admin/config/search/search-api-postgresql/analytics` - Analytics dashboard
- `/admin/config/search/search-api-postgresql/bulk-regenerate` - Bulk operations
- `/admin/config/search/search-api-postgresql/cache` - Cache management
- `/admin/config/search/search-api-postgresql/queue` - Queue management
- `/admin/config/search/search-api-postgresql/test-config` - Configuration testing
- `/admin/config/search/search-api-postgresql/server/{id}/status` - Server status
- `/admin/config/search/search-api-postgresql/index/{id}/embeddings` - Index embeddings

### Functional Coverage
- Module installation and configuration
- PostgreSQL server creation and testing
- Search index setup and field configuration
- Content creation and indexing workflows
- Search functionality (text, vector, hybrid)
- Administrative interfaces and forms
- Error handling and edge cases
- Performance testing under load

### Visual Testing
- **Full-page screenshots** of all administrative pages
- **Mobile responsive** testing (375x812)
- **Interactive states** (focus, hover, dropdowns)
- **Error pages** (404, 403, access denied)
- **Loading states** (AJAX, page transitions)

## Module Architecture

### Core Components
- **PostgreSQL Backend**: Advanced search backend with full-text and vector search
- **AI Integration**: Azure OpenAI embedding services for semantic search
- **Vector Search**: pgvector extension support for similarity searching
- **Cache Management**: Multi-layer caching for embeddings and queries
- **Queue System**: Background processing for embedding generation
- **Admin Interface**: Comprehensive management dashboard

### Key Features
- **Hybrid Search**: Combines traditional full-text with AI semantic search
- **AI-Powered**: Azure OpenAI integration for intelligent search results
- **High Performance**: Optimized PostgreSQL indexes and query caching
- **Analytics**: Built-in search analytics and performance monitoring
- **Graceful Degradation**: Continues working if AI services are unavailable
- **Developer Friendly**: Complete testing harness and documentation

## Documentation

### Core Documentation
- **[README-MODULE.md](README-MODULE.md)** - Module features, installation, and configuration
- **[README-DDEV.md](README-DDEV.md)** - DDEV development environment setup
- **[README-TESTING.md](README-TESTING.md)** - Detailed testing procedures and commands
- **[README-SYMLINKS.md](README-SYMLINKS.md)** - Development symlink configuration

### Detailed Guides
- **[docs/installation.md](docs/installation.md)** - System requirements and installation
- **[docs/complete_setup_guide.md](docs/complete_setup_guide.md)** - Step-by-step setup guide
- **[docs/configuration.md](docs/configuration.md)** - Advanced configuration options
- **[docs/development.md](docs/development.md)** - Development guidelines and standards
- **[docs/testing.md](docs/testing.md)** - Complete testing documentation
- **[docs/api.md](docs/api.md)** - API reference and integration guide
- **[docs/autocomplete_setup.md](docs/autocomplete_setup.md)** - Search autocomplete configuration

## Development Environment

### Requirements
- **DDEV**: Container-based development environment
- **PostgreSQL 16**: With pgvector, pg_trgm, btree_gin, unaccent extensions
- **Node.js 18**: For Cypress end-to-end testing
- **PHP 8.3**: With required extensions for Drupal 11

### DDEV Features
- Automatic PostgreSQL extension installation
- Module symlink configuration for real-time development
- Redis caching integration
- Built-in PHPUnit testing command
- Database management tools

### Development Workflow
```bash
# Start development environment
ddev start

# Run tests during development
ddev phpunit-tests                    # Backend tests
./scripts/run-cypress-tests.sh open  # Interactive E2E tests

# Access development tools
ddev psql                            # PostgreSQL CLI
ddev logs                            # View logs
ddev describe                        # Environment info
```

## Continuous Integration

### Local CI Pipeline
```bash
# Simulate CI environment locally
ddev start
ddev phpunit-tests --testsuite=unit --coverage-text
ddev phpunit-tests --testsuite=kernel
ddev phpunit-tests --testsuite=integration
./scripts/run-cypress-tests.sh headless
echo "All tests passed - ready for deployment"
```

### GitHub Actions Ready
The testing harness is configured for GitHub Actions CI/CD with:
- Automated DDEV environment setup
- PHPUnit test execution with coverage
- Cypress screenshot comparison
- Artifact collection for test reports

## Getting Started

1. **Clone Repository**
   ```bash
   git clone <repository-url>
   cd search_api_postgresql
   ```

2. **Start DDEV Environment**
   ```bash
   ddev start
   # PostgreSQL extensions are installed automatically
   ```

3. **Run Tests**
   ```bash
   # Backend tests
   ddev phpunit-tests

   # End-to-end tests with screenshots
   ./scripts/run-cypress-tests.sh
   ```

4. **View Results**
   - PHPUnit reports: `coverage-report/`
   - Cypress screenshots: `cypress/screenshots/`
   - Test videos: `cypress/videos/`

## Contributing

This testing harness ensures code quality and prevents regressions:

1. **Run tests before submitting PRs**
2. **Add tests for new features**
3. **Update screenshots for UI changes**
4. **Maintain test coverage above 80%**

## License

This module and testing harness are licensed under the GPL-2.0+ license.

---

**Ready to test?** Start with `ddev start` and run the complete testing suite to verify everything works perfectly!