# Doctis AI Assistant — Status and To-Do

**Branch:** `ai-meeting` (meeting work in progress; `dev` holds the June 2026 state)
**Status as of:** 2026-10-03 — Help tab answers from the user manual, a live navigation map and a knowledge base users teach (see [ai-knowledge.md](ai-knowledge.md)); meeting lifecycle (plan → change/cancel → minutes with actions → chair approval → issues → next meeting in series → recurring meetings scheduled automatically) **passed end to end on the VM**; My Meetings (all meetings for managers) and meeting pages; calendar invitations; SOP and Other tabs not started. **Not to be merged into `dev` until a full suite of testing is completed** (owner).
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
| **Help tab** | Answers from the user manual, a live map of the pages the user can open, and the knowledge base; users teach it ("Correct this"), managers review entries on the Knowledge page. Cached prompt. Design and verification: [ai-knowledge.md](ai-knowledge.md). |
| **Knowledge base** | `{ai_knowledge}`, shared by the Help and Meeting assistants; entries unverified until a manager publishes them; Knowledge tab (`ai_knowledge_page.php`). |
| **Meeting tab** | Plans agendas (optionally as the next meeting of a series) and writes minutes with a structured action list. Records the meeting, stores the record as a Doctis document in the meeting project (agenda On Record, minutes as a Draft for the chair), and emails participants with calendar invitations. |
| **Meeting page** | `meeting_view_page.php`: details, participants and attendance, actions and their issues, the series, and role-based actions: Write/Revise/Approve Minutes, Change Meeting (reschedule, location, minute taker, invitees), Cancel Meeting, Plan Next Meeting, Calendar download. |
| **My Meetings** | My View tab listing upcoming and past meetings (cancelled ones struck through, repeating ones marked), linking to the meeting pages, with a Minutes due indicator. Managers (`$g_meeting_view_all_threshold`) can switch to All meetings. |
| **Recurring meetings** | A series repeats weekly, fortnightly or monthly (same weekday), set in the agenda conversation or on the meeting page. `scripts/meeting_schedule.php` (hourly cron) creates each next meeting 3 days ahead through the assistant. |
| **Verified** | 2026-10-02, real API ([TESTING.md §7a](../TESTING.md)): MIN-QA-20261020 (meeting 27, document #130) was planned, rescheduled, minuted by frodo with three actions, and approved; this created issues #1–#3, assigned and linked to the approved minutes. MIN-QA-20261027 (meeting 28, document #131) was planned in the series from "same time next week", then cancelled. Series 27 was then set weekly and the scheduler, run as of 31 Oct, created MIN-QA-20261103 (meeting 45, document #132) with the same people and the open actions carried forward; a re-run created nothing. The manager/reporter visibility split was checked. 21 meeting PHPUnit tests and the full `mantis` suite (237) pass. |
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
  direct git helpers were removed. Then added chair approval, the email
  lifecycle, the meeting page, change and cancel, calendar invitations,
  series, actions as issues, and hand uploads counted as minutes.

### State of the native development VM (2026-10-02)

- The running clone (`/var/www/html/doctis`) is on `ai-meeting`, updated by
  `git pull --ff-only /home/robert/Documents/doctis ai-meeting` (nothing
  pushed to GitHub). `{meeting}`, `{meeting_invitee}`, `{meeting_action}` and
  `{meeting_series}` exist; `database_version` is 63. The meeting tables were
  recreated on 2026-10-02 when `{meeting}` gained columns. That removed the
  rows of the first two test meetings, whose documents #128 and #129 remain as
  ordinary documents.
- The running `config_inc.php` has `$g_meeting_project_id = 1;` (HCRQMS),
  replacing the obsolete `$g_hcrqms_repo_path` line.
- `/etc/cron.d/doctis` runs `scripts/meeting_schedule.php` hourly (minute 17).
  No series is set to repeat; the test series was stopped after its test.
- Test artefacts in HCRQMS: documents #128–#132 and their commits; meetings
  27 (approved; issues #1–#3, due dates backfilled after due dates were
  enabled), 28 (cancelled) and 45 (scheduled automatically).
- **Time zone:** `$g_default_timezone` is unset and users have no timezone
  preference, so Doctis works in UTC while the server is Australia/Sydney.
  Times are consistent inside Doctis, and calendar invitations are written
  in UTC, so they are correct. Deferred by the owner.
- HCRQMS is Doctis project 1, repository 1 (`hcrqms-r1`, branch `dev`). The
  template `system/templates/Meeting-Agenda-and-Minutes.md` and a hand-made
  record `system/meetings/MIN-SYS-20260917.md` are in that repository.
- Sample users were loaded on 2026-10-02 (`load_testing_user` only; the
  script's example project would collide with HCRQMS as project 1). Seven of
  them accept invitations: frodo, gandalf and legolas (all meetings), and
  sam, pip, merry and gimli (department only). Passwords are blank except
  `admin`'s.
- **Outbound email is live** (Gmail SMTP, sent by cron every minute; see
  DEV-SETUP.md). Meeting test emails so far went only to `.example`
  addresses and `root@localhost`. Before a test, check who will be emailed;
  to inspect messages without sending, see TESTING.md §7a.
- frodo (Developer) and sam (Updater) are members of HCRQMS for tests.
  gandalf is not a member, but as a global manager he can read private
  projects (`private_project_threshold` = 70); merry and the others cannot.

Meeting logic has PHPUnit coverage (`tests/Mantis/MeetingApiTest.php`, 17
tests). The Help tab and the prompts themselves do not.

---

## 2. File map

| File | Purpose |
|------|---------|
| [`ai_assist_page.php`](../../ai_assist_page.php) | Page shell: auth, shared CSS, tab navigation, SOP/Other placeholders, `<script src>` tags |
| [`ai_assist_help_page.php`](../../ai_assist_help_page.php) | Help tab panel HTML |
| [`ai_assist_meeting_page.php`](../../ai_assist_meeting_page.php) | Meeting tab panel HTML; `?meeting_id=` opens minutes mode, `?series_of=` plans the next meeting |
| [`ai_assist_api.php`](../../ai_assist_api.php) | AJAX endpoint: request validation, `load`/`clear`/`chat`, Anthropic call, `{ai_sessions}` CRUD |
| [`ai_assist_help_api.php`](../../ai_assist_help_api.php) | `ai_assist_help_system_prompt()`: two system blocks (cached shared part; per-user part) |
| [`ai_assist_knowledge_api.php`](../../ai_assist_knowledge_api.php), [`core/ai_knowledge_api.php`](../../core/ai_knowledge_api.php) | Knowledge base: manual, navigation map, entries, teaching marker; entity and review |
| [`ai_knowledge_page.php`](../../ai_knowledge_page.php) / [`ai_knowledge_update.php`](../../ai_knowledge_update.php) | Knowledge page and handler |
| [`ai_assist_anthropic_api.php`](../../ai_assist_anthropic_api.php) | Messages API client (system blocks, text blocks joined, cache usage) |
| [`ai_assist_meeting_api.php`](../../ai_assist_meeting_api.php) | Meeting prompt (focus meeting, series, open actions), marker processing (agenda/minutes/actions), validation |
| [`core/meeting_api.php`](../../core/meeting_api.php) | Meeting entity: get/list/create/update, roles and permissions, attendance, series, record storage and rewriting, approval, change/cancel, hand-upload rules, email |
| [`core/meeting_calendar_api.php`](../../core/meeting_calendar_api.php) | iCalendar (`meeting_ics()`): REQUEST / CANCEL / PUBLISH |
| [`core/meeting_action_api.php`](../../core/meeting_action_api.php) | Actions: parse/validate, store, create issues on approval, open actions in a series |
| [`meeting_view_page.php`](../../meeting_view_page.php) | Meeting page |
| [`meeting_edit_page.php`](../../meeting_edit_page.php) / [`meeting_edit.php`](../../meeting_edit.php) | Change Meeting form and handler |
| [`meeting_cancel.php`](../../meeting_cancel.php) | POST handler: cancel (with confirmation) |
| [`meeting_ics.php`](../../meeting_ics.php) | Calendar download |
| [`my_view_meeting_page.php`](../../my_view_meeting_page.php) | My Meetings view |
| [`meeting_minutes_approve.php`](../../meeting_minutes_approve.php) | POST handler: chair approves minutes |
| [`dwg_primary_file_sync_head.php`](../../dwg_primary_file_sync_head.php), [`dwg_view_inc.php`](../../dwg_view_inc.php) | Meeting documents: promote-Draft is chair-only and marks the meeting approved; upload form follows the hand-upload rule; notice linking to the meeting page |
| [`dwg_primary_file_update.php`](../../dwg_primary_file_update.php), [`api/soap/mc_dwg_primary_api.php`](../../api/soap/mc_dwg_primary_api.php) | Upload paths: refuse or treat as draft minutes for meeting documents |
| [`core/email_inc_api.php`](../../core/email_inc_api.php), [`core/classes/EmailMessage.class.php`](../../core/classes/EmailMessage.class.php), [`core/classes/EmailSenderPhpMailer.class.php`](../../core/classes/EmailSenderPhpMailer.class.php) | Doctis-marked MantisBT edits: queued email may carry string attachments (`metadata['attachments']`) |
| [`js/ai_assist.js`](../../js/ai_assist.js) | Shared JS: `renderMarkdown()`, `createChatSession(cfg)` (with `cfg.extra` request fields), tab/hash handling |
| [`js/ai_assist_help.js`](../../js/ai_assist_help.js) / [`js/ai_assist_meeting.js`](../../js/ai_assist_meeting.js) | Per-tab `createChatSession` instances; the meeting instance sends `meeting_id` / `series_of` |
| [`admin/tools/doctis-meeting-chat.py`](../../admin/tools/doctis-meeting-chat.py) | Test driver: chat as a user, plan a follow-up, approve minutes |
| [`tests/Mantis/MeetingApiTest.php`](../../tests/Mantis/MeetingApiTest.php) | Meeting PHPUnit tests |
| [`core/html_api.php`](../../core/html_api.php) | `print_my_view_menu()`: My Meetings tab |
| [`core/layout_api.php`](../../core/layout_api.php) | Sidebar "AI Assistant" entry (search for `ai_assist_threshold`) |
| [`core/file_dwg_api.php`](../../core/file_dwg_api.php) | `file_dwg_primary_add()`, which takes an optional explicit `git_path` for a first registration |
| [`lang/strings_english.txt`](../../lang/strings_english.txt) | `ai_assist_*`, `meeting_*`, `my_view_meeting_link` strings |
| [`config_defaults_inc.php`](../../config_defaults_inc.php) | AI and meeting settings (§5) |
| [`admin/schema.php`](../../admin/schema.php) | `{ai_sessions}` (index 1), `{meeting}` (60), `{meeting_invitee}` (61), `{meeting_action}` (62); `meeting_invite` on `{user}` |
| [`my_view_cnf_page.php`](../../my_view_cnf_page.php) and related | User profile "Meeting Invites" setting (0 never, 1 department, 2 all) |

---

## 3. How the pipeline works

### Request flow (one user turn)

```
Browser (js/ai_assist.js, createChatSession)
  │  POST ai_assist_api.php  { action: 'chat', mode, history[], meeting_id?, series_of? }
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

`{meeting}`: `series_id` (first meeting of the series, 0 = none), `sequence`
(calendar revision), `doc_ref` (unique, e.g. `MIN-SYS-20261015`), `title`,
`department`, `project_id`/`dwg_id`/`git_path` (the stored document, 0/'' if
none), `chair_id`, `minute_taker_id`, `date_start` (Unix), `duration` (min),
`location`, `status`, `created_by`, `date_created`, `date_updated`.

`{meeting_invitee}`: `meeting_id`, `user_id` (0 = guest without an account),
`name`, `attendance` (0 invited, 10 attended, 20 apologies).

`{meeting_action}`: `meeting_id`, `ref` (A1…), `description`, `owner_id`
(0 = no account), `owner_name`, `due_date`, `bug_id` (0 until approval).

Status: 10 agenda issued → 20 minutes awaiting approval → 30 minutes
approved; 90 cancelled (by the chair or organiser while at 10).

**Managing (owner decision 2026-10-02):** while only the agenda is issued, the
chair or organiser can reschedule, change the location, the minute taker
(must be an invitee) and the invitees (only users who accept invitations, or
named guests), or cancel. The agenda record is rewritten (frontmatter
`date`/`time`/`location`/`minute_taker`, the body's matching `**Label:**`
lines, and the Invitees table) and put On Record at once, like an issued
agenda. The title is not editable.

**Series:** **Plan Next Meeting** on the meeting page
(`ai_assist_page.php?series_of=N`) gives the assistant the previous meeting
and the series' open actions. The assistant can also link a "next week's
review" to one of the user's recent meetings. `series_of` is accepted only for
meetings the user leads.

**Visibility (owner decision):** users at `$g_meeting_view_all_threshold`
(MANAGER) see every meeting: All meetings on My Meetings, and any meeting
page. Seeing grants no approving, managing or minuting.

**Recurring meetings (owner decision: schedule themselves):** `{meeting_series}`
(keyed by the series root) holds `weekly`, `fortnightly` or `monthly` (same
weekday of the month; a 5th weekday becomes the last). It is set by the
agenda marker's `recurrence` attribute ("every Tuesday") or the meeting page's
Repeats control (chair or organiser), and stopped there. The scheduler
(`scripts/meeting_schedule.php`, hourly cron, single-run lock) creates the
next occurrence `$g_meeting_schedule_lead_days` (3) days ahead. The next
start is computed from the series' latest meeting, cancelled ones included;
the model for the new meeting is the latest meeting not cancelled. The
scheduler logs in as that meeting's chair and asks the assistant for the
agenda, then fixes date, time, department, duration, location, minute taker
and invitees from the previous meeting (`ai_assist_process_meeting_document()`
overrides). The usual storage, emails and calendar invitations follow, and
the chair gets a "Next meeting scheduled" email. Failures are recorded on the
series (`last_error`) and shown on the meeting page. A lapsed series resumes
with the next occurrence still to come.

**Action owners outside the project (owner decision):** on approval, an
owner who cannot handle issues in the meeting project is added to it at
their own global access level (`$g_meeting_action_owner_join`). Users with an
existing project entry are left unchanged, and the issue text records the
addition. **Known consequence:** approving minutes lets the chair bring
invitees into the private meeting project, without manage-project rights.

**Actions → issues:** minutes carry a `MEETING_ACTIONS` list. On approval
each action becomes an issue in the meeting project: category `meetings`,
summary `[REF A1] …`, linked to the meeting document (so the issue records
the approved minutes' SHA). The handler is the owner (joined to the project if
needed, above) when the approver may assign; otherwise the owner is named in
the issue text. The due date is set as the issue's due date. Due dates were
enabled by the owner: `$g_due_date_update_threshold` DEVELOPER, view REPORTER,
in `config_defaults_inc.php`; this also applies to document due dates. A chair
below DEVELOPER in the project still gets the due date in the text only. It is
always in the text. Actions are open until their issue reaches
`bug_resolved_status_threshold`.

**Hand uploads (owner decision):** a revision uploaded to a meeting document
through the document page or SOAP counts as draft minutes. It is accepted only
from the chair, minute taker or organiser, and only before approval; it moves
the meeting to 20 and circulates the draft.

**Approval (owner decision, 2026-10-02):** an issued agenda goes On Record
immediately. The **chair** approves the minutes with `meeting_minutes_approve()`,
which promotes the minutes Draft to On Record and sets status 30. The chair
can do this from the **Approve Minutes** button on My Meetings or the meeting
page (`meeting_minutes_approve.php`) or from the document page's promote-Draft
button. For meeting documents that button is shown to, and accepted from, the
chair only, instead of managers. Approval stamps the record
(`status: Approved Minutes`, `effective_date`) before promoting it, creates
the action issues, and emails the approved minutes.

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
    duration=… location=… minute_taker_id=… invitee_ids="1,5" guests="Name; Name" series_of=…>>>
<<<MEETING_DOCUMENT type="minutes" meeting_id=… attended_ids=… apology_ids=…>>>
<<<MEETING_ACTIONS>>> [{"ref","action","owner_id","owner","due"}] <<<END_MEETING_ACTIONS>>>
```

Server-side handling (`ai_assist_meeting_api.php`):

| Agenda | Minutes |
|--------|---------|
| Department must be configured; date/time must parse (user timezone) | Meeting must exist; user must be chair/minute taker/organiser; status 10 or 20 |
| Reference built as `MIN-{dept}-{Ymd}` (+`-2`… if taken); the model's `doc_id` is replaced in the content | Attendance IDs accepted only for the meeting's invitees |
| Invitee and minute-taker IDs accepted only from the candidate list; `guests` become account-less invitees | Action owners accepted only from the participants; invalid due dates dropped; up to 50 actions |
| `series_of` accepted only for a meeting the user leads | A revision of the minutes replaces the stored actions |
| Meeting row + invitees created | Status → 20 |
| If a meeting project is configured: document created (category `Meetings`, number = reference) and file stored at `{dept path}/{ref}.md`, **On Record** | If the meeting has a document: same file replaced, **staged as Draft** for the chair's approval |
| Agenda emailed to invitees | Draft emailed to all participants except the author, for corrections; the chair is asked to approve |

A storage failure after the meeting row is created leaves the meeting
recorded and reports the error in the chat's saved-document card.

### 4.3a Email (owner decision 2026-10-02: emailed content is enough)

Participants need not be members of the private meeting project; email is
how they read the record. `core/meeting_api.php` sends every stage with the
full record (YAML frontmatter stripped). A document link is added only for
recipients who can view the document, and every message links to the meeting page.

| Event | Recipients | Calendar | Notes |
|-------|-----------|----------|-------|
| Agenda issued | invitees with accounts | `invite.ics` REQUEST | date, time, duration, location, chair |
| Meeting changed | current invitees | REQUEST, SEQUENCE+1 | updated agenda |
| Invitee removed | that invitee | `cancel.ics` CANCEL | |
| Meeting cancelled | invitees | CANCEL | reason, if given |
| Minutes drafted | chair, minute taker and invitees, except the author | — | corrections deadline 3 business days ahead (TMPL-SYS-001 step 7); the chair's copy asks for approval |
| Minutes approved | the same, except the approver | — | approved record |

Calendar times are UTC with a stable UID (`doctis-meeting-<id>@<host>`), so
clients update or remove the event they already hold. Attachments travel
through the MantisBT queue in `metadata['attachments']`; the sender adds
them with PHPMailer.

My Meetings shows **Minutes due** (2 business days after the meeting ends,
TMPL-SYS-001 step 6) on meetings still at "agenda issued", in red when
overdue. Neither deadline is enforced.

### 4.4 Storage path

Storage goes through the normal primary-file path: `DwgAddCommand` creates
the document, then `file_dwg_primary_add()` handles it
(`GitFileStorageBackend::store()`: worktree sync, commit as the user, push),
and the result is registered with `file_dwg_primary_register()` (agenda) or
`file_dwg_primary_draft_register()` (minutes). The agenda's registration pins
an approved ref, as every first upload does.

### 4.5 Open design questions

Decided 2026-10-02 by the owner: agendas go On Record at once; the chair
approves minutes; emailed content is enough for participants outside the
meeting project; hand uploads count as minutes; departments stay in
configuration, with FS, FIN, SAF and SAL added; build series, actions to
issues, manage from the meeting page, and calendar invitations. Then: managers
see all meetings; action owners outside the project are added to it (the owner
expects this may raise a permission problem; see §4.1); due dates enabled;
repeating series schedule themselves; no merge into `dev` until a full suite
of testing is completed. Still open:

1. **Owner-join permissions:** should adding action owners to the private
   meeting project require the approving chair to hold manage-project rights,
   or a separate threshold, rather than the chair's approval alone?
2. **Changing the title** of an issued meeting is not supported (it appears in
   several places in the record).
3. **Calendar replies:** invitations are sent with `RSVP=FALSE`; accept and
   decline replies are not collected.
4. **Recurrence end:** a series repeats until stopped; there is no end date
   or occurrence count.

---

## 5. Configuration reference

Set in `config/config_inc.php` (never committed).

| Key | Default | Description |
|-----|---------|-------------|
| `$g_anthropic_api_key` | `getenv('ANTHROPIC_API_KEY')` or `''` | Blank disables the AI Assistant and hides the sidebar button. My Meetings still works (no Plan/Write actions). |
| `$g_ai_model` | `'claude-sonnet-4-6'` | Model ID sent to the API. |
| `$g_ai_assist_threshold` | `REPORTER` | Minimum global access level for the AI page and API. |
| `$g_ai_knowledge_review_threshold` | `MANAGER` | Reviews knowledge entries. |
| `$g_ai_knowledge_daily_limit` | `20` | Taught entries per user per day. |
| `$g_ai_knowledge_manual_path` | `'doc/MANUAL.md'` | Manual given to the assistant. |
| `$g_meeting_project_id` | `0` | Project whose repository holds meeting records (HCRQMS). 0 = record meetings without documents. Installation-specific, so the native template does not set it. |
| `$g_meeting_template_path` | `'system/templates/Meeting-Agenda-and-Minutes.md'` | Template, read from the meeting project's repository HEAD. |
| `$g_meeting_category` | `'Meetings'` | Category for meeting documents; created on first use. |
| `$g_meeting_view_all_threshold` | `MANAGER` | Global level that sees every meeting. |
| `$g_meeting_action_owner_join` | `ON` | Add action owners who cannot be assigned to the meeting project, at their own global level. |
| `$g_meeting_schedule_lead_days` | `3` | Days before a recurring meeting that the scheduler creates it. |
| `$g_due_date_update_threshold` / `$g_due_date_view_threshold` | `DEVELOPER` / `REPORTER` | Enabled for action due dates (MantisBT default NOBODY). |
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
- [x] Chair approval of minutes (stamp `Approved Minutes` + `effective_date`, promote Draft, status 30); approval request email to the chair
- [x] Records carry the TMPL-SYS-001 YAML frontmatter
- [x] Meeting roles authorise writing minutes in a private meeting project
- [x] End-to-end test passed; procedure and driver in [TESTING.md §7a](../TESTING.md) (`admin/tools/doctis-meeting-chat.py`)
- [x] Email lifecycle (agenda, draft minutes for corrections, approved minutes), document link only for viewers; Minutes due indicator
- [x] Meeting page; change (reschedule, location, minute taker, invitees) and cancel by chair/organiser
- [x] Calendar invitations (REQUEST/CANCEL attachments; download)
- [x] Meeting series; Plan Next Meeting; open actions carried into the next agenda
- [x] Actions → issues on approval, linked to the approved minutes
- [x] Hand uploads to meeting documents count as draft minutes (web and SOAP)
- [x] Departments FS, FIN, SAF, SAL added
- [x] Managers see all meetings; action owners join the project; due dates enabled (and backfilled on issues #1–#3)
- [x] Recurring meetings: series rule, scheduler script and cron, meeting-page control, chair notice
- [x] PHPUnit `tests/Mantis/MeetingApiTest.php` (21 tests); full lifecycle and scheduler verified live (§1)

### Next

- [ ] **Full suite of testing before merging into `dev`** (owner): define its scope (e.g. TESTING.md §9 regression sweep, §7a meeting run, browser checks of every meeting page, SOAP upload to a meeting document, a clean-install run of `admin/install.php` with the meeting tables).
- [ ] Open questions in §4.5.
- [ ] Cache the Meeting prompt (≈5.3k uncached input tokens per turn): split it into a shared cached block and a per-user block, as the Help prompt is.
- [ ] Knowledge base layer 3 ([ai-knowledge.md](ai-knowledge.md)): gap log of unanswered questions; `search_knowledge` tool once the knowledge base outgrows the prompt; optional export as a controlled document.
- [ ] **Time zone** (§1): deferred by the owner.

### Later

- [ ] Agenda email as HTML, or the record attached as a file
- [ ] Meeting title change (record rewrite in several places)
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
| 2026-10-02 | Chair approval, email lifecycle, meeting page, change/cancel, calendar invitations, series, actions → issues, hand uploads as minutes; owner decisions recorded (§4.5) |
| 2026-10-02 | Managers see all meetings; action owners join the meeting project; due dates enabled; recurring meetings scheduled automatically (`{meeting_series}`, `scripts/meeting_schedule.php`); merge deferred until full testing |
| 2026-10-03 | Knowledge base: live navigation map, manual and user-taught entries for Help and Meeting; Knowledge page; manual brought up to date (see ai-knowledge.md) |
