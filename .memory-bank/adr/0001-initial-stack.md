# ADR 0001: Zero-Dependency WordPress Core Native Architecture

- **Status**: Accepted
- **Confidence**: Verified
- **Date**: 2026-08-02

## Context
The GNN FileHub NextGen plugin requires a resilient, low-maintenance architecture that prevents breaking changes across WordPress updates, avoids PHP execution vulnerabilities (RCE), eliminates database I/O bottlenecks caused by `scandir()` disk lookups, and supports multi-provider storage (Local, Cloudflare R2, Google Drive).

## Decision
1. **WP Core Native APIs Only:** Maximize usage of standard WordPress Core components:
   - Custom Post Type `attachment` and Post Meta for file index & metadata.
   - `WP_REST_Controller` for REST API endpoints `/wp-json/filehub/v1/`.
   - `wp_remote_post()` and `wp_remote_request()` (WP HTTP API) for Cloudflare R2 (S3 SigV4) and Google Drive API v3.
2. **Zero External Composer / NPM Packages:** Do not pull AWS SDK, Google Client SDK, or external frontend frameworks.
3. **Pure CSS WP Theme Color Variables:** Use WordPress Admin CSS classes (`.wrap`, `.card`, `.wp-list-table`) and CSS variables (`var(--wp-admin-theme-color)`) for custom iOS/Material toggle switches.
4. **Local Protected Isolation:** Store local uploads under `wp-content/uploads/filehub-protected/` with `.htaccess` (`Deny from all`) and serve downloads exclusively via REST Proxy stream.

## Consequences
- Zero vulnerability risk from outdated Composer vendor dependencies.
- Perfect visual integration with any WordPress admin theme color scheme.
- Full performance scaling via indexed MySQL queries instead of server disk scans.
- Strict proxy delivery prevents direct PHP execution in public upload paths.

## Evidence
- `implementation_plan.md`
- `.specs/gnn_filehub_wp_native_spec.md`
