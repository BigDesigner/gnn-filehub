# GNN Filehub 1.7.0

A WordPress file manager with private local storage, Cloudflare R2 and Google Drive, resumable upload sessions, quotas and member account screens. Production code uses WordPress APIs and vanilla JavaScript without Composer or NPM packages.

## Requirements and private storage

- WordPress 6.0+, PHP 8.0+ (64-bit), PHP Fileinfo, and PHP Zip for DOCX/XLSX/PPTX validation.
- A writable private directory **outside the actual web document root**. The default is a uniquely named sibling of the WordPress installation. Configure an absolute path in wp-config.php when the web-server user cannot create it:

```php
define('GNN_FILEHUB_PRIVATE_DIR', '/srv/filehub-private');
```

Uploads fail closed when the private directory or MIME validator is unavailable. Download streams use owner/admin authorization, private cache headers and no direct filesystem URLs. Guest files are downloadable only by administrators. FileHub records do not appear in the standard public WordPress media API. Exclude FileHub account/upload/manager pages and API responses from any reverse-proxy or full-page cache; purge page/asset caches during upgrades. The plugin also sends private/no-store headers and marks its pages non-cacheable.

On upgrade, the old uploads/filehub-protected tree is atomically moved outside the web root where filesystem permissions allow it. Indexed files then migrate in batches without changing their IDs. If an atomic move is unavailable, individual files are copied, hash-verified and removed from the old location. **Resolve any migration notice before treating an old installation as migrated**; an old public directory still needs web-server access denial until relocation finishes. Missing old files are reported rather than silently discarded.

Keep existing local storage and a database backup together. Changing an R2 account/bucket or a Google account is a data migration: keep access to the account containing existing files. Switching the active provider does not move existing cloud objects. Previously overwritten content cannot be reconstructed from metadata.

## Uploads, quotas and recovery

The browser receives a server-generated session and bearer token. The server reserves bytes before accepting content, validates exact chunk sizes and SHA-256 checksums, verifies the assembled MIME type, and commits one attachment. Uploaded parts can be retried; a lost completion response returns the same committed record. Re-select the same file in the same tab to resume an unfinished upload. Tokens and progress persist for up to six hours; file contents are not stored in browser storage.

Browser chunk size adapts to PHP upload/post limits, up to 1 MiB. Validated cloud transfers advance in separate requests of at most 8 MiB. R2 uses multipart uploads; Google Drive uses resumable sessions and provider-reported offsets. Old unrestricted /upload, /upload-chunk, /r2-presign and /r2-finalize clients receive a retirement response and must refresh/use the session API. Browser-to-bucket CORS is no longer needed. URLs already issued by an older version remain valid until their original expiry (up to one hour); rotate the old signing key or allow that window to expire during upgrade.

- Three unfinished uploads per owner; guests share a separate guest quota and session pool.
- Default staging limits: 10 GiB per owner/file and 20 GiB across uploads and cloud download staging.
- Two staged cloud downloads per owner; reservations are released on shutdown and expired records are cleaned by scheduled maintenance.
- Per-file limit 0 disables the administrator's per-file setting; operational staging limits still apply. Default quota 0 means unlimited committed storage.
- Rates limit registration, new sessions, chunk submission and cloud transfer requests.

Operators can tune staging limits in server-side PHP using filehub_staging_limit_bytes and filehub_site_staging_limit_bytes filters. Ensure disk space and a working WordPress cron schedule. R2 buckets must be private. Retain R2's incomplete-multipart lifecycle cleanup; [Cloudflare documents its default seven-day abort policy](https://developers.cloudflare.com/r2/objects/upload-objects/). Drive session recovery follows [Google's resumable upload protocol](https://developers.google.com/workspace/drive/api/guides/manage-uploads).

Failed physical deletion keeps the WordPress record. Deletion from WordPress uses the same provider check; removing a user retains remaining assets under administrator ownership when no reassignment was requested. Provider credentials must remain available for cleanup. Do not rotate to another account while unfinished cloud uploads require cleanup.

## Content validation and antivirus

MIME validation is always enabled and does not claim to detect every malicious file. Optionally select a local ClamAV service in Settings: TCP 127.0.0.1:3310 or the standard Unix socket /var/run/clamav/clamd.ctl. The daemon must be installed, updated and configured by the server operator. When enabled, an unavailable scanner, scan error, size limit or malware result prevents publication. Configure ClamAV StreamMaxLength and timeouts for the site's allowed file size.

A custom trusted scanner may also use the filehub_scan_file filter: return true only after approval, or WP_Error/false to reject. Raw executable, script, SVG and HTML extensions are not in the supported extension allowlist.

## Member and administrator screens

Use [filehub_account], [filehub_uploader], [filehub_manager] and [filehub_admin_files], or assign/create pages in FileHub settings. The manager provides server search, pagination, deletion and image/PDF preview up to 8 MiB. Administrators have per-user usage/quota analysis. Display-name changes and password changes are independent; password changes refresh the browser session.

New accounts require the site's registration policy checks and an email confirmation before login. Passwords require at least 12 characters. Configure WordPress email delivery before enabling registration. Existing accounts are unaffected by the new-account confirmation policy.

The filename-renaming switch affects display names; physical keys are always unique. Storage meters identify FileHub-tracked usage rather than inventing provider capacity when a real quota is unavailable.
