# Doctis Git Storage — Architecture and Implementation

This document describes how Doctis uses git as its document storage backend:
the governing principles, repository layout, backend class structure, and the
operations the PHP layer performs. It covers the implemented system only;
outstanding and deferred work is in [GIT_TODO.md](GIT_TODO.md).

---

## Governing Principle

**Doctis is a lightweight document index and lifecycle tracking system that
uses git as its storage backend. It is not a git forge, not a GitHub
replacement, and not a document version navigation tool.**

All design decisions follow from this. The moment a proposed feature starts
managing branches, displaying diffs, showing commit graphs, or providing
contributor views — it belongs in git tooling, not in Doctis.

---

## Separation of Concerns

### Git's role

Git is the **collaborative storage and versioning infrastructure**. Multiple
developers can clone, branch, commit, push, and merge against the bare
repositories using standard git tooling, independently of Doctis. None of that
activity needs to be visible to, or driven by, Doctis.

### Doctis's role

Doctis is the **lifecycle and index layer**. It owns:

- Document metadata (title, reference, revision, classification, etc.)
- Workflow state (status, assignment, review, approval)
- Access control and license requirements
- The record of *which specific git version* is associated with each
  significant lifecycle event

All meaningful state transitions are initiated from the Doctis interface. The
direction of control is always **Doctis → git**, never git → Doctis. There are
no `post-receive` hooks or webhooks pushing state from git into Doctis.

---

## Scope: Primary Document Only

The GIT backend applies exclusively to the **primary registered document** of
each `dwg` record — the single version-controlled artefact. Document
attachments (`{dwg_file}` rows) and bug/issue attachments (`{bug_file}` rows)
are always stored via `DISK` or `DATABASE`; the `GIT` setting is not used for
them.

Rationale: the registered document is the only item a `git clone` user should
see and the only revision-controlled record. Attachments are Doctis-specific
"assisting metadata" — they have no business being versioned in the document
store that external users clone.

Config key separation:

| Key | Applies to | GIT behaviour |
|-----|-----------|---------------|
| `$g_dwg_upload_method` | Primary registered document | uses `GitFileStorageBackend` |
| `$g_file_upload_method` | Bug/issue attachments | `GIT` falls through to `DATABASE` |
| (attachment path in `file_dwg_api.php`) | Document attachments | `GIT` mapped to `DATABASE` via `file_dwg_get_attachment_storage_backend()` |

---

## Repository Layout

Each Doctis project maps to exactly one bare git repository:

```
/var/git/doctis/<slug>.git              bare repo (authoritative store)
/var/www/doctis/worktrees/<slug>        Doctis's own working tree (write staging)
```

The slug is derived from the project name:

```php
preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( trim( $t_name ) ) )
// "Example Project" → "example-project"
```

The bare repo is created lazily on the first document file upload
(`ensure_project_repo()` in `GitFileStorageBackend`). Every project's repo is
self-contained; there is no monolithic store.

### File path within the repo

Every document file is stored at:

```
<dwg_id>/<filename>        e.g.  4/spec-rev-b.md
```

The `<dwg_id>/` directory is the GIT backend's collision-avoidance mechanism:
it lets different documents in the same project each have a `report.pdf`
without conflict, and makes `git log <dwg_id>/` show that document's complete
history in isolation. The GIT backend preserves original filenames (unlike
DISK/DATABASE which mangle them), so the repo is human-readable on clone.

### Slug derivation: known limitations

The slug is computed from the *current* project name at runtime in several
places. This creates two latent hazards that are deferred to [GIT_TODO.md](GIT_TODO.md):

1. **Rename split-brain** — renaming a project after first upload causes
   `store()` and `delete()` to target a new repo (recomputed slug) while
   `retrieve()` reads from the stored `folder` path (old slug).
2. **Slug collisions** — distinct project names that produce the same slug
   (e.g. `"Phase 2"` and `"Phase-2"`) would share a bare repo.

---

## Backend Class Architecture

### Interface

`core/classes/FileStorageBackendInterface.class.php` defines the contract:

```php
store(tmp_file, size, unique_name, file_path, browser_upload, metadata[])
    → ['diskfile' => ..., 'folder' => ..., 'content' => ...]

retrieve(row, project_id)
    → ['type' => ..., 'content' => ...] | false

delete(diskfile, project_id, metadata[])
    → void
```

### Implementations

| Class | File | Method |
|-------|------|--------|
| `DiskFileStorageBackend` | `core/classes/DiskFileStorageBackend.class.php` | Filesystem |
| `DatabaseFileStorageBackend` | `core/classes/DatabaseFileStorageBackend.class.php` | MariaDB BLOB |
| `GitFileStorageBackend` | `core/classes/GitFileStorageBackend.class.php` | Per-project git repo |

### Factory functions (in `core/file_dwg_api.php`)

Two separate factory functions enforce the primary-document/attachment split:

| Factory | Used by | Returns GIT backend when? |
|---------|---------|--------------------------|
| `file_dwg_get_storage_backend()` | Primary document upload/download/delete | Always, when `$g_dwg_upload_method = GIT` |
| `file_dwg_get_attachment_storage_backend()` | Document attachments | Never — maps `GIT → DATABASE` |

---

## Database Record

Every primary document file upload creates a row in `{dwg_primary_file}`.
The GIT backend uses the standard columns as follows:

| Column | GIT value | Notes |
|--------|-----------|-------|
| `diskfile` | Full commit SHA (40 hex chars) | Key — uniquely identifies the stored version |
| `folder` | Absolute path to the bare repo | Redundant (derivable from project context) but retained |
| `content` | Empty string | Used by DATABASE backend only |
| `git_sha` | Approved/on-record SHA | Pinned at lifecycle transitions; see On-Record/Draft below |

The `dwg_primary_file` table has **no `project_id` column**. Project ID must
always be derived via `dwg_get_field($dwg_id, 'project_id')`.

The `folder` value is redundant because the bare repo path is always
mechanically derivable:

```
dwg_id  →  project_id  →  slug  →  /var/git/doctis/<slug>.git
```

It is retained as harmless defensive scaffolding from the initial
implementation; `retrieve()` reads it back from the stored row.

---

## Operations

### Store (file upload)

`GitFileStorageBackend::store()`:

1. Calls `ensure_git_home()` — sets `HOME` env var from `posix_getpwuid()` so
   git can find `/var/www/.gitconfig` under Apache (which does not set `HOME`
   for worker processes).
2. Calls `ensure_project_repo()` — initialises bare repo and working tree if
   they do not yet exist.
3. Writes the uploaded file to `<worktree>/<dwg_id>/<filename>`.
4. Sets `GIT_AUTHOR_NAME` and `GIT_AUTHOR_EMAIL` from the Doctis user record
   so commits are attributed to the Doctis user, not `www-data`.
5. `git add`, `git commit` (message: `dwg_id=<N> by <username>`).
6. `git push origin <branch>` — **mandatory**; the bare repo receives nothing
   until push.
7. Returns the commit SHA as `diskfile`.

### Retrieve (file download)

`GitFileStorageBackend::retrieve()`:

1. Reads `diskfile` (commit SHA) and `folder` (bare repo path) from the DB row.
2. Calls `ensure_git_home()`.
3. Runs `git show <sha>:<dwg_id>/<filename>` against the bare repo directly
   (not the working tree, which may be in a transitional state during a
   concurrent upload).
4. Returns the raw file bytes.

### Delete (soft delete)

`GitFileStorageBackend::delete()`:

1. `git rm <dwg_id>/<filename>` in the working tree.
2. `git commit -m "dwg_id=<N> FILE_DELETED by <username>"`.
3. `git push origin <branch>`.

The file disappears from `HEAD` but its full commit history — every prior
version — is permanently retained in the bare repo. History is never rewritten.

---

## On-Record vs Draft

Doctis distinguishes two versions of a document's primary file:

| Concept | Storage | Access |
|---------|---------|--------|
| **On-Record (approved)** | `{dwg_primary_file}.git_sha` — a pinned SHA in the DB | `retrieve()` via `git show <git_sha>:path` |
| **Draft** | Whatever is at the repo's branch `HEAD` | `file_dwg_git_head_info()` reads it independently of the DB |

`file_dwg_primary_sync_head()` (`core/file_dwg_api.php`) is the "approve
current draft" primitive: it reads `HEAD` from the bare repo and writes it as
the new `git_sha`. This is how a draft commit becomes the On-Record version.

`dwg_primary_head_warn.php` renders a warning when `HEAD` has advanced past
the on-record SHA, with options including "promote current HEAD to on-record".

When developers push commits directly to the bare repo (bypassing Doctis), they
simply advance the Draft; the On-Record version remains pinned to its `git_sha`
until explicitly promoted inside Doctis. This is what makes direct pushes
safe — the DB is not desynchronised by a push, because draft commits are not
meant to have DB rows.

---

## Smart HTTP Gateway (Remote Clone)

Advanced users can `git clone` a project's entire document repository from a
remote workstation, authenticated with a Doctis API token — no Linux account
required. Current status: **read-only** (clone/fetch); push is rejected (Phase
2, deferred).

### Architecture

```
git clone http://<user>:<API_TOKEN>@vaio/git/<slug>.git
               ↓
        Apache routes /git/* to git_http.php
               ↓
        core/git_http_api.php:
          - Extract HTTP Basic credentials from Authorization header
          - Validate API token via api_token_get_user()
          - Resolve slug → project_id (exact slug match + case-insensitive fallback)
          - Check project-level access (DEVELOPER+ to read)
          - Allowlist check (only smart-HTTP endpoints permitted)
          - Path-traversal guard
          - Reject git-receive-pack (push disabled)
               ↓
        proc_open() → /usr/lib/git-core/git-http-backend
          GIT_PROJECT_ROOT=/var/git/doctis
          PATH_INFO=/<slug>.git/<service>
               ↓
        CGI response headers parsed; body streamed back to git client
```

### Key files

| File | Purpose |
|------|---------|
| `git_http.php` | Entry point: bootstrap + `git_http_handle_request()` |
| `core/git_http_api.php` | Auth, authz, slug→project map, CGI proxy |
| `admin/tools/git-serve.conf` | Apache configuration (installed, enabled on vaio) |

### Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `$g_git_http_enabled` | `OFF` | Master switch; set `ON` in `config_inc.php` to activate |
| `$g_git_http_backend` | `/usr/lib/git-core/git-http-backend` | Path to CGI binary |
| `$g_git_http_read_threshold` | `DEVELOPER` | Minimum project access level for clone/fetch |
| `$g_git_http_write_threshold` | `MANAGER` | Reserved for push (Phase 2) |

### Authentication

Users authenticate with:
- HTTP Basic **username**: Doctis username
- HTTP Basic **password**: a personal API token from *My Account → API Tokens*

The gateway reuses the existing `api_token_get_user()` infrastructure from
`core/api_token_api.php` — nothing new to provision.

### Access model

Git serves whole repositories. Per-document `view_dwg_threshold` and license
gating **cannot** be enforced over git. Access is project-level only: the caller
needs at least `$g_git_http_read_threshold` on the project to clone.

---

## Developer Direct Access

Developers with shell access to the server can clone and push to bare repos
directly:

```bash
git clone hcr@vaio:/var/git/doctis/example.git
```

This is entirely independent of Doctis. Developers push freely as part of
normal iterative development — pushes advance the Draft only; Doctis remains
unaware until a lifecycle transition explicitly records a SHA. The bare repos
are at `/var/git/doctis/`, owned `www-data:www-data`, mode `2770`. Developers
in the `www-data` group can read and push directly.

---

## Metadata Mapping

| Doctis concept | Git concept |
|---------------|-------------|
| Project (`project_id` / `name`) | Repository (one per project, `<slug>.git`) |
| Document `dwg_id` | Subdirectory within the repo (`<dwg_id>/`) |
| Document primary filename | File within `<dwg_id>/` subdirectory |
| Upload event | Git commit (author = Doctis user) |
| Delete event | Soft-delete commit (`git rm` + commit + push) |
| `{dwg_primary_file}.diskfile` | Commit SHA from the last upload |
| `{dwg_primary_file}.git_sha` | Approved/on-record commit SHA |
| `{dwg_primary_file}.folder` | Absolute path to the bare repo |
| `{dwg_primary_file}.date_added` | Upload timestamp (DB) |
| Uploader `user_id` | `GIT_AUTHOR_NAME` / `GIT_AUTHOR_EMAIL` on the commit |

---

## What Doctis Does NOT Do

- Display commit history or diffs
- Manage branches
- Show contributor graphs
- Provide pull-request or code-review workflows
- Act as a general git repository browser

These belong in git tooling (a git client, a forge such as Gitea). Doctis's
only legitimate git-facing operations are:

- **Read** — retrieve file content at a stored SHA
- **Write** — store a new file version (upload → new commit)
- **Register** — record an existing SHA against a document record *(todo)*
- **Tag** — optionally mark an approved commit *(todo, nicety)*
- **Trigger** — invoke the pandoc pipeline at the incorporation step *(todo)*
- **Serve** — proxy `git-http-backend` for authenticated remote clone *(read-only; push deferred)*

---

## Key Source Files

| File | Purpose |
|------|---------|
| `core/classes/GitFileStorageBackend.class.php` | Core GIT backend: store, retrieve, delete, slug, repo init |
| `core/classes/FileStorageBackendInterface.class.php` | Backend interface definition |
| `core/file_dwg_api.php` | Two factory functions; `file_dwg_primary_*` and `file_dwg_*` call paths |
| `core/git_http_api.php` | Smart HTTP gateway: auth, authz, CGI proxy |
| `git_http.php` | Smart HTTP entry point |
| `core/constant_inc.php` | `define('GIT', 3)` |
| `config_defaults_inc.php` | `$g_git_storage_root`, `$g_git_worktree_root`, `$g_git_http_*` defaults |
| `admin/test-git-php.php` | 15-step integration test (store/retrieve/delete cycle) |
| `admin/tools/git-serve.conf` | Apache config for Smart HTTP routing |
