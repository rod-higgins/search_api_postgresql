# Installation Guide

## System Requirements

- Drupal 10.4+ or 11.x
- PostgreSQL 13+ (recommended: 16+)
- PHP 8.1+
- pgvector extension for PostgreSQL

## Required PostgreSQL Extensions

### pgvector Extension

Install the pgvector extension for vector similarity search:

**Ubuntu/Debian:**
```bash
sudo apt-get install postgresql-16-pgvector
```

**CentOS/RHEL:**
```bash
sudo yum install pgvector
```

**macOS with Homebrew:**
```bash
brew install pgvector
```

**Enable in PostgreSQL:**
```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### Additional Extensions

Enable required PostgreSQL extensions:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS unaccent;
```

## Module Installation

### 1. Install Dependencies

```bash
composer require drupal/search_api
composer require drupal/key
```

### 2. Enable Modules

```bash
drush en search_api_postgresql search_api key -y
```

### 3. Verify Installation

Check the module status page at `/admin/reports/status` for:
- PostgreSQL pgvector extension
- Required PostgreSQL extensions
- Module configuration status

## DDEV Development Setup

For local development with DDEV:

### 1. Start Environment

```bash
ddev start
```

### 2. Setup Database Extensions

```bash
ddev drush php:script scripts/local-setup.php
```

### 3. Verify Setup

```bash
ddev psql -c "SELECT * FROM pg_extension WHERE extname = 'vector';"
```

## Configuration

### 1. Create Search Server

Navigate to `/admin/config/search/search-api/add-server` and:
- Choose "PostgreSQL" backend
- Configure database connection
- Set connection parameters

### 2. Database Connection Settings

For DDEV environments:
- Host: `db`
- Port: `5432`
- Database: `db`
- Username: `db`
- Password: `db`

For production environments, use your actual database credentials.

### 3. Test Connection

After saving the server configuration, verify the connection status shows as "Available".

## Post-Installation

### 1. Create Search Index

Navigate to `/admin/config/search/search-api/add-index` and:
- Select your PostgreSQL server
- Choose data sources to index
- Configure field mappings
- Enable the index

### 2. Index Content

```bash
drush search-api:index your_index_id
```

### 3. Verify Search Functionality

Create a search page or view to test the search functionality.

## Troubleshooting

### Common Issues

**pgvector extension not found:**
- Verify extension is installed on the PostgreSQL server
- Check PostgreSQL logs for installation errors

**Database connection fails:**
- Verify database credentials
- Check firewall settings
- Ensure PostgreSQL is accepting connections

**Indexing errors:**
- Check Drupal logs for specific error messages
- Verify field configurations
- Test with smaller content sets

### Debug Commands

```bash
# Check module status
drush pm:list | grep search_api_postgresql

# Test database connection
drush eval "\\Drupal::database()->query('SELECT 1')->fetchField();"

# Check search server status
drush search-api:server-status your_server_id
```

## Performance Optimization

### PostgreSQL Configuration

Optimize PostgreSQL settings for search workloads:

```sql
-- Increase shared_buffers
shared_buffers = 256MB

-- Configure work_mem for complex queries
work_mem = 4MB

-- Enable JIT compilation
jit = on
```

### Index Optimization

The module automatically creates optimized indexes for:
- Full-text search using GIN indexes
- Vector similarity using HNSW indexes
- Faceted fields using appropriate index types

### Caching

Configure caching for improved performance:
- Enable Drupal page cache
- Configure Redis for cache backend
- Use CDN for static assets

## Production Deployment

### Security Checklist

- Use Key module for credential storage
- Enable HTTPS for all connections
- Configure proper database user permissions
- Set up firewall rules
- Enable SSL for PostgreSQL connections

### Performance Checklist

- Configure PostgreSQL for production workload
- Set up connection pooling
- Configure caching layers
- Monitor query performance
- Set up backup procedures

### Monitoring

- Monitor database performance
- Track search query performance
- Set up error alerting
- Monitor resource usage