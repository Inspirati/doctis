# Source code repositories in Doctis

**Read this first if you are starting a clean session on this feature.** It is
the design-intent record, the progress tracker, and a primer on the parts of
the existing system the feature touches. Everything below marked *verified* was
checked against the running code, not inferred.

Status: **not started. Next step is the spike in §7.**
Owner approved the direction on 2026-10-25 and said the open questions in §8
can only be answered properly after the spike.

---

## 1. The idea

Host selected software source-code projects in Doctis git repositories instead
of GitHub. The projects in question use "little more than the git backend
engine itself" — no pull requests, no GitHub Actions, no GitHub issues.

The owner's framing: an entire source tree is registered as a **single Doctis
document** — e.g. titled *"My Project Source Repository"* — where the primary
document is not a single file but the **root of a source repository**.

### 1.1 The refinement to carry forward

A document whose primary file is literally a directory produces a misleading
record: a filesize that is not one, a MIME type that is a lie, and a download
that does not work (§4.2 has the measurements).

The stronger reading, and the one to build: **a document that records a
*release* of a repository.** `{dwg_primary_file}` is already nearly that — it
holds `git_sha` (a *commit*), `git_branch`, and an approved-ref pin.
`git_path` is the only column forcing a single file. Point it at the root and
the row stops describing a file and starts describing *a repository at a
commit*.

What that earns, which "directory as document" does not:

- the document's **status workflow becomes release approval** — Draft → Under
  Review → Approved at a specific SHA, with an owner, a revision and an audit
  trail. For ISO 9001 that is a configuration-management record: *"release 2.1
  approved on 2026-11-03 by X at commit abc1234"*. This is the actual reason to
  do this in Doctis rather than GitHub.
- **promote-to-On-Record becomes "cut a release"** — the mechanism already
  exists (`file_dwg_primary_sync_head()`).
- **issues already attach to documents**, so a bug tracker against a source
  release comes free, in the same system as the QMS.
- the document's `revision` field maps to successive releases naturally.

---

## 2. Environment primer (for a cold session)

Read [CLAUDE.md](../../CLAUDE.md) first; this is the short version of what
matters here.

- Work in `/home/robert/Documents/doctis` (edit and commit). The running
  instance nginx serves is `/var/www/html/doctis`, at
  `http://10.0.0.109/doctis/`.
- **Deploy only as `hcr`**, never run git in the running clone as `robert`:
  ```bash
  cd /home/robert/Documents/doctis && git bundle create /tmp/d.bundle dev
  chmod 644 /tmp/d.bundle
  cd /tmp && sudo -u hcr -H git -C /var/www/html/doctis fetch /tmp/d.bundle dev
  sudo -u hcr -H git -C /var/www/html/doctis reset --hard FETCH_HEAD
  ```
- PHPUnit runs in the running clone as `www-data`:
  `sudo -u www-data vendor/bin/phpunit --testsuite mantis` (382 tests as of
  2026-10-25).
- **The VM is a scratchpad.** No backup before a rebuild; clean up only what
  affects later tests in the same session. Email notification is permanently
  OFF there.
- **Shared MantisBT code may be modified where justified** — minimise, do not
  avoid. See CLAUDE.md.
- Git storage: `/var/git/doctis/<slug>-r<id>.git` (bare, authoritative) and
  `/var/www/doctis/worktrees/<slug>-r<id>` (server write staging).
  `$g_git_storage_root`, `$g_git_worktree_root`.
- Current VM data: live's 21 users, project 1 `HCRQMS` with 101 documents in
  repo `hcrqms-r1` (branch `dev`), plus 18 top-level sample projects with 67
  sub-projects. `administrator`/`root` is enabled for testing.

### 2.1 Running a probe script

There is no shell on the live server, but on the VM the quickest way to
exercise the API is a CLI script run as `www-data`. It must live under a path
`www-data` can read — **not** the scratchpad:

```bash
sudo cp /path/to/probe.php /var/www/doctis/probe.php
sudo chmod 644 /var/www/doctis/probe.php
cd /tmp && sudo -u www-data php /var/www/doctis/probe.php
sudo rm -f /var/www/doctis/probe.php
```

The script needs `require_once '/var/www/html/doctis/core.php';` then
`auth_attempt_script_login( 'administrator' );` — without the login,
`history_*` and anything calling `auth_get_current_user_id()` will try to
redirect and die with `APPLICATION ERROR #18`.

**Do not buffer output into a string and echo at the end.** A fatal error
discards it, and you will think an operation failed when it succeeded — that
happened during the probing for §4.2 and left an orphaned document behind.
Echo as you go.

---

## 3. How the existing primary-document model works

### 3.1 Data

`{dwg_primary_file}` — one row per document, `UNIQUE KEY` on `dwg_id`:

| Column | Meaning |
|--------|---------|
| `dwg_id` | the document |
| `git_path` | repo-relative path, **stored, never computed at read time** |
| `git_sha` | the **On Record commit** SHA (not a blob SHA) |
| `git_branch` | default `main`; `dev` for ZIP-imported repos |
| `filename` | basename of `git_path` |
| `filesize`, `file_type` | derived at register time (§4.2 shows what a tree does to these) |
| `user_id`, `date_added`, `description` | provenance |

There is **no `project_id`** — always derive it via
`dwg_get_field( $dwg_id, 'project_id' )`.

`{dwg_primary_draft}` has the same shape and holds the *Draft* (HEAD) version
when it is ahead of On Record.

`{repository}` is a first-class entity (`core/repository_api.php`): `name`,
`slug`, `default_branch`, `owner_project_id`, `adopted_from`. Projects map to
repositories via `{project_repository}`; no link means inherit from the
nearest linked ancestor project, so a project tree shares one repository by
default. Basename is `<slug>-r<id>`; the stored `slug` is authoritative and
resolution is by the immutable `-r<id>` suffix.

### 3.2 The functions that matter

All in `core/file_dwg_api.php` unless noted:

| Function | Line | Role |
|----------|------|------|
| `file_dwg_primary_register()` | 1897 | **the single registration choke point.** Verifies `<sha>:<git_path>` exists, checks collisions, writes the row, pins the approved ref |
| `file_dwg_git_path_sanitize()` | 1752 | rejects empty paths, trailing `/`, `.`/`..` segments, control chars, >1024 bytes |
| `file_dwg_primary_build_path()` | 1790 | builds a path for a NEW document from `$g_dwg_repo_path_template` (default `{dwg_id}/{filename}`; tokens `{dwg_id}`, `{filename}`, `{category}`) |
| `file_dwg_primary_path_in_use()` | 1828 | collision check — **exact match** on `git_path`, per repository |
| `file_dwg_primary_draft_register()` | 2012 | records the Draft (HEAD) version |
| `file_dwg_primary_get_content()` | 2345 | what a download reads |
| `file_dwg_primary_sync_head()` | 2433 | **promote Draft → On Record.** This is the "cut a release" primitive |
| `GitFileStorageBackend::store()` | `core/classes/GitFileStorageBackend.class.php:132` | writes, commits, **and always pushes** |
| `GitFileStorageBackend::retrieve()` | :237 | `git show <sha>:<git_path>` |
| `repository_configure_bare()` | `core/repository_api.php:341` | installs the pre-receive hook and `http.receivepack`. Idempotent |

UI entry points: `dwg_primary_file_update.php` (upload/replace),
`dwg_primary_file_sync_head.php` (promote), `dwg_primary_file_touch.php`,
`dwg_primary_file_tag_page.php`, `dwg_revision_view_page.php`. The view-page
panel starts at `dwg_view_inc.php:1062`, gated on
`$g_dwg_primary_document_threshold`. Download is `file_download.php` with
`type=dwg_primary` (line 83) or `type=dwg_primary_head` (line 128).

### 3.3 The git HTTP gateway — already works

`core/git_http_api.php`, entry point `git_http_handle_request()` (line 117),
resolves `<slug>-r<id>.git` to a repository and authorises against that
repository's **owner project**:

- reads (`git-upload-pack`: clone/fetch) need `$g_git_http_read_threshold`
  — default **DEVELOPER**
- writes (`git-receive-pack`: push) need `$g_git_http_write_threshold`
  — default **MANAGER**

Authentication is by API token. From the gateway's own header comment:

> Pushes advance the draft (HEAD) only — the On-Record version stays pinned to
> its recorded SHA until promoted inside Doctis.

**That is already a release gate.** Development pushes move HEAD; a release is
a deliberate act inside Doctis.

---

## 4. Verified findings (2026-10-25)

### 4.1 Nothing objects to a repo full of unregistered files

HCRQMS has **152 files at HEAD and 101 registered documents**, and the Git
Store page reports "Database and disk agree". The `orphan` /
`worktree-only` statuses in `core/git_store_api.php` (line ~146) are about
**repositories**, not files. So a 5,000-file source tree with one registered
document raises no consistency warning. *Verified.*

### 4.2 A directory registers successfully today

`file_dwg_primary_register()` verifies with `git cat-file -e <sha>:<path>`,
which **succeeds for trees as well as blobs**. Registering
`system/procedures` (a directory) was accepted and produced:

```
filename   procedures
filesize   279                        <- size of the TREE OBJECT, meaningless
file_type  application/octet-stream   <- fallback, wrong
git_path   system/procedures
git_sha    83351490ce
git_branch dev
```

It also pinned `refs/doctis/approved/207/1` at that commit — an immutable,
client-unpushable ref. *That is a release tag in all but name.*

`file_dwg_primary_get_content()` returned **5 bytes reading `Array`** — the
caller mishandles a tree result, where `git show <sha>:<tree>` would otherwise
emit a directory listing rather than source.

The **repo root** (`git_path = ''`) is refused, but only by
`file_dwg_git_path_sanitize()` rejecting an empty string — nothing conceptual.

So: the **data model tolerates a tree; presentation and download do not.**

### 4.3 The pre-receive hook is the real obstacle

`admin/tools/git-hooks/pre-receive`, installed into every bare repo by
`repository_configure_bare()`, rejects:

- **ref deletions** — any branch or tag
- **non-fast-forward updates** — all force-pushes
- client writes to `refs/doctis/*`

Correct for document control, **hostile to software development**: no
rebase-and-force-push on a feature branch, no deleting merged branches (they
accumulate permanently), no moving a tag. New ref creation is allowed, since
it is fast-forward by definition.

This is installed per-repository, so relaxing it for source repos means making
the hook **policy-driven per repository** rather than editing it globally.

### 4.4 Publishing refuses these documents already

`core/dwg_publish_api.php` refuses to publish any document with a license
requirement, and the pipeline only handles `.md`/`.markdown`. So the publish
path will decline a source document without further work — convenient, but
make it decline for a *stated* reason rather than by accident.

---

## 5. Design decisions already taken

| # | Decision | Date |
|---|----------|------|
| S1 | Build the **release-record** framing (§1.1), not "a document whose file is a directory" | 2026-10-25 |
| S2 | Proceed by **spike first** (§7); the §8 questions are answered after it, not before | 2026-10-25 |

---

## 6. Progress tracker

| Item | State |
|------|-------|
| Spike (§7) | **not started** |
| `git_path = ''` (repo root) accepted by the sanitiser | not started |
| Download via `git archive` | not started |
| Suppress filesize / MIME / preview for tree-rooted documents | not started |
| Per-repository pre-receive policy | not started |
| Document "kind" flag | not started — see §8 |
| Worktree cost measurement for a large repo | not started |
| Tests in `tests/Mantis/` | not started |
| `doc/MANUAL.md` user documentation | not started |

Update this table in place as work lands, and add a dated line to §9.

---

## 7. The spike

Smallest thing that tells us whether the release-record framing fits how the
owner actually works. Throwaway code is fine; the output is a judgement, not a
feature.

1. Create a scratch project with its own repository.
2. Push a real source project into it over the Smart HTTP gateway as a
   developer would, with an API token. Note anything that fights you.
3. Register **one document** against the repo root at a commit — which needs
   `file_dwg_git_path_sanitize()` to accept `''`, the one code change the spike
   cannot avoid.
4. Make `file_download.php?type=dwg_primary` return `git archive <sha>` as a
   zip for that document.
5. Push more commits; confirm Draft (HEAD) moves while On Record stays pinned.
   Promote with `file_dwg_primary_sync_head()` and confirm
   `refs/doctis/approved/<dwg_id>/<n>` moves to the new commit.
6. Try the things a developer does and the hook forbids: force-push a branch,
   delete a merged branch. Record exactly what the failure looks like to the
   person pushing.
7. Measure: repository size on disk, worktree size, and how long a store
   operation takes with a real history.

Then answer §8 and revise §1.1 if the framing did not survive contact.

---

## 8. Open questions — answer after the spike

- **One document per release, or one document whose On Record advances?** The
  latter is less bookkeeping; the former gives each release its own record,
  status and approval. The document `revision` field suggests the latter was
  the original intent.
- **Multiple release lines?** The row pins one `git_branch`, so `main` and a
  maintenance branch would want a document each.
- **Does the pre-receive policy relax, and how far?** Per-repository flag,
  per-branch pattern (protect `main`, free rein on `feature/*`), or leave it
  strict and change the workflow instead.
- **Is a document "kind" worth it?** Attachments, preview, publishing and the
  classification/revision fields all want different treatment for a source
  release. A kind flag is a schema change to `{dwg}` and therefore a rebuild.
- **Worktree cost.** Every repo gets a checked-out worktree. Acceptable for a
  large source repo, or does the backend need a bare-only path?
- **Do issues against a source document actually get used,** or does the team
  keep issues elsewhere? This is most of the argument for Doctis over GitHub.
- **What happens on repository adoption?** `admin/import-git-repo.php` registers
  *files* as documents; a source project wants a mode that registers the tree
  as one document. Does the importer grow a flag, or is this a separate path?

---

## 9. Log

- **2026-10-25** — Idea raised and explored. §4 findings verified against the
  running VM. Owner approved the §1.1 framing and the spike-first approach.
  Probe artefacts (document 207 and its dangling
  `refs/doctis/approved/207/1`) were cleaned up; VM back to 101 documents and
  101 primary files. No feature code written.

---

## 10. Related reading

- [GIT_ARCHITECTURE.md](GIT_ARCHITECTURE.md) — the storage backend and why
  primaries are git-only
- [GIT_SOLUTION_SPACE.md](GIT_SOLUTION_SPACE.md) — design rationale for the
  git store
- [GIT_SERVER_SETUP.md](GIT_SERVER_SETUP.md) — storage roots, branch naming,
  server identity
- [GIT_IMPORTER.md](GIT_IMPORTER.md) — adopting an existing repository
- [../dwg/OWNERSHIP.md](../dwg/OWNERSHIP.md) — document owner/assignee, which a
  source release document inherits
- [../TESTING.md](../TESTING.md) — the git integration tests that must keep
  passing
