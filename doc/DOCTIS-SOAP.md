# Doctis SOAP API — Audit and Status

**Updated:** 2026-06-29
**Original audit date:** 2026-06-12 (branch `git-structure-2`)
**Current branch:** `admin-tools`
**Scope:** `api/soap/` (19 files, ~12 000 lines)

> **Usage examples** (curl envelopes, authentication, REST endpoints, server setup) are in
> [`doc/REST-AND-SOAP-API.md`](REST-AND-SOAP-API.md).  This document is the internal
> developer reference: file inventory, method catalogue, data structures, and remaining
> implementation gaps.

---

## 1. Background

Doctis inherits the MantisBT SOAP layer intact.  MantisBT exposes its data
via a `SoapServer` that auto-discovers every PHP function whose name begins
with `mc_`.  The server is bootstrapped in `mantisconnect.php`, which sources
`mc_core.php`, which in turn includes the per-domain API files via explicit
`require_once` statements.

A parallel document (`dwg`) CRUD set — `mc_dwg_api.php` — was added by
Doctis, mirroring `mc_issue_api.php` in structure.  A document attachment API
(`mc_dwg_attachment_api.php`) and a primary document file API
(`mc_dwg_primary_api.php`) have since been added, giving documents first-class
SOAP support that now exceeds the issue attachment surface.

---

## 2. File inventory

| File | Lines | Role |
|------|------:|------|
| `mantisconnect.php` | ~80 | Entry point; starts `SoapServer`, discovers `mc_*` functions |
| `mc_core.php` | ~50 | Central include; sources all `mc_*_api.php` files via explicit `require_once` |
| `mc_api.php` | ~1 400 | Shared helpers: login, marshalling, enum conversion, fault generation |
| `mc_account_api.php` | ~120 | User and license account helpers |
| `mc_enum_api.php` | ~400 | Enumeration endpoints; includes `mc_enum_dwg_status` |
| `mc_filter_api.php` | ~550 | Filter retrieval and search (bugs only) |
| `mc_issue_api.php` | ~2 300 | Bug/issue CRUD — 16 public methods |
| `mc_dwg_api.php` | ~2 100 | Document CRUD — 16 public methods *(Doctis)* |
| `mc_issue_attachment_api.php` | ~130 | Issue file-attachment endpoints |
| `mc_dwg_attachment_api.php` | ~170 | Document file-attachment endpoints *(Doctis — new)* |
| `mc_dwg_primary_api.php` | ~200 | Primary document file endpoints *(Doctis — new)* |
| `mc_project_attachment_api.php` | ~130 | Project-level file-attachment endpoints |
| `mc_project_api.php` | ~1 400 | Project management — 21 public methods |
| `mc_file_api.php` | ~260 | File upload/download helpers; extended for `dwg` type |
| `mc_config_api.php` | ~60 | Config retrieval |
| `mc_custom_field_api.php` | ~60 | Custom field definitions |
| `mc_user_pref_api.php` | ~55 | User preferences |
| `mc_user_profile_api.php` | ~80 | Developer environment profiles |
| `mc_tag_api.php` | ~210 | Tag management |

---

## 3. Public SOAP methods — complete list (98 total)

### 3.1 Authentication and version (2)

| Method | Returns | Notes |
|--------|---------|-------|
| `mc_login(u, p)` | AccountData + access level + timezone | All methods below require valid `u`/`p` |
| `mc_version()` | string | Returns `MANTIS_VERSION`; no auth required |

### 3.2 Document management — Doctis (22)

Operates on `{documents}` / `{dwg}` / `{dwg_text}` via `DwgData`.

Core CRUD (16):

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
| `mc_dwgs_get(u, p, dwg_ids[])` | DwgData[] |
| `mc_dwgs_get_header(u, p, dwg_ids[])` | DwgHeaderData[] |

Attachments (3) — in `mc_dwg_attachment_api.php`:

| Method | Returns |
|--------|---------|
| `mc_dwg_attachment_get(u, p, attachment_id)` | base64Binary content |
| `mc_dwg_attachment_add(u, p, dwg_id, name, file_type, content)` | attachment_id |
| `mc_dwg_attachment_delete(u, p, attachment_id)` | bool |

Primary document file (3) — in `mc_dwg_primary_api.php`:

| Method | Returns |
|--------|---------|
| `mc_dwg_primary_get(u, p, dwg_id)` | PrimaryFileData (or empty) |
| `mc_dwg_primary_upload(u, p, dwg_id, name, file_type, content[, description])` | bool |
| `mc_dwg_primary_delete(u, p, dwg_id)` | bool |

### 3.3 Issue/bug management — upstream MantisBT (16)

Parallel to §3.2; operates on `{bugs}` / `BugData`.  Methods: `mc_issue_exists`,
`mc_issue_get`, `mc_issue_get_history`, `mc_issue_get_biggest_id`,
`mc_issue_get_id_from_summary`, `mc_issue_add`, `mc_issue_update`,
`mc_issue_delete`, `mc_issue_note_add`, `mc_issue_note_update`,
`mc_issue_note_delete`, `mc_issue_relationship_add`,
`mc_issue_relationship_delete`, `mc_issue_set_tags`, `mc_issues_get`,
`mc_issues_get_header`.

### 3.4 Enumerations (14)

`mc_enum_status`, `mc_enum_priorities`, `mc_enum_severities`,
`mc_enum_reproducibilities`, `mc_enum_projections`, `mc_enum_etas`,
`mc_enum_resolutions`, `mc_enum_access_levels`, `mc_enum_project_status`,
`mc_enum_project_view_states`, `mc_enum_view_states`,
`mc_enum_custom_field_types`, `mc_enum_get`,
**`mc_enum_dwg_status`** *(Doctis — new)*.

All return `{id, name}` tuple arrays; names are localised.

### 3.5 Filtering and search (6)

`mc_filter_get`, `mc_filter_get_issues`, `mc_filter_get_issue_headers`,
`mc_filter_search_issues`, `mc_filter_search_issue_ids`,
`mc_filter_search_issue_headers`.

All six operate on **bugs only**.  There is no document equivalent — see §7.1.

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

### 3.7 File attachments (12)

Issue attachments (3): `mc_issue_attachment_get/add/delete`
Document attachments (3): `mc_dwg_attachment_get/add/delete`
Primary document file (3): `mc_dwg_primary_get/upload/delete`
Project attachments (3): `mc_project_attachment_get/add/delete`

The `content` parameter in all `_add` and `_get` variants uses `xsd:base64Binary`.
See §5.5 for the double-encoding requirement when calling from raw curl.

### 3.8 Tags, config, preferences, profiles (7)

`mc_tag_add/delete/get_all`, `mc_config_get_string`,
`mc_user_pref_get_pref`, `mc_user_profiles_get_all`.

---

## 4. Data structures

### 4.1 DwgData (document)

Doctis-specific fields on top of the IssueData baseline:

| Field | Type | Source table |
|-------|------|-------------|
| `title` | string | `documents` |
| `author` | string | `documents` |
| `publisher` | string | `documents` |
| `number` | string | `documents` |
| `edition` | string | `documents` |
| `revision` | string | `documents` |
| `reference` | string | `documents` |
| `link_url` | string | `documents` |
| `classification` | string | `dwg` (shadowed from `documents`) |
| `revision_date` | datetime | `documents` |
| `release_date` | datetime | `documents` |
| `description` | string | `dwg_text` |
| `steps_to_reproduce` | string | `dwg_text` |
| `additional_information` | string | `dwg_text` |

Fields shared with IssueData: `id`, `summary` (vestigial — always blank, see §6.3),
`project`, `category`, `priority`, `status`, `view_state`, `creator`/`reporter`,
`handler`, `created_at`, `updated_at`, `due_date`, `custom_fields`, `attachments`,
`notes`, `relationships`, `monitors`, `tags`, `sticky`.

`status` uses the `dwg_status` enum rather than the MantisBT `status` enum.
Use `mc_enum_dwg_status` to discover valid values.

### 4.2 PrimaryFileData

Returned by `mc_dwg_primary_get` when a primary file exists; empty struct when not.

| Field | Type | Notes |
|-------|------|-------|
| `filename` | string | Original upload filename |
| `filesize` | int | Bytes |
| `file_type` | string | MIME type |
| `date_added` | datetime | Upload timestamp |
| `description` | string | Optional description text |
| `download_url` | string | Direct download URL for the file |

### 4.3 DwgNoteData

Identical in structure to `IssueNoteData` (BugNoteData).

### 4.4 DwgHeaderData

Lightweight list row: `id`, `title`, `project`, `status`, `priority`,
`created_at`, `updated_at`.

### 4.5 Other structures (upstream, unchanged)

`IssueData`, `IssueHeaderData`, `IssueNoteData`, `AttachmentData`,
`RelationshipData`, `ProjectData`, `ProjectVersionData`, `CategoryData`,
`AccountData`, `TagData`, `FilterData`, `ProfileData`.

---

## 5. Doctis additions in the SOAP layer — current state

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
- `mci_tag_set_for_dwg()` for tag assignment on create (active)

Loaded explicitly by `mc_core.php` line 38.

### 5.2 `mc_dwg_attachment_api.php` — document attachments

Mirrors `mc_issue_attachment_api.php` with `dwg` substituted for `bug`/`issue`.
Uses `file_dwg_*` API functions.  Access control: read uses `view_dwg_threshold`;
write/delete use `upload_dwg_file_threshold` / `delete_attachments_threshold`.

Loaded explicitly by `mc_core.php` line 39.

### 5.3 `mc_dwg_primary_api.php` — primary document file

Provides get/upload/delete for the single canonical file stored per document in
the GIT backend (or DISK/DATABASE if configured).  `mc_dwg_primary_upload` encodes
content as base64; `mc_dwg_primary_get` returns a `PrimaryFileData` struct.
Access control: read uses `view_dwg_threshold`; write/delete use `update_dwg_threshold`.

Loaded explicitly by `mc_core.php` line 40.

### 5.4 `mc_api.php` — shared helper extensions

- `mci_license_get(license_id, lang, detail)` — marshal license entity
- `mci_get_license_id(license, default)` — resolve license object to ID
- `mci_get_license_status_id(enum)` — license status enum conversion
- `mci_get_license_view_state_id(enum)` — license view-state conversion
- `mci_get_document(document_id)` — retrieve document title
- `mci_get_document_id(document, project_id)` — resolve document to ID

### 5.5 `mc_enum_api.php` — `mc_enum_dwg_status`

One-liner delegating to `mci_enum_get_array_by_id` with the `'dwg_status_enum_string'`
config key.  Returns the Doctis document status list (pending, received, assigned,
in-review, reviewed, approved, reserved, rejected, archived) as `{id, name}` tuples.

### 5.6 `mc_account_api.php` — license account helpers

- `mci_license_get_array_by_id(license_id)` — single license marshalling
- `mci_license_get_array_by_ids(license_ids[])` — bulk license marshalling

### 5.7 `mc_file_api.php` — extended for `dwg` type

`mci_file_get()` handles `'dwg'` alongside `'bug'` and `'doc'`, querying
`{dwg_file}` and checking `access_has_dwg_level()`.

### 5.8 `mc_project_api.php` — document filter in `get_issues_for_user`

Accepts `'document'` as a `filter_type` value, delegating to
`filter_create_document()`.

### 5.9 base64Binary double-encoding requirement

PHP's `SoapServer` pre-decodes `xsd:base64Binary` input before passing to the
PHP handler.  PHP's `SoapClient` compensates by double-encoding.  Raw `curl`
callers must therefore also double-encode content going IN and double-decode
content coming OUT.  See CLAUDE.md §SOAP API Testing for curl examples.  This
does not affect PHPUnit tests using SoapClient.

---

## 6. Status of previously-identified gaps

| Ref | Item | Status |
|-----|------|--------|
| 6.1 | `mc_dwg_api.php` not explicitly in `mc_core.php` | **FIXED** — now line 38; `mc_dwg_attachment_api.php` (line 39) and `mc_dwg_primary_api.php` (line 40) also added |
| 6.2 | No `mc_dwg_attachment_*` endpoints | **DONE** — `mc_dwg_attachment_api.php` (170 lines) |
| 6.3 | No `mc_dwg_primary_*` endpoints | **DONE** — `mc_dwg_primary_api.php` (200 lines) |
| 6.4 | No document filter SOAP endpoints | **Open** — see §7.1 |
| 6.5 | No license CRUD SOAP endpoints | **Open** — see §7.2; REST endpoints exist as the primary interface |
| 6.6 | Tag-setting commented out in `mc_dwg_add` | **FIXED** — `mci_tag_set_for_dwg()` called at line 1229 |
| 6.7 | `mc_enum_dwg_status` missing | **DONE** — `mc_enum_api.php` line 198 |
| 6.8 | REST API had no document CRUD | **DONE** — `documents_rest.php` and `licenses_rest.php` added (P3-A) |

### New issue — `mc_dwg_hash` naming

`mc_dwg_api.php` contains a function `mc_dwg_hash()` (line 2092) that computes
an ETag hash of a document record.  Because its name starts with `mc_`, the
SoapServer auto-discovers and registers it as a public SOAP method, even though
it is intended as an internal helper (the equivalent of `mci_issue_hash`, not
`mc_issue_hash`).  It has no WSDL entry and no access-control check.  It cannot
be called via a standard SoapClient but is callable via raw SOAP.

**Fix:** rename to `mci_dwg_hash` to match the internal helper naming convention
and remove it from the auto-discovered public surface.

---

## 7. Remaining work items

### 7.1 — Document filter SOAP methods (medium-high effort)

No SOAP equivalents exist for the six `mc_filter_*` methods, so SOAP clients
cannot execute saved document filters or perform structured document searches.

**Needed:**

| Function | Purpose |
|----------|---------|
| `mc_dwg_filter_get(u, p, project_id)` | Return user's saved document filters |
| `mc_dwg_filter_search(u, p, filter, page, per_page)` | Return DwgData[] matching filter |
| `mc_dwg_filter_search_ids(u, p, filter, page, per_page)` | Return matching dwg ids |
| `mc_dwg_filter_search_headers(u, p, filter, page, per_page)` | Return DwgHeaderData[] |

Implementation: delegate to `filter_dwg_*` API functions (already used inside
`mc_dwg_api.php` for `mc_dwgs_get`).  New file `mc_dwg_filter_api.php` +
`require_once` in `mc_core.php`.

New struct `DwgFilterData`: mirrors `FilterData`; document-specific fields
(title, number, revision, reference, classification, discipline) need their
own filter keys in the struct.

Effort: 2–3 days.

### 7.2 — License CRUD SOAP methods (medium effort, conditional on `$g_licenses_enabled`)

License management is currently REST-only (`/api/rest/licenses`).  The
marshalling helpers already exist in `mc_api.php` and `mc_account_api.php`.
The missing piece is the public SOAP endpoint layer.

**Needed:**

| Function | Purpose |
|----------|---------|
| `mc_license_get(u, p, license_id)` | Return LicenseData |
| `mc_license_get_all(u, p, project_id)` | Return LicenseData[] for a project (or global) |
| `mc_dwg_license_add(u, p, dwg_id, license_id)` | Associate a license requirement with a document |
| `mc_dwg_license_delete(u, p, dwg_id, license_id)` | Remove a license requirement |
| `mc_dwg_license_get(u, p, dwg_id)` | Return licenses required by a document |

All endpoints should return an empty result (not a fault) when
`$g_licenses_enabled = OFF`, consistent with the web layer guard.

New struct `LicenseData`: `id`, `name`, `type`, `status`, `match_str`,
`description`, `project`, `view_state`, `access_min`.

New file `mc_license_api.php` + `require_once` in `mc_core.php`.  Effort: 1–2 days.

### 7.3 — `mc_dwg_hash` naming fix (trivial)

Rename `mc_dwg_hash` → `mci_dwg_hash` in `mc_dwg_api.php` and update any
callers.  Removes the function from the auto-discovered public SOAP surface.
Effort: < 30 minutes.

---

## 8. Access control summary

| Endpoint group | Read gate | Write gate | Delete gate |
|----------------|-----------|------------|-------------|
| Document CRUD | `view_dwg_threshold` | `report_dwg_threshold` / `update_dwg_threshold` | `delete_dwg_threshold` |
| Document attachments | `view_dwg_threshold` | `upload_dwg_file_threshold` | `delete_attachments_threshold` |
| Primary document file | `view_dwg_threshold` | `update_dwg_threshold` | `update_dwg_threshold` |
| Document filters | `view_dwg_threshold` | — | — |
| Licenses | `view_dwg_threshold` | `manage_license_threshold` | `manage_license_threshold` |

All gates use `access_has_dwg_level()` for document-scoped checks and
`access_has_project_level()` for project-scoped checks, consistent with
the existing `mc_dwg_*` implementation.

---

## 9. Testing

The runnable smoke test covers 13 SOAP operations end-to-end:

```bash
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh"
```

See `admin/tools/README.md` for the full test sequence.  It is idempotent and
cleans up leftover state.  Exit code 0 = all pass.

For PHPUnit SOAP tests, bootstrap with `tests/bootstrap.php` and use a
`SoapClient` pointed at the vaio WSDL:

```php
$client = new SoapClient('http://10.0.0.10/doctis/api/soap/mantisconnect.php?wsdl');
$result = $client->mc_dwg_get('manager', '', $dwg_id);
```

For the primary file methods, the recommended test sequence is:

1. `mc_dwg_primary_get` with no file → expect empty `PrimaryFileData`
2. `mc_dwg_primary_upload` → upload a small file; verify `true` returned
3. `mc_dwg_primary_get` → verify filename, size, description
4. Download URL in returned struct → verify file content via HTTP GET
5. `mc_dwg_primary_upload` again → verify replace semantics (old file gone from git HEAD)
6. `mc_dwg_primary_delete` → verify row removed and git soft-delete committed
7. `mc_dwg_primary_get` → verify returns empty again

When implementing §7.1 or §7.2, add a dedicated `admin/test-soap-extensions.php`
script covering the new methods before merging.

---

## 10. Relationship to REST API

As of branch `admin-tools`:

| Domain | REST | SOAP |
|--------|------|------|
| Documents (CRUD) | **Done** — `/api/rest/documents` | **Done** — `mc_dwg_*` |
| Document attachments | Via REST document response | **Done** — `mc_dwg_attachment_*` |
| Primary document file | Not implemented | **Done** — `mc_dwg_primary_*` |
| Document filters | Via `GET /documents?project_id=` | Not implemented (§7.1) |
| Licenses (CRUD) | **Done** — `/api/rest/licenses` | Not implemented (§7.2) |
| Issues/bugs (CRUD) | **Done** — `/api/rest/issues` | **Done** — `mc_issue_*` |
| Projects | **Done** — `/api/rest/projects` | **Done** — `mc_project_*` |

The underlying Doctis core API functions (`dwg_api.php`, `license_api.php`, etc.)
are transport-agnostic.  REST and SOAP are both thin adapter layers calling the
same PHP functions.  There is no technical dependency between the two tracks; they
can be extended in parallel.

The REST layer is better suited for:
- Machine-readable integrations (JSON, structured pagination)
- Token-authenticated CI/CD pipelines
- License management

The SOAP layer is better suited for:
- File transfer (base64Binary with chunked support)
- Legacy integrations using WSDL-generated client stubs
- Existing MantisBT client libraries

See [`doc/REST-AND-SOAP-API.md`](REST-AND-SOAP-API.md) for integration guide,
curl examples, authentication setup, and web server configuration.
