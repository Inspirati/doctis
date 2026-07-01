# Doctis — Developer Issues, Hotspots & Production Readiness

*Last revised 2026-07-02. Covers outstanding items only; resolved items have been removed.*

---

## Executive Summary

Doctis is a solid fork of MantisBT 2.27 with well-structured parallel APIs for
the document (`dwg`) domain. The core logic and REST API are functional. The
outstanding gaps are concentrated in: **data-model ambiguities** in the
`dwg`/`document` layer, **deployment hardening** (default credentials), and a
medium-priority backlog (filter layer debt, bulk import).

---

## Part 1 — Identified Issues

### 1. Data Model Ambiguities (HIGH PRIORITY)

#### 1a. `classification` field — remaining occurrence in `dwg_cache_row()`

`core/dwg_api.php` line ~794:

```php
SELECT * FROM {dwg} a INNER JOIN {documents} b ON a.document_id=b.id WHERE a.id=?
```

`SELECT *` on a JOIN causes `doc.id`, `doc.classification`, and `doc.link_url`
to overwrite the corresponding `dwg` columns in every ADOdb result row. This
is the single-document fetch path — called on every document view page load.

`DwgFilterQuery::build_main()` had the same bug and was fixed in the
`db-optimise` branch (commit `3335de2`). This is the remaining occurrence.

**Fix:** Replace `SELECT *` with an explicit column list, aliasing the
conflicting `doc` columns (`doc.classification AS doc_classification`, etc.),
mirroring the fix already applied in `DwgFilterQuery`.

**Test strategy:** Create a document where `dwg.classification = 'SECRET'` and
the linked `documents.classification = 'UNCLASSIFIED'`. Load the document view
page and assert the displayed classification reads `SECRET`.

#### 1b. `document_api.php` status field mix-up

`core/document_api.php:571`:
```
// @TODO RobD - beware, we have mixed up the use of the table status fields
// between re-use of the 'bug' table meaning and that of the 'category' table
```

**Fix:** Audit all uses of `status` in `document_api.php`. Map each to the
correct semantic — document lifecycle status vs. category active/inactive flag
— and add constants or a comment block documenting which is which.

**Test strategy:** For each `@TODO RobD` near `status`, assert before and after:
`document_get($id)->status` must match a lifecycle enum value, not a boolean.
Run `phpunit` after each individual fix.

#### 1c. `DWGNOTE = 0` in `core/constant_inc.php:643`

Zero is falsy in PHP. Any `if($t_type)` check or switch fall-through that
treats zero as "no type set" will silently misclassify a document note.

**Fix:** Change `define('DWGNOTE', 0)` to `define('DWGNOTE', 4)` and update
every `switch` / `case` / comparison that references `DWGNOTE` or the literal
`0` in a type-discriminator context.

**Test strategy:** `grep -rn 'DWGNOTE\|case 0' core/` to enumerate all call
sites before the change. After: add a note to a document and assert it is
stored with `type = 4` and retrieved without being dropped by a falsy check.
A constant-value assertion (`$this->assertNotEquals(0, DWGNOTE)`) acts as a
permanent regression guard.

---

### 2. Filter Layer Technical Debt (MEDIUM PRIORITY)

`core/filter_api.php` lines 101, 1156, 1192 contain three "temporary
quick'n'dirty hack" comment blocks that route document filtering through the
bug filter pipeline without refactoring the call signatures. These are not
temporary.

**Fix:** Extract a shared `$p_entity_type` parameter or a strategy object into
`filter_get_bug_rows()`. The groundwork already exists in `DwgFilterQuery.class.php`
— complete the abstraction there and remove the hacks.

**Risk:** High refactor risk. Tackle after REST integration tests provide a
regression safety net.

**Hotspot files:**
- [core/filter_api.php](../core/filter_api.php) — lines 101, 1156, 1192
- [core/filter_dwg_api.php](../core/filter_dwg_api.php) — `@TODO RobD` on reuse line 96
- [core/classes/DwgFilterQuery.class.php](../core/classes/DwgFilterQuery.class.php) — commented-out `$table` property (line 112)

---

### 3. Deployment Credentials

#### 3a. Default admin credentials

The README documents default credentials as `administrator` / `root`. These
must be prominently marked as **must-change** items before any external
exposure, ideally enforced with a first-login password prompt.

---

### 4. Bulk Document Import — No UI (MEDIUM PRIORITY)

There is no built-in user interface for bulk-adding documents to the database.

**Plan:** A minimal CSV import page (`manage_import_data_page.php` already
exists for issues) should be extended or duplicated for documents. Fields to
map: document reference number, title, URL, project, status, category.

**Test strategy:** Prepare a CSV with 5 rows including edge cases (missing
optional fields, duplicate reference numbers). Import; assert 5 `dwg` rows
created with correct metadata; duplicate reference rejected with a clean error.

---

## Part 2 — Hotspots for New Developers

These are the files you will spend the most time in. Read them before touching
anything else.

### Hotspot 1: `core/dwg_api.php`

The heart of document tracking. Contains the `BugData`-style class for
documents, all CRUD functions, and the DB mapping layer. Pay attention to:
- Lines 131–156: the split between new document fields and legacy bug fields
  (both marked `@TODO RobD`)
- Line 379–395: validation is partially commented out or incomplete
- Line 805: `classification` field ambiguity (see Issue 1a above)

### Hotspot 2: `core/filter_api.php` + `core/filter_dwg_api.php`

The filter system is where bugs and dwgs diverge most sharply. The three hacks
at lines 101, 1156, 1192 of `filter_api.php` are the main reason the dwg
filter works at all. Do not remove them without a full refactor of
`DwgFilterQuery`. Any change to filter column logic in `columns_api.php`
likely needs a parallel change in `columns_dwg_api.php`.

### Hotspot 3: `config_defaults_inc.php`

Both MantisBT and Doctis defaults live here. The file is 6400 lines. Doctis
additions are scattered throughout; search for `$g_dwg_` to find them
(137 occurrences). Any new document configuration option must be added here
first, then overridden in `config/config_inc.php` for specific deployments.

### Hotspot 4: `dwg_view_inc.php`

The most frequently changed file in recent commits. This renders the document
view page and is the primary user-facing surface for the document feature. It
drives `DwgViewPageCommand.php`.

### Hotspot 5: `core/document_api.php`

Contains document-as-category reference data logic. This is separate from
`dwg_api.php` — it handles the *reference data* for documents (their
identifying metadata, categories) rather than the lifecycle tracking.

### Hotspot 6: `core/license_api.php`

The License entity is entirely Doctis-specific with no MantisBT equivalent. A
good file to read when getting familiar with Doctis's own patterns outside the
MantisBT fork.

---

## Part 3 — Pre-Production Checklist

| Item | Status | Priority |
|------|--------|----------|
| `SELECT *` column shadowing in `dwg_cache_row()` | Not done | High |
| `DWGNOTE = 0` falsy type discriminator | Not done | High |
| Change default admin password / enforce on first login | Not done | High |
| Resolve `status` field semantic confusion in `document_api.php` | Not done | High |
| Filter layer hacks in `filter_api.php` | Not done | Medium |
| Add bulk import UI for documents | Not done | Medium |

---

## Part 4 — Recommended First Tasks for New Developers

1. **Read** `CLAUDE.md` and `README.md` in full.
2. **Create a document** in the UI; follow the code path from `dwg_create_page.php`
   → `dwg_create.php` → `DwgAddCommand.php` → `dwg_api.php::create()`.
3. **Read** `core/filter_api.php` lines 95–200 and `core/filter_dwg_api.php`
   to understand the filter split.
4. **Pick one** of the P0 or P1 items from Part 5 and resolve it.
5. **Write** a failing test in `tests/` to document a discovered gap before fixing it.

---

## Part 5 — Implementation Priority Plan

Issues are ordered by the risk they pose to data correctness and production
safety. Each item includes a test strategy so coverage can be verified before
and after the fix.

### Priority 0 — Immediate Data Correctness

#### P0-A: `SELECT *` column shadowing in `dwg_cache_row()`

See §1a above. `core/dwg_api.php` line ~794 — the single-document fetch used
on every view page load.

**Test strategy:** described in §1a.

#### P0-B: `DWGNOTE = 0` in `core/constant_inc.php:643`

See §1c above.

**Test strategy:** described in §1c.

---

### Priority 1 — High-Impact Correctness

#### P1-A: Status field semantic confusion in `document_api.php`

See §1b above.

**Test strategy:** described in §1b.

---

### Priority 2 — Medium Cleanup

#### P2-A: Filter layer hacks (`core/filter_api.php` lines 101, 1156, 1192)

Load-bearing but fragile. Refactor risk is high — tackle after the REST suite
provides a regression safety net. Write integration tests covering every filter
property before touching the code; those tests become the refactor guard.

#### P2-B: Bulk document import (no UI)

See §4 above. Test strategy: described in §4.
