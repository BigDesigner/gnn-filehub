# Project Sprint Pipeline

## Current Sprint: v0.3.0-alpha (Real WordPress Playground Server QA Certified)

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

### 📋 Backlog
- Chunked / Resumable upload handler for multi-gigabyte files.
- Per-user custom quota override setting in WP Admin user profile.

### 🚦 Release Readiness
- Status: 100% QA Certified & Verified on Real WP Playground Server (`http://127.0.0.1:9400`)
- Next Step: Ready for production release.
