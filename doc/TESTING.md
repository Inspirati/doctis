# Doctis Testing Guide

This document indexes every test method available in the Doctis codebase:
what each one covers, when to reach for it, and how to run it. It is a map,
not a tutorial — each section links to the authoritative source (script
`--help`, source file, or a design doc) for full detail.

All commands run **locally on the native development VM** (`10.0.0.94`),
whose nginx serves the running clone at `/var/www/html/doctis` as
`http://10.0.0.94/doctis/`. See [CLAUDE.md](../CLAUDE.md)'s Development
Environment section and [DEV-SETUP.md](../DEV-SETUP.md). Deploy a change to
the running clone before testing it. The former vaio host is no longer the
test environment; older run records below that mention it are history.

The VM holds persistent data (imported projects and the HCR571 pilot), and its
outbound email is live. Use throwaway projects for anything destructive, and
turn email notifications off before tests that queue mail in bulk.

---

## 1. Overview — which test method for which change

| You changed... | Use |
|---|---|
| PHP core/API logic with no git or SOAP surface | §2 PHPUnit |
| SOAP endpoint (dwg, primary file, attachment) | §3 `doctis-soap-test.sh` |
| Git storage backend / repository resolution / path handling | §4 `test-git-php.php` + `test-git-doctis.php` |
| Repository import (`admin/import-git-repo.php`) | §5 Import dry-run/real-run against a scratch repo |
| Smart HTTP gateway (clone/push, auth, hook enforcement) | §6 remote clone/push script, or §4's hook-enforcement steps |
| A page, form, or workflow with no good headless test | §7 curl-based testing |
| AI Meeting Assistant, meetings, My Meetings, minutes approval | §7a meeting end-to-end |
| Documentation Topics (catalogues, tags, applicability, Topics page) | §2 `TopicApiTest` + §7b |
| Anything touching schema | §8: upgrade (new table) or rebuild (changed table) first, then re-run the relevant suite above |
| You aren't sure what else broke | §9 full regression sweep |

---

## 2. PHPUnit (`tests/`)

**What it covers:** MantisBT core API unit tests and the Doctis tests in
`tests/Mantis/` (`Topic*Test`, `MeetingApiTest`, `AiKnowledgeTest` and
others), plus SOAP integration tests (`tests/soap/`) via PHP's SoapClient.
New document-domain unit tests belong in `tests/Mantis/`.

**Setup (one-time, already done on the VM):** in the running clone,
`composer install`, then `cp tests/bootstrap.php.sample tests/bootstrap.php`
and set `MANTIS_TESTSUITE_SOAP_HOST`, `USERNAME` and `PASSWORD`. The VM's
`tests/bootstrap.php` (gitignored) points SOAP at
`http://10.0.0.94/doctis/api/soap/mantisconnect.php?wsdl` as administrator.

**Run** in the running clone, after deploying the change:
```bash
cd /var/www/html/doctis
sudo -u www-data vendor/bin/phpunit --testsuite mantis     # 276 tests, 2026-10-13
vendor/bin/phpunit --testsuite mantis --filter Topic       # a filtered run also works as robert
```

Config: [phpunit.xml](../phpunit.xml) — three suites (`mantis`, `soap`,
`mantis core formatting`). A `rest` suite exists but is commented out: its
tests target stock MantisBT REST (`MANTIS_TESTSUITE_REST_HOST`), not the
Doctis `documents`, `licenses` and `topics` routes.

---

## 3. SOAP endpoint smoke test — `doctis-soap-test.sh`

**What it covers:** all 13 Doctis-specific SOAP operations end-to-end against
a *live* instance: status enum, document fetch, primary-file lifecycle
(upload → metadata → download → delete), attachment lifecycle. This is the
fastest way to confirm the whole PHP → SOAP → git/DB stack is wired correctly
after a change, without a browser.

**Properties:** non-fatal (later steps run even if earlier ones fail so you
get a full picture), idempotent (cleans up leftover state from a prior run at
the start). The target `dwg_id` is required and must be a **throwaway
document** in a real project: the test deletes its primary file at the start
and again in step 7. The schema's seed row (`dwg` id 1) has `project_id=0`
and fails from step 4.

**Setup:** create a scratch top-level project and a document in it (no file
needed). Turn email notifications off while the test runs: `manager` has a
real address and `$g_email_receive_own` is ON.

**Run:**
```bash
bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh \
  http://10.0.0.94/doctis manager '' <dwg_id>
#  host                    user    pw  throwaway document
```

**Clean up:** the upload creates a repository for the scratch project. Delete
the document, the `{repository}` and `{project_repository}` rows, the project,
and `/var/git/doctis/<slug>-r<id>.git` plus its worktree.

Run 2026-10-13 on the native VM, against a scratch project removed afterwards:
13 of 13 passed.

Full endpoint reference, raw-curl SOAP templates, and base64Binary
double-encoding notes: CLAUDE.md §"SOAP API Testing and Diagnostics".

---

## 4. Git storage integration tests

Two complementary scripts — deliberately layered, so a failure immediately
tells you whether the fault is in raw git mechanics or in the Doctis mapping
layer on top of them (see
[doc/git/GIT_SOLUTION_SPACE.md](git/GIT_SOLUTION_SPACE.md) for why the two
layers are architecturally separate).

### 4a. `admin/test-git-php.php` — pure git mechanics

**What it covers:** the czproject/git-php integration itself, with no
Doctis core bootstrap: init, clone, add, commit, push, retrieve-by-SHA, HEAD
retrieve, SHA256 integrity, soft-delete, history retention, and
**pre-receive hook enforcement** (force-push rejection, ref-delete rejection,
`refs/doctis/*` client-write rejection). Self-contained — creates and tears
down its own temporary bare repo.

```bash
sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php
```

Must pass before enabling git storage on any new server. Run 2026-10-13 on
the native VM: 20 of 20 passed.

### 4b. `admin/test-git-doctis.php` — the Doctis mapping layer

**What it covers:** everything `core/repository_api.php` and the
path-as-data model in `core/file_dwg_api.php` add on top of raw git — 93
checks (2026-10-13): path sanitiser, repository-entity CRUD, project→
repository resolution (explicit link → hierarchy inheritance → create at
top-level project), on-disk materialisation, primary upload with the default
and a `{category}/{filename}` template, same-filename vs. new-filename
replacement (directory-sticky, HEAD stays single-copy), register-by-reference
(`file_dwg_primary_register()`), cross-project path-collision rejection,
dangling-path detection (`file_dwg_git_head_info()` after an external
delete), sync-to-HEAD promotion, repository adoption (`repository_adopt()`
— full history, remotes stripped), and rename relocation. Bootstraps the
full core, creates and tears down its own projects/documents/repositories.

```bash
sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php
```

Run 2026-10-13 on the native VM: 93 passed, 0 failed; the existing projects
and repositories were untouched.

Architecture reference: [doc/git/GIT_ARCHITECTURE.md](git/GIT_ARCHITECTURE.md).

---

## 5. Repository import — `admin/import-git-repo.php`

**What it covers:** adopting an existing git repository as a Doctis project
(optionally with sub-projects sharing its repository) and registering its
qualifying files as documents by reference, with no commits made to the
source content. See [doc/git/GIT_IMPORTER.md](git/GIT_IMPORTER.md) for the
full design (`.doctis` manifest format, metadata-gleaning rules, use-case
walkthroughs) and [doc/git/GIT_TODO.md](git/GIT_TODO.md) §6/WP7 for the
implementation record.

**Always dry-run first.** `--dry-run` performs Phase A/B discovery and
metadata gleaning and prints the full would-be result — projects, documents,
categories, status mapping, and every warning (unmapped frontmatter status,
unmatched owner account) — without writing anything to the database or
touching git.

```bash
I=/var/www/html/doctis/admin/import-git-repo.php
sudo -u www-data php $I --help

# Dry run — flat *.md tree, directory allowlist (HCRQMS-style)
sudo -u www-data php $I --source /var/git/MyDocs --name 'My Docs' \
  --directories content,system --patterns '*.md' --dry-run

# Dry run — one sub-project per top-level directory (monorepo-style)
sudo -u www-data php $I --source /var/git/MyHardware --name 'My Hardware' \
  --subprojects subdirs --patterns '*.pdf' --filename-parse yes --dry-run

# Real run (same flags, minus --dry-run); wrapper form:
bash /var/www/html/doctis/admin/tools/doctis-git-import.sh \
  --source /var/git/MyDocs --name 'My Docs' --directories content,system --patterns '*.md'

# Re-run later to pick up new commits — registers only new/unregistered
# paths, never deletes, reports any registered path missing at HEAD:
sudo -u www-data php $I --source /var/git/MyDocs --name 'My Docs' \
  --directories content,system --patterns '*.md' --update
```

The browser route is Manage → **Import Data**. It takes a ZIP upload, stages
it as a one-commit repository and runs the same importer with
`--subprojects none`. The VM's HCRQMS and LiDAR projects were loaded this way;
see [doc/git/HCRQMS_ZIP_UI_IMPORT.md](git/HCRQMS_ZIP_UI_IMPORT.md) and the
reload steps in
[admin/tools/data-scripts/README.md](../admin/tools/data-scripts/README.md).

**Source repository placement:** stage the source repo anywhere readable by
`www-data` *outside* `$g_git_storage_root` (`/var/git/doctis/` by default) —
e.g. `/var/git/<name>/` alongside it, not inside it. The importer clones
*from* that path; it never modifies the source. `doctis-git-reset.sh` (§8)
only touches `$g_git_storage_root`, so staged sources survive a clean-room
reset untouched.

**Verifying an import:** after a real run, confirm content integrity
end-to-end — this is what actually proves the registration is correct, not
just that rows were inserted:
```bash
# Compare a downloaded document against the git blob directly
curl -s -b "$C" "http://10.0.0.94/doctis/file_download.php?type=dwg_primary&id=<dwg_id>" | md5sum
git --git-dir=/var/git/doctis/<repo-basename>.git show 'HEAD:<git_path>' | md5sum
```
Both hashes must match. See §7 for the curl login/session pattern.

---

## 6. Remote clone / edit / push — external contributor simulation

**What it covers:** the Smart HTTP gateway's write path
(`$g_git_http_enabled`, API token auth, `$g_git_http_write_threshold`) from
the perspective of an actual external contributor: clone with a personal API
token, edit a file, commit, push — over the network, with no server shell
access. This is the one workflow the other test methods above don't reach,
because they all use the server's shell.

**Script:** [doc/git/doctis-remote-clone-edit-push.sh](git/doctis-remote-clone-edit-push.sh)
— it talks to the Doctis host only over HTTP(S), exactly as a real remote
user would. Run it from any machine that can reach the VM, the VM included.

**Prerequisites:**
- `$g_git_http_enabled = ON` on the target instance (it is on the VM)
- A personal API token (*My Account → API Tokens* in the Doctis UI, or
  `api_token_create()` for scripted setup — see the script's own header for
  a one-liner)
- The repository basename (`<slug>-r<id>`, e.g. `hcrqms-r1`) — shown on a
  document's "Advanced: Direct Git Repository Access" panel
  (`dwg_primary_head_warn.php`), or `ls /var/git/doctis/` on the server
- **A scratch repository.** The script commits an edit to the first `*.md`
  file it finds and pushes it, so on the VM do not point it at the imported
  `hcrqms`, `lidar` or `hcr571` repositories. Upload a Markdown document to a
  throwaway top-level project to create one, and remove it afterwards (see §3).

**Run:**
```bash
bash doc/git/doctis-remote-clone-edit-push.sh --help

bash doc/git/doctis-remote-clone-edit-push.sh \
  --host 10.0.0.94 --repo <scratch-slug>-r<id> --user manager
# prompts for the API token; edits the first *.md file found, commits, pushes
```

> **Gateway host note:** the gateway is mounted at the **server root**, *not*
> under the app path. On the VM's nginx, `location ~ ^/git/(.+)$` in
> `/etc/nginx/sites-available/doctis` runs `git_http.php`; Apache used
> `Alias /git` in `admin/tools/git-serve.conf`. So `--host` is `10.0.0.94`,
> not `10.0.0.94/doctis`, even though the web UI itself lives at
> `http://10.0.0.94/doctis/`.

**What a successful run proves:** authentication, read access, write access,
and — critically — that the push advanced only the repository **Draft**
(`HEAD`); the Doctis **On-Record** version (if any) stays pinned at its
recorded SHA until a manager explicitly promotes the new HEAD (*Sync to
HEAD* in the Primary Document panel, or `dwg_primary_file_sync_head.php`).
Check this directly:
```bash
# MYSQL_PWD set as in CLAUDE.md "Querying the database"
mysql -h 127.0.0.1 -u doctis doctis -N -e "SELECT git_sha FROM dwg_primary_file WHERE dwg_id=<id>;"
git --git-dir=/var/git/doctis/<repo-basename>.git rev-parse HEAD
# the two will now differ — that's the expected, correct outcome
```
The document's view page should show a "missing at HEAD" or "updated" badge
in the Draft row (`dwg_view_inc.php`) reflecting exactly this divergence.

**Negative-path check (auth rejection):** re-run with a bad token; expect a
clean `fatal: Authentication failed` from git and a non-zero exit — this is
what `git_http_require_auth()` is supposed to produce. Force-push and
ref-deletion rejection are already covered by §4a's hook-enforcement steps;
this script does not repeat them.

---

## 7. Curl-based testing

**What it covers:** anything with real HTTP/session/CSRF/redirect behaviour
that the API-level tests above don't exercise — page rendering, form
submission, login flow, project-context redirects, file upload via
multipart. The closest thing to a browser test without a browser.

**Login (two-step, cookie jar):** use the `$g_path` host, `10.0.0.94`.
Logging in at `127.0.0.1` loses the session on the first redirect.
```bash
B=http://10.0.0.94/doctis; C=<scratch dir>/doctis_cookies.txt
curl -s -c "$C" -b "$C" -X POST "$B/login_password_page.php" \
  -d "username=manager&return=index.php" -o /dev/null
curl -s -c "$C" -b "$C" -X POST "$B/login.php" \
  -d "username=manager&password=&return=index.php&secure_session=0" -o /dev/null
```

Full pattern library (CSRF token extraction, document creation, primary-file
upload/replace, download, diagnosing 0-byte/302/500 responses): CLAUDE.md
§"Curl-Based Testing".

### 7a. AI Meeting Assistant end to end — `doctis-meeting-chat.py`

**What it covers:** the full meeting lifecycle through the real endpoints:
agenda conversation → meeting record + document On Record + agenda emails;
minutes conversation by the minute taker → Draft revision + approval
request; chair approval → record stamped `Approved Minutes`, Draft promoted,
meeting status 30.

**Prerequisites:** `$g_anthropic_api_key` and `$g_meeting_project_id` set;
sample users loaded (frodo, sam and gandalf opt in to invitations; their
passwords are blank). Each chat turn is a billed API call, and each
confirmation writes a document and commits to the meeting project's
repository, so use a sandbox instance.

**Email is sent for real** if SMTP is configured and the sender cron runs (as
on the native VM, see DEV-SETUP.md): each run emails the participants. The
LOTR sample users have undeliverable `.example` addresses; never invite the
role accounts, which have `@gmail.com` addresses. To inspect messages without
sending, queue them from PHP and read and delete the `{email}` rows in the
same run, just after a cron minute boundary.

**Knowledge base** (`tests/Mantis/AiKnowledgeTest.php`, no AI calls), with
the meeting tests below:

```bash
vendor/bin/phpunit --testsuite mantis --filter AiKnowledgeTest --testdox
```

Live check, which makes AI calls (procedure and results in `doc/ai/ai-knowledge.md`
§Verification):

```bash
T=admin/tools/doctis-meeting-chat.py; export DOCTIS_URL=http://10.0.0.94/doctis/
$T sam '' --mode help --clear "Where do i find the corporate directory?"   # → My View → Organisational Chart
$T sam '' --mode help --clear "<a question Doctis cannot know>"             # → says so, invites teaching
$T sam '' --mode help "<the answer>"                                       # → draft entry, asks to save
$T sam '' --mode help "Yes, add it."                                       # → KNOWLEDGE_ENTRY: id, unverified
# another user asks a paraphrase → answer cites KB-n as unverified; publish it on
# ai_knowledge_page.php → the warning goes; delete test entries afterwards
```

**Page check** (headless; needs `apt install node-jsdom`). Loads
`ai_assist_page.php` with its real scripts and checks that the hash tab is
shown, that no script fails, and that Send and Ctrl+Enter post the right chat
request. Requests are recorded, not sent: no AI call is made. It caught the
2026-10-02 bug where opening the page with `#tab-meeting` broke both chats.

```bash
T=admin/tools/doctis-ai-page-check.js; U=http://10.0.0.94/doctis
node $T $U administrator root '?meeting_id=<id>#tab-meeting' meeting   # Write Minutes
node $T $U administrator root '?series_of=<id>#tab-meeting' meeting    # Plan Next Meeting
node $T $U administrator root '#tab-meeting' meeting                   # Plan a Meeting
node $T $U administrator root '' help
```

**Unit/integration tests** for the meeting logic (no API calls, no git, no
email sent; throwaway users and meetings are removed afterwards):

```bash
cd /var/www/html/doctis   # needs tests/bootstrap.php (§2)
vendor/bin/phpunit --testsuite mantis --filter MeetingApiTest --testdox
```

Running the **whole** `mantis` suite also creates throwaway accounts in other
tests, which queue "Account registration" emails to `.test` addresses. On an
instance with live SMTP, delete them before the cron sender runs:
`DELETE FROM email WHERE email LIKE '%.test' AND subject = '[Doctis] Account registration'`.

**End to end** (frodo and sam are HCRQMS members; gandalf is a global manager):

```bash
export DOCTIS_URL=http://10.0.0.94/doctis/
T=admin/tools/doctis-meeting-chat.py

# 1. Chair plans the meeting (two turns: draft, then confirm)
$T administrator root --clear \
  "Agenda for Frodo, Sam and Gandalf on 20 October 2026 at 10am: weekly QMS progress review, 45 minutes." \
  "Yes, go ahead."
#    → SAVED_DOCUMENT: meeting_id, dwg_id, file_path, stored=true, emails_sent ×3

# 2. Chair reschedules: meeting page → Change Meeting (meeting_edit_page.php),
#    e.g. time 11:00. (Scripted: POST meeting_edit.php with the form token.)

# 3. Minute taker (first invitee named) writes the minutes, with actions
$T frodo '' --meeting-id <meeting_id> \
  "All attended. <notes per agenda item>. Actions: Frodo to … by 27 October; Sam to … by 30 October."
$T frodo '' --meeting-id <meeting_id> "Yes, save the minutes."
#    → stored=true, staged=true, actions=N

# 4. Chair approves → actions become issues
$T administrator root --approve <meeting_id>

# 5. Plan the next meeting in the series
$T administrator root --clear --series-of <meeting_id> "Same time next week please." 
$T administrator root --series-of <meeting_id> "Yes, go ahead."

# 6. Cancel it: meeting page → Cancel Meeting (with a reason; confirmation step)
```

**Check after each step** (database access: `doc/ai/ai-todo.md` §8):

| After | Expect |
|-------|--------|
| 1 | `{meeting}` status 10, sequence 0; invitees with user ids; document in category `meetings`, number `MIN-…`, file at `{dept path}/MIN-….md`, On Record, commit by the chair, `refs/doctis/approved/<dwg>/1`; YAML frontmatter `status: Agenda`; 3 agenda emails with `invite.ics` (METHOD:REQUEST) |
| 2 | sequence 1; record diff only in the changed lines, On Record (no draft); 3 "Meeting Updated" emails, calendar SEQUENCE:1 |
| 3 | commit by frodo; `{dwg_primary_draft}` row; file `revision: B`, `status: Draft Minutes`; attendance recorded; status 20; `{meeting_action}` rows with owner ids and due dates; draft emailed to participants except frodo (the chair's copy asks for approval); Approve Minutes shown to the chair only |
| 4 | stamping commit by the chair (`status`, `effective_date`, `**Status:**` only), promoted On Record, second approved ref, status 30; one issue per action in the meeting project, handler = owner (when allowed), `document_id` = the meeting document, `document_sha` = the approved SHA; approved minutes emailed |
| 5 | new meeting with `series_id` = the first meeting; agenda includes "Approval of previous minutes (MIN-…)" and the open actions; same invitees, time and minute taker |
| 6 | status 90, sequence 1; record `status: Cancelled`; CANCEL emails with the reason; struck through on My Meetings |

Runs: 2026-10-02 on the native VM. First run (steps 1, 3, 4) passed after three
fixes (missing frontmatter, private meeting project refusing the minute taker,
approval stamp). Second run (all six steps) passed without changes.

**Recurring meetings** (the scheduler makes one API call per occurrence and
emails the invitees):

```bash
# 7. Make a series repeat: meeting page → Repeats → Weekly (meeting_recurrence.php)
# 8. Evaluate and run the scheduler as the web server user, simulating a date
#    within $g_meeting_schedule_lead_days of the next occurrence
cd /var/www/html/doctis
sudo -u www-data php scripts/meeting_schedule.php --dry-run --now="YYYY-MM-DD HH:MM"
sudo -u www-data php scripts/meeting_schedule.php --series=<root id> --now="YYYY-MM-DD HH:MM"
sudo -u www-data php scripts/meeting_schedule.php --now="YYYY-MM-DD HH:MM"   # again: nothing due
# 9. Stop the series (Repeats → Does not repeat) so cron does not keep scheduling it
```

| After | Expect |
|-------|--------|
| 7 | `{meeting_series}` row (`recurrence`, `active=1`); meeting page shows the rule |
| 8 | dry run lists the series only once the lead window is reached; the real run creates the next meeting in the series (same invitees, minute taker, department, duration, location; next weekday occurrence), its document, agenda emails with `invite.ics`, a "Next meeting scheduled" email to the chair; the agenda approves the previous minutes and lists the open actions; `last_run` set, `last_error` empty; a second run creates nothing |
| 9 | no active `{meeting_series}` rows |

Also check visibility: a global manager who is not a participant sees nothing
under My meetings, everything under All meetings, and only Calendar on a
meeting page. A reporter who is not a participant is refused the meeting page.

Run 2026-10-02: series 27 scheduled MIN-QA-20261103 as of 31 Oct; all checks passed.

### 7b. Documentation Topics

Design and scope: [doc/topics/TOPICS_PLAN.md](topics/TOPICS_PLAN.md).

**API rules** (`tests/Mantis/TopicApiTest.php`; throwaway projects, document
rows and catalogues, removed afterwards; no git, no email):

```bash
cd /var/www/html/doctis
vendor/bin/phpunit --testsuite mantis --filter Topic --testdox
```

`TopicApiTest` (phases 1 and 1b), `TopicExpectationApiTest` (phases 2 and
3: stale writes, activity, unversioned evidence, catalogue migration, shared
documents) and `TopicSnapshotApiTest` (phase 3 snapshots) share
`TopicTestCase` (throwaway records; project deletion removes every Topic
record of the project).

Covers: the bundled STD-ENG-004 Rev A catalogue (codes as strings, 21–23
under 20); code, title and one-level parent validation; publish needs an
approved source document and freezes the catalogue; applicability starts
Undecided, needs a rationale for Not applicable and keeps history; declared
coverage with no parent/child roll-up and archived documents not counted; tag
history types; retired Topics, disabled projects and catalogue switches
refused; the feature switch; moves, document deletion and project deletion.

**Pages** (needs `$g_topics_enabled = ON`): log in with curl as in §7, then
- Manage → Topics: load the bundled catalogue, create a scratch catalogue,
  add a principal and a child Topic, edit, retire, delete, delete the
  catalogue. Duplicate codes and deleting a parent with children are refused.
- With a project selected, `topic_page.php`: enable Topics, tag documents
  from Untagged documents and from `dwg_view.php` (the Documentation Topics
  panel), record decisions on `topic_detail_page.php` (Not applicable without
  a rationale is refused), and check the document History lists "Topic
  added/changed/removed".
- Roles (sample users, blank passwords): viewer is refused the Topics page and
  sees no panel; reporter sees but cannot tag; developer tags; manager also
  decides; only administrators reach Manage → Topics.
- Make a tagged document private: a reporter sees "Restricted documents are
  also tagged" instead of it, and the coverage is unchanged.
- Phase 1b. Document list of a Topics project: Topics column (whole tags in
  bold) and Topics filter; `dwg_search.php?project_id=<P>&topic[]=T20` lists
  documents tagged 20 or 21–23, `topic[]=-2` the untagged ones (log in at the
  server's own address so the redirect keeps the session). A project without
  Topics shows neither. Bulk **Add Topic** tags the selection and reports
  documents already tagged. The create form's Topics block tags the new
  document; an unknown Topic id is refused before anything is created.
  `topic_export.php?format=csv|print&project_id=<P>` (viewer refused).
  SOAP `mc_dwg_get` and REST `/api/rest/documents/<id>` return `topics`.
- Phase 2, in a throwaway project (not a pilot: expectations and assessments
  are engineering decisions): load the default milestones; add an
  expectation (due SRR/PDR, Approved); its page shows Not due at Planning
  and Missing at SRR/PDR; link a tagged document (Pending: assessment
  required, Draft but Approved required); assess Adequate (Pending: maturity);
  set the document to approved (Satisfied); change its revision
  (Reassessment required, "has a new version"); reassess (Satisfied).
  Decide every Topic and authorise the profile; the Topics page shows
  readiness No expectations due at Planning and Ready at SRR/PDR. Roles:
  viewer refused; reporter reads; developer links and removes evidence;
  manager edits, assesses and manages milestones. Delete the project via
  Manage → Projects: every Topic record of it goes.
- Phase 3, in throwaway projects (create and delete them from the PHP
  command line; a document needs a `{dwg_text}` row to show):
  - Snapshot: with one public and one private evidence document, take a
    snapshot from the Topics page. The administrator sees both; a reporter
    sees the public one, "not visible to you now", and the reason naming the
    private one redacted, on the snapshot page, its CSV
    (`topic_export.php?format=csv&snapshot_id=`), the live Topic page and
    REST.
  - Catalogue revision: Create Revision on a catalogue, change a Topic in the
    copy, open "Review the change" from the project's Topics page, confirm
    the move; the project and its decisions use the new revision and
    Activity records it.
  - Shared document: share another project's document from the Topics page;
    it shows "from <project>", and its own page lists "Also used by" even
    when its project has no Topics. Remove the history rows it adds to the
    shared document afterwards.
  - REST, logged in: `api/rest/topics/catalogues`, `topics/<P>`,
    `topics/<P>/snapshots`, `topics/snapshots/<id>`; a project without Topics
    and a missing snapshot give 404.

Run 2026-10-13 on the native VM (HCR571 pilot, project 29): 256 `mantis` tests
passed, including 12 `TopicApiTest`; all page and role checks passed. Phase 1b,
same day: 260 tests (16 `TopicApiTest`) and the checks above passed. Phase 2,
same day: 268 tests (16 + 8 `TopicExpectationApiTest`) and the phase 2 walk
through above passed. Phase 3, same day: 276 tests (32 Topic tests: 16 + 13 + 3)
and the phase 3 checks above passed. After the same day's database rebuild,
the pilot is project 5 and the full `mantis` suite passed again.

---

## 8. Clean-room database and git-store reset

**What it covers:** returning to a known-empty state before a test session
that must start clean — required before re-running any suite above if a
prior session left ad hoc test data behind.

**Always reset both stores together** — they reference each other. On the
VM a reset also deletes the imported projects, the HCR571 pilot and any
meetings: back up first and reload afterwards, following
[admin/tools/data-scripts/README.md](../admin/tools/data-scripts/README.md).
The scripts run as root, which reaches MariaDB through its unix socket.

```bash
# Either: one command, with sample data (example project, licences, test users)
sudo bash /var/www/html/doctis/admin/tools/doctis-reset-native-test.sh --preview
sudo bash /var/www/html/doctis/admin/tools/doctis-reset-native-test.sh --execute

# Or: step by step
# 1. Wipe the git document store (bare repos + worktrees)
sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh <<< yes
# 2. Drop, recreate, and install the database from admin/schema.php
#    (installer reached over loopback; the password argument is unused)
sudo bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh doctis not-used 127.0.0.1 <<< yes
# 3. (optional) the standard example project/licences/test users
sudo bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh <<< yes
```

Verify: `sudo mysql doctis -e 'SELECT id, name FROM project; SELECT username FROM user;'`
shows no projects and only `administrator` (no sample data), or the example
project and test users (sample data loaded);
`ls /var/git/doctis/ /var/www/doctis/worktrees/` is empty. Full procedure and
rationale: CLAUDE.md §"Resetting to a Clean Slate for Testing".

**After a schema change:** a new table needs only `php admin/upgrade_unattended.php`
in the running clone. A changed table needs a rebuild (steps 1–2 above) before
running §4 or §5 — a stale schema is the most common cause of confusing
failures in both. See CLAUDE.md §"Database Schema Changes".

---

## 9. Suggested full regression sweep

Run in this order after a change that touches the git/document storage
layer — each step's failure mode points cleanly at the layer above it:

```bash
# 0. Optional clean rebuild (§8). On the VM this deletes the imported data:
#    back up and reload afterwards, or skip it. Steps 1-2 create and remove
#    their own state, and steps 3-4 use throwaway projects.

# 1. Git mechanics (§4a)
sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php

# 2. Mapping layer (§4b)
sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php

# 3. SOAP lifecycle against a throwaway document (§3)
bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh http://10.0.0.94/doctis manager '' <dwg_id>

# 4. Gateway, against a scratch repository (§6)
bash doc/git/doctis-remote-clone-edit-push.sh --host 10.0.0.94 --repo <scratch-repo-basename> --user manager

# 5. PHPUnit (§2), if core API surface changed
cd /var/www/html/doctis && sudo -u www-data vendor/bin/phpunit --testsuite mantis
```

All must be green (or, for step 4, produce the expected Draft/On-Record
divergence) before considering the change verified.
