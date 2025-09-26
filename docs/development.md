# Development Guide

## Development Environment Setup

### DDEV Environment

This project includes a complete DDEV setup for local development:

**Starting Development:**
```bash
git clone <repository-url>
cd search_api_postgresql
ddev start
# Setup PostgreSQL extensions
ddev psql -c "CREATE EXTENSION IF NOT EXISTS vector;"
ddev psql -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
ddev psql -c "CREATE EXTENSION IF NOT EXISTS btree_gin;"
ddev psql -c "CREATE EXTENSION IF NOT EXISTS unaccent;"
```

**Environment Features:**
- Drupal 11 with PHP 8.3
- PostgreSQL 16 with pgvector extension
- Redis caching
- Automatic module symlinking for development

### Module Symlinks

The DDEV environment automatically creates symlinks between your module source and the Drupal installation:

**Symlinked Directories:**
- `src/` - Module source code
- `config/` - Configuration files
- `templates/` - Twig templates
- `tests/` - Test files
- `js/` - JavaScript files
- `css/` - CSS files

**Benefits:**
- Real-time code changes reflected immediately
- No manual copying required
- Works with IDEs and file watchers

## Code Structure

### Directory Organization

```
src/
├── Plugin/
│   └── search_api/
│       ├── backend/
│       │   └── SearchApiPostgresqlBackend.php
│       ├── processor/
│       │   └── PostgresqlProcessor.php
│       └── data_type/
│           └── PostgresqlDataType.php
├── Service/
│   ├── DatabaseService.php
│   ├── VectorService.php
│   └── EmbeddingService.php
├── Controller/
│   └── AdminController.php
└── EventSubscriber/
    └── SearchApiSubscriber.php
```

### Backend Plugin Development

The main backend plugin extends Search API's backend interface:

**Key Methods:**
- `indexItems()` - Index content into PostgreSQL
- `search()` - Execute search queries
- `deleteItems()` - Remove content from index
- `addIndex()` - Create new search index
- `removeIndex()` - Delete search index

**Custom Features:**
- Vector similarity search
- Full-text search optimization
- Facet query generation
- Result highlighting

### Data Type Plugins

Create custom data types for specialized content:

**Vector Data Type:**
- Handles AI embeddings
- Supports similarity searches
- Integrates with pgvector extension

**Full-text Data Type:**
- PostgreSQL-specific text search
- Language configuration support
- Stemming and tokenization

### Processor Plugins

Develop processors to enhance search functionality:

**Text Processors:**
- Custom tokenization
- Language-specific processing
- Content transformation

**Vector Processors:**
- Embedding generation
- Vector normalization
- Similarity calculation

## Database Integration

### Schema Management

The module automatically manages database schema:

**Table Creation:**
- Index-specific tables for each search index
- Optimized column types for search fields
- Automatic index creation for performance

**Schema Updates:**
- Handles field additions and removals
- Manages data type changes
- Preserves existing data during updates

### Query Building

**Full-text Queries:**
```php
$query = $this->database->select('search_index', 'si')
  ->fields('si', ['item_id', 'score'])
  ->condition(
    'fulltext_field',
    $this->database->quote($search_terms),
    'MATCHES'
  );
```

**Vector Queries:**
```php
$query = $this->database->select('search_index', 'si')
  ->fields('si', ['item_id'])
  ->where(
    'vector_field <-> :vector < :threshold',
    [':vector' => $vector, ':threshold' => $threshold]
  );
```

### Performance Optimization

**Index Management:**
- Automatic GIN index creation for full-text fields
- HNSW index creation for vector fields
- Composite indexes for complex queries

**Query Optimization:**
- Prepared statements for security
- Query result caching
- Connection pooling

## Testing

### Unit Tests

Write comprehensive unit tests for all components:

**Test Structure:**
```php
namespace Drupal\Tests\search_api_postgresql\Unit;

use Drupal\Tests\UnitTestCase;

class BackendTest extends UnitTestCase {

  public function testIndexCreation() {
    // Test index creation functionality
  }

  public function testSearchQuery() {
    // Test search query generation
  }
}
```

### Integration Tests

Test integration with Search API and Drupal core:

**Database Tests:**
- Test with real PostgreSQL database
- Verify index creation and data storage
- Test search functionality end-to-end

**Performance Tests:**
- Benchmark indexing performance
- Test search response times
- Verify memory usage

### DDEV Testing Commands

Use provided DDEV commands for testing:

```bash
# Test PostgreSQL extensions
ddev psql -c "SELECT * FROM pg_extension;"

# Test vector functionality
ddev exec "psql -d db -c \"SELECT '[1,2,3]'::vector <-> '[1,2,4]'::vector;\""

# Test search functionality
ddev drush search-api:index content_index
ddev drush search-api:search content_index "test query"
```

## Debugging

### Debug Configuration

Enable debug logging for development:

```php
// In settings.local.php
$config['system.logging']['error_level'] = 'verbose';
$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';
```

### Database Debugging

**Query Logging:**
```sql
-- Enable query logging in PostgreSQL
ALTER SYSTEM SET log_statement = 'all';
SELECT pg_reload_conf();
```

**Performance Analysis:**
```sql
-- Analyze query performance
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM search_index
WHERE fulltext_field @@ plainto_tsquery('english', 'search term');
```

### Search API Debugging

**Drush Commands:**
```bash
# Debug search index status
drush search-api:status

# Test server connection
drush search-api:server-status server_id

# Debug indexing process
drush search-api:index --batch-size=1 index_id
```

## Contributing

### Code Standards

Follow Drupal coding standards:

**PHP Standards:**
- PSR-4 autoloading
- Drupal coding standards
- Comprehensive documentation

**JavaScript Standards:**
- ES6+ syntax
- Drupal JavaScript standards
- JSDoc documentation

### Git Workflow

**Branch Naming:**
- `feature/description` - New features
- `bugfix/description` - Bug fixes
- `hotfix/description` - Critical fixes

**Commit Messages:**
- Clear, descriptive commit messages
- Reference issue numbers where applicable
- Follow conventional commit format

### Pull Request Process

**Before Submitting:**
1. Run all tests locally
2. Check code style compliance
3. Update documentation
4. Test with sample data

**Review Process:**
1. Automated testing on multiple environments
2. Code review by maintainers
3. Manual testing of new features
4. Documentation review

## Advanced Development

### Custom Search Features

**Semantic Search:**
- Integrate with AI services for embeddings
- Implement vector similarity algorithms
- Provide hybrid search capabilities

**Advanced Faceting:**
- Hierarchical facet support
- Dynamic facet generation
- Facet result optimization

**Multilingual Support:**
- Language-specific text processing
- Cross-language search capabilities
- Translation integration

### Performance Optimization

**Caching Strategies:**
- Implement result caching
- Cache embedding calculations
- Optimize query caching

**Scaling Considerations:**
- Database connection pooling
- Read replica support
- Distributed search capabilities

### Integration Development

**External Services:**
- AI/ML service integration
- Third-party search providers
- Analytics and monitoring tools

**Drupal Integration:**
- Views integration
- Panels/Layout Builder support
- Media and file search

## Deployment

### Production Deployment

**Environment Preparation:**
- Production PostgreSQL setup
- Extension installation
- Performance configuration

**Security Considerations:**
- Credential management
- Access control configuration
- SSL/TLS setup

**Monitoring Setup:**
- Performance monitoring
- Error tracking
- Usage analytics

### Performance Tuning

**Database Optimization:**
- Connection pooling
- Query optimization
- Index maintenance

**Application Optimization:**
- Caching configuration
- CDN integration
- Resource optimization

### Maintenance

**Regular Tasks:**
- Index maintenance
- Performance monitoring
- Security updates

**Backup Procedures:**
- Database backup strategies
- Configuration backup
- Recovery procedures