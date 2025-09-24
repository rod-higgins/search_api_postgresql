# API Reference

## Backend Plugin API

### SearchApiPostgresqlBackend

The main backend plugin that provides PostgreSQL search functionality.

#### Public Methods

**indexItems(IndexInterface $index, array $items)**
- Indexes items into the PostgreSQL database
- Parameters:
  - `$index`: The search index
  - `$items`: Array of items to index
- Returns: Array of successfully indexed item IDs

**search(QueryInterface $query)**
- Executes a search query against the PostgreSQL backend
- Parameters:
  - `$query`: The search query object
- Returns: ResultSetInterface with search results

**deleteItems(IndexInterface $index, array $item_ids)**
- Removes items from the search index
- Parameters:
  - `$index`: The search index
  - `$item_ids`: Array of item IDs to remove
- Returns: void

**addIndex(IndexInterface $index)**
- Creates database tables and indexes for a new search index
- Parameters:
  - `$index`: The search index to create
- Returns: void

**removeIndex(IndexInterface $index)**
- Removes database tables for a search index
- Parameters:
  - `$index`: The search index to remove
- Returns: void

**updateIndex(IndexInterface $index)**
- Updates database schema when index configuration changes
- Parameters:
  - `$index`: The updated search index
- Returns: void

#### Protected Methods

**createTable(IndexInterface $index)**
- Creates the main search table for an index
- Internal method for schema management

**getTableName(IndexInterface $index)**
- Generates the table name for a search index
- Returns: string table name

**buildSearchQuery(QueryInterface $query)**
- Builds the PostgreSQL query from Search API query
- Returns: SelectInterface database query

## Data Type Plugins

### PostgresqlFullTextDataType

Provides PostgreSQL-specific full-text search functionality.

#### Methods

**getValue($value)**
- Converts input value to tsvector format
- Parameters:
  - `$value`: Input text value
- Returns: string PostgreSQL tsvector representation

**createColumn(FieldInterface $field)**
- Creates database column definition for full-text field
- Parameters:
  - `$field`: Search API field
- Returns: array column specification

### VectorDataType

Handles vector embeddings for semantic search.

#### Methods

**getValue($value)**
- Converts input to vector format
- Parameters:
  - `$value`: Input vector data (array or string)
- Returns: string PostgreSQL vector representation

**validateValue($value)**
- Validates vector data format and dimensions
- Parameters:
  - `$value`: Vector data to validate
- Returns: bool validation result

## Service Classes

### DatabaseService

Manages database operations for search functionality.

#### Methods

**createSearchTable(IndexInterface $index)**
- Creates optimized search table for index
- Parameters:
  - `$index`: Search index configuration
- Returns: void

**createIndexes(IndexInterface $index)**
- Creates PostgreSQL indexes for search performance
- Parameters:
  - `$index`: Search index configuration
- Returns: void

**insertItems(IndexInterface $index, array $items)**
- Bulk insert indexed items into database
- Parameters:
  - `$index`: Target search index
  - `$items`: Items to insert
- Returns: int number of inserted items

**searchItems(QueryInterface $query)**
- Execute search query against database
- Parameters:
  - `$query`: Search query object
- Returns: array search results

### VectorService

Handles vector operations and similarity calculations.

#### Methods

**generateEmbedding(string $text)**
- Generates vector embedding for text content
- Parameters:
  - `$text`: Input text content
- Returns: array vector embedding

**calculateSimilarity(array $vector1, array $vector2)**
- Calculates similarity between two vectors
- Parameters:
  - `$vector1`: First vector
  - `$vector2`: Second vector
- Returns: float similarity score (0-1)

**normalizeVector(array $vector)**
- Normalizes vector to unit length
- Parameters:
  - `$vector`: Input vector
- Returns: array normalized vector

### EmbeddingService

Manages AI embedding generation and caching.

#### Methods

**getEmbedding(string $content)**
- Gets or generates embedding for content
- Parameters:
  - `$content`: Text content to embed
- Returns: array vector embedding

**cacheEmbedding(string $content, array $embedding)**
- Caches generated embedding
- Parameters:
  - `$content`: Original text content
  - `$embedding`: Generated vector embedding
- Returns: void

**clearCache()**
- Clears embedding cache
- Returns: void

## Query Building API

### Query Conditions

**Full-text Search:**
```php
$query->addCondition('title', 'search terms', '=');
$query->setFulltextFields(['title', 'body']);
```

**Vector Similarity:**
```php
$query->addCondition('content_vector', $vector, 'SIMILAR');
$query->setOption('similarity_threshold', 0.8);
```

**Range Queries:**
```php
$query->addCondition('created', ['2023-01-01', '2023-12-31'], 'BETWEEN');
```

**Facet Queries:**
```php
$query->addCondition('category', ['news', 'events'], 'IN');
```

### Query Options

**Sorting:**
```php
$query->setOption('sort', [
  'relevance' => QueryInterface::SORT_DESC,
  'created' => QueryInterface::SORT_DESC,
]);
```

**Pagination:**
```php
$query->setOption('limit', 20);
$query->setOption('offset', 40);
```

**Highlighting:**
```php
$query->setOption('highlight', [
  'fields' => ['title', 'body'],
  'prefix' => '<mark>',
  'suffix' => '</mark>',
]);
```

## Event System

### Search API Events

The module dispatches and subscribes to various Search API events:

**SEARCH_API_ITEM_INDEXED:**
- Fired when items are successfully indexed
- Event data: IndexInterface, array of item IDs

**SEARCH_API_ITEMS_DELETED:**
- Fired when items are removed from index
- Event data: IndexInterface, array of item IDs

**SEARCH_API_QUERY_PRE_EXECUTE:**
- Fired before query execution
- Event data: QueryInterface
- Allows query modification

**SEARCH_API_RESULTS_ALTERED:**
- Fired after results are generated
- Event data: ResultSetInterface
- Allows result modification

### Custom Events

**POSTGRESQL_VECTOR_GENERATED:**
- Fired when vector embedding is generated
- Event data: content string, vector array

**POSTGRESQL_INDEX_CREATED:**
- Fired when PostgreSQL index is created
- Event data: IndexInterface, table name

## Configuration API

### Server Configuration

**Database Settings:**
```php
$config = [
  'host' => 'localhost',
  'port' => 5432,
  'database' => 'drupal',
  'username' => 'drupal_user',
  'password' => 'password',
];
```

**Search Settings:**
```php
$config = [
  'min_chars' => 3,
  'autocomplete' => [
    'suggest_suffix' => TRUE,
    'suggest_words' => TRUE,
  ],
];
```

**Vector Settings:**
```php
$config = [
  'embedding_service' => 'openai',
  'vector_dimension' => 1536,
  'similarity_threshold' => 0.7,
];
```

### Index Configuration

**Field Configuration:**
```php
$field_config = [
  'type' => 'postgresql_fulltext',
  'settings' => [
    'text_search_config' => 'english',
    'enable_stemming' => TRUE,
    'weight' => 'A',
  ],
];
```

**Processor Configuration:**
```php
$processor_config = [
  'weights' => [
    'preprocess_index' => 0,
    'preprocess_query' => 0,
  ],
  'settings' => [
    'boost' => 1.0,
    'fields' => ['title', 'body'],
  ],
];
```

## Hooks and Alter Functions

### Module Hooks

**hook_search_api_postgresql_index_alter()**
- Alters index configuration before creation
- Parameters: IndexInterface $index

**hook_search_api_postgresql_query_alter()**
- Alters search query before execution
- Parameters: QueryInterface $query

**hook_search_api_postgresql_results_alter()**
- Alters search results after execution
- Parameters: ResultSetInterface $results

### Drupal Hooks

**hook_search_api_backend_info_alter()**
- Modifies available backend information
- Used to register the PostgreSQL backend

**hook_search_api_data_type_info_alter()**
- Modifies available data type information
- Used to register PostgreSQL-specific data types

## Error Handling

### Exception Classes

**PostgresqlBackendException:**
- Base exception class for backend errors
- Used for configuration and connection errors

**VectorException:**
- Thrown for vector operation errors
- Embedding generation and similarity calculation errors

**IndexException:**
- Thrown for index management errors
- Table creation and schema update errors

### Error Codes

**Connection Errors:**
- `POSTGRESQL_CONNECTION_FAILED`: Database connection failure
- `POSTGRESQL_EXTENSION_MISSING`: Required extension not installed

**Index Errors:**
- `POSTGRESQL_TABLE_CREATE_FAILED`: Table creation failure
- `POSTGRESQL_INDEX_CREATE_FAILED`: Index creation failure

**Query Errors:**
- `POSTGRESQL_QUERY_FAILED`: Query execution failure
- `POSTGRESQL_SYNTAX_ERROR`: Query syntax error

## Performance Monitoring

### Metrics Collection

**Query Performance:**
- Execution time tracking
- Query complexity analysis
- Result set size monitoring

**Index Performance:**
- Indexing speed metrics
- Index size tracking
- Update frequency monitoring

**Vector Operations:**
- Embedding generation time
- Similarity calculation performance
- Cache hit rates

### Debug Information

**Query Debugging:**
```php
$query->setOption('debug', TRUE);
// Enables detailed query logging
```

**Performance Profiling:**
```php
$query->setOption('profile', TRUE);
// Enables performance profiling
```

## Testing Utilities

### Test Helpers

**MockBackend:**
- Mock implementation for unit testing
- Simulates PostgreSQL operations without database

**TestDataGenerator:**
- Generates test content and vectors
- Provides sample data for testing

**PerformanceTestCase:**
- Base class for performance testing
- Includes benchmarking utilities

### Assertion Methods

**assertIndexExists()**
- Verifies search index exists in database

**assertVectorSimilarity()**
- Tests vector similarity calculations

**assertSearchResults()**
- Validates search result format and content