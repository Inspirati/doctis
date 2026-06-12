# Plan: Primary Document File Upload

**Branch:** `dwg-primary-document`
**Status:** In progress

---

## Background

Doctis indexes documents stored externally. The recently implemented GIT storage
backend provides a managed external store with Doctis as the front-end. The
existing file attachment mechanism (`{dwg_file}`) supports files attached to
dwg notes — supplementary material analogous to email attachments.

This plan adds the concept of a **primary document file**: the canonical file
that the dwg record itself represents. This is distinct from note attachments
in every way:

| | Primary document | Note attachment |
|---|---|---|
| What it is | The document the record represents | Supplementary file on a note |
| Cardinality | One per dwg (or none) | Many per note |
| Replaces? | Yes — new upload is a new revision | No — chronological history |
| Versioning | Inherently important | Incidental |
| Storage | GIT backend (always) | DB / DISK (reverting — see note) |

**Future revert (out of scope for this branch):** Note attachments (`{dwg_file}`)
will be reverted to filesystem or database storage only. The GIT backend will
become exclusive to primary document files. Do not implement this during the
current phase; remain mindful of it when designing the API boundary.

---

## Decisions

- **Schema:** New dedicated table `{dwg_primary_file}` (Option D). One row per
  dwg, or absent if no primary document has been uploaded. Git-specific fields
  included from the outset to support future expansion without further migration.
- **UI:** Both — optional upload on the create form, plus a dedicated section on
  the view page that is visually distinct from the notes/activity section.
- **Storage:** GIT backend exclusively for primary documents.

---

## Steps

### Phase 1 — Schema  ✅
- [x] Add `{dwg_primary_file}` table to `admin/schema.php`
- [x] Run installer on vaio to apply schema (steps 247–248 confirmed GOOD)

### Phase 2 — API layer  ✅
- [x] `file_dwg_primary_exists( $p_dwg_id )` — bool
- [x] `file_dwg_primary_get( $p_dwg_id )` — returns row or null
- [x] `file_dwg_primary_add( $p_dwg_id, $p_user_id, $p_tmp_file, $p_filename, $p_filesize, $p_file_type, $p_description )` — stores via GIT backend, inserts row; replace semantics
- [x] `file_dwg_primary_delete( $p_dwg_id )` — soft-deletes via GIT backend, removes row
- [x] `file_dwg_primary_get_content( $p_dwg_id )` — retrieves file content for download

### Phase 3 — Create page  ✅
- [x] Always set `enctype="multipart/form-data"` on the create form (unconditional)
- [x] Add "Primary Document" upload section to `dwg_create_page.php`
- [ ] Handle upload in `dwg_create.php` after dwg record is created
  (call `file_dwg_primary_add()` if a file was submitted)

### Phase 4 — View page  ✅
- [x] Add "Primary Document" widget to `dwg_view_inc.php`:
  - If file present: filename, size, date, download link, "Upload new revision" form
  - If no file and user can edit: upload form, clearly distinct from notes section
  - If no file and read-only: plain "No document file uploaded" message
- [x] Add `dwg_primary_file_update.php` — POST handler for view-page upload/replace

### Phase 5 — Download
- [ ] Extend `file_download.php` to serve primary document files
  (new query parameter `type=dwg_primary&id=<dwg_id>`)

### Phase 6 — Create page handler
- [ ] Handle upload in `dwg_create.php` after dwg record is created
  (call `file_dwg_primary_add()` if a file was submitted)

### Phase 7 — Access control
- [ ] Review whether dedicated config thresholds are needed beyond `update_dwg_threshold`
  for upload and `view_dwg_threshold` for download; add to `config_defaults_inc.php` if so

---

## New / modified files

| File | Change |
|------|--------|
| `admin/schema.php` | Add `{dwg_primary_file}` table |
| `core/file_dwg_api.php` | Add `file_dwg_primary_*` functions |
| `dwg_create_page.php` | Add primary document upload section |
| `dwg_create.php` | Handle primary file upload on create |
| `dwg_view_inc.php` | Add primary document widget |
| `dwg_primary_file_update.php` | New: POST handler for view-page upload |
| `file_download.php` | Extend to serve primary document files |
| `config_defaults_inc.php` | New threshold config keys if required |
| `lang/strings_english.txt` | New UI strings |

---

## Issues / notes

_(record problems and decisions here as they arise)_
