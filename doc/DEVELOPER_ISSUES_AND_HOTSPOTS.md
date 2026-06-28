# Doctis — Developer Issues, Hotspots & Production Readiness Plan

*Generated 2026-04-30 from git history analysis and codebase inspection.*

---

## Executive Summary

Doctis is a solid fork of MantisBT 2.27 with well-structured parallel APIs for the document (`dwg`) domain. The core logic is functional and the Docker tooling makes the dev environment reproducible. The outstanding gaps are concentrated in three areas: **data-model ambiguities** in the `dwg`/`document` layer, **missing REST API coverage** for documents and licenses, and **deployment hardening** (credentials, bootstrap behaviour, incomplete install scripts).

---

## Part 1 — Identified Issues

### 1. Vague Commit History
All Doctis-origin commits carry messages like "Doctis update", "Doctis development", "Doctis fixes to dwg versus bug filter". There is no way to identify *what* changed or *why* from `git log`.

**Impact:** Bisecting regressions, reviewing changes during upstream syncs, and onboarding contributors are all significantly harder.

**Plan:** Adopt a commit convention going forward (e.g. `dwg: fix filter field mapping for severity column`). Consider a lightweight git hook.

---

### 2. Data Model Ambiguities (HIGH PRIORITY)

#### 2a. `classification` field duplicated across tables
`core/dwg_api.php:805`:
```
// @TODO RobD - beware we currently have field 'classification' in both tables;
// either drop one or be specific as right now it'd be indeterminate
```
The `documents` table and the `bugs` table both carry a `classification` column. When a JOIN or shared query touches both, the result is undefined.

**Fix:** Explicitly qualify all `classification` references with their table alias (`t_documents.classification` vs `t_bugs.classification`), then decide which table owns the canonical value.

#### 2b. `document_api.php` status field mix-up
`core/document_api.php:571`:
```
// @TODO RobD - beware, we have mixed up the use of the table status fields
// between re-use of the 'bug' table meaning and that of the 'category' table
```

**Fix:** Audit all uses of `status` in `document_api.php`. Map each to the correct semantic — document lifecycle status vs. category active/inactive flag — and add constants or a comment block documenting which is which.

#### 2c. DWGNOTE constant at index 0
`core/constant_inc.php:643`:
```php
define( 'DWGNOTE', 0 );  // @TODO RobD - bump this up to index 4 ?
```
Using 0 as a type discriminator is accident-prone (zero is falsy in PHP).

**Fix:** Change to a non-zero value (e.g. 4) and update all downstream switch/case statements. Verify with a grep for `DWGNOTE`.

---

### 3. Filter Layer Technical Debt (MEDIUM PRIORITY)

`core/filter_api.php` lines 101, 1156, 1192 contain three separate "temporary quick'n'dirty hack" comment blocks that route document filtering through the bug filter pipeline without refactoring the call signatures. These are not temporary.

**Fix:** Extract a shared `$p_entity_type` parameter or a strategy object into `filter_get_bug_rows()`. The groundwork already exists in `DwgFilterQuery.class.php` — complete the abstraction there and remove the hacks.

**Hotspot files:**
- [core/filter_api.php](../core/filter_api.php) — lines 101, 1156, 1192
- [core/filter_dwg_api.php](../core/filter_dwg_api.php) — `@TODO RobD` on reuse line 96
- [core/classes/DwgFilterQuery.class.php](../core/classes/DwgFilterQuery.class.php) — commented-out `$table` property (line 112)

---

### 4. Email Notifications — Missing Document Fields (MEDIUM PRIORITY)

`core/email_dwg_api.php` contains at least 10 `@TODO RobD` notes marking places where bug-oriented fields (`severity`, `reproducibility`, `resolution`, `target_version`) are referenced but documents do not carry those fields. The current code either silently skips or may cause notices.

**Fix:** For each marked location, either:
- Remove the field from the document email template, or
- Map to the equivalent document concept (e.g. `priority` → document urgency).

Run the email path manually against a test document to flush out any remaining PHP notices.

---

### 5. `document_api.php` Stale File Header

The file header in `core/document_api.php` reads:
```
* Category API
* @subpackage CategoryAPI
```
It was clearly duplicated from `category_api.php` and never updated.

**Fix:** Update the docblock to reflect the actual content (`Document API`, `@subpackage DocumentAPI`). Low-risk, but it misleads new developers significantly.

---

### 6. REST API — No Document or License Endpoints (HIGH PRIORITY)

`api/rest/index.php` loads only the standard MantisBT REST routers:
- `issues_rest.php`
- `projects_rest.php`
- `users_rest.php`
- `filters_rest.php`

There are no equivalent `documents_rest.php` or `licenses_rest.php` files. The Command layer (`DwgAddCommand.php`, `LicenseCreateCommand.php`, etc.) exists but is only used internally, not wired to HTTP routes.

**Plan:**
1. Create `api/rest/restcore/documents_rest.php` mirroring the structure of `issues_rest.php`.
2. Register Slim routes: `GET/POST /api/rest/v1/documents`, `GET/PATCH/DELETE /api/rest/v1/documents/{id}`.
3. Wire to `DwgAddCommand`, `DwgViewPageCommand`, etc.
4. Create `api/rest/restcore/licenses_rest.php` similarly.
5. Update `api/rest/swagger.json` with the new endpoint specs.

---

### 7. Deployment Credentials (HIGH PRIORITY)

#### 7a. `docker-live/docker-compose.yml`
Hard-coded credentials:
```yaml
DOCTIS_DB_PASS: password
MARIADB_ROOT_PASSWORD: password
MARIADB_PASSWORD: password
```

**Fix:** Replace all credential values with references to Docker secrets or environment-variable files (`.env`, mounted secrets). Document this in the README.

#### 7b. `docker-live/bootstrap.sh`
The bootstrap script:
- Connects using the MariaDB **root** user (not the application user)
- Calls `doctis-drop-and-create-new-database.sh` **every** container start, destroying all data

```bash
DBUSER="root"   # should be the application user
/var/www/html/admin/tools/doctis-drop-and-create-new-database.sh   # runs every start
```

**Fix:**
1. Switch bootstrap to use the application DB user.
2. Guard the DB init call: only run if the schema does not already exist (check for a sentinel table or row count).

#### 7c. Default admin credentials
README documents default credentials as `administrator` / `root`. These must be prominently marked as **must-change** items before any external exposure.

---

### 8. Install Scripts — Stub Handlers (MEDIUM PRIORITY)

`admin/tools/install-check.sh` defines four environment-detection handlers that are all stubs:
```bash
install_vbox()    { echo "[INFO] ..."; # TODO: add steps here }
install_qemu()    { echo "[INFO] ..."; # TODO: add steps here }
install_droplet() { echo "[INFO] ..."; # TODO: add steps here }
install_baremetal(){ echo "[INFO] ..."; # TODO: add steps here }
```

The installer correctly detects the environment but then does nothing environment-specific.

**Fix:** Either implement the steps or collapse all cases into a single generic Linux installer path until each environment-specific path is needed.

---

### 9. Bulk Document Import — No UI

README explicitly documents this limitation:
> *There is currently no built-in user interface support for bulk adding documents to the database.*

**Plan:** A minimal CSV import page (`manage_import_data_page.php` already exists for issues) should be extended or duplicated for documents. Fields to map: document reference number, title, URL, project, status, category.

---

### 10. `composer.json` — Package Identity Not Updated

```json
"name": "mantisbt/mantisbt"
```

This still identifies the package as upstream MantisBT.

**Fix:** Change to `inspirati/doctis`. Low risk, important for Packagist/dependency tooling clarity.

---

### 11. PHP Version Constraint vs. Production Image Mismatch

`composer.json` platform constraint:
```json
"config": { "platform": { "php": "7.4.33" } }
```

Production Dockerfile:
```dockerfile
FROM php:8.2-apache
```

The composer platform lock prevents dependencies from being resolved for PHP 8.2 features, while the actual runtime is 8.2. This is intentional (compatibility floor) but should be documented explicitly and the floor should be raised to at least 8.1 in line with MantisBT's own `PHP_MIN_VERSION`.

---

## Part 2 — Hotspots for New Developers

These are the files you will spend the most time in. Read them before touching anything else.

### Hotspot 1: `core/dwg_api.php`

The heart of document tracking. Contains the `BugData`-style class for documents, all CRUD functions, and the DB mapping layer. Pay attention to:
- Lines 131–156: the split between new document fields and legacy bug fields (both marked `@TODO RobD`)
- Line 379–395: validation is partially commented out or incomplete
- Line 805: `classification` field ambiguity (see Issue 2a above)

### Hotspot 2: `core/filter_api.php` + `core/filter_dwg_api.php`

The filter system is where bugs and dwgs diverge most sharply. The three hacks at lines 101, 1156, 1192 of `filter_api.php` are the main reason the dwg filter works at all. Do not remove them without a full refactor of `DwgFilterQuery`. Any change to filter column logic in `columns_api.php` likely needs a parallel change in `columns_dwg_api.php`.

### Hotspot 3: `core/email_dwg_api.php`

All document notification emails flow through here. It is a near-copy of `email_api.php` with many fields that do not apply to documents left in place (marked with `@TODO`). If email notifications are broken, start here.

### Hotspot 4: `config_defaults_inc.php`

Both MantisBT and Doctis defaults live here. The file is 6400 lines. Doctis additions are scattered throughout; search for `$g_dwg_` to find them (137 occurrences). Any new document configuration option must be added here first, then overridden in `config/config_inc.php` for specific deployments.

### Hotspot 5: `dwg_view_inc.php`

The most frequently changed file in recent commits (appears in 4 of the last 6 `Doctis update` commits). This renders the document view page and is the primary user-facing surface for the document feature. It drives `DwgViewPageCommand.php`.

### Hotspot 6: `core/document_api.php`

Misnamed, contains document-as-category logic. The stale file header is misleading. This is separate from `dwg_api.php` — it handles the *reference data* for documents (their identifying metadata, categories) rather than the lifecycle tracking.

### Hotspot 7: `core/license_api.php`

The License entity is entirely Doctis-specific with no MantisBT equivalent. A good file to read when getting familiar with Doctis's own patterns outside the MantisBT fork.

---

## Part 3 — Pre-Production Checklist

| Item | Status | Priority |
|------|--------|----------|
| Replace hard-coded DB credentials in docker-compose | Not done | Critical |
| Guard DB bootstrap against data loss on restart | Not done | Critical |
| Resolve `classification` field ambiguity | **Done** — explicit SELECT in `dwg_cache_row()`, `dwg_cache_array_rows()`, `DwgFilterQuery` | High |
| Change default admin password in docs / enforce on first login | Not done | High |
| Add REST API routes for documents | Not done | High |
| `DWGNOTE = 0` — not a bug; same value as `BUGNOTE` is intentional (both mean "entity note" in their domain) | N/A | ~~High~~ |
| Resolve email_dwg_api.php missing-field TODOs | Not done | Medium |
| Implement or stub-collapse install-check.sh handlers | Not done | Medium |
| Add bulk import UI for documents | Not done | Medium |
| Fix `document_api.php` file header | Not done | Low |
| Update `composer.json` package name | Not done | Low |
| Adopt descriptive commit message convention | Ongoing | Medium |

---

## Part 4 — Recommended First Tasks for New Developers

1. **Read** `CLAUDE.md` and `README.md` in full.
2. **Spin up** the dev Docker environment (`docker/docker-compose.dev.yml`) and log in.
3. **Create a document** in the UI; follow the code path from `dwg_create_page.php` → `dwg_create.php` → `DwgAddCommand.php` → `dwg_api.php::create()`.
4. **Read** `core/filter_api.php` lines 95–200 and `core/filter_dwg_api.php` to understand the filter split.
5. **Pick one** of the `@TODO RobD` items from `core/email_dwg_api.php` and resolve it.
6. **Write** a failing test in `tests/` to document a discovered gap before fixing it.

---

## Part 5 — Implementation Priority Plan (added 2026-06-28)

Issues are ordered by the risk they pose to data correctness and production safety.
Each item includes a test strategy so coverage can be verified before and after the fix.

### Priority 0 — Immediate Data Correctness (fix before any further feature work)

#### P0-A: `classification` / `link_url` / `id` column shadowing in `dwg_cache_row()`

`core/dwg_api.php` line ~794:
```php
SELECT * FROM {dwg} a INNER JOIN {documents} b ON a.document_id=b.id WHERE a.id=?
```
`SELECT *` on a JOIN causes `doc.id`, `doc.classification`, and `doc.link_url` to
overwrite the corresponding `dwg` columns in every ADOdb result row. This is the
single-document fetch path — it is called on every document view page load.

*Also noted:* `DwgFilterQuery::build_main()` had the same bug and was fixed in the
`db-optimise` branch (commit `3335de2`). This is the remaining occurrence.

**Fix:** Replace `SELECT *` with an explicit column list, aliasing the conflicting
`doc` columns (`doc.classification AS doc_classification`, etc.), mirroring the
fix already applied in `DwgFilterQuery`.

**Test strategy:** Create a document where `dwg.classification = 'SECRET'` and the
linked `documents.classification = 'UNCLASSIFIED'`. Load the document view page and
assert the displayed classification reads `SECRET`. After the fix the correct value
must appear; before it reads `UNCLASSIFIED` (the `doc` column silently wins).

#### P0-B: `DWGNOTE = 0` in `core/constant_inc.php:643`

Zero is falsy in PHP. Any `if($t_type)` check or switch fall-through that treats
zero as "no type set" will silently misclassify a document note. The existing value
was noted as needing a bump to `4`.

**Fix:** Change `define('DWGNOTE', 0)` to `define('DWGNOTE', 4)` and update every
`switch` / `case` / comparison that references `DWGNOTE` or the literal `0` in a
type-discriminator context.

**Test strategy:** `grep -rn 'DWGNOTE\|case 0' core/` to enumerate all call sites
before the change. After the change: add a note to a document and assert it is
stored with `type = 4` and retrieved without being dropped by a falsy check. A
constant-value assertion (`$this->assertNotEquals(0, DWGNOTE)`) in PHPUnit acts as
a permanent regression guard.

---

### Priority 1 — High-Impact Correctness

#### P1-A: Status field semantic confusion in `document_api.php`

Line ~571: the same `status` column conflates document lifecycle state
(110=pending … 195=archived) with category active/inactive (0/1). Requires an
audit of every `status` reference in `core/document_api.php`.

**Test strategy:** For each `@TODO RobD` near `status`, assert before and after:
`document_get($id)->status` must match a lifecycle enum value, not a boolean.
Run `phpunit` after each individual fix.

#### P1-B: Email notifications — missing document fields (`core/email_dwg_api.php`)

Ten-plus `@TODO RobD` markers where bug-oriented fields (`severity`,
`reproducibility`, `resolution`, `target_version`) are referenced but do not exist
on documents. Causes PHP notices on every document-update email.

**Test strategy:** Enable email debug logging; trigger every document lifecycle
transition (create → assign → review → sign → archive); grep the PHP error log for
notices. After fixes: zero notices for the full lifecycle.

---

### Priority 2 — Before Any External Deployment

#### P2-A: Docker credentials and destructive bootstrap (`docker-live/`)

Hard-coded `password` everywhere; bootstrap drops the database on every container
restart.

**Fix:** Replace credential literals with Docker secrets / `.env` references. Guard
the DB init call so it only runs if the schema does not already exist.

**Test strategy:** `grep -r "password" docker-live/` must return zero credential
literals. Bring up the stack, insert a sentinel row, `docker compose restart`,
assert the sentinel row survives.

---

### Priority 3 — REST API (enables programmatic clients)

#### P3-A: Document and license REST endpoints

No `documents_rest.php` or `licenses_rest.php` exist. The Command layer is ready.

**Approach:** TDD — write failing tests in `tests/rest/` first, then implement Slim
routes. Order: `GET /documents` → `GET /documents/{id}` → `POST /documents` →
`PATCH /documents/{id}` → `DELETE /documents/{id}` → licenses.

---

### Priority 4 — Medium Cleanup

#### P4-A: Filter layer hacks (`core/filter_api.php` lines 101, 1156, 1192)

Load-bearing but fragile. Refactor risk is high — tackle after the REST suite
provides a regression safety net.

**Test strategy:** Write integration tests covering every filter property before
touching the code; those tests become the refactor guard.

#### P4-B: Bulk document import (no UI)

**Test strategy:** Prepare a CSV with 5 rows including edge cases (missing optional
fields, duplicate reference numbers). Import; assert 5 `dwg` rows created, correct
metadata, duplicate reference rejected with a clean error.

#### P4-C: Install script stub handlers (`admin/tools/install-check.sh`)

Collapse `install_vbox / install_qemu / install_droplet / install_baremetal` to a
single generic Linux path until each environment-specific variant is needed.

---

### Priority 5 — Low-Risk Housekeeping (batch together)

| Item | File | Action |
|------|------|--------|
| Stale file header | `core/document_api.php` | Update docblock to `Document API` |
| Package identity | `composer.json` | Change name to `inspirati/doctis` |
| PHP platform floor | `composer.json` | Raise `platform.php` to `8.1`; run `composer update` |
| Commit convention | repo root | Add `.gitmessage` template; document in `CONTRIBUTING.md` |
