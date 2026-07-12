# Doctis Git Storage — Architecture and Implementation

This document describes how Doctis uses git as its document storage backend:
the governing principles, the repository entity, backend class structure, and
the operations the PHP layer performs. It covers the implemented system only;
outstanding and deferred work is in [GIT_TODO.md](GIT_TODO.md). The design
rationale for the current model (the "C1 refactor": repository as a
first-class entity, path-as-data, git-only primaries) is in
[GIT_SOLUTION_SPACE.md](GIT_SOLUTION_SPACE.md).

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
(Repository *import* — a deliberate, operator-initiated batch read — is
consistent with this; see [GIT_IMPORTER.md](GIT_IMPORTER.md).)

---

## Scope: Primary Documents Are Git-Only; Attachments Never Are

The **primary registered document** of each `dwg` record — the single
version-controlled artefact — is stored **exclusively in git**. There is no
configurable storage method for it: `file_dwg_get_storage_backend()` returns
the GIT backend unconditionally, and a working `git` binary is an install
prerequisite alongside MariaDB.

Document attachments (`{dwg_file}` rows) and bug/issue attachments
(`{bug_file}` rows) are always stored via `DISK` or `DATABASE`; they are
Doctis-specific "assisting metadata" with no business being versioned in the
document store that external users clone.

Config key roles:

| Key | Applies to | Notes |
|-----|-----------|-------|
| (none — always GIT) | Primary registered document | `file_dwg_get_storage_backend()` |
| `$g_dwg_upload_method` | Document attachments only | `GIT` value maps to `DATABASE` |
| `$g_file_upload_method` | Bug/issue attachments | `GIT` falls through to `DATABASE` |

---

## The Repository Entity

A git repository is a first-class Doctis entity — a `{repository}` row
(managed by `core/repository_api.php`) with `name`, `slug`, `default_branch`,
`owner_project_id`, `adopted_from`, `date_created`.

### Project → repository mapping

Projects map to repositories through the `{project_repository}` link table
(kept Doctis-parallel so the MantisBT `{project}` table stays untouched):

- A project with an **explicit link** uses that repository.
- A project without one **inherits** by walking up the project hierarchy to
  the nearest linked ancestor.
- When no ancestor has a repository either, one is **created on demand at the
  top-level project** (named after it, owned by it) the first time any
  document in the tree stores a file.

A project tree therefore shares one repository by default — the natural model
for monorepos and sub-projects — while any sub-project can be given its own
repository by an explicit link. Resolution lives in
`repository_id_for_project()` / `repository_id_for_project_or_create()`.

### On-disk layout

```
/var/git/doctis/<slug>-r<id>.git              bare repo (authoritative store)
/var/www/doctis/worktrees/<slug>-r<id>        Doctis's own working tree (write staging)
```

Examples: `example-r1.git`, `hcrqms-r7.git`. The basename has two parts with
different roles:

| Part | Source | Role | Mutable? |
|------|--------|------|----------|
| slug (`example`) | `{repository}.slug`, from the owner project name | Cosmetic — for humans browsing `/var/git/doctis/` | Refreshed automatically when the owner project is renamed |
| id suffix (`-r<id>`) | `{repository}.id` primary key | Identity — the part the system resolves by | Never changes for the life of the repository |

The stored `slug` column is authoritative for the on-disk name — nothing
scans the storage root. **Resolution is by id, never by slug**: the Smart
HTTP gateway parses the trailing `-r<id>` from the requested URL and serves
the canonical on-disk repo, so clone URLs bookmarked before a project rename
keep working. (The historical rationale — rename split-brain, slug
collisions, degenerate slugs under name-derived repo paths — is preserved in
this file's git history and in GIT_SOLUTION_SPACE.md.)

### Repository relocation on project rename

`project_update()` calls `dwg_project_repo_rename()` (a thin wrapper over
`repository_rename_for_owner_project()`) whenever a project name changes.
For each repository owned by the project, under the advisory storage lock
(`dwg_git_storage_lock()`, an `flock` on `<git_storage_root>/.doctis-lock`):

1. **`rename(2)` the bare repository** — the atomic pivot; resolvers see the
   old name or the new one, never neither. If the target path unexpectedly
   exists, the rename is skipped and logged.
2. **Update the stored slug** — the authoritative name source.
3. **Delete the server worktree** — a disposable write-staging cache whose
   `origin` remote embeds the old bare path; `repository_ensure_on_disk()`
   lazily re-clones it on the next store.

### Key functions (core/repository_api.php)

| Function | Purpose |
|----------|---------|
| `repository_create()` | Insert `{repository}` row + owner link (no disk I/O) |
| `repository_link_project()` | Explicitly link a project to a repository |
| `repository_id_for_project()` | Resolve: own link → nearest linked ancestor → 0 |
| `repository_id_for_project_or_create()` | As above, creating at the top-level project on first need |
| `repository_project_ids()` | All projects resolving to a repository (for per-repo uniqueness checks) |
| `repository_basename()` / `repository_bare_path()` / `repository_worktree_path()` | Naming/paths from the stored slug + id |
| `repository_ensure_on_disk()` | Init bare + configure (hook, receivepack) + clone worktree |
| `repository_adopt()` | `git clone --bare` an existing repo into the store; strip remotes; record default branch |
| `repository_configure_bare()` | Install/refresh pre-receive hook + `http.receivepack` |
| `repository_rename_for_owner_project()` | Slug refresh + on-disk relocation |
| `dwg_git_storage_lock()` / `_unlock()` | Advisory lock serialising relocation against creation/adoption |

`core/file_dwg_api.php` keeps thin project-keyed wrappers
(`dwg_project_repository_id()`, `dwg_project_repo_basename()`,
`dwg_project_bare_repo_path()`, `dwg_project_worktree_path()`,
`dwg_project_repo_rename()`) so document-level code resolves through the
project it already knows. Never derive a repo path from a project name
anywhere else.

---

## Path-as-Data: File Paths Within the Repository

Every primary document's repo-relative path is **stored data** —
`{dwg_primary_file}.git_path` — not a computed convention. Nothing in the
storage layer ever computes a path; it reads the column.

- **New documents** get their path at creation time from the per-project
  template `$g_dwg_repo_path_template` (tokens `{dwg_id}`, `{filename}`,
  `{category}`). The default `{dwg_id}/{filename}` is collision-free by
  construction; a template like `{category}/{filename}` produces
  human-legible repositories at the cost of a creation-time collision check.
- **Imported documents** (repository adoption) keep their native paths
  verbatim — see GIT_IMPORTER.md.
- **Replacements are directory-sticky**: uploading a replacement keeps the
  registered directory and adopts only the new basename. When the path
  changes, the old path is soft-deleted from HEAD in a follow-up commit; when
  it is unchanged, the store commit replaces content in place.
- **Uniqueness is per repository**, across every project sharing it, enforced
  in `file_dwg_primary_register()` / `file_dwg_primary_add()`
  (`file_dwg_primary_path_in_use()`); collisions are a validation error
  naming the holding document.
- Paths are sanitised by `file_dwg_git_path_sanitize()` (traversal, control
  characters, empty segments rejected).

`filename` remains the basename of `git_path`, kept for display and
Content-Disposition.

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

| Class | File | Used for |
|-------|------|----------|
| `GitFileStorageBackend` | `core/classes/GitFileStorageBackend.class.php` | Primary documents (always) |
| `DiskFileStorageBackend` | `core/classes/DiskFileStorageBackend.class.php` | Attachments |
| `DatabaseFileStorageBackend` | `core/classes/DatabaseFileStorageBackend.class.php` | Attachments |

### Factory functions (in `core/file_dwg_api.php`)

| Factory | Used by | Returns |
|---------|---------|---------|
| `file_dwg_get_storage_backend()` | Primary document upload/download/delete | `GitFileStorageBackend`, unconditionally |
| `file_dwg_get_attachment_storage_backend()` | Document attachments | `DISK` or `DATABASE` (`GIT → DATABASE`) |

---

## Database Record

Every primary document registration creates a row in `{dwg_primary_file}`:

| Column | Value | Notes |
|--------|-------|-------|
| `git_path` | Repo-relative file path | Path-as-data; unique per repository |
| `git_sha` | Registered/on-record commit SHA | Pinned at registration/promotion; see On-Record/Draft |
| `git_branch` | Branch at time of registration | |
| `filename` | Basename of `git_path` | Display / Content-Disposition |
| `filesize` / `file_type` | From the git object / caller | `filesize` read via `git cat-file -s` |

The table has **no `project_id` column**; project id is always derived via
`dwg_get_field($dwg_id, 'project_id')`, and the repository via the project.
(The former `folder` and `content` columns are gone — the bare path is
derived from the repository entity, and content never lives in the DB.)

---

## Operations

### Register (the primitive)

`file_dwg_primary_register( dwg_id, user_id, git_path, sha = HEAD, … )` in
`core/file_dwg_api.php` is the single choke point through which every
primary-file registration flows — **it performs no git writes**:

1. Sanitises the path; resolves the project's bare repository.
2. Resolves `''` → current `HEAD`; verifies the blob exists at
   `<sha>:<git_path>` (`git cat-file -e`).
3. Enforces per-repository path uniqueness.
4. Reads `filesize` from the object store; derives `file_type` from the
   extension when not supplied.
5. Inserts/replaces the `{dwg_primary_file}` row.
6. With `$p_pin` (default): writes the SHA to `documents.reference` and pins
   it via `file_dwg_git_pin_approved()`.

This is how existing commits — made by developers directly in git, or present
in an adopted repository — become registered documents. Uploads are layered
on top of it.

### Store (file upload)

`file_dwg_primary_add()` → `GitFileStorageBackend::store()`:

1. Determines the path: registered directory (sticky) + new basename for
   replacements; `$g_dwg_repo_path_template` for first upload. Collision
   check **before** any git write.
2. `store()`: `ensure_git_home()` (HOME fix for Apache), author env from the
   Doctis user, `repository_ensure_on_disk()`, `dwg_git_worktree_sync()`
   (fetch + reset so the commit builds on the latest pushed state), write the
   file at `git_path`, `git add` + `git commit`
   (message: `dwg_id=<N> by <username>`), **mandatory `git push`**.
3. If the path changed, the old path is soft-deleted from HEAD.
4. The new commit SHA is passed to `file_dwg_primary_register()`.

Duplicate content (commit exits 1 — nothing to commit) is treated as a
successful store returning the existing HEAD SHA.

### Retrieve (file download)

`GitFileStorageBackend::retrieve()` runs
`git show <git_sha>:<git_path>` against the bare repository directly (never
the worktree). Historical versions (`dwg_primary_at_sha`) and the current
draft (`dwg_primary_head`) retrieve the same `git_path` at a different SHA.

### Delete (soft delete)

`git rm <git_path>` + commit (`dwg_id=<N> FILE_DELETED by <username>`) +
push. The file disappears from `HEAD` but the full commit history is retained
in the bare repo. History is never rewritten.

---

## On-Record vs Draft

Doctis distinguishes two versions of a document's primary file:

| Concept | Storage | Access |
|---------|---------|--------|
| **On-Record (approved)** | `{dwg_primary_file}.git_sha` — a pinned SHA in the DB | `retrieve()` via `git show <git_sha>:<git_path>` |
| **Draft** | Whatever is at the repo's branch `HEAD` | `file_dwg_git_head_info()` reads it independently of the DB |

`file_dwg_primary_sync_head()` is the "approve current draft" primitive: it
reads `HEAD` and writes it as the new `git_sha`. The registered `git_path` is
the document's identity and is **never changed by promotion** — the path must
exist at HEAD to promote.

### Dangling paths

Because external pushes can rename or delete files (impossible under the old
system-owned layout), `file_dwg_git_head_info()` checks whether the
registered `git_path` still exists at HEAD (`filename: null` when absent).
The document view panel shows a **"missing at HEAD"** badge and
`dwg_primary_head_warn.php` explains the state. The pinned on-record version
remains retrievable regardless — approved SHAs are immune to later branch
history. Remedy: re-upload (re-establishes the path) or register the file's
new location.

### Approved-SHA pinning

Whenever a `git_sha` is recorded (registration, upload, promotion),
`file_dwg_git_pin_approved()` writes a permanent, server-managed git ref:

```
refs/doctis/approved/<dwg_id>/<sequence>  →  <sha>
```

This keeps every approved commit reachable regardless of later branch history
(immune to GC), and forms an out-of-band approval record that does not depend
on the Doctis DB. The pre-receive hook rejects any client push touching
`refs/doctis/*`; only the server writes them (`git update-ref` bypasses hooks).

---

## Smart HTTP Gateway (Remote Clone)

Advanced users can `git clone` a repository from a remote workstation,
authenticated with a Doctis API token — no Linux account required. Users at
`$g_git_http_write_threshold` (default `MANAGER`) can also `git push` draft
updates; pushes advance the Draft (HEAD) only, never the On-Record version.

### Architecture

```
git clone http://<user>:<API_TOKEN>@vaio/git/<slug>-r<id>.git
               ↓
        Apache routes /git/* to git_http.php
               ↓
        core/git_http_api.php:
          - Extract HTTP Basic credentials from Authorization header
          - Validate API token via api_token_get_user()
          - Resolve repo → {repository} row from the immutable trailing "-r<id>"
            (stale slugs in bookmarked URLs keep working after a rename;
            the canonical on-disk repo name is always served)
          - Authorise against the repository's OWNER project
            (read: $g_git_http_read_threshold, default DEVELOPER;
             push: $g_git_http_write_threshold, default MANAGER)
          - Allowlist check (only smart-HTTP endpoints permitted)
          - Path-traversal guard
               ↓
        proc_open() → /usr/lib/git-core/git-http-backend
               ↓
        CGI response headers parsed; body streamed back to git client
               ↓ (push only)
        pre-receive hook in the bare repo rejects:
          - non-fast-forward (forced) updates
          - ref deletions
          - client writes to refs/doctis/*
```

### Access model

Git serves whole repositories. Per-document `view_dwg_threshold` and license
gating **cannot** be enforced over git. Access is authorised against the
repository's **owner project** — the clone/push boundary is the repository,
which for a shared (project-tree) repository means the top-level project's
access level. Sub-projects sharing a parent's repository have no clone URL of
their own.

### Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `$g_git_http_enabled` | `OFF` | Master switch; set `ON` in `config_inc.php` to activate |
| `$g_git_http_backend` | `/usr/lib/git-core/git-http-backend` | Path to CGI binary |
| `$g_git_http_read_threshold` | `DEVELOPER` | Minimum owner-project access for clone/fetch |
| `$g_git_http_write_threshold` | `MANAGER` | Minimum owner-project access for push |

Users authenticate with their Doctis username and a personal API token from
*My Account → API Tokens* (reuses `api_token_get_user()`; nothing new to
provision).

---

## Developer Direct Access

Developers with shell access to the server can clone and push to bare repos
directly:

```bash
git clone hcr@vaio:/var/git/doctis/example-r1.git
```

This is entirely independent of Doctis. Developers push freely as part of
normal iterative development — pushes advance the Draft only; Doctis remains
unaware until a lifecycle transition explicitly records a SHA. The bare repos
are at `/var/git/doctis/`, owned `www-data:www-data`, mode `2770`.

---

## Metadata Mapping

| Doctis concept | Git concept |
|---------------|-------------|
| Repository entity (`{repository}`) | Bare repository `<slug>-r<id>.git` |
| Project (tree) | Shares one repository via `{project_repository}` link / hierarchy inheritance |
| Document primary file | `{dwg_primary_file}.git_path` within the repository |
| Upload event | Git commit (author = Doctis user) |
| Registration event | DB row + `refs/doctis/approved/*` pin — no commit |
| Delete event | Soft-delete commit (`git rm` + commit + push) |
| `{dwg_primary_file}.git_sha` | Registered/on-record commit SHA |
| `{dwg_primary_file}.date_added` | Registration timestamp (DB) |
| Uploader `user_id` | `GIT_AUTHOR_NAME` / `GIT_AUTHOR_EMAIL` on the commit |
| Adopted origin | `{repository}.adopted_from` |

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
- **Register** — record an existing SHA + path against a document record
- **Pin** — record an approved SHA as a permanent `refs/doctis/approved/*` ref
- **Adopt** — clone an existing repository into the store (importer)
- **Tag** — optionally mark an approved commit
- **Trigger** — invoke the pandoc pipeline at the incorporation step *(todo)*
- **Serve** — proxy `git-http-backend` for authenticated remote clone and draft push

---

## Key Source Files

| File | Purpose |
|------|---------|
| `core/repository_api.php` | Repository entity: CRUD, project resolution, disk lifecycle, adoption, rename relocation, storage lock |
| `core/classes/GitFileStorageBackend.class.php` | GIT backend: store, retrieve, delete (path-as-data) |
| `core/classes/FileStorageBackendInterface.class.php` | Backend interface definition |
| `core/file_dwg_api.php` | Register primitive; path template/sanitizer/collision; project-keyed wrappers; worktree sync; approved-SHA pinning; `file_dwg_primary_*` call paths |
| `core/git_http_api.php` | Smart HTTP gateway: auth, authz (owner project), CGI proxy |
| `git_http.php` | Smart HTTP entry point |
| `config_defaults_inc.php` | `$g_git_storage_root`, `$g_git_worktree_root`, `$g_dwg_repo_path_template`, `$g_git_http_*` defaults |
| `admin/tools/git-hooks/pre-receive` | Pre-receive hook source of truth (installed into every bare repo) |
| `admin/test-git-php.php` | 20-step git-mechanics test (no core bootstrap) |
| `admin/test-git-doctis.php` | Mapping-layer integration test: entity, resolution, templates, register, collisions, dangling paths, adoption, relocation |
| `admin/tools/git-serve.conf` | Apache config for Smart HTTP routing |
