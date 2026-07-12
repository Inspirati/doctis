# Doctis Git Integration — Solution Space Analysis

Status: **ANALYSIS / DECISION DOCUMENT** — written 2026-07-12, prompted by the
repository-import requirement ([GIT_IMPORTER.md](GIT_IMPORTER.md)).

## 0. Premise — We Are Not Constrained by the Current Implementation

Doctis has never been deployed. The only running instance is the vaio
development box; its database is rebuilt routinely from `admin/schema.php`,
and its single live repo (`example-1.git`) is disposable test data. There is
**no data-migration constraint and no installed-base constraint**. The only
sunk costs are:

- ~380 lines of `GitFileStorageBackend`, ~270 lines of gateway, and the
  helpers in `file_dwg_api.php` — *code*, cheap to reshape;
- the **operational lessons** encoded in them (HOME env fix, push-mandatory,
  worktree sync, hook enforcement, id-suffix naming) — *knowledge*, which
  survives any refactor and must not be lost.

The question this document answers: **does the import requirement expose the
current design as wrong at the foundation, such that a bottom-up refactor
yields a cleaner whole — or is it genuinely an increment?** The method: break
the design into orthogonal axes, enumerate the realistic options on each,
assemble the coherent end-to-end candidates, and compare.

The trigger for suspicion is legitimate. As first drafted, GIT_IMPORTER.md
bolted two mode-switches onto the existing design:

- **D1** — a nullable `git_path` column: *two path conventions* in one repo,
  imported documents native, Doctis documents `<dwg_id>/`;
- **D2** — per-project config delegation: *two repo-resolution rules*,
  own-repo vs delegated.

Permanent dual-mode behaviour, keyed on how a record happened to be created,
is the signature of a tack-on. This analysis takes that seriously.

---

## 1. Requirements (restated, implementation-free)

| # | Requirement |
|---|-------------|
| R1 | Each document has one primary, revision-controlled artefact; every stored version is permanently retrievable by identity (audit / ISO 9001) |
| R2 | Doctis records lifecycle state (status, approval) *about* versions; approval pins an exact version immutably |
| R3 | External users can clone the document store and push draft updates with standard git tooling, authenticated against Doctis accounts |
| R4 | Draft evolution (pushes) must never move the approved/on-record version; promotion is an explicit Doctis action |
| R5 | Attachments (dwg + bug) are non-versioned assisting metadata, never in the cloned store |
| R6 | Control direction is Doctis → git; no daemons, hooks, or webhooks driving Doctis from git events |
| **R7** | **(new)** An existing repository — arbitrary layout, full history — can be adopted as a project's store, and its qualifying files registered as documents, without disturbing the repo's usability for its existing consumers |
| **R8** | **(new)** A repository may serve a *tree* of projects (monorepo → parent + sub-projects) |
| R9 | Minimise diff against MantisBT upstream (Doctis-parallel files; light touch on shared code) |
| Q1–Q4 | Qualities: one code path over modes; human-legible repos; small, debuggable mechanism; honest failure modes |

R7/R8 are the new entrants. Note what they change: R1–R6 can all be satisfied
by a system that *owns* its storage layout. R7 cannot — an adopted repo's
layout is a fact, not a choice. **R7 forces path to become data.** That is
the pivot on which the whole analysis turns.

---

## 2. The Solution Space, Axis by Axis

### A1 — System posture: what *is* git to Doctis?

| Option | Description | Assessment |
|--------|-------------|------------|
| **A1a. Git as pluggable blob store** *(current)* | GIT is method 3 beside DISK/DATABASE behind `FileStorageBackendInterface`; Doctis owns layout and treats SHAs as opaque "diskfile" keys | The abstraction is already a polite fiction: `git_sha`/`git_branch` columns, the head-warn page, approved-ref pinning, the Smart HTTP gateway and the draft/on-record doctrine all exist *outside* the interface. GIT is not a storage method; it is a different model wearing a storage method's uniform. |
| **A1b. Git as *the* system of record for primary documents; Doctis as index + lifecycle overlay** | Every primary document is a `(repository, path)` reference plus pinned SHAs; DB rows are registrations *about* repo content | Matches what the feature has organically become (GIT_ARCHITECTURE's own headline: "lightweight document index and lifecycle tracking system that uses git as its storage backend"). Import stops being special: *registering existing content is the primitive; upload is register + a commit that creates the content first.* |
| A1c. Hybrid by project mode | Doctis-native projects = A1a; imported projects = A1b | This is what IMPORTER-D1/D2 accidentally builds. Two permanent behavioural classes of project; every future feature pays the mode tax. Reject. |
| A1d. External forge overlay (Gitea/GitLab/GitHub API) | Doctis stores nothing; references remote repos | Adds a hard runtime dependency and an auth/permission impedance mismatch; loses the pre-receive enforcement point; useless air-gapped. Contradicts the self-contained QMS posture. Reject. |

**Finding:** A1b. Not because the current code is bad, but because the system
already behaves as A1b everywhere except the storage interface, and R7 cannot
be expressed in A1a at all.

### A2 — Primary-document storage pluralism

| Option | Assessment |
|--------|------------|
| Keep DISK / DATABASE / GIT selectable for primary documents *(current: `$g_dwg_upload_method`)* | Under DISK/DATABASE, R1 versioning, R3 clone, R4 draft/on-record are all *silently absent* — the flagship workflow degrades to "MantisBT attachment with one file". Nobody has ever run Doctis in these modes for primaries. Every switch site (~10 in `file_dwg_api.php`) is a place for the GIT case to be forgotten (a known bug class — see CLAUDE.md error table). |
| **Git-only for primary documents; DISK/DATABASE remain for attachments** | The primary document is *definitionally* the revision-controlled artefact — a non-versioning backend for it is a contradiction in the product's own terms. Deleting the switch deletes a config key, the `content` blob and `folder` columns from `{dwg_primary_file}`, and a class of "missing GIT case" bugs. Cost: `git` binary becomes an install prerequisite like MariaDB — acceptable for a document-control product whose headline feature is the clonable store. |

**Finding:** git-only for primaries. The backend *interface* stays — it fits
attachments (true blob semantics) perfectly and keeps upstream-parallel
structure (R9).

### A3 — In-repo layout: who chooses paths?

The heart of the matter.

| Option | Description | Assessment |
|--------|-------------|------------|
| A3a. System layout `<dwg_id>/<filename>` *(current)* | Collision-proof by construction; machine-oriented | Cannot express an adopted repo (R7). Also quietly at odds with R3/Q2: a cloned repo full of numbered directories is legible to Doctis, not to humans. |
| A3b. Dual: system layout + nullable override *(IMPORTER-D1 as first drafted)* | `git_path` empty ⇒ computed; set ⇒ verbatim | Works, but institutionalises two conventions per repo forever. The mode is invisible at the repo level: a clone shows `4/spec.pdf` next to `content/engineering/spec.md`. Q1/Q2 fail. |
| **A3c. Path as data, universally** | Every `{dwg_primary_file}` row stores its repo-relative path, `NOT NULL`. Doctis-*created* documents get their path at creation time from a per-project **template** (`{dwg_id}/{filename}` default; e.g. `{category}/{filename}` for curated repos); imported documents get their existing path verbatim. After creation, nothing anywhere computes a path — storage code reads the column, full stop. | One rule, one code path. The current behaviour survives as merely the *default template*, not as a hardcoded convention. Imported and native documents are indistinguishable to every downstream operation. |
| A3d. Restructure on import (`git mv` into system layout) | Force adopted repos into A3a | Breaks the adopted repo for its existing consumers (HCRQMS is a live publishing tree); rejected in IMPORTER-D1 and stays rejected. |
| A3e. Replay import (synthesise new history into system layout, preserving authors/dates via `GIT_AUTHOR_DATE`) | History *content* preserved, SHAs and paths rewritten | Severs SHA continuity with the source repo — external clones diverge irreconcilably; the "history" is a forgery of provenance. Reject. |

**Finding:** A3c. This is the single most consequential decision in the
space. Its honest costs (§4): a collision rule for user-chosen templates, and
external renames can dangle a stored path at HEAD (undetectable under A3a
only because A3a made external contribution to *paths* impossible).

### A4 — Repository ↔ project topology

| Option | Description | Assessment |
|--------|-------------|------------|
| A4a. 1:1, repo keyed by project, created lazily on first upload *(current)* | Repo existence = directory-on-disk; identity = `<slug>-<project_id>` | Cannot express R8. Lazy creation as a side effect of upload was already slightly warty (advisory-lock dance vs rename; repos appear by surprise); import needs eager adoption anyway. |
| A4b. 1:1 + per-project config delegation *(IMPORTER-D2 as first drafted)* | `config_set('git_storage_project_id', …)` | Works, but the project↔repo relation — a structural fact — lives in the config table as an untyped integer with cycle-guarding done in PHP. Invisible to schema, joins, and referential reasoning. |
| **A4c. Repository as a first-class entity** | `{repository}` table: `id, name, slug, default_branch, owner_project_id, adopted_from, date_created`. `{project}.repository_id` (nullable FK): set ⇒ that repo; NULL ⇒ inherit from parent project; NULL at root ⇒ repo created on demand (and the row recorded). Sub-projects of a monorepo simply carry the parent's `repository_id`. | The relation becomes typed data. Adoption provenance (`adopted_from` = old origin URL) is recorded where it belongs. Naming becomes `<slug>-r<repo_id>.git` — same immutable-id-suffix scheme, same gateway resolution logic, same rename mechanics, now keyed on the entity that actually owns the name. N:1 (R8) is native; nothing prevents a future project *moving* between repos because documents reference the repo through the project, and pinned SHAs stay valid in the repo they were pinned in. |
| A4d. Global monolithic repo (all projects, one repo, top-level dir per project) | | Clone-scope = everything: destroys the project-level access boundary (git cannot enforce per-path read authz on serve). Reject. |
| A4e. Submodules (each sub-project its own repo; parent = super-repo of submodules) | | Requires splitting the monorepo on import (rejected above); submodule UX is notoriously hostile to exactly the non-git-expert users a QMS serves. Reject. |
| A4f. One project spanning *multiple* repos | | No driving use case; multiplies every resolution question. Explicitly out of scope. |

**Finding:** A4c. Note the access-model consequence is unchanged from today:
the clone/push boundary is the **repository**, authorised against its
*owner project's* access level; per-document thresholds remain un-enforceable
over git (already documented in GIT_ARCHITECTURE).

### A5 — Write mechanics: how a Doctis-driven change becomes a commit

| Option | Assessment |
|--------|------------|
| **Server worktree: sync → write file → add/commit → push** *(current)* | Built, tested (20-step integration test), debuggable by an operator with ordinary git knowledge (`ls` the worktree, `git log`). Costs: a second on-disk tree per repo, the sync/orphan-healing logic, and the worktree↔bare consistency rules. |
| Plumbing direct-to-bare: `hash-object` → `mktree` → `commit-tree` → CAS `update-ref` | Eliminates worktrees, sync, and orphan healing entirely; commits are built atomically against the current ref with compare-and-swap retry. Costs: raw plumbing code is harder to read and debug; bypasses `pre-receive` (server-side writes already do, via `update-ref` for pins — same trust model); loses the "inspect the worktree" diagnostic affordance. |

**Finding:** keep the worktree mechanism. It is orthogonal to R7/R8 — import
adoption doesn't touch write mechanics — and it is the part of the current
implementation whose lessons cost the most to learn. Log plumbing-direct as a
possible later simplification (it becomes *more* attractive under A3c, since
multi-document repos make worktrees larger than needed). **A refactor should
not churn layers whose design is not implicated by the new requirements.**

### A6 — Where lifecycle/approval metadata lives

| Option | Assessment |
|--------|------------|
| **DB rows + server-managed pinned refs (`refs/doctis/approved/…`)** *(current)* | The DB is the workflow authority (R2); the refs are the out-of-band, GC-proof audit trace inside the artefact store itself. Dual-write, but each side serves a distinct master: MantisBT machinery needs the DB; auditors and clones get the refs. |
| Git-native only (tags/notes as authority) | MantisBT's entire filter/permission/workflow engine is DB-shaped; deriving workflow state from refs means rebuilding MantisBT. Reject. |
| Metadata *in* the repo (`.doctis/` YAML or frontmatter round-trip; DB = rebuildable cache) | Philosophically attractive for audit (repo fully self-describing; HCRQMS frontmatter shows the pattern works for authors). But every status change becomes a commit (history noise, write amplification), concurrent workflow actions contend on the branch, and Doctis rewriting *file content* it doesn't author crosses a line — the store should never mutate documents. Reject for system metadata; **frontmatter remains an import-time read-only source** (IMPORTER §6). |
| Signed approvals (GPG-signed tags at approval) | Genuine strengthening of R1/R2 evidence; pure add-on to the pinning mechanism; deferred (key management), unchanged from GIT_TODO §6. |

**Finding:** current model is correct; unchanged.

### A7 — Control direction and reconciliation

The Doctis→git doctrine (R6) survives contact with import intact, with one
sharpened definition: **import and re-import are operator-initiated batch
reads**, not events. No post-receive hooks, no pollers. What import *adds* is
a place where git state can now legitimately disagree with the index (paths
renamed/deleted by external pushes — impossible under A3a, possible under
A3c), so the existing HEAD-drift surface (`dwg_primary_head_warn.php`) gains
one check: *does my `git_path` still exist at HEAD?* — with "point me at the
new path" as the operator remedy. Detection remains lazy (at page view / at
re-import), never event-driven.

### A8 — Programmatic surface

Unchanged from IMPORTER-D4: adoption is inherently server-side filesystem
work (PHP CLI); registration-by-reference becomes a core primitive first,
SOAP wrapper second, REST when the dwg REST layer exists at all. The axis is
orthogonal to the refactor question. One improvement falls out of A4c: an
explicit `repository` entity gives future SOAP/REST a natural noun
(`mc_repository_adopt`, `/repositories/{id}`), instead of verbs hung off
projects.

---

## 3. Candidate Architectures

Assembling the axis findings into coherent wholes:

| | C0 — Increment (IMPORTER as first drafted) | **C1 — Repository-centric refactor (recommended)** | C2 — Repo-as-database | C3 — Forge overlay |
|---|---|---|---|---|
| Posture | A1c hybrid-by-mode | A1b index over git | metadata in repo | overlay on external API |
| Primary storage | 3 methods + mode | git-only | git-only | forge |
| Layout | dual (`dwg_id/` + nullable override) | **path as data + creation template** | native | native |
| Topology | 1:1 + config delegation | **`{repository}` entity, projects reference/inherit** | repo entity | remote repos |
| Write mech | worktree | worktree (unchanged) | worktree + metadata commits | API calls |
| R7 import | ✓ (as special mode) | ✓ (as ordinary case) | ✓ | partial |
| R8 monorepo | ✓ (config hack) | ✓ (typed relation) | ✓ | ✓ |
| Q1 one code path | ✗ — permanent dual mode | ✓ | ✓ | ✓ |
| Q3 mechanism size | smallest delta, largest resulting machine | small delta (see §5), **smallest resulting machine** | largest | smaller Doctis, huge dependency |
| R6 direction | ✓ | ✓ | strained (workflow writes → commits) | inverted (webhooks) |
| R9 upstream diff | neutral | neutral (all touched files are Doctis-parallel; `project_api.php` touch shrinks — rename relocation moves behind the repo entity) | neutral | neutral |
| Verdict | the tack-on the user suspected | **adopt** | reject (A6) | reject (A1d) |

### Why C1 is a refactor and not a rewrite

The current implementation separates into two layers that the axis analysis
treats very differently:

- **Infrastructure layer** — bare+worktree mechanics, push-mandatory, sync &
  orphan healing, pre-receive enforcement, approved-ref pinning, Smart HTTP
  gateway, HOME/attribution fixes, id-suffix naming & rename relocation.
  **Every finding above keeps this layer.** It encodes the operational
  knowledge; none of it is implicated by R7/R8.
- **Mapping layer** — how (project, document) resolves to (repo, path):
  the `<dwg_id>/` rule, the project-keyed lazy repo, the method switch.
  **This is where all three findings land** (A3c, A4c, A2), and it is small:
  resolution already funnels through the `dwg_project_*` helpers (~19 call
  sites, 6 files — the §1 naming-stability work of 2026-07-03 unknowingly
  paid for this refactor), and the method switch is ~10 sites in one file.

C0's true cost is not its diff; it is that every future feature (REST, bulk
ops, reporting, the pandoc trigger) meets *two* kinds of project and *two*
kinds of path forever. C1's cost is a contained mapping-layer rebuild now,
while there is exactly one developer, zero deployments, and a rebuildable
database.

---

## 4. Honest Costs and New Obligations of C1

Refactors are not free even when clean. Named plainly:

1. **Collision policy becomes real.** `{dwg_id}/{filename}` never collided;
   a project template like `{category}/{filename}` can. Rule: path uniqueness
   is enforced per repository at document-creation/import time (DB uniqueness
   on `(repository_id via project, git_path)` is awkward across the project
   indirection — enforce in `file_dwg_primary_register()`, which all paths
   now flow through). Collision ⇒ hard validation error naming the holder.
   The default template keeps the collision-free property out of the box.
2. **Dangling paths.** External pushes can rename/delete a registered path.
   Pinned SHAs keep every approved version retrievable regardless (R1 is
   never at risk); but "current draft" display needs the HEAD-existence check
   (A7) and an operator re-point action. This is new UI surface, small but
   not zero.
3. **Git becomes an install prerequisite** for any Doctis with documents.
   Defensible (§A2), but must be stated in install docs and checked by
   `admin/check`.
4. **`{repository}` table plumbing** — gateway, backend, helpers, project
   create/rename paths all re-keyed from `project_id` to `repository_id`.
   Contained (§3) but touches the scariest code (gateway auth). The 20-step
   integration test is the safety net and must be extended first, not after.
5. **Schema churn**: `{repository}` new; `{project}.repository_id` added
   (one MantisBT-shared table touched — the *only* R9-relevant change;
   alternative: a Doctis-parallel `{project_repository}` link table keeps
   `{project}` pristine at the cost of a join — decide at implementation);
   `{dwg_primary_file}` gains `git_path NOT NULL`, drops `folder` and
   `content`, renames `diskfile` → `draft_sha` (its actual meaning: last
   registered/uploaded commit, distinct from on-record `git_sha`).
6. **Docs debt**: GIT_ARCHITECTURE.md sections on naming (`<slug>-<id>` →
   `<slug>-r<id>`), lazy creation, and the storage-method table all need
   rewriting when C1 lands — tracked in GIT_TODO, not silently.

---

## 5. Consequences for the Import Plan

GIT_IMPORTER.md remains the requirements/workflow document; under C1 its
prerequisites simplify and two of its decisions dissolve:

| IMPORTER item | Under C0 | Under C1 |
|---|---|---|
| D1 `git_path` override | nullable column, dual convention | **dissolves** — path-as-data is universal (A3c); import just sets it |
| D2 storage delegation | per-project config hack | **dissolves** — sub-projects reference the parent's `repository_id` (A4c) |
| D3 authority after import | unchanged | unchanged (`adopted_from` recorded on `{repository}`) |
| D4 PHP CLI | unchanged | unchanged; adoption = "create `{repository}` row + clone --bare + configure" — the same explicit path Doctis-native repo creation now also uses |
| D5 `.doctis` manifest | unchanged | unchanged |
| D6 lifecycle at import | unchanged | unchanged |
| D7 folders→categories | unchanged | unchanged |
| P1–P4 prerequisites | 4 items | replaced by the C1 work packages below |

### C1 work packages (supersede IMPORTER §4 P1–P3; P4 survives as WP4)

| WP | Content | Proves |
|----|---------|--------|
| WP1 | `{repository}` table + `{project}` linkage (or link table); repo lifecycle explicit (`create` / `adopt`); naming `<slug>-r<id>`; gateway + helpers re-keyed; rename relocation moved onto the repo entity | A4c |
| WP2 | `git_path` universal in `{dwg_primary_file}` (+ drop `folder`/`content`, rename `diskfile`→`draft_sha`); backend reads stored path only; creation-time path template (`$g_dwg_repo_path_template`, per-project overridable); collision enforcement | A3c |
| WP3 | Retire `$g_dwg_upload_method` for primaries (git-only); attachment factories/backends untouched | A2 |
| WP4 | `file_dwg_primary_register()` — register-by-reference primitive (unchanged from IMPORTER P4; now *the* choke point for paths and collisions) | A1b |
| WP5 | HEAD-path-existence check in `dwg_primary_head_warn.php` + re-point action | §4.2 |
| WP6 | Extend `admin/test-git-php.php` per WP (template paths, adopted-repo fixture, shared-repo sub-project, register/collision cases) — **extended before each WP lands, not after** | all |
| WP7 | The importer itself (IMPORTER §5 pipeline, unchanged in shape: adopt = WP1's adopt path; register = WP4 in a loop) | R7/R8 |

Sequenced so each WP leaves the system working and integration-tested; WP7 is
mostly *deleting* special cases from the drafted importer design.

---

## 6. Decision

**Recommendation: adopt C1 — refactor the mapping layer (path-as-data with
creation templates; repository as a first-class entity; git-only primaries)
before building the importer; keep the infrastructure layer untouched.**

The import requirement was the stress test, and the current design failed it
in a specific, diagnosable way: it hardcodes as *convention* (system-owned
paths, project-keyed repos) what R7/R8 reveal to be *data*. C0 would patch
that with mode switches and carry the duality forever; C2/C3 solve it by
relocating problems into worse places. C1 fixes the actual fault line, costs
a contained amount now — at the uniquely cheap moment of zero deployments —
and makes the importer an ordinary client of the resulting model rather than
a special mode grafted beside it.
