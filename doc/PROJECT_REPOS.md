# Concept Report — Per-Project Git Repositories

**Status:** Concept / design analysis only. No code changes are proposed by this
document itself; it exists to inform a decision.
**Date:** 2026-06-30
**Scope:** The `GIT` file-storage backend (`$g_dwg_upload_method = GIT`), which
gives each Doctis project its own bare git repository for document storage. Two
parts:

- **Part 1 — Repository naming & contents:** how repos are named (§1–§7) and what
  they should contain (§8 — scoping git to the registered document, excluding
  attachments).
- **Part 2 — Remote repository access:** how a user might `git clone` a project
  repo onto their own workstation using Doctis credentials, without a Linux
  account.

# Part 1 — Repository Naming & Contents

---

## 1. Important correction to the premise

The task brief assumed:

> "this project's repository is created with the name being the project ID number"

**This is not what the current code does.** The repository is *already* named
after the **project name**, slugified, not after the project ID. The project ID
is used only as the *subdirectory inside* the repo that groups a single
document's files.

It is easy to see where the confusion arises. The on-disk layout is:

```
/var/git/doctis/<project-slug>.git          ← repo name = slugified PROJECT NAME
/var/www/doctis/worktrees/<project-slug>/    ← worktree name = slugified PROJECT NAME
        └── <dwg_id>/<filename>              ← dwg_id is a path INSIDE the repo
```

So for the `example` project (id 1) holding document id 4, a file lives at:

```
/var/git/doctis/example.git           (bare repo — note: "example", not "1")
        worktree: .../worktrees/example/4/report.pdf
```

The `4` (a `dwg_id`) is the per-document folder, **not** the repo name. The
relevant code:

| Concern | Code | Result |
|---------|------|--------|
| Repo name | `GitFileStorageBackend::project_slug()` ([core/classes/GitFileStorageBackend.class.php:114](../core/classes/GitFileStorageBackend.class.php#L114)) | `<slug>.git` from project name |
| In-repo path | `GitFileStorageBackend::repo_rel_path()` ([core/classes/GitFileStorageBackend.class.php:185](../core/classes/GitFileStorageBackend.class.php#L185)) | `<dwg_id>/<filename>` |

```php
private function project_slug( int $p_project_id ): string {
    $t_name = project_get_field( $p_project_id, 'name' );
    return preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( trim( $t_name ) ) );
}
```

Consequently, **the feature the brief asks us to evaluate ("name the repo after
the project") is already implemented.** Whitespace is already collapsed to `-`
by the `preg_replace` above, which also satisfies the brief's secondary
suggestion.

The remaining and more useful question is therefore the *inverse*: the
name-based design as it stands carries several latent risks. This report
documents those risks, and weighs name-based naming against the ID-based scheme
the brief assumed was in place — because ID-based naming, while less readable,
is precisely what avoids those risks. A hybrid is recommended.

> **Side note — repos are created lazily.** A repo is *not* created when a
> project is created (`project_create()` has no git hook). It is created on the
> first document file upload, via `ensure_project_repo()`
> ([core/classes/GitFileStorageBackend.class.php:147](../core/classes/GitFileStorageBackend.class.php#L147)).
> Any naming change therefore only takes effect from a project's first upload,
> which slightly eases migration but also means the slug is computed from
> whatever the project name happens to be *at first upload time*.

---

## 2. In-repo layout: the purpose of the per-document subdirectory

A single project repo holds the files for **every document in that project**.
Both the registered primary document and any attachments are written to the same
path scheme, `<dwg_id>/<filename>`:

| File kind | Store call | Path written |
|-----------|-----------|--------------|
| Registered primary document | `file_dwg_primary_add()` ([core/file_dwg_api.php:1532](../core/file_dwg_api.php#L1532)) | `<dwg_id>/<filename>` |
| Document attachment | `file_dwg_add()` ([core/file_dwg_api.php:1029](../core/file_dwg_api.php#L1029)) | `<dwg_id>/<filename>` |

### Why a per-document subdirectory exists

**It is the GIT backend's collision-avoidance mechanism, and it operates between
documents.** The GIT backend stores files under their **real, original
filename** — not a hashed or mangled name. Without the `<dwg_id>/` prefix the
repo root would be a single flat namespace, and any two documents that each
uploaded a `report.pdf` would collide at the root, the second silently
overwriting the first.

The `<dwg_id>/` directory gives each document its own namespace, achieving two
things:

1. **Same filename across different documents is safe** — `4/report.pdf` and
   `7/report.pdf` coexist. *This is the direct answer to "is it because we allow
   multiple documents with the same filename within a project?" — yes, across
   documents.*
2. **All of one document's files are grouped together** — so `git log 4/` shows
   that document's complete file history in isolation.

### Contrast with the other backends (why this is GIT-specific)

The `DISK` and `DATABASE` backends avoid collisions differently: MantisBT
generates a **unique mangled disk filename** (`file_dwg_generate_unique_name()`)
and historically prefixed it with the document id — the legacy `doc-0000000-…`
scheme that `file_dwg_get_display_name()` strips back off for display
([core/file_dwg_api.php:103-115](../core/file_dwg_api.php#L103)).

The GIT backend deliberately does **not** mangle filenames — it keeps the
human-readable original name so the repository is browsable and `git log` is
meaningful to someone who does `git clone`. The `<dwg_id>/` subdirectory is what
*replaces* filename-mangling as the collision-avoidance mechanism. It is not an
extra layer on top of unique names; it is the GIT backend's substitute for them.

### Limitation: collisions *within* a single document

The subdirectory solves collisions *between* documents but not *within* one. If
a single document's primary file and one of its attachments share a filename (or
two attachments do), both map to `<dwg_id>/<name>` and overwrite each other at
`HEAD` — though git history retains every version, so nothing is truly lost.
Note that this overwrite-at-same-path behaviour is also exactly how
**revision/replace semantics** are intended to work: re-uploading a primary file
under the same name overwrites the path, and the commit history captures the
revision chain. The recommendation in §8 (removing attachments from the repo
entirely) largely eliminates the *unintended* side of this collision.

---

## 3. How the slug is used today

The slugification expression
`preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( trim( $name ) ) )`
appears in **five** places:

| Location | Purpose |
|----------|---------|
| [core/classes/GitFileStorageBackend.class.php:116](../core/classes/GitFileStorageBackend.class.php#L116) | Canonical `project_slug()` used by `store()` and `delete()` |
| [core/file_dwg_api.php:1796](../core/file_dwg_api.php#L1796) | Bare-repo path (duplicate) |
| [core/file_dwg_api.php:1834](../core/file_dwg_api.php#L1834) | Bare-repo path (duplicate) |
| [core/file_dwg_api.php:1928](../core/file_dwg_api.php#L1928) | Bare-repo path (duplicate) |
| [core/file_dwg_api.php:1981](../core/file_dwg_api.php#L1981) | Worktree path (duplicate) |

This duplication is itself a maintainability hazard: any change to the slug
algorithm must be made in five places or the backend and the API helpers will
disagree about where a project's repo lives.

The bare-repo path **is also persisted** per file. `store()` returns
`folder => <bare repo path>` ([core/classes/GitFileStorageBackend.class.php:283](../core/classes/GitFileStorageBackend.class.php#L283)),
and this is saved into `{dwg_primary_file}.folder` / `{dwg_file}.folder`
(`varchar(250)`). Crucially, `retrieve()` reads back the **stored** path
(`$p_row['folder']`), whereas `store()` and `delete()` **recompute** the slug
live. This split is the root of the rename hazard described next.

---

## 4. Risks and limitations of name-based naming

### 4.1 Project rename causes a split-brain (highest severity)

Project names are mutable. `project_update()`
([core/project_api.php:453](../core/project_api.php#L453)) allows changing the
name of an existing project with documents already stored. Because the slug is
derived from the *current* name on every `store()`/`delete()`, a rename
silently relocates where the backend looks:

| Operation after rename | Path source | Effect |
|------------------------|-------------|--------|
| `retrieve()` of an *old* file | **stored** `folder` column | ✅ still works — points at old repo |
| `store()` of a *new* file | **recomputed** slug | ❌ creates a brand-new repo under the new slug |
| `delete()` of an *old* file | **recomputed** slug | ❌ targets the new (wrong) worktree; old file not removed |

The project ends up with its documents fragmented across two (or more) repos —
one per historical name — with no record linking them. Old reads happen to keep
working only because the path was frozen at write time; everything else
diverges. This is a genuine data-integrity bug latent in the current design,
independent of any redesign.

An **ID-based** name (`<id>.git`) is immune: the project ID never changes.

### 4.2 Slug collisions defeat the project-name uniqueness guarantee

The `project` table enforces `UNIQUE KEY idx_project_name (name)` on the
*exact* name. Slugification is **lossy**, so distinct, individually-valid
project names can map to the same slug:

| Project name (all unique in DB) | Slug |
|---------------------------------|------|
| `Phase 2` | `phase-2` |
| `Phase-2` | `phase-2` |
| `phase  2` (double space) | `phase-2` |
| `PHASE 2!` | `phase-2-` *(trailing dash; see 4.3)* |

Two projects sharing a slug would share a bare repo and worktree, **mixing
unrelated documents in one git history** and crossing project access
boundaries. The DB uniqueness constraint provides no protection because it
operates on a different (un-slugified) value.

An **ID-based** name is collision-free by construction.

### 4.3 Lossy / degenerate slugs

The regex collapses any run of disallowed characters to a single `-` but does
**not** trim leading/trailing dashes or collapse the empty case:

- `"  Project!!  "` → `project-` (trailing dash).
- A name composed entirely of non-ASCII or punctuation — e.g. `"中文项目"`,
  `"***"` — slugifies to `"-"` or `""`. An empty slug yields a repo literally
  named `.git`, and a worktree path equal to the worktree root itself — both
  dangerous. Doctis does not currently restrict project names to ASCII, so this
  is reachable.

### 4.4 Length

Project names are `varchar(128)`. A 128-character name produces a ~128-char
slug plus `.git`. This is within ext4/XFS limits (255 bytes per path
component) but leaves little headroom and makes for unwieldy directory names.
IDs are at most ~10 digits.

### 4.5 Readability — the one advantage of names

The single, real benefit of name-based repos is **human legibility** when an
operator inspects `/var/git/doctis/` or reads `git log` output. `example.git`
is self-documenting; `1.git` requires a DB lookup. This advantage is worth
preserving, and the hybrid option below does so without the risks.

---

## 5. Whitespace handling (the brief's secondary question)

The brief proposed either (a) forbidding whitespace in project names, or (b)
replacing whitespace with `-` in the repo name.

**Recommendation: option (b), which is already in force**, and do **not**
restrict project names. Reasons:

- Project names are a long-standing, user-facing MantisBT field. Forbidding
  spaces would be a surprising UX regression and a diff against upstream
  (§"Minimise Diff with MantisBT" in CLAUDE.md). Spaces in project names are
  entirely reasonable ("Bridge Refit 2027").
- The slug layer is the correct place to absorb filesystem-unsafe characters,
  and it already does so for whitespace *and* every other disallowed character.

The whitespace question is thus effectively closed; the unresolved issues are
the *collision* and *rename* problems above, which whitespace stripping does
not address (and in fact slightly worsens, by mapping more distinct names onto
the same slug).

---

## 6. Design options for repo naming

### Option A — Pure ID-based naming: `<id>.git`

```
/var/git/doctis/1.git
```

- ✅ Immutable, collision-free, rename-safe, bounded length. Eliminates §4.1–4.4
  entirely.
- ❌ Not human-readable on disk or in admin tooling.
- Migration: rename existing repos/worktrees and rewrite stored `folder` values.

### Option B — Hardened name slug (status quo, repaired)

Keep `<slug>.git`, but: centralise the slug function (remove the 5-way
duplication), trim leading/trailing dashes, reject/fallback empty slugs,
**and** solve rename + collision — e.g. by storing the chosen slug in the
`project` table once and never recomputing it, plus a slug-uniqueness check at
project create/update.

- ✅ Most readable.
- ❌ Most code, most ongoing fragility; rename handling (move dirs + rewrite
  stored paths atomically) is genuinely hard to get right.

### Option C — Hybrid `<slug>-<id>.git` (recommended)

```
/var/git/doctis/example-1.git
/var/git/doctis/bridge-refit-2027-7.git
```

Append the immutable project ID as a suffix to a (best-effort) slug.

- ✅ **Collision-free** — the `-<id>` suffix guarantees uniqueness regardless of
  how lossy the slug is (solves §4.2, §4.3-empty).
- ✅ **Human-readable** — retains the project name for operators (solves §4.5).
- ✅ **Rename-tolerant by policy** — because the ID disambiguates, a rename that
  changes only the slug portion can be either (i) ignored — keep computing from
  ID alone and treat the slug as cosmetic, or (ii) handled by also persisting
  the path. The simplest robust variant uses **only the ID** for lookup and the
  slug purely as a cosmetic prefix that is *not* re-derived after creation.
- ◐ Still benefits from centralising the slug helper and persisting the repo
  path.

**Recommendation:** adopt **Option C**, and in its strongest form, **persist the
full repo directory name** (or just the bare-repo path, which the schema already
stores per-file) at the project level so that *no* code path ever recomputes it
from the mutable name. The ID suffix then guarantees uniqueness while the slug
prefix preserves readability.

---

## 7. Implementation implications (repo naming)

These are the touch-points any naming change must cover — listed to scope the
work, not to prescribe it.

1. **Single source of truth for the path.** Replace the canonical
   `project_slug()` *and* the four inline copies in
   [core/file_dwg_api.php](../core/file_dwg_api.php) with one shared helper
   (e.g. `dwg_project_repo_basename( $p_project_id )`). Today's duplication is a
   correctness risk on its own.
2. **Eliminate live recomputation in `store()`/`delete()`.** Both should resolve
   the repo location the same way `retrieve()` does — ideally from a persisted
   value — so a rename can never split a project's storage (§4.1).
3. **Persisted mapping.** Either rely on the existing per-file `folder` column
   (already populated) for *all* operations, or add a project-level store of the
   repo name. Per CLAUDE.md's schema rule, any new column goes into
   [admin/schema.php](../admin/schema.php) as a `CREATE TABLE` edit followed by a
   full DB rebuild — never an `ALTER TABLE`.
4. **Migration of existing repos.** The live `example.git` on vaio and its
   worktree would need renaming, and stored `folder` values rewritten, to match
   the new scheme. For a beta system with one project this is trivial but must
   not be skipped, or §4.1's split-brain is created during the migration itself.
5. **Install / setup scripts.** [admin/tools/doctis-git-setup.sh](../admin/tools/doctis-git-setup.sh)
   and [admin/tools/install-target.sh](../admin/tools/install-target.sh) create
   only the *root* directories, not per-project repos (those are lazy), so they
   need no change for naming — but [admin/test-git-php.php](../admin/test-git-php.php)
   hard-codes a `TEST_SLUG` and should be reviewed against the new helper.
6. **Docs.** CLAUDE.md describes the layout as `<slug>.git`; update it to match
   whatever scheme is adopted.

---

## 8. Scope the GIT backend to the registered document only (remove attachments)

This section addresses a separate decision raised after the layout above became
clear: **document attachments are currently stored in the git repository, and
they should not be.**

### 8.1 What happens today

A **single** config key, `$g_dwg_upload_method` (default `DATABASE`,
[config_defaults_inc.php:2339](../config_defaults_inc.php#L2339)), drives **all**
document file storage through **one** factory,
`file_dwg_get_storage_backend()` ([core/file_dwg_api.php:711](../core/file_dwg_api.php#L711)):

```php
function file_dwg_get_storage_backend(): FileStorageBackendInterface {
    switch( config_get( 'dwg_upload_method' ) ) {
        case DISK:     return new DiskFileStorageBackend();
        case DATABASE: return new DatabaseFileStorageBackend();
        case GIT:      return new GitFileStorageBackend();
        ...
```

Both the registered primary document (`file_dwg_primary_add`) **and** attachments
(`file_dwg_add`) call this same factory, as do the retrieve path
(`file_dwg_get_content` → `retrieve()`, [core/file_dwg_api.php:1304](../core/file_dwg_api.php#L1304))
and the delete path (`file_dwg_delete` → `delete()`, [core/file_dwg_api.php:744](../core/file_dwg_api.php#L744)).
So when `GIT` is configured, **attachments are committed into the per-project
repo right alongside the registered document**, both at `<dwg_id>/<filename>`.

### 8.2 Why that is undesirable (rationale)

- The **registered document is the only revision-controlled item**. It is the
  single artefact that should appear to someone performing a `git clone` of the
  project store. The repo is meant to be a clean, browsable archive of
  registered documents and their revision history — nothing else.
- **Attachments are Doctis-specific "assisting metadata"** against a registered
  document (notes, supporting files, review correspondence). They are
  application-internal and have no business being versioned in, or exposed
  through, the document store that external `git clone` users see.
- Mixing them also creates the intra-document filename-collision corner case
  noted in §2 (a primary file and an attachment sharing a name overwrite each
  other at `HEAD`). Removing attachments from the repo removes that corner case.

### 8.3 Recommendation

**Restrict the GIT backend to the registered primary document. Route document
attachments to the original MantisBT storage (filesystem `DISK` or database
`DATABASE` blob), exactly as bug attachments are stored.**

This is consistent with an **existing precedent** in the codebase: bug/bugnote
attachments in `file_api.php` already fall through `GIT → DATABASE` (documented
in CLAUDE.md, "File Storage Backend Architecture" / "Known Architectural
Trade-offs" #5). Document attachments would simply adopt the same rule, so the
behaviour is already understood and partially modelled in the project.

### 8.4 Implementation implications (attachment revert)

1. **No schema change required.** The `{dwg_file}` table already carries the full
   MantisBT-standard column set — `diskfile`, `folder`, `content` (longblob) —
   see [admin/schema.php:374-389](../admin/schema.php#L374). It mirrors
   `bug_file`, so `DISK`/`DATABASE` attachment storage works as-is. (The
   GIT-specific `git_sha` column lives only on `{dwg_primary_file}`, reinforcing
   that the primary file is the intended versioned artefact.)
2. **Decouple backend selection by file *kind*.** The factory is currently
   parameterless. Two viable shapes, lowest-diff first:
   - **(a) Sibling helper** `file_dwg_get_attachment_storage_backend()` that maps
     `GIT → DATABASE` (or `DISK`) and otherwise honours the configured method —
     a direct mirror of the `file_api.php` fall-through. The primary-file path
     keeps calling the existing `file_dwg_get_storage_backend()`.
   - **(b) Separate config key** `$g_dwg_attachment_upload_method` for explicit
     control, defaulting to `DATABASE`. More flexible, slightly more surface.
   Option (a) is recommended for minimal diff and because it needs no new config.
3. **Re-point the three attachment call paths** at the attachment backend:
   store `file_dwg_add()` ([:1021](../core/file_dwg_api.php#L1021)), retrieve
   `file_dwg_get_content()` ([:1304](../core/file_dwg_api.php#L1304)), and delete
   `file_dwg_delete()` ([:744](../core/file_dwg_api.php#L744)). The
   `file_dwg_primary_*` functions are left on GIT and unchanged.
4. **Review the GIT-path guards/helpers** at
   [core/file_dwg_api.php](../core/file_dwg_api.php) lines 1796 / 1834 / 1928 /
   1981 and the `!== GIT` guard at line 1828 — confirm each applies only to the
   primary file and not to attachments once the split exists.
5. **Migration of attachments already in git.** Any attachment already committed
   (e.g. on vaio's `example.git`) must be extracted from git back into the chosen
   `DISK`/`DATABASE` location and its `{dwg_file}` row rewritten
   (`diskfile`/`folder`/`content` repopulated, git-specific values cleared). For
   the current beta dataset this is small; a clean-slate reset
   (CLAUDE.md, "Resetting to a Clean Slate for Testing") is the simplest path if
   the existing data is disposable.
6. **Net effect on the repo.** After this change a `git clone` of a project store
   shows only registered documents: one `<dwg_id>/` directory per document,
   containing the registered file(s) and their revision history, with no
   attachment noise. The per-document subdirectory (§2) still earns its keep —
   for cross-document same-name separation and for per-document `git log`.

---

## 9. Summary of recommendations

1. **Correct the mental model:** repos are named after the **project name**
   today, not the project ID; the ID is only the in-repo document folder, whose
   purpose is to keep same-named files from *different* documents apart and to
   group each document's files/history (§2).
2. **Do not restrict project names** (no whitespace ban). Whitespace is already
   safely collapsed to `-` at the slug layer; banning it regresses UX and
   upstream-diff goals without fixing the real problems.
3. **The real, currently-latent bugs are slug collisions (§4.2) and the
   rename split-brain (§4.1).** Whitespace handling does not address either.
4. **Adopt Option C — `<slug>-<id>.git`** — for collision-free uniqueness *and*
   readability, with the repo location persisted (or derived from the immutable
   ID) so it is never recomputed from the mutable name.
5. **De-duplicate the slug logic** (one helper, five call-sites today) as a
   prerequisite — it is a standalone correctness risk.
6. **Remove attachments from the git store (§8):** scope GIT to the registered
   primary document only, and route document attachments to `DISK`/`DATABASE`
   like MantisBT bug attachments. No schema change is needed; the change mirrors
   an existing fall-through precedent in `file_api.php`.

---
---

# Part 2 — Remote Repository Access from a User Workstation

**Status:** Concept / design analysis only.
**Scope:** How an advanced Doctis user might `git clone` a project's **entire**
document repository onto their **own remote workstation** and **push draft
document updates back**, authenticated by their **Doctis credentials**, without
each user requiring a Linux account on the server.

> **Requirement clarification (supersedes the read-only assumption below).** The
> requirement is **read-write**: advanced users clone the whole project repo and
> **push** draft updates directly. This is compatible with the existing design,
> which already separates an **On-Record (approved)** version from an ongoing
> **Draft** at `HEAD` — see §P2.2.1. Single-document direct access is explicitly
> *not* wanted; the unit of access is the whole project repository.

---

## P2.1 The problem, grounded in the current page

`dwg_primary_head_warn.php?id=12` renders an "Advanced: Direct Git Repository
Access" panel. For document 12 in project *Ropoli* it shows, inter alia:

```
Project              Ropoli
Bare repository      /var/git/doctis/ropoli.git
Document path in repo 12/LiDAR-Camera-Calibration-Work-Instruction.md
Clone repository locally   git clone '/var/git/doctis/ropoli.git' /tmp/ropoli
```

Every command on that page — including the clone — assumes **shell access to the
server** (the page itself says "run on the server, or prefix with
`ssh hcr@vaio "..."`"). The clone target is a **server-local** path
(`/var/git/doctis/ropoli.git` → `/tmp/ropoli`). This is useful only to an
operator already logged into vaio; it does nothing for a reviewer sitting at a
workstation who wants the repository locally.

> **Note (ties to Part 1):** [dwg_primary_head_warn.php:56](../dwg_primary_head_warn.php#L56)
> contains a **sixth** copy of the slug regex
> (`preg_replace( '/[^a-z0-9\-]+/', ... )`). Any remote-access URL scheme makes
> the repo name *user-visible and bookmarked*, which sharply raises the cost of
> the rename/collision hazards in Part 1 §4. **Stabilising the repo name first
> (Part 1 Option C, `<slug>-<id>.git`, with the name persisted) is a prerequisite
> for exposing repositories remotely** — otherwise a project rename silently
> breaks every user's configured git remote.

The requirement: let an advanced user, from their workstation, run something like

```
git clone https://doctis.example.org/git/ropoli.git
# ...edit draft documents, commit...
git push
```

and authenticate with their existing Doctis identity — no shell account. The
clone is of the **whole project repo**, and the user **pushes draft updates**
back to it.

---

## P2.2 Cross-cutting constraints (apply to every option)

### P2.2.1 Writes (draft pushes) are required — and safe under the On-Record/Draft model

Doctis already distinguishes the **approved (On-Record)** version of a document
from the live **Draft** at the repository's `HEAD`. This is what makes direct
pushes safe rather than corrupting:

| Concept | Where it lives | Code |
|---------|----------------|------|
| **On-Record (approved)** | `{dwg_primary_file}.git_sha` — a pinned SHA recorded in the DB | `retrieve()` serves it via `git show <sha>:path` |
| **Draft** | whatever is at the branch `HEAD` of the bare repo | `file_dwg_git_head_info()` ([core/file_dwg_api.php:1827](../core/file_dwg_api.php#L1827)) reads it independently of the DB |
| **Promote draft → approved** | explicit Doctis action that records HEAD's SHA as the new approved SHA | `file_dwg_primary_sync_head()` ([core/file_dwg_api.php:1782](../core/file_dwg_api.php#L1782)) |

So an advanced user pushing new commits simply **advances the Draft**; the
On-Record version stays pinned to its recorded SHA until someone explicitly
approves the new draft inside Doctis. The DB is *not* desynchronised by a push,
because draft commits were never meant to have DB rows — only approval records a
SHA. (`dwg_primary_head_warn.php` already exists precisely to warn that HEAD may
differ from the approved version.)

**This reverses the earlier read-only assumption: remote access must support
`git push` (`receive-pack`), not forbid it.** However, enabling pushes
introduces three concrete implications that must be handled:

- **(a) The server-side worktree must sync to `origin` before its own commits.**
  Today `GitFileStorageBackend::store()` does `addFile → commit → push` with **no
  prior `fetch`/`pull`** ([core/classes/GitFileStorageBackend.class.php:250-270](../core/classes/GitFileStorageBackend.class.php#L250)).
  Once external users push, the server worktree's branch falls behind `origin`,
  and the next UI-driven upload will fail with a non-fast-forward push rejection
  (or commit onto a stale base). **`store()` must fetch and fast-forward/rebase
  the worktree to `origin/HEAD` before staging its own commit.** This is a
  prerequisite change, not optional.

- **(b) Approved SHAs must be protected from loss.** `retrieve()` of an On-Record
  version depends on that commit remaining reachable in the bare repo. A
  force-push or history rewrite from a workstation could orphan an approved
  commit and expose it to garbage collection — silently destroying the approved
  content. Mitigations: **reject non-fast-forward / force pushes** on the served
  branch, **and** have Doctis pin every approved SHA with a managed git ref
  (e.g. `refs/doctis/approved/<dwg_id>/<n>` → SHA) at approval time, so the
  commit is permanently reachable and GC-safe regardless of branch history.

- **(c) Concurrency on a shared mutable HEAD.** Multiple advanced users pushing
  to the same branch will hit ordinary non-fast-forward rejections and must
  `pull --rebase`; this is standard git and acceptable. A branch convention
  (e.g. per-user/topic draft branches that Doctis can be pointed at) is a
  possible refinement, but the default model — one mainline draft whose HEAD is
  the draft — matches the current single-`git_sha`-per-document design.

### P2.2.2 Authorization is per-PROJECT, not per-document

Git serves a whole repository; it has no concept of Doctis access levels or
**licenses**. A user who can clone `ropoli.git` gets **every document in that
project** present in git history, and a user who can push can write **any** of
them. This is a hard limitation of exposing raw git, and it fits the clarified
requirement (the unit of access *is* the whole project repo):

- Doctis per-document `view_dwg_threshold` and **license-gated** access
  (Part 1 / `license_api.php`) **cannot be enforced** through a repo clone/push.
- Remote git access should therefore be gated on a **project-level** entitlement.
  Distinguish **read** (clone/fetch) from **write** (push) — the push entitlement
  should be a higher bar (e.g. developer/manager on the project), since pushers
  move every document's draft.
- A further reason to land **Part 1 §8** (remove attachments from the repo)
  first: a clone then exposes only *registered documents*, never attachment
  "assisting metadata", and pushers cannot touch attachments via git.

### P2.2.3 Credentials, not OS identity

The goal is to reuse Doctis credentials. For git-over-HTTPS the cleanest secret
is a **MantisBT/Doctis personal API token** (already supported for the REST API)
used as the HTTP Basic *password*, rather than the user's real password. Tokens
are revocable, scoped, and avoid prompting for the account password in git
credential helpers. The same token authorises both fetch and push, with the
project-level read/write check (P2.2.2) applied per operation.

### P2.2.4 The web server already owns the repos

The bare repos are `www-data:www-data` (CLAUDE.md). Any HTTP-based option runs
as `www-data` and can both read **and write** them with no permission changes —
which is convenient now that push is required. SSH-based options need a
group/ACL bridge instead.

---

## P2.3 Options

### Option 1 — Smart HTTP(S) via `git-http-backend` + a Doctis auth gateway *(recommended)*

Git's "smart HTTP" protocol is served by the `git-http-backend` CGI. Place it
behind the existing Apache instance under a path such as `/git/`, and gate it
with a Doctis-aware authenticator.

```
git clone https://doctis.example.org/git/ropoli.git
Username: <doctis username>
Password: <doctis personal API token>
```

**Flow per request:**
1. Apache requires HTTP Basic auth on `/git/`.
2. A thin auth handler (Apache `mod_authnz_external`/`mod_auth_form`, or a small
   PHP/`AuthBasicProvider` shim that boots Doctis `core.php`) validates
   *username + API token* against the Doctis user table.
3. The handler resolves the repo name in the URL → `project_id` (via the
   stable mapping from Part 1) and checks the user's **project-level** access,
   distinguishing **read** (fetch) from **write** (push) per P2.2.2.
4. On success it hands off to `git-http-backend`. **Push is enabled** for
   write-authorised users (`http.receivepack=true` / serve `git-receive-pack`),
   with **force-push refused** and approved SHAs ref-pinned per P2.2.1(b).

- ✅ **No OS accounts.** Pure Doctis credentials over HTTPS, for fetch *and* push.
- ✅ Reuses the existing Apache/TLS stack and the existing REST API-token
   mechanism.
- ✅ `www-data` already owns the repos — no permission bridging for reads or
   writes.
- ✅ Standard `git clone`/`git push https://…` works on any OS with stock git;
   credentials cached by the user's git credential helper.
- ◐ Requires wiring the CGI + an authz shim, careful URL→project mapping, and the
   worktree-sync fix P2.2.1(a) plus force-push rejection P2.2.1(b).

This is the best fit for "advanced users push drafts, authenticated by Doctis
credentials, no Linux accounts".

### Option 2 — SSH with managed keys, one shared system account (gitolite-style)

A single unprivileged `git` system account (not one per user). Each user
registers an **SSH public key** in their Doctis profile; an
`AuthorizedKeysCommand` (or gitolite) maps the presented key → Doctis user →
allowed projects, and forces a **read-only** `git-shell`/`upload-pack`-only
command.

- ✅ Exactly **one** OS account total; users never get shell logins (forced
   command only).
- ✅ Efficient native git transport; good for power users / automation.
- ❌ Authenticates by **SSH key, not Doctis password/token** — needs a key-
   management UI in Doctis and a key→user→project resolver.
- ❌ New SSH attack surface and `AuthorizedKeysCommand` plumbing to maintain.
- ◐ Read-only must be enforced by forced command, not just convention.

A reasonable secondary offering for advanced/automation users, but heavier than
Option 1 and does not satisfy "via their Doctis credentials" directly.

### Option 3 — Auto-provisioned per-user Linux accounts (the brief's opt-in) *(not recommended)*

A Doctis toggle that, when an advanced user opts in, has the web application
create a real system account with restricted (`git-shell`) git access.

- ✅ Conceptually direct: each user gets native SSH git access.
- ❌ **Web app creating OS accounts requires root-level automation** — a serious
   privilege-escalation and attack surface for a PHP web app.
- ❌ Account lifecycle (deprovisioning on Doctis user disable/delete, password/
   key rotation, quota, audit) becomes a parallel identity system to maintain.
- ❌ Still needs read-only enforcement and per-project authz on top.
- ❌ Highest operational and security cost of all options.

Present for completeness; **recommend against**. Option 1 delivers the same end-
user capability without any OS accounts. If native SSH is genuinely required,
Option 2's *single shared account* achieves it far more safely than per-user
accounts.

### Option 4 — API-delivered snapshots via REST/SOAP (`git bundle` / `git archive`) *(does not meet the push requirement)*

Rather than exposing a live git remote, add a Doctis REST (or SOAP) endpoint that
returns the project repository as a **git bundle** or a **`git archive`** tarball.

- ✅ Trivial, correct auth & authz — reuses existing REST/SOAP credentials/ACL;
   no new transport, no OS accounts.
- ❌ **Read-only / pull-only by nature — there is no push path.** With the
   clarified requirement (advanced users push draft updates), this **cannot be
   the primary mechanism**: a user could clone a bundle but has no way to send
   draft commits back through it.

Retain only as a *secondary, read-only export* convenience (e.g. "download full
revision history offline") for users who do not need to push. It does not
satisfy the core requirement on its own.

### Option 5 — Dumb HTTP (static) read-only *(mention only)*

Serve the bare repo directory as static files over authenticated HTTPS after
running `git update-server-info` on every push.

- ✅ Minimal server software.
- ❌ Requires regenerating server-info after every change; less efficient; no
   smart negotiation. Strictly inferior to Option 1. Not recommended.

---

## P2.4 Comparison

| Option | OS accounts | Auth = Doctis creds | Live remote | Supports push (draft write) | Effort | Verdict |
|--------|-------------|---------------------|-------------|------------------------------|--------|---------|
| 1. Smart HTTP + gateway | none | ✅ (token) | ✅ | ✅ (receive-pack, no force) | medium | **Recommended** |
| 2. SSH, 1 shared acct | one shared | ◐ (SSH key) | ✅ | ✅ | medium-high | Secondary / power users |
| 3. Per-user Linux accts | one per user | ◐ | ✅ | ✅ | high | **Avoid** |
| 4. REST/SOAP bundle | none | ✅ | ❌ (snapshots) | ❌ | low | Read-only export only |
| 5. Dumb HTTP | none | ✅ | ◐ | ❌ | low | Unsuitable (read-only) |

---

## P2.5 Recommendation

1. **Primary: Option 1 — Smart HTTP(S) via `git-http-backend`**, authenticated
   with *Doctis username + personal API token*, gated by a Doctis auth shim that
   enforces **project-level** access and **serves both fetch and push**
   (`receive-pack` enabled for write-authorised users, force-push refused).
   Surface a per-user opt-in that reveals/creates an API token and shows the
   workstation clone URL — replacing the current server-local `/tmp/<slug>`
   guidance on `dwg_primary_head_warn.php` with a real read-write remote URL.
2. **Secondary, read-only export: Option 4 — REST/SOAP `git bundle`**, for users
   who only need an offline copy or full-history export. It cannot accept pushes,
   so it does not satisfy the core requirement on its own.
3. **Prerequisites before exposing any remote URL:**
   - Land **Part 1 Option C** (stable `<slug>-<id>.git`, name persisted) so a
     project rename cannot break users' configured remotes.
   - Land **Part 1 §8** (attachments out of the repo) so a clone exposes only
     registered documents and pushers cannot touch attachment metadata via git.
   - **Make the server worktree fetch/fast-forward before its own commits**
     (P2.2.1(a)) — without this, the first external push breaks UI uploads.
   - **Refuse force-push and ref-pin every approved SHA** (P2.2.1(b)) so an
     On-Record version can never be orphaned/garbage-collected by draft history
     rewrites.
   - Document clearly that remote git access is **project-level** and therefore
     **cannot honour per-document `view_dwg_threshold` or license gating** —
     restrict the (read vs write) entitlement accordingly.
4. **Avoid Option 3** (auto-provisioned per-user OS accounts). If native SSH push
   is ever required, prefer Option 2's single shared account with a forced
   command that permits fetch/push but blocks force-push and shell access.

> **Workflow note.** Doctis already has the "approve the current draft" primitive
> — `file_dwg_primary_sync_head()` ([core/file_dwg_api.php:1782](../core/file_dwg_api.php#L1782))
> records the current HEAD SHA as the new On-Record version. Once remote pushes
> are enabled, this becomes the natural promotion step: advanced users push
> drafts freely, and a reviewer with the appropriate access promotes a chosen
> HEAD to approved inside Doctis. No new approval mechanism is required.
