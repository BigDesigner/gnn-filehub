# Security Constraints & Boundary Conditions

## 1. Security Constraints & Input Validation [Verified]
- All REST endpoints (`/wp-json/filehub/v1/`) MUST validate requests with strict `permission_callback` using `current_user_can()` and `X-WP-Nonce` header verification.
- All file uploads MUST be sanitized via `sanitize_file_name()`, MIME-validated using `finfo_file()`, and validated against white-listed extensions (`jpg`, `png`, `pdf`, `zip`, etc.).
- Input text fields MUST be sanitized using `sanitize_text_field()`, and output data MUST be escaped using `esc_html()`, `esc_url()`, and `esc_attr()`.

## 2. Authentication & Authorization [Verified]
- BOLA / IDOR Defense: Direct file access by file ID MUST verify user ownership (`post_author` check) unless caller has `manage_options` administrative capability.
- Download links MUST serve files via secure REST Proxy stream rather than disclosing physical path locations.

## 3. Storage & Isolation Boundaries [Verified]
- Local Protected Storage MUST reside at `wp-content/uploads/filehub-protected/`.
- An `.htaccess` file containing `Deny from all` MUST be automatically written inside the storage root to prevent direct web execution of PHP or script files.

## 4. Architectural & Dependency Contracts [Verified]
- Zero External Composer Libraries: Cloudflare R2 and Google Drive API integrations MUST use standard WordPress Core `wp_remote_post()` and `wp_remote_request()`.
- UI Theme Contract: Custom admin components (toggles, cards) MUST strictly utilize WP Admin CSS variables (`var(--wp-admin-theme-color)`) and standard WP Admin classes (`.wrap`, `.card`, `.wp-list-table`).

## 5. End-to-End Integration & Wiring Contract [Verified]
- Every completed feature MUST complete all 5 links of the integration chain:
  `DB Persistence (Post Meta)` -> `Backend API Handler (WP REST)` -> `Frontend API Service (Fetch API)` -> `UI Trigger Element` -> `UI Feedback (Progress / Toast)`.
