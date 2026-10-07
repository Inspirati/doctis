# Doctis Testing Guide

This document indexes every test method available in the Doctis codebase:
what each one covers, when to reach for it, and how to run it. It is a map,
not a tutorial — each section links to the authoritative source (script
`--help`, source file, or a design doc) for full detail.

All server-side commands run **on vaio** via `ssh hcr@vaio "..."` per
[CLAUDE.md](../CLAUDE.md)'s Development Environment section, unless a section
says otherwise (the git-import client script explicitly does not).

---

## 1. Overview — which test method for which change

| You changed... | Use |
|---|---|
| PHP core/API logic with no git or SOAP surface | §2 PHPUnit |
| SOAP endpoint (dwg, primary file, attachment) | §3 `doctis-soap-test.sh` |
| Git storage backend / repository resolution / path handling | §4 `test-git-php.php` + `test-git-doctis.php` |
| Repository import (`admin/import-git-repo.php`) | §5 Import dry-run/real-run against a scratch repo |
| Smart HTTP gateway (clone/push, auth, hook enforcement) | §6 remote clone/push script, or §4's hook-enforcement steps |
| A page, form, or workflow with no good headless test | §7 curl-based live testing |
| AI Meeting Assistant, meetings, My Meetings, minutes approval | §7a meeting end-to-end |
| Anything touching schema | Rebuild first — §8 — then re-run the relevant suite above |
| You aren't sure what else broke | §9 full regression sweep |

---

## 2. PHPUnit (`tests/`)

**What it covers:** MantisBT core API unit tests (`tests/Mantis/`) and SOAP
integration tests (`tests/soap/`) via PHP's SoapClient. Doctis has not yet
added its own PHPUnit suites for `dwg_api.php` / document workflows — new
document-domain unit tests belong here as they're written.

**Setup (one-time):**
```bash
cd /var/www/html/doctis   # on vaio
composer install
cp tests/bootstrap.php.sample tests/bootstrap.php
# edit tests/bootstrap.php: MANTIS_TESTSUITE_SOAP_HOST, USERNAME, PASSWORD
```

**Run:**
```bash
ssh hcr@vaio "cd /var/www/html/doctis && ./vendor/bin/phpunit"
```

Config: [phpunit.xml](../phpunit.xml) — three suites (`mantis`, `soap`,
`mantis core formatting`; a `rest` suite exists but is commented out, tracking
the "no REST endpoints for dwg/document" gap in CLAUDE.md).

---

## 3. SOAP endpoint smoke test — `doctis-soap-test.sh`

**What it covers:** all 13 Doctis-specific SOAP operations end-to-end against
a *live* instance: status enum, document fetch, primary-file lifecycle
(upload → metadata → download → delete), attachment lifecycle. This is the
fastest way to confirm the whole PHP → SOAP → git/DB stack is wired correctly
after a change, without a browser.

**Properties:** non-fatal (later steps run even if earlier ones fail so you
get a full picture), idempotent (cleans up leftover state from a prior run at
the start), needs a real `dwg_id` in a real project — the schema's seed
row (`dwg` id 1) has `project_id=0` and will fail step 4 onward; create or
pick a real document first.

**Run:**
```bash
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh \
  http://10.0.0.10/doctis manager '' <dwg_id>"
#                           host          user  pw  target document
```

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
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php"
```

Must pass before enabling git storage on any new server.

### 4b. `admin/test-git-doctis.php` — the Doctis mapping layer

**What it covers:** everything `core/repository_api.php` and the
path-as-data model in `core/file_dwg_api.php` add on top of raw git — 59
checks across 13 steps: path sanitiser, repository-entity CRUD, project→
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
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php"
```

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
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php --help"

# Dry run — flat *.md tree, directory allowlist (HCRQMS-style)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
  --source /var/git/MyDocs --name 'My Docs' \
  --directories content,system --patterns '*.md' --dry-run"

# Dry run — one sub-project per top-level directory (monorepo-style)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
  --source /var/git/MyHardware --name 'My Hardware' \
  --subprojects subdirs --patterns '*.pdf' --filename-parse yes --dry-run"

# Real run (same flags, minus --dry-run); wrapper form:
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-git-import.sh \
  --source /var/git/MyDocs --name 'My Docs' --directories content,system --patterns '*.md'"

# Re-run later to pick up new commits — registers only new/unregistered
# paths, never deletes, reports any registered path missing at HEAD:
ssh hcr@vaio "... import-git-repo.php --source /var/git/MyDocs --name 'My Docs' \
  --directories content,system --patterns '*.md' --update"
```

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
curl -s -b cookies.txt "http://10.0.0.10/doctis/file_download.php?type=dwg_primary&id=<dwg_id>" | md5sum
ssh hcr@vaio "git --git-dir=/var/git/doctis/<repo-basename>.git show 'HEAD:<git_path>'" | md5sum
```
Both hashes must match. See §7 for the curl login/session pattern.

---

## 6. Remote clone / edit / push — external contributor simulation

**What it covers:** the Smart HTTP gateway's write path
(`$g_git_http_enabled`, API token auth, `$g_git_http_write_threshold`) from
the perspective of an actual external contributor: clone with a personal API
token, edit a file, commit, push — over the network, with no server shell
access. This is the one workflow the other test methods above don't reach,
because they all run *on* vaio.

**Script:** [doc/git/doctis-remote-clone-edit-push.sh](git/doctis-remote-clone-edit-push.sh)
— run on a **workstation** (this machine), not via `ssh hcr@vaio`. It talks
to the Doctis host only over HTTP(S), exactly as a real remote user would.

**Prerequisites:**
- `$g_git_http_enabled = ON` on the target instance
- A personal API token (*My Account → API Tokens* in the Doctis UI, or
  `api_token_create()` for scripted setup — see the script's own header for
  a one-liner)
- The repository basename (`<slug>-r<id>`, e.g. `hcrqms-r5`) — shown on a
  document's "Advanced: Direct Git Repository Access" panel
  (`dwg_primary_head_warn.php`), or `ls /var/git/doctis/` on the server

**Run:**
```bash
bash doc/git/doctis-remote-clone-edit-push.sh --help

bash doc/git/doctis-remote-clone-edit-push.sh \
  --host 10.0.0.10 --repo hcrqms-r5 --user manager
# prompts for the API token; edits the first *.md file found, commits, pushes
```

> **Gateway host note:** the gateway is mounted at the **vhost root**
> (`Alias /git /var/www/html/doctis/git_http.php` in
> `admin/tools/git-serve.conf`), *not* under the app path — `--host` is
> `10.0.0.10`, not `10.0.0.10/doctis`, even though the web UI itself lives at
> `http://10.0.0.10/doctis/`.

**What a successful run proves:** authentication, read access, write access,
and — critically — that the push advanced only the repository **Draft**
(`HEAD`); the Doctis **On-Record** version (if any) stays pinned at its
recorded SHA until a manager explicitly promotes the new HEAD (*Sync to
HEAD* in the Primary Document panel, or `dwg_primary_file_sync_head.php`).
Check this directly:
```bash
ssh hcr@vaio "mysql -N -e \"SELECT git_sha FROM doctis.dwg_primary_file WHERE dwg_id=<id>;\""
ssh hcr@vaio "git --git-dir=/var/git/doctis/<repo-basename>.git rev-parse HEAD"
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

## 7. Curl-based live testing

**What it covers:** anything with real HTTP/session/CSRF/redirect behaviour
that the API-level tests above don't exercise — page rendering, form
submission, login flow, project-context redirects, file upload via
multipart. The closest thing to a browser test without a browser.

**Login (two-step, cookie jar):**
```bash
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/login_password_page.php" \
  -d "username=manager&return=index.php" -o /dev/null
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/login.php" \
  -d "username=manager&password=&return=index.php&secure_session=0" -o /dev/null
```

Full pattern library (CSRF token extraction, document creation, primary-file
upload/replace, download, diagnosing 0-byte/302/500 responses): CLAUDE.md
§"Curl-Based Live Testing".

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

---

## 8. Clean-room database and git-store reset

**What it covers:** returning to a known-empty state before a test session
that must start clean — required before re-running any suite above if a
prior session left ad hoc test data behind.

**Always run both steps together** — they reference each other:

```bash
# 1. Wipe the git document store (bare repos + worktrees)
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"

# 2. Drop, recreate, and install the database from admin/schema.php
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"

# 3. (optional) reload the standard example project/licenses/test users
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh"
```

Verify: `mysql -e 'SELECT id, name FROM doctis.project;'` should show only
`administrator` (no sample data) or the example project (sample data
loaded); `ls /var/git/doctis/ /var/www/doctis/worktrees/` should be empty.
Full procedure and rationale: CLAUDE.md §"Resetting to a Clean Slate for
Testing".

**After any schema change:** rebuild (steps 1–2 above) before running §4 or
§5 — a stale schema is the most common cause of confusing failures in both.
See CLAUDE.md §"Database Schema Changes".

---

## 9. Suggested full regression sweep

Run in this order after a change that touches the git/document storage
layer — each step's failure mode points cleanly at the layer above it:

```bash
# 0. Rebuild clean (§8)
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh"

# 1. Git mechanics (§4a)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php"

# 2. Mapping layer (§4b)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php"

# 3. SOAP lifecycle against a real document (§3 — create one first if needed)
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh http://10.0.0.10/doctis manager '' <dwg_id>"

# 4. Gateway, from a workstation (§6)
bash doc/git/doctis-remote-clone-edit-push.sh --host 10.0.0.10 --repo <repo-basename> --user manager

# 5. PHPUnit (§2), if core API surface changed
ssh hcr@vaio "cd /var/www/html/doctis && ./vendor/bin/phpunit"
```

All must be green (or, for step 4, produce the expected Draft/On-Record
divergence) before considering the change verified.
