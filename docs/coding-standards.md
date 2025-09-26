# Drupal Coding Standards

## Overview

This project follows the official Drupal coding standards as provided by the drupal/coder package. All custom phpcs.xml files have been removed to ensure consistency with Drupal's standard practices.

## Available Standards

The following coding standards are available via drupal/coder:

- **Drupal**: Core Drupal coding standards
- **DrupalPractice**: Additional best practice rules

## Usage Commands

### Basic Usage
```bash
# Check all source code against Drupal standards
ddev exec "phpcs --standard=Drupal src/ tests/"

# Check against DrupalPractice standards
ddev exec "phpcs --standard=DrupalPractice src/ tests/"

# Check both standards together (if environment supports it)
ddev exec "phpcs --standard=Drupal,DrupalPractice src/ tests/"
```

### Auto-fixing
```bash
# Auto-fix coding standard violations
ddev exec "phpcbf --standard=Drupal src/ tests/"
ddev exec "phpcbf --standard=DrupalPractice src/ tests/"
```

### Detailed Reporting
```bash
# Generate detailed report
ddev exec "phpcs --standard=Drupal --report=full src/"

# Summary report
ddev exec "phpcs --standard=Drupal --report=summary src/"

# Check specific file
ddev exec "phpcs --standard=Drupal src/Plugin/Backend/SearchApiPostgresqlBackend.php"
```

### File Extensions
```bash
# Check all relevant Drupal file types
ddev exec "phpcs --standard=Drupal --extensions=php,module,inc,install,test,profile,theme src/"
```

## Known Issues

### SlevomatCodingStandard Compatibility
There may be compatibility issues with certain versions of SlevomatCodingStandard that cause PHPCS to fail with undefined constant errors. If you encounter this issue:

1. **Use Standards Separately**:
   ```bash
   # Check Drupal standard alone
   ddev exec "phpcs --standard=Drupal src/"

   # Check DrupalPractice separately
   ddev exec "phpcs --standard=DrupalPractice src/"
   ```

2. **Update Dependencies**:
   ```bash
   ddev composer update drupal/coder
   ddev composer update squizlabs/php_codesniffer
   ```

3. **Check Available Standards**:
   ```bash
   ddev exec "phpcs -i"
   ```

## Integration with Development Workflow

### Pre-commit Hook
```bash
#!/bin/bash
# .git/hooks/pre-commit
echo "Running Drupal coding standards check..."
ddev exec "phpcs --standard=Drupal src/ --report=summary"
if [ $? -ne 0 ]; then
    echo "ERROR: Coding standards violations found. Please fix before committing."
    echo "Run: ddev exec 'phpcbf --standard=Drupal src/' to auto-fix issues."
    exit 1
fi
echo "SUCCESS: Coding standards check passed."
```

### IDE Integration
Configure your IDE to use the Drupal coding standards:

**PhpStorm/IntelliJ**:
- Settings → Editor → Code Style → PHP
- Set from → Drupal
- Use phpcs with `--standard=Drupal`

**VS Code**:
- Install phpcs extension
- Configure to use `--standard=Drupal`

## Best Practices

### Code Quality Workflow
1. Write code following Drupal conventions
2. Run `phpcbf --standard=Drupal` to auto-fix minor issues
3. Run `phpcs --standard=Drupal` to check for remaining violations
4. Manually fix any remaining issues
5. Commit clean, standards-compliant code

### Team Development
- All developers should use the same Drupal coding standards
- No custom phpcs.xml files should be added to the repository
- Use the official drupal/coder package standards exclusively
- Document any environment-specific workarounds in this file

### Continuous Integration
Ensure CI pipelines include coding standards checks:

```yaml
# GitHub Actions example
- name: Check Coding Standards
  run: |
    ddev exec "phpcs --standard=Drupal src/ tests/"
    ddev exec "phpcs --standard=DrupalPractice src/ tests/"
```

## Troubleshooting

### Common Issues

**"Drupal standard not found"**:
```bash
ddev composer require drupal/coder --dev
ddev exec "phpcs --config-set installed_paths vendor/drupal/coder/coder_sniffer"
```

**"Fatal error in SlevomatCodingStandard"**:
```bash
# Use standards individually
ddev exec "phpcs --standard=Drupal src/"
# Skip DrupalPractice if it causes issues
```

**Permission errors**:
```bash
ddev exec "chown -R www-data:www-data src/ tests/"
```

## References

- [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)
- [drupal/coder package](https://www.drupal.org/project/coder)
- [PHP_CodeSniffer Documentation](https://github.com/squizlabs/PHP_CodeSniffer/wiki)
- [Drupal Code Review Process](https://www.drupal.org/docs/develop/git/using-git-to-contribute-to-drupal/code-review-process-and-workflow)