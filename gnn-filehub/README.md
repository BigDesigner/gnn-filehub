# GNN FileHub NextGen - WordPress Plugin

Ultra-fast, zero-dependency WordPress File Management plugin supporting Local Protected Storage, Cloudflare R2, Google Drive, Chunked Resumable Uploads, and Automatic Page Assignments.

## Features
- **Zero External Dependencies:** Built 100% with WordPress Core Native APIs.
- **Multiple Storage Engines:** Local Protected (`.htaccess` isolated), Cloudflare R2 (S3 SigV4), Google Drive API v3.
- **Chunked Resumable Uploads:** Multi-gigabyte file transfers split into 2MB chunks to bypass PHP limits.
- **Per-User Custom Quotas:** Set custom MB storage limits per user via WP Admin User Profile editor.
- **Front-End Screens:** Drag-and-Drop Uploader, Live File Manager, User Registration, Login, Profile, Password Change.
- **Automatic Page Assignments:** Dropdown page selectors in WP Admin with automatic `the_content` shortcode injection.
- **Automatic GitHub Updater:** Integrated version checker via GitHub Releases API.
