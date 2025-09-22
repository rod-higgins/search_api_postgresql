# Search API PostgreSQL - Installation Guide

## Overview

This module provides a PostgreSQL-based search backend for Search API with full-text search capabilities, AI-powered vector search using Azure OpenAI, and advanced faceted search support compatible with Facets 3.0.

## Prerequisites

### System Requirements

- **Drupal**: 10.4+ or 11.x
- **PostgreSQL**: 13+ (recommended: 16+)
- **PHP**: 8.1+
- **Key module**: For secure credential storage (recommended)

### Required PostgreSQL Extensions

The following PostgreSQL extensions must be installed and enabled:

#### 1. pgvector Extension (Required for AI Search)

**Installation:**

```bash
# Ubuntu/Debian
sudo apt-get install postgresql-16-pgvector

# CentOS/RHEL
sudo yum install pgvector

# macOS with Homebrew
brew install pgvector

# Or compile from source
git clone https://github.com/pgvector/pgvector.git
cd pgvector
make
sudo make install
```

**Enable in PostgreSQL:**

```sql
-- Connect to your database as superuser
\c your_database_name
CREATE EXTENSION IF NOT EXISTS vector;

-- Verify installation
SELECT * FROM pg_extension WHERE extname = 'vector';
```

#### 2. pg_trgm Extension (Required for Fuzzy Search)

**Enable pg_trgm:**

```sql
-- Connect to your database as superuser
\c your_database_name
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Verify installation
SELECT * FROM pg_extension WHERE extname = 'pg_trgm';
```

#### 3. Full-Text Search Support (Built-in)

PostgreSQL's full-text search is built-in but you may want to install additional dictionaries:

```sql
-- Check available text search configurations
SELECT cfgname FROM pg_ts_config;

-- Common configurations include:
-- 'simple', 'english', 'spanish', 'french', 'german', etc.
```

### Lando Setup (Development Environment)

If using Lando for development, extensions are automatically set up:

```bash
lando start
lando setup-extensions  # Installs pgvector and other required extensions
lando test-pgvector     # Verify pgvector is working
```

## Module Installation

### 1. Install Dependencies

```bash
# Install the module and its dependencies
composer require drupal/search_api
composer require drupal/key  # Recommended for credential storage

# Install Facets 3.0 for faceted search (optional but recommended)
composer require drupal/facets
```

### 2. Enable Modules

```bash
# Enable required modules
drush en search_api key

# Enable the PostgreSQL backend module
drush en search_api_postgresql

# Enable Facets (optional)
drush en facets
```

### 3. Verify Installation

Check the module status page at `/admin/reports/status` for:

- PostgreSQL pgvector extension
- Key module (if installed)
- Analytics tables initialized
- Embedding cache configured

## Database Types Supported

The module provides the following native PostgreSQL data types:

### 1. PostgreSQL Full-text (`postgresql_fulltext`)

**Features:**
- Native PostgreSQL tsvector indexing
- Language-specific text search configurations (English, Spanish, French, German, etc.)
- Stemming and stop word filtering
- Phrase search support
- Fuzzy/similarity search using trigrams
- Field weighting (A=highest, B=high, C=medium, D=low)
- Search result highlighting with `ts_headline`
- Relevance scoring with `ts_rank`

**Configuration Options:**
- Text search configuration (language)
- Stemming enable/disable
- Minimum/maximum word length
- Phrase search support
- Fuzzy search support
- Field weights for different content types
- Highlighting parameters
- Ranking normalization options

### 2. Vector (`vector`)

**Features:**
- AI embedding storage using pgvector
- Semantic similarity search
- Support for various embedding dimensions (default: 1536 for text-embedding-ada-002)
- Cosine similarity and other distance functions
- Hybrid search (traditional + semantic)

**Supported Input Formats:**
- Float arrays: `[1.0, 2.0, 3.0]`
- PostgreSQL vector format: `[1.0,2.0,3.0]`
- Comma-separated strings: `"1.0,2.0,3.0"`

## Facets 3.0 Integration

The module is fully compatible with Drupal Facets 3.0+:

### Installation

```bash
composer require drupal/facets
drush en facets
```

### Features Supported

- **Standard facets**: Text, numeric, date, taxonomy term facets
- **Range facets**: Numeric and date range filtering
- **Hierarchical facets**: Taxonomy term hierarchies
- **Multi-value field facets**: Complex field structures
- **Facet operators**: AND/OR operations
- **Facet sorting**: By count, alphabetical, weight
- **Facet filtering**: Show/hide empty facets

### Configuration

1. Create a Search API server with PostgreSQL backend
2. Create and configure a search index
3. Go to `/admin/config/search/facets`
4. Create facets for your indexed fields
5. Configure facet display and behavior

The module automatically:
- Creates optimized indexes for faceted fields
- Handles complex multi-value field queries
- Manages facet result caching
- Provides lazy index creation for performance

## Azure OpenAI Integration Setup

### 1. Azure OpenAI Prerequisites

**Azure Requirements:**
- Active Azure subscription
- Azure OpenAI resource deployed
- Text embedding model deployed (e.g., `text-embedding-ada-002`)

**Get Configuration Details:**
- **Endpoint**: Your Azure OpenAI resource endpoint (e.g., `https://myresource.openai.azure.com/`)
- **API Key**: Found in Azure portal under "Keys and Endpoint"
- **Deployment Name**: The name you gave your embedding model deployment
- **API Version**: Current version (default: `2024-02-01`)

### 2. Configure Credentials (Recommended: Using Key Module)

**Create API Key:**
```bash
# Navigate to Key management
drush config:edit key.key.azure_openai_key
```

Or via UI at `/admin/config/system/keys`:

1. Add Key → "Configuration"
2. Key ID: `azure_openai_key`
3. Key Type: "Authentication"
4. Key Provider: "Configuration"
5. Key Value: Your Azure OpenAI API key

### 3. Search Server Configuration

**Create PostgreSQL Server:**

1. Go to `/admin/config/search/search-api/add-server`
2. Choose "PostgreSQL with AI Vector Search" backend
3. Configure database connection
4. Configure Azure OpenAI settings:
   - **Endpoint**: `https://your-resource.openai.azure.com/`
   - **API Key**: Select your configured key or enter directly
   - **Deployment Name**: Your embedding model deployment name
   - **API Version**: `2024-02-01` (or latest)
   - **Embedding Dimension**: `1536` (for text-embedding-ada-002)

**Backend Configuration Options:**
- **Performance Settings**:
  - Max concurrent requests: 5
  - Request timeout: 30 seconds
  - Retry attempts: 3
  - Cache TTL: 3600 seconds
- **Security Settings**:
  - Require HTTPS: Yes
  - Validate SSL: Yes
  - Rate limiting: 60 calls/minute
- **Vector Search**:
  - Enable semantic search: Yes
  - Similarity threshold: 0.7
  - Max results: 100

### 4. Index Configuration

**Create Search Index:**

1. Go to `/admin/config/search/search-api/add-index`
2. Select your PostgreSQL server
3. Add fields to index:
   - **Text fields**: Use `postgresql_fulltext` data type
   - **Vector fields**: Use `vector` data type for AI embeddings
   - **Facet fields**: Use appropriate data types (string, integer, etc.)

**Field Configuration Examples:**

```yaml
# Full-text field with PostgreSQL optimization
title:
  type: postgresql_fulltext
  configuration:
    text_search_config: english
    enable_stemming: true
    weight_title: A
    enable_highlighting: true

# Vector field for semantic search
content_embedding:
  type: vector
  configuration:
    dimension: 1536
    similarity_threshold: 0.7

# Facet-enabled fields
document_type:
  type: string
  # Will automatically support facets

created_date:
  type: date
  # Will automatically support date range facets
```

### 5. Test the Setup

**Test Database Connection:**
```bash
lando drush search-api:server-status your_server_id
```

**Test Vector Extension:**
```bash
lando test-pgvector
```

**Test Azure OpenAI Connection:**
```bash
lando drush search-api:test-backend your_server_id
```

**Generate Test Embeddings:**
```bash
lando drush search-api:index your_index_id
```

## Performance Optimization

### Database Optimization

**PostgreSQL Configuration:**

```sql
-- Increase shared_buffers for better performance
shared_buffers = 256MB

-- Configure work_mem for complex queries
work_mem = 4MB

-- Enable JIT compilation for better query performance
jit = on

-- Configure max_parallel_workers for vector operations
max_parallel_workers = 4
max_parallel_workers_per_gather = 2
```

**Vector-Specific Settings:**

```sql
-- Set vector memory settings
SET enable_seqscan = off;  -- For vector similarity queries
SET effective_cache_size = '4GB';  -- Adjust based on available RAM
```

### Index Optimization

**GIN Indexes for Full-text:**
```sql
-- Automatically created by the module
CREATE INDEX CONCURRENTLY idx_content_fulltext_gin
ON search_index USING gin(content_fulltext);
```

**Vector Indexes:**
```sql
-- HNSW index for fast vector similarity (created automatically)
CREATE INDEX CONCURRENTLY idx_content_vector_hnsw
ON search_index USING hnsw (content_vector vector_cosine_ops);
```

### Caching Configuration

**Configure Embedding Cache:**

```bash
drush config:edit search_api_postgresql.embedding_cache
```

Settings:
- **Default TTL**: 86400 seconds (24 hours)
- **Max entries**: 10000 cached embeddings
- **Cleanup probability**: 0.01 (1% chance per request)
- **Enable compression**: Yes

**Configure Redis (Production):**

```yaml
# settings.php
$settings['cache']['default'] = 'cache.backend.redis';
$settings['redis.connection']['interface'] = 'PhpRedis';
$settings['redis.connection']['host'] = 'localhost';
$settings['redis.connection']['port'] = 6379;
```

## Troubleshooting

### Common Issues

**1. pgvector Extension Not Found**

```bash
# Check if pgvector is installed
lando psql -c "SELECT * FROM pg_extension WHERE extname = 'vector';"

# If not found, install and enable
lando setup-extensions
```

**2. Azure OpenAI Connection Failures**

```bash
# Test connection manually
curl -H "api-key: YOUR_API_KEY" \
     -H "Content-Type: application/json" \
     -d '{"input": "test"}' \
     "https://your-resource.openai.azure.com/openai/deployments/your-deployment/embeddings?api-version=2024-02-01"
```

**3. Performance Issues**

```sql
-- Check index usage
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM search_index
WHERE content_vector <=> '[1,2,3]'::vector < 0.8;

-- Check for missing indexes
SELECT schemaname, tablename, attname, n_distinct, correlation
FROM pg_stats
WHERE tablename = 'search_index';
```

**4. Memory Issues with Large Embeddings**

```bash
# Increase PHP memory limit
echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/custom.ini

# Configure work_mem for PostgreSQL
echo "work_mem = 8MB" >> /var/lib/postgresql/data/postgresql.conf
```

### Debug Commands

```bash
# Check module status
drush pm:list | grep search_api_postgresql

# Verify database schema
lando drush sql:query "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'search_api%';"

# Test search functionality
lando drush search-api:index your_index_id
lando drush search-api:search your_index_id "test query"

# Check analytics data
lando drush sql:query "SELECT COUNT(*) as total_analytics FROM search_api_postgresql_analytics;"

# Monitor embedding cache
lando drush search-api:cache-stats
```

### Logs and Monitoring

**Check Drupal Logs:**
```bash
drush watchdog:show --filter="search_api_postgresql"
```

**Check PostgreSQL Logs:**
```bash
# Find PostgreSQL log location
lando psql -c "SHOW log_directory;"
lando psql -c "SHOW log_filename;"

# View recent logs
tail -f /var/log/postgresql/postgresql-16-main.log
```

**Analytics Dashboard:**

Visit `/admin/config/search/search-api-postgresql` for:
- Server status and health checks
- Embedding generation analytics
- Query performance metrics
- Cost tracking (API usage)
- Cache hit rates

## Production Deployment

### Security Checklist

- Use Key module for API credential storage
- Enable HTTPS for all API communications
- Configure proper database user permissions
- Set up firewall rules for database access
- Enable SSL for PostgreSQL connections
- Configure rate limiting for API calls
- Set up monitoring and alerting

### Performance Checklist

- Configure PostgreSQL for production workload
- Set up connection pooling (pgbouncer)
- Configure Redis for caching
- Enable Drupal performance modules (page cache, dynamic page cache)
- Set up CDN for static assets
- Configure proper index maintenance schedules
- Monitor query performance and optimize slow queries

### Backup Strategy

- Regular PostgreSQL backups including vector data
- Backup search index configurations
- Backup Azure OpenAI configurations (not keys)
- Test restoration procedures
- Document recovery procedures

## Advanced Configuration

### Custom Embedding Models

To use different embedding models:

1. Deploy your model in Azure OpenAI
2. Update server configuration with new deployment name and dimensions
3. Regenerate embeddings for existing content:

```bash
drush search-api:clear your_index_id
drush search-api:index your_index_id
```

### Multi-language Setup

Configure language-specific text search configurations:

```yaml
# For Spanish content
text_search_config: spanish
enable_stemming: true

# For German content
text_search_config: german
enable_stemming: true
```

### Hybrid Search Tuning

Balance traditional and semantic search:

```yaml
# In search configuration
vector_weight: 0.7        # Weight for semantic similarity
fulltext_weight: 0.3      # Weight for traditional full-text
similarity_threshold: 0.6  # Minimum similarity score
```

## Support and Resources

- **Drupal Community**: [Drupal.org Search API Group](https://www.drupal.org/project/search_api)
- **PostgreSQL Documentation**: [PostgreSQL Full-text Search](https://www.postgresql.org/docs/current/textsearch.html)
- **pgvector Documentation**: [pgvector GitHub](https://github.com/pgvector/pgvector)
- **Azure OpenAI Documentation**: [Azure OpenAI Service](https://docs.microsoft.com/en-us/azure/cognitive-services/openai/)

## License

This module is licensed under the GNU General Public License v2.0 or later.