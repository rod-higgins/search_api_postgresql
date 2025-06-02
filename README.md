# Search API PostgreSQL

This module provides a PostgreSQL backend for the Search API module, leveraging PostgreSQL's native full-text search capabilities including tsvector and tsquery for optimal performance.

## Features

- **Native PostgreSQL Full-Text Search**: Uses tsvector and GIN indexes for fast searching
- **Azure Database Compatible**: Optimized for Azure Database for PostgreSQL
- **Advanced Search Features**: Supports faceting, autocomplete, spell checking, and more
- **Multi-language Support**: Configurable text search configurations for different languages
- **Performance Optimized**: Efficient indexing and querying strategies

## Requirements

- Drupal 10.4+ or Drupal 11
- PostgreSQL 12+
- PHP PDO PostgreSQL extension
- Search API module

## Installation

1. Install via Composer:
   ```bash
   composer require drupal/search_api_postgresql
   ```

2. Enable the module:
   ```bash
   drush en search_api_postgresql
   ```

## Configuration

1. **Create a Search API Server**:
   - Go to `/admin/config/search/search-api`
   - Add server
   - Select "PostgreSQL" as the backend
   - Configure your database connection

2. **Database Connection Settings**:
   - **Host**: Your PostgreSQL server hostname
   - **Port**: Usually 5432
   - **Database**: Your database name
   - **Username/Password**: Database credentials
   - **SSL Mode**: Recommended "require" for Azure Database

3. **Advanced Settings**:
   - **FTS Configuration**: Language-specific stemming and stop words
   - **Index Prefix**: Table name prefix for search indexes
   - **Batch Size**: Items processed per indexing batch

## Azure Database for PostgreSQL Setup

For Azure Database for PostgreSQL, use these recommended settings:

```
Host: myserver.postgres.database.azure.com
Port: 5432
SSL Mode: require
Username: myuser@myserver
```

## Supported Features

- ✅ Full-text search with relevance ranking
- ✅ Faceted search
- ✅ Autocomplete suggestions  
- ✅ Spell checking
- ✅ Multi-language configurations
- ✅ Complex query conditions
- ✅ Sorting and pagination
- ✅ Random sorting

## Field Types

| Search API Type | PostgreSQL Type | Description |
|-----------------|-----------------|-------------|
| text | TEXT | Standard text content |
| string | VARCHAR(255) | Short string values |
| integer | INTEGER | Numeric integers |
| decimal | DECIMAL(10,2) | Decimal numbers |
| date | TIMESTAMP | Date/time values |
| boolean | BOOLEAN | True/false values |
| postgresql_fulltext | TEXT | Optimized for tsvector |

## Performance Tips

1. **Use GIN Indexes**: Automatically created for tsvector columns
2. **Optimize Batch Size**: Adjust based on your content size
3. **Language Configuration**: Choose appropriate FTS configuration
4. **Regular Maintenance**: Monitor index usage and performance

## Development

### Running Tests

```bash
# Unit tests
./vendor/bin/phpunit modules/contrib/search_api_postgresql/tests/src/Unit/

# Kernel tests  
./vendor/bin/phpunit modules/contrib/search_api_postgresql/tests/src/Kernel/
```

### Debugging

Enable debug mode in the backend configuration to log all database queries for troubleshooting.

## Contributing

Please follow Drupal coding standards and include tests for new functionality.

## License

GPL-2.0+

## Support

- [Issue Queue](https://www.drupal.org/project/issues/search_api_postgresql)
- [Documentation](https://www.drupal.org/docs/contributed-modules/search-api-postgresql)