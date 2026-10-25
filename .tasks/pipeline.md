# Project Sprint Pipeline

Current sprint: **v1.7.0 security remediation — implemented, local verification passed**. See the current checklist and `.tasks/remediation-plan.md` for evidence.

## Historical Sprint: v0.3.0-alpha (earlier Playground checks)

### 📌 Active Tasks
- [x] **Task 1: Plugin Bootstrap & Core Class** (`gnn-filehub-nextgen.php`, `inc/class-filehub-core.php`) [Verified]
- [x] **Task 2: Storage Interface & Protected Local Storage Driver** (`inc/storage/class-storage-interface.php`, `inc/storage/class-storage-local.php`) [Verified]
- [x] **Task 3: Cloud Storage Drivers via WP HTTP API** (`inc/storage/class-storage-r2.php`, `inc/storage/class-storage-gdrive.php`) [Verified]
- [x] **Task 4: Attachment CPT Handler & REST Controller** (`inc/class-filehub-attachment.php`, `inc/class-filehub-rest-api.php`) [Verified]
- [x] **Task 5: Admin Dashboard UI & Pure CSS Toggles** (`inc/class-filehub-admin.php`, `assets/css/filehub-admin.css`) [Verified]
- [x] **Task 6: Shortcodes & Public Drag-and-Drop Uploader** (`inc/class-filehub-shortcodes.php`, `assets/js/filehub-public.js`) [Verified]
- [x] **Task 7: GitHub Automatic Update Checker & CI Release Builder** (`inc/class-filehub-updater.php`, `.github/workflows/release.yml`) [Verified]
- [x] **Task 8: Front-End Screens (Login, Profile, Password Change, Live Search & AJAX Delete)** (`inc/class-filehub-shortcodes.php`, `assets/js/filehub-public.js`, `inc/class-filehub-rest-api.php`) [Verified]
- [x] **Task 9: Front-End User Registration Shortcode & REST API** (`[filehub_register]`, `POST /wp-json/filehub/v1/register`) [Verified]
- [x] **Task 10: WooCommerce-Style Automatic Page Assignments & Sleek Tabbed WP Admin UI** (`wp_dropdown_pages`, `the_content` auto-injection filter, `.nav-tab-wrapper`) [Verified]
- [x] **Task 11: Real WordPress Playground Server E2E & REST QA Testing** (`npx @wp-playground/cli` on Port 9400 - 100% Passed) [Verified]
- [x] **Task 12: Chunked / Resumable upload handler for multi-gigabyte files.** [Verified]
- [x] **Task 13: Per-user custom quota override setting in WP Admin user profile.** [Verified]

## Converged Tasks (Pending)
- [x] **Task 14: Add 3-Retry Logic for Chunk Uploads** — Implemented by the shared client API retry helper; verified by client/HTTP checks.
- [x] **Task 15: Enterprise Features Automated Tests** — Covered by `.tests/test-regressions.php`, `test-http.py` and `test-concurrency.py` (quotas, resumable chunks, byte equality and races).

### 📋 Backlog
- (Empty)

### 🚦 Release Readiness
- Status: Historical; superseded by the 2026-10-25 verification below
- Next Step: See the current remediation verification below


## 2026-10-25: v1.7.0 Security Remediation (Current)
Historical completion claims above do not certify the current revision.
- [x] Record ADR-0003 and synchronize the memory bank before source edits.
- [x] Map F01-F29 into `.tasks/remediation-plan.md`.
- [x] Private storage, owner isolation and legacy migration (F01-F03, F07-F08, F12, F27).
- [x] Transactional uploads and cloud transfer (F04-F06, F09-F14, F17-F18, F28).
- [x] Listings, account, input policy and abuse controls (F15-F16, F20-F21).
- [x] Updater and release gates (F19, F22-F23).
- [x] UI, statistics, feature accuracy and accessibility (F24-F26, F29).
- [x] Regression verification and repeat sentinel-audit; update findings with evidence.

Local verification: 32/32 WordPress, 15/15 real HTTP, 14/14 client checks, 2/2 concurrent reservation checks; syntax, catalog, version and package checks passed. Remote CI and real provider/host checks are deployment follow-up, not executed claims.
