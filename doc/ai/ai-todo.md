# Doctis AI Assistant — Status and To-Do

**Branch:** `dev` (the original `ai-assist` branch was merged; there is no separate AI branch)
**Status as of:** 2026-10-02 — Help tab working; Meeting tab partly working (see §1); SOP and Other tabs not started
**Companion document:** [ai-engine.md](ai-engine.md) — the original concept plan and platform assessment (June 2026)

This is a living document. It records what exists, how it works, what is
known to be broken, and what remains to do. Read it before working on any AI
feature, and update it when work is completed or decisions change. Keep
statements about verified behaviour separate from statements about code that
has only been read.

---

## Contents

1. [Current status](#1-current-status)
2. [File map](#2-file-map)
3. [How the pipeline works](#3-how-the-pipeline-works)
4. [Meeting tab in detail](#4-meeting-tab-in-detail)
5. [Configuration reference](#5-configuration-reference)
6. [Known constraints and gotchas](#6-known-constraints-and-gotchas)
7. [To-do list](#7-to-do-list)
8. [Inspecting AI state on the development VM](#8-inspecting-ai-state-on-the-development-vm)

---

## 1. Current status

| Tab | State |
|-----|-------|
| **Help** | Working proof of concept. Chat with server-side system prompt, session persistence, token display, Markdown rendering, copy button. |
| **Meeting** | Chat works and drafts agendas. **Saving, committing and registering meeting records does not work** with the current Doctis git-storage design (§4.3). Doctis document registration is an empty stub. |
| **SOP** | "Coming soon" placeholder. |
| **Other** | "Coming soon" placeholder. |

The AI code was written in June 2026 (commits `040a95679` … `e39d705ca`,
2026-06-16 to 2026-06-19) and has not changed since. During that period Doctis
gained the git repository entity (`{repository}`, `{project_repository}`,
`core/repository_api.php`), path-as-data registration
(`file_dwg_primary_register()`), and the HCRQMS ZIP import. The Meeting tab
predates all of these and still assumes HCRQMS is a plain git working tree at
`$g_hcrqms_repo_path`.

### State of the native development VM (checked 2026-10-02)

- `http://10.0.0.94/doctis/ai_assist_page.php` is served by the running clone
  on `dev`. An Anthropic API key is configured, so the sidebar button and tabs
  are active.
- `$g_hcrqms_repo_path = '/var/git/doctis'` in both `config_defaults_inc.php`
  and the running `config_inc.php`. That directory is the store for Doctis's
  bare repositories, not an HCRQMS checkout (§4.3).
- HCRQMS is imported as Doctis project 1 (`HCRQMS`), repository 1
  (`/var/git/doctis/hcrqms-r1.git`, branch `dev`, worktree
  `/var/www/doctis/worktrees/hcrqms-r1`). The meeting template
  `system/templates/Meeting-Agenda-and-Minutes.md` and an existing record
  `system/meetings/MIN-SYS-20260917.md` are in that repository.
- `{ai_sessions}` is empty: no AI chat has been run on this VM.
- One user (`administrator`), with `meeting_invite = 0`, so the Meeting tab's
  candidate-invitee list is empty.
- SMTP is not configured on this VM, so agenda emails would only be queued.

None of the AI functions have automated tests.

---

## 2. File map

| File | Purpose |
|------|---------|
| [`ai_assist_page.php`](../../ai_assist_page.php) | Page shell: auth, shared CSS, tab navigation, SOP/Other placeholders, `<script src>` tags |
| [`ai_assist_help_page.php`](../../ai_assist_help_page.php) | Help tab panel HTML (included by the page) |
| [`ai_assist_meeting_page.php`](../../ai_assist_meeting_page.php) | Meeting tab panel HTML (included by the page) |
| [`ai_assist_api.php`](../../ai_assist_api.php) | AJAX endpoint: request validation, `load`/`clear`/`chat` actions, Anthropic call, `{ai_sessions}` CRUD |
| [`ai_assist_help_api.php`](../../ai_assist_help_api.php) | `ai_assist_help_system_prompt()` |
| [`ai_assist_meeting_api.php`](../../ai_assist_meeting_api.php) | Meeting system prompt, candidate invitees, document extraction, file write, agenda email, git commit, registration stub |
| [`js/ai_assist.js`](../../js/ai_assist.js) | Shared JS: `renderMarkdown()`, `createChatSession(cfg)` factory, tab/hash handling; exports `window.AiAssist` |
| [`js/ai_assist_help.js`](../../js/ai_assist_help.js) | Help tab `createChatSession` instance |
| [`js/ai_assist_meeting.js`](../../js/ai_assist_meeting.js) | Meeting tab `createChatSession` instance |
| [`core/layout_api.php`](../../core/layout_api.php) | Sidebar "AI Assistant" entry (search for `ai_assist_threshold`) |
| [`lang/strings_english.txt`](../../lang/strings_english.txt) | `ai_assist_*` and `meeting_invite*` strings |
| [`config_defaults_inc.php`](../../config_defaults_inc.php) | `$g_anthropic_api_key`, `$g_ai_model`, `$g_ai_assist_threshold`, `$g_hcrqms_repo_path`, `$g_ai_meeting_departments` |
| [`admin/tools/templates/native-app-settings.php`](../../admin/tools/templates/native-app-settings.php) | AI settings appended to new native `config_inc.php` files |
| [`admin/schema.php`](../../admin/schema.php) | `{ai_sessions}` table (step 1); `meeting_invite` column on `{user}` |
| [`my_view_cnf_page.php`](../../my_view_cnf_page.php), [`my_view_cnf_update.php`](../../my_view_cnf_update.php), [`core/commands/UserProfileUpdateCommand.php`](../../core/commands/UserProfileUpdateCommand.php) | User profile "Meeting Invites" setting (0 = never, 1 = department, 2 = all) |

---

## 3. How the pipeline works

### Request flow (one user turn)

```
Browser (js/ai_assist.js, createChatSession)
  │  POST ai_assist_api.php
  │  Headers: Content-Type: application/json, X-Requested-With: XMLHttpRequest
  │  Body:    { action: 'chat', mode, history[] }
  ▼
ai_assist_api.php
  │  — POST + XHR header check, auth, ai_assist_threshold
  │  — sanitise history (role/content only; last turn must be 'user')
  │  — build system prompt server-side for the mode
  │  — POST https://api.anthropic.com/v1/messages (non-streaming, 90 s timeout)
  │  — meeting mode: ai_assist_process_meeting_document() on the reply
  │  — upsert {ai_sessions} row for (user, mode)
  ▼
{ reply, error, usage, saved_document }
  ▼
js/ai_assist.js — append bubble, update token count, show saved-document card
```

The `load` action returns the stored history for (user, mode) when the page
opens; `clear` deletes it.

### System prompts

Both prompts are built **server-side**; the browser never sends one (the
`systemPrompt` field in the JS config is unused and left blank).

- **Help:** `ai_assist_help_system_prompt()` — describes Doctis and the
  document workflow, and adds the current project name and document count.
- **Meeting:** `ai_assist_meeting_system_prompt()` — see §4.1.

### Session persistence

`{ai_sessions}` holds one row per user per mode: `history` (JSON message
array, LONGTEXT), `created`/`updated` (Unix timestamps), and, for meetings,
`doc_id` (HCRQMS ID such as `MIN-SYS-20260917`) and `dwg_id` (always NULL
until registration exists). The full history is re-sent to the API on every
turn.

### Token limits

`max_tokens` is set per mode in `ai_assist_api.php`: 2048 for Help, 4096 for
Meeting. Neither is configurable.

---

## 4. Meeting tab in detail

### 4.1 Conversation design (commit `3f3aa09a8`, "high-inference mode")

The prompt aims for two to four exchanges:

1. The user describes the meeting in one sentence. Claude infers the date,
   time, duration (default 60 min), attendees, subject, department and
   location (default Microsoft Teams). The chair is the current user and the
   minute taker is the first invitee named. Claude replies with a
   time-allocated draft agenda and asks one question.
2. The user confirms or asks for changes.
3. On confirmation Claude emits the document between markers:

```
<<<MEETING_DOCUMENT type="agenda|minutes" doc_id="MIN-{dept}-{YYYYMMDD}" dept="{dept}" title="…" invitee_ids="1,5,9">>>
…Markdown following TMPL-SYS-001…
<<<END_MEETING_DOCUMENT>>>
```

The prompt includes the department list from `$g_ai_meeting_departments`, the
candidate invitees (enabled users with `meeting_invite != 0`), and the HCRQMS
meeting template if it can be read from
`$g_hcrqms_repo_path/system/templates/Meeting-Agenda-and-Minutes.md`;
otherwise it falls back to a built-in outline.

**Minutes mode is underspecified.** The welcome text invites the user to type
"minutes", but the current prompt only defines the marker for minutes. It does
not describe how to capture the discussion, and nothing loads the earlier
agenda file into the conversation. The earlier two-mode flow from ENG-TASK-002
§4.4 was replaced by the 06-19 rewrite.

### 4.2 Document processing (as coded)

`ai_assist_process_meeting_document()` strips the marker block from the reply
and then:

| Step | Agenda | Minutes |
|------|--------|---------|
| Write `{repo}/{dept path}/{doc_id}.md` | yes | yes |
| Email each `invitee_ids` user via `email_store()` (uses `email_secondary` if set) | yes | no |
| `git add` + `git commit` as the current user | no | yes |
| `ai_assist_register_meeting_doctis()` when the department `project_id > 0` | no | yes (stub returns `null`) |

With `$g_hcrqms_repo_path` blank, nothing is written; agenda emails are still
sent.

### 4.3 Why saving does not work with current Doctis storage

- `$g_hcrqms_repo_path` points at `/var/git/doctis`, the parent of the bare
  repositories. The template read fails, so the built-in outline is used. An
  agenda would be written as `/var/git/doctis/system/meetings/…`, inside the
  bare-repository store. `git add` there fails because it is not a working
  tree, so minutes would never be committed. None of this has run on this VM
  (no such directories exist).
- Even if pointed at `/var/www/doctis/worktrees/hcrqms-r1`, the code would
  bypass the repository API: no storage lock, no push to the bare repository
  (Doctis pushes as the last step of every store), and uncommitted agenda
  files would be left in a worktree that Doctis syncs to HEAD.
- The department paths are hardcoded. Only `system/meetings` exists in the
  HCRQMS repository; the `content/*/meetings` directories do not. HCRQMS also
  has `field-service`, `finance`, `safety` and `sales` departments that are
  not configured.
- `doc_id`, `dept` and `invitee_ids` come from model output (which the user
  can steer) and are used without validation: `doc_id` becomes a filename
  (path traversal is possible), and `invitee_ids` can name any user, not only
  opted-in candidates.

The native install template copies the same `/var/git/doctis` value into every
new `config_inc.php`, and AGENTS.md records that these settings were copied
from production, so the production value should be checked.

### 4.4 Intended direction

Treat a meeting record as an ordinary Doctis primary document in the
HCRQMS repository:

1. Resolve the repository from the HCRQMS **project** (repository API /
   `dwg_project_*` wrappers in `core/file_dwg_api.php`), not from a filesystem
   path. `$g_hcrqms_repo_path` then becomes unnecessary (or is replaced by a
   project ID setting).
2. Read the template from the repository at its HEAD (or approved ref) rather
   than from disk.
3. Create the document record and store the file through the existing
   upload path (`DwgAddCommand` + `GitFileStorageBackend::store()`, which
   commits as the user and pushes), registered with
   `file_dwg_primary_register()`. The minutes then replace the agenda file as
   a new revision of the **same** document, rather than being a separate file.
4. Validate `doc_id` with `file_dwg_git_path_sanitize()` and check the
   department code and invitee IDs against the configured lists.

Relevant existing functions: `dwg_project_repository_id()`,
`dwg_project_worktree_path()`, `dwg_git_worktree_sync()`,
`file_dwg_git_path_sanitize()` and `file_dwg_primary_register()` in
`core/file_dwg_api.php`.

This fulfils Phase 2 of [ai-engine.md](ai-engine.md) (document registration)
and removes the separate git helpers in `ai_assist_meeting_api.php`.

---

## 5. Configuration reference

Set in `config/config_inc.php` (never committed). On native installs, new
configurations get the AI block from `admin/tools/templates/native-app-settings.php`.

| Key | Default | Description |
|-----|---------|-------------|
| `$g_anthropic_api_key` | `getenv('ANTHROPIC_API_KEY')` or `''` | Blank disables the feature and hides the sidebar button. |
| `$g_ai_model` | `'claude-sonnet-4-6'` | Model ID sent to the API (the native template also honours `AI_MODEL`). |
| `$g_ai_assist_threshold` | `REPORTER` | Minimum global access level for the page and API. |
| `$g_hcrqms_repo_path` | `'/var/git/doctis'` | HCRQMS working-tree path for meeting records. The current default is wrong (§4.3); its docblock says the default is blank, which it is not. |
| `$g_ai_meeting_departments` | 8 departments, all `project_id => 0` | Department code → name, output path, Doctis project ID. |

The API key is billed against the Anthropic account that owns it. Set a usage
limit in the Anthropic Console.

---

## 6. Known constraints and gotchas

- **CSP:** MantisBT sends `script-src 'self'`. All JavaScript must be in
  external files under `js/`; inline `<script>` is silently blocked. Inline
  `<style>` is allowed.
- **Non-streaming:** replies arrive whole after 2–8 s; the typing indicator
  covers the wait. Streaming (SSE) would need output buffering disabled for
  `ai_assist_api.php` in nginx/PHP-FPM (`fastcgi_buffering off`, or the
  `X-Accel-Buffering: no` header) and in PHP.
- **Config key names:** code calls `config_get_global( 'ai_model' )` etc.
  Renaming a `$g_` default requires changing every caller.
- **Schema:** `{ai_sessions}` is a `CREATE TABLE IF NOT EXISTS` block in
  `admin/schema.php`. Follow the flat-schema rules in CLAUDE.md; the old
  `admin/ai_sessions_migrate.php` was deleted. On the native VM, rebuilding the
  database also wipes the imported HCRQMS data (see DEV-SETUP.md).
- **History growth:** the whole conversation is re-sent every turn, so cost
  grows with session length. One meeting session is well within the context
  window.
- **Email:** agenda bodies are raw Markdown, sent through the normal MantisBT
  queue (`email_store()` and the cron sender). Nothing is delivered on the
  native VM until SMTP is configured.

---

## 7. To-do list

### Done (June 2026)

- [x] Sidebar button gated on API key and `ai_assist_threshold`
- [x] Four-tab page; URL hash selects the tab
- [x] Chat UI: bubbles, typing indicator, send/stop/clear, Markdown (code, bold/italic, lists, rules), copy button, token count
- [x] Authenticated proxy to the Anthropic Messages API, with 401/429/529 mapped to user messages
- [x] Session persistence in `{ai_sessions}` (`load`/`clear`/upsert)
- [x] Server-side system prompts for both modes; per-mode PHP/JS modules (`f62d1563e`)
- [x] Help: current-project context in prompt
- [x] Meeting: high-inference agenda drafting, chair/minute-taker rules, Teams default
- [x] Meeting: candidate invitees from `{user}.meeting_invite`; profile setting
- [x] Meeting: agenda email to matched invitees

### Next — make the Meeting tab work on current Doctis storage

- [ ] **Fix the HCRQMS location.** Resolve the repository from the HCRQMS
  project instead of `$g_hcrqms_repo_path`. Until then, at least correct the
  default and the native template (blank = chat-only), and fix the docblock.
- [ ] **Read the template from the repository** instead of from the filesystem.
- [ ] **Store and register through Doctis** (§4.4): create the document, store via
  `GitFileStorageBackend` (commit + push under the storage lock), and register
  with `file_dwg_primary_register()`. Delete `ai_assist_git()` /
  `ai_assist_git_commit_meeting()` once replaced. Fill `{ai_sessions}.dwg_id`.
- [ ] **Validate model-supplied attributes:** sanitise `doc_id`, reject unknown
  departments, and restrict `invitee_ids` to the candidate list.
- [ ] **Department mapping:** derive departments from the HCRQMS project and
  sub-project structure (or `content/*` directories), or update
  `$g_ai_meeting_departments` to match the repository and give each one a
  project ID.
- [ ] **Define minutes mode:** load the agenda (from the session's
  `doc_id`/`dwg_id`) into the conversation, specify the minutes capture flow,
  and store the minutes as a revision of the agenda document.
- [ ] **Test on the VM:** opt sample users in to meeting invites, run agenda and
  minutes sessions, and confirm the commit attribution, the push, the document
  record and the queued emails. Add the procedure to `doc/TESTING.md`.

### Later

- [ ] Action items → Doctis issues linked to the meeting document ([ai-engine.md](ai-engine.md) Phase 3)
- [ ] Agenda email: render Markdown to HTML or attach the file; include a link to the document
- [ ] `max_tokens` per-mode configuration (`$g_ai_max_tokens_help`, `$g_ai_max_tokens_meeting`)
- [ ] Review `$g_ai_model` default against current Claude models
- [ ] Per-user rate limiting and a usage/audit log (user, mode, tokens, time)
- [ ] Streaming responses (SSE) — see §6 for the nginx requirements
- [ ] Automated tests for the prompt builders, marker parsing and attribute validation

### SOP Interview tab (GUID-SYS-007 Part B) — not started

- [ ] System prompt for the eight-phase interview, with department requirements
  (`system/guidance/QMS Departmental Requirements.md`) injected server-side
- [ ] OFI log update (`system/guidance/interview-ofi-log.md`)
- [ ] Review routing. The original plan raised GitHub pull requests
  (`$g_github_token`, `reviewers.yaml`). Now that HCRQMS is a Doctis
  repository, reconsider using the Doctis review workflow instead.

### Other tab — not started

- [ ] Decide scope (free-form assistant, or host for future tools) after the Meeting tab is complete.

### Deferred / under consideration

- [ ] Python microservice for Claude calls if streaming or SOP complexity
  outgrows PHP ([ai-engine.md](ai-engine.md) §5.3); on the native VM this
  would be a `systemd` unit proxied by PHP or nginx.

---

## 8. Inspecting AI state on the development VM

The `doctis` database account in the running clone's `config/config_inc.php`
can be used from the shell without copying the password anywhere:

```bash
export MYSQL_PWD=$(php -r 'require "/var/www/html/doctis/core/constant_inc.php";
  include "/var/www/html/doctis/config/config_inc.php"; echo $g_db_password;')
mysql -h 127.0.0.1 -u doctis doctis -e "
  SELECT user_id, mode, doc_id, dwg_id, FROM_UNIXTIME(updated) FROM ai_sessions;
  SELECT id, username, department, meeting_invite FROM user WHERE meeting_invite != 0;"
```

`constant_inc.php` must be loaded first because `config_inc.php` uses
constants such as `OFF`. This account has full privileges on `doctis.*`, so
use it read-only unless a change is intended.

Application errors from `error_log()` go to the PHP-FPM/nginx logs under
`/var/log/doctis` and `/var/log/nginx`.

---

## Revision history

| Date | Change |
|------|--------|
| 2026-06-17 | Initial version — Phase 1 (Help tab) |
| 2026-06-18 | Phase 2 — DB persistence, token display, Markdown, context injection, copy button |
| 2026-06-18 | `{ai_sessions}` added to schema.php |
| 2026-06-18 | Phase 3 core — Meeting tab, server-side prompt, HCRQMS write, git commit, department config, JS factory |
| 2026-10-02 | Rewritten against the 2026-10-02 `dev` state: records the 06-19 module split, high-inference prompt, invitee matching and agenda email; documents that meeting storage is incompatible with the git repository entity and sets out the fix; adds VM inspection notes |
