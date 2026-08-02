# Bug List & Issue Tracking

| Bug ID | Title / Vulnerability | Type | Status | Source / Evidence | Confidence | Next Action |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| SEC-001 | Missing Nonce / CSRF in legacy upload & delete | Security | Resolved in Spec | `implementation_plan.md` | Verified | Enforce `X-WP-Nonce` & REST `permission_callback` |
| SEC-002 | Direct script execution risk in upload path | Security | Resolved in Spec | `implementation_plan.md` | Verified | Enforce `filehub-protected/` with `.htaccess` `Deny from all` |
| PERF-001 | Disk lock via `scandir()` I/O bottleneck | Performance | Resolved in Spec | `implementation_plan.md` | Verified | Replace `scandir()` with `attachment` CPT DB queries |
