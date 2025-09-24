# Development Scripts

This directory contains scripts for setting up and managing the search_api_postgresql development environment.

## Scripts

### local-setup.php

**Purpose**: Programmatically creates the PostgreSQL Search API server configuration for local development.

**Usage**:
```bash
# From DDEV environment
ddev drush php:script scripts/local-setup.php

# Or from host (if Drush is available)
drush php:script scripts/local-setup.php
```

**What it does**:
- Verifies required modules are enabled (search_api, search_api_postgresql)
- Creates a PostgreSQL Search API server configuration
- Sets up autocomplete and search parameters
- Replaces the need for static `postgresql_server_config.yml`

**Configuration created**:
- Server ID: `postgresql_server`
- Backend: `search_api_postgresql`
- Database: `default:default`
- Minimum characters: 3
- Autocomplete: enabled (suffix and words)

## Benefits of Script-based Setup

1. **Dynamic**: Adapts to environment changes
2. **Validated**: Checks dependencies before setup
3. **Idempotent**: Can be run multiple times safely
4. **Maintainable**: Easier to update than static YAML
5. **Documented**: Self-documenting with clear output