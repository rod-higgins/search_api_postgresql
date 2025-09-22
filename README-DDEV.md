# DDEV Setup for Search API PostgreSQL + pgvector

This project is configured for local development with DDEV, including PostgreSQL with pgvector extension support.

## Quick Start

1. **Start the environment:**
   ```bash
   ddev start
   ```

2. **Setup the database with extensions:**
   ```bash
   ddev setup-database
   ```

3. **Install Drupal (if not already installed):**
   ```bash
   ddev composer create-project drupal/recommended-project:11.x tmp && cp -r tmp/. . && rm -rf tmp
   ddev composer require drush/drush
   ddev drush site:install --db-url=pgsql://db:db@db/db -y
   ```

4. **Install Search API modules:**
   ```bash
   ddev composer require drupal/search_api_postgresql drupal/key drupal/admin_toolbar
   ddev drush en search_api_postgresql search_api key admin_toolbar -y
   ```

## Available Commands

### PostgreSQL Commands
- `ddev psql` - Connect to PostgreSQL database
- `ddev pg-extensions` - Show available and installed extensions
- `ddev pg-performance` - Database performance metrics
- `ddev setup-database` - Initialize database with pgvector extensions

### pgvector Testing
- `ddev pgvector-test` - Comprehensive pgvector functionality test
- `ddev search-api-schema-test` - Test Search API table structure with vectors

### Search API Commands
- `ddev search-status` - Show Search API indexing status
- `ddev search-reindex` - Reset tracker and reindex all content
- `ddev search-clear` - Clear search indexes

## Services

- **Web**: Drupal 11 with PHP 8.3
- **Database**: PostgreSQL 16 with pgvector extension
- **Cache**: Redis 7
- **Mail**: Mailhog (accessible at `http://search-api-postgresql.ddev.site:8026`)

## Database Configuration

The PostgreSQL database is configured with:
- pgvector extension for vector similarity search
- pg_trgm for trigram matching
- btree_gin and btree_gist for indexing
- unaccent for accent-insensitive search
- Optimized settings for Search API performance

## End-to-End Testing

After setup, verify everything works:

1. **Test PostgreSQL version:**
   ```bash
   ddev psql -c "SELECT version();"
   ```

2. **Verify pgvector installation:**
   ```bash
   ddev pgvector-test
   ```

3. **Test Search API schema:**
   ```bash
   ddev search-api-schema-test
   ```

4. **Configure Search API:**
   - Visit `/admin/config/search/search-api`
   - Add server with PostgreSQL backend
   - Connection settings: Host=db, Port=5432, Database=db, User=db, Password=db

## Performance Monitoring

Monitor your setup with:
```bash
ddev pg-performance
```

This shows database size, active connections, and cache hit ratio.

## Troubleshooting

If you encounter issues:

1. Check database connectivity: `ddev psql -c "SELECT 1;"`
2. Verify extensions: `ddev pg-extensions`
3. Test pgvector: `ddev pgvector-test`
4. Check logs: `ddev logs`