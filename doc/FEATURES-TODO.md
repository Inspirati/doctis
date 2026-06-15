# Doctis — Planned Features

Items in this file are design-agreed features that have not yet been
implemented.  Each entry captures the intent, the affected code locations,
and enough detail to implement without revisiting the design discussion.

---

## 1 — Gate the Primary Document panel on access level ✔ done

### Intent

The **Primary Document** panel on `dwg_view.php` is currently rendered for
all authenticated users, including those with the `VIEWER` access level.
Viewers can see the Approved file link and the Draft link, but cannot act on
them in any meaningful, auditable way.  The panel (and its download links)
should be hidden from viewers; it should only be rendered for users at
`REPORTER` level and above.

### Affected code

| Location | What to change |
|----------|----------------|
| [dwg_view_inc.php](../dwg_view_inc.php) line ~1037 | Wrap the entire `<div id="primary_document" …>` widget block in an `if( access_has_dwg_level( REPORTER, $f_dwg_id ) )` guard |
| [file_download.php](../file_download.php) `dwg_primary` and `dwg_primary_head` blocks | The `access_ensure_dwg_level` calls there already use `view_dwg_threshold`; confirm that threshold is set to `REPORTER` or above in `config_defaults_inc.php` / `config_inc.php` |
| [dwg_primary_head_warn.php](../dwg_primary_head_warn.php) | Same — already uses `view_dwg_threshold`; no change needed if the threshold is correct |

### Config consideration

A dedicated config key `$g_dwg_primary_document_threshold` (defaulting to
`REPORTER`) would be cleaner than hard-coding the constant, making the gate
adjustable per-installation without a code change.

### Notes

- The `VIEWER` constant is 10; `REPORTER` is 25.  The gate should use the
  new config key or `REPORTER` directly, not `view_dwg_threshold` (which
  controls whether the document record itself is visible, a separate concern).
- Sync to HEAD, Tag, and Touch are already gated at `MANAGER` (70) via
  `$t_can_sync_to_head`; the panel gate is a coarser, lower-level check.

---

## 2 — Extend reference hyperlink detection to git repository references ✔ done

### Intent

`string_get_dwg_view_reference_link()` in [core/string_api.php](../core/string_api.php)
(line 836) generates a hyperlink from the `dwg_reference` field.  It
currently detects two patterns:

| Pattern | Regex | Target |
|---------|-------|--------|
| Objective-ID | `[A-Z]{1,3}[0-9]{6,9}` | `$g_reference_url1` + `/document/versions/latest` |
| Siemens / other | (fallback) | `$g_reference_url2` + reference string |

We want to add a third detector: if the reference looks like a reference
into the Doctis git back-end (a git SHA, abbreviated SHA, or tag name
that exists in the project's bare repository), hyperlink it to the
**currently approved git SHA** stored in `{dwg_primary_file}.git_sha` for
that document.

### What "looks like a git reference" means

The check should be performed in order, stopping at the first match:

1. **Full SHA** — exactly 40 hex characters: `/^[0-9a-f]{40}$/i`
2. **Abbreviated SHA** — 7–12 hex characters that resolve unambiguously in
   the project bare repo: `/^[0-9a-f]{7,12}$/i`, then verify with
   `git --git-dir=<bare> rev-parse --verify <ref>^{commit} 2>/dev/null`
3. **Tag name** — non-empty string that exists as a tag in the bare repo:
   `git --git-dir=<bare> rev-parse --verify refs/tags/<ref> 2>/dev/null`

### Link target

When a git reference is detected the link should point to:

```
file_download.php?type=dwg_primary&id=<dwg_id>
```

…i.e. the **Approved** version recorded in Doctis, not the raw git object.
The tooltip (`title=""`) should show the full 40-character SHA so the user
can distinguish it from a generic download link.

An alternative (or additional) option is to link to
`dwg_primary_head_warn.php?id=<dwg_id>` when the reference resolves to the
git HEAD specifically, since that is the draft/current-state download path.

### Affected code

| Location | What to change |
|----------|----------------|
| [core/string_api.php:836](../core/string_api.php) | Add git-reference detection branch before the Objective-ID / Siemens fallback chain |
| [config_defaults_inc.php](../config_defaults_inc.php) | Optionally add `$g_reference_git_detect = ON` to make the git detection opt-in |

### Notes

- `string_get_dwg_view_reference_link()` receives `$p_bug_id` (the dwg_id)
  and `$p_dwg_reference` (the reference string) — both values needed for the
  git lookup are already available at the call site.
- The bare repo path must be derived from `project_id → slug → git_storage_root`
  as in `file_dwg_git_head_info()`.  Extract this into a shared helper to
  avoid repeating the derivation in a fourth place.
- Git lookups add latency.  Cache the result per-request (static variable)
  if the reference field is rendered more than once per page (e.g. in list
  views via `columns_dwg_api.php`).

---

## 3 — Auto-tag git SHA on document status transition

### Intent

When a tracked document's **Status** changes in Doctis, automatically
apply a git lightweight tag to the SHA recorded in `{dwg_primary_file}.git_sha`
at the time of the transition.  This makes the document lifecycle permanently
visible in the git history without any manual tagging step.

Example: when document 42 transitions to `incorporated` (status 190), the
tag `doctis/42/incorporated` (or a configurable format) is applied to its
current Approved SHA in the project bare repository.

### Trigger point

[core/dwg_api.php](../core/dwg_api.php) `DwgData::save()` — the block at
line ~733 already detects a status change and fires `email_dwg_status_changed()`.
The git tag call should be added immediately after, using the same
`$t_old_data->status != $this->status` guard:

```php
# status changed
if( $t_old_data->status != $this->status ) {
    $t_status_label = MantisEnum::getLabel( config_get( 'dwg_status_enum_string' ), $this->status );
    $t_status_label = str_replace( ' ', '_', $t_status_label );
    email_dwg_status_changed( $c_bug_id, $t_status_label );

    # ── NEW: auto-tag git SHA ───────────────────────────────────────
    if( config_get( 'dwg_git_status_tag' ) === ON ) {
        dwg_git_auto_tag_on_status( $c_bug_id, $this->status );
    }
}
```

### Tag name format

The tag name should be configurable.  Proposed default:

```
doctis/<dwg_id>/<status_label>
```

Examples: `doctis/42/incorporated`, `doctis/7/accepted`

The `/` hierarchy is valid in git tags and groups all Doctis-generated tags
under the `doctis/` namespace, making them easy to list or exclude.

A `$g_dwg_git_status_tag_format` config key (printf-style or using named
placeholders) would allow site-specific formats such as:
- `release/%s-%d` → `release/incorporated-42`
- `approved/%04d` → `approved/0042`

### Which status transitions trigger a tag

Not all status transitions are meaningful enough to tag.  The proposed
approach is a config array:

```php
$g_dwg_git_auto_tag_statuses = array( 180, 190, 195 );
# accepted=180, incorporated=190, archived=195
```

An empty array disables auto-tagging entirely.  Configurable per-project
via the standard MantisBT per-project config override mechanism.

### Affected code

| Location | What to change |
|----------|----------------|
| [core/dwg_api.php](../core/dwg_api.php) ~line 733 | Add `dwg_git_auto_tag_on_status()` call inside status-change block |
| [core/file_dwg_api.php](../core/file_dwg_api.php) | Add `dwg_git_auto_tag_on_status( int $p_dwg_id, int $p_new_status ): void` — checks config array, derives tag name, calls `file_dwg_git_tag()` |
| [config_defaults_inc.php](../config_defaults_inc.php) | `$g_dwg_git_status_tag = OFF` (disabled by default), `$g_dwg_git_auto_tag_statuses = array( 180, 190, 195 )`, `$g_dwg_git_status_tag_format = 'doctis/%d/%s'` |

### Notes

- `file_dwg_git_tag()` already exists (implemented in `git-design-1` branch)
  and handles duplicate-tag detection gracefully (`'exists'` return value).
  The auto-tag function should silently ignore `'exists'` — a document that
  is re-transitioned to the same status (e.g. sent back to `accepted` after
  being reworked) should not error.
- The git tag is applied to the SHA recorded in `{dwg_primary_file}` at the
  moment of the status change.  If no primary file exists yet, the function
  should silently skip.
- If the git backend is not active (`dwg_upload_method !== GIT`), skip without
  error.
- Consider whether the tag should be **annotated** (with a message recording
  the Doctis user and timestamp) rather than lightweight.  Annotated tags
  carry their own object and are more informative in `git log --tags`, but
  require the `GIT_COMMITTER_*` environment variables to be set (already
  handled by `ensure_git_home()` / `set_git_author()` in the backend class).
  Annotated is recommended; make it the default with a config override to
  revert to lightweight.

---

## 4 — Status-aware label in the Primary Document panel

### Intent

The "On Record" label on the recorded-SHA row of the Primary Document panel
(`dwg_view.php`) should upgrade to **"Approved"** when the document's workflow
status has reached a formally significant level — specifically when the status
is at or above `accepted` (180) but below `archived` (195).  For all other
statuses the label remains "On Record".

This makes the panel self-documenting: a user glancing at the Primary Document
section can immediately see whether the file they are looking at has been
formally accepted through the review cycle, without cross-referencing the
status field in the View Document Details panel above.

### Status boundary

Using the default `$g_dwg_status_enum_string` (`110:pending … 195:archived`):

| Document status | Label shown |
|-----------------|-------------|
| < 180 (pending … rework … independent review) | On Record |
| 180 — accepted | **Approved** |
| 190 — incorporated | **Approved** |
| 195 — archived | On Record (archived implies superseded, not current-approved) |

The thresholds should be driven by config rather than hard-coded constants,
to allow installations with a different status enum to tune the boundary:

```php
# config_defaults_inc.php
$g_dwg_primary_approved_threshold = 180;   # accepted — first "approved" status
$g_dwg_primary_archived_status    = 195;   # archived — above this, revert to 'On Record'
```

### Affected code

| Location | What to change |
|----------|----------------|
| [dwg_view_inc.php](../dwg_view_inc.php) ~line 1070 | Where `lang_get('primary_document_approved')` is echoed inside the `<span class="label label-success">` — replace with a ternary that reads the dwg status and selects between `primary_document_approved` ('Approved') and `primary_document_on_record` ('On Record') |
| [lang/strings_english.txt](../lang/strings_english.txt) | Add `$s_primary_document_on_record = 'On Record';` alongside the existing `$s_primary_document_approved = 'Approved';` (currently 'On Record' is the value of `approved` — split them into two distinct keys) |
| [config_defaults_inc.php](../config_defaults_inc.php) | Add `$g_dwg_primary_approved_threshold` and `$g_dwg_primary_archived_status` with the defaults above |

### Notes

- The dwg status is already available in the `$t_result['issue']` array loaded
  earlier in `dwg_view_inc.php`; no extra DB query is needed.
- The `label-success` CSS class (green badge) should be used for "Approved" and
  `label-default` (grey) for "On Record", to give a visual distinction
  consistent with the rest of the status display.
- This feature intentionally does not change the download link or any access
  control — it is a display-only label change.

---

## 5 — Snapshot the document SHA at issue creation time

### Intent

When an issue (bug) is raised against a Doctis document, the `documents.reference`
field at that moment holds the git SHA of the primary document file currently
"On Record".  That SHA must be captured and stored with the issue so that a
reviewer can always retrieve the exact version of the document the issue was
raised against — even after the primary document has been superseded by a later
upload.

Currently, the "View Issue Details" panel in `bug_view.php` shows
`$t_document['reference']`, which is read live from `documents.reference` and
therefore reflects the *current* primary file, not the historical one.  The two
diverge the moment a new primary document version is uploaded after the issue
was created.

### Data model

Add one column to the `bug` table:

```sql
ALTER TABLE {bug}
    ADD COLUMN document_sha VARCHAR(40) NOT NULL DEFAULT '';
```

`document_sha` stores the full 40-character git SHA of `dwg_primary_file.git_sha`
at the moment the issue is created.  It is set once and never updated
subsequently — it is an immutable snapshot.  Empty string when:
- the document has no primary file at issue creation time, or
- the storage backend is not GIT (DISK / DATABASE have no meaningful SHA).

### Capture point

`bug_report.php` already reads `$t_document_id` at line 184 and passes it into
`$t_issue['document']`.  The snapshot should be taken in the command layer
(or directly in `bug_report.php` before calling `BugReportCommand`) once
`document_id > 0`:

```php
if( $t_document_id > 0 ) {
    $t_primary = file_dwg_primary_get( $t_document_id );
    if( $t_primary && preg_match( '/^[0-9a-f]{40}$/i', $t_primary['git_sha'] ) ) {
        $t_issue['document_sha'] = $t_primary['git_sha'];
    }
}
```

The value must then be written to `{bug}.document_sha` when the bug row is
inserted (in `bug_api.php` `bug_add()` or the equivalent command).

### Display

In `bug_view_inc.php` `print_document_details()` (around line 416), replace the
current `$t_document['reference']` display with logic that checks
`$t_bug['document_sha']`:

- If `document_sha` is a 40-char SHA — render it as an 8-character abbreviated
  download link pointing to the historical SHA endpoint (see below), with the
  full SHA in the `title` tooltip.
- If `document_sha` is empty — fall back to displaying `$t_document['reference']`
  as today (the current On Record reference, with the existing link logic).

The column header label could change from `lang_get('dwg_reference')` to
`lang_get('dwg_reference_at_creation')` when a SHA is present, to make clear
it is a snapshot rather than the current reference.

### Historical SHA download endpoint

A new case in `file_download.php` is required to serve the document at an
arbitrary historical git SHA (the current `dwg_primary` case only serves the
current On Record version):

```
type=dwg_primary_at_sha   parameters: dwg_id, sha
```

Implementation mirrors `file_dwg_primary_get_head_content()` but targets the
supplied SHA rather than git HEAD:

```bash
git --git-dir=<bare_repo> cat-file blob <sha>:<dwg_id>/<filename>
```

The filename at the historical commit must be found from the commit tree, e.g.
`git ls-tree --name-only <sha> <dwg_id>/`.  Access is gated by
`dwg_primary_document_threshold` (same as the existing primary download).

### Affected code

| Location | What to change |
|----------|----------------|
| Schema migration | `ALTER TABLE {bug} ADD COLUMN document_sha VARCHAR(40) NOT NULL DEFAULT ''` |
| [bug_report.php](../bug_report.php) ~line 185 | Snapshot `dwg_primary_file.git_sha` into `$t_issue['document_sha']` when `document_id > 0` |
| [core/bug_api.php](../core/bug_api.php) `bug_add()` | Write `document_sha` to the INSERT |
| [bug_view_inc.php](../bug_view_inc.php) `print_document_details()` | Show historical SHA (abbreviated, as download link) when `document_sha` is set; fall back to current reference otherwise |
| [file_download.php](../file_download.php) | Add `dwg_primary_at_sha` case |
| [core/file_dwg_api.php](../core/file_dwg_api.php) | Add `file_dwg_primary_get_content_at_sha( int $p_dwg_id, string $p_sha ): array|false` |
| [lang/strings_english.txt](../lang/strings_english.txt) | Add `$s_dwg_reference_at_creation` label |

### Notes

- The `document_sha` column is append-only: once set it is never modified.
  Any update to the primary document after the issue is raised must not touch it.
- The SOAP `mc_dwg_api.php` `mci_issue_data_to_array()` function should expose
  `document_sha` as a read-only field in the issue data structure.
- If `document_sha` equals the current `documents.reference` (i.e. the document
  has not been updated since the issue was raised), the display may suppress the
  redundancy and show only one link — but this is a UI refinement, not a
  correctness requirement.
- For non-GIT installations `document_sha` will always be empty and the column
  is a harmless no-op.
