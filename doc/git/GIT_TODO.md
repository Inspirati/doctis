# Doctis Git Storage — Outstanding Work

This document tracks all deferred features, design decisions to be made, and
development tasks for the git storage system. See [GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md)
for the implemented baseline.

Status: ☐ todo · ◐ in progress · ⊘ explicitly deferred · ☑ done

---

## 1. Repository Naming Stability — ☑ DONE (Option C implemented 2026-07-03)

**Implemented:** Repos are named `<slug>-<project_id>.git` (e.g. `example-1.git`).
The canonical helpers live in `core/file_dwg_api.php`:

| Helper | Purpose |
|--------|---------|
| `dwg_project_repo_basename()` | Canonical basename `<slug>-<id>`; returns the existing on-disk name when present (rename-tolerant), else computes from the current project name |
| `dwg_project_repo_basename_existing()` | On-disk lookup by immutable id suffix |
| `dwg_project_bare_repo_path()` | `<git_storage_root>/<basename>.git` |
| `dwg_project_worktree_path()` | `<git_worktree_root>/<basename>` |

All former slug-expression sites (eight by implementation time) now use these
helpers.  The Smart HTTP gateway resolves repos by the trailing `-<id>` and
serves the canonical on-disk repo, so clone URLs bookmarked before a project
rename keep working.  A name that slugifies to nothing falls back to the slug
`project` (e.g. `project-9.git`).  The live `example.git` on vaio was migrated
to `example-1.git` (bare + worktree + remote URL + stored `folder` values).

**Follow-up (2026-07-04):** renaming a project now automatically relocates its
repository so the cosmetic slug tracks the project name —
`dwg_project_repo_rename()` in `core/file_dwg_api.php`, called from
`project_update()`, serialised by an advisory storage lock shared with
`ensure_project_repo()`.  See
[GIT_ARCHITECTURE.md §Repository relocation on project rename](GIT_ARCHITECTURE.md).

**Design rationale** (why the `-<id>` suffix, the three defects of the old
name-only scheme, and the resolution/lifecycle rules) is documented in
[GIT_ARCHITECTURE.md §Why the `-<id>` suffix](GIT_ARCHITECTURE.md).  The full
historical write-up (1a rename split-brain, 1b slug collisions, 1c degenerate
slugs, 1d duplicated slug expression) is preserved in git history of this file
prior to 2026-07-03.

### Implementation tasks for Option C — all done

1. ☑ All slug expressions replaced by the shared helpers above (eight sites:
   backend class ×3, `file_dwg_api.php` ×4 including `git_touch`,
   `git_http_api.php`, `dwg_primary_head_warn.php`,
   `dwg_primary_file_tag_page.php`).
2. ☑ Repo location is derived from the immutable project id alone
   (on-disk lookup by `-<id>` suffix) — no schema change was needed; the
   per-file `folder` column is retained as before for `retrieve()`.
3. ☑ `store()`, `delete()`, `retrieve()` and the gateway all resolve through
   the same helpers; nothing recomputes a path from the mutable name.
4. ☑ Live `example.git`/worktree migrated to `example-1.git`/`example-1` on
   vaio; worktree `origin` remote updated; stored `folder` values rewritten.
5. ☑ `admin/test-git-php.php` updated (`TEST_REPO` mimics `<slug>-<id>`; hook
   enforcement steps 16–20 added).
6. ☑ CLAUDE.md and GIT_ARCHITECTURE.md descriptions updated.

---

## 2. Push (Draft Write) Support — ☑ DONE (2026-07-03)

The Smart HTTP gateway now serves `git-receive-pack` (push) to users at
`$g_git_http_write_threshold` (default `MANAGER`).  Pushes advance `HEAD` (the
draft) only; the pinned `git_sha` (on-record version) changes only when
promoted inside Doctis.  See [GIT_ARCHITECTURE.md §On-Record vs Draft](GIT_ARCHITECTURE.md).

**2a. Gateway receive-pack** ☑ — `core/git_http_api.php` authorises reads at
`$g_git_http_read_threshold` and writes at `$g_git_http_write_threshold`;
`http.receivepack=true` is set on every bare repo by
`GitFileStorageBackend::configure_bare_repo()`.

**2b. Force-push rejection** ☑ — a `pre-receive` hook (source of truth:
`admin/tools/git-hooks/pre-receive`) rejects non-fast-forward updates, ref
deletions, and client writes to `refs/doctis/*`.  It is installed/refreshed
automatically on every `ensure_project_repo()` call, and was installed
manually into the migrated `example-1.git`.

**2c. Worktree sync before UI-driven commits** ☑ —
`dwg_git_worktree_sync()` (`core/file_dwg_api.php`) runs
`fetch` + `reset --hard origin/<branch>` at the start of `store()`,
`delete()`, and `file_dwg_git_touch()`, so server-side commits always build on
the latest pushed state.  It also self-heals orphan commits left by a
previously failed push (such a store never created a DB row).

**2d. Approved SHAs pinned as managed refs** ☑ —
`file_dwg_git_pin_approved()` writes `refs/doctis/approved/<dwg_id>/<seq>`
whenever `git_sha` is recorded (initial/replacement upload in
`file_dwg_primary_add()`, and promotion in `file_dwg_primary_sync_head()`).
Approved commits therefore stay reachable regardless of branch history, and
the refs are an out-of-band approval record independent of the Doctis DB.
The hook (2b) blocks clients from touching these refs.

**2e. "Approve draft" surfaced in UI** ☑ — the *Sync to HEAD* button in the
Primary Document panel (`dwg_view_inc.php`, MANAGER-gated, with confirmation
step) is the promotion action.  `dwg_primary_head_warn.php` now explains the
push workflow: draft pushes for write-authorised users, promotion inside
Doctis, force-push rejection.

---

## 3. Lifecycle SHA Recording

**Current state:** The `diskfile` column stores the SHA from the most recent
upload. The `git_sha` column stores the approved/on-record SHA. Neither is yet
fully integrated with the Doctis workflow event log.

### 3a. Register an existing SHA without upload ☐

A mechanism to set `diskfile` (and optionally `git_sha`) to an existing commit
SHA by reference, without performing a file upload. This enables:
- Pointing a Doctis record at a commit made by a developer directly in git.
- Registering the SHA of a specific approved version after direct-push
  development cycles.

Implementation: a form field or SOAP/REST endpoint that accepts a SHA, calls
`git cat-file -t <sha>` to verify it exists in the project repo, then writes it
to the DB.

### 3b. "Use current HEAD" action ☐

A one-click Doctis action that reads `HEAD` from the bare repo
(`git rev-parse HEAD`) and writes it as the current document SHA — useful when
a developer has pushed the final approved state directly and a Doctis user wants
to record it without knowing the SHA.

### 3c. SHA in the workflow history log ☐

At significant lifecycle transitions (submit for review, approve, incorporate),
record the current `git_sha` in the Doctis history log entry. This provides the
ISO 9001 audit record: "at the point the document moved to status X, the git
commit was Y." The history log is the bridge between Doctis's workflow and git's
commit history.

### 3d. Git tagging at approval (nicety) ☐

At the approve step, place a tag in the bare repo:

```
git tag dwg<id>/approved-<revision> <sha>
```

This is a navigational aid for operators browsing the repo directly. It is
**not** essential (the `git_sha` record is sufficient) but adds legibility to
the repo history. Implement after 3c.

---

## 4. Pandoc Pipeline Trigger

**Current state:** Not implemented.

The pandoc pipeline is triggered at the Doctis "incorporate/release" step,
sourced from the git repo at the recorded approved SHA. It is independent of the
git/Doctis integration architecture described here — its only relationship to
git is as a data source.

Outstanding decisions: input format(s), output format(s), destination for
rendered output, timeout handling, error reporting back to Doctis UI.

---

## 5. UX and Security Hardening

### 5a. Surface clone URL in the UI ☑ DONE

`dwg_primary_head_warn.php` shows the remote clone URL
(`http://<user>@<host>/git/<slug>-<id>.git`) with API-token instructions,
gated on `$g_git_http_enabled = ON`, plus push-workflow guidance matched to
the viewer's access level.  Naming is stable (§1), so the URL survives project
renames.  The server-local clone path remains visible only in the deprecated
administrator-diagnostics table.

### 5b. TLS requirement for production ☐

API tokens travel as HTTP Basic credentials. The dev vhost on vaio is plain
HTTP (acceptable for LAN development only). Before any wider deployment:
- Configure HTTPS on the Apache vhost.
- Optionally: redirect HTTP → HTTPS for the `/git/` path.
- Document that token-based git access must not be used over plain HTTP outside
  a private network.

### 5c. Auth logging, rate limiting, lockout integration ☐

The Smart HTTP gateway does not currently log failed authentication attempts or
enforce rate limits. Consider:
- Logging failed token lookups to the Apache error log or a Doctis audit table.
- Honouring the MantisBT `$g_max_failed_login_count` lockout mechanism (or a
  parallel one for API tokens).

### 5d. Buffering vs streaming for large repos ☐

The Phase 1 gateway buffers the full `git-http-backend` response in memory
before sending it to the client (`core/git_http_api.php`). This is fine for
small document repos but will exhaust PHP memory for large repos or when
serving a `git bundle`. Implement streaming (chunked output) as a follow-up
optimisation if large-repo support becomes a requirement.

---

## 6. Repository Import and the Mapping-Layer Refactor — ◐ decision pending

The repository-import requirement ([GIT_IMPORTER.md](GIT_IMPORTER.md)) forced
a clean-sheet review of the git integration:
[GIT_SOLUTION_SPACE.md](GIT_SOLUTION_SPACE.md). Its recommendation (**C1**):

- path-as-data with per-project creation templates (replaces the hardcoded
  `<dwg_id>/<filename>` rule);
- `{repository}` as a first-class entity, projects reference or inherit it
  (replaces project-keyed lazy repos; enables monorepo sub-projects);
- git-only storage for primary documents (retires `$g_dwg_upload_method`
  switching; attachments unchanged);
- infrastructure layer (worktree mechanics, hooks, pinning, gateway)
  explicitly **kept as-is**.

Work packages WP1–WP7 are defined in GIT_SOLUTION_SPACE.md §5. If C1 is
adopted: sections of GIT_ARCHITECTURE.md (naming, lazy creation,
storage-method table) need rewriting as the WPs land, and this file's §3a is
absorbed into WP4.

---

## 7. Open Decisions

| Topic | Status | Notes |
|-------|--------|-------|
| **Repo naming scheme** | ☑ Decided & implemented | Option C (`<slug>-<id>.git`) — see §1 |
| **Signed commits** | Deferred | GPG-signed commits would strengthen audit properties but require key management. Out of scope until the core workflow is complete. |
| **Git LFS** | Deferred | LFS would keep the repo index small for large binaries (PDFs, CAD files > 50 MB). Requires LFS server (or hosted service) and credential handling. No threshold decided. |
| **Branching strategy for drafts** | Deferred | Should in-review revisions live on a branch (`review/dwg-123`) and be merged on approval? Cleaner history; adds workflow complexity. The current single-mainline model suffices until the review workflow is built. |
| **Git bundle / REST export** | Deferred | A REST endpoint returning a `git bundle` of the project repo would serve users who only need an offline full-history export. Does not satisfy the push requirement and would not replace the Smart HTTP gateway. |
| **SSH access (single shared account)** | Deferred | An SSH option using a single `git` system account with `AuthorizedKeysCommand` mapping SSH keys to Doctis users. Secondary to Option 1 (Smart HTTP). Useful for power users or automation. |
