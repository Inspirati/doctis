# Doctis AI Engine — Implementation Log and To-Do

**Branch:** `ai-assist`  
**Status:** Active development  
**Companion document:** [doc/ai-engine.md](ai-engine.md) — architecture concept plan and platform assessment

This is a living document.  It records what has been built, how it works, and
what remains to do.  When working on AI features in this codebase, read this
document first.  Update it as work is completed or decisions change.

---

## Contents

1. [What is built](#1-what-is-built)
2. [File map](#2-file-map)
3. [How the pipeline works](#3-how-the-pipeline-works)
4. [Configuration reference](#4-configuration-reference)
5. [Known constraints and gotchas](#5-known-constraints-and-gotchas)
6. [To-do list](#6-to-do-list)

---

## 1. What is built

A working proof-of-concept AI chat page embedded in Doctis, using the Anthropic
Messages API (`claude-sonnet-4-6`) via a server-side PHP proxy.

### What works today

- **Sidebar button** — "AI Assistant" (`fa-comments` icon) appears in the left
  navigation on every page for any authenticated user at or above
  `$g_ai_assist_threshold` (default: `REPORTER`), provided `$g_anthropic_api_key`
  is non-blank.  Button is suppressed entirely when no key is configured.

- **`ai_assist_page.php`** — top-level page with four Bootstrap JS tabs:
  **Help**, **Meeting**, **SOP**, **Other**.  Tabs switch client-side (no page
  reload).  Active tab is reflected in the URL hash for bookmarkability.

- **Help tab** — fully functional conversational chat interface:
  - Chat bubble UI (user messages right-aligned, assistant left-aligned)
  - Animated typing indicator (three bouncing dots) while waiting for a response
  - Lightweight Markdown rendering in assistant replies: fenced code blocks,
    inline code, `**bold**`, newlines → `<br>`
  - Welcome message that disappears on first send and is restored (compact) on
    Clear
  - Character count warning above 3 500 chars
  - Send button / Ctrl+Enter / Shift+Enter to submit
  - Stop button to abort an in-flight request
  - Clear button to reset the conversation

- **Meeting, SOP, Other tabs** — placeholder "coming soon" panels with icons.

- **`ai_assist_api.php`** — authenticated AJAX endpoint:
  - Validates: POST method, `X-Requested-With: XMLHttpRequest` header,
    authentication, access level, API key configured
  - Sanitises the conversation history (strips unknown keys, validates roles)
  - Calls `https://api.anthropic.com/v1/messages` via PHP cURL
  - Returns `{reply, error, usage}` JSON
  - Maps Anthropic HTTP error codes 401 / 429 / 529 to user-friendly messages
  - Logs errors to Apache error log (`error_log()`)

---

## 2. File map

| File | Purpose |
|------|---------|
| `ai_assist_page.php` | Page shell: auth check, tab layout, chat HTML, CSS, `<script src>` tag |
| `ai_assist_api.php` | AJAX endpoint: validation, cURL call to Anthropic, JSON response |
| `js/ai_assist.js` | All client-side JS: chat state, XHR, DOM manipulation, event listeners |
| `core/layout_api.php` | Sidebar entry added at line ~857 (before QMS button) |
| `lang/strings_english.txt` | `ai_assist_link`, `ai_assist_title`, `ai_assist_tab_*` strings |
| `config_defaults_inc.php` | `$g_anthropic_api_key`, `$g_ai_model`, `$g_ai_assist_threshold` defaults |
| `doc/ai-engine.md` | Architecture concept plan (read before making structural changes) |

---

## 3. How the pipeline works

### Request flow (one user turn)

```
Browser (js/ai_assist.js)
  │
  │  POST ai_assist_api.php
  │  Headers: Content-Type: application/json
  │           X-Requested-With: XMLHttpRequest
  │  Body:    { mode, system, history[] }
  │
  ▼
ai_assist_api.php
  │  — auth + access level check
  │  — sanitise history array
  │  — build Anthropic payload
  │
  │  POST https://api.anthropic.com/v1/messages
  │  Headers: x-api-key, anthropic-version: 2023-06-01
  │
  ▼
Anthropic API  →  reply text
  │
  ▼
ai_assist_api.php
  │  — decode response
  │  — return { reply, error, usage }
  │
  ▼
js/ai_assist.js
  │  — append assistant bubble to DOM
  │  — push to chatHistory[]
```

### Conversation state

The full conversation history (`chatHistory` array in JS) is held **in browser
memory only**.  It is sent with every request so the stateless Anthropic API
receives full context on each turn.  History is **not** persisted to the
database or server filesystem in the current implementation.

Consequence: refreshing the page loses the conversation.  This is acceptable
for the Help tab but will need addressing for Meeting and SOP modes.

### System prompt

The system prompt that primes Claude's behaviour for each tab is a JavaScript
string constant defined at the top of `js/ai_assist.js` (`SYSTEM_PROMPT`,
lines 12–30).  It is sent on every API call as the `system` field.

**To change Help tab behaviour:** edit `SYSTEM_PROMPT` in `js/ai_assist.js`.
Changes take effect immediately on next page load (hard-refresh the browser
to bypass JS caching).

When Meeting and SOP modes are implemented, each will have its own system
prompt constant, sent with `mode: 'meeting'` or `mode: 'sop'` in the payload.
The API endpoint already accepts and forwards the `mode` field; it does not
currently use it server-side.

### CSP constraint

MantisBT sets `Content-Security-Policy: script-src 'self'` (no `'unsafe-inline'`).
**All JavaScript must be in external `.js` files under the Doctis web root.**
Inline `<script>` blocks are silently blocked by the browser.  This is why
`js/ai_assist.js` exists as a separate file rather than being embedded in the
page PHP.

Inline `<style>` blocks are permitted (`style-src 'self' 'unsafe-inline'` is
set).

---

## 4. Configuration reference

All keys are set in `config/config_inc.php` (never committed to git).

| Key | Default | Description |
|-----|---------|-------------|
| `$g_anthropic_api_key` | `''` | Anthropic API key (`sk-ant-...`).  Empty = AI Assistant disabled; sidebar button hidden. |
| `$g_ai_model` | `'claude-sonnet-4-6'` | Claude model identifier sent to the API. |
| `$g_ai_assist_threshold` | `REPORTER` | Minimum Doctis access level to see the sidebar button and use the page. |

The API key should be obtained from [console.anthropic.com](https://console.anthropic.com)
→ API Keys → Create Key.  It is billed against the Anthropic account that owns
the key.  Set a usage limit under Billing → Usage Limits.

---

## 5. Known constraints and gotchas

**Conversation history is browser-only.**  
Each page load starts a fresh session.  The full history array grows with each
turn and is re-sent to the API every time.  For long sessions this increases
token cost, but a complete Help tab session (10–20 turns) is well within the
200k token context window of Sonnet.

**System prompt is client-visible.**  
`SYSTEM_PROMPT` in `js/ai_assist.js` is a public static file.  Any user can
read it.  Do not embed credentials, internal-only process detail, or
confidential instructions in the system prompt.  For the Help tab this is not
an issue.

**`$g_ai_model` must match `config_defaults_inc.php` key name.**  
The default key is `ai_model` (no `g_` prefix in the internal config_get call):
`config_get_global( 'ai_model' )`.  If you rename the default, update both
`config_defaults_inc.php` and `ai_assist_api.php` line 91.

**Non-streaming responses.**  
The current implementation waits for the complete API response before updating
the UI (non-streaming).  Claude typically responds in 2–6 seconds for Help-tab
queries.  The typing indicator covers this delay acceptably.  Streaming (SSE)
would require Apache output-buffering configuration changes and is deferred to
a later phase.

**`max_tokens` is hardcoded at 2048** in `ai_assist_api.php` line 92.  
This is generous for Help tab answers.  Meeting and SOP document-generation
phases may need a higher value (e.g. 4096 for a full SOP draft).  Make this
configurable when those modes are implemented.

---

## 6. To-do list

Items are grouped by priority.  Tick boxes are updated as work is completed.

### Phase 1 — Help tab (proof-of-concept) ✓ complete

- [x] Sidebar "AI Assistant" button (gated on API key + access level)
- [x] `ai_assist_page.php` with four-tab layout (Help / Meeting / SOP / Other)
- [x] Chat UI: bubbles, typing indicator, send/stop/clear, Markdown rendering
- [x] `ai_assist_api.php`: authenticated proxy to Anthropic Messages API
- [x] `js/ai_assist.js`: external file (CSP compliance)
- [x] System prompt for Help mode in `js/ai_assist.js`
- [x] Welcome message dismisses on first send; compact message restored on clear
- [x] API key gate: page renders with configuration notice when key is absent
- [x] Error handling: Anthropic 401 / 429 / 529 mapped to user messages
- [x] Bug fix: `this.responseText` captured before nulling `currentXhr`

### Phase 2 — Help tab improvements

- [ ] **Persist conversation to database** between page loads.  Add a
  `{ai_sessions}` table (session metadata + `history` JSON column).  Load on
  page open, save on each turn.  See `doc/ai-engine.md` §4.4 for schema draft.
- [ ] **Token usage display** — show cumulative input/output tokens in the
  status bar.  The API already returns `usage` in each response; JS discards it.
- [ ] **Streaming responses (SSE)** — pipe the Anthropic streaming API through
  PHP to the browser for word-by-word output.  Requires: `ob_end_clean()`,
  `Content-Type: text/event-stream`, `set_time_limit(0)`, Apache
  `php_flag output_buffering Off` for the API endpoint path.
- [ ] **Richer Markdown rendering** — current renderer handles only fenced
  code, inline code, bold, and newlines.  Add ordered/unordered lists and
  horizontal rules at minimum.  Consider a small external library (e.g.
  `marked.js`) — must be hosted locally to satisfy CSP `script-src 'self'`.
- [ ] **Context injection** — detect the user's current Doctis project and
  inject project name + document count into the system prompt so Help answers
  can be project-specific.
- [ ] **Copy-to-clipboard button** on assistant bubbles.

### Phase 3 — Meeting Assistant tab

Implements ENG-TASK-002 (Meeting Assistant Tool) as the Meeting tab.

- [ ] **System prompt for Meeting mode** — encode the two-phase session flow
  (Agenda Mode / Minutes Mode) from ENG-TASK-002 §4.4 as a JS constant in
  `js/ai_assist.js` or a separate `js/ai_meeting.js`.  Pass with
  `mode: 'meeting'`.
- [ ] **Session persistence** — Meeting sessions span multiple visits (agenda
  created now, minutes completed later).  Requires the `{ai_sessions}` table
  from Phase 2, with `mode` and `doc_id` columns.
- [ ] **HCRQMS file write** — on session completion, write the generated
  Markdown meeting record to the HCRQMS repository at the configured path
  (`$g_hcrqms_repo_path`, to be added to `config_defaults_inc.php`).  Reuse
  the `shell_exec()` git pattern from `GitFileStorageBackend`.
- [ ] **Git commit** — commit the generated file with the logged-in user's
  name/email as author (from `user_get_field($t_user_id, 'realname')`
  and `email`).  Use `GIT_AUTHOR_NAME` / `GIT_AUTHOR_EMAIL` env vars,
  same pattern as `GitFileStorageBackend::store()`.
- [ ] **Doctis document registration** — after commit, call `dwg_add()` (or
  `DwgAddCommand`) to register the meeting record as a Doctis document.
  Store the created `dwg_id` back in the `{ai_sessions}` row.
- [ ] **Department → project mapping** — map the HCRQMS department codes
  (ENG, SYS, HR…) from `departments.yaml` to Doctis project IDs so the
  registered document lands in the correct project.
- [ ] **Add `$g_hcrqms_repo_path` config default** to
  `config_defaults_inc.php`.

### Phase 4 — SOP Interview tab

Implements GUID-SYS-007 Part B as the SOP tab.

- [ ] **System prompt for SOP mode** — encode the eight-phase interview flow
  from GUID-SYS-007 as a system prompt.  More complex than Meeting mode;
  requires department-specific procedure coverage loaded dynamically.
- [ ] **Department requirements lookup** — read
  `$g_hcrqms_repo_path/system/guidance/QMS Departmental Requirements.md`
  server-side (in PHP) and inject the relevant department section into the
  system prompt at session start.
- [ ] **OFI log update** — append to
  `$g_hcrqms_repo_path/system/guidance/interview-ofi-log.md` after each
  session.
- [ ] **GitHub Pull Request creation** — use the GitHub REST API (HTTPS POST)
  to raise a PR targeting the department reviewer.  Store a GitHub personal
  access token in config (`$g_github_token`).  `gh` CLI cannot be used from
  a web-server context.
- [ ] **Reviewer lookup** — read `reviewers.yaml` (from GUID-SYS-007) to map
  department slug → GitHub username for the PR `reviewers` field.

### Phase 5 — Other tab / General mode

- [ ] **Decide scope** — General mode could be a free-form Claude session with
  a minimal system prompt, or it could host future tools not yet specified.
  Defer until Phase 3 experience informs the design.

### Deferred / under consideration

- [ ] **Python FastAPI microservice** — if streaming UX becomes important or
  the SOP interview tool proves complex to manage in PHP, extract the Claude
  API calls to a small Python service running as a `systemd` unit on vaio.
  PHP proxies to `localhost:PORT`.  See `doc/ai-engine.md` §5.3.
- [ ] **`max_tokens` per-mode configuration** — add
  `$g_ai_max_tokens_help`, `$g_ai_max_tokens_meeting`, etc. to
  `config_defaults_inc.php` rather than hardcoding 2048.
- [ ] **Rate limiting per user** — prevent a single user from exhausting the
  API quota.  Track requests in the `{ai_sessions}` table and enforce a
  per-user-per-hour limit in `ai_assist_api.php`.
- [ ] **Audit log** — record each AI session (user, mode, token usage, timestamp)
  to a dedicated table for billing reconciliation and usage monitoring.

---

## Revision history

| Date | Change |
|------|--------|
| 2026-06-17 | Initial version — documents Phase 1 implementation; drafts Phases 2–5 |
