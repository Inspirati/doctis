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

- No REST or SOAP exposure of a release record.
- No "cut a release" affordance beyond the existing **Promote Draft** button, and no status gate on it: promotion is still `MANAGER` with no required document status.
- No importer mode that registers a tree as one document (`admin/import-git-repo.php` still registers files).
- No user documentation in `doc/MANUAL.md`.
- Nothing reads `{repository}.default_branch` for a source repository; the post-receive hook picks HEAD by its own heuristic.

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
*One document whose On Record advances* — built, and the pins give each
release its own immutable record anyway, so the bookkeeping of a document per
release buys little. What it does **not** give is a per-release status,
approver and revision field. Decide that when the first real release cycle
runs; nothing in the build forecloses either.

**Multiple release lines?** *Yes, one document per branch, and it works.*
`file_dwg_git_branch_tip()` makes a document's Draft follow its own
`git_branch`, so `main` and a maintenance branch can be two documents of one
repository, each advancing independently. Untried with two documents on one
repository at once — the trial used one document per repository.

**Does the pre-receive policy relax, and how far?** *Fully, per repository,
and the evidence is in §7 step 6.* `refs/doctis/*` stay unpushable, which is
the whole safety argument, and it was verified against a force-push and a
prune.

**Is a document "kind" worth it?** *Not yet.* `file_type` carries the
distinction with no schema change, and every consumer that needed to know —
download, publish, upload, move, the Git Store state — reads it through
`file_dwg_primary_is_tree()`. Revisit if a release record needs to differ in
*fields* (its own revision scheme, no classification) rather than in
behaviour; that is when a column earns its rebuild.

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

---

## 9. Log

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

---

## 10. Driving it (as built)

Enabling it for a project, start to finish:

1. **Create the repository.** No UI does this yet (§8.1); a script run as
   `www-data` per §2.1:
   ```php
   $t_repo_id = repository_id_for_project_or_create( $t_project_id );
   repository_ensure_on_disk( $t_repo_id );
   ```
2. **Turn the feature on for that project** — `$g_dwg_source_release_enabled`,
   per project, via Manage → Configuration → Configuration Report, or
   `config_set( 'dwg_source_release_enabled', ON, NO_USER, $t_project_id )`.
3. **Set the push policy** to *source repository* on Manage → **Git Store**,
   unless the repository should stay append-only.
4. **Push the project**, with a Doctis API token as the password:
   ```bash
   git remote add origin http://<user>:<token>@<host>/git/<slug>-r<id>.git
   git push -u origin main
   ```
   Reads need `$g_git_http_read_threshold` (DEVELOPER), pushes
   `$g_git_http_write_threshold` (MANAGER), on the repository's owner project.
5. **Create a document** for the release record and open it. The Primary
   Document panel offers **Register Source Release**: pick the branch, leave
   the commit blank for its tip, leave the subtree blank for the whole
   repository, and add a release note.
6. From then on: developers push, which raises a **Draft**; a manager
   **Promote Draft** to cut a release, which pins
   `refs/doctis/approved/<dwg_id>/<n>`. **Download archive** gives
   `git archive` of the On Record commit as a zip (`&format=tar.gz` for the
   other).

Issues raised against the document carry the commit they were raised at, so
`file_download.php?type=dwg_primary_at_sha` serves the release that was on
record at the time.

---

## 11. What is left to build

In the order that would matter to a first real user:

| Item | Why |
|------|-----|
| A button to create a project's repository | §8.1 — today it needs a script, which blocks step 1 above |
| `doc/MANUAL.md` section | Nothing user-facing documents this |
| Importer mode: register a tree as one document | §8, adoption; the primitive exists, the flag does not |
| Maintain `{repository}.default_branch` after a push | §8.1; the hook repairs HEAD, the row goes stale |
| Two documents on two branches of one repository | Supported but untried (§8) |
| Release notes that accumulate | §8.1; `description` is 255 chars and is replaced |
| A status gate on promotion | §8.1; decide whether a release needs an approved status |
| REST/SOAP exposure | §6.1 |
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
