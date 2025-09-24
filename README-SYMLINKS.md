# Development Symlinks for search_api_postgresql

This DDEV environment is configured to automatically create symlinks between your module source code and the Drupal test installation.

## How it works

The file `.ddev/docker-compose.symlinks.yaml` configures Docker bind mounts that create real-time symlinks between:

- **Source**: Root module files and directories
- **Target**: `/web/modules/contrib/search_api_postgresql/` in the DDEV container

## Symlinked directories and files

- `src/` → module source code
- `config/` → configuration files
- `templates/` → Twig templates
- `tests/` → test files
- `js/` → JavaScript files
- `css/` → CSS files
- All `.yml` module definition files

## Activation

1. Run `ddev restart` to activate the symlinks
2. Your changes to the module source will immediately appear in the Drupal installation
3. No manual copying needed!

## Benefits

- Real-time development - changes appear instantly
- No sync scripts needed
- Works with IDEs and file watchers
- Consistent with Drupal contrib development workflow