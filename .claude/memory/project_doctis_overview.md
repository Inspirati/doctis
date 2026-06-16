---
name: Doctis project overview
description: What Doctis is, its architecture, key files, and production readiness gaps
type: project
originSessionId: bdfed52c-eca0-4531-a353-5719c4f50f73
---
Doctis is a fork of MantisBT 2.27 (PHP/MariaDB) extended to track **documents** ("dwg") and issues raised against them during formal review cycles. Also adds a **License** entity (review programme grouping).

**Why:** A formal document-review tracking system tailored to engineering/compliance workflows.

**How to apply:** All work should preserve minimal diff from MantisBT upstream. Parallel `dwg_*` APIs mirror `bug_*` APIs. Upstream synced via `upstream_sync` branch.

Key files: `core/dwg_api.php`, `core/document_api.php`, `core/license_api.php`, `core/filter_api.php` (hacks at lines 101/1156/1192), `dwg_view_inc.php` (most-edited view file), `config_defaults_inc.php` ($g_dwg_* settings, 137 occurrences).

Outstanding production gaps (as of 2025-12):
- Hard-coded `password` credentials in `docker-live/docker-compose.yml`
- Bootstrap script drops DB every restart — needs sentinel guard
- No REST API endpoints for documents or licenses
- `classification` field exists in both `documents` and `bugs` tables — ambiguous
- `DWGNOTE` constant is 0 (falsy) — should be bumped to non-zero
- 56 @TODO RobD comments across codebase; ~10 in email_dwg_api.php for missing document fields
- No bulk import UI for documents
- `document_api.php` has wrong file header ("Category API")
