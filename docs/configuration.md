# Configuration Guide

## Search Server Configuration

### Creating a PostgreSQL Search Server

1. Navigate to `/admin/config/search/search-api/add-server`
2. Configure the following settings:

**Basic Settings:**
- Server name: PostgreSQL Search Server
- Backend: PostgreSQL
- Description: PostgreSQL backend for advanced search features

**Database Connection:**
- Host: Your database host (use `db` for DDEV)
- Port: 5432 (default PostgreSQL port)
- Database: Your database name
- Username: Database username
- Password: Database password

**Advanced Settings:**
- Minimum characters: 3 (minimum search term length)
- Enable autocomplete: Yes
- Suggest words: Yes
- Suggest suffix: Yes

### Connection Testing

After configuring the server, test the connection:
- Save the configuration
- Check that server status shows "Available"
- If connection fails, verify database credentials and connectivity

## Index Configuration

### Creating a Search Index

1. Navigate to `/admin/config/search/search-api/add-index`
2. Configure index settings:

**Basic Information:**
- Index name: Content Index
- Machine name: content_index
- Data sources: Select appropriate content types

**Server Selection:**
- Choose your PostgreSQL server

**Index Settings:**
- Index items immediately: Recommended for development
- Track changes: Enable for automatic updates

### Field Configuration

Add fields to your index based on content requirements:

**Text Fields:**
- Type: Full text
- Boost: Set relevance weights (1.0 = normal, higher = more important)
- Processor: Configure text processing options

**Taxonomy Fields:**
- Type: String (for exact matching)
- Type: Full text (for searchable taxonomy terms)

**Date Fields:**
- Type: Date
- Processor: Configure date formatting

**Number Fields:**
- Type: Integer or Decimal
- Processor: Configure numeric processing

### Field Processors

Configure processors to enhance search functionality:

**Content Access:**
- Ensures search respects Drupal permissions
- Required for secure search implementation

**Rendered Item:**
- Indexes rendered content including formatted text
- Useful for comprehensive text search

**HTML Filter:**
- Strips HTML tags from content
- Prevents HTML from interfering with search

**Tokenizer:**
- Configures text tokenization
- Set word boundaries and special characters

**Stopwords:**
- Removes common words (the, and, or, etc.)
- Improves search relevance

**Stemmer:**
- Reduces words to root forms
- Improves matching for different word forms

## Data Types

### Full Text Fields

Configure full-text search fields for optimal performance:

**PostgreSQL Full-text Type:**
- Uses PostgreSQL's native full-text search
- Supports language-specific configurations
- Provides relevance ranking

**Configuration Options:**
- Language: Set appropriate text search configuration
- Stemming: Enable/disable word stemming
- Highlighting: Enable search result highlighting

### Vector Fields

For AI-powered semantic search:

**Vector Data Type:**
- Stores embedding vectors for semantic search
- Requires pgvector extension
- Configure vector dimensions (typically 1536 for OpenAI embeddings)

**Configuration:**
- Dimension: Set vector size
- Similarity threshold: Configure minimum similarity score
- Distance function: Choose similarity calculation method

### Facet Fields

Configure fields for faceted search:

**String Fields:**
- For exact matching facets
- Taxonomy terms, content types, etc.

**Numeric Fields:**
- For range-based facets
- Prices, dates, ratings, etc.

**Hierarchical Fields:**
- For taxonomy hierarchies
- Category structures, organizational hierarchies

## Search Processors

### Text Processing

**Ignore Case:**
- Makes search case-insensitive
- Recommended for most implementations

**Ignore Characters:**
- Removes punctuation and special characters
- Improves search matching

**Transliteration:**
- Converts accented characters to ASCII
- Improves cross-language search

### Content Processing

**Add URL:**
- Adds entity URLs to search results
- Enables direct linking to content

**Add Hierarchy:**
- Processes hierarchical relationships
- Useful for taxonomy-based search

**Content Access:**
- Filters results based on user permissions
- Essential for secure implementations

### Advanced Processing

**Language with Fallback:**
- Handles multilingual content
- Provides fallback for missing translations

**Aggregated Fields:**
- Combines multiple fields into single searchable field
- Useful for comprehensive search across field types

**Computed Fields:**
- Adds calculated values to search index
- Custom field processing logic

## Performance Configuration

### Database Optimization

**Index Strategy:**
- Automatic index creation for searched fields
- GIN indexes for full-text search
- HNSW indexes for vector similarity

**Query Optimization:**
- Query caching for repeated searches
- Connection pooling for high-traffic sites
- Prepared statements for security and performance

### Caching Configuration

**Search Result Caching:**
- Cache search results for improved performance
- Configure cache TTL based on content update frequency

**Index Caching:**
- Cache index metadata
- Reduces database queries for index operations

**Query Caching:**
- Cache parsed queries
- Improves performance for complex searches

## Security Configuration

### Access Control

**Content Access Processor:**
- Required for respecting Drupal permissions
- Filters search results based on user access

**User-specific Results:**
- Configure user-based result filtering
- Implement role-based search restrictions

### Credential Management

**Key Module Integration:**
- Store database credentials securely
- Use Key module for API keys and sensitive data

**Connection Security:**
- Use SSL/TLS for database connections
- Configure proper firewall rules

## Multilingual Configuration

### Language Support

**Text Search Configurations:**
- Configure language-specific text processing
- Support for multiple PostgreSQL text search configs

**Language Processing:**
- Configure language detection
- Set up language-specific stemming

**Content Language:**
- Handle multilingual content indexing
- Configure language fallbacks

### Stemming Configuration

**Language-specific Stemming:**
- English: english stemmer
- Spanish: spanish stemmer
- French: french stemmer
- German: german stemmer

**Custom Stemming:**
- Configure custom stemming rules
- Language-specific stop words

## Facets Integration

### Facet Configuration

**Facet Sources:**
- Map facets to indexed fields
- Configure facet hierarchy

**Facet Display:**
- Configure facet widget types
- Set up facet result formatting

**Facet Behavior:**
- Configure multi-select behavior
- Set up facet dependencies

### Performance Optimization

**Facet Caching:**
- Cache facet calculations
- Configure cache invalidation

**Lazy Loading:**
- Load facets on demand
- Improve initial page load performance

## Monitoring and Maintenance

### Index Management

**Index Status:**
- Monitor indexing progress
- Check for indexing errors

**Index Maintenance:**
- Schedule regular reindexing
- Monitor index size and performance

**Content Tracking:**
- Configure change tracking
- Set up automatic reindexing

### Performance Monitoring

**Query Performance:**
- Monitor search query execution time
- Identify slow queries

**Database Performance:**
- Monitor PostgreSQL performance metrics
- Track index usage and efficiency

**Resource Usage:**
- Monitor memory and CPU usage
- Configure resource limits

## Troubleshooting

### Common Configuration Issues

**Server Connection Problems:**
- Verify database credentials
- Check network connectivity
- Validate PostgreSQL configuration

**Indexing Failures:**
- Check field configuration
- Verify data types compatibility
- Review error logs

**Search Result Issues:**
- Verify processor configuration
- Check field mappings
- Test with simple queries

### Debug Configuration

**Enable Debug Logging:**
- Configure detailed logging
- Monitor search API operations

**Test Queries:**
- Use Drush commands for testing
- Validate search functionality

**Performance Analysis:**
- Use PostgreSQL EXPLAIN for query analysis
- Monitor index usage