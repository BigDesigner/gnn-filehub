# Bug List & Issue Tracking

| Bug ID | Title / Vulnerability | Type | Status | Source / Evidence | Confidence | Next Action |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| SEC-001 | Missing Nonce / CSRF in legacy upload & delete | Security | Resolved in Spec | `implementation_plan.md` | Verified | Enforce `X-WP-Nonce` & REST `permission_callback` |
| SEC-002 | Direct script execution risk in upload path | Security | Resolved in Spec | `implementation_plan.md` | Verified | Enforce `filehub-protected/` with `.htaccess` `Deny from all` |
| PERF-001 | Disk lock via `scandir()` I/O bottleneck | Performance | Resolved in Spec | `implementation_plan.md` | Verified | Replace `scandir()` with `attachment` CPT DB queries |
| DATA-001 | Shared Settings API option group wiped other tabs' options on save | Data Loss | Resolved | User report; `class-filehub-admin.php` | Verified (WP Playground) | Split into `filehub_general_group` / `filehub_pages_group` / `filehub_storage_group` |
| DATA-002 | Downloaded files lost their extension (`post_title` is extension-less) | Data Integrity | Resolved | User report ("download.zip" → "download") | Verified | Added `_filehub_file_name` postmeta, used for download `Content-Disposition` and file-list display |
| UPLOAD-001 | Chunk-merged temp file rejected by `is_uploaded_file()` in local driver | Functional | Resolved | User report ("Hata: geçersiz dosya yükleme isteği") | Verified | Also accept files verified under `sys_get_temp_dir()` via `realpath()` prefix check |
| UPLOAD-002 | Concurrent chunk uploads could double-create the attachment on merge | Concurrency | Resolved | Introduced by raising chunk concurrency to 3 | Verified | Blocking `flock()` lock around the "last chunk / should merge" check |
| CLOUD-001 | Google Drive large-file uploads (~500MB) "succeeded" in UI but never appeared in Drive | Reliability | Resolved | User report + code review (`file_get_contents()` exhausting PHP memory limit) | Verified (real 500MB upload on gnn.tr) | Switched to Drive resumable upload protocol, streamed from disk in 8MB chunks |
| CLOUD-002 | R2 upload/download buffered whole file in PHP memory (same class as CLOUD-001) | Reliability | Resolved | Code review, proactive fix alongside CLOUD-001 | Verified | Raw cURL streamed PUT (`CURLOPT_INFILE`) for upload, temp-file streaming for download |
| UX-001 | Upload queue reported "N dosya başarıyla yüklendi!" regardless of actual per-file success | Correctness | Resolved | Discovered testing CLOUD-001/002 with intentionally-broken credentials | Verified | Threaded real success/failure through `onSettled()` for every upload path; accurate summary |
| UX-002 | Upload progress froze at a silent "%100" while server merged chunks / relayed to cloud, reading as a stuck page | UX | Resolved | User report | Verified | Dynamic "Dosya işleniyor, lütfen bekleyin..." status once bytes are fully received, across all 3 upload paths |
| SEC-003 | `wp_login_form()` never fires core's `login_form` action, breaking login-security plugins (e.g. Defender reCAPTCHA) that hook it | Compatibility | Resolved | User screenshot ("Error: reCAPTCHA verification failed") | Verified (mock `login_form` listener on WP Playground; real Defender not installed there) | Manually fire `do_action('login_form')` and splice output before `</form>` |
| SEC-004 | IDOR/BOLA on `/filehub/v1/download/{id}`: any attachment ID downloadable by any visitor, `permission_callback` set to `__return_true` with the promised in-handler ownership check never implemented | Security | Resolved | User-provided audit report (`.memory-bank/audits/audit-c459049.md`), confirmed by code review — `handle_delete_file()` already had the correct ownership pattern, `handle_download_file()` did not | Verified (code review, `php -l` clean) | Added the same `post_author === current_user_id() OR manage_options` check used by `handle_delete_file()` to `handle_download_file()` |
| SEC-005 | Regression from SEC-004: legitimate owners got "Bu dosyayı indirme yetkiniz yok" on their own files | Regression | Resolved | User report, immediately after SEC-004 shipped | Verified (code review) | The download link is a plain `<a href>` navigation, which can't carry an `X-WP-Nonce` header — without it, WordPress's REST cookie-auth layer (`rest_cookie_check_errors()`) doesn't resolve the request to the real logged-in user, so `get_current_user_id()` saw 0 inside the handler and SEC-004's new ownership check rejected the actual owner. Fixed by appending the same `wp_rest` nonce as a `_wpnonce` query arg (`build_download_url()`) to every generated download URL, which WordPress accepts from `$_REQUEST` the same as the header. |

## 2026-10-25 remediation

F01-F29 were implemented and locally verified in v1.7.0; closure evidence is tracked in `.tasks/remediation-plan.md`. Earlier resolved labels do not certify deployment/service configuration.

| Audit IDs | Status | Evidence |
|---|---|---|
| F01 | Implemented / locally verified | R01, R02, R24, R31; HTTP owner/anonymous checks |
| F02 | Implemented / locally verified | R03, R31 |
| F03 | Implemented / locally verified | R04, R30; HTTP scanner rejection |
| F04 | Implemented / locally verified | R05, R28, R32; concurrent PHP workers |
| F05 | Implemented / locally verified | R10, R20, R27; real multipart HTTP |
| F06 | Implemented / locally verified | R06, R18 |
| F07 | Implemented / locally verified | R07 |
| F08 | Implemented / locally verified | R08, R22; live hosting configuration remains a deployment check |
| F09 | Implemented / locally verified | R09, R18 |
| F10 | Implemented / locally verified | R02, R06, R29 |
| F11 | Implemented / locally verified | R12, R20, R29; HTTP deletion |
| F12 | Implemented / locally verified | R11, R31 |
| F13 | Implemented / locally verified | R02, R10; multi-chunk byte-identical HTTP; client contract |
| F14 | Implemented / locally verified | R18, R26, R27; real multipart HTTP; client contract |
| F15 | Implemented / locally verified | R13; HTTP search; client contract |
| F16 | Implemented / locally verified | R25; three real HTTP session-change checks |
| F17 | Implemented / locally verified | R14 |
| F18 | Implemented / locally verified | R19, R26 |
| F19 | Implemented / locally verified | R17; static hook review |
| F20 | Implemented / locally verified | R15, R16, R30 |
| F21 | Implemented / locally verified | R21, R24; actual HTTP login |
| F22 | Implemented / locally verified | 32 WP scenarios, 15 HTTP checks, 14 client checks, 2 concurrency checks; remote CI configured, not yet run |
| F23 | Implemented / locally verified | check-release.py; local ZIP verification |
| F24 | Implemented / locally verified | R23; client contract; HTTP complete stream |
| F25 | Implemented / locally verified | R14, R23, R32; static query review |
| F26 | Implemented / locally verified | R09, R30; scanner protocol HTTP; client contract; real engine/theme checks remain deployment-scoped |
| F27 | Implemented / locally verified | R08, R22 |
| F28 | Implemented / locally verified | R14 |
| F29 | Implemented / locally verified | 333-message catalog check; client contract; static keyboard/redirect review |
