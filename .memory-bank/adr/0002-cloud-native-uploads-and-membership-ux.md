# ADR 0002: Cloud-Native Streamed Uploads, Per-Tab Settings Isolation, and Member-Facing UX

- **Status**: Accepted
- **Confidence**: Verified
- **Date**: 2026-08-03

## Context
Following the initial zero-dependency build (ADR 0001), real-world usage on the plugin
author's own site (gnn.tr) with real Cloudflare R2 and Google Drive credentials surfaced a
class of problems ADR 0001 didn't anticipate: PHP's memory limit and execution time are a
hard ceiling for any single-request upload/download path, WordPress's Settings API silently
destroys unregistered fields when multiple tabs share one option group, and non-technical
members landing in wp-admin (via a stray login redirect, a bookmarked URL, a plugin default)
is a confusing and unwanted experience for a plugin meant to feel like a consumer file-sharing
product, not a WordPress backend.

## Decision

1. **Direct-to-cloud upload for R2 (presigned URL, WeTransfer-style).** WordPress computes a
   query-string SigV4-signed PUT URL (`UNSIGNED-PAYLOAD` sentinel, since content length isn't
   known ahead of time) via a new `/filehub/v1/r2-presign` REST route; the browser PUTs the
   file directly to `*.r2.cloudflarestorage.com`, bypassing PHP entirely. A second
   `/filehub/v1/r2-finalize` call HEAD-verifies the real uploaded size server-side (never
   trusts a client-reported size) before registering the WordPress attachment, and enforces
   the user's quota against the verified size. This removes PHP's `upload_max_filesize` /
   `memory_limit` / `max_execution_time` as a ceiling for R2 uploads altogether — the only
   remaining limit is the browser's own upload speed.
2. **Resumable, disk-streamed uploads for Google Drive.** Google Drive has no equivalent
   presign-and-PUT-from-browser primitive with this plugin's OAuth scope model, so uploads
   still proxy through PHP — but files over 8MB now use Google's resumable upload protocol,
   streamed from disk via `fopen`/`fread` in bounded 8MB chunks with `Content-Range` headers,
   instead of `file_get_contents()`-ing the whole file into one in-memory multipart request.
   The same disk-streaming principle applies to downloads for both Drive and R2 (stream to a
   `wp_tempnam()` file, `readfile()` it) and to R2's own upload path (raw cURL
   `CURLOPT_INFILE`/`CURLOPT_UPLOAD`, `hash_file()` instead of `hash(file_get_contents())`).
   Small files keep the simpler, lower-latency non-streamed path.
3. **Per-tab Settings API option groups.** `register_setting()` groups now split along the
   admin UI's tab boundaries (`filehub_general_group` / `filehub_pages_group` /
   `filehub_storage_group`) rather than one shared group. WordPress's Settings API treats any
   option registered to a group but absent from a given `options.php` POST as "the user
   cleared it" and nulls it out — a single shared group means saving *any one* tab silently
   wipes every other tab's settings. Fields written outside the standard settings form flow
   (e.g. `filehub_gdrive_refresh_token`, set directly via `update_option()` by the OAuth
   callback) are deliberately *not* registered to any group, so the Settings API never touches
   them.
4. **Unified, WooCommerce-"My Account"-style membership surface.** One `[filehub_account]`
   shortcode / one `filehub_page_account` option replaces the earlier separate register/login/
   profile pages: tabbed login/register when logged out, full profile + password change when
   logged in. The page's nav-menu label is kept in sync with auth state via `wp_nav_menu_objects`
   (classic menus) and a `render_block` filter on `core/navigation-link` (block-theme
   Navigation blocks) — regex-swapping the rendered `wp-block-navigation-item__label` span,
   since block markup is pre-rendered HTML rather than a filterable menu-item object at that
   point. Lightweight `.filehub-nav-cards` link members between the account/uploader/manager
   pages, so the plugin never requires the site to have a real navigation menu configured.
5. **wp-admin is hidden from non-privileged members.** Anyone logged in without `edit_posts`
   (i.e. below Editor — the plugin's actual "regular member" population) never sees the admin
   bar, is bounced from `admin_init` back to the account page, has a bare `wp-login.php` GET
   redirected to the account page, and has `login_url()`/`register_url()` filtered to point
   there too. `current_user_can('edit_posts')` was chosen as the gate (rather than a plugin-
   specific role check) because it's the same capability WordPress core itself uses to decide
   who gets a real admin experience, so it composes correctly with any role/capability plugin
   the site might already have. Admins and Editors are explicitly exempt in every one of these
   hooks — this is a member-facing UX decision, not a security boundary, and must never block
   staff from managing the site.
6. **`wp_login_form()` needs `login_form` fired manually for security-plugin compatibility.**
   Login-security plugins (observed: WPMU DEV Defender's reCAPTCHA) hook WordPress core's
   `login_form` action — fired inside `wp-login.php`'s own template — to render and verify
   their widget. `wp_login_form()` is a *different*, minimal code path that never fires it, so
   any plugin relying on that hook silently rejects logins submitted through this plugin's
   custom form. Fixed by capturing `wp_login_form()`'s output via `ob_start()` and splicing
   `do_action('login_form')`'s output in immediately before `</form>`, so such plugins render
   (and can verify) their widget inside the same form that gets submitted.

## Consequences
- R2 uploads now scale to arbitrarily large files with no PHP-side resource ceiling; Drive
  uploads scale to whatever Google's resumable protocol supports, bounded only by the site's
  own execution-time budget for the (much smaller, chunked) relay requests, not the file size.
- Every settings tab can be saved independently without any risk of clobbering the others —
  this pattern should be followed for any *future* settings tab added to the plugin.
- The plugin's front end can now serve as a complete self-contained membership area; wp-admin
  becomes purely an operator/administrator surface, never something a regular member needs to
  understand exists.
- Trade-off: the account-page nav-label sync and the `login_form` splice are both markup-level
  (regex / string-splice) fixes rather than using a first-class WordPress API, because none
  exists at the right level (block markup is already rendered HTML by the time `render_block`
  fires; `wp_login_form()` has no filter to inject arbitrary markup before `</form>`). Both are
  narrowly scoped and tested, but should be revisited if a WordPress core API for either ever
  appears.

## Evidence
- `.memory-bank/changelog/verified-worklog.md` — "v1.0.1 – v1.3.0" entry, including the live
  WP Playground verification list (settings isolation, R2 presign→PUT→finalize flow with a
  real direct browser PUT confirmed via `performance.getEntriesByType`, chunked-upload lock
  behavior, `login_form` hook firing for a mock listener).
- Real production verification by the plugin author: a genuine 500MB file uploaded to Google
  Drive on their live site (gnn.tr) after the resumable-upload fix, confirmed appearing in
  Drive with the correct size and in the plugin's quota tracker.
