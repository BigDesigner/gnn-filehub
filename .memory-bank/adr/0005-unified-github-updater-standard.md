# ADR 0005: Unified GNN GitHub Updater Standard

- **Status**: Accepted
- **Confidence**: Verified
- **Date**: 2026-11-21
- **Category**: External Integrations
- **Supersedes**: None
- **Superseded By**: None

## Context
`FileHub_Updater` (`gnn-filehub/inc/class-filehub-updater.php`) diverged from the Unified GNN GitHub Updater Standard:
1. `get_remote_release()` lacked a GitHub HTML redirect/expanded_assets fallback when the GitHub REST API failed or hit rate limits, and stored failure sentinels as an array instead of `(object)['failed' => true]`.
2. `sanitize_github_url()` only allowed `github.com` rather than the standard GitHub/GitHubusercontent HTTPS host allowlist, and `get_package_url()` only matched a rigid filename pattern without `zipball_url` fallback.
3. Semver validation did not allow pre-release suffixes (`-[0-9A-Za-z.-]+`).
4. `after_install()` did not normalize destination paths via `wp_normalize_path()`, move mismatched directories via `$wp_filesystem->move()`, call `wp_clean_plugins_cache(true)`, or restore active plugin state via `upgrader_pre_install`.
5. `check_for_update()` did not guard `$transient->response` / `$transient->no_update` array initialization or unset `response` when up to date, and `handle_manual_check()` lacked a `403` `wp_die()` response on insufficient capability.

## Decision
Standardize `FileHub_Updater` on the Unified GNN GitHub Updater Standard:
- Register `pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_pre_install`, `upgrader_post_install`, `admin_init`, and `load-update-core.php`.
- Implement `get_local_version()` using `GNN_FILEHUB_VERSION` with `get_plugin_data()` fallback.
- Implement `get_remote_release()` with GitHub API primary fetch, `wp_remote_head` + `/releases/expanded_assets/{tag}` fallback, `.zip` asset preference with `zipball_url` fallback, semver validation, `(object)['failed' => true]` 300s negative cache, and `sanitize_github_url()` HTTPS host allowlist.
- Implement `capture_pre_install_state()`, `after_install()`, `handle_manual_check()`, and `clear_cache()` per standard, preserving `filehub_check_update`, `filehub_manual_update`, and `filehub_github_update_check`.

## Consequences
- Consistent update behavior across wp-admin, WP-Cron, and WP-CLI, resilient to GitHub API rate limits and directory naming differences.

## Evidence
- `gnn-filehub/inc/class-filehub-updater.php`
