# Doctis Git Repository Importer — Plan

Status: **IMPLEMENTED** (2026-07-12) — `admin/import-git-repo.php`, wrapper
`admin/tools/doctis-git-import.sh`; both driving use cases imported and
verified on vaio (see [GIT_TODO.md §6](GIT_TODO.md) WP7 for the
implementation record).  This document is retained as the requirements and
design spec.  Implementation deviations from the plan: frontmatter `doc_id`
maps to `documents.number` (Doctis manages `reference` as the on-record
SHA); `status: Active` maps to accepted (180); the register primitive is
`file_dwg_primary_register()` per the C1 model rather than §4's P-numbers.

> **2026-07-12 — C1 foundation implemented; this plan's §3–§4 are superseded.**
> [GIT_SOLUTION_SPACE.md](GIT_SOLUTION_SPACE.md) replaced D1 (`git_path` as a
> nullable override) and D2 (per-project config delegation) with
> *path-as-data universally* and a first-class `{repository}` entity — and
> that foundation is now **built and tested** (WP1–WP6; see
> [GIT_TODO.md §6](GIT_TODO.md)).  What remains is **WP7 — the importer
> itself**, whose two primitives already exist:
> `repository_adopt()` (core/repository_api.php) performs the Phase A clone
> (remote-strip, hook install, default-branch record), and
> `file_dwg_primary_register()` (core/file_dwg_api.php) performs each Phase B
> registration.  Sub-projects share the parent repository natively (hierarchy
> inheritance — no delegation config needed).  The rest of this document —
> requirements, use cases, D3–D7, the `.doctis` manifest, metadata gleaning
> (§6), pipeline shape (§5), idempotency (§9) — stands and is the WP7 spec.

This document plans a new capability: importing an **existing git repository**
into Doctis, so that its history becomes the project's document store and its
qualifying files become registered Doctis documents. It builds on the
implemented baseline in [GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md) and directly
addresses [GIT_TODO.md §3a](GIT_TODO.md) ("Register an existing SHA without
upload") — the importer is essentially the batch form of that mechanism, plus
repository adoption.

---

## 1. Problem Statement

Two separable concerns:

- **A — Repository adoption.** Clone an existing repository (with full
  history) into the Doctis storage location (`$g_git_storage_root`) so it
  becomes the project's bare repo, indistinguishable from one Doctis created
  itself (pre-receive hook, `http.receivepack`, ownership, naming).

- **B — Project creation and document registration.** If no Doctis project
  exists for the repository, create it (and, where applicable, sub-projects);
  then walk the repository content and register qualifying files as Doctis
  `dwg` records, each with a `{dwg_primary_file}` row pointing at an existing
  commit SHA — **no new commits are made during registration**.

### Driving use cases

| # | Repository | Shape | Import rule |
|---|-----------|-------|-------------|
| 1 | `~/Robo/HCRQMS` | QMS content repo; documents are `*.md` with YAML frontmatter | Recursively register `*.md` under an explicit directory allowlist: `content/`, `system/`. Other trees (`engine/`, `sandbox/`, `_build/`) are not documents. |
| 2 | `~/Robo/HCR-Hardware-Designs` | Hardware monorepo; one subdirectory per design (`HCR-570/`, `HCR-579/`, …) containing PDFs, images, CAD sources | One **parent** project for the repo; one **sub-project per qualifying subdirectory**; register that subdirectory's documents into the sub-project using the same recursive mechanism as use case 1 (with per-repo file-pattern configuration — here `*.pdf` etc., not `*.md`). |

---

## 2. Fit with the Governing Principle

From GIT_ARCHITECTURE.md: *direction of control is always Doctis → git; no
hooks or webhooks push state from git into Doctis.*

An import is a **deliberate, operator-initiated action** — a human runs a tool
that reads the repository once and writes Doctis records. It is not a sync
daemon, not a post-receive hook, and not continuous reconciliation. Re-running
the importer is likewise an explicit operator action (see §9 Idempotency).
This keeps the importer inside the architectural boundary.

---

## 3. Design Decisions

### D1 — Repository layout: native paths, not `<dwg_id>/<filename>`

The implemented GIT backend stores files at `<dwg_id>/<filename>`
(`GitFileStorageBackend::repo_rel_path()`). An imported repository has an
arbitrary, meaningful layout (`content/engineering/guidance/…`, `HCR-579/…`)
that existing users, build pipelines (HCRQMS is a publishing source tree), and
history depend on.

**Options considered:**

| Option | Description | Verdict |
|--------|-------------|---------|
| Restructure on import | `git mv` every registered file into `<dwg_id>/` | ✗ Breaks the repo for its existing consumers (HCRQMS site build, CAD project references); history needs `--follow`; the repo stops being *theirs* |
| Reference-only | Leave the repo where it is; store absolute paths | ✗ Fails requirement A; repo not under Doctis management, hooks, or gateway |
| **Native paths (chosen)** | Keep the imported layout; record each document's repo-relative path in the DB | ✓ Repo remains fully usable by its current consumers; history is untouched |

**Implementation:** add a nullable path override to `{dwg_primary_file}`:

```sql
`git_path` varchar(1024) NOT NULL DEFAULT ''
```

(edited into the base `CREATE TABLE` in `admin/schema.php` per the standard
schema workflow). Semantics:

- `git_path = ''` (default) — backend behaves exactly as today:
  `repo_rel_path()` returns `<dwg_id>/<filename>`. **Zero change for every
  existing record and for Doctis-native uploads.**
- `git_path` non-empty — `store()`, `retrieve()`, `delete()` use it verbatim
  as the repo-relative path. `filename` continues to hold the basename for
  UI display and Content-Disposition.

Only `repo_rel_path()` and its three call sites in
`GitFileStorageBackend.class.php` change (the metadata array already carries
the DB row / metadata into all three operations). A Doctis-driven *replace*
of an imported document's primary file writes a new commit **at its native
path**, so the external repo view stays coherent.

### D2 — One repo, many projects: storage delegation for sub-projects

Use case 2 conflicts with the implemented invariant *"each project maps to
exactly one bare repository"*: the parent project owns the cloned monorepo,
but documents live in sub-projects, and `GitFileStorageBackend` resolves the
repo from the **document's own** `project_id`.

> **Clarification — what a sub-project implies today.** A Doctis sub-project
> is a full `{project}` row linked via `{project_hierarchy}`; the git layer is
> unaware of the hierarchy. As currently implemented, the first document file
> stored in a sub-project triggers `ensure_project_repo()` and lazily creates
> a **separate** bare repo for it (`hcr-570-9.git`, …). Sub-projects sharing
> the parent's repository is therefore *not* current behaviour — it is what
> this decision adds. With delegation in place, sub-projects have **no clone
> URL of their own**; the parent repo is the unit of clone/push.

**Options considered:**

| Option | Description | Verdict |
|--------|-------------|---------|
| Split the monorepo | `git filter-repo --subdirectory-filter` per subdirectory → one bare repo per sub-project | ✗ Rewrites SHAs, severs the repo from its origin; the user keeps working in the monorepo, so Doctis would hold orphaned forks |
| Categories instead of sub-projects | Register everything in the parent project, one category per subdirectory | ✗ Loses per-sub-project access control, assignment and filtering; user explicitly wants sub-projects |
| **Storage delegation (chosen)** | A sub-project may delegate git storage to an ancestor project's repo | ✓ Monorepo stays intact; sub-projects are fully real Doctis projects |

**Implementation:** a per-project config option (MantisBT `config_api`, scoped
`ALL_USERS` / project — **no schema change**):

```php
config_set( 'git_storage_project_id', <parent_id>, ALL_USERS, <subproject_id> );
```

A new helper in `core/file_dwg_api.php`:

```php
dwg_project_storage_project_id( int $p_project_id ): int
```

returns the delegation target if set (followed transitively, cycle-guarded),
else `$p_project_id`. Call it at the top of `dwg_project_bare_repo_path()` /
`dwg_project_worktree_path()` resolution inside the backend and in
`ensure_project_repo()` (which must **not** lazily create a repo for a
delegating project). The Smart HTTP gateway needs no change: users clone the
**parent** repo — the natural unit for a monorepo. Delegation is explicit
(set by the importer), so ordinary new sub-projects keep today's behaviour of
getting their own repo.

### D3 — Repository authority after import

After import the Doctis bare repo is a **clone**, and the question is which
copy is authoritative.

**Recommendation: Doctis becomes the authoritative home.** Contributors
re-point `origin` to the Smart HTTP URL
(`http://<user>@<host>/git/<slug>-<id>.git`, API-token auth) and keep
working — pushes advance the Draft exactly as designed
(GIT_TODO §2 is done). The pre-receive hook (no force-push, no ref deletion)
applies from the moment of adoption.

If the old remote (e.g. GitHub) must be retained, it becomes a downstream
mirror (`git push --mirror` from a cron on the server, or manual). What the
importer must **not** promise is bidirectional sync — that re-introduces the
git → Doctis control direction the architecture forbids. Divergence between
Doctis and a still-active old origin is an operator problem; the import tool
warns about configured remotes and strips them from the adopted bare repo
(see §5, step A4).

### D4 — Delivery vehicle: server-side PHP CLI, core APIs directly

**Options considered:**

| Option | Verdict |
|--------|---------|
| Standalone Python script over SOAP | ✗ for the MVP: the clone into `/var/git/doctis/` and hook installation are filesystem operations on the server that no SOAP/REST endpoint performs (nor should — "adopt this server path as a repo" is not a safe remote API). SOAP also lacks a register-without-upload endpoint (`mc_dwg_primary_upload` creates a *new commit*), and lacks `mc_project_hierarchy_add`. A Python/SOAP importer would still need a server-side companion — two halves, two runtimes. |
| Standalone Python script over REST | ✗ No dwg/document REST endpoints exist at all (known gap, CLAUDE.md §Known Trade-offs #3). Would gate the importer on building a REST layer first. |
| **PHP CLI using core APIs (chosen)** | ✓ Single tool, runs where the filesystem work must happen anyway, reuses `project_create()`, `project_hierarchy_add()`, `DwgAddCommand`, `file_dwg_git_pin_approved()`, and the repo-naming helpers directly — no new remote API surface required for the MVP. |

**Location and invocation** (mirrors `admin/test-git-php.php` conventions):

```
admin/import-git-repo.php          the importer (PHP CLI, bootstraps core.php)
admin/tools/doctis-git-import.sh   thin wrapper: sudo -u www-data php … with args
```

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
    --source /path/to/repo --user manager [--dry-run] [--update]"
```

Runs as `www-data` so the bare repo, worktree and hook land with correct
ownership without a chown pass.

**Phase 2 (optional, later):** expose the registration half as SOAP
`mc_dwg_primary_register( dwg_id, git_path, sha )` — this *is* GIT_TODO §3a
and is useful independently of bulk import. A remote Python bulk-registerer
becomes possible then; repository adoption stays server-side forever.

### D5 — Import manifest: `.doctis` file, not `.gitkeep`

The importer must know, per repository: which directories are document trees,
which file patterns are documents, and whether subdirectories become
sub-projects. Two candidate markers were suggested; recommendation:

- **`.gitkeep` — rejected as the signal.** It is an informal convention whose
  only meaning is "keep this empty directory"; its presence in a *populated*
  design directory is accidental, and it can carry no configuration.
- **`.doctis` — chosen.** A committed INI-style manifest. At repo root it
  drives the whole import; optionally present in a subdirectory to override
  or opt in/out per sub-project. Being committed, the import policy is
  versioned with the content it describes.

Root manifest, use case 1 (`HCRQMS/.doctis`):

```ini
[project]
name = HCRQMS
description = HC Robotics Quality Management System

[import]
directories = content, system      ; allowlist, recursive
patterns = *.md                    ; document file globs
subprojects = none

[metadata]
frontmatter = yes                  ; parse YAML frontmatter (see §6)
category_from = directory          ; relative dir path → category name (D7),
                                   ; e.g. content/engineering/guidance → "engineering/guidance"
```

Root manifest, use case 2 (`HCR-Hardware-Designs/.doctis`):

```ini
[project]
name = HCR Hardware Designs

[import]
subprojects = subdirs              ; each qualifying top-level dir → sub-project
subdir_marker = .doctis            ; a dir qualifies if it contains this file
                                   ; (fallback if none found: every top-level dir
                                   ;  that is not dot-prefixed, with confirmation)
patterns = *.pdf                   ; registered document types
; loose files at repo root are never registered

[metadata]
frontmatter = no
filename_parse = yes               ; see §6 — HCR-570C-…-Rev-D pattern
```

Per-subdirectory `HCR-570/.doctis` may override `patterns`, set the
sub-project `name`/`description`, or set `import = no`.

CLI flags mirror every manifest key, so a repo **without** a `.doctis` file
can still be imported non-interactively (`--directories`, `--patterns`,
`--subprojects`, …); when both exist, CLI flags win. `--dry-run` prints the
full would-be result (projects, documents, metadata) without writing anything.

### D6 — Lifecycle state of imported documents

Each registered document points at the import-time `HEAD` commit:

- `{dwg_primary_file}.diskfile` = HEAD SHA, `git_branch` = current branch,
  `git_path` = repo-relative path, `filename` = basename,
  `filesize`/`file_type` from `git cat-file` / extension map,
  `date_added` = the file's last-commit timestamp.
- `git_sha` (On-Record) = HEAD SHA as well, **pinned** via
  `file_dwg_git_pin_approved()` → `refs/doctis/approved/<dwg_id>/1`,
  *unless* the document's own frontmatter says it is a draft
  (`status: Draft` → leave `git_sha` empty; the record starts as Draft-only
  and is promoted later via the existing *Sync to HEAD* action).
- `dwg.status`: default `110:pending`; refined from frontmatter where
  available (see mapping in §6).

Rationale: imported files are, in the common case, the current official
versions of real documents — importing them as unrecorded drafts would make
every document immediately show the "HEAD has advanced" warning state.

### D7 — Representing multi-depth directories (Doctis has no folders)

Doctis has no folder concept, but imported repositories do — HCRQMS nests
2–3 levels (`content/engineering/guidance/…`). The **storage** layer is not
the problem: `git_path` (D1) preserves the full repo-relative path, so the
repo tree stays intact and retrieve/replace work at native depth. The
question is only how the directory hierarchy is surfaced in Doctis's flat
UI/filter model.

**Options considered:**

| Option | Mechanism | Verdict |
|--------|-----------|---------|
| **Category = relative directory path (chosen)** | Auto-create one category per distinct directory; the category *name* holds the path relative to the allowlisted root, e.g. `engineering/guidance`, `it`, `policies` (category names are free-text varchar — slashes are legal) | ✓ Full fidelity at any depth with zero schema or UI change; filterable and groupable with existing category machinery |
| First segment → category, second → `discipline` | Two existing filter axes | ✗ Caps at depth 2; overloads `discipline` |
| Tags per path segment | `content`, `engineering`, `guidance` as dwg tags | ✗ Loses ordering (which parent owns `guidance`?); tag sprawl |
| Nested sub-projects mirroring directories | Hierarchy + D2 delegation at every level | ✗ Project explosion (~30+ for HCRQMS); projects carry access control, workflows, versions — far too heavy for "folder" |
| Derived folder column from `git_path` | Expose `dirname(git_path)` as a dwg-list column + filter (`columns_dwg_api.php` / `filter_dwg_api.php`) | ◐ The honest representation, but new filter-pipeline code; deferred as a later enhancement (§11) |

Consequences of the chosen option:

- The category list is a flat *list of paths*, not a collapsible tree —
  acceptable at HCRQMS scale (11 departments × a few kinds ≈ 20–30
  categories per project).
- Use case 2 needs no depth handling at all: the sub-project *is* the top
  level, and files directly inside `HCR-570/` get the project default
  category; any deeper structure follows the same path-as-category rule
  relative to the subdirectory.
- The `.doctis` manifest key `category_from = directory` (D5) means
  "relative directory path", not "first segment".

---

## 4. Prerequisite Code Changes (before the importer itself)

| # | Change | Files | Notes |
|---|--------|-------|-------|
| P1 | `git_path` column | `admin/schema.php` (`dwg_primary_file` base definition) + DB rebuild | See D1 |
| P2 | Honour `git_path` in the backend | `core/classes/GitFileStorageBackend.class.php` (`repo_rel_path()` + its 3 call sites), `core/file_dwg_api.php` (pass/persist `git_path` through `file_dwg_primary_add()` metadata and the row array) | Empty ⇒ legacy behaviour |
| P3 | Storage delegation helper | `core/file_dwg_api.php`: `dwg_project_storage_project_id()`; resolve through it in the backend path helpers and `ensure_project_repo()` | See D2; per-project config key `git_storage_project_id` |
| P4 | Register-without-upload primitive | `core/file_dwg_api.php`: `file_dwg_primary_register( $p_dwg_id, $p_user_id, $p_git_path, $p_sha, $p_branch, $p_description )` — verifies the blob exists (`git cat-file -e <sha>:<path>` against the bare repo), inserts/updates the `{dwg_primary_file}` row, pins the approved ref | This is GIT_TODO §3a; the importer is its first consumer. SOAP wrapper deferred (D4 Phase 2) |

P1–P4 are independently testable and individually small; land them first as
their own commits, extend `admin/test-git-php.php` for each (see §10).

---

## 5. Importer Pipeline

`admin/import-git-repo.php`, sequential phases; every phase logs to stdout and
aborts cleanly on error (nothing is half-adopted — see failure handling below).

### Phase A — Repository adoption

*(Updated for the implemented C1 model — the heavy lifting is
`repository_create()` + `repository_adopt()` in `core/repository_api.php`.)*

| Step | Action | Detail |
|------|--------|--------|
| A1 | Pre-flight | Source is a git repo with ≥1 commit; working tree clean (warn if dirty — uncommitted changes will not be imported); `$g_git_storage_root` writable; identify default branch |
| A2 | Read manifest | Root `.doctis` merged with CLI flags; resolve project name; **fail early** if a project of that name exists without `--update` |
| A3 | Create project(s) | `project_create()` for the parent; for `subprojects = subdirs`: enumerate qualifying subdirectories, `project_create()` + `project_hierarchy_add()` each. Sub-projects inherit the parent's repository natively via the hierarchy walk — no per-sub-project configuration needed |
| A4 | Create repository entity | `repository_create( name, parent_project_id, adopted_from = source )` — inserts the `{repository}` row and owner link; basename is `<slug>-r<id>` |
| A5 | Adopt | `repository_adopt( repo_id, source )` — under the storage lock: `git clone --bare` into the canonical path, strip inherited remotes (D3), install the pre-receive hook, set `http.receivepack`, record the default branch on the entity, clone the server worktree |

### Phase B — Document discovery and registration

| Step | Action | Detail |
|------|--------|--------|
| B1 | Discover | Walk the allowlisted directories (use case 1) or each qualifying subdirectory (use case 2) at `HEAD`, matching `patterns`; use `git ls-tree -r HEAD` rather than the filesystem so only *committed* content is registered |
| B2 | Extract metadata | Per file: frontmatter → git history → path/filename → (optional) PDF info; see §6 |
| B3 | Create dwg record | Via `DwgAddCommand` (parity with UI/SOAP validation): project (or sub-project) id, title, summary, category, classification, discipline, status |
| B4 | Register primary file | `file_dwg_primary_register()` (P4) with `git_path`, HEAD SHA, branch; pin approved ref per D6 |
| B5 | Report | Table of created projects / documents / skipped files (with reasons) / metadata sources used; exit non-zero if any file failed |

**Failure handling:** Phase A is all-or-nothing — on any A-step failure,
remove the partially created bare repo/worktree and (if created this run) the
project rows, then exit. Phase B failures are per-file: log, skip, continue,
report at the end (mirrors `doctis-soap-test.sh`'s non-fatal style); a repeat
run with `--update` picks up the stragglers (§9).

---

## 6. Metadata Gleaning

Sources in priority order (higher wins per field):

**1. Document-embedded metadata** — YAML frontmatter (HCRQMS `*.md` files
carry `doc_id`, `title`, `revision`, `status`, `owner`, `approver`,
`effective_date`, `review_period`, `classification`):

| Frontmatter key | Doctis field | Notes |
|-----------------|--------------|-------|
| `title` | `documents.title` / `dwg.summary` | |
| `doc_id` | `documents.reference` | e.g. `WI-IT-001`; also the §9 idempotency key |
| `revision` | `documents.revision` | |
| `classification` | `dwg.classification` / `documents.classification` | e.g. `Internal` |
| `status` | `dwg.status` | Map: `Draft`→110 pending (no On-Record pin, D6); `In Review`→160 review; `Approved`/`Effective`→180 accepted; `Released`→190 incorporated; `Superseded`/`Obsolete`→195 archived; unknown→110 + warning |
| `owner` / `approver` | `dwg.handler_id` | Resolved only if it matches a Doctis username/realname; else noted in the import report |
| `effective_date` | `documents.release_date` | Parse to Unix int (`INT UNSIGNED` convention) |
| `review_period` | `dwg.due_date` | `effective_date + period` when both parse |

**2. Git history** (per file, from the adopted bare repo):

| Git source | Command | Doctis field |
|-----------|---------|--------------|
| First commit adding the file | `git log --follow --diff-filter=A --format=%at -1 -- <path>` | `dwg.date_submitted`; `documents.release_date` fallback |
| Last commit touching the file | `git log --format=%at -1 -- <path>` | `dwg.last_updated`; `documents.revision_date`; `{dwg_primary_file}.date_added` |
| Last commit author | `git log --format='%an %ae' -1 -- <path>` | Match email against Doctis users → `dwg.creator_id` (fallback: importing user, with the git author recorded in `documents.author`) |
| Commit count for the file | `git rev-list --count HEAD -- <path>` | Import-note only ("N revisions in git history") — too weak to synthesise a revision letter |
| Tags reaching the last commit | `git tag --contains <sha>` | Import-note only; possible `documents.edition` hint if the repo uses release tags |
| HEAD SHA / branch | `git rev-parse HEAD`, `symbolic-ref` | `diskfile`, `git_sha`, `git_branch` |
| `remote.origin.url` (pre-strip) | `git config` | `documents.link_url` — pointer back to the repo's previous home |

**3. Path and filename conventions:**

| Source | Doctis field | Example |
|--------|--------------|---------|
| Directory path relative to the allowlisted root (D7) | `dwg.category_id` (category auto-created, name = relative path) | `content/engineering/guidance/foo.md` → category *engineering/guidance* |
| Subdirectory name (use case 2) | Sub-project name; `documents.number` | `HCR-570` |
| Filename pattern `<number><rev-letter>-<title…>[-Rev-<r>][_(qualifier)]` | `documents.number`, `documents.revision`, `documents.title`, draft flag | `HCR-570C-Gimbal-IMU-Rev-D_(Uncontrolled-Draft-V2).png` → number `HCR-570`, revision `C`/`D`, title *Gimbal IMU*; `(Uncontrolled-Draft…)` ⇒ treat as Draft (D6) |
| Filename (always) | `{dwg_primary_file}.filename`; title of last resort | |

**4. Embedded file metadata (optional, later):** `pdfinfo` Title/Author/
CreationDate for PDFs where 1–3 produced nothing. Nice-to-have; not MVP.

All gleaned values and their source are listed in the `--dry-run` output so
the operator can verify the mapping before the real run.

---

## 7. Use Case Walkthrough — HCRQMS

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
    --source /path/on/vaio/HCRQMS --user manager --dry-run"
```

1. Manifest: project *HCRQMS*, `directories = content, system`,
   `patterns = *.md`, `subprojects = none`.
2. Project `HCRQMS` created (say id 7) → bare repo cloned to
   `/var/git/doctis/hcrqms-7.git`, hook installed, remotes stripped.
3. `git ls-tree -r HEAD` filtered to `content/**/*.md`, `system/**/*.md`
   → 33 documents (current count).
4. Each: frontmatter parsed (`doc_id` → reference, `revision`, `status`
   mapped, `classification`), categories auto-created from the relative
   directory path per D7 (`engineering/guidance`, `it`, `policies`, …),
   dwg + primary-file rows written with
   `git_path = content/it/Task-Instruction-….md` etc., On-Record pinned
   unless `status: Draft`.
5. Report: 33 registered (N on-record, M draft), any unresolved
   `owner:` names listed.

Repo remains a working QMS/publishing tree; contributors re-point origin to
`http://<user>@vaio/git/hcrqms-7.git` and continue as before — pushes advance
Drafts, promotion happens in Doctis.

## 8. Use Case Walkthrough — HCR-Hardware-Designs

1. Manifest: `subprojects = subdirs`, marker `.doctis` per design directory
   (commit one into each of `HCR-570 … HCR-585`; directories without it —
   and root-level loose files like `arducopter.apj`, `stm32*_pinmap*.h` —
   are skipped), `patterns = *.pdf` (root default; a subdir manifest may add
   `*.png, *.step`).
2. Parent project *HCR Hardware Designs* (say id 8) → repo adopted as
   `/var/git/doctis/hcr-hardware-designs-8.git`.
3. Sub-projects `HCR-570`, `HCR-571`, … created via `project_create()` +
   `project_hierarchy_add()`, each with `git_storage_project_id = 8` (D2).
4. Per subdirectory, matching files registered into the sub-project;
   filename parsing (§6.3) fills number/revision/title; `(Uncontrolled-Draft…)`
   qualifiers import as Draft.
5. Clone URL for everyone: the **parent** repo. Sub-projects have no repo of
   their own by design.

---

## 9. Idempotency and Re-import

`--update` mode makes the importer safely re-runnable against a repository
that has grown since the first import (still operator-initiated — see §2):

- **Match key**, per file, in order: existing `{dwg_primary_file}.git_path`
  in the target (sub-)project → frontmatter `doc_id` vs `documents.reference`
  → no match ⇒ *new* document.
- **New file** → register as in Phase B.
- **Known file, HEAD advanced** → no DB change (that is the normal Draft
  state; `dwg_primary_head_warn.php` already surfaces it). Listed in the
  report.
- **Known file, deleted at HEAD** → never auto-delete; report as
  "missing at HEAD" for operator action.
- **Without `--update`**: refuse to touch an existing project (fail early,
  A2).

---

## 10. Testing Plan

1. **Unit-ish (extend `admin/test-git-php.php`):**
   - P2: store/retrieve/delete round-trip with a non-empty `git_path`
     (native layout) alongside the existing `<dwg_id>/` cases.
   - P3: delegation — repo resolution for a project with
     `git_storage_project_id` set; `ensure_project_repo()` must not create a
     repo for a delegating project.
   - P4: `file_dwg_primary_register()` happy path + rejection of a SHA/path
     not present in the repo.
2. **Importer dry-run fixtures:** two tiny throwaway repos mimicking each use
   case shape (created and torn down by the test, like `test-git-php.php`
   does); assert the dry-run report (project set, document count, metadata
   fields).
3. **Live rehearsal on vaio:** full clean-slate reset (per CLAUDE.md), real
   import of HCRQMS, then: spot-check `dwg_view.php` records; download a
   primary file (exercises `retrieve()` via `git_path`); replace one via the
   UI (native-path commit lands correctly); `git clone` the adopted repo
   through the Smart HTTP gateway; run `doctis-soap-test.sh` against an
   imported document id.
4. **Re-run semantics:** commit a new `.md` to the source, re-import with
   `--update`, verify exactly one new document and zero duplicates.

---

## 11. Deferred / Open Questions

| Topic | Notes |
|-------|-------|
| SOAP `mc_dwg_primary_register` | Phase 2 of D4; also closes GIT_TODO §3a for single documents. Enables a remote Python bulk-registerer for repos already adopted. |
| REST endpoints | Blocked on the wider "no dwg REST" gap; the importer must not wait for it. |
| Continuous mirror from an old origin | Explicitly out of scope (D3) — one-way `git push --mirror` cron is an operator concern, not a Doctis feature. |
| PDF metadata extraction | §6.4 — optional enrichment pass. |
| Folder column derived from `git_path` | D7's deferred option: expose `dirname(git_path)` as a dwg-list column and filter axis (`columns_dwg_api.php` / `filter_dwg_api.php`). Revisit if path-as-category proves insufficient for navigation. |
| Frontmatter as ongoing source of truth | Import reads frontmatter once. Whether later pushes that change frontmatter should ever update Doctis fields is a git → Doctis flow and therefore rejected for now; revisit only as an operator-run `--update` enrichment. |
| Very large repos / LFS sources | `git clone --bare` of an LFS repo does not fetch LFS objects; detect `.gitattributes` LFS filters in A1 and abort with guidance until the LFS decision (GIT_TODO §6) is made. |
| Non-UTF-8 / exotic filenames | `git ls-tree -z` handling and a `varchar(1024)` path budget; files exceeding it are skipped with a report entry. |
