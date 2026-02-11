# phpDmAdmin

A lightweight, single-file PHP web UI for DaMeng (DM8) databases. It provides quick browsing, ad-hoc SQL, and inline editing without additional dependencies.

<p align="center">
| <b>English</b> | <a href="./README_zh.md"><b>简体中文</b></a>
</p>

## Features

- Login page with configurable default connection settings
- Schema and table browsing with filtering
- SQL console with server-side pagination, column filtering, and edit-in-place (when primary key is present)
- Table view with inline cell editing, NULL/empty distinction, and long-text preview
- Optional default schema auto-apply for SQL
- Basic charset conversion support (mbstring/iconv)

## Requirements

- PHP 7.4+ (recommended)
- PDO extension with `pdo_dm` driver
- A reachable DM8 instance

## Quick Start

1. Upload `index.php` (and optional `config.php`) to your web root.
2. Open the page in a browser.
3. Fill in connection details and click **Connect**.

## Configuration

Create `config.php` to override defaults:

```php
<?php
$DM_CONFIG = [
    'enabled' => true,
    'default_host' => '127.0.0.1',
    'default_port' => 5236,
    'default_db' => 'DM8',
    'default_user' => 'SYSDBA',
    'default_password' => '',
    'default_schema' => '',
    'default_charset' => 'UTF-8',
    'data_charset' => '',
    'error_charset' => '',
    'output_charset' => 'UTF-8',
    'auto_schema' => true,
    'max_rows' => 200,
];
```

Notes:
- `data_charset` controls input conversion when writing to the DB.
- `error_charset` helps normalize DB error messages.
- `max_rows` controls pagination size.

## Security Notes

- This tool is intended for trusted environments only.
- Protect the directory with authentication or IP restrictions.
- Disable it by setting `'enabled' => false` in `config.php` when not in use.

## License

MIT License. See `LICENSE` if provided, otherwise apply MIT to this repository.
