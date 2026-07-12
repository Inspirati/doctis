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

Each Doctis project maps to exactly one bare git repository, named
`<slug>-<project_id>`:

```
/var/git/doctis/<slug>-<id>.git              bare repo (authoritative store)
/var/www/doctis/worktrees/<slug>-<id>        Doctis's own working tree (write staging)
```

Examples: `example-1.git`, `bridge-refit-2027-7.git`.  The immutable project
id suffix guarantees uniqueness; the slug prefix is cosmetic and follows the
project name (refreshed automatically on project rename — see below).  Naming
is owned by the helpers in `core/file_dwg_api.php` — never derive a repo path
from the project name anywhere else:

| Helper | Purpose |
|--------|---------|
| `dwg_project_repo_basename()` | Canonical basename; returns the existing on-disk name when present, else computes `<slug>-<id>` from the current name |
| `dwg_project_repo_basename_existing()` | On-disk lookup by the trailing `-<id>` |
| `dwg_project_repo_slug()` | Cosmetic slug from the current project name |
| `dwg_project_bare_repo_path()` | Absolute bare-repo path |
| `dwg_project_worktree_path()` | Absolute worktree path |
| `dwg_project_repo_rename()` | Relocates the repo when the project is renamed (called from `project_update()`) |
| `dwg_git_storage_lock()` / `_unlock()` | Advisory lock serialising relocation against repo creation |

The bare repo is created lazily on the first document file upload
(`ensure_project_repo()` in `GitFileStorageBackend`), which also installs the
pre-receive hook and sets `http.receivepack` (see Push below). Every project's
repo is self-contained; there is no monolithic store.

### Why the `-<id>` suffix — design rationale

The repository basename has two parts with fundamentally different roles:

| Part | Source | Role | Mutable? |
|------|--------|------|----------|
| slug (`example`) | Project *name*, slugified | Cosmetic — for humans browsing `/var/git/doctis/` | Follows the name: refreshed automatically when the project is renamed |
| id suffix (`-1`) | `{project}.id` primary key | Identity — the part the system actually resolves by | Never changes for the life of the project |

An earlier implementation named repos from the project name alone
(`example.git`).  That scheme had three latent defects, all stemming from the
fact that a project name is **mutable and lossy** while the repo directory on
disk is neither:

1. **Rename split-brain.**  The slug was recomputed from the *current* name on
   every operation.  Renaming "Example" to "Shipyard" meant the next upload
   created a brand-new `shipyard.git` while existing files were still read
   from `example.git` — the project silently fragmented across two repos with
   no record linking them.
2. **Slug collisions.**  The DB enforces uniqueness on the *exact* name, but
   slugification is lossy: `Phase 2`, `Phase-2`, and `phase  2` are three
   distinct valid project names that all slugify to `phase-2`.  Two projects
   would have shared one bare repo, mixing unrelated documents and crossing
   access boundaries.
3. **Degenerate slugs.**  A name of only punctuation or non-ASCII characters
   (e.g. `中文项目`, `***`) slugifies to nothing, producing a repo literally
   named `.git`.

The id suffix fixes all three at once, because a project id is **immutable and
unique by construction** — it can never collide and never changes.
(Alternatives considered: pure-id names like `1.git` are collision-free but
unreadable for operators; a hardened slug-only scheme with a stored slug
column, collision checks, and atomic rename handling is the most code and the
most fragility.  `<slug>-<id>` keeps human readability at no correctness cost.)

**Resolution is by id, never by slug.**  `dwg_project_repo_basename()` first
scans `$g_git_storage_root` for an existing directory whose *full trailing
digit run* is `-<id>.git`; only when none exists (first-ever upload for the
project) does it compute a fresh slug from the current name.  The Smart HTTP
gateway does the same: it parses the trailing `-<id>` from the requested URL
and ignores the slug prefix entirely, always serving the canonical on-disk
repo.

Consequences of these rules:

- **Renaming a project is always safe.**  Correctness never depends on the
  slug: even if the on-disk name were stale, every lookup resolves by id.
- **Clone URLs survive renames, in both directions.**  After a rename
  "Example" → "Shipyard", both `…/git/example-1.git` (bookmarked before the
  rename) and `…/git/shipyard-1.git` reach the same repository.
- **A name that slugifies to nothing** falls back to the slug `project`
  (e.g. `project-9.git`) rather than producing a degenerate path.

### Repository relocation on project rename

Because a stale slug would make `/var/git/doctis/` progressively unreadable
for operators, `project_update()` calls `dwg_project_repo_rename()` whenever
the project name changes, keeping the on-disk name aligned with the owning
project.  This is a cosmetic maintenance action layered on top of the
id-based lookup — it is *possible* precisely because nothing depends on the
slug.

Sequence, under the advisory storage lock (`dwg_git_storage_lock()`, an
`flock` on `<git_storage_root>/.doctis-lock`, also taken by
`ensure_project_repo()`):

1. **`rename(2)` the bare repository** — the atomic pivot.  Same parent
   directory, same filesystem, one syscall: resolvers see the old name or the
   new one, never neither, never both.  If the target path unexpectedly
   exists, the rename is skipped and logged; the repo stays fully functional
   under its old name.
2. **Delete the server worktree** — it is a disposable write-staging cache
   whose `origin` remote embeds the old bare path.  `ensure_project_repo()`
   lazily re-clones it with the correct origin on the next upload.
3. **Rewrite stored `{dwg_primary_file}.folder` values** — informational
   only; `retrieve()` derives the bare path from the project id and uses the
   stored value only as a legacy fallback.

Concurrency: in-flight git transfers survive the rename (open fds and cwd
follow the inode on Linux); an operation that resolved the old path *before*
the pivot fails cleanly with a `ServiceException` and succeeds on retry — no
DB row is written for a failed store, so nothing recorded is ever lost.
Permissions: renaming requires write on the parent directories, and both
`$g_git_storage_root` and `$g_git_worktree_root` are owned by `www-data`
(mode `2770`), the account Apache runs as.

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
dwg_id  →  project_id  →  dwg_project_bare_repo_path()  →  /var/git/doctis/<slug>-<id>.git
```

It is retained as informational scaffolding only: `retrieve()` derives the
bare path from the project id and falls back to the stored value just for
legacy rows whose derived path is absent.  `dwg_project_repo_rename()`
rewrites it on relocation to keep it accurate.

---

## Operations

### Store (file upload)

`GitFileStorageBackend::store()`:

1. Calls `ensure_git_home()` — sets `HOME` env var from `posix_getpwuid()` so
   git can find `/var/www/.gitconfig` under Apache (which does not set `HOME`
   for worker processes).
2. Calls `ensure_project_repo()` — initialises bare repo and working tree if
   they do not yet exist; installs/refreshes the pre-receive hook and sets
   `http.receivepack=true` on the bare repo.
3. Calls `dwg_git_worktree_sync()` — `fetch` + `reset --hard origin/<branch>`
   so the commit builds on the latest pushed state (remote pushes advance the
   bare repo independently of the server worktree).  Also discards any orphan
   commit left by a previously failed push.
4. Writes the uploaded file to `<worktree>/<dwg_id>/<filename>`.
5. Sets `GIT_AUTHOR_NAME` and `GIT_AUTHOR_EMAIL` from the Doctis user record
   so commits are attributed to the Doctis user, not `www-data`.
6. `git add`, `git commit` (message: `dwg_id=<N> by <username>`).
7. `git push origin <branch>` — **mandatory**; the bare repo receives nothing
   until push.
8. Returns the commit SHA as `diskfile`.

The caller (`file_dwg_primary_add()`) then pins the recorded SHA as a
permanent ref via `file_dwg_git_pin_approved()` — see On-Record vs Draft.

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

1. `dwg_git_worktree_sync()` — same pre-commit sync as store.
2. `git rm <dwg_id>/<filename>` in the working tree.
3. `git commit -m "dwg_id=<N> FILE_DELETED by <username>"`.
4. `git push origin <branch>`.

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

### Approved-SHA pinning

Whenever a `git_sha` is recorded (upload via `file_dwg_primary_add()`, or
promotion via `file_dwg_primary_sync_head()`), `file_dwg_git_pin_approved()`
writes a permanent, server-managed git ref in the bare repo:

```
refs/doctis/approved/<dwg_id>/<sequence>  →  <sha>
```

This keeps every approved commit reachable regardless of later branch history
(immune to GC), and forms an out-of-band approval record that does not depend
on the Doctis DB.  The pre-receive hook rejects any client push touching
`refs/doctis/*`; only the server writes them (`git update-ref` bypasses hooks).

---

## Smart HTTP Gateway (Remote Clone)

Advanced users can `git clone` a project's entire document repository from a
remote workstation, authenticated with a Doctis API token — no Linux account
required. Users at `$g_git_http_write_threshold` (default `MANAGER`) can also
`git push` draft updates; pushes advance the Draft (HEAD) only, never the
On-Record version (see On-Record vs Draft above).

### Architecture

```
git clone http://<user>:<API_TOKEN>@vaio/git/<slug>-<id>.git
               ↓
        Apache routes /git/* to git_http.php
               ↓
        core/git_http_api.php:
          - Extract HTTP Basic credentials from Authorization header
          - Validate API token via api_token_get_user()
          - Resolve repo → project_id from the immutable trailing "-<id>"
            (stale slugs in bookmarked URLs keep working after a rename;
            the canonical on-disk repo name is always served)
          - Check project-level access
            (read: $g_git_http_read_threshold, default DEVELOPER;
             push: $g_git_http_write_threshold, default MANAGER)
          - Allowlist check (only smart-HTTP endpoints permitted)
          - Path-traversal guard
               ↓
        proc_open() → /usr/lib/git-core/git-http-backend
          GIT_PROJECT_ROOT=/var/git/doctis
          PATH_INFO=/<canonical-basename>.git/<service>
               ↓
        CGI response headers parsed; body streamed back to git client
               ↓ (push only)
        pre-receive hook in the bare repo rejects:
          - non-fast-forward (forced) updates
          - ref deletions
          - client writes to refs/doctis/*
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
| `$g_git_http_write_threshold` | `MANAGER` | Minimum project access level for push |

### Authentication

Users authenticate with:
- HTTP Basic **username**: Doctis username
- HTTP Basic **password**: a personal API token from *My Account → API Tokens*

The gateway reuses the existing `api_token_get_user()` infrastructure from
`core/api_token_api.php` — nothing new to provision.

### Access model

Git serves whole repositories. Per-document `view_dwg_threshold` and license
gating **cannot** be enforced over git. Access is project-level only: the caller
needs at least `$g_git_http_read_threshold` on the project to clone, and at
least `$g_git_http_write_threshold` to push.

---

## Developer Direct Access

Developers with shell access to the server can clone and push to bare repos
directly:

```bash
git clone hcr@vaio:/var/git/doctis/example-1.git
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
| Project (`project_id` / `name`) | Repository (one per project, `<slug>-<id>.git`) |
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
- **Pin** — record an approved SHA as a permanent `refs/doctis/approved/*` ref
- **Register** — record an existing SHA against a document record *(todo)*
- **Tag** — optionally mark an approved commit *(todo, nicety)*
- **Trigger** — invoke the pandoc pipeline at the incorporation step *(todo)*
- **Serve** — proxy `git-http-backend` for authenticated remote clone and draft push

---

## Key Source Files

| File | Purpose |
|------|---------|
| `core/classes/GitFileStorageBackend.class.php` | Core GIT backend: store, retrieve, delete, repo init, hook install |
| `core/classes/FileStorageBackendInterface.class.php` | Backend interface definition |
| `core/file_dwg_api.php` | Backend factories; repo-naming helpers (`dwg_project_*`); worktree sync; approved-SHA pinning; `file_dwg_primary_*` call paths |
| `core/git_http_api.php` | Smart HTTP gateway: auth, authz (read + push), CGI proxy |
| `git_http.php` | Smart HTTP entry point |
| `core/constant_inc.php` | `define('GIT', 3)` |
| `config_defaults_inc.php` | `$g_git_storage_root`, `$g_git_worktree_root`, `$g_git_http_*` defaults |
| `admin/tools/git-hooks/pre-receive` | Pre-receive hook source of truth (installed into every bare repo) |
| `admin/test-git-php.php` | 20-step integration test (store/retrieve/delete cycle + hook enforcement) |
| `admin/tools/git-serve.conf` | Apache config for Smart HTTP routing |
