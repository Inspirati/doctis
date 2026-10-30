# Git storage — working notes

The detail that used to sit in CLAUDE.md's *File Storage Backend Architecture*
section, split out on 2026-10-30 because most of it is needed only when working
on storage itself. The architecture proper is
[GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md); this is the operational residue —
the repository entity's naming rules, path-as-data, the Git Store page's
behaviour, the key source files, and the VM's current infrastructure state.

Design rationale: [GIT_SOLUTION_SPACE.md](GIT_SOLUTION_SPACE.md). Source
release records: [SOURCE_REPOSITORIES.md](SOURCE_REPOSITORIES.md). LFS:
[GIT_LFS.md](GIT_LFS.md).

---


**Primary registered documents are stored exclusively in git** — there is no
configurable method for them (`file_dwg_get_storage_backend()` returns
`GitFileStorageBackend` unconditionally; a working `git` binary is an install
prerequisite).  `DISK`/`DATABASE` remain for **attachments only**:

| Applies to | Governed by | Backends |
|-----------|-------------|----------|
| Primary registered document | (nothing — always GIT) | `GitFileStorageBackend` |
| Document attachments (`{dwg_file}`) | `$g_dwg_upload_method` | `DISK` / `DATABASE` (`GIT` value maps to `DATABASE`) |
| Bug/issue attachments (`{bug_file}`) | `$g_file_upload_method` | `DISK` / `DATABASE` (`case GIT:` falls through to `DATABASE` in `file_api.php` and `print_dwg_attachment_preview_text()`; `file_download.php` remaps `GIT` to `DATABASE` before its switches) |

Rationale: the registered document is the only item a `git clone` user should
see and the only revision-controlled record; attachments are Doctis
"assisting metadata".  Full architecture:
[doc/git/GIT_ARCHITECTURE.md](../../doc/git/GIT_ARCHITECTURE.md); design rationale:
[doc/git/GIT_SOLUTION_SPACE.md](../../doc/git/GIT_SOLUTION_SPACE.md).

**Source repository release records.** A primary document normally names one
file. With `$g_dwg_source_release_enabled` ON for a project it may instead
name a git **tree** — the repository root, or one subtree — so the document
records a *release of a repository* at a commit. Promoting the Draft to On
Record is cutting a release, pinned permanently by
`refs/doctis/approved/<dwg_id>/<n>`; content arrives by `git push`, never by
upload, and downloads as `git archive` (zip or `tar.gz`). Off by default,
per-project. A tree-rooted row is distinguished by **`{dwg}.kind`** (document/source) and
**`{dwg}.frozen`** (line/release); `file_type = DWG_TREE_MIME_TYPE`
(`file_dwg_primary_is_tree()`) survives for display and Content-Type only.
Registration is `dwg_source_release_page.php`.

Each bare repository carries a **push policy** in its own git config
(`doctis.pushpolicy`, set on Manage → **Git Store**):
`document` (the default, append-only — no force-push, no ref deletion) or
`source` (normal development: both allowed on every ref). `refs/doctis/*` stay
unpushable under both, which is what keeps a released commit reachable however
the branches are rewritten. Hooks: `admin/tools/git-hooks/pre-receive` and
`post-receive` (the latter repairs a bare repo's dangling HEAD).

**Git LFS.** Doctis is the LFS server for the repositories it hosts: a client
derives its endpoint by appending `/info/lfs` to the remote URL, so the
requests arrive on the same `/git/` route and use the same API-token Basic
auth. [core/git_lfs_api.php](../../core/git_lfs_api.php) implements the batch call,
the basic transfer and verify; objects live per repository under
`$g_git_lfs_root` (`/var/git/doctis-lfs`), outside the bare repos so an LFS
object can never be packed into one. `GitFileStorageBackend::retrieve()`
resolves a pointer to its object and returns a *path*, which
`file_dwg_send_content()` streams — so the three `file_download.php` document
routes all work without holding the file in memory. Off unless
`$g_git_lfs_enabled` is ON. Two known gaps: a source-release `git archive`
still emits pointers, and import of an LFS repository is still refused.
Built 2026-10-26, stages 1-2 of 3, and merged into `dev` as a6566b213; pushed.
Read [doc/git/GIT_LFS.md](../../doc/git/GIT_LFS.md) before touching it — in
particular, never set `"authenticated": true` in a batch reply: it tells the
client to withhold credentials from the transfer, which produces an endless
401 loop.

Built 2026-10-25, trial-tested on the VM, and **merged into `dev`** as
a6566b213 on 2026-10-26; pushed. `{dwg}.kind` and `{dwg}.frozen` (89c16592e)
were an in-place edit to step 18, needing a rebuild — **already done on live**
(verified 2026-10-30). Design record, spike results, how to drive it and
what is left: [doc/git/SOURCE_REPOSITORIES.md](../../doc/git/SOURCE_REPOSITORIES.md)
— read it before touching this. Sharp edges: a project's repository must exist
before anyone can push and only a script creates one (§8.1), and a git push
sends the whole packfile as one request body, so the `/git/` route needs its
body limit lifted (`client_max_body_size 0`) or every real push fails with
HTTP 413.

**Interface:** [core/classes/FileStorageBackendInterface.class.php](../../core/classes/FileStorageBackendInterface.class.php)

```
store(tmp_file, size, unique_name, file_path, browser_upload, metadata[]) → [diskfile, folder, content]
retrieve(row, project_id) → [type, content] | false
delete(diskfile, project_id, metadata[]) → void
```

For the GIT backend, `metadata['git_path']` (repo-relative path) is required
by `store()`/`delete()`, and `retrieve()` reads `row['git_path']`.

## Repository entity and naming

A git repository is a first-class entity — a `{repository}` row managed by
[core/repository_api.php](../../core/repository_api.php).  Projects map to
repositories via the `{project_repository}` link table: explicit link →
that repo; no link → inherit from the nearest linked ancestor project; no
ancestor either → created on demand at the **top-level project**.  A project
tree therefore shares one repository by default (monorepo/sub-project model);
a sub-project can be explicitly linked to its own.

```
/var/git/doctis/<slug>-r<id>.git        bare repo (authoritative store)
/var/www/doctis/worktrees/<slug>-r<id>  working tree (write staging area)
```

Basename `<slug>-r<repository_id>` (e.g. `example-r1`); the stored `slug`
column is authoritative (nothing scans the disk); resolution is by the
immutable `-r<id>` suffix.  Renaming a project relocates its owned repos
(`repository_rename_for_owner_project()`, via the `dwg_project_repo_rename()`
wrapper called from `project_update()`).  Document code resolves through the
project-keyed wrappers in `core/file_dwg_api.php`
(`dwg_project_bare_repo_path()` etc.) — never derive a repo path from a
project name directly.

## Path-as-data

Each primary file's repo-relative path is stored in
`{dwg_primary_file}.git_path` — never computed at read time.  New documents
get a path from `$g_dwg_repo_path_template` (tokens `{dwg_id}`, `{filename}`,
`{category}`; default `{dwg_id}/{filename}`; per-project overridable);
imported documents keep native paths.  Replacements are directory-sticky
(basename may change; old path soft-deleted when it changes).  Path
uniqueness is enforced per repository across all projects sharing it.
`file_dwg_primary_register()` is the single registration choke point — it
verifies `<sha>:<git_path>` exists, checks collisions, writes the row, and
pins the approved ref; uploads layer on it.

**`{dwg_primary_file}` columns:** `git_path`, `git_sha` (on-record SHA),
`git_branch`, `filename` (= basename of git_path), `filesize`, `file_type`.
No `folder`/`content` columns, and NO `project_id` column — project ID must
always be derived via `dwg_get_field($dwg_id, 'project_id')`.

**Push is mandatory:** `git commit` only writes to the working tree's local
history. The bare repo receives nothing until `git push` is called.
`GitFileStorageBackend::store()` always pushes as its final step.

**HOME fix:** web-server PHP workers run without `HOME` (PHP-FPM clears the
environment by default; Apache did the same), so git cannot find
`/var/www/.gitconfig`. `GitFileStorageBackend::ensure_git_home()` calls
`putenv('HOME=...')` using `posix_getpwuid()` before every git operation.
This is transparent at runtime but explains why manual `sudo -u www-data`
tests always worked while web requests failed before the fix was in place.

**Branch name:** `init.defaultBranch = main` in `/var/www/.gitconfig` ensures
new repos use `main`. The PHP backend calls `getCurrentBranchName()` before
every push rather than hardcoding `'main'`, so both `main` and `master` repos
are handled correctly. ZIP imports create `dev`. See "Branch naming: main vs
master" in [doc/git/GIT_SERVER_SETUP.md](../../doc/git/GIT_SERVER_SETUP.md) for how
to rename an existing `master` branch.

**Soft delete:** `git rm` + commit + push. The file disappears from HEAD but
the full commit history is retained in the bare repo.

**Duplicate content:** if `git commit` exits 1 (nothing to commit — identical
content re-uploaded), the backend treats it as a successful store and returns
the existing HEAD SHA.

## Key files (git storage)

| File | Role |
|------|------|
| [core/repository_api.php](../../core/repository_api.php) | `{repository}` entity: CRUD, project resolution, disk lifecycle, `repository_adopt()`, rename relocation, storage lock |
| [core/file_dwg_api.php](../../core/file_dwg_api.php) | `file_dwg_primary_register()`, path template/sanitizer/collision, project-keyed wrappers, worktree sync, approved-SHA pinning |
| [core/classes/GitFileStorageBackend.class.php](../../core/classes/GitFileStorageBackend.class.php) | store/retrieve/delete via `git_path` |
| [core/git_http_api.php](../../core/git_http_api.php) | Smart HTTP gateway — resolves `-r<id>`, authorises against the repository's owner project |
| [core/git_store_api.php](../../core/git_store_api.php) / [manage_git_store_page.php](../../manage_git_store_page.php) | Manage → **Git Store** (administrators). Lists every repository in the database or on disk and flags mismatches. For a selected repository it shows: files by folder with search; per-file history, preview and download (`manage_git_store_download.php`); registered documents with On Record vs HEAD state; commits; refs, including approved-version pins; and the server worktree. An unregistered file at HEAD offers **Register**, which opens the normal Create Document form with `git_path` set and, for Markdown carrying frontmatter, the fields pre-filled (`core/dwg_frontmatter_api.php`, shared with the importer); **Register all N with frontmatter** does the ones needing no input. Registration pins the document to the commit that last changed that path, never HEAD. Any file type: a document is the Doctis record, not the format. A repository whose owner project was deleted reads as **`unowned`** — `project_delete()` never removed repositories, so the row, the link rows, the bare repo, the worktree and the LFS objects all survived invisibly — and can be removed there explicitly (`repository_remove()`). Never called from `project_delete()`: once a project's documents are gone the bare repo is their only record |
| [core/constant_inc.php](../../core/constant_inc.php) | `define('GIT', 3)` (still used by attachment mapping) |
| [config_defaults_inc.php](../../config_defaults_inc.php) | `$g_git_storage_root`, `$g_git_worktree_root`, `$g_dwg_repo_path_template` |
| [core/file_api.php](../../core/file_api.php) / [core/print_dwg_api.php](../../core/print_dwg_api.php) / [file_download.php](../../file_download.php) | Attachment-side `GIT → DATABASE` fall-throughs |
| [admin/import-git-repo.php](../../admin/import-git-repo.php) | Repository importer: adopt an existing git repo as a project (+ sub-projects) and register its files as documents by reference. `--dry-run`/`--update`; `.doctis` manifest or CLI flags; run as www-data (wrapper: `admin/tools/doctis-git-import.sh`). See [doc/git/GIT_IMPORTER.md](../../doc/git/GIT_IMPORTER.md) |

## Integration tests

```bash
# Pure git mechanics (no core bootstrap)
sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php

# Mapping layer (bootstraps core) — sanitizer, repository entity/resolution,
# path templates, register-by-reference, collisions, dangling paths,
# sync-to-HEAD, repository adoption, rename relocation
sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php
```

Both create and tear down their own state and must pass before enabling git
storage on any new server.

## Server infrastructure status (native VM) — current state

| Item | Status |
|------|--------|
| `/var/git/doctis/` | `www-data:www-data`, mode `2770`; `robert` reads it through the `www-data` group |
| `/var/www/doctis/worktrees/` | `www-data:www-data`, mode `2770` |
| `/var/www/.gitconfig` | Server identity `Doctis <doctis@vaio.local>` — the hostname is a leftover from the retired `vaio` host and is cosmetic; `init.defaultBranch=main`, `safe.directory=*` |
| `czproject/git-php v4.4.0` | Installed via Composer into `vendor/` |
| Repositories | One per top-level project, created on import or at first upload: currently `hcrqms-r1` and `lidar-r2` (imported, branch `dev`) and `hcr571-r3` (`main`); each has the pre-receive hook and `http.receivepack=true` |
| `$g_file_upload_method` / `$g_dwg_upload_method` | `DISK` / `GIT` in `config/config_inc.php` — attachments only (`GIT` maps to DATABASE); primaries are always git |
