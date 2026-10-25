# ADR 0004: Local Antivirus and Isolated Release Verification

- **Status**: Accepted
- **Confidence**: Verified
- **Date**: 2026-10-25
- **Category**: External Integrations
- **Supersedes**: None
- **Superseded By**: None

## Context
ADR-0003 defines validated, private ingestion. MIME inspection alone is not antivirus detection. The audit also found historical tests unrelated to current API behavior and a release workflow without gates.

## Decision
Offer explicit optional ClamAV INSTREAM scanning against only the standard local TCP or Unix socket. Do not install a daemon or enable scanning implicitly. Scanner failure, timeout, size rejection or a malware verdict prevents publication. Additional trusted scanners can use filehub_scan_file. Email confirmation uses a separate expiring single-use token; guest upload access is a session capability, while downloads require a positive owner or administrator. These are explicit public-route authentication exceptions to the old blanket nonce-only wording.

Use the actual WordPress installer, plugin dbDelta migrations, REST dispatch and filesystem in an isolated runtime. Mock only external provider HTTP and email. Add real cookie/multipart HTTP tests, concurrent PHP processes and actual JavaScript contract tests. Gate release packaging on a reusable MySQL/PHP/WordPress CI matrix, version/catalog consistency and a clean plugin archive. Workflow action majors were checked against their official repositories.

## Consequences
A real local antivirus service, working email and production storage permissions remain operator prerequisites. Local verification used WordPress 7.0.2/PHP 8.5.8/SQLite; provider responses and the ClamAV verdict were stand-ins. Remote MySQL/PHP matrix and real service credentials are not claimed as executed. No production Composer/NPM dependency was added.

## Evidence
- `.tests/test-regressions.php`
- `.tests/test-http.py`
- `.tests/test-concurrency.py`
- `.tests/test-client.cjs`
- `.memory-bank/audits/verification-f0d2946-v1.7.0.json`
- `.github/workflows/verify.yml`
- `gnn-filehub/inc/class-filehub-security.php`
