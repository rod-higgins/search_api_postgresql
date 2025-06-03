# Search API PostgreSQL

This module provides a PostgreSQL backend for the Search API module, leveraging PostgreSQL's native full-text search capabilities including tsvector and tsquery for optimal performance, with enhanced AI text embeddings support for semantic search and **secure credential storage**.

## Features

- **Native PostgreSQL Full-Text Search**: Uses tsvector and GIN indexes for fast searching
- **AI Text Embeddings**: Semantic search using Azure AI Services with vector similarity
- **Hybrid Search**: Combines traditional full-text search with vector similarity search
- **Azure Database Compatible**: Optimized for Azure Database for PostgreSQL
- **Secure Credential Storage**: Uses Drupal Key module for secure API key and password storage
- **Advanced Search Features**: Supports faceting, autocomplete, spell checking, and more
- **Multi-language Support**: Configurable text search configurations for different languages
- **Performance Optimized**: Efficient indexing and querying strategies

## Requirements

- Drupal 10.4+ or Drupal 11
- PostgreSQL 12+
- PHP PDO PostgreSQL extension
- Search API module
- **Key module** (required for secure credential storage)
- **For AI Embeddings**: pgvector extension for PostgreSQL

## Installation

1. Install via Composer:
   ```bash
   composer require drupal/search_api_postgresql
   ```

2. Enable the module and its dependencies:
   ```bash
   drush en search_api_postgresql key
   ```

3. **For AI Embeddings** (optional), install pgvector extension:
   ```sql
   CREATE EXTENSION vector;
   ```

## Security-First Configuration

This module requires the Key module for secure credential storage. **All passwords and API keys are stored securely using the Key module** instead of plain text in configuration.

### Step 1: Create Secure Keys

Before configuring the backend, create keys for your credentials:

1. **Database Password Key**:
   - Go to `/admin/config/system/keys/add`
   - Create a key named "PostgreSQL Database Password"
   - Choose appropriate key type and provider (e.g., Configuration, Environment, File)
   - Store your database password securely

2. **Azure AI API Key** (if using AI embeddings):
   - Create another key named "Azure AI API Key"
   - Store your Azure AI Services API key securely

### Step 2: Configure Search API Server

1. **Create a Search API Server**:
   - Go to `/admin/config/search/search-api`
   - Add server
   - Select "PostgreSQL" or "PostgreSQL with Azure AI Vector Search" as the backend

2. **Database Connection Settings**:
   - **Host**: Your PostgreSQL server hostname
   - **Port**: Usually 5432
   - **Database**: Your database name
   - **Username**: Your database username
   - **Database Password Key**: Select the key you created for the database password
   - **SSL Mode**: Recommended "require" for Azure Database

### AI Embeddings Configuration

1. **Enable AI Text Embeddings**:
   - Check "Enable AI Text Embeddings" in the backend configuration

2. **Azure AI Services Setup**:
   - **Endpoint**: Your Azure AI Services endpoint (e.g., `https://yourservice.openai.azure.com/`)
   - **Azure AI Services API Key**: Select the key you created for the API key
   - **Model**: Select embedding model (text-embedding-ada-002, text-embedding-3-small, etc.)
   - **Dimensions**: Vector dimensions (1536 for ada-002, configurable for newer models)

3. **Hybrid Search Settings**:
   - **Vector Weight**: Weight for vector similarity in hybrid search (0-1)
   - **Full-text Weight**: Weight for traditional search in hybrid search (0-1)
   - **Similarity Threshold**: Minimum similarity score for vector results

## Security Best Practices

### Key Storage Recommendations

1. **Production Environment**:
   - Use external key providers (Environment variables, HashiCorp Vault, etc.)
   - Never store credentials in configuration or database in plain text
   - Regularly rotate API keys and passwords

2. **Development Environment**:
   - Use Configuration key provider for development
   - Keep development keys separate from production

3. **Azure Security**:
   - Use Azure Managed Identity when possible
   - Configure network security groups to restrict database access
   - Enable SSL/TLS for all connections

### Key Management Commands

Use the provided Drush commands to validate your key configuration:

```bash
# Validate all keys for a server
drush search-api-postgresql:validate-keys my_server

# Test Azure AI connection using secure keys
drush search-api-postgresql:test-ai my_server

# Check vector support
drush search-api-postgresql:check-vector-support my_server
```

## Azure Database for PostgreSQL Setup

For Azure Database for PostgreSQL, use these recommended settings:

```
Host: myserver.postgres.database.azure.com
Port: 5432
SSL Mode: require
Username: myuser@myserver
```

### Installing pgvector on Azure

1. Connect to your Azure PostgreSQL server
2. Enable the vector extension:
   ```sql
   CREATE EXTENSION vector;
   ```

## Supported Features

### Traditional Search
- ✅ Full-text search with relevance ranking
- ✅ Faceted search
- ✅ Autocomplete suggestions  
- ✅ Spell checking
- ✅ Multi-language configurations
- ✅ Complex query conditions
- ✅ Sorting and pagination
- ✅ Random sorting

### AI-Enhanced Search
- ✅ Vector similarity search using embeddings
- ✅ Hybrid search (combining full-text and vector search)
- ✅ Semantic search capabilities
- ✅ Automatic embedding generation
- ✅ Configurable similarity thresholds
- ✅ Support for multiple embedding models

### Security Features
- ✅ Secure credential storage using Key module
- ✅ No plain text passwords or API keys in configuration
- ✅ Support for multiple key storage providers
- ✅ Credential validation and testing tools

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
| vector | VECTOR(n) | Vector embeddings for AI search |

## Search Modes

### Traditional Full-Text Search
Standard PostgreSQL full-text search using tsvector and tsquery.

### Vector Similarity Search
Semantic search using AI-generated embeddings and cosine similarity.

### Hybrid Search
Combines both traditional and vector search with configurable weights:
- Results are scored using both relevance and similarity
- Weights can be adjusted based on your content and use case
- Provides the best of both worlds: exact matches and semantic understanding

## Performance Tips

1. **Use GIN Indexes**: Automatically created for tsvector columns
2. **Vector Indexes**: HNSW or IVFFlat indexes for fast vector similarity
3. **Optimize Batch Size**: Adjust based on your content size
4. **Language Configuration**: Choose appropriate FTS configuration
5. **Embedding Batching**: Configure appropriate batch sizes for API calls
6. **Regular Maintenance**: Monitor index usage and performance

## Migration from Insecure Configuration

If you're upgrading from a version that stored credentials in plain text:

1. **Create keys** for all your existing credentials
2. **Update server configuration** to use the new key references
3. **Validate** the new configuration using the provided Drush commands
4. **Remove old plain text credentials** from any backups or configuration exports

The module will automatically detect if credentials are not properly secured and provide clear error messages.

## API Usage and Costs

When using AI embeddings:
- Monitor your Azure AI Services usage and costs
- Consider implementing caching for frequently searched content
- Use appropriate batch sizes to optimize API calls
- Text is automatically chunked for long content

## Development

### Running Tests

```bash
# Unit tests
./vendor/bin/phpunit modules/contrib/search_api_postgresql/tests/src/Unit/

# Kernel tests  
./vendor/bin/phpunit modules/contrib/search_api_postgresql/tests/src/Kernel/
```

### Debugging

Enable debug mode in the backend configuration to log:
- Database queries
- Embedding API calls
- Vector similarity calculations
- Key retrieval operations (without exposing actual key values)

## Troubleshooting

### Common Issues

1. **Key not found errors**:
   - Verify the key exists in `/admin/config/system/keys`
   - Check key permissions and provider configuration
   - Use `drush search-api-postgresql:validate-keys` to diagnose

2. **pgvector extension not found**:
   - Ensure pgvector is installed and enabled in PostgreSQL
   - Check that your user has permissions to create extensions

3. **Azure AI API errors**:
   - Verify your endpoint URL and API key using the test connection feature
   - Check your Azure AI Services quotas and limits
   - Ensure your deployment name matches the model configuration

### Security Issues

1. **Plain text credentials detected**:
   - Module will refuse to start with plain text credentials
   - Create appropriate keys and update configuration
   - Use the validation commands to verify setup

2. **Key decryption failures**:
   - Check key provider configuration
   - Verify key permissions and access
   - Review Drupal logs for detailed error messages

## Contributing

Please follow Drupal coding standards and include tests for new functionality. When contributing security-related features, ensure they follow security best practices.

## License

GPL-2.0+

## Support

- [Issue Queue](https://www.drupal.org/project/issues/search_api_postgresql)
- [Documentation](https://www.drupal.org/docs/contributed-modules/search-api-postgresql)
- [Azure AI Services Documentation](https://docs.microsoft.com/en-us/azure/cognitive-services/openai/)
- [pgvector Documentation](https://github.com/pgvector/pgvector)
- [Drupal Key Module](https://www.drupal.org/project/key)