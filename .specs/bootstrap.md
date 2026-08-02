# Greenfield Project Bootstrap & Stack Specifications

## Project Overview
- **Project Name:** GNN FileHub NextGen
- **Paradigm:** WordPress Plugin [Verified]
- **Target Location:** `wp-content/plugins/gnn-filehub-nextgen/` or workspace root [Verified]

## Prerequisites & Requirements
- **PHP Version:** 8.0+ [Verified]
- **WordPress Core:** 6.0+ [Verified]
- **Database:** MySQL 5.7+ / MariaDB 10.3+ [Verified]
- **Dependencies:** 0 External Dependencies (Safely using WP Core HTTP API & REST Framework) [Verified]
- **Version Source of Truth:** `VERSION` file in repository root [Verified]

## CI/CD Pipelines
- **Workflow File:** `.github/workflows/release.yml` [Verified]
- **Trigger:** `workflow_dispatch` (Manuel tetikleme)
- **Artifact:** Clean plugin ZIP archive (`gnn-filehub-nextgen-v<VERSION>.zip`) omitting dev/agent metadata.

## Directory Structure Map
```text
VERSION
gnn-filehub-nextgen.php
inc/
  class-filehub-core.php
  class-filehub-rest-api.php
  class-filehub-attachment.php
  class-filehub-admin.php
  class-filehub-shortcodes.php
  storage/
    class-storage-interface.php
    class-storage-local.php
    class-storage-r2.php
    class-storage-gdrive.php
assets/
  css/filehub-admin.css
  js/filehub-public.js
.github/
  workflows/
    release.yml
```

## Recommended Validation Commands
| Command | Purpose | Requires Installed Tool | Notes |
| :--- | :--- | :--- | :--- |
| `php -l gnn-filehub-nextgen.php` | Syntax Validation | PHP CLI | Fast syntax check |
| `php -l inc/class-filehub-core.php` | Core Class Syntax Check | PHP CLI | Core engine validation |
