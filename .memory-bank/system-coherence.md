# System Coherence & Operational Rules

## 1. Overview
This document defines the operational protocol and system coherence guidelines for the Sentinel Agent Memory Bank governing the **GNN FileHub NextGen** project workspace.

## 2. Session Protocols
- **Start Protocol:** Inspect `.memory-bank/active-session.json`, `.tasks/pipeline.md`, and `.tasks/handoff.md` before taking any action.
- **Lock Protocol:** Check for `.memory-bank/.session.lock`. If lock exists and is younger than 10 minutes, halt execution. Delete lock on clean completion.
- **Atomic State Writes:** Write session updates to `.memory-bank/active-session.tmp.json` before overwriting `.memory-bank/active-session.json`.

## 3. Pre-Flight & Operational Checklists

### Pre-Change Checklist
1. Verify target file paths and specs in `.specs/boundary-conditions.md`.
2. Confirm zero external dependency policy (WP Core APIs only).
3. Ensure no security regressions (`X-WP-Nonce`, `permission_callback`, `.htaccess` isolation).

### Post-Change Checklist
1. Run syntax validation (`php -l` on modified PHP files).
2. Update `.memory-bank/changelog/verified-worklog.md`.
3. Update `.tasks/pipeline.md` with task progress.
4. Record any unresolved issues in `.memory-bank/bugs/bug-list.md`.

## 4. Architectural Boundaries
- **Language Standard:** All `.memory-bank/`, `.specs/`, `.agents/`, and `.tasks/` files MUST be written in English.
- **Interactive Reports:** Output to the user in their preferred language (`Turkish`).
- **Code Preservation:** Never delete legacy documentation without creating an archive under `.archive/docs-migration/<DATE>/` and updating `.memory-bank/migration-map.md`.
