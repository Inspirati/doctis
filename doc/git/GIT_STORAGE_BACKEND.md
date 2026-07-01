# Git Storage Backend for Doctis Documents

*Analysis and proposal — June 2026*

---

## 1. Executive Summary

Doctis currently supports two document file storage mechanisms, selected via the `file_upload_method` config key:

| Method | Constant | How files are stored |
|--------|----------|----------------------|
| `DISK` | `1` | Host filesystem; filenames are 32-char MD5 hashes |
| `DATABASE` | `2` | MariaDB `content` BLOB column in `{dwg_file}` |

This document analyses the feasibility of a third mechanism — **GIT** — that stores each document file as a blob committed to a git repository, giving Doctis a tamper-evident, versioned, auditable file store with no dependency on a shared filesystem or DB blob sizes.

---

## 2. Current Architecture — What We Work With

### 2.1 Key functions

| Function | File | Purpose |
|----------|------|---------|
| `file_dwg_add()` | `core/file_dwg_api.php:904` | Store new file; branches on `file_upload_method` |
| `file_dwg_get_content()` | `core/file_dwg_api.php:1288` | Retrieve raw bytes; branches on `file_upload_method` |
| `file_dwg_delete()` | `core/file_dwg_api.php:716` | Remove file; branches on `file_upload_method` |
| `file_dwg_delete_local()` | `core/file_dwg_api.php:676` | `unlink()` for DISK method |
| `file_dwg_attach_files()` | `core/file_dwg_api.php:71` | Called from upload page; delegates to `file_dwg_add()` |

### 2.2 Database metadata (`{dwg_file}` table)

Every upload — regardless of storage method — creates a row in `{dwg_file}`:

```
id, dwg_id, user_id, dwgnote_id
title, description
diskfile   (MD5 hash used as filesystem filename)
filename   (original display name)
folder     (absolute disk path; empty for DATABASE)
filesize, file_type, date_added
content    (BLOB; NULL for DISK)
```

### 2.3 The branch pattern

Both `file_dwg_add()` and `file_dwg_get_content()` use the same pattern:

```php
if( config_get('file_upload_method') == DISK ) {
    // filesystem logic
} else {
    // database BLOB logic
}
```

There is no storage abstraction interface — the two paths are inlined. Adding a third method means extending these branches (or, ideally, refactoring to a backend interface first).

### 2.4 Versioning gap

The `{documents}` table has `revision` and `revision_date` metadata fields, but these are free-text — no attachment-level version history exists. The history log records `FILE_ADDED` / `FILE_DELETED` events with timestamps and user IDs, but not content hashes. A git backend would close this gap automatically.

---

## 3. Why Git as a Storage Backend?

### 3.1 Strengths

- **Immutable history** — every version of every document is permanently addressable by SHA-1/SHA-256 object hash, providing cryptographic proof that a file has not been altered after submission.
- **Audit trail** — git commit metadata (author, committer, timestamp, message) maps naturally onto Doctis review cycles and edition/revision semantics.
- **Cheap branching** — review branches, proposed-revision branches, and release tags all come for free.
- **Standard tooling** — authorised users can clone the repo and work offline with standard git clients; Doctis becomes one consumer of a git remote rather than the sole gatekeeper.
- **LFS compatibility** — large binary files (PDFs, CAD drawings) can be offloaded to Git LFS, keeping the repo index small while retaining the audit properties.
- **Remote hosting** — the backing store can be a self-hosted Gitea/Forgejo instance, a bare repo on a server, or a managed service (GitHub/GitLab), decoupling Doctis from its own disk.

### 3.2 Weaknesses / Risks

- **PHP ↔ git integration** — PHP has no native git library; requires either shelling out to `git` CLI (fragile, injection risk) or using `libgit2` via the `php-git2` / `git.php` library bindings.
- **Concurrency** — concurrent uploads require locking (git repos are not concurrency-safe for simultaneous pushes without a server).
- **Binary diffs** — git's delta compression is ineffective on binary files unless Git LFS is used; repository size can grow quickly.
- **Access control** — git access control is coarser than Doctis project-level permissions; would need a dedicated repo per Doctis project, or a server-side hook approach.
- **Latency** — each upload becomes a `git add` + `git commit` + (optionally) `git push`, adding round-trip latency compared to a direct write.
- **Rollback complexity** — deleting a file from git history requires `git filter-repo` or force-push; "delete" in Doctis would need to be a soft-delete commit rather than actual history rewriting.

---

## 4. Integration Approaches

Three approaches are ranked by implementation effort and architectural cleanliness.

---

### Approach A — Shell-out Wrapper (Low effort, highest risk)

PHP shells out to `git` CLI commands (`exec()`, `shell_exec()`, `proc_open()`).

**Upload flow:**
```
file_dwg_add()
  → write temp file to a git working tree
  → exec: git -C $repo add $file
  → exec: git -C $repo commit -m "dwg_id=$id rev=$revision user=$user"
  → exec: git -C $repo push origin main   [optional]
  → store commit SHA in {dwg_file}.diskfile
  → store repo path in {dwg_file}.folder
```

**Retrieval flow:**
```
file_dwg_get_content()
  → exec: git -C $repo show $sha:$path
  → return stdout bytes
```

**Pros:** Minimal new code; re-uses existing git binary.  
**Cons:** Shell injection risk (filenames must be rigorously sanitised); brittle to PATH/environment changes in PHP-FPM; difficult to unit-test; performance overhead per request.

**Verdict:** Viable for a prototype/PoC; not recommended for production.

---

### Approach B — libgit2 / CzProject\GitPhp (Medium effort, safer)

Use an established PHP library that wraps git operations without shelling out:

- **[czproject/git-php](https://github.com/czproject/git-php)** — pure PHP wrapper over git CLI, with proper argument escaping and exception handling; widely used.
- **[php-git2](https://github.com/libgit2/php-git2)** — PHP extension binding to libgit2 (C library); fast, no subprocess, but requires a compiled extension.

**Recommended library:** `czproject/git-php` (composer installable, no C extension required, actively maintained).

**Upload flow:**
```php
$git = new CzProject\GitPhp\Git;
$repo = $git->open($t_repo_path);
file_put_contents($t_file_path, $p_content);
$repo->addFile($t_file_path);
$repo->commit("dwg #{$p_dwg_id} rev {$p_revision} by {$t_user_name}");
$t_sha = $repo->getLastCommitId()->toString();
// store $t_sha in {dwg_file}.diskfile
```

**Retrieval:**  Use `git show $sha:path` via the library, or maintain a mirror of the working tree and read directly.

**Pros:** Proper argument escaping; testable; clear error handling.  
**Cons:** Still depends on git binary (but via safe API); working tree on disk required.

**Verdict:** Recommended starting point.

---

### Approach C — Dedicated Storage Backend Interface (High effort, best architecture)

Refactor the inline `if/else` branches into a proper PHP interface, then implement git as one of several backends. This is the cleanest long-term approach and aligns with Doctis's goal of staying close to MantisBT structure while extending it in parallel files.

**New files:**

```
core/classes/storage/
    FileStorageBackendInterface.php   — interface
    DiskStorageBackend.php            — wraps existing DISK logic
    DatabaseStorageBackend.php        — wraps existing DATABASE logic
    GitStorageBackend.php             — new git backend
```

**Interface:**

```php
interface FileStorageBackendInterface {
    /** Store file content; return an opaque string identifier */
    public function store( string $content, array $metadata ): string;

    /** Retrieve content by identifier */
    public function retrieve( string $identifier ): string;

    /** Delete file; git backend performs a soft-delete commit */
    public function delete( string $identifier ): void;

    /** Return human-readable storage location for display */
    public function describe( string $identifier ): string;
}
```

**Factory:**

```php
// core/classes/storage/FileStorageBackendFactory.php
function file_dwg_get_storage_backend(): FileStorageBackendInterface {
    switch( config_get( 'file_upload_method' ) ) {
        case DISK:     return new DiskStorageBackend();
        case DATABASE: return new DatabaseStorageBackend();
        case GIT:      return new GitStorageBackend();
    }
}
```

`file_dwg_add()` and `file_dwg_get_content()` each become a two-liner:

```php
$backend = file_dwg_get_storage_backend();
$t_identifier = $backend->store( $t_content, $t_metadata );
```

**Pros:** Clean separation of concerns; each backend is unit-testable in isolation; future backends (S3, WebDAV, etc.) slot in without touching core functions; minimal diff to MantisBT since changes are in new parallel files.  
**Cons:** Requires refactoring two core functions and all call sites; interface must accommodate the fact that `{dwg_file}.diskfile` and `.folder` currently serve double duty as both metadata and storage identifier.

**Verdict:** Recommended final architecture; can be delivered incrementally (Phase 1: interface + DISK/DATABASE backends; Phase 2: GIT backend).

---

## 5. Repository Layout Strategy

Regardless of approach, the git repository layout must be decided upfront.

> **Terminology note** — In Doctis, a *license* is a skill, security clearance, or professional qualification held by a registered user. Document access can be restricted to users who hold specified licenses. This is distinct from the MantisBT *project* concept (a top-level organisational boundary grouping documents and issues). The sections below use *project* as the repository boundary; licenses are a per-user access-control attribute and are not relevant to repository layout.

### Option 5.1 — One repo per Doctis installation

```
$g_git_storage_path/
    documents/
        <dwg_id>/
            <filename>          ← current revision
        …
```

Simple, but conflates all projects into one repo; git access control cannot be project-scoped.

### Option 5.2 — One repo per Doctis Project

```
$g_git_storage_path/
    <project_name>/
        <dwg_reference>/
            <filename>
        …
```

Maps cleanly onto the Doctis/MantisBT project concept — the natural administrative and access-control boundary for a set of documents. Enables per-project read-only git clones to be handed to external reviewers without exposing documents from other projects.  
**Recommended.**

### Option 5.3 — One repo per Document

Each document has its own git repo; the document `reference` field becomes the repo name.

Very clean history; trivial to hand a single document's git repo to a reviewer. Cost: many small repos; overhead of managing repo discovery.

---

## 6. Metadata Mapping

| Doctis concept | Git concept |
|---------------|-------------|
| Project (`project_id` / `name`) | Repository (one repo per project) |
| Document `reference` | Subdirectory path within the repo |
| Document `revision` | Git tag (`v{edition}.{revision}`) or branch |
| Upload event (`FILE_ADDED`) | Git commit (with Doctis user as author) |
| Delete event (`FILE_DELETED`) | Soft-delete commit (removes file from tree; history retained) |
| `{dwg_file}.diskfile` | Git blob SHA or `<commit>:<path>` ref |
| `{dwg_file}.folder` | Repo root path (or remote URL) |
| `{dwg_file}.date_added` | Commit timestamp |
| Uploader `user_id` | Commit author email (`user@doctis.local`) |
| User license (qualification) | Not represented in git; remains a Doctis DB concept only |

---

## 7. Schema Changes

A new constant and config key are required:

```php
// core/constant_inc.php
define( 'GIT', 3 );

// config_defaults_inc.php
$g_file_upload_method = DATABASE;          // unchanged default
$g_git_storage_path   = '';               // abs path to repo root (GIT method)
$g_git_remote_url     = '';               // optional: push to bare remote
$g_git_lfs_enabled    = OFF;             // enable Git LFS for large files
```

The `{dwg_file}` table requires no schema changes: `diskfile` stores the git object ref and `folder` stores the repo path, consistent with existing DISK semantics.

---

## 8. File Content Integrity

A key advantage of the git backend is that content hashes are computed automatically. Doctis can expose these for verification:

- **Store:** After `git commit`, record the blob SHA (`git hash-object <file>`) in a new optional `{dwg_file}.content_hash` column (VARCHAR 64, NULL for DISK/DATABASE backends).
- **Verify:** On download, recompute SHA and compare — provides tamper detection that the existing DISK and DATABASE backends lack.

Optional schema addition:

```sql
ALTER TABLE {dwg_file} ADD COLUMN content_hash VARCHAR(64) NULL DEFAULT NULL AFTER content;
```

---

## 9. Security Considerations

1. **Path traversal** — Document `reference` and `filename` fields must be sanitised before use as filesystem paths. Use `basename()` and a whitelist of allowed characters.
2. **Command injection** — If shelling out (Approach A), every variable passed to `exec()` must be escaped with `escapeshellarg()`. Using a library (Approach B/C) mitigates this.
3. **Repo isolation** — PHP process must have write access to `$g_git_storage_path` but not beyond. Use a dedicated system user.
4. **Secrets in commit messages** — Commit messages should reference Doctis IDs only; never embed passwords or tokens.
5. **Push credentials** — If pushing to a remote, credentials must be managed via SSH keys or a credential helper configured outside PHP, never hardcoded.
6. **Git LFS tokens** — LFS authentication tokens have expiry; the backend must handle token refresh or use a long-lived SSH key.

---

## 10. Recommended Phased Implementation Plan

### Phase 1 — Storage Backend Interface (no new storage method)

1. Create `core/classes/storage/FileStorageBackendInterface.php`.
2. Create `DiskStorageBackend.php` and `DatabaseStorageBackend.php` wrapping existing logic.
3. Refactor `file_dwg_add()`, `file_dwg_get_content()`, `file_dwg_delete()` to use the factory.
4. Add unit tests for each backend.
5. Ship — zero user-visible change; purely internal refactor.

### Phase 2 — Git Backend (Approach B/C)

1. `composer require czproject/git-php`.
2. Create `GitStorageBackend.php` implementing the interface.
3. Add `GIT` constant, config keys, and admin UI fields.
4. Implement repo-per-project layout: one bare working tree per project under `$g_git_storage_path/<project_name>/`.
5. Add `content_hash` column to `{dwg_file}` schema migration.
6. Implement download integrity check (optional, config flag).
7. Add admin page to browse/clone per-project repositories.

### Phase 3 — Remote Push & Reviewer Access

1. Configure optional remote (`$g_git_remote_url`).
2. On each commit, push to remote in a background job (or synchronously with timeout).
3. Expose per-project clone URLs in the Doctis project management pages.
4. Optional: read-only git HTTP access via `git-http-backend` behind Doctis authentication.

---

## 11. Open Questions

1. **Single repo vs. per-project?** The per-project model is recommended but adds repo-management overhead (one `git init` per project created in Doctis). Would a per-installation model with a subdirectory per project suffice, accepting that git access control cannot be project-scoped?
2. **Signed commits?** GPG-signed commits would strengthen audit properties but require key management. Out of scope for Phase 2?
3. **LFS threshold?** What file size triggers LFS storage? PDF documents are typically 1–50 MB; CAD files can exceed 500 MB.
4. **Branching strategy for drafts?** Should in-review document revisions live on a branch (`review/dwg-123`) and be merged on approval? This maps neatly onto the Doctis review workflow but adds complexity.
5. **Soft-delete tombstone format?** When a file is "deleted" in Doctis, should the git commit remove the file from the tree, or replace it with a zero-byte `.deleted` sentinel? Removing is cleaner; the sentinel is easier to detect.
6. **MantisBT upstream sync impact?** `file_api.php` (bug attachments) is MantisBT-owned; the git backend should be implemented only in `file_dwg_api.php` to avoid upstream merge conflicts. Confirm this constraint before starting Phase 1.

---

## 12. References

| Item | Location |
|------|----------|
| Existing upload logic | [core/file_dwg_api.php:904–1099](../core/file_dwg_api.php) |
| Existing retrieval logic | [core/file_dwg_api.php:1288–1340](../core/file_dwg_api.php) |
| Existing delete logic | [core/file_dwg_api.php:716–747](../core/file_dwg_api.php) |
| Storage config defaults | [config_defaults_inc.php:2317–2417](../config_defaults_inc.php) |
| DISK/DATABASE constants | [core/constant_inc.php:191–192](../core/constant_inc.php) |
| dwg_file schema | [admin/schema.php:1057+](../admin/schema.php) |
| Download entry point | [file_download.php:37–245](../file_download.php) |
| Delete entry point | [dwg_file_delete.php:70](../dwg_file_delete.php) |
| czproject/git-php | https://github.com/czproject/git-php |
| libgit2 / php-git2 | https://libgit2.org / https://github.com/libgit2/php-git2 |
| Git LFS | https://git-lfs.com |
