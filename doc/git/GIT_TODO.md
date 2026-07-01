# Doctis Git Storage — Outstanding Work

This document tracks all deferred features, design decisions to be made, and
development tasks for the git storage system. See [GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md)
for the implemented baseline.

Status: ☐ todo · ◐ in progress · ⊘ explicitly deferred

---

## 1. Repository Naming Stability [prerequisite for durable clone URLs]

**Current state:** The repo name is derived from the project name slug
(`preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($name)))`). The slug
expression appears in **six** places in the codebase with no shared helper.

### Latent bugs in the current name-based scheme

**1a. Project rename causes split-brain (highest severity)**

`project_update()` allows changing the name of an existing project. Because the
slug is recomputed from the *current* name on every `store()` and `delete()`,
a rename silently relocates where the backend looks:

| Operation after rename | Path source | Effect |
|------------------------|-------------|--------|
| `retrieve()` of an old file | Stored `folder` column | ✅ still works — frozen at write time |
| `store()` of a new file | Recomputed slug | ❌ creates a new repo under the new slug |
| `delete()` of an old file | Recomputed slug | ❌ targets the wrong worktree |

The project ends up fragmented across two repos with no record linking them.
This is a live data-integrity bug in the current design.

**1b. Slug collisions**

The project table enforces `UNIQUE KEY` on the *exact* project name.
Slugification is lossy, so distinct names can produce the same slug:

| Name (all DB-unique) | Slug |
|----------------------|------|
| `Phase 2` | `phase-2` |
| `Phase-2` | `phase-2` |
| `phase  2` (double space) | `phase-2` |

Two projects sharing a slug would share a bare repo, mixing unrelated documents
and crossing access boundaries.

**1c. Degenerate / empty slugs**

A project name composed entirely of non-ASCII or punctuation characters (e.g.
`"中文项目"`, `"***"`) slugifies to `"-"` or `""`. An empty slug creates a
repo named `.git` or a worktree path equal to the root itself. Doctis does not
currently restrict project names to ASCII.

**1d. Slug expression duplicated six times**

| Location |
|----------|
| `core/classes/GitFileStorageBackend.class.php:116` (canonical `project_slug()`) |
| `core/file_dwg_api.php:1796` (bare-repo path) |
| `core/file_dwg_api.php:1834` (bare-repo path) |
| `core/file_dwg_api.php:1928` (bare-repo path) |
| `core/file_dwg_api.php:1981` (worktree path) |
| `dwg_primary_head_warn.php:56` (user-visible URL display) |

Any change to the slug algorithm must be made in all six places.

### Recommended fix: Option C — `<slug>-<id>.git`

Name repos `<slug>-<id>.git` (e.g. `example-1.git`, `bridge-refit-2027-7.git`):

- **Collision-free** — the immutable ID suffix guarantees uniqueness regardless
  of how lossy the slug is.
- **Human-readable** — the slug prefix is self-documenting for operators
  inspecting `/var/git/doctis/`.
- **Rename-tolerant** — the ID disambiguates; the slug is cosmetic. The repo
  location can be persisted (or derived from ID alone) so rename never causes
  split-brain.

**Option A (pure ID):** `1.git` — immutable and collision-free but not readable.
**Option B (hardened slug):** `example.git` retained, with a stored slug column,
collision checks, and atomic rename handling — most readable, most code, most
fragility.

Option C is the best trade-off.

### Implementation tasks for Option C

1. ☐ Replace all six slug expressions with one shared helper, e.g.
   `dwg_project_repo_basename($p_project_id)` in `core/file_dwg_api.php` or
   the backend class.
2. ☐ Persist the repo name (or at minimum, the bare repo path) at the project
   level — either rely on the existing per-file `folder` column for *all*
   operations, or add a `git_repo_name` column to the `{project}` table
   (schema edit in `admin/schema.php`, full DB rebuild).
3. ☐ Ensure `store()` and `delete()` resolve the repo location the same way
   `retrieve()` does — from a persisted value, never recomputed from the
   mutable name.
4. ☐ Migrate the live `example.git` on vaio and its worktree to the new scheme,
   and rewrite stored `folder` values.
5. ☐ Update `admin/test-git-php.php`, which hardcodes a `TEST_SLUG`.
6. ☐ Update CLAUDE.md description of the repo layout.

> **This is a prerequisite for exposing remote clone URLs in the UI.** Once a
> URL is user-visible or bookmarked, a project rename that silently breaks it
> is a significant UX regression.

---

## 2. Push (Draft Write) Support

**Current state:** The Smart HTTP gateway (Phase 1) serves `git-upload-pack`
(clone/fetch). `git-receive-pack` (push) is rejected with 403.

Advanced users should be able to `git push` draft document updates directly.
This is safe under the On-Record/Draft model — pushes advance `HEAD` (the
draft) but do not change the pinned `git_sha` (the on-record version) until
explicitly promoted inside Doctis. See [GIT_ARCHITECTURE.md §On-Record vs Draft](GIT_ARCHITECTURE.md).

### Required changes

**2a. Gateway: permit receive-pack for write-authorised users** ☐

Remove the `git-receive-pack` block in `core/git_http_api.php` and allow it
for users at `$g_git_http_write_threshold` (default: `MANAGER`).
Enable `http.receivepack` per repo (`git config -C <worktree> http.receivepack true`
or set in the gitconfig).

**2b. Reject force-push and non-fast-forward pushes** ☐

Force-push can orphan an approved commit and expose it to garbage collection.
Implement server-side rejection of non-fast-forward pushes on the served
branch. Options:
- A `pre-receive` hook in each bare repo that rejects `--force` pushes.
- An `update` hook checking that the old SHA is reachable from the new one.

Hooks must be installed in every bare repo (on creation and for existing repos).

**2c. Worktree sync before UI-driven commits** ☐

`GitFileStorageBackend::store()` currently does `addFile → commit → push` with
no prior `fetch`/`pull`
(`core/classes/GitFileStorageBackend.class.php:250–270`). Once external users
push, the server worktree falls behind `origin`, and the next UI-driven upload
fails with a non-fast-forward push rejection.

**Fix:** add a `fetch` + fast-forward/rebase to `origin/HEAD` at the start of
`store()`, before staging the new file. This must be done before push support
is enabled.

**2d. Pin approved SHAs as managed git refs** ☐

`retrieve()` of an On-Record version depends on that commit remaining reachable
in the bare repo. A force-push (if not blocked by 2b) or history rewrite could
orphan an approved commit and expose it to GC.

At the moment `git_sha` is set (i.e. when a draft is promoted to on-record),
write a permanent git ref:

```
refs/doctis/approved/<dwg_id>/<sequence>  →  <sha>
```

This makes the commit permanently reachable regardless of branch history, and
provides an out-of-band record that does not depend on the Doctis DB.

**2e. Surface the "approve draft" action in the UI** ☐

`file_dwg_primary_sync_head()` (`core/file_dwg_api.php:1782`) is the existing
"approve current draft" primitive. Once push is enabled, this becomes the
natural promotion step: advanced users push drafts freely, and a reviewer
promotes a chosen HEAD to approved inside Doctis. The current UI exposure of
this action should be reviewed and surfaced appropriately.

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

### 5a. Surface clone URL in the UI ☐

`dwg_primary_head_warn.php` currently shows the *server-local* clone path
(`git clone '/var/git/doctis/<slug>.git' /tmp/<slug>`), which is only useful to
an operator logged into the server. Replace with the real remote URL once Phase
1 naming is stable:

```
git clone http://<user>:<token>@<host>/git/<slug>.git
```

Show this alongside instructions to create an API token at *My Account → API
Tokens*. Gate the display on `$g_git_http_enabled = ON`.

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

## 6. Open Decisions

| Topic | Status | Notes |
|-------|--------|-------|
| **Repo naming scheme** | Decision pending | Option C (`<slug>-<id>.git`) recommended — see §1 |
| **Signed commits** | Deferred | GPG-signed commits would strengthen audit properties but require key management. Out of scope until the core workflow is complete. |
| **Git LFS** | Deferred | LFS would keep the repo index small for large binaries (PDFs, CAD files > 50 MB). Requires LFS server (or hosted service) and credential handling. No threshold decided. |
| **Branching strategy for drafts** | Deferred | Should in-review revisions live on a branch (`review/dwg-123`) and be merged on approval? Cleaner history; adds workflow complexity. The current single-mainline model suffices until the review workflow is built. |
| **Git bundle / REST export** | Deferred | A REST endpoint returning a `git bundle` of the project repo would serve users who only need an offline full-history export. Does not satisfy the push requirement and would not replace the Smart HTTP gateway. |
| **SSH access (single shared account)** | Deferred | An SSH option using a single `git` system account with `AuthorizedKeysCommand` mapping SSH keys to Doctis users. Secondary to Option 1 (Smart HTTP). Useful for power users or automation. |
