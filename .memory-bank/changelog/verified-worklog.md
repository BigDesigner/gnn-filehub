# Verified Worklog

## Completed Work
- **2026-08-02:** Project initialized with Sentinel Agent Memory Bank structure.
- **2026-08-02:** Git repository initialized and initial Sentinel config committed (`1e93a09`).
- **2026-08-02:** **Task 1 Completed:** Created `gnn-filehub-nextgen.php` and `inc/class-filehub-core.php`.
- **2026-08-02:** **Task 2 Completed:** Created `inc/storage/class-storage-interface.php` and `inc/storage/class-storage-local.php`.
- **2026-08-02:** **Task 3 Completed:** Created Cloudflare R2 (`inc/storage/class-storage-r2.php`) and Google Drive (`inc/storage/class-storage-gdrive.php`).
- **2026-08-02:** **Task 4 Completed:** Created `inc/class-filehub-attachment.php` and `inc/class-filehub-rest-api.php`.
- **2026-08-02:** **Task 5 Completed:** Created `inc/class-filehub-admin.php` and `assets/css/filehub-admin.css`.
- **2026-08-02:** **Task 6 Completed:** Created `inc/class-filehub-shortcodes.php` and `assets/js/filehub-public.js`.
- **2026-08-02:** **Task 7 Completed:** Created GitHub Auto-Updater (`inc/class-filehub-updater.php`) and `.github/workflows/release.yml`.
- **2026-08-02:** **Task 8 Completed:** Added `[filehub_login]`, `[filehub_profile]`, `[filehub_password_change]` shortcodes and Live Search & AJAX Delete.
- **2026-08-02:** **Task 9 Completed:** Added `[filehub_register]` shortcode and `POST /wp-json/filehub/v1/register` REST endpoint.
- **2026-08-02:** **Task 10 Completed:** Added WooCommerce-style dropdown page selectors (`wp_dropdown_pages`), automatic shortcode content injection filter (`the_content`), and redesigned WP Admin panel into a sleek tabbed interface (`.nav-tab-wrapper`).
- **2026-08-02:** **Task 11 Completed:** Executed Real `npx @wp-playground/cli` server test on port 9400. Verified live WP Admin rendering (`<h1 class="wp-heading-inline">GNN FileHub NextGen</h1>`), tabbed navigation, and all 6 WooCommerce-style page assignment dropdowns on the live server. Created report at `.memory-bank/audits/testreport-real-playground.md`.

## Validation Status
- Real WP Playground Server (`http://127.0.0.1:9400`) -> 100% Verified Live HTTP Response.
- All 11 PHP files syntax checked via `php -l` -> 100% Passed cleanly with 0 errors.

## v1.0.1 – v1.3.0 (2026-08-02 → 2026-08-03): Admin UX, Frontend Rebuild, Cloud Reliability

Renamed the plugin from "GNN FileHub NextGen" to **GNN Filehub** by **BigDesigner** across
header, updater, admin UI, `.pot`. All work below re-verified live on WP Playground
(`http://127.0.0.1:9400`, site dir `554db6a21b4296bf0ca534f357ed737f05cf78dc03e24d226f6ecaf911b8bfc0`)
after each change, `php -l` / `node -c` clean on every touched file.

**Admin panel (v1.0.1–1.1.2):**
- Fixed cross-tab settings wipeout: all options shared one `filehub_settings_group`, so saving
  any single settings tab blanked every other tab's fields via `options.php`'s "unregistered =
  present in group but absent from POST => set null" behavior. Split into
  `filehub_general_group` / `filehub_pages_group` / `filehub_storage_group`.
- Split admin UI into three real top-level pages (Genel Bakış / Tüm Dosyalar / Ayarlar) instead
  of tabs sharing one page; Ayarlar now also has a **Bakım** tab for JSON settings export/import
  (whitelist-only import, nonce + `manage_options` gated, file type/size checked).
- Overview quota cards no longer hardcode "R2 10GB / Drive 15GB" as if configured — only render
  once real credentials are saved; added the previously-missing Local Storage usage card.
- Storage tab now conditionally shows only the selected driver's credential fields (was showing
  R2 + Drive simultaneously regardless of selection).
- "Otomatik Sayfa Oluştur" (WooCommerce-style) button auto-creates/assigns any missing required
  pages in one click.
- Rebranded plugin-row action links to match the author's other GNN plugins (Donate / Settings /
  Check Updates), added Author URI / License headers.
- Replaced the manual "paste a refresh token from OAuth Playground" Google Drive field with a
  real OAuth2 "Connect with Google" flow (`access_type=offline&prompt=consent`, nonce as
  `state`) — no more short-lived Testing-mode tokens from a third-party tool.

**Frontend rebuild (v1.1.0–1.1.2):**
- Unified `[filehub_account]` shortcode: WooCommerce "My Account"-style single page — login/
  register tabs when logged out, full profile (incl. password change) when logged in. Replaces
  the separate register/login/profile page-assignment options with one `filehub_page_account`.
  Nav menu label for that page dynamically flips "Giriş Yap" ⇄ "Hesabım" (classic menus via
  `wp_nav_menu_objects`, block-theme Navigation blocks via `render_block` on
  `core/navigation-link`).
- Replaced ad-hoc inline styles with `assets/css/filehub-public.css`; introduced live theming:
  `filehub-public.js` measures the page's actual rendered background + reads explicit signals
  (`data-theme`, `.dark`/`.light` classes, `color-scheme`) to toggle `.filehub-theme-dark` on
  `.filehub-container`, re-checked via `MutationObserver` + a 2s interval fallback so it tracks
  *any* theme's toggle mechanism, not a guessed one. `--filehub-theme-color` now resolves through
  the standard `--wp--preset--color--primary/--accent` block-theme variables instead of
  `--wp-admin-theme-color`, which never exists on the front end.
- `wp_login_form()` never fires core's `login_form` action (it's a separate template from
  wp-login.php), so login-security plugins that hook it to render+verify a widget (e.g. WPMU DEV
  Defender's reCAPTCHA) silently rejected the login. Now fires `do_action('login_form')` before
  `</form>` so such plugins render — and can actually verify — their widget here too.
- Cross-page navigation cards ("Dosya Gönder" / "Dosyalarım" from the account page, "Hesabım"
  back from uploader/manager) so the site never needs a real nav menu for members to get around.
- Non-privileged members (anyone without `edit_posts`, i.e. Subscribers) are kept out of
  wp-admin entirely: admin bar hidden, `admin_init` redirect back to the account page, bare
  `wp-login.php` GET redirected to it too, `login_url()`/`register_url()` filtered. Admins/
  Editors unaffected.

**Uploads (v1.1.0–1.3.0):**
- Multi-file selection/drop, uploaded sequentially with per-file progress labeling.
- Chunk size 2MB→5MB, 3 chunks in flight at once (was strictly sequential) to hide per-request
  WP REST bootstrap overhead. Server-side chunk merge now guarded by a blocking file lock
  (`flock`) keyed by user+file_id, since concurrent chunks meant more than one request could see
  "all parts present" — without the lock, two requests could both merge and double-create the
  attachment.
- Fixed downloaded files losing their extension: the real filename (with extension) was never
  stored anywhere — `post_title` is intentionally extension-less — now kept in a
  `_filehub_file_name` postmeta and used for both the download `Content-Disposition` and the
  file-list display name.
- `FileHub_Attachment::sanitize_upload_filename()`: transliterates Turkish characters
  (ç/ş/ğ/ı/ö/ü + uppercase) to ASCII, spaces → underscores, then `sanitize_file_name()`. Local
  driver's collision suffix changed from `-N` to `_N`.
- **Google Drive large-file bug:** uploads silently "succeeded" in the UI but never appeared in
  Drive for files in the hundreds-of-MB range. Root cause: the whole file was `file_get_contents()`'d
  into a PHP string for a single in-memory multipart request, exhausting PHP's memory limit.
  Switched to Google's resumable upload protocol, streamed from disk in bounded 8MB chunks —
  verified live with a real 500MB upload. Downloads for both R2 and Drive now stream through a
  temp file instead of buffering the whole body, for the same reason.
- Each user's Drive files now land in their own `<user_id>` subfolder under the configured
  target folder (created on first upload, cached in a transient), instead of one shared pile.
- **Cloudflare R2 direct-to-cloud uploads:** WordPress issues a query-string-presigned PUT URL
  (`get_presigned_upload_url()`/`verify_uploaded_object()` in `class-storage-r2.php`, new
  `/r2-presign` + `/r2-finalize` REST routes) and the browser PUTs the file straight to R2 — the
  bytes never touch PHP, removing the memory/execution-time ceiling entirely for R2. Requires the
  bucket's CORS to allow the site origin (documented in the storage tab UI). Real size is
  re-verified via a HEAD request before the attachment is registered, not trusted from the client.
- Fixed a real bug this surfaced: the upload queue's final status message ("N dosya başarıyla
  yüklendi!") was shown unconditionally regardless of whether individual uploads actually
  succeeded, silently masking failures (this is what made the R2/Drive issues above look like
  they were succeeding when they weren't). Each upload path now reports real success/failure.
- Progress UI no longer freezes at a silent "%100" while the server merges chunks / relays to a
  cloud driver — switches to "Dosya işleniyor, lütfen bekleyin..." once all bytes are with the
  server, across all three upload paths (single, chunked, R2 direct).

## Validation Status (v1.3.0)
- All PHP files touched across this range: `php -l` clean.
- `assets/js/filehub-public.js`: `node -c` clean after every edit.
- Live-tested on WP Playground: cross-tab settings isolation (bidirectional), overview quota
  gating, storage driver panel switching, WooCommerce-style page auto-create, unified account
  page (both logged-in and logged-out states, tab switching), dark/light live reactivity
  (simulated toggle via DOM mutation), Google OAuth "Connect" redirect (real Google endpoint,
  fake client correctly rejected with `invalid_client`), R2 direct-upload presign→PUT→finalize
  flow (fake credentials correctly rejected, confirmed the browser truly attempted the direct
  PUT to `*.r2.cloudflarestorage.com` via `performance.getEntriesByType`), chunked upload lock
  (7MB/25MB local uploads, single attachment each, no duplicates), Turkish filename
  transliteration + `_N` collision suffix, extension-preserving download, `login_form` hook
  firing correctly for a mock reCAPTCHA-style listener. Real Google Drive 500MB upload confirmed
  working by the plugin author on their live site (gnn.tr) post-fix.
