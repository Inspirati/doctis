# Doctis — Primary Documents and System Operations

This document covers two implemented feature areas (primary document files and
system administration operations) and the planned features that extend them.
It replaces `PLAN-dwg-primary-document.md`, `FEATURES-TODO.md`, and
`UPDATER-PLAN.md`.

Status markers: ✅ implemented · ☐ planned · ⊘ explicitly deferred

---

## Description

### Primary Document File

Each `dwg` record can have a single **primary document file** — the canonical
registered artefact the record represents.  This is distinct from note
attachments in every way:

| | Primary document | Note attachment |
|---|---|---|
| What it is | The document the record represents | Supplementary file on a note |
| Cardinality | One per dwg (or none) | Many per note |
| Replaces? | Yes — new upload replaces the previous | No — chronological history |
| Versioning | Inherently important | Incidental |
| Storage | GIT backend | DB / DISK |

#### Schema

Stored in `{dwg_primary_file}` — one row per dwg, absent if no file has been
uploaded.  Git-specific fields (`git_sha`, `diskfile`, `folder`) are included
at the schema level.  The table has no `project_id` column; project is always
derived via `dwg_get_field($dwg_id, 'project_id')`.

#### API functions (`core/file_dwg_api.php`)

| Function | Purpose |
|---|---|
| `file_dwg_primary_exists( $p_dwg_id )` | Returns bool |
| `file_dwg_primary_get( $p_dwg_id )` | Returns row or null |
| `file_dwg_primary_add( ... )` | Stores via GIT backend; replace semantics |
| `file_dwg_primary_delete( $p_dwg_id )` | Soft-deletes via GIT backend, removes row |
| `file_dwg_primary_get_content( $p_dwg_id )` | Retrieves file content for download |
| `file_dwg_git_head_info( $p_dwg_id )` | Returns HEAD SHA and comparison to on-record SHA |
| `file_dwg_primary_sync_head( $p_dwg_id )` | Promotes current HEAD to the on-record SHA |

#### UI

- **Create page** (`dwg_create_page.php`): optional primary document upload
  section; form always has `enctype="multipart/form-data"`.
- **View page** (`dwg_view_inc.php`): Primary Document widget showing filename,
  size, date, download link, and an upload form if the user can edit.
  Read-only users see "No document file uploaded" when absent.
- **Upload handler** (`dwg_primary_file_update.php`): POST handler for
  view-page upload and replace.
- **Download** (`file_download.php`): `?type=dwg_primary&id=<N>` serves the
  on-record version; `?type=dwg_primary_head&id=<N>` serves the current HEAD.
- **Head-warn page** (`dwg_primary_head_warn.php`): rendered when HEAD has
  advanced past the on-record SHA; offers Sync to HEAD, Git Clone URL, and a
  "promote draft to on-record" action.

#### Access control

The Primary Document widget and download are gated at `REPORTER` level (25).
Sync-to-HEAD and related lifecycle actions require `MANAGER` (70) via
`$t_can_sync_to_head`.  A dedicated `$g_dwg_primary_document_threshold` config
key (defaulting to `REPORTER`) is the canonical control; the download endpoints
in `file_download.php` already use `access_ensure_dwg_level`.

#### Implementation notes

- **`DwgAddCommand.php` date fields** — `revision_date`, `release_date`,
  `due_date` were accessed unconditionally; fixed with `?? null`.
- **`dwg_primary_file_update.php` DwgData object** — `dwg_get()` returns a
  `DwgData` object; was incorrectly accessed as `$t_dwg['project_id']`; fixed
  to `$t_dwg->project_id`.
- **`file_dwg_primary_add()` metadata** — `filename` and `user_id` keys were
  missing from the `$t_metadata` array; added.
- **`file_dwg_primary_add()` list() destructuring** — `store()` returns a
  named-key array; `list($a,$b,$c)=` expects numeric keys; fixed to use named keys.
- **`GitFileStorageBackend::ensure_git_home()`** — under Apache, `putenv('HOME=...')`
  alone was insufficient; fixed by also setting `GIT_AUTHOR_NAME`,
  `GIT_AUTHOR_EMAIL`, `GIT_COMMITTER_NAME`, `GIT_COMMITTER_EMAIL` from the
  gitconfig, bypassing the HOME lookup entirely.
- **`dwg_view_inc.php` PHP tag** — stray `<?php` inside an already-open PHP
  block caused a parse error; removed the duplicate open tag.

---

### System Administration Operations

Six system-administration actions are available on `manage_overview_page.php`
in a System Operations widget visible only to administrators.  All pages
enforce `auth_reauthenticate()` and `access_ensure_global_level(ADMINISTRATOR)`.

| Feature | Pages | Notes |
|---|---|---|
| Config file download/upload | `manage_config_file_page.php`, `manage_config_file_download.php`, `manage_config_file_upload.php` | Upload writes via sudo wrapper to handle ownership |
| Git pull (self-update) | `manage_git_pull_page.php`, `manage_git_pull_action.php` | Requires `safe.directory` + sudoers — see §Setup below |
| Database rebuild | `manage_db_rebuild_page.php`, `manage_db_rebuild_action.php` | Requires typing `REBUILD` to confirm; most destructive |
| Load sample data | `manage_db_load_sample_page.php`, `manage_db_load_sample_action.php` | Requires typing `LOAD`; not idempotent — only run on empty DB |
| Database backup | `manage_db_backup_page.php`, `manage_db_backup_download.php` | Streams `mysqldump | gzip` directly to browser |
| Git store backup | `manage_git_backup_page.php`, `manage_git_backup_download.php` | Streams `tar czf` of bare repos; no sudo needed |

Visual risk hierarchy on the widget: grey (safe) → blue (reversible) →
amber (additive, not idempotent) → red (destructive).

#### Security model

The web process runs as `www-data`.  Relevant ownership:

| Resource | Owner | www-data access |
|---|---|---|
| `config/config_inc.php` | hcr:share | **read** only |
| `/var/www/html/doctis/.git/` | hcr:share | **read** only |
| `/var/git/doctis/` | www-data:www-data | **full** |
| `/var/www/doctis/worktrees/` | www-data:www-data | **full** |

`www-data` cannot write the working tree or config directory.  `hcr` owns the
working tree, is in both `www-data` and `share`, and holds `~/.my.cnf` (MariaDB
credentials).  Operations that need write access run as `hcr` via `sudo`.

The deploy account is recorded in `$g_updater_run_as_user` in `config_inc.php`.
All system operations pages build `sudo -u <that user>` commands via
`system_ops_sudo_prefix()` in `core/system_ops_api.php`.

#### Required server infrastructure (one-time manual setup on each host)

**1. Sudoers file** — run as the deploy account (not root) so `$USER` expands
correctly:

```bash
sudo tee /etc/sudoers.d/doctis-web << EOF
www-data ALL=($USER) NOPASSWD: /usr/bin/git -C /var/www/html/doctis pull
www-data ALL=($USER) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-write-config.sh
www-data ALL=($USER) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh
www-data ALL=($USER) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh
www-data ALL=($USER) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-backup-database.sh
EOF
sudo visudo -c -f /etc/sudoers.d/doctis-web
```

Note: `doctis-git-setup.sh` (Step 9) automates the `git pull` line.  The
remaining four lines must be added manually (they require the deploy user's
credentials and script paths that `doctis-git-setup.sh` does not know).

**2. System gitconfig `safe.directory`** — required for both `www-data` (the
page's direct git calls) and the deploy user (the sudo'd pull):

```bash
sudo git config --system --add safe.directory /var/www/html/doctis
```

This is also handled by `doctis-git-setup.sh` Step 9, but only for the
`git pull` line.  Without it, the branch/commit display on
`manage_git_pull_page.php` shows blank and all git operations report
"detected dubious ownership".

**3. Helper scripts must be executable:**

```bash
chmod +x /var/www/html/doctis/admin/tools/doctis-write-config.sh
chmod +x /var/www/html/doctis/admin/tools/doctis-backup-database.sh
```

#### Smoke-testing the sudo rules

```bash
# Config write (non-destructive — creates a backup automatically)
sudo -u www-data bash -c 'echo "<?php # test" | sudo -u hcr /var/www/html/doctis/admin/tools/doctis-write-config.sh'

# Git pull prerequisites — verify safe.directory and sudoers (NOT 'git status' — not in allowlist)
sudo -u www-data git -C /var/www/html/doctis rev-parse --abbrev-ref HEAD
sudo -l -U www-data | grep 'doctis pull'

# DB backup
sudo -u www-data bash -c 'sudo -u hcr /var/www/html/doctis/admin/tools/doctis-backup-database.sh | head -5'

# Git backup (no sudo required)
sudo -u www-data bash -c 'tar czf - -C /var/git/doctis . | wc -c'
```

---

### Completed Feature Notes

The following features from the original `FEATURES-TODO.md` are implemented.

**Primary Document panel access gating** — The Primary Document widget in
`dwg_view_inc.php` is wrapped in `access_has_dwg_level( REPORTER, $f_dwg_id )`.
Download endpoints in `file_download.php` use `access_ensure_dwg_level` with
`view_dwg_threshold`.  The gate uses `$g_dwg_primary_document_threshold`
(defaults to `REPORTER`) not `view_dwg_threshold`, keeping the two concerns
separate.  `label-success` (green) badges show "Approved"; `label-default`
(grey) shows "On Record".

**Reference hyperlink detection — git references** — `string_get_dwg_view_reference_link()`
in `core/string_api.php` detects git references (full 40-char SHA, abbreviated
7–12 char SHA resolved via `git rev-parse`, or tag name) and links them to
`file_download.php?type=dwg_primary&id=<dwg_id>` (the on-record version).
The bare-repo path derivation is shared via `file_dwg_project_bare_repo()`.

---

## Plan

### Auto-Tag Git SHA on Document Status Transition

**Trigger:** `DwgData::save()` in `core/dwg_api.php` at the status-change block
(around line 733).  Guarded by `$g_dwg_git_status_tag = ON` (default OFF).

**Tag name format** — configurable, default `doctis/<dwg_id>/<status_label>`:

```php
$g_dwg_git_status_tag_format = 'doctis/%d/%s';
// e.g. doctis/42/incorporated
```

**Which transitions trigger a tag** — configurable array:

```php
$g_dwg_git_auto_tag_statuses = array( 180, 190, 195 );
// accepted=180, incorporated=190, archived=195
// Empty array disables auto-tagging entirely.
```

**Implementation:**

Add `dwg_git_auto_tag_on_status( int $p_dwg_id, int $p_new_status ): void` to
`core/file_dwg_api.php`.  At the call site in `core/dwg_api.php`:

```php
if( config_get( 'dwg_git_status_tag' ) === ON ) {
    dwg_git_auto_tag_on_status( $c_bug_id, $this->status );
}
```

The function: checks `$g_dwg_git_auto_tag_statuses`, derives the tag name,
calls `file_dwg_git_tag()`.  Silently ignores `'exists'` return (a re-transition
to the same status must not error).  Skips silently if no primary file exists or
if `dwg_upload_method !== GIT`.

**Tag type:** annotated (carries user and timestamp) by default; config override
to revert to lightweight.  `GIT_COMMITTER_*` env vars are already set by
`ensure_git_home()` / `set_git_author()`.

**Affected code:**

| Location | Change |
|---|---|
| `core/dwg_api.php` ~line 733 | Add call inside status-change block |
| `core/file_dwg_api.php` | Add `dwg_git_auto_tag_on_status()` |
| `config_defaults_inc.php` | `$g_dwg_git_status_tag = OFF`, `$g_dwg_git_auto_tag_statuses`, `$g_dwg_git_status_tag_format` |

---

### Status-Aware Label in the Primary Document Panel

**Intent:** The "On Record" label on the recorded-SHA row should upgrade to
"**Approved**" when the document status is at or above `accepted` (180) and
below `archived` (195).

**Status boundary:**

| Document status | Label |
|---|---|
| < 180 (pending … rework … independent review) | On Record |
| 180 — accepted | **Approved** |
| 190 — incorporated | **Approved** |
| 195 — archived | On Record (superseded, not current-approved) |

**Config keys** (defaults):

```php
$g_dwg_primary_approved_threshold = 180;  // accepted
$g_dwg_primary_archived_status    = 195;  // archived — above this, revert to 'On Record'
```

**Affected code:**

| Location | Change |
|---|---|
| `dwg_view_inc.php` ~line 1070 | Replace `lang_get('primary_document_approved')` with a ternary reading dwg status |
| `lang/strings_english.txt` | Add `$s_primary_document_on_record = 'On Record';`; split from existing `$s_primary_document_approved` |
| `config_defaults_inc.php` | Add `$g_dwg_primary_approved_threshold` and `$g_dwg_primary_archived_status` |

The dwg status is already in `$t_result['issue']`; no extra DB query needed.
CSS: `label-success` (green) for "Approved"; `label-default` (grey) for "On Record".
Display only — no access control or download link change.

---

### Snapshot Document SHA at Issue Creation

**Intent:** When an issue is raised against a document, capture the SHA of the
primary file that is On Record at that moment.  This lets a reviewer retrieve the
exact version the issue was raised against, even after the primary document is
superseded.

**Data model:** Add one column to `{bug}`:

```sql
ALTER TABLE {bug}
    ADD COLUMN document_sha VARCHAR(40) NOT NULL DEFAULT '';
```

Set once at issue creation; never updated.  Empty when no primary file exists at
issue creation time or when the storage backend is not GIT.

**Capture point** (`bug_report.php`, after reading `$t_document_id`):

```php
if( $t_document_id > 0 ) {
    $t_primary = file_dwg_primary_get( $t_document_id );
    if( $t_primary && preg_match( '/^[0-9a-f]{40}$/i', $t_primary['git_sha'] ) ) {
        $t_issue['document_sha'] = $t_primary['git_sha'];
    }
}
```

Write `document_sha` to the INSERT in `bug_api.php` `bug_add()`.

**Display** (`bug_view_inc.php` `print_document_details()`):

- If `document_sha` is a 40-char SHA — render as an 8-char abbreviated download
  link (`type=dwg_primary_at_sha`) with full SHA in `title` tooltip; change
  column header to `lang_get('dwg_reference_at_creation')`.
- If `document_sha` is empty — fall back to current `$t_document['reference']`
  with existing link logic.

**Historical SHA download endpoint** (`file_download.php`):

```
type=dwg_primary_at_sha   params: dwg_id, sha
```

Implementation: `git --git-dir=<bare_repo> cat-file blob <sha>:<dwg_id>/<filename>`,
where the filename is recovered from the commit tree via
`git ls-tree --name-only <sha> <dwg_id>/`.  Access gated by
`dwg_primary_document_threshold`.

**Affected code:**

| Location | Change |
|---|---|
| `admin/schema.php` | Add `document_sha VARCHAR(40) NOT NULL DEFAULT ''` to `{bug}` |
| `bug_report.php` ~line 185 | Snapshot `dwg_primary_file.git_sha` when `document_id > 0` |
| `core/bug_api.php` `bug_add()` | Write `document_sha` to INSERT |
| `bug_view_inc.php` `print_document_details()` | Show historical SHA (abbreviated, as download link) or fall back |
| `file_download.php` | Add `dwg_primary_at_sha` case |
| `core/file_dwg_api.php` | Add `file_dwg_primary_get_content_at_sha( int $p_dwg_id, string $p_sha )` |
| `lang/strings_english.txt` | Add `$s_dwg_reference_at_creation` |
| `api/soap/mc_dwg_api.php` | Expose `document_sha` as a read-only field in issue data |

---

## TODO

### Primary Document

- ☐ Review whether additional config thresholds beyond `update_dwg_threshold`
  (upload) and `view_dwg_threshold` (download) are needed for the primary
  document; add to `config_defaults_inc.php` if so.  (Phase 7 of original plan.)

### Features to Implement

- ☐ Auto-tag git SHA on status transition — see Plan above
- ☐ Status-aware "Approved" / "On Record" label — see Plan above
- ☐ Snapshot document SHA at issue creation — see Plan above

### System Operations — Open Questions

- **Output streaming for long-running operations?** Database rebuild may take
  5–10 seconds.  `exec()` buffers all output until completion.  If the browser
  spinner feels too slow, replace with `proc_open` + chunked `fread`/`flush`
  (same pattern used for streaming downloads).

- **Audit log?** System operations leave no Doctis-level audit trail.  Consider
  `error_log( 'ADMIN: ' . current_user_get_field('username') . ' executed X at ' . date('c') )`
  in each action page, writing a breadcrumb to `/var/log/apache2/error.log`.

- **Config backup cleanup?** `doctis-write-config.sh` accumulates
  `.bak.YYYYMMDD_HHMMSS` files in `config/`.  Either add a `find … -delete`
  line keeping the most recent N, or accept manual cleanup (files are small).

- **Sudoers completeness on fresh install** — `doctis-git-setup.sh` Step 9
  only writes the `git pull` sudoers line.  The four remaining rules
  (config-write, db-rebuild, sample-data, db-backup) must be added manually.
  Consider automating in `doctis-git-setup.sh` or a dedicated setup script.
