# Search API PostgreSQL

A comprehensive PostgreSQL backend for Drupal's Search API module, featuring native full-text search capabilities with **AI-powered vector search** and **semantic search** through Azure OpenAI and direct OpenAI integration.

## 🚀 Features

### Core Search Capabilities
- **Native PostgreSQL Full-Text Search**: Uses tsvector and GIN indexes for blazing-fast text search
- **AI-Powered Vector Search**: Semantic search using OpenAI embeddings with PostgreSQL pgvector
- **Hybrid Search**: Intelligently combines traditional text search with AI similarity search
- **Multi-language Support**: Configurable PostgreSQL text search configurations
- **Advanced Search Features**: Faceting, autocomplete, spell checking, and more

### AI & Vector Search
- **Multiple AI Providers**: Azure OpenAI Service and direct OpenAI API support
- **Multiple Embedding Models**: Support for text-embedding-ada-002, text-embedding-3-small, text-embedding-3-large
- **Vector Indexing**: IVFFlat and HNSW indexing methods optimized for different use cases
- **Intelligent Caching**: Database and memory-based embedding cache with compression
- **Graceful Degradation**: Automatic fallback to text search when AI services are unavailable

### Enterprise Features
- **Secure Credential Storage**: Uses Drupal Key module - no plain text passwords or API keys
- **Queue Processing**: Background embedding generation with batch processing
- **Performance Monitoring**: Real-time analytics, cost tracking, and performance metrics
- **Error Recovery**: Automatic error detection, classification, and recovery strategies
- **Circuit Breaker Pattern**: Protects against cascading failures
- **Horizontal Scaling**: Supports connection pooling and distributed processing

## 🔧 Requirements

### Core Requirements
- **Drupal**: 10.4+ or 11.x
- **PHP**: 8.1+ with PDO PostgreSQL extension
- **PostgreSQL**: 12+ (13+ recommended for Azure Database)
- **Drupal Modules**: Search API, Key module
- **PHP Extensions**: pdo_pgsql, curl, json

### For AI Features (Optional)
- **PostgreSQL pgvector extension**: Required for vector similarity search
- **Azure OpenAI Service** OR **OpenAI API access**: For embedding generation
- **Sufficient memory**: Vector operations are memory-intensive

## 📦 Installation

### 1. Install via Composer

```bash
composer require drupal/search_api_postgresql
```

### 2. Enable Required Modules

```bash
drush en search_api_postgresql search_api key
```

### 3. Install pgvector Extension (For AI Features)

**Azure Database for PostgreSQL:**
```bash
# Via Azure CLI
az postgres flexible-server parameter set \
  --resource-group myresourcegroup \
  --server-name myserver \
  --name shared_preload_libraries \
  --value 'vector'

# Restart server, then connect and run:
CREATE EXTENSION vector;
```

**Self-hosted PostgreSQL:**
```bash
# Install pgvector (varies by system)
git clone https://github.com/pgvector/pgvector.git
cd pgvector
make
make install

# Then in PostgreSQL:
CREATE EXTENSION vector;
```

## 🔐 Security-First Configuration

This module prioritizes security by requiring the Key module for all sensitive credentials.

### Step 1: Create Secure Keys

**Never store passwords or API keys in plain text!**

1. **Database Password Key**:
   - Navigate to `/admin/config/system/keys/add`
   - Create key: "PostgreSQL Database Password"
   - Choose secure provider (Environment, File, HashiCorp Vault, etc.)

2. **AI API Key** (if using AI features):
   - Create key: "OpenAI API Key" or "Azure OpenAI API Key"
   - Store your API key securely

### Step 2: Configure Search Server

1. **Create Server**:
   - Go to `/admin/config/search/search-api`
   - Add server
   - Choose backend:
     - **"PostgreSQL"** - Standard backend with optional AI
     - **"PostgreSQL with Azure AI Vector Search"** - Azure-optimized

2. **Database Connection**:
   ```yaml
   Host: your-db-host.com (or myserver.postgres.database.azure.com)
   Port: 5432
   Database: your_database
   Username: your_username
   Database Password Key: [Select your secure key]
   SSL Mode: require  # Recommended for production
   ```

## 🤖 AI Configuration Options

### Option 1: Azure OpenAI Service (Recommended for Enterprise)

**Prerequisites:**
- Azure OpenAI Service deployed
- Embedding model deployed (text-embedding-ada-002 or newer)

**Configuration:**
```yaml
Enable AI Text Embeddings: ✓
Azure AI Services Endpoint: https://yourservice.openai.azure.com/
Azure AI Services API Key: [Select your secure key]
Deployment Name: your-embedding-deployment
Embedding Model: text-embedding-ada-002
Vector Dimensions: 1536  # Auto-detected based on model
```

**Hybrid Search Settings:**
```yaml
Text Search Weight: 0.6    # Traditional PostgreSQL FTS
Vector Search Weight: 0.4  # AI similarity search
Similarity Threshold: 0.15 # Minimum similarity score (0-1)
```

### Option 2: Direct OpenAI API

**Prerequisites:**
- OpenAI API key

**Configuration:**
```yaml
Enable AI Text Embeddings: ✓
Service Provider: OpenAI Direct
API Key: [Select your secure key]
Model: text-embedding-3-small  # or text-embedding-3-large
Vector Dimensions: 1536  # or 3072 for text-embedding-3-large
```

## 🏗️ Backend Comparison

| Feature | PostgreSQL | PostgreSQL with Azure AI |
|---------|------------|-------------------------|
| **Full-text Search** | ✅ Native tsvector | ✅ Native tsvector |
| **Vector Search** | ✅ Optional | ✅ Optimized |
| **AI Provider** | Any (OpenAI, Azure) | Azure-optimized |
| **Hybrid Search** | ✅ Configurable | ✅ Advanced tuning |
| **Enterprise Features** | ✅ Full support | ✅ Azure-specific optimizations |
| **Best For** | Flexible deployments | Azure-first organizations |

## 🔍 Search Modes

### 1. Traditional Full-Text Search
```php
$query = $index->query();
$query->keys('search terms');
// Uses PostgreSQL tsvector matching
```

### 2. Vector Similarity Search
```php
$query = $index->query();
$query->setOption('search_mode', 'vector_only');
$query->keys('find content similar to this concept');
// Pure semantic similarity using AI embeddings
```

### 3. Hybrid Search (Default with AI enabled)
```php
$query = $index->query();
$query->keys('artificial intelligence machine learning');
// Combines text matching AND semantic similarity
// Results are ranked using both traditional relevance and AI similarity
```

## 🚀 Advanced Features

### Queue Processing

Enable background embedding generation for better performance:

```bash
# Enable queue processing
drush search-api-postgresql:queue-server my_server enable

# Process queue manually
drush search-api-postgresql:queue-process --max-items=100

# Check queue status
drush search-api-postgresql:queue-status
```

### Embedding Cache Management

```bash
# View cache statistics
drush search-api-postgresql:cache-stats my_server

# Clear embedding cache
drush search-api-postgresql:cache-clear my_server

# Perform cache maintenance
drush search-api-postgresql:cache-maintenance my_server
```

### Performance Monitoring

```bash
# View embedding statistics
drush search-api-postgresql:embedding-stats my_index

# Check vector support
drush search-api-postgresql:check-vector-support my_server

# Validate secure key configuration
drush search-api-postgresql:validate-keys my_server
```

## 📊 Performance Optimization

### Vector Index Configuration

**For Azure Database for PostgreSQL:**
```yaml
Vector Index Method: IVFFlat  # Better for Azure
IVFFlat Lists: 100           # Adjust based on data size
```

**For High-Performance Deployments:**
```yaml
Vector Index Method: HNSW    # Better recall
HNSW M: 16                   # Controls index build time vs search speed
HNSW ef_construction: 64     # Higher = better recall, slower build
```

### Caching Strategy

```yaml
Enable Embedding Caching: ✓
Cache Backend: database      # or 'memory' for speed
Cache TTL: 2592000          # 30 days
Max Cache Entries: 100000
Enable Compression: ✓        # Saves storage space
```

### Batch Processing

```yaml
Enable Queue Processing: ✓
Batch Threshold: 5           # Use batches for 5+ items
Batch Size: 10              # Items per API call
Rate Limit Delay: 100ms     # Respect API limits
```

## 💰 Cost Management

### Azure OpenAI Pricing (Approximate)
- **text-embedding-ada-002**: ~$0.0001 per 1K tokens
- **text-embedding-3-small**: ~$0.00002 per 1K tokens  
- **text-embedding-3-large**: ~$0.00013 per 1K tokens

### Cost Optimization Strategies

1. **Smart Caching**: Cache embeddings to avoid regeneration
2. **Batch Processing**: Reduce API call overhead
3. **Content Filtering**: Only embed searchable content
4. **Model Selection**: Choose appropriate model for your use case

### Example Costs
- **1,000 blog posts** (~500 words each): $0.25 - $5.00 one-time
- **10,000 product descriptions**: $2.50 - $50.00 one-time
- **Ongoing updates**: Depends on content change frequency

## 🔧 Drush Commands

### Server Management
```bash
# Test Azure AI connection
drush search-api-postgresql:test-ai my_server

# Check vector support
drush search-api-postgresql:check-vector-support my_server

# Validate key configuration
drush search-api-postgresql:validate-keys my_server
```

### Embedding Management
```bash
# Regenerate all embeddings for an index
drush search-api-postgresql:regenerate-embeddings my_index

# View embedding statistics
drush search-api-postgresql:embedding-stats my_index

# Queue bulk regeneration
drush search-api-postgresql:queue-regenerate my_index --batch-size=100
```

### Queue Operations
```bash
# View queue status
drush search-api-postgresql:queue-status

# Process queue with custom limits
drush search-api-postgresql:queue-process --max-items=50 --time-limit=120

# Enable/disable queue for a server
drush search-api-postgresql:queue-server my_server enable
drush search-api-postgresql:queue-server my_server disable

# Clear queue
drush search-api-postgresql:queue-clear
```

### Cache Management
```bash
# Show cache statistics
drush search-api-postgresql:cache-stats my_server

# Clear embedding cache
drush search-api-postgresql:cache-clear my_server

# Perform maintenance (cleanup expired entries)
drush search-api-postgresql:cache-maintenance my_server

# Warm up cache with popular content
drush search-api-postgresql:cache-warmup my_index --limit=100
```

## 📈 Analytics & Monitoring

Access detailed analytics at `/admin/config/search/search-api-postgresql/analytics`:

- **Cost Tracking**: API usage and costs over time
- **Performance Metrics**: Search latency, cache hit rates
- **Usage Patterns**: Query volume, embedding generation trends
- **Error Monitoring**: Degradation alerts and recovery actions

## 🛡️ Error Handling & Resilience

### Graceful Degradation

The module automatically handles service failures:

- **AI Service Down**: Falls back to traditional text search
- **Rate Limits**: Implements circuit breaker pattern
- **Partial Failures**: Continues with available results
- **Network Issues**: Automatic retry with exponential backoff

### Circuit Breaker

Protects against cascading failures:
- Automatically disables failing services
- Gradual recovery when services return
- Configurable failure thresholds
- Admin notifications for critical issues

## 🔍 Troubleshooting

### Common Issues

**1. pgvector Extension Missing**
```
Error: pgvector extension is not available
Solution: Install and enable pgvector in PostgreSQL
```

**2. Key Access Issues**
```
Error: Database password key 'my_key' not found
Solution: Create key at /admin/config/system/keys/add
```

**3. Azure API Connection Failures**
```
Error: HTTP 401 - Unauthorized
Solution: Verify API key and endpoint configuration
```

**4. Memory Issues During Indexing**
```
Error: Allowed memory size exhausted
Solution: Increase PHP memory_limit or enable queue processing
```

### Debug Mode

Enable debug logging for detailed troubleshooting:

```yaml
Debug Mode: ✓
```

This logs all database queries and API calls (without exposing credentials).

### Health Checks

```bash
# Comprehensive server health check
drush search-api-postgresql:health-check my_server

# Test specific components
drush search-api-postgresql:test-connection my_server
drush search-api-postgresql:test-ai my_server
```

## 🚀 Production Deployment

### Recommended Configuration

**For High-Traffic Sites:**
```yaml
# Database
SSL Mode: require
Connection Pooling: ✓
Max Connections: 10

# AI Features
Enable Queue Processing: ✓
Batch Size: 20
Cache TTL: 604800  # 7 days
Enable Compression: ✓

# Vector Index
Method: HNSW
HNSW M: 16
HNSW ef_construction: 64
```

**For Cost-Conscious Deployments:**
```yaml
# AI Features
Model: text-embedding-3-small  # Most cost-effective
Batch Size: 50                # Larger batches
Rate Limit Delay: 200ms       # Conservative API usage
Cache TTL: 2592000            # 30 days (longer cache)
```

### Security Checklist

- ✅ All credentials stored in Key module
- ✅ SSL enabled for database connections
- ✅ API keys rotated regularly
- ✅ Network access restricted
- ✅ Debug mode disabled in production
- ✅ Error logging configured
- ✅ Regular security updates applied

## 🤝 Support & Contributing

### Getting Help

- **Issue Queue**: [Drupal.org project page](https://www.drupal.org/project/search_api_postgresql)
- **Documentation**: [Module documentation](https://www.drupal.org/docs/contributed-modules/search-api-postgresql)
- **Azure Support**: [Azure OpenAI documentation](https://learn.microsoft.com/azure/cognitive-services/openai/)

### Contributing

Contributions welcome! Please:

1. Follow Drupal coding standards
2. Include tests for new functionality
3. Update documentation
4. Consider security implications

### Performance Testing

When contributing performance features:

1. Test with realistic data volumes (10K+ items)
2. Monitor memory usage during operations
3. Verify cache effectiveness
4. Test graceful degradation scenarios

## 📋 Migration Guide

### From Other Search Backends

1. **Export existing configuration**
2. **Create new PostgreSQL server**
3. **Re-index content** (embeddings generated automatically)
4. **Test search functionality**
5. **Update search forms** if needed

## 📄 License

GPL-2.0+

---

**Ready to supercharge your Drupal search with AI?** 🚀

Start with traditional PostgreSQL search and add AI features when you're ready. The module grows with your needs while maintaining security and performance.