# Doctis Git Content Detection — Plan

Status: **DRAFT / PLANNING** — nothing in this document is implemented yet.

Goal: when a file is **added to a Doctis-owned repository from outside
Doctis** (a `git push` through the Smart HTTP gateway or a direct server-side
push), Doctis detects it and registers it as a document — the same
registration the repository importer performs
([GIT_IMPORTER.md](GIT_IMPORTER.md)), applied continuously to a repository
Doctis already owns.

---

## 1. The Driving Test Session

A remote user cloned `example-r1`, then pushed two commits:

| Push | Change | Doctis meaning | Handled today? |
|------|--------|----------------|----------------|
| 1 | **new file** `2/foobar.txt` | An unregistered path appeared at HEAD | ✗ — invisible to Doctis. **This is the gap.** |
| 2 | **modified** `2/doctis_install_log_….html` | The registered primary file of dwg 2 advanced | ✓ — existing Draft/On-Record machinery: the view panel shows the "updated" badge; *Sync to HEAD* promotes it |

Only case 1 needs new machinery. (A third case — a registered path renamed or
deleted externally — is already *detected* per-document by
`file_dwg_git_head_info()` ["missing at HEAD"]; §6 folds it into the same
project-level surface.)

Note what makes case 1 subtler than it looks: `foobar.txt` was created
**inside `2/`**, the template directory belonging to document 2. Whether that
file is "a new document" or "clutter accidentally dropped into another
document's directory" is a policy question, not a technical one (§5).

---

## 2. Architectural Constraint — and the Honest Amendment It Needs

The doctrine ([GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md)): *control flows
Doctis → git, never git → Doctis; no post-receive hooks or webhooks pushing
state from git into Doctis.* This is what makes direct pushes safe: a push
advances the Draft only, and the DB cannot be desynchronised by git activity.

Automatic registration is, by definition, git activity producing DB state —
so this feature cannot be built without either violating the doctrine or
refining it. The plan refines it. The doctrine exists to protect two
properties, and both survive if detection obeys these rules:

1. **Additive and idempotent only.** Detection may *create* registrations for
   new paths (exactly the importer `--update` semantics: skip registered
   paths, never modify an existing record, never auto-delete, never touch an
   On-Record SHA). A scan run twice is a no-op the second time.
2. **Git never executes Doctis.** If a hook participates at all, it may only
   drop a *signal* (a marker file under Doctis's own storage root); all
   reading, deciding, and writing is done by Doctis-side code on Doctis's
   schedule. No hook invokes PHP, touches the DB, or blocks the push on
   Doctis logic.

Precedent already inside the doctrine: importer `--update` is
operator-initiated batch reconciliation, and GIT_ARCHITECTURE explicitly
blesses lazy detection "at page view / at re-import". This feature is that
same reconciliation with (a) its policy persisted, (b) a UI surface, and
(c) optionally, a cheap freshness signal.

---

## 3. Trigger Solution Space

| Option | Mechanism | Latency | Doctrine | Verdict |
|--------|-----------|---------|----------|---------|
| T1. Lazy scan at page view | Project/list page compares HEAD against last-scanned SHA; shows results | On next visit | Clean (existing precedent) | ✓ **Phase 1** — detection display |
| T2. Manual scan/register action | "Scan repository" button (MANAGER) runs detect + register synchronously | Operator click | Clean (operator-initiated) | ✓ **Phase 1** — the registration trigger |
| T3. CLI / cron | `import-git-repo.php --update` already does this; a thin `repo-sync` CLI + operator-configured cron | Minutes | Clean (operator configured the schedule) | ✓ **Phase 1** CLI exists in essence; cron is deployment guidance, not code |
| T4. Post-receive **signal** + Doctis-side drain | Hook appends one line (`<epoch> <ref> <old> <new>`) to `<bare>/doctis-pending`; Doctis processes on next page view or cron tick | Seconds–minutes | Requires §2 amendment (signal-only) | ✓ **Phase 2** — makes T1 cheap and T3 prompt; enables true auto-register mode |
| T5. Synchronous hook registration | post-receive invokes PHP, registers during the push | Immediate | ✗ Violates rule 2; heavy code in push path; failure aborts feedback mid-push; attribution/context problems | ✗ Rejected |

**Decision:** Phase 1 ships T1+T2 (+T3 documentation); Phase 2 adds T4 with a
per-repository `auto_register` policy. T4 without the hook also degrades
gracefully: scanning `HEAD != last_scanned_sha` on page view catches
everything the hook would have signalled, just later.

---

## 4. Design Overview

Three stages, mirroring the importer's Phase B but persistent and repeatable:

```
                     ┌────────────────────────────────────────────┐
  git push ─────────►│ bare repo HEAD advances                    │
                     │ (Phase 2: post-receive appends to          │
                     │  <bare>/doctis-pending — signal only)      │
                     └────────────────────────────────────────────┘
                                       │
                            DETECT (repository_sync_scan)
                     HEAD != {repository}.last_scanned_sha ?
                     git diff --name-status <last>..HEAD, filtered
                     through the repository's persisted import policy;
                     route each path to its project via root mapping
                                       │
                    ┌──────────────────┴──────────────────┐
             PRESENT (always)                      REGISTER
    "Repository changes" panel:            per candidate: create dwg via
    new candidates / modified              the shared registration library
    registered / missing-at-HEAD           (importer Phase B factored out),
    + per-file and register-all            then file_dwg_primary_register()
    actions (MANAGER)                      — pinned or draft per policy
```

### Shared registration library — refactor, not duplicate

The importer's Phase B (discovery filter, frontmatter/git-history/filename
metadata gleaning, category-from-path, `DwgAddCommand`, register, report) is
currently embedded in `admin/import-git-repo.php`. **Extract it into
`core/repository_sync_api.php`**:

| Function | Role |
|----------|------|
| `repository_sync_scan( $p_repository_id )` | Returns `{ new: [path→candidate meta], modified: [dwg_id], missing: [dwg_id] }` by diffing `last_scanned_sha..HEAD` (full `ls-tree` walk when no last SHA) against registered `git_path`s and the import policy |
| `repository_sync_register( $p_repository_id, $p_paths, $p_user_id )` | Registers the given candidate paths: metadata gleaning → `DwgAddCommand` → `file_dwg_primary_register()`; returns per-path results |
| `repository_sync_policy( $p_repository_id )` | Effective policy: committed `.doctis` manifest at HEAD wins, else stored policy row, else conservative default (§5) |

The importer becomes the first client (its Phase B collapses to
`repository_sync_register()` over the initial candidate set); the UI action,
CLI, and Phase 2 drain are the others. One code path — the same argument that
drove the C1 refactor.

---

## 5. Policy — What Qualifies for Auto-Registration

A repository now receives arbitrary pushes; "register everything new" is
wrong (build outputs, `.gitignore`, the `foobar.txt`-in-`2/` case). The
importer solved this with CLI flags; ongoing detection needs the policy
**persisted per repository**.

### Policy source, in precedence order

1. **Committed `.doctis` manifest at HEAD** (same INI format as the importer)
   — the repo self-describes; policy is versioned with content; remote users
   can see (and propose changes to) what gets registered.
2. **Stored policy on the `{repository}` row** — captured by the importer
   from its CLI flags at import time (so an imported repo keeps behaving as
   imported), editable later via manage UI (Phase 2) or CLI.
3. **Default for template-created repos** (never imported, no manifest):
   `patterns = *` is wrong; recommend **detect-and-present but never
   auto-register** — candidates appear in the panel for explicit operator
   action only.

### Policy fields (persisted)

`directories`, `patterns`, `category_from`, `frontmatter`, `filename_parse`
(all as in GIT_IMPORTER D5), plus new:

| Field | Values | Meaning |
|-------|--------|---------|
| `auto_register` | `off` (default) / `new` | `off`: detect + present only. `new`: unattended registration of qualifying new paths (Phase 2 drain / cron) |
| `register_status` | status word | Lifecycle for auto-registered docs when metadata is silent; default `pending`, **draft (unpinned)** recommended for auto mode — an unreviewed push should not mint an On-Record version |

### Routing a path to a project (monorepos)

The importer maps top-level directories to sub-projects but persists nothing;
detection must re-route new paths the same way. **Add
`root_path varchar(1024) NOT NULL DEFAULT ''` to `{project_repository}`**,
and have the importer write explicit link rows (`repo_id`, `root_path`) for
each sub-project it creates. Detection routes each candidate to the project
with the longest matching `root_path` (`''` = the owner project / whole
tree). Resolution semantics are unchanged — the rows point at the same
repository the hierarchy walk already finds; `root_path` is routing metadata,
not access control.

### The ambiguity cases — explicit rules

| Case | Rule |
|------|------|
| New file inside an existing document's template directory (`2/foobar.txt`) | Never auto-register; always present for operator decision, flagged "inside dwg 2's directory". The operator registers it as a new document (path kept verbatim) or deletes it in git. Rationale: it is equally likely to be an auxiliary file mistakenly committed beside a primary document |
| New file not matching `patterns` | Ignored by auto mode; shown collapsed in the panel ("N non-matching files") so nothing is invisible |
| New file matching patterns under a routed root | Auto-registrable (policy permitting) |
| Registered path modified | Not this feature — existing Draft machinery (badge + Sync to HEAD) |
| Registered path missing at HEAD | Presented in the panel (never auto-deleted) — same rule as importer `--update`; ties into the existing "re-point dangling path" follow-up (GIT_TODO §6) |

### Attribution

Scan-based registration attributes `creator` via git author email →
Doctis user (importer convention), acting user = the operator who clicked /
the CLI user. Phase 2: the gateway already exports `REMOTE_USER` (the
authenticated Doctis username) to `git-http-backend`, which passes it to
hooks — the post-receive signal line can carry it, letting unattended
registrations attribute the *pusher* even when the git author email matches
no account.

---

## 6. UI Surface (Phase 1)

One new page + one badge; deliberately minimal:

- **`repo_sync_page.php`** (per project, MANAGER): the "Repository changes"
  panel — three sections (new candidates with gleaned metadata preview,
  modified-registered, missing-at-HEAD), checkboxes + "Register selected" /
  "Register all", policy summary line, `last_scanned_sha` + scan timestamp.
  Scanning happens on page load (T1); registration on POST (T2, CSRF token
  `repo_sync_token`).
- **Badge**: a count chip ("3 unregistered changes") on the project's
  document list page linking to the panel — computed from the cached scan
  result (`{repository}.last_scanned_sha` + stored candidate count), not a
  fresh git walk per page view.
- Per-document view: unchanged (existing badges already cover
  modified/missing).

---

## 7. Data Model Changes

| Table | Change |
|-------|--------|
| `{repository}` | Add `last_scanned_sha varchar(40) NOT NULL DEFAULT ''`, `last_scanned int unsigned NOT NULL DEFAULT 1`, `sync_policy text NOT NULL DEFAULT ''` (INI/JSON blob mirroring §5 fields; empty = §5 default #3) |
| `{project_repository}` | Add `root_path varchar(1024) NOT NULL DEFAULT ''` (§5 routing) |

Standard flat-schema workflow: edit `admin/schema.php`, rebuild, reload
sample data. No new tables — a scan-results cache table was considered and
rejected (results are recomputable in one `git diff` + one query; caching
SHAs is enough).

---

## 8. Phase 2 — Post-Receive Signal and Auto Mode

1. **`admin/tools/git-hooks/post-receive`** (installed/refreshed by
   `repository_configure_bare()` beside the pre-receive hook): appends
   `<epoch> <remote_user> <ref> <old_sha> <new_sha>` to
   `<bare>/doctis-pending` and exits 0 unconditionally. No PHP, no DB, never
   blocks or fails the push. (Signal-only per §2 rule 2.)
2. **Drain**: `admin/repo-sync.php` CLI (cron-able) and/or an opportunistic
   drain on authenticated page loads: for each repository with a non-empty
   `doctis-pending`, run `repository_sync_scan()`; if `auto_register = new`,
   call `repository_sync_register()` for qualifying candidates (draft/pinned
   per `register_status`); truncate the spool under the storage lock.
3. **History/notification**: auto-registrations log to the document history
   as the attributed user and appear in the panel's "recently auto-registered"
   list; email notification follows whatever `email_dwg_api` does for
   document creation (verify during implementation).

Phase 2 is genuinely optional: Phase 1 alone fully covers the test session
(the operator opens the panel, sees `2/foobar.txt`, clicks register).

---

## 9. Work Packages

| WP | Content | Depends on |
|----|---------|-----------|
| S1 | Schema: `{repository}` policy/scan columns; `{project_repository}.root_path`; importer writes policy + root links at import time | — |
| S2 | `core/repository_sync_api.php`: scan / register / policy (extract importer Phase B); importer refactored onto it; `--update` behaviour unchanged (regression-test) | S1 |
| S3 | `repo_sync_page.php` + register action + list-page badge; lang strings | S2 |
| S4 | CLI `admin/repo-sync.php` (scan/register/report, `--dry-run`, per-repo or all) | S2 |
| S5 | Docs: GIT_ARCHITECTURE doctrine refinement (§2 rules), TESTING.md §5 addition | S2–S4 |
| S6 | *(Phase 2)* post-receive hook + spool drain + `auto_register` policy + attribution via `REMOTE_USER` | S2, S4 |
| S7 | Tests: extend `admin/test-git-doctis.php` — scan detects a manually pushed file; register creates dwg + pin/draft per policy; idempotent rescan; inside-existing-dwg-dir case flagged not auto-registered; missing-at-HEAD reported; (Phase 2) spool append + drain | each WP |

## 10. Open Questions

| Topic | Notes |
|-------|-------|
| Registration of **binary-heavy pushes** (CAD, PDFs at volume) | Scan is metadata-only (`ls-tree`/`diff --name-status`), so cost stays low; gleaning reads blobs only for `*.md` frontmatter. No blocker, but auto mode + huge pushes deserves a candidate-count sanity cap (e.g. warn > 100, require manual confirm) |
| Multiple branches | Scan/registration is HEAD-of-default-branch only, consistent with the whole draft model. Pushes to other branches accumulate silently until merged — document this |
| Should `auto_register = new` ever pin? | Default recommendation is draft-only for unattended registrations; pinning stays a human act. Revisit only with a concrete workflow that needs otherwise |
| `.doctis` manifest change pushed remotely | Takes effect on next scan (policy source #1). This is deliberate — and is itself an audit-visible, versioned act |
