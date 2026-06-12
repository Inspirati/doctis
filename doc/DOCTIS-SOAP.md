# Doctis SOAP API — Audit and Extension Plan

**Date:** 2026-06-12
**Branch at time of audit:** `git-structure-2`
**Scope:** `api/soap/` (17 files, ~9 000 lines)

---

## 1. Background

Doctis inherits the MantisBT SOAP layer intact.  MantisBT exposes its data
via a `SoapServer` that auto-discovers every PHP function whose name begins
with `mc_`.  The server is bootstrapped in `mantisconnect.php`, which sources
`mc_core.php`, which in turn includes the per-domain API files.

A parallel document (`dwg`) CRUD set — `mc_dwg_api.php` — was added by
Doctis, mirroring `mc_issue_api.php` in structure.  This gives documents
first-class SOAP support equivalent to that of bugs.

---

## 2. File inventory

| File | Lines | Role |
|------|------:|------|
| `mantisconnect.php` | ~80 | Entry point; starts `SoapServer`, discovers `mc_*` functions |
| `mc_core.php` | ~60 | Central include; sources all `mc_*_api.php` files |
| `mc_api.php` | ~1 400 | Shared helpers: login, marshalling, enum conversion, fault generation |
| `mc_account_api.php` | ~120 | User and license account helpers |
| `mc_enum_api.php` | ~400 | Enumeration endpoints |
| `mc_filter_api.php` | ~550 | Filter retrieval and search |
| `mc_issue_api.php` | ~2 300 | Bug/issue CRUD — 16 public methods |
| `mc_dwg_api.php` | ~2 400 | Document CRUD — 16 public methods *(Doctis)* |
| `mc_issue_attachment_api.php` | ~130 | Issue file-attachment endpoints |
| `mc_project_attachment_api.php` | ~130 | Project-level file-attachment endpoints |
| `mc_project_api.php` | ~1 400 | Project management — 21 public methods |
| `mc_file_api.php` | ~260 | File upload/download helpers; extended for `dwg` type |
| `mc_config_api.php` | ~60 | Config retrieval |
| `mc_custom_field_api.php` | ~60 | Custom field definitions |
| `mc_user_pref_api.php` | ~55 | User preferences |
| `mc_user_profile_api.php` | ~80 | Developer environment profiles |
| `mc_tag_api.php` | ~210 | Tag management |

### Notable omission in `mc_core.php`

`mc_dwg_api.php` is **not** explicitly `require_once`'d in `mc_core.php`.
Its `mc_dwg_*` functions are discovered at runtime by `mantisconnect.php`
because `mc_dwg_api.php` is included elsewhere before the discovery loop runs.
This is fragile.  See §7.1.

---

## 3. Public SOAP methods — complete list (91 total)

### 3.1 Authentication and version (2)

| Method | Returns | Notes |
|--------|---------|-------|
| `mc_login(u, p)` | AccountData + access level + timezone | All methods below require valid `u`/`p` |
| `mc_version()` | string | Returns `MANTIS_VERSION`; no auth required |

### 3.2 Document management — Doctis (16)

Operates on `{documents}` / `DwgData`.

| Method | Returns |
|--------|---------|
| `mc_dwg_exists(u, p, dwg_id)` | bool |
| `mc_dwg_get(u, p, dwg_id, fields[])` | DwgData |
| `mc_dwg_get_history(u, p, dwg_id)` | HistoryEvent[] |
| `mc_dwg_get_biggest_id(u, p, project_id)` | int |
| `mc_dwg_get_id_from_title(u, p, title)` | int |
| `mc_dwg_add(u, p, issue)` | dwg_id |
| `mc_dwg_update(u, p, dwg_id, issue)` | bool |
| `mc_dwg_delete(u, p, dwg_id)` | bool |
| `mc_dwg_note_add(u, p, dwg_id, note)` | dwgnote_id |
| `mc_dwg_note_update(u, p, note)` | timestamp |
| `mc_dwg_note_delete(u, p, dwgnote_id)` | bool |
| `mc_dwg_relationship_add(u, p, dwg_id, rel)` | relationship_id |
| `mc_dwg_relationship_delete(u, p, dwg_id, rel_id)` | bool |
| `mc_dwg_set_tags(u, p, dwg_id, tags[])` | bool |
| `mc_dwgs_get(u, p, project_id, filter, page, per_page)` | DwgData[] + page info |
| `mc_dwgs_get_header(u, p, project_id, filter, page, per_page)` | DwgHeaderData[] |

### 3.3 Issue/bug management — upstream MantisBT (16)

Parallel to §3.2; operates on `{bugs}` / `BugData`.  Methods: `mc_issue_exists`,
`mc_issue_get`, `mc_issue_get_history`, `mc_issue_get_biggest_id`,
`mc_issue_get_id_from_summary`, `mc_issue_add`, `mc_issue_update`,
`mc_issue_delete`, `mc_issue_note_add`, `mc_issue_note_update`,
`mc_issue_note_delete`, `mc_issue_relationship_add`,
`mc_issue_relationship_delete`, `mc_issue_set_tags`, `mc_issues_get`,
`mc_issues_get_header`.

### 3.4 Enumerations (13)

`mc_enum_status`, `mc_enum_priorities`, `mc_enum_severities`,
`mc_enum_reproducibilities`, `mc_enum_projections`, `mc_enum_etas`,
`mc_enum_resolutions`, `mc_enum_access_levels`, `mc_enum_project_status`,
`mc_enum_project_view_states`, `mc_enum_view_states`,
`mc_enum_custom_field_types`, `mc_enum_get`.

All return `{id, name}` tuple arrays; names are localised.

### 3.5 Filtering and search (6)

`mc_filter_get`, `mc_filter_get_issues`, `mc_filter_get_issue_headers`,
`mc_filter_search_issues`, `mc_filter_search_issue_ids`,
`mc_filter_search_issue_headers`.

All six operate on **bugs only**.  There is no document equivalent.

### 3.6 Project management (21)

`mc_project_add/update/delete`, `mc_project_get_id_from_name`,
`mc_projects_get_user_accessible`, `mc_project_get_issues`,
`mc_project_get_issue_headers`, `mc_project_get_issues_for_user`,
`mc_project_get/add/delete/rename_category*`, `mc_project_get_*versions*`,
`mc_project_version_add/update/delete`, `mc_project_get_custom_fields`,
`mc_project_get_users`, `mc_project_get_all_subprojects`,
`mc_project_get_attachments`.

`mc_project_get_issues_for_user` accepts a `'document'` filter type
(Doctis addition).

### 3.7 File attachments (6)

`mc_issue_attachment_get/add/delete`,
`mc_project_attachment_get/add/delete`.

No document-attachment equivalents (`mc_dwg_attachment_*`) exist.

### 3.8 Tags, config, preferences, profiles (7)

`mc_tag_add/delete/get_all`, `mc_config_get_string`,
`mc_user_pref_get_pref`, `mc_user_profiles_get_all`.

---

## 4. Data structures

### 4.1 DwgData (document)

Doctis-specific fields on top of the IssueData baseline:

| Field | Type | Notes |
|-------|------|-------|
| `title` | string | Document title |
| `author` | string | |
| `publisher` | string | |
| `number` | string | Document number |
| `edition` | string | |
| `revision` | string | |
| `reference` | string | External reference code |
| `link_url` | string | URL to external document system |
| `classification` | string | e.g. UNCLASSIFIED |
| `revision_date` | datetime | |
| `release_date` | datetime | |

Fields shared with IssueData: `id`, `summary`, `description`, `project`,
`category`, `priority`, `severity`, `status`, `resolution`, `view_state`,
`creator`/`reporter`, `handler`, `created_at`, `updated_at`, `due_date`,
`custom_fields`, `attachments`, `notes`, `relationships`, `monitors`,
`tags`, `sticky`.

`status` uses the `dwg_status` enum rather than the MantisBT `status` enum.

### 4.2 DwgNoteData

Identical in structure to `IssueNoteData` (BugNoteData).

### 4.3 DwgHeaderData

Lightweight list row: `id`, `title`, `project`, `status`, `priority`,
`severity`, `created_at`, `updated_at`.

### 4.4 Other structures (upstream, unchanged)

`IssueData`, `IssueHeaderData`, `IssueNoteData`, `AttachmentData`,
`RelationshipData`, `ProjectData`, `ProjectVersionData`, `CategoryData`,
`AccountData`, `TagData`, `FilterData`, `ProfileData`.

---

## 5. Doctis additions already in the SOAP layer

### 5.1 `mc_dwg_api.php` — document CRUD

Full parallel implementation of all 16 issue methods for documents.
Internally calls the same Doctis core APIs used by the web layer:

- `DwgAddCommand` for creation
- `dwg_get()`, `dwg_update()`, `dwg_delete()`, `dwg_exists()` for CRUD
- `dwgnote_*()` for notes
- `dwg_relationship_*()` for relationships
- `dwg_get_monitors()`, `dwg_monitor()`, `dwg_unmonitor()` for monitors
- `file_dwg_get_visible_attachments()` for note attachments
- `filter_dwg_get_dwg_rows()` for list/search

### 5.2 `mc_api.php` — shared helper extensions

- `mci_license_get(license_id, lang, detail)` — marshal license entity
- `mci_get_license_id(license, default)` — resolve license object to ID
- `mci_get_license_status_id(enum)` — license status enum conversion
- `mci_get_license_view_state_id(enum)` — license view-state conversion
- `mci_get_document(document_id)` — retrieve document title
- `mci_get_document_id(document, project_id)` — resolve document to ID

### 5.3 `mc_account_api.php` — license account helpers

- `mci_license_get_array_by_id(license_id)` — single license marshalling
- `mci_license_get_array_by_ids(license_ids[])` — bulk license marshalling

### 5.4 `mc_file_api.php` — extended for `dwg` type

`mci_file_get()` handles `'dwg'` alongside `'bug'` and `'doc'`, querying
`{dwg_file}` and checking `access_has_dwg_level()`.

### 5.5 `mc_project_api.php` — document filter in `get_issues_for_user`

Accepts `'document'` as a `filter_type` value, delegating to
`filter_create_document()`.

---

## 6. What is missing / incomplete

### 6.1 `mc_dwg_api.php` not in `mc_core.php`

**Risk:** The file is picked up via a side-effect of another include rather
than an explicit `require_once`.  If include order changes the `mc_dwg_*`
functions will not be exposed.

**Fix (one line):** add to `mc_core.php`:
```php
require_once( $t_current_dir . 'mc_dwg_api.php' );
```

### 6.2 No document attachment endpoints (`mc_dwg_attachment_*`)

The SOAP layer has `mc_issue_attachment_get/add/delete` but no document
equivalents.  Callers cannot upload or download note attachments on documents
via SOAP.

**Needed:**
- `mc_dwg_attachment_get(u, p, attachment_id)` → base64 content
- `mc_dwg_attachment_add(u, p, dwg_id, name, mime_type, content)` → attachment_id
- `mc_dwg_attachment_delete(u, p, attachment_id)` → bool

Implementation pattern: copy `mc_issue_attachment_api.php`, replace `bug`/`issue`
with `dwg`, use `file_dwg_*` API functions.

### 6.3 No primary document file endpoints

The `{dwg_primary_file}` table (one canonical file per document record,
always stored in the GIT backend) is not exposed via SOAP at all.

**Needed:**
- `mc_dwg_primary_get(u, p, dwg_id)` → PrimaryFileData (filename, size, date, description, download_url)
- `mc_dwg_primary_upload(u, p, dwg_id, name, mime_type, content, description)` → bool (calls `file_dwg_primary_add()`)
- `mc_dwg_primary_delete(u, p, dwg_id)` → bool (calls `file_dwg_primary_delete()`)

New struct `PrimaryFileData`: `filename`, `filesize`, `file_type`, `date_added`,
`description`, `download_url`.

Access control: upload/delete gate on `update_dwg_threshold`;
get/download gate on `view_dwg_threshold`.

### 6.4 No document filter endpoints (`mc_dwg_filter_*`)

The six `mc_filter_*` methods all operate on bugs.  There are no document
equivalents, so SOAP clients cannot execute saved document filters or search
documents by structured criteria.

**Needed:**
- `mc_dwg_filter_get(u, p, project_id)` → DwgFilterData[] (user's saved document filters)
- `mc_dwg_filter_search(u, p, filter_struct, page, per_page)` → DwgData[]
- `mc_dwg_filter_search_ids(u, p, filter_struct, page, per_page)` → int[]
- `mc_dwg_filter_search_headers(u, p, filter_struct, page, per_page)` → DwgHeaderData[]

Implementation: delegate to `filter_dwg_*` API functions (already used inside
`mc_dwg_api.php` for `mc_dwgs_get`).

### 6.5 No license CRUD endpoints

Helper functions for marshalling license data exist in `mc_api.php` and
`mc_account_api.php`, but there are no public `mc_license_*` SOAP methods.
External clients cannot query which licenses a document requires, or manage
licenses, via SOAP.

**Needed (if `$g_licenses_enabled = ON`):**
- `mc_license_get(u, p, license_id)` → LicenseData
- `mc_license_get_all(u, p, project_id)` → LicenseData[]
- `mc_dwg_license_add(u, p, dwg_id, license_id)` → bool
- `mc_dwg_license_delete(u, p, dwg_id, license_id)` → bool
- `mc_dwg_license_get(u, p, dwg_id)` → LicenseData[] (licenses required by a document)

New struct `LicenseData`: `id`, `name`, `type`, `status`, `match_str`,
`description`, `project` (or global), `view_state`, `access_min`.

All endpoints should return an empty result or be omitted entirely when
`$g_licenses_enabled = OFF` (consistent with the web layer guard).

### 6.6 Tag-setting commented out in `mc_dwg_add`

In `mc_dwg_api.php` the line that sets tags on a newly-created document is
commented out:
```php
// mci_tag_set_for_issue( $p_issue_id, $p_issue['tags'], $t_user_id );
```
The issue equivalent in `mc_issue_api.php` is active.  Tags on documents
therefore cannot be set via `mc_dwg_add`.

**Fix:** uncomment and rename to the dwg-specific equivalent, or verify that
`mci_tag_set_for_issue` works generically for both entity types.

### 6.7 `mc_enum_dwg_status` missing

`mc_dwgs_get` and `mc_dwg_get` use `dwg_status` enum values, but there is no
`mc_enum_dwg_status()` endpoint.  Clients have no machine-readable way to
discover the document status values and their IDs.

**Needed:**
- `mc_enum_dwg_status(u, p)` → `{id, name}[]`

Implementation: one-liner delegating to `mci_enum_get_array_by_id` with
`'dwg_status_enum_string'` config key.

### 6.8 REST API has no document CRUD

The REST layer (`api/rest/restcore/`) has no `/dwg` or `/documents` routes.
`pages_rest.php` provides a single `/dwg/view/{id}` endpoint that renders the
web view page — useful for embedding but not for machine consumption.

This is a separate concern from SOAP but worth noting: any external integration
that prefers REST over SOAP must currently use the web UI for all document
operations.

---

## 7. Plan — recommended work items

Items are grouped by priority.  None of §6.2–6.8 is required for the SOAP layer
to function; the existing `mc_dwg_*` methods cover the core document workflow.

### Priority 1 — Correctness fixes (low effort, high value)

| # | Item | File(s) | Effort |
|---|------|---------|--------|
| 7.1 | Add explicit `require_once` for `mc_dwg_api.php` in `mc_core.php` | `mc_core.php` | Minutes |
| 7.2 | Uncomment / fix tag-setting in `mc_dwg_add` | `mc_dwg_api.php` | < 1 hour |
| 7.3 | Add `mc_enum_dwg_status` endpoint | `mc_enum_api.php` | < 1 hour |

### Priority 2 — Document attachment SOAP methods (medium effort)

| # | Item | File(s) | Effort |
|---|------|---------|--------|
| 7.4 | `mc_dwg_attachment_get/add/delete` | new `mc_dwg_attachment_api.php` | 1–2 days |
| 7.5 | Add `mc_dwg_attachment_api.php` to `mc_core.php` | `mc_core.php` | Minutes |

The new file should mirror `mc_issue_attachment_api.php` exactly, substituting
`dwg` for `bug`/`issue` and `file_dwg_*` for `file_*` API calls.  Access
control follows `upload_dwg_file_threshold` / `download_attachments_threshold`.

### Priority 3 — Primary document file SOAP methods (medium effort)

| # | Item | File(s) | Effort |
|---|------|---------|--------|
| 7.6 | `mc_dwg_primary_get/upload/delete` | new `mc_dwg_primary_api.php` | 1–2 days |
| 7.7 | `PrimaryFileData` struct definition | `mc_dwg_primary_api.php` | Included above |
| 7.8 | Add to `mc_core.php` | `mc_core.php` | Minutes |

Upload encodes file content as base64 (same pattern as `mc_issue_attachment_add`),
decodes and passes decoded bytes to `file_dwg_primary_add()`.  Download uses
`file_dwg_primary_get_content()` and returns base64-encoded result.

### Priority 4 — Document filter SOAP methods (medium-high effort)

| # | Item | File(s) | Effort |
|---|------|---------|--------|
| 7.9 | `mc_dwg_filter_get/search/search_ids/search_headers` | new `mc_dwg_filter_api.php` | 2–3 days |
| 7.10 | `DwgFilterData` struct definition | included above | — |
| 7.11 | Add to `mc_core.php` | `mc_core.php` | Minutes |

Delegate to `filter_dwg_*` API functions.  The filter struct mirrors the bug
filter struct; document-specific fields (title, number, revision, etc.) need
their own filter keys.

### Priority 5 — License SOAP methods (medium effort, conditional)

| # | Item | File(s) | Effort |
|---|------|---------|--------|
| 7.12 | `mc_license_get/get_all` | new `mc_license_api.php` | 1 day |
| 7.13 | `mc_dwg_license_add/delete/get` | `mc_license_api.php` | 1 day |
| 7.14 | `LicenseData` struct definition | included above | — |
| 7.15 | Guard all license endpoints with `$g_licenses_enabled` check | `mc_license_api.php` | Included above |
| 7.16 | Add to `mc_core.php` | `mc_core.php` | Minutes |

All marshalling helpers already exist in `mc_api.php` and
`mc_account_api.php`.  The missing piece is the public endpoint layer.

### Priority 6 — REST document CRUD (high effort, separate track)

| # | Item | File(s) | Effort |
|---|------|---------|--------|
| 7.17 | `/dwg` REST routes: GET, POST, PUT, DELETE | new `documents_rest.php` | 3–5 days |
| 7.18 | `/dwg/{id}/primary-file` REST routes | `documents_rest.php` | 1 day (if §7.6 done first) |
| 7.19 | `/dwg/{id}/attachments` REST routes | `documents_rest.php` | 1 day (if §7.4 done first) |
| 7.20 | Wire new routes into Slim app bootstrap | `api/rest/index.php` | Hours |

REST work is independent of SOAP and should only be undertaken once the SOAP
layer is complete — SOAP is the more mature integration path for existing clients.

---

## 8. Access control summary for planned endpoints

| Endpoint group | Read gate | Write gate | Delete gate |
|----------------|-----------|------------|-------------|
| Document attachments | `view_dwg_threshold` | `upload_dwg_file_threshold` | `delete_attachments_threshold` |
| Primary document file | `view_dwg_threshold` | `update_dwg_threshold` | `update_dwg_threshold` |
| Document filters | `view_dwg_threshold` | — | — |
| Licenses | `view_dwg_threshold` | `manage_license_threshold` | `manage_license_threshold` |

All gates use `access_has_dwg_level()` for document-scoped checks and
`access_has_project_level()` for project-scoped checks, consistent with the
existing `mc_dwg_*` implementation.  When `$g_licenses_enabled = OFF` all
license endpoints return an empty result rather than a fault.

---

## 9. New file plan

```
api/soap/
├── mc_core.php                   — add 3 require_once lines (§7.1, 7.5, 7.8, 7.11, 7.16)
├── mc_enum_api.php               — add mc_enum_dwg_status (§7.3)
├── mc_dwg_api.php                — fix tag-setting (§7.2)
├── mc_dwg_attachment_api.php     — NEW (§7.4)
├── mc_dwg_primary_api.php        — NEW (§7.6)
├── mc_dwg_filter_api.php         — NEW (§7.9)
└── mc_license_api.php            — NEW (§7.12–7.15)
```

---

## 10. Relationship to REST API

The REST layer currently provides:
- Full bug/issue CRUD (via `issues_rest.php`)
- Project management (via `projects_rest.php`)
- User, filter, config, lang endpoints
- Document view-page render only (via `pages_rest.php` `/dwg/view/{id}`)

SOAP provides full document CRUD; REST does not.  Clients that prefer REST
must use the web UI for document operations until §7.17–7.20 is implemented.

There is no technical blocker to implementing REST in parallel with SOAP — the
underlying Doctis core API functions are transport-agnostic — but SOAP is the
better-established path and should be completed first.

---

## 11. Testing approach

Each new or corrected SOAP method should be exercised with a SoapClient
script before merging.  The existing pattern is:

```php
$client = new SoapClient( 'http://10.0.0.10/doctis/api/soap/mantisconnect.php?wsdl' );
$result = $client->mc_dwg_get( 'manager', '', $dwg_id );
var_dump( $result );
```

A dedicated `admin/test-soap.php` script (parallel to `admin/test-git-php.php`)
should be created to cover all planned methods in sequence.  Test sequence for
primary file methods:

1. `mc_dwg_primary_get` on a document with no file — expect null/empty
2. `mc_dwg_primary_upload` — upload a small file, verify return is true
3. `mc_dwg_primary_get` — verify filename, size, description returned
4. Download URL in returned struct — verify file content via HTTP GET
5. `mc_dwg_primary_upload` again — verify replace semantics (old file gone from git HEAD)
6. `mc_dwg_primary_delete` — verify row removed and git soft-delete committed
7. `mc_dwg_primary_get` — verify returns null/empty again
