# Azure AI Vector Search for Search API PostgreSQL

This enhanced version of the Search API PostgreSQL module adds **Azure AI-powered vector search capabilities**, enabling semantic search through Azure OpenAI embeddings combined with PostgreSQL's native full-text search.

## Overview

The Azure integration provides:
- **Semantic Search**: Understanding of meaning beyond exact keyword matches
- **Hybrid Search**: Combines traditional PostgreSQL FTS with AI vector similarity
- **Azure OpenAI Integration**: Uses Azure's enterprise-grade AI services
- **PostgreSQL pgvector**: Leverages native vector operations in PostgreSQL

## Prerequisites

### Azure Resources Required

1. **Azure Database for PostgreSQL Flexible Server**
   - PostgreSQL 13+ with pgvector extension enabled
   - Sufficient compute and memory for vector operations

2. **Azure OpenAI Service**
   - Deployed embedding model (text-embedding-ada-002 or newer)
   - API key and endpoint access

### Drupal Requirements

- Drupal 10.4+ or Drupal 11
- Search API module
- PDO PostgreSQL extension
- cURL extension (for Azure API calls)

## Installation

### 1. Enable pgvector Extension

**In Azure Portal:**
1. Go to your PostgreSQL server → Server parameters
2. Search for `shared_preload_libraries`
3. Add `vector` to the value
4. Restart the server
5. Connect to your database and run: `CREATE EXTENSION vector;`

**Or via Azure CLI:**
```bash
az postgres flexible-server parameter set \
  --resource-group myresourcegroup \
  --server-name myserver \
  --name shared_preload_libraries \
  --value 'vector'
```

### 2. Install the Module

```bash
composer require drupal/search_api_postgresql
drush en search_api_postgresql
```

### 3. Create Azure OpenAI Deployment

1. In Azure OpenAI Studio, create a new deployment
2. Choose an embedding model (recommended: `text-embedding-ada-002`)
3. Note your deployment name, endpoint, and API key

## Configuration

### 1. Create Search API Server

1. Navigate to `/admin/config/search/search-api`
2. Add a new server
3. Select **"PostgreSQL with Azure AI Vector Search"** as backend
4. Configure your settings:

### 2. Database Connection
```yaml
Host: myserver.postgres.database.azure.com
Port: 5432
Database: mydatabase
Username: myuser@myserver
Password: mypassword
SSL Mode: require  # Required for Azure Database
```

### 3. Azure AI Configuration
```yaml
Enable Azure AI Vector Search: ✓
Azure OpenAI Endpoint: https://myresource.openai.azure.com/
API Key: [your-azure-openai-key]
Deployment Name: my-embedding-deployment
Embedding Model: text-embedding-ada-002
```

### 4. Hybrid Search Settings
```yaml
Text Search Weight: 0.6    # Weight for PostgreSQL FTS
Vector Search Weight: 0.4  # Weight for Azure AI similarity
Similarity Threshold: 0.15 # Minimum similarity (0-1)
```

### 5. Performance Optimization
```yaml
Batch Embedding: ✓
Batch Size: 10
Rate Limit Delay: 100ms
Retry Attempts: 3
Vector Index Method: ivfflat  # Recommended for Azure Database
```

## Usage

### Search Modes

The module supports three search modes:

**1. Hybrid Search (Default)**
```php
$query = $index->query();
$query->keys('artificial intelligence machine learning');
// Uses both text matching AND semantic similarity
```

**2. Vector-Only Search**
```php
$query = $index->query();
$query->setOption('search_mode', 'vector_only');
$query->keys('AI and ML concepts');
// Pure semantic similarity search
```

**3. Text-Only Search**
```php
$query = $index->query();
$query->setOption('search_mode', 'text_only');
$query->keys('exact phrase matching');
// Traditional PostgreSQL full-text search
```

### Example: Semantic Product Search

```php
// Search for products using natural language
$query = $products_index->query();
$query->keys('comfortable running shoes for long distances');
$query->setOption('search_mode', 'hybrid');

$results = $query->execute();
// Will find products semantically related to comfort, running, 
// and endurance even without exact keyword matches
```

## Performance Considerations

### Vector Index Optimization

For optimal performance in Azure Database for PostgreSQL:

```sql
-- Monitor index usage
SELECT * FROM pg_stat_user_indexes WHERE relname LIKE '%search_api%';

-- Check vector index performance
EXPLAIN (ANALYZE, BUFFERS) 
SELECT * FROM search_api_myindex 
WHERE content_embedding <-> '[1,2,3...]' < 0.8;
```

### Embedding Generation

- **Initial indexing**: ~$0.0001 per 1K tokens (Azure OpenAI pricing)
- **Batch processing**: Reduces API calls and costs
- **Background processing**: Consider using queues for large datasets

### Storage Requirements

- **Vector storage**: ~6KB per item (1536 dimensions × 4 bytes)
- **Index overhead**: Additional 30-50% storage for vector indexes
- **Memory usage**: Plan for vector index memory requirements

## Monitoring and Troubleshooting

### Check Vector Statistics

```php
$backend = $server->getBackend();
$stats = $backend->getAzureVectorStats($index);

echo "Embedding coverage: " . $stats['embedding_coverage'] . "%\n";
echo "Total items: " . $stats['total_items'] . "\n";
echo "Vector dimension: " . $stats['vector_dimension'] . "\n";
```

### Common Issues

**1. pgvector Extension Not Available**
```
Error: pgvector extension is not available
Solution: Enable vector extension in Azure Database for PostgreSQL
```

**2. Azure API Rate Limits**
```
Error: HTTP 429 - Too Many Requests
Solution: Increase rate_limit_delay or reduce batch_size
```

**3. Low Embedding Coverage**
```
Issue: Only 50% of items have embeddings
Solution: Check Azure API connectivity and re-index content
```

### Debug Mode

Enable debug logging to troubleshoot issues:

```yaml
Debug Mode: ✓
```

This will log all database queries and Azure API calls.

## Cost Optimization

### Azure OpenAI Costs

- **Embedding generation**: ~$0.0001 per 1K tokens
- **Typical blog post**: ~500 words = $0.00005
- **10,000 articles**: ~$5-10 for initial indexing

### Optimization Strategies

1. **Batch Processing**: Reduce API calls
2. **Content Filtering**: Only embed searchable content
3. **Update Frequency**: Limit re-indexing frequency
4. **Query Caching**: Cache popular search embeddings

## Advanced Configuration

### Custom Embedding Models

For text-embedding-3-large (3072 dimensions):

```yaml
Embedding Model: text-embedding-3-large
Vector Dimension: 3072  # Automatically set
Vector Index Lists: 200  # Increase for larger datasets
```

### Multi-language Support

Configure PostgreSQL FTS for your language:

```yaml
FTS Configuration: french  # or german, spanish, etc.
```

The vector embeddings work across languages automatically.

### Custom Similarity Thresholds

Adjust based on your content:

```yaml
Similarity Threshold: 0.2   # Stricter (more similar results)
Similarity Threshold: 0.1   # Looser (more diverse results)
```

## Security

### Azure Security Best Practices

1. **Use Managed Identity**: When possible, avoid API keys
2. **Network Security**: Use private endpoints for Azure services
3. **Key Rotation**: Regularly rotate API keys
4. **Access Controls**: Limit Azure OpenAI access to necessary users

### Database Security

1. **SSL Connections**: Always use SSL for Azure Database connections
2. **Firewall Rules**: Restrict database access by IP
3. **User Permissions**: Use least-privilege database users

## Support and Resources

- **Azure OpenAI Documentation**: https://learn.microsoft.com/azure/cognitive-services/openai/
- **pgvector Documentation**: https://github.com/pgvector/pgvector
- **Azure Database for PostgreSQL**: https://learn.microsoft.com/azure/postgresql/
- **Drupal Issue Queue**: https://www.drupal.org/project/issues/search_api_postgresql

## Contributing

When contributing to the Azure vector search functionality:

1. Test with actual Azure services (not just mocks)
2. Consider cost implications of API calls in tests
3. Ensure compatibility with different Azure regions
4. Follow Azure API best practices for rate limiting and error handling