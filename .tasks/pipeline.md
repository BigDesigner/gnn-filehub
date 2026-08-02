# Project Sprint Pipeline

## Current Sprint: v0.1.0-alpha (Greenfield Plugin & GitHub Auto-Updater Complete)

### 📌 Active Tasks
- [x] **Task 1: Plugin Bootstrap & Core Class** (`gnn-filehub-nextgen.php`, `inc/class-filehub-core.php`) [Verified]
- [x] **Task 2: Storage Interface & Protected Local Storage Driver** (`inc/storage/class-storage-interface.php`, `inc/storage/class-storage-local.php`) [Verified]
- [x] **Task 3: Cloud Storage Drivers via WP HTTP API** (`inc/storage/class-storage-r2.php`, `inc/storage/class-storage-gdrive.php`) [Verified]
- [x] **Task 4: Attachment CPT Handler & REST Controller** (`inc/class-filehub-attachment.php`, `inc/class-filehub-rest-api.php`) [Verified]
- [x] **Task 5: Admin Dashboard UI & Pure CSS Toggles** (`inc/class-filehub-admin.php`, `assets/css/filehub-admin.css`) [Verified]
- [x] **Task 6: Shortcodes & Public Drag-and-Drop Uploader** (`inc/class-filehub-shortcodes.php`, `assets/js/filehub-public.js`) [Verified]
- [x] **Task 7: GitHub Automatic Update Checker & CI Release Builder** (`inc/class-filehub-updater.php`, `.github/workflows/release.yml`) [Verified]

### 📋 Backlog
- Chunked / Resumable upload handler for multi-gigabyte files.
- Per-user storage quota enforcement and visual alerts.

### 🚦 Release Readiness
- Status: 100% Complete & Verified (`php -l` passed on 11/11 PHP files)
- Next Step: Commit GitHub Auto-Updater feature.
