# ADR 0003: Private Storage and Transactional Upload Sessions

- **Status**: Accepted
- **Confidence**: Verified
- **Date**: 2026-10-25
- **Category**: Auth
- **Supersedes**: [ADR-0001](0001-initial-stack.md), [ADR-0002](0002-cloud-native-uploads-and-membership-ux.md)
- **Superseded By**: None

## Context
Audit f0d2946 identified 29 remediation groups. Public attachment metadata, guest identity 0, unreserved quotas, mutable R2 signed PUTs and unbounded temporary uploads violate the intended isolation. The user authorized all remediation on 2026-10-25, with records updated before implementation.

## Decision
1. Retain WordPress attachments/postmeta, REST cookie sessions, native HTTP, vanilla JS, isolated settings groups and the unified account UX. No production Composer/NPM dependencies.
2. FileHub attachments are private and excluded from standard public media queries/routes. Only positive owners and administrators may download; guest uploads are administrator-only assets.
3. New local files and temporary sessions use a validated private directory outside the document root. Existing legacy local objects migrate without overwriting. Failure is visible and prevents unsafe new storage. This deliberately replaces the uploads/.htaccess-only location; original specifications remain preserved as historical requirements.
4. Server-issued upload sessions reserve real bytes under a per-principal atomic database lock. Sessions bind owner, immutable filename, expected size, driver/configuration and accepted chunk size. Persistent progress, expiry, cleanup, retry and cancel are part of the wire contract. All content is locally validated before cloud publication.
5. Retire unrestricted browser R2 presign/finalize endpoints with HTTP 410. Transfer validated files to R2 multipart and Google Drive resumable sessions in bounded native HTTP requests. No client can rewrite committed objects. This trades PHP disk staging and additional requests for enforceable MIME, size and resource policies.
6. UUID storage keys, verified provider deletion, rollback and WordPress deletion hooks protect object/index consistency. Ordinary WordPress media stays outside FileHub mutation scope.
7. SQL aggregate statistics, paginated server search, typed settings/REST input, session refresh after password changes, registration policy hooks and rate limits are shared contracts.
8. Release checks must validate version/package consistency and regression tests. Feature claims must match implemented behavior. Malware scanning is an explicit integration boundary, never an implied built-in antivirus guarantee.

## Consequences
Private directory write permissions and disk staging space are required. The default staging cap is explicit and configurable through a server filter. Existing cloud credentials remain supported. A configuration change cannot silently redirect in-flight sessions. External cloud performance and hosting configuration still require deployment verification.

## Lineage & Migration
ADR-0001's public uploads path and ADR-0002's reusable presigned PUT model are superseded. Existing metadata is guarded immediately, local objects migrate in bounded batches, and legacy endpoint clients receive an actionable retirement response. Existing account shortcodes, setting names, file IDs and cloud objects remain usable. Do not erase existing files, options or documentation during migration.

## Evidence
- `.memory-bank/audits/audit-f0d2946.md`
- `.tasks/remediation-plan.md`
- `gnn-filehub/inc/`
- `.github/workflows/release.yml`

## Verification follow-up

Implemented and locally verified in v1.7.0. Reservations use custom upload/download tables and an atomic unique SQL insert with compare-and-delete leases; large local I/O renews leases. See ADR-0004 for antivirus and verification scope. Deployment checks remain explicitly separate.
