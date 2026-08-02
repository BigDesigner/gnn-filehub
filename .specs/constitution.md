# Project Constitution & Engineering Laws

## Core Principles
1. **WordPress Core Native First:** All development MUST leverage native WordPress Core APIs (`WP_REST_Controller`, `wp_remote_post`, `attachment` CPT, Post Meta, Options API).
2. **Zero Dependency Footprint:** No Composer or NPM packages may be added. Keep the plugin lightweight, fast, and 100% resilient to WP core updates.
3. **Pure CSS WP Theme Compliance:** Admin UI elements MUST use standard WordPress Admin CSS layout constructs (`.wrap`, `.card`, `.form-table`) and CSS theme variables (`var(--wp-admin-theme-color)`). Never load heavy external frameworks like Tailwind or Bootstrap.
4. **Resumable & Chunked Uploads:** Support chunked file transfers for large uploads to avoid server PHP `upload_max_filesize` and `max_execution_time` timeouts.
5. **No Loss of Technical Detail:** Specifications, security requirements, and operational rules in `.specs/` are locked constraints.
