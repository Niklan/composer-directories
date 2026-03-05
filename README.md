# Composer Directories

A Composer plugin that automatically creates directories and symlinks defined in your `composer.json` during `install` and `update` commands. Directories are always created before symlinks.

## Installation

```bash
composer require niklan/composer-directories
```

## Usage

Add configuration to the `extra` section of your `composer.json`:

```json
{
    "extra": {
        "ensure-directories": [
            "web/sites/simpletest/browser_output",
            "var/log",
            "var/files/public",
            {"path": "var/files/private", "permissions": "0700"}
        ],
        "symlinks": {
            "var/files/public": "web/sites/default/files"
        }
    }
}
```

Both features work independently -- you can use directories, symlinks, or both.

### Directories

- String entries use the default permission `0775` (`drwxrwxr-x`), matching the [Drupal default](https://git.drupalcode.org/project/drupal/-/blob/aea47fe1b2bf7945548988ca14d069b83273f2a5/core/lib/Drupal/Core/File/FileSystem.php#L27).
- Object entries accept a `path` and an optional `permissions` string in [octal format](https://en.wikipedia.org/wiki/File-system_permissions#Numeric_notation).
- All paths are relative to the project root (where `composer.json` is located).

### Symlinks

- Keys are targets, values are link paths. Both are relative to the project root.
- If a symlink already exists, it is replaced. If a non-symlink file/directory exists at the link path, it is skipped with a warning.
- On Windows, directory junctions are used instead of symlinks (no admin rights needed).

### Security

Paths that resolve outside the project root (e.g., `../../../etc`) are rejected with a warning. This applies to both directories and symlinks (target and link).

## Development

### Requirements

- PHP 8.2+
- Composer 2.x

### Setup

```bash
composer install
```

### Linting

```bash
# Run all linters
composer lint

# Run individually
composer phpcs    # PHP CodeSniffer (PSR-12 + Slevomat)
composer phpstan  # PHPStan (level 10)
```

### Auto-fix code style

```bash
composer phpcbf
```

### Testing

```bash
composer phpunit
```

### Run everything

```bash
composer test
```

## License

MIT
