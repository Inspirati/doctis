# Source code repositories in Doctis

**Read this first if you are starting a clean session on this feature.** It is
the design-intent record, the progress tracker, and a primer on the parts of
the existing system the feature touches. Everything below marked *verified* was
checked against the running code, not inferred.

Status: **spike done; a first implementation is on branch `source-repos`,
trial-tested on the VM.** Not merged, not pushed. §7 records what the spike
found, §8 the questions it answered and the ones still open, §11 what is left
to build.

The direction was approved on 2026-10-25. No schema change was needed, so the
branch deploys to the VM or live by fetch alone — no database rebuild.

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

### 1.2 One repository, one policy — why a source project wants its own

The push policy and `$g_dwg_source_release_enabled` are both **per
repository**. A repository therefore cannot be append-only for document
control *and* rewritable for source development at the same time: those are
contradictory rules over the same refs.

A project tree shares one repository by default, so a source sub-project
inside a document project would inherit the parent's policy and force a
choice between them. What the document policy buys over the source policy is
narrow but real: an **approved** version is pinned under both and survives
anything, but an **unpromoted Draft** is only reachable from the branch, so
under the source policy a force-push can destroy it.

The answer is to give the source project **its own repository** — a
first-class capability of `{project_repository}`, now reachable from Manage
Project → *Repository* (§10). Verified 2026-10-25: parent on
`document`, sub-project on `source`, separate bare repos, documents resolving
to the right one, and the source release archive containing no documents.

#### Which repository a project uses: default, and override

Both are possible, and the distinction caused real confusion, so plainly:

- **By default a project tree shares one repository.** A project with no
  `{project_repository}` row inherits by walking up to the nearest ancestor
  that has one; with none anywhere, one is created at the **top-level**
  project. So a sub-project is *not* separate unless it is made separate.
- **An explicit link overrides that**, and a sub-project with its own
  repository then becomes the inheritance root for *its own* descendants.

Measured three levels deep:

| project | own link | resolves to |
|---|---|---|
| Split Trial | 15 | `split-trial-r15` |
| → Split Trial Firmware | 16 | `split-trial-firmware-r16` |
| → Split Trial Tools | 17 | `split-trial-tools-r17` |
| → → Split Trial Firmware Bootloader | — | `split-trial-firmware-r16` *(its parent's, not the grandparent's)* |

Before 2026-10-25 nothing outside the repository importer created that link,
so in practice it was always "inherit". **Manage Projects** now carries a
*Repository* column showing, for every project and sub-project, the policy of
the repository it resolves to, a *releases* marker when
`$g_dwg_source_release_enabled` is on for it, and the repository's name,
italicised when inherited.

**Git submodules are not an answer.** `git archive` does not recurse into
them: a release archive would contain the submodule's directory *empty*, so
the approved artefact would be silently incomplete. (Doctis also refuses to
register one — a gitlink is `160000 commit`, which
`file_dwg_git_object_type()` reports as neither blob nor tree.)

#### What a new project defaults to, and how to change it

Creating a project asks nothing about repositories, and should not: a project
may legitimately never have one — a pure index of documents held elsewhere
needs no git store, and a repository that is never used is a directory nobody
asked for. So a new project starts with **no repository at all**.

| | |
|---|---|
| At creation | no repository; `repository_id_for_project()` returns 0 |
| First time a document file is stored | a repository is created **at the top-level project** of the tree (`repository_id_for_project_or_create()`, from `GitFileStorageBackend::ensure_project_repo()`) and the whole tree shares it |
| Its push policy | **document control** — `repository_configure_bare()` seeds `doctis.pushpolicy` only when unset, so an existing repository is never silently re-imposed on |
| Source releases | **off** — `$g_dwg_source_release_enabled` defaults OFF and is per project |

So the default for everything is the document-control behaviour Doctis had
before this feature existed, and a project becomes a source project only by
someone saying so.

**Changing it afterwards** is all on **Manage Project → Repository**:

- *Give this project its own repository* — needed before the push policy can
  differ from its parent's.
- *Push policy* — editable on the project that **owns** the repository, and
  by an administrator only, because it decides whether released history can
  be rewritten. On a project that inherits, it is shown read-only and names
  the project to change it on. A shared repository says how many projects
  share it and warns that a change applies to all of them.
- *Source releases* — per project, at the project-management threshold.

Manage → **Git Store** keeps its own policy control for the
repository-centric, administrator view; Manage Project is the project-centric
one. They write the same `doctis.pushpolicy`.

**The one trap.** A sub-project cannot be given its own repository once it
already stores document files in the one it inherits — relinking would leave
them pointing into a repository the project no longer resolves to, so the
button refuses and says so. **Decide a source sub-project's repository before
anyone uploads a document to it.** Moving a project's worth of documents
between repositories is not built (§11).

### 1.3 What is registered, and what is not (owner, 2026-10-26)

Doctis is a **document control system** that can also host source trees. It
is not becoming a forge. The consequence is a rule worth stating plainly,
because it decides a lot:

> In a source project, **no individual source file is a registered Doctis
> item.** The registered items are **releases and/or branches**.

A repository may hold ten thousand files and three registered documents, and
nothing objects (§4.1). Issues, approval, ownership, history and the
documents list all operate on the release or the line — never on
`src/main.c`.

It follows that making a project a source project is an **explicit act**, not
something inferred (§1.2), and that the trap in §1.2 — the decision must
precede the first upload — is acceptable rather than regrettable.

#### Branches are not all temporary

The common git pattern is a feature branch that exists until it merges. Some
repositories instead host **permanent branches**: a maintenance line for a
shipped version, a per-customer line, a long-lived integration line. Those
deserve records of their own.

**A branch record works today.** A tree-rooted document carries `git_branch`
and follows that branch's tip, so one document per line is already the
model, verified 2026-10-26 with three lines of one repository:

| document | branch | commit on `v1-maintenance` | commit on `main` |
|---|---|---|---|
| Firmware, main line | `main` | — | Draft |
| Firmware, v1 maintenance line | `v1-maintenance` | Draft | — |
| Firmware, Acme customer line | `customer-acme` | — | — |

Each line's Draft moves only for its own commits.

**A deleted branch strands its record**, rather than silently following
another line — fixed 2026-10-26 after the probe caught it doing exactly
that. `file_dwg_git_head_info()` reports `branch_missing`, offers no Draft,
and promotion is refused; the version on record is untouched. Only reachable
under the `source` push policy, which is the one that permits deletion.

#### Open: branch record and release record are the same shape

A branch record and a release record are both *(path, branch, commit)* rows.
What should differ is whether **On Record advances**: a line goes on, a
release is frozen. Today every tree-rooted document advances, so there is no
way to say "this one is finished".

The two-tier reading, which reconciles this with the owner's preference for
one document per release (§8.3):

- a **branch record** is the living line — permanent, owned, never frozen;
- a **release record** is cut from it, frozen at a commit, with its own
  status and approval cycle.

**Settled 2026-10-26** by the owner, who cleared a schema change for it:
`{dwg}.kind` says which of the two it is and `{dwg}.frozen` says whether it
tracks, as §3.1 tabulates. Not the status-threshold alternative floated
first — `status` would then have meant both *where in the approval workflow*
and *is this frozen*, which is the same overloading that CLAUDE.md records as
a defect in `{license_user_list}.status`.

The registration page offers the choice directly — **Tracking line** or
**Frozen release** — so the two-tier model is usable without waiting for a
*Cut release* action. Verified: after a commit following a release, the line
offered the new commit as a Draft while the release offered nothing, showed
no promote button, and refused promotion.

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
- **The spike's own artefacts are still there** (see §7): projects 254
  `Source Trial` (repo `source-trial-r2`, document 209, HCR-Hardware-Designs)
  and 255 `Source Trial Large` (repo `source-trial-large-r3`, document 210,
  the Doctis repository). Both have `$g_dwg_source_release_enabled` ON and the
  `source` push policy. A rebuild clears them; §10 says how to recreate one.

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

**What `git_path` names decides everything.** It is a repo-relative path, and
git resolves it to either a blob or a tree. For one repository at one commit:

| `git_path` | git object | a download gives you |
|---|---|---|
| `docs/handbook.md` | blob | that one file |
| empty | tree - the repository root | an archive of every file |
| `src` | tree - a **subtree** | an archive of everything under `src/` |
| `src/lib` | tree - a deeper subtree | an archive of everything under `src/lib/` |

"**Rooted at a subtree**" throughout this document means the third or fourth
row: the record covers *part* of the repository. It earns its keep in two
cases - a mixed repository, where rooting at `src/` keeps document commits
from moving the release and keeps the documents out of the archive (§8.2),
and a monorepo whose `firmware/`, `tools/` and `docs-site/` are released
independently. **For a source project with its own repository the root is
the right choice**, and a subtree is the exception.

`{dwg}.kind` and `{dwg}.frozen` say what the record *is*:

| | `kind` | `frozen` | behaviour |
|---|---|---|---|
| Document | `DWG_KIND_DOCUMENT` | - | primary is a blob; the original behaviour |
| Source **line** | `DWG_KIND_SOURCE` | `0` | follows its branch's tip; every push offers a Draft |
| Source **release** | `DWG_KIND_SOURCE` | `1` | pinned at its commit; no Draft, cannot be promoted |

`kind` is written by `file_dwg_primary_register()` from the object it actually
registered, so it cannot disagree with what is stored. `frozen` means nothing
for a document kind, and `dwg_is_frozen()` reports false for one whatever the
column holds, so a "frozen document" cannot reach a caller.

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

## 4. Verified findings — the state *before* the spike (2026-10-25)

Kept as the baseline the implementation was measured against. **§6 and §7
supersede every "obstacle" below**; each one says where.

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

*Addressed.* §7 step 3 has the same measurement after the change.

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

*Addressed* — that is what was built (S4, S5). §7 step 6 has the verbatim
refusals under each policy, and the demonstration that an approved release
survives a force-push past it.

### 4.4 Publishing refuses these documents already

`core/dwg_publish_api.php` refuses to publish any document with a license
requirement, and the pipeline only handles `.md`/`.markdown`. So the publish
path will decline a source document without further work — convenient, but
make it decline for a *stated* reason rather than by accident.

*Addressed.* `dwg_publish_refusal()` now answers "a source release records a
repository, not a page" before it looks at anything else.

---

## 5. Design decisions already taken

| # | Decision | Date |
|---|----------|------|
| S1 | Build the **release-record** framing (§1.1), not "a document whose file is a directory" | 2026-10-25 |
| S2 | Proceed by **spike first**; the §8 questions are answered after it, not before — done, §7 | 2026-10-25 |
| S3 | A release record is marked by `{dwg_primary_file}.file_type = 'inode/directory'` (`DWG_TREE_MIME_TYPE`), **not** by a new `kind` column — no schema change, so no rebuild | 2026-10-25 |
| S4 | The push policy is **fully relaxed** in `source` mode: force-push and ref deletion allowed on every ref, because the approved pin protects the record (§7, step 6) | 2026-10-25, owner |
| S5 | The policy lives in the **bare repo's own git config** (`doctis.pushpolicy`), set on Manage → Git Store, because the hook can only read its own repository | 2026-10-25 |
| S6 | A release record is **off by default**: `$g_dwg_source_release_enabled`, per-project, so only the projects holding source repositories gain it | 2026-10-25 |

---

## 6. What was built

Branch `source-repos`, off `dev` at e99b10496. No schema change.

| Area | Change |
|------|--------|
| Marker | `DWG_TREE_MIME_TYPE` in `core/constant_inc.php`; `file_dwg_primary_is_tree()` |
| Root path | `file_dwg_git_path_sanitize( $path, $allow_root = false )` — `''` accepted only on request, so a file document still cannot have an empty path |
| Registration | `file_dwg_primary_register()` reads the object type with `git cat-file -t`, refuses a tree unless asked, forces `filesize = 0`, names the row after the repository slug, and takes its new arguments as an options array (`allow_tree`, `branch`) |
| Draft | `file_dwg_primary_draft_register()` likewise; `file_dwg_git_head_info()` compares tree objects and spells the empty pathspec `.` |
| Release line | `file_dwg_git_branch_tip()` — a document's Draft follows its own `git_branch`, falling back to HEAD |
| Download | `file_dwg_source_release_stream()` streams `git archive` (zip or `tar.gz`) with `passthru()`; wired into all three `file_download.php` primary routes. `GitFileStorageBackend::retrieve()` refuses tree rows |
| Refusals | Upload onto a release, publishing, and `file_dwg_primary_move_prepare()` all decline, each for a stated reason |
| Push policy | `admin/tools/git-hooks/pre-receive` reads `doctis.pushpolicy`; `repository_get_push_policy()` / `repository_set_push_policy()`; control on Manage → Git Store |
| HEAD repair | New `admin/tools/git-hooks/post-receive`, installed beside `pre-receive` by `repository_configure_bare()` |
| UI | `dwg_source_release_page.php` (branch, commit, subtree, note); the Primary Document panel becomes "Source Release" — no byte count, archive download, `whole repository` marker |
| Git Store | A repo-rooted document's On Record state comes from `git_store_tree_state()` (tree object comparison), not from the blob listing |
| Config | `$g_dwg_source_release_enabled` (OFF), `$g_dwg_source_release_archive_format` (`zip`) |
| Server | `client_max_body_size 0` in the `/git/` location — see §7 step 2 |
| Tests | Steps 14–15 of `admin/test-git-doctis.php`, 35 assertions (102 → 137) |

### 6.1 Not built

- No endpoint that *registers* a release over REST or SOAP, though `kind` and
  `frozen` now travel in the document object.
- No status gate on promotion or on cutting a release: both are `MANAGER`
  with no required document status.
- No importer mode that registers a tree as one document
  (`admin/import-git-repo.php` still registers files).

---

## 7. The spike — what it found

Run on the VM on 2026-10-25 against **HCR-Hardware-Designs** (27 commits,
22 MiB packed) and, for scale, the **Doctis repository itself** (14,781
commits, 82 MiB packed). Scratch projects `Source Trial` (254, repo
`source-trial-r2`, document 209) and `Source Trial Large` (255, repo
`source-trial-large-r3`, document 210) are **still on the VM** for inspection.

**1. Scratch project and repository.** Fine. A project created with no
repository gets one from `repository_id_for_project_or_create()` +
`repository_ensure_on_disk()`. There is no UI for that step — the repository
is otherwise created on first upload, which a source project never does. A
release record therefore needs the repository to exist first, and today only a
script creates it. *Worth a button.*

**2. Push over the Smart HTTP gateway.** Works, with two obstacles found.

- **HTTP 413.** `client_max_body_size 64M` rejected the 82 MiB Doctis push:
  `error: RPC failed; HTTP 413`. A git push sends the whole packfile as one
  request body, so this is not an edge case — it is every real repository.
  Fixed with `client_max_body_size 0` inside the `/git/` location, in the
  running site and in `admin/tools/templates/nginx-doctis.conf`;
  `admin/tools/git-serve.conf` carries the Apache equivalent
  (`LimitRequestBody 0`). **Live's front proxy will need the same.**
- **Dangling HEAD.** Doctis creates an empty bare repo with HEAD naming
  `refs/heads/main`. Push a project whose branch is called something else —
  Doctis's own `dev` — and HEAD never resolves. `git clone` then warns
  "remote HEAD refers to nonexistent ref" and checks out **nothing**, and
  Doctis cannot register a release or track a Draft at all. Fixed by the new
  post-receive hook; the developer sees
  `remote: doctis: repository HEAD set to dev`. This was a latent defect in
  the repository lifecycle, not a source-repository one.

The gateway's URL is `http://<host>/git/<slug>-r<id>.git` — **not** under
`/doctis/`. Authentication is the Doctis username and an API token as the
password.

**3. Register one document against the repo root.** Works. Compare with the
pre-spike measurement in §4.2:

| | before (§4.2) | now |
|---|---|---|
| `filename` | `procedures` | `source-trial` (the repository slug) |
| `filesize` | `279` (the tree object) | `0` |
| `file_type` | `application/octet-stream` | `inode/directory` |
| `git_path` | `system/procedures` | `''` (the repository root) |
| download | 5 bytes reading `Array` | a 28 MB zip of 336 files |

**4. Download.** `git archive` streamed through `passthru()`: 28 MB zip in
1.2 s, `tar.gz` likewise, both unpacking to a `<slug>-<sha8>/` prefix. Nothing
is buffered, so repository size does not meet PHP's memory limit.

**5. Draft and promotion.** Exactly the existing mechanism, and it needed no
change beyond tree comparison. A developer push moved the Draft to `ffbf4825`
while On Record stayed at `e393897c`; **Promote Draft** cut release 2 and left
both pins in place:

```
refs/doctis/approved/209/1 -> e393897c   release 1
refs/doctis/approved/209/2 -> ffbf4825   release 2
```

`{dwg_history}` records each promotion. **The release history is the pins**,
and it is already an ISO 9001 configuration record.

**6. The things a developer does that the hook forbade.** Under the new
`source` policy all four work: create a branch, rebase and force-push it,
delete it when merged, and force-push `main`. Under `document` they fail like
this, verbatim:

```
remote: doctis: non-fast-forward update of refs/heads/feature/trial2 rejected (force-push / history rewrite is not permitted)
remote: doctis: deleting refs/heads/feature/trial2 is not permitted
remote: doctis: refs/doctis/approved/209/99 is server-managed and cannot be pushed
```

The third is refused under **both** policies, and that is what makes the
relaxation safe. Demonstrated, not argued: `main` was force-rewound past the
On Record commit and `git gc --prune=now` run, after which the commit was
still present and the document still downloaded its 28 MB archive intact,
because `refs/doctis/approved/209/2` keeps it reachable.

**7. Cost.** Measured on the VM.

| | HCR-Hardware-Designs | Doctis (worst case) |
|---|---|---|
| commits | 27 | 14,781 |
| bare repo on disk | 38 MB | 81 MB |
| **server worktree** | **124 KB** | **124 KB** |
| push through the gateway | 1.8 s | 3.2 s |
| `file_dwg_primary_register()` | — | 0.034 s |
| `file_dwg_git_head_info()` | — | 0.018 s |
| `git archive` of the whole tree | 1.2 s (28 MB) | 0.35 s (6.1 MB) |

The worktree figure is the interesting one, and it answers §8: **a source
repository's server worktree is never populated.** It is cloned when the bare
repo is still empty and only a Doctis-side `store()` ever syncs it — which a
release record refuses. A source repository costs the bare repo and nothing
else. (A repository *adopted* from a source with content is different: that
one is cloned with a full checkout.)

Every operation is proportional to the tree, not the history: a 14,781-commit
repository registers in 34 ms.

---

## 8. The open questions, answered

**One document per release, or one document whose On Record advances?**
*One document whose On Record advances* is what is built. **The owner is
leaning to one document per release** (2026-10-25), so that the documents
list shows every past release with its own approval cycle. §8.3 measures what
that needs; the path-uniqueness obstacle has been removed, two remain.

**Multiple release lines?** *Yes, since the uniqueness exemption.* The claim
here was wrong twice on 2026-10-25 — first "it works", then, after testing,
"it does not" — so: `file_dwg_primary_path_in_use()` used to refuse a second
document at a path another held, which blocked a maintenance line on the same
subtree. That check guards *writes* (`store()` commits the upload at
`git_path`), and a release never writes, so **tree rows are now exempt** and
two documents may record one subtree on two branches,
`file_dwg_git_branch_tip()` giving each its own Draft. Still untested with
two live branches at once.

**Several documents on one repository?** *Yes — see §8.2, verified.*

**Does the pre-receive policy relax, and how far?** *Fully, per repository,
and the evidence is in §7 step 6.* `refs/doctis/*` stay unpushable, which is
the whole safety argument, and it was verified against a force-push and a
prune.

**Is a document "kind" worth it?** *Yes - built 2026-10-26*, once the owner
cleared a schema change. `{dwg}.kind` and `{dwg}.frozen` replace the
`file_type = 'inode/directory'` sentinel as the thing behaviour reads;
`file_type` keeps the value for display and Content-Type but nothing branches
on it. §3.1 and §1.3.

**Worktree cost.** *Not a problem; no bare-only path needed.* §7 step 7.

**Do issues against a source document actually get used?** **Still open** —
the only question the spike could not answer, because it needs the team, not
the code. Issues attach to document 209 today with no further work.

**What happens on repository adoption?** **Still open.**
`admin/import-git-repo.php` registers files as documents; a source project
wants a mode that registers the tree as one. The pieces now exist
(`file_dwg_primary_register()` with `allow_tree`), so it is a flag on the
importer rather than a separate path — but it is not written.

### 8.1 New questions the spike raised

- **Creating the repository needs a script.** A source project's repository
  must exist before anyone can push, and nothing in the UI creates one
  (ordinarily it appears on first upload). Manage Project, or the Git Store
  page, should be able to create an empty repository for a project.
- **Should promotion require a status?** §1.1's framing is Draft → Under
  Review → Approved, but promotion is only gated on `MANAGER`. Tying it to
  `$g_dwg_status_enum_string` would make the status mean something for a
  release.
- **`{repository}.default_branch` is not maintained** for a pushed-into
  repository: it stays `main` while the branch is `dev`. The post-receive
  hook repairs HEAD on disk but nothing updates the row.
- **The release note is the only per-release text.** `description` on
  `{dwg_primary_file}` holds it, 255 characters, and it is replaced on
  promotion rather than accumulating. A real changelog wants somewhere else
  to live.

### 8.2 What several documents on one repository mean (verified 2026-10-25)

Probed in project `Mix Trial` (513) with sub-project `Mix Trial Firmware`
(514), sharing repository 14, holding `docs/handbook.md`, `src/app/main.c`
and `src/lib/util.c`.

| Pattern | Works | Notes |
|---|---|---|
| A file document **and** a source release, in one project | **yes** | Drafts are independent: a commit to `docs/handbook.md` raised a Draft on the handbook document only; a commit to `src/app/main.c` on the `src/` release only |
| One release per **component** (subtree) of a monorepo | **yes** | `src/` and `src/lib/` as two documents. Nesting behaves: a commit to `src/lib/util.c` raised a Draft on **both**, which is right — `src` contains `src/lib` |
| A release owned by a **sub-project** | **yes** | Document 396 belongs to project 514 and records `src/lib` of the parent's repository |
| One document per **release** | n/a | Releases are versions of one document; the `refs/doctis/approved/*` pins are the release history |
| One document per **branch** (release line) | **yes**, since the uniqueness exemption (§8); untested with two live branches |
| One document per **release**, same path, successive commits | **yes** — see §8.3 for the two things it still needs |

**Design rule for a mixed project: root the release at a subtree, never at
the repository root.** A root-rooted release in a project that also holds
documents is wrong twice over, both measured:

- its Draft moves on **every** commit, including one that touches only a
  Doctis-managed document file — because the root tree changes;
- its archive **contains those documents** (`docs/handbook.md` was in the zip).

A repository root is the right choice only when the repository holds nothing
but the source project, as in the `Source Trial` projects of §7.

### 8.3 One document per release — what it still needs (measured 2026-10-25)

Prototyped in project 516 (`Split Trial Firmware`): three documents, "Firmware
release v1.1/1.2/1.3", each registered against subtree `src` at a different
commit.

**Works already.** All three registered, each archives its own content
(`return 1`, `return 2`, `return 3`), and each carries its own approval
record:

```
refs/doctis/approved/401/1 -> 8e4540c   v1.1
refs/doctis/approved/402/1 -> 4fb20a2   v1.2
refs/doctis/approved/403/1 -> 9908e1f   v1.3
```

Each is an ordinary document, so it already has its own status, owner,
revision field, history and issues — which is the whole appeal of the option.

**Two things to fix, both measured.**

1. ~~**A superseded release reports a Draft for ever.**~~ **Fixed 2026-10-26**
   by `{dwg}.frozen`: a release registered as frozen offers no Draft, shows
   no promote button and refuses promotion, however far its branch moves on
   (§1.3).

2. **Manage → Git Store lists one release, not three.**
   `git_store_documents()` keys its array by `git_path`, so the three `src`
   rows collapse to the last. Measured: *"documents listed: 2"* for a
   repository holding four registered documents. Re-keying it is the bulk of
   the work, because seven places in `manage_git_store_page.php` index that
   array by path, including the unregistered-files diff.

**And one thing to build:** a *Cut release* action that creates the next
document (copying title, category, owner and bumping `revision`) and
registers it at the chosen commit, in place of **Promote Draft**. Without it
each release is a hand-made document.

**Against keeping "On Record advances":** less to build and less to list. But
the release history then lives only in refs and `{dwg_history}`, which the
documents list does not show, and a release cannot be approved by a different
person from the one before it.

---

## 9. Log

- **2026-10-26** — Owner corrected the `frozen` invariant: it is a valid state
  for an ordinary document too, which lets a released document be tracked
  directly. Done, and it removed a special case rather than adding one —
  `frozen` now means the same for both kinds, with a Freeze / Unfreeze
  control. Owner also confirmed that an external clone behaving like a normal
  git repository, and adopting an existing local repository by repointing its
  upstream, are already how it works; both are now in `doc/MANUAL.md` §13.
  Then the remaining work: the `kind` column and `DwgKindFilter`, the *Cut
  release* action, `git_store_documents_list()` (the Documents tab reported 2
  records for a repository holding 4), `repository_default_branch()` upkeep,
  and the manual. Verifying Cut release turned up a **pre-existing defect**:
  `dwg_create.php` passed a hardcoded `"sample summary"` and
  `DwgData::create()` never persisted the column, so every document created
  through the web form or the REST API was nameless wherever
  `dwg_format_summary()` is used — a page title of `0259: `. Both fixed.
  `test-git-doctis.php` 165 → 176.

- **2026-10-26** — Owner asked what "subtree" meant, which this document had
  used throughout without defining; §3.1 now does, with the blob/tree table.
  Owner also cleared schema changes for the development phase, which settled
  §1.3: `{dwg}.kind` and `{dwg}.frozen`, two columns as the owner chose, in
  place of the `file_type` sentinel and in place of the status-threshold
  alternative — `status` would otherwise have carried two meanings, the
  mistake CLAUDE.md records in `{license_user_list}.status`. The meaningless
  "frozen document" is prevented rather than represented. Registration now
  offers **Tracking line** or **Frozen release**, so the two-tier model works
  without a *Cut release* action. Database rebuilt (schema version 77);
  HCRQMS deliberately not reloaded, and the earlier trial projects are gone.
  Replaced with `Engineering` / `Flight Controller Firmware`, which holds a
  frozen v1.0 release and a tracking main line. `test-git-doctis.php`
  152 → 165, `test-git-php.php` 20/20, PHPUnit 382/382.

- **2026-10-25** — Idea raised and explored. §4 findings verified against the
  running VM. Owner approved the §1.1 framing and the spike-first approach.
  Probe artefacts (document 207 and its dangling
  `refs/doctis/approved/207/1`) were cleaned up; VM back to 101 documents and
  101 primary files. No feature code written.
- **2026-10-25** — Spike run and first implementation written, on branch
  `source-repos` (4 commits off `dev` e99b10496, unmerged, unpushed). Owner
  chose the fully-relaxed `source` push policy (S4) and HCR-Hardware-Designs
  as the trial payload. §6 lists what was built, §7 what the spike found, §8
  the questions it answered. Two latent defects found and fixed on the way:
  the gateway's request-body limit (HTTP 413 on any real push) and a dangling
  bare-repo HEAD (a clone that checks out nothing). Tests: `test-git-doctis.php`
  102 → 137, `test-git-php.php` 20/20, PHPUnit `mantis` 382/382. Trial
  projects 254 and 255 and documents 209 and 210 left on the VM.
- **2026-10-26** — Owner framed the system rule in §1.3: Doctis is a document
  control system that can host source trees, and in a source project **no
  individual source file is a registered item** — the registered items are
  releases and/or branches. Confirmed the explicit-act trap in §1.2 is
  acceptable. Raised permanent branches. Probed with three lives lines of one
  repository: a document per line works and each Draft moves only for its own
  commits. The probe also caught a defect in this session's own code — a
  record whose branch had been deleted fell back to the repository HEAD and
  offered main's tip for promotion on to another line's record. Fixed:
  `branch_missing`, no Draft, promotion refused. `test-git-doctis.php`
  141 → 152. Left open in §1.3: branch record and release record are the same
  shape, and nothing yet says which is frozen.
- **2026-10-26** — Owner asked what a newly created project defaults to and
  how its type is changed afterwards, having found the setting only on
  Manage → Git Store. The gap was real: the push policy lived on an
  administrator's repository-centric screen and
  `$g_dwg_source_release_enabled` had **no UI at all** — read in three
  places, set in none, so enabling it meant the raw Configuration Report.
  Manage Project → *Repository* is now the one place for all three settings;
  `manage_proj_repo_create.php` folded into `manage_proj_repo_update.php`.
  Defaults documented in §1.2: no repository at creation, created at the
  top-level project on first document file, `document` policy, releases off.
  Lifecycle verified end to end on a fresh project (783): default → give it
  its own repository → switch to source, with the policy reaching disk.
- **2026-10-25** — Owner pointed out that the per-repository push policy and
  a mixed project are in conflict: one repository cannot run both sets of
  rules. Correct, and §8.2's "use a subtree" answer did not address it. §1.2
  added; the separation was verified (parent `document`, sub-project
  `source`, separate repos, correct resolution, no documents in the source
  archive), and the missing piece — a way to give a sub-project its own
  repository — was **built** as Manage Project → *Repository*. Submodules
  measured and rejected: `git archive` leaves a submodule's directory empty.
  Owner leaning to one document per release, so that was prototyped: it
  works, and the path-uniqueness check that blocked it was removed as a
  correctness fix (it guards writes, and a release never writes). §8.3
  records the two things it still needs. `test-git-doctis.php` 137 → 141.
  Probe projects 515-517 left on the VM.
- **2026-10-25** — Owner asked what happens in a project holding both
  documents and source, whether sub-projects get their own repository, and
  what several documents on one repository would mean. Probed in project
  `Mix Trial` (513/514, repository 14), also left on the VM; results in §8.2,
  which adds the design rule *in a mixed project, root the release at a
  subtree, never at the repository root*. The probe disproved this document's
  own claim that per-branch release lines work: they are refused by the
  path-uniqueness check. §8 and §11 corrected.

---

## 10. Driving it (as built)

Enabling it for a project, start to finish:

1. **Manage Project → Repository**, all three settings in one place:
   *Give this project its own repository* (needed before its push policy can
   differ from its parent's; refused once it already stores documents in the
   inherited one), then *Push policy* → **source repository** and tick
   *Source releases*. The policy needs an administrator.
2. **Push the project**, with a Doctis API token as the password:
   ```bash
   git remote add origin http://<user>:<token>@<host>/git/<slug>-r<id>.git
   git push -u origin main
   ```
   Reads need `$g_git_http_read_threshold` (DEVELOPER), pushes
   `$g_git_http_write_threshold` (MANAGER), on the repository's owner project.
3. **Create a document** for the release record and open it. The Primary
   Document panel offers **Register Source Release**: pick the branch, leave
   the commit blank for its tip, leave the subtree blank for the whole
   repository, and add a release note.
4. From then on: developers push, which raises a **Draft**; a manager
   **Promote Draft** to cut a release, which pins
   `refs/doctis/approved/<dwg_id>/<n>`. **Download archive** gives
   `git archive` of the On Record commit as a zip (`&format=tar.gz` for the
   other).

Issues raised against the document carry the commit they were raised at, so
`file_download.php?type=dwg_primary_at_sha` serves the release that was on
record at the time.

---

## 11. What is left to build

**Done since the first pass** — a *Cut release* action, the `kind` column and
filter, the flat Git Store document list, `{repository}.default_branch`
upkeep, freezing, and `doc/MANUAL.md` §13. What remains, in the order that
would matter to a first real user:

| Item | Why |
|------|-----|
| Importer mode: register a tree as one document | §8, adoption; the primitive exists, the flag does not |
| Moving existing documents between repositories | §1.2 — a project that already stores documents in the repository it inherits cannot be given its own until they move; `file_dwg_primary_move_prepare()` moves one document, nothing moves a project's worth |
| Release notes that accumulate | §8.1; `description` is 255 chars and is replaced |
| A status gate on promotion | §8.1; decide whether a release needs an approved status |
| REST/SOAP exposure of `kind`, `frozen` and release records | `DwgData` carries the two columns, so they appear in the SOAP/REST document already; nothing *acts* on them there and no endpoint registers a release |
| Live front-proxy body limit | §7 step 2 — needed before any push to live |

---
## 12. Related reading

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
