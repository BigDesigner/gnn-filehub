# Greenfield Project Bootstrap & Stack Specifications

## Project Overview
- **Project Name:** GNN FileHub NextGen
- **Paradigm:** WordPress Plugin [Verified]
- **Plugin Directory:** `gnn-filehub/` [Verified]

## Prerequisites & Requirements
- **PHP Version:** 8.0+ [Verified]
- **WordPress Core:** 6.0+ [Verified]
- **Database:** MySQL 5.7+ / MariaDB 10.3+ / SQLite (Playground) [Verified]
- **Dependencies:** 0 External Dependencies (Safely using WP Core HTTP API & REST Framework) [Verified]
- **Version Source of Truth:** `gnn-filehub/VERSION` file [Verified]

## Clean Directory Structure Map
```text
.github/
  workflows/
    release.yml
.memory-bank/
.specs/
.tasks/
tests/
  test-real-playground-suite.php
gnn-filehub/                        <-- Clean Plugin Root
  VERSION
  README.md
  blueprint.json
  gnn-filehub-nextgen.php
  inc/
    class-filehub-core.php
    class-filehub-rest-api.php
    class-filehub-attachment.php
    class-filehub-admin.php
    class-filehub-shortcodes.php
    class-filehub-updater.php
    storage/
      class-storage-interface.php
      class-storage-local.php
      class-storage-r2.php
      class-storage-gdrive.php
  assets/
    css/filehub-admin.css
    js/filehub-public.js
```

## Recommended Validation Commands
| Command | Purpose | Requires Installed Tool | Notes |
| :--- | :--- | :--- | :--- |
| `Get-ChildItem -Recurse -Filter *.php \| ForEach-Object { php -l $_.FullName }` | Full Linting | PHP CLI | Validates all PHP files |
| `npx @wp-playground/cli start` | Playground Live Test | Node / npx | Runs WP Playground on `gnn-filehub/` |
