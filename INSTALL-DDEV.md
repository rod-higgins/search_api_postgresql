# Complete DDEV Installation Guide for Search API PostgreSQL + pgvector

This guide walks you through setting up a complete Drupal 11 environment with DDEV for testing the Search API PostgreSQL module with AI/vector search capabilities.

## Quick Start (TL;DR)

For experienced developers who want to get started immediately:

```bash
git clone <repository-url> search_api_postgresql
cd search_api_postgresql
ddev start
ddev install-drupal
ddev verify-setup
ddev launch
```

Then configure Search API at `/admin/config/search/search-api` with database settings: `Host=db, Port=5432, Database=db, User=db, Password=db`

## Prerequisites

1. **Install DDEV**: Follow the [official DDEV installation guide](https://ddev.readthedocs.io/en/stable/users/install/)
2. **Docker**: Ensure Docker is running on your system
3. **Git**: For cloning repositories

## Step 1: Initial Setup

```bash
# Navigate to your development directory
cd /path/to/your/dev/folder

# Clone or navigate to the search_api_postgresql project
git clone <repository-url> search_api_postgresql
cd search_api_postgresql

# Start DDEV environment
ddev start
```

## Step 2: Installation Options

### Option A: Automated Installation (Recommended)

For a complete automated setup:

```bash
# Run the automated installer
ddev install-drupal

# Verify everything is working
ddev verify-setup

# Launch your site
ddev launch
```

This will automatically:
- Install Drupal 11 with PostgreSQL
- Setup all PostgreSQL extensions (pgvector, pg_trgm, etc.)
- Install and enable all required modules
- Create test content
- Configure the environment

### Option B: Manual Installation

If you prefer manual control over each step:

## Step 2: Install Drupal 11 (Manual)

```bash
# Create a fresh Drupal 11 project structure
ddev composer create-project drupal/recommended-project:11.x tmp
ddev exec "cp -r tmp/. . && rm -rf tmp"

# Install essential development tools
ddev composer require drush/drush drupal/devel drupal/admin_toolbar

# Setup the database with PostgreSQL extensions
ddev setup-database

# Install Drupal with PostgreSQL
ddev drush site:install standard \
  --db-url=pgsql://db:db@db/db \
  --site-name="Search API PostgreSQL Dev" \
  --account-name=admin \
  --account-pass=admin \
  --yes

# Enable essential modules
ddev drush en admin_toolbar admin_toolbar_tools devel -y
```

## Step 3: Install Search API and Dependencies

```bash
# Install Search API PostgreSQL and related modules
ddev composer require \
  drupal/search_api \
  drupal/search_api_postgresql \
  drupal/key \
  drupal/token \
  drupal/pathauto

# Install AI/OpenAI modules for vector search (if using AI features)
ddev composer require \
  drupal/ai \
  drupal/openai \
  drupal/search_api_ai

# Enable the modules
ddev drush en \
  search_api \
  search_api_postgresql \
  key \
  token \
  pathauto \
  -y

# Enable AI modules if you plan to use AI features
ddev drush en ai openai search_api_ai -y
```

## Step 4: Verify PostgreSQL and pgvector Setup

```bash
# Test PostgreSQL connection
ddev psql -c "SELECT version();"

# Verify pgvector installation
ddev pgvector-test

# Check all extensions are installed
ddev pg-extensions

# Test Search API schema compatibility
ddev search-api-schema-test
```

Expected output should show:
- PostgreSQL 16.x
- pgvector extension installed and working
- All required extensions (pg_trgm, btree_gin, etc.) available

## Step 5: Configure Search API Server

### Via Drush (Recommended)
```bash
# Create a Search API server configuration
ddev drush config:set search_api.server.postgresql_server \
  name "PostgreSQL Server" \
  id postgresql_server \
  status true \
  backend search_api_postgresql \
  backend_config.host db \
  backend_config.port 5432 \
  backend_config.database db \
  backend_config.username db \
  backend_config.password db \
  --yes
```

### Via Web Interface
1. Visit your site: `ddev launch`
2. Login as admin (admin/admin)
3. Go to **Configuration > Search and metadata > Search API** (`/admin/config/search/search-api`)
4. Click **Add server**
5. Configure:
   - **Server name**: PostgreSQL Server
   - **Backend**: PostgreSQL
   - **Database settings**:
     - Host: `db`
     - Port: `5432`
     - Database: `db`
     - Username: `db`
     - Password: `db`

## Step 6: Create Test Content

```bash
# Create some test content types and nodes
ddev drush en node -y

# Generate test content using Devel
ddev drush devel:generate-content 50 --bundles=article

# Or create content manually via the interface
```

## Step 7: Setup Search Index

### Via Web Interface
1. Go to **Configuration > Search and metadata > Search API** (`/admin/config/search/search-api`)
2. Click **Add index**
3. Configure:
   - **Index name**: Content Index
   - **Data sources**: Select "Content"
   - **Server**: Select your PostgreSQL server
   - **Index items immediately**: Check this
4. Add fields to index (title, body, etc.)
5. Save the index

### Via Drush
```bash
# Check search status
ddev search-status

# Manually index content
ddev search-reindex
```

## Step 8: AI/Vector Search Setup (Optional)

If you want to test AI-powered vector search:

### Configure OpenAI API
1. Get an OpenAI API key from [OpenAI Platform](https://platform.openai.com/)
2. Go to **Configuration > System > Keys** (`/admin/config/system/keys`)
3. Add a new key:
   - **Key type**: Authentication
   - **Key value**: Your OpenAI API key

### Configure AI Settings
1. Go to **Configuration > AI** (`/admin/config/ai`)
2. Configure OpenAI provider with your API key
3. Set up embedding generation for search content

### Configure Vector Search
1. Edit your Search API index
2. Add vector fields for AI embeddings
3. Configure vector similarity search processors

## Step 9: Testing and Verification

### Basic Functionality Tests
```bash
# Test database performance
ddev pg-performance

# Check search status
ddev search-status

# Test pgvector functionality
ddev pgvector-test

# Test search schema
ddev search-api-schema-test
```

### Web Interface Tests
1. **Search API Status**: `/admin/config/search/search-api`
   - Verify server connection is green
   - Check index status shows indexed items

2. **Test Search**: Create a search page or use Views
   - Go to **Structure > Views** (`/admin/structure/views`)
   - Create a new view using Search API
   - Test search functionality

3. **Database Inspection**:
   ```bash
   # View search tables
   ddev psql -c "\dt *search*"

   # Check for vector columns (if using AI)
   ddev psql -c "\d+ search_api_db_content_index"
   ```

## Step 10: Performance Testing

```bash
# Index a large amount of content
ddev drush devel:generate-content 1000

# Measure indexing performance
time ddev search-reindex

# Check database performance
ddev pg-performance

# Test vector operations (if using AI)
ddev psql -c "
SELECT COUNT(*) as total_vectors
FROM search_api_db_content_index
WHERE embedding_vector IS NOT NULL;
"
```

## Step 11: Development Workflow

### Daily Development Commands
```bash
# Start your development session
ddev start

# Clear caches
ddev drush cr

# Check search status
ddev search-status

# Reindex after changes
ddev search-reindex

# View logs
ddev logs -f
```

### Debugging Commands
```bash
# PostgreSQL debugging
ddev psql -c "SELECT * FROM pg_stat_activity;"

# Search API debugging
ddev drush search-api:reset-tracker
ddev drush search-api:index --batch-size=10

# Check for errors
ddev logs | grep -i error
```

## Troubleshooting

### Common Issues

1. **pgvector extension not found**:
   ```bash
   ddev restart
   ddev setup-database
   ```

2. **Search API server connection fails**:
   - Verify database credentials in server config
   - Check: `ddev psql -c "SELECT 1;"`

3. **Indexing fails**:
   ```bash
   ddev search-clear
   ddev search-reindex
   ```

4. **Vector operations fail**:
   ```bash
   ddev pgvector-test
   ddev pg-extensions
   ```

### Getting Help

- Check logs: `ddev logs`
- Test database: `ddev psql -c "SELECT version();"`
- Verify modules: `ddev drush pml | grep search`
- Check configuration: `ddev drush config:get search_api.server.postgresql_server`

## Expected Results

After completing this installation, you should have:

**Drupal 11** fully installed and configured
**PostgreSQL 16** with pgvector extension
**Search API PostgreSQL** module working
**Vector search capabilities** (if AI modules installed)
**Test content** indexed and searchable
**Development tools** ready for module testing

## Next Steps

1. **Module Development**: Start developing/testing Search API PostgreSQL features
2. **Performance Testing**: Test with larger datasets
3. **AI Integration**: Experiment with vector similarity search
4. **Custom Features**: Implement project-specific search requirements

Your local development environment is now ready for comprehensive testing of the Search API PostgreSQL module with full vector search capabilities!