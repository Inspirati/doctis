# Doctis AI Assistant — Status and To-Do

**Branch:** `ai-meeting` (meeting work in progress; `dev` holds the June 2026 state)
**Status as of:** 2026-10-02 — Help tab working; Meeting tab rebuilt on a meeting entity and Doctis document storage, **not yet tested end to end**; My Meetings view added; SOP and Other tabs not started
**Companion document:** [ai-engine.md](ai-engine.md) — the original concept plan and platform assessment (June 2026)

This is a living document. It records what exists, how it works, what is
known to be broken, and what remains to do. Read it before working on any AI
feature, and update it when work is completed or decisions change. Keep
statements about verified behaviour separate from statements about code that
has only been read.

The overall shape of the meeting feature is still being explored. §4.5 lists
the open design questions.

---

## Contents

1. [Current status](#1-current-status)
2. [File map](#2-file-map)
3. [How the pipeline works](#3-how-the-pipeline-works)
4. [Meetings in detail](#4-meetings-in-detail)
5. [Configuration reference](#5-configuration-reference)
6. [Known constraints and gotchas](#6-known-constraints-and-gotchas)
7. [To-do list](#7-to-do-list)
8. [Inspecting AI state on the development VM](#8-inspecting-ai-state-on-the-development-vm)

---

## 1. Current status

| Area | State |
|------|-------|
| **Help tab** | Working proof of concept: server-side system prompt, session persistence, token display, Markdown rendering, copy button. |
| **Meeting tab** | Drafts agendas and minutes. On confirmation, it records a meeting (`{meeting}`, `{meeting_invitee}`) and stores the record as a Doctis document in the meeting project's repository: the agenda goes On Record and the minutes become a staged Draft. Then it emails invitees. **Verified so far:** the pages render, the schema upgrade applied, and PHP lint passes. **Not yet run:** an agenda/minutes conversation, document storage, email. |
| **My Meetings** | New My View tab (`my_view_meeting_page.php`) listing upcoming and past meetings, with the user's role, participants, status, a document link and a Write/Revise Minutes action. Verified to render (with no meetings yet). |
| **SOP tab** | "Coming soon" placeholder. |
| **Other tab** | "Coming soon" placeholder. |

### History

- **June 2026** (`040a95679` … `e39d705ca`): Help tab, then the Meeting tab.
  The Meeting tab wrote files and ran `git commit` under
  `$g_hcrqms_repo_path`. That design was overtaken by the git repository
  entity (`{repository}`, `core/repository_api.php`) and the HCRQMS import,
  and as configured (`/var/git/doctis`, the bare-repository store) it could
  not work.
- **October 2026** (`ai-meeting`): introduced the meeting entity, storage
  through `file_dwg_primary_add()`, validated marker handling, a defined
  minutes flow, and the My Meetings view. `$g_hcrqms_repo_path` and the
  direct git helpers were removed.

### State of the native development VM (2026-10-02)

- The running clone (`/var/www/html/doctis`) was switched to `ai-meeting` at
  `2c4c794e3` for testing (fetched from the working clone; nothing pushed).
  Its schema upgrade created `{meeting}` and `{meeting_invitee}`.
- **Pending (owner action):** pull the latest `ai-meeting`, set the recorded
  `database_version` from 63 to 61 (see §6, schema numbering), and add
  `$g_meeting_project_id = 1;` to the running `config_inc.php`. Until then,
  meetings are recorded without documents.
- HCRQMS is Doctis project 1, repository 1 (`hcrqms-r1`, branch `dev`). The
  template `system/templates/Meeting-Agenda-and-Minutes.md` and a hand-made
  record `system/meetings/MIN-SYS-20260917.md` are in that repository.
- Sample users were loaded on 2026-10-02 (`load_testing_user` only; the
  script's example project would collide with HCRQMS as project 1). Seven of
  them accept invitations: frodo, gandalf and legolas (all meetings), and
  sam, pip, merry and gimli (department only). Passwords are blank except
  `admin`'s.
- SMTP is not configured, so agenda emails are only queued.

No AI or meeting function has automated tests.

---

## 2. File map

| File | Purpose |
|------|---------|
| [`ai_assist_page.php`](../../ai_assist_page.php) | Page shell: auth, shared CSS, tab navigation, SOP/Other placeholders, `<script src>` tags |
| [`ai_assist_help_page.php`](../../ai_assist_help_page.php) | Help tab panel HTML |
| [`ai_assist_meeting_page.php`](../../ai_assist_meeting_page.php) | Meeting tab panel HTML; reads `?meeting_id=` to open in minutes mode |
| [`ai_assist_api.php`](../../ai_assist_api.php) | AJAX endpoint: request validation, `load`/`clear`/`chat`, Anthropic call, `{ai_sessions}` CRUD |
| [`ai_assist_help_api.php`](../../ai_assist_help_api.php) | `ai_assist_help_system_prompt()` |
| [`ai_assist_meeting_api.php`](../../ai_assist_meeting_api.php) | Meeting prompt, focus meeting, marker processing (agenda/minutes), validation, agenda email |
| [`core/meeting_api.php`](../../core/meeting_api.php) | Meeting entity: get/list/create/update, roles, attendance, status labels, template read, document storage (`meeting_store_record()`) |
| [`my_view_meeting_page.php`](../../my_view_meeting_page.php) | My Meetings view (Write/Revise/Approve Minutes actions) |
| [`meeting_minutes_approve.php`](../../meeting_minutes_approve.php) | POST handler: chair approves minutes |
| [`dwg_primary_file_sync_head.php`](../../dwg_primary_file_sync_head.php), [`dwg_view_inc.php`](../../dwg_view_inc.php) | Promote-Draft is chair-only for meeting documents and marks the meeting approved |
| [`js/ai_assist.js`](../../js/ai_assist.js) | Shared JS: `renderMarkdown()`, `createChatSession(cfg)` (with `cfg.extra` request fields), tab/hash handling |
| [`js/ai_assist_help.js`](../../js/ai_assist_help.js) / [`js/ai_assist_meeting.js`](../../js/ai_assist_meeting.js) | Per-tab `createChatSession` instances; the meeting instance sends `meeting_id` |
| [`core/html_api.php`](../../core/html_api.php) | `print_my_view_menu()`: My Meetings tab |
| [`core/layout_api.php`](../../core/layout_api.php) | Sidebar "AI Assistant" entry (search for `ai_assist_threshold`) |
| [`core/file_dwg_api.php`](../../core/file_dwg_api.php) | `file_dwg_primary_add()`, which takes an optional explicit `git_path` for a first registration |
| [`lang/strings_english.txt`](../../lang/strings_english.txt) | `ai_assist_*`, `meeting_*`, `my_view_meeting_link` strings |
| [`config_defaults_inc.php`](../../config_defaults_inc.php) | AI and meeting settings (§5) |
| [`admin/schema.php`](../../admin/schema.php) | `{ai_sessions}` (index 1), `{meeting}` (60), `{meeting_invitee}` (61); `meeting_invite` on `{user}` |
| [`my_view_cnf_page.php`](../../my_view_cnf_page.php) and related | User profile "Meeting Invites" setting (0 never, 1 department, 2 all) |

---

## 3. How the pipeline works

### Request flow (one user turn)

```
Browser (js/ai_assist.js, createChatSession)
  │  POST ai_assist_api.php  { action: 'chat', mode, history[], meeting_id? }
  ▼
ai_assist_api.php
  │  — POST + XHR header check, auth, ai_assist_threshold
  │  — sanitise history (role/content only; last turn must be 'user')
  │  — build system prompt server-side for the mode
  │  — POST https://api.anthropic.com/v1/messages (non-streaming, 90 s timeout)
  │  — meeting mode: ai_assist_process_meeting_document() on the reply
  │  — upsert {ai_sessions} row for (user, mode); store meeting ref in doc_id
  ▼
{ reply, error, usage, saved_document }
```

`load` returns the stored history; in meeting mode with a `meeting_id` for a
different meeting than the stored session, it returns none so the minutes
conversation starts fresh. `clear` deletes the session.

### System prompts

Both are built server-side; the browser never sends one.

### Token limits

`max_tokens`: 2048 Help, 8192 Meeting (a full minutes record is long). Not configurable.

---

## 4. Meetings in detail

### 4.1 Data model

`{meeting}`: `doc_ref` (unique, e.g. `MIN-SYS-20261015`), `title`,
`department`, `project_id`/`dwg_id`/`git_path` (the stored document, 0/'' if
none), `chair_id`, `minute_taker_id`, `date_start` (Unix), `duration` (min),
`location`, `status`, `created_by`, `date_created`, `date_updated`.

`{meeting_invitee}`: `meeting_id`, `user_id` (0 = guest without an account),
`name`, `attendance` (0 invited, 10 attended, 20 apologies).

Status: 10 agenda issued → 20 minutes awaiting approval → 30 minutes
approved; 90 cancelled (**nothing sets 90 yet**, §7).

**Approval (owner decision, 2026-10-02):** an issued agenda goes On Record
immediately. The **chair** approves the minutes with `meeting_minutes_approve()`,
which promotes the minutes Draft to On Record and sets status 30. The chair
can do this from the **Approve Minutes** button on My Meetings
(`meeting_minutes_approve.php`) or from the document page's promote-Draft
button. For meeting documents that button is shown to, and accepted from, the
chair only, instead of managers. When someone other than the chair saves the
minutes, the chair is emailed an approval request.

A user's role in a meeting is chair, minute taker, organiser (creator) or
invitee. Chair, minute taker and organiser may write minutes.

### 4.2 Conversation design

- **Agenda:** the "infer, don't ask" flow from June. One sentence produces a
  time-allocated draft, then one confirmation, then the document. The prompt
  now includes today's date and timezone, so that relative dates resolve.
- **Minutes:** opened from My Meetings (`ai_assist_page.php?meeting_id=N#tab-meeting`)
  or by typing "minutes" and choosing from the list of meetings awaiting
  minutes in the prompt. With `meeting_id`, the prompt includes the meeting's
  details, invitees (with IDs) and the issued agenda. The assistant asks for
  attendance, takes notes per agenda item, summarises, and generates on
  confirmation.

### 4.3 Document markers

```
<<<MEETING_DOCUMENT type="agenda" doc_id=… dept=… title=… date="YYYY-MM-DD" time="HH:MM"
    duration=… location=… minute_taker_id=… invitee_ids="1,5" guests="Name; Name">>>
<<<MEETING_DOCUMENT type="minutes" meeting_id=… attended_ids=… apology_ids=…>>>
```

Server-side handling (`ai_assist_meeting_api.php`):

| Agenda | Minutes |
|--------|---------|
| Department must be configured; date/time must parse (user timezone) | Meeting must exist; user must be chair/minute taker/organiser; status 10 or 20 |
| Reference built as `MIN-{dept}-{Ymd}` (+`-2`… if taken); the model's `doc_id` is replaced in the content | Attendance IDs accepted only for the meeting's invitees |
| Invitee and minute-taker IDs accepted only from the candidate list; `guests` become account-less invitees | — |
| Meeting row + invitees created | Status → 20 |
| If a meeting project is configured: document created (category `Meetings`, number = reference) and file stored at `{dept path}/{ref}.md`, **On Record** | If the meeting has a document: same file replaced, **staged as Draft** (needs a manager to promote) |
| Agenda emailed to invitees with accounts (links to My Meetings and the document) | — |

A storage failure after the meeting row is created leaves the meeting
recorded and reports the error in the chat's saved-document card.

### 4.4 Storage path

Storage goes through the normal primary-file path: `DwgAddCommand` creates
the document, then `file_dwg_primary_add()` handles it
(`GitFileStorageBackend::store()`: worktree sync, commit as the user, push),
and the result is registered with `file_dwg_primary_register()` (agenda) or
`file_dwg_primary_draft_register()` (minutes). The agenda's registration pins
an approved ref, as every first upload does.

### 4.5 Open design questions

Decided 2026-10-02: agendas go On Record at once; the chair approves minutes
(§4.1). Still open:

1. **Stray drafts on meeting documents.** A Draft uploaded by hand through the
   document page while the meeting is still at "agenda issued" can no longer
   be promoted by anyone, because only the chair may promote, and only minutes
   awaiting approval. Should hand uploads to meeting documents be blocked, or
   count as minutes?
2. **Approval notice to invitees** once minutes are approved?
3. **Departments.** HCRQMS is one project with `content/<department>`
   directories; only `system/meetings` exists so far. HCRQMS also has
   field-service, finance, safety and sales departments that are not
   configured. Should departments come from configuration, sub-projects, or
   the repository?
4. **Recurring meetings and series** (e.g. the weekly QMS review): link
   meetings and pre-fill "approval of last minutes"?
5. **Action items → issues** (ai-engine.md Phase 3).
6. **Editing outside the chat:** reschedule, cancel or change invitees from
   My Meetings, or always through the assistant?
7. **Visibility:** My Meetings shows only the user's own meetings. Should
   managers see department meetings?

---

## 5. Configuration reference

Set in `config/config_inc.php` (never committed).

| Key | Default | Description |
|-----|---------|-------------|
| `$g_anthropic_api_key` | `getenv('ANTHROPIC_API_KEY')` or `''` | Blank disables the AI Assistant and hides the sidebar button. My Meetings still works (no Plan/Write actions). |
| `$g_ai_model` | `'claude-sonnet-4-6'` | Model ID sent to the API. |
| `$g_ai_assist_threshold` | `REPORTER` | Minimum global access level for the AI page and API. |
| `$g_meeting_project_id` | `0` | Project whose repository holds meeting records (HCRQMS). 0 = record meetings without documents. Installation-specific, so the native template does not set it. |
| `$g_meeting_template_path` | `'system/templates/Meeting-Agenda-and-Minutes.md'` | Template, read from the meeting project's repository HEAD. |
| `$g_meeting_category` | `'Meetings'` | Category for meeting documents; created on first use. |
| `$g_ai_meeting_departments` | 8 departments | Code → name, record directory, optional own `project_id`. |

`$g_hcrqms_repo_path` is no longer read. A leftover line in an existing
`config_inc.php` is harmless.

---

## 6. Known constraints and gotchas

- **CSP:** all JavaScript must be in external files under `js/`.
- **Non-streaming:** replies arrive whole after 2–8 s. Streaming would need
  nginx `fastcgi_buffering off` (or `X-Accel-Buffering: no`) and PHP output
  buffering disabled for `ai_assist_api.php`.
- **Schema numbering:** in `admin/schema.php` the `# ── Step N` labels before
  step 60 run **two behind** the real `$g_upgrade` index (`dwg_primary_draft`,
  labelled 57, is index 59). `database_version` records the real index. New
  tables go at the end and are applied on an existing database with
  `php admin/upgrade_unattended.php` (non-destructive, unlike a rebuild).
- **Permissions for storage:** creating the agenda document needs
  `create_dwg_threshold` in the meeting project. Storing minutes needs
  `update_dwg_threshold` on the document.
- **Agenda email** is raw Markdown with links prepended, sent through the
  MantisBT queue.
- **History growth:** the whole conversation is re-sent every turn.

---

## 7. To-do list

### Done

- [x] Help tab, chat UI, session persistence, server-side prompts (June 2026)
- [x] High-inference agenda drafting; invitee matching; agenda email (June 2026)
- [x] Meeting entity and API (`{meeting}`, `{meeting_invitee}`, `core/meeting_api.php`)
- [x] Meeting records stored as Doctis documents in the meeting project; `$g_hcrqms_repo_path` and direct git helpers removed
- [x] Template read from the repository
- [x] Validation of model-supplied attributes; reference built server-side; saved-document card escapes output
- [x] Minutes flow defined; meeting focus via `?meeting_id=`; attendance recorded
- [x] My Meetings view in the My View tabs

### Next — prove it works

- [ ] **Owner actions on the VM** (§1): pull, reset version to 61, set `$g_meeting_project_id`.
- [ ] **End-to-end test:** load sample users or opt users in to invites; run an
  agenda conversation, then a minutes conversation from My Meetings. Check the
  meeting rows, the document (`Meetings` category, path, On Record), the
  minutes Draft, commit authorship, the push to the bare repository, and the
  queued emails. Then record the procedure in `doc/TESTING.md`.
- [x] Chair approval of minutes (promote Draft, status 30); approval request email to the chair
- [ ] Department configuration review (§4.5 question 3).

### Later

- [ ] Reschedule / cancel / edit invitees from My Meetings
- [ ] Action items → Doctis issues linked to the meeting document
- [ ] Agenda email as HTML or with the file attached; calendar invitation (`.ics`)
- [ ] Automated tests: marker parsing, attribute validation, role checks, reference uniqueness
- [ ] `max_tokens` per-mode configuration; review `$g_ai_model` default
- [ ] Per-user rate limiting and a usage/audit log
- [ ] Streaming responses (SSE)

### SOP Interview tab (GUID-SYS-007 Part B) — not started

- [ ] Eight-phase interview prompt with department requirements injected server-side
- [ ] OFI log update
- [ ] Review routing: reconsider GitHub PRs vs the Doctis review workflow, now
  that HCRQMS is a Doctis repository

### Other tab — not started

- [ ] Decide scope after the meeting feature settles.

---

## 8. Inspecting AI state on the development VM

The `doctis` database account in the running clone's `config/config_inc.php`
can be used from the shell without copying the password anywhere:

```bash
export MYSQL_PWD=$(php -r 'require "/var/www/html/doctis/core/constant_inc.php";
  include "/var/www/html/doctis/config/config_inc.php"; echo $g_db_password;')
mysql -h 127.0.0.1 -u doctis doctis -e "
  SELECT id, doc_ref, title, status, dwg_id, FROM_UNIXTIME(date_start) FROM meeting;
  SELECT * FROM meeting_invitee;
  SELECT user_id, mode, doc_id, dwg_id, FROM_UNIXTIME(updated) FROM ai_sessions;"
```

`constant_inc.php` must be loaded first because `config_inc.php` uses
constants such as `OFF`. This account has full privileges on `doctis.*`, so
use it read-only unless a change is intended.

PHP errors are logged under `/var/log/doctis` and `/var/log/nginx`.

---

## Revision history

| Date | Change |
|------|--------|
| 2026-06-17 | Initial version — Phase 1 (Help tab) |
| 2026-06-18 | Phase 2 — DB persistence, token display, Markdown, context injection, copy button |
| 2026-06-18 | Phase 3 core — Meeting tab, server-side prompt, HCRQMS write, git commit, department config, JS factory |
| 2026-10-02 | Rewritten against `dev`; documented that meeting storage was incompatible with the git repository entity |
| 2026-10-02 | `ai-meeting`: meeting entity, document-backed records, minutes flow, validation, My Meetings view; open design questions (§4.5) |
