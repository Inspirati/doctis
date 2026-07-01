# Concept Plan — AI Engine Integration: Meeting Assistant as a Doctis Page

**Status:** Concept draft — for discussion  
**Date:** 2026-06-16  
**Context:** Considers implementing ENG-TASK-002 (Meeting Assistant Tool) as a native Doctis page rather than as a standalone `~/qms-meeting/` Claude Code session.  Companion to GUID-SYS-007 (QMS Programme Expansion Roadmap).

---

## 1. Summary

The Meeting Assistant Tool (ENG-TASK-002) is currently specified as a Claude Code CLI tool: a `CLAUDE.md`-driven session that a user runs locally to produce meeting records and commit them to the HCRQMS Git repository. This document assesses what would be required to re-implement that tool as a web page within Doctis, evaluates whether PHP is a suitable platform for the requirement, and proposes a phased implementation approach.

**Headline verdict:** PHP and the Doctis/MantisBT platform are a workable but imperfect fit. The most significant constraint is not PHP's language capabilities but the mismatch between PHP's stateless request-response model and the multi-turn conversational session model the tool requires. This is solvable at moderate engineering cost. The integration benefits — free authentication, document registration, access control, and audit trail — are substantial and justify the effort over a standalone tool for the meeting assistant and any subsequent AI tools in this programme.

---

## 2. Why Doctis Rather Than a Standalone Tool

The ENG-TASK-002 specification notes that the standalone `~/qms-meeting/` approach has a significant friction point: every user who wants to run the tool needs Claude Code installed and authenticated on their local machine. The QMS Programme Expansion Roadmap (GUID-SYS-007) acknowledges this as the central deployment challenge, proposing a kiosk laptop or Docker container as workarounds.

A Doctis-hosted implementation eliminates the deployment problem entirely. It also delivers several integrations at no additional cost:

| Benefit | How it works in Doctis |
|---------|----------------------|
| **Authentication** | Users log in with their existing Doctis account — no separate Claude or API credentials needed |
| **Access control** | Access level thresholds (e.g. REPORTER and above) control who can initiate sessions |
| **Document registration** | When a meeting record is completed, a Doctis document record can be created automatically, linking the HCRQMS file to the register |
| **Issue tracking** | Action items from meeting records can optionally be created as Doctis issues, providing a tracked workflow |
| **Audit trail** | Doctis history records when each meeting record was registered, reviewed, and approved |
| **Project scoping** | Meeting records are naturally associated with a Doctis project, mirroring the department/team structure |
| **Single URL** | Staff access the tool from the same browser bookmark as the document register — no additional infrastructure |

The broader programme (SOP interview tool, and any future AI-assisted QMS tools) would benefit from the same integration. Establishing this pattern in the Meeting Assistant creates a reusable AI integration layer within Doctis.

---

## 3. Functional Scope

A Doctis implementation of the Meeting Assistant would reproduce the two-mode workflow defined in ENG-TASK-002:

**Agenda Mode** — pre-meeting:
- Guided conversational session captures meeting metadata and agenda items
- Generates a pre-populated meeting record file in HCRQMS-compliant Markdown
- File is saved to the HCRQMS repository on the Doctis server

**Minutes Mode** — post-meeting:
- Loads an existing agenda file (by date, department, project)
- Guided session completes attendee record, discussion notes, decisions, and action items
- Generates the completed meeting record file
- Optionally commits the file to the HCRQMS repository and registers it as a Doctis document

**Scope not replicated in Phase 1:**
- GitHub Pull Request creation (requires a `gh` CLI or GitHub API integration — deferred)
- OFI log updates (SOP interview tool feature — not part of meeting assistant)
- Action item creation as Doctis issues (Phase 2 integration)

---

## 4. Architecture

### 4.1 Overview

```
Browser (chat UI, JS)
    │
    │  HTTP POST  (user message + session_id)
    ▼
meeting_page.php         — page shell, authentication, session init
meeting_api.php          — AJAX endpoint: receives message, calls Claude, streams reply
    │
    ├── {meeting_sessions} table  — session metadata and conversation history (JSON)
    │
    ├── Anthropic API (HTTPS)     — Claude claude-sonnet-4-6 or equivalent
    │
    ├── HCRQMS repository         — file writes (NFS or local path)
    │
    └── Doctis dwg_add            — optional: register completed record in Doctis
```

### 4.2 Page Entry Points

| File | Role |
|------|------|
| `meeting_page.php` | Main page — renders the chat UI shell, initialises or resumes a session, enforces access level |
| `core/meeting_api.php` | REST-style AJAX endpoint — receives `{session_id, message}`, appends to history, calls Claude API, streams or returns the reply, updates session state |
| `core/meeting_dwg_api.php` | Helper functions: session CRUD, system prompt construction, HCRQMS file write, git commit, Doctis document registration |

### 4.3 Frontend

A single-page chat interface within the standard Doctis/MantisBT layout. Key elements:

- Chat message thread (user messages right-aligned, assistant left-aligned)
- Text input with send button
- Mode indicator (Agenda / Minutes)
- Session state display (meeting title, department, current phase)
- Action buttons: New Session, Resume Session, Register in Doctis

The frontend sends each user message via `fetch()` to `meeting_api.php`. The reply can be delivered as either:

- **Non-streaming (simpler):** The PHP endpoint calls the Claude API, waits for the complete response, and returns it as JSON. Typical Claude response time is 2–8 seconds. Acceptable for a tools-oriented workflow; acceptable for a meeting assistant where the user is reading and thinking, not watching a cursor.
- **Streaming SSE (better UX):** The PHP endpoint opens a Claude streaming connection and pipes each chunk to the browser as a Server-Sent Event. Requires `Content-Type: text/event-stream`, `ob_flush()`, `flush()` in a loop, and Apache/PHP-FPM configured to not buffer output. More complex but the right approach for a polished experience.

**Recommendation:** Start with non-streaming for Phase 1. Add streaming in Phase 2 once the session management layer is stable.

### 4.4 Session State

Each turn in a meeting assistant session is a new HTTP request. Between requests, the full conversation history must be persisted server-side and re-sent to the Claude API on each call (Claude is stateless; it has no memory of previous turns unless the history is included in the messages array).

Proposed database table `{meeting_sessions}`:

```sql
CREATE TABLE IF NOT EXISTS {meeting_sessions} (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    project_id  INT UNSIGNED NOT NULL,
    created     DATETIME NOT NULL,
    updated     DATETIME NOT NULL,
    mode        ENUM('agenda','minutes') DEFAULT NULL,
    phase       VARCHAR(8) DEFAULT NULL,   -- e.g. 'A3', 'M6'
    doc_id      VARCHAR(64) DEFAULT NULL,  -- e.g. MIN-ENG-20260616
    file_path   VARCHAR(512) DEFAULT NULL, -- relative path in HCRQMS
    status      ENUM('active','completed','abandoned') DEFAULT 'active',
    history     LONGTEXT NOT NULL          -- JSON array: [{role, content}, ...]
);
```

The `history` column holds the complete Claude message array for the session. This grows with each turn. A full meeting assistant session (10–40 minutes of conversation) is unlikely to exceed Claude's context window (200k tokens for Sonnet), but history pruning or summarisation should be planned for if longer sessions are anticipated.

### 4.5 Claude API Integration

PHP does not have an official Anthropic SDK, but the API is straightforward JSON over HTTPS. A thin wrapper using PHP's cURL extension (already available on any MantisBT server) is sufficient:

```php
function meeting_claude_chat( array $p_messages, string $p_system ): string {
    $t_payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 4096,
        'system'     => $p_system,
        'messages'   => $p_messages,
    ]);
    $t_ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $t_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $t_payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . config_get( 'anthropic_api_key' ),
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $t_response = curl_exec( $t_ch );
    curl_close( $t_ch );
    $t_data = json_decode( $t_response, true );
    return $t_data['content'][0]['text'] ?? '';
}
```

The system prompt passed to each call encodes the full session driver (equivalent to the `CLAUDE.md` from the standalone tool), plus the current session state (mode, phase, meeting metadata collected so far). This means the session driver is PHP code, not a flat CLAUDE.md file — it is constructed dynamically with the current context embedded.

### 4.6 HCRQMS Repository Integration

The completed meeting record files must be written to the HCRQMS Git repository. Two sub-questions:

**Where is the HCRQMS repository relative to the Doctis server?**

| Scenario | Approach |
|----------|----------|
| HCRQMS repo is on the same server (or NFS-mounted) | `file_put_contents()` to the mounted path; `shell_exec('git commit ...')` — same pattern as Doctis git backend |
| HCRQMS repo is on GitHub only | Use the GitHub Contents API (HTTPS PUT) to write and commit the file without needing a local clone |
| HCRQMS repo is on a different server | SSH to that server and run git commands — fragile; avoid |

For a corporate intranet deployment, the simplest arrangement is a local bare clone of HCRQMS on the Doctis server (updated via a scheduled `git fetch`), with `www-data` having write access to a worktree. This mirrors exactly what Doctis already does for its document git backend.

**Git commit attribution:**

The commit should be attributed to the logged-in Doctis user (their name and email from `user_get_field($t_user_id, 'realname')` and `email`), not to the web server identity. This requires passing `GIT_AUTHOR_NAME` and `GIT_AUTHOR_EMAIL` as environment variables to the git command, exactly as `GitFileStorageBackend::store()` does for document commits.

### 4.7 New Configuration Keys

Add to `config_defaults_inc.php`:

```php
# AI Engine — Meeting Assistant
$g_anthropic_api_key     = '';          # Anthropic API key; blank = feature disabled
$g_hcrqms_repo_path      = '';          # Absolute path to local HCRQMS worktree
$g_meeting_assistant_threshold = REPORTER;  # Minimum access level to use the tool
```

---

## 5. PHP Platform Assessment

### 5.1 What PHP handles well

| Requirement | PHP capability |
|-------------|---------------|
| HTTPS API calls to Anthropic | cURL — mature, well-tested, already used in MantisBT |
| JSON handling | Native `json_encode/decode` — no issues |
| Database session persistence | MariaDB via MantisBT's `db_query()` — straightforward |
| File write to local path | `file_put_contents()` — trivial |
| Git shell commands | `shell_exec()` / `proc_open()` — already used in Doctis git backend |
| User authentication and access control | Inherited from MantisBT — free |
| HTML/JS chat UI | Standard HTML5 + `fetch()` — no framework required |

### 5.2 Where PHP creates friction

**Stateless request-response model.** PHP does not maintain state between HTTP requests. The entire conversation history must be serialised to the database, retrieved on each request, appended with the new exchange, and re-sent to the Claude API. This is architecturally inelegant but is a solved pattern for chat applications. The performance cost is one database read and one write per user turn, which is negligible.

**No native streaming without configuration.** PHP can stream SSE but requires `set_time_limit(0)`, disabled output buffering (`ob_end_clean()`), and PHP-FPM (not mod_php) configured without buffering. These are normal adjustments for a chat feature but require deliberate setup. Apache's default configuration buffers all PHP output, which defeats streaming entirely until the buffer settings are changed.

**Execution time limits.** A 40-minute meeting session cannot be a single PHP request. This is not a problem if sessions are message-by-message (each HTTP request is one user turn, taking 2–8 seconds of PHP execution time). It would be a problem only if someone tried to implement the entire session as a long-running PHP process, which is the wrong approach.

**No background processing.** PHP cannot easily do background work after sending the HTTP response. If future requirements include asynchronous tasks (e.g. generating and committing a document after the session ends while the user sees a "processing" indicator), PHP needs a job queue (Redis + a worker process, or a cron-triggered script). For Phase 1 the commit is synchronous and blocking, which is acceptable given its speed.

**No official Anthropic PHP SDK.** A cURL wrapper adds approximately 30 lines of code. This is not a material obstacle.

### 5.3 Verdict

PHP is adequate but not optimal for conversational AI interfaces. The correct comparison is:

| Approach | Build effort | Integration value | Long-term fit |
|----------|-------------|-------------------|---------------|
| Standalone Claude Code CLI (`~/qms-meeting/`) | Low (already specified) | None | Poor — each user needs local setup |
| Doctis PHP page (this plan) | Medium | High — auth, document register, audit trail | Good for small-scale tool |
| Dedicated microservice (Python FastAPI + React) | High | Medium — can link to Doctis via API | Best for full programme at scale |

For the Meeting Assistant (the simpler tool), the Doctis integration provides enough value to justify building it in PHP. For the SOP Interview Tool (ENG-TASK-003 / GUID-SYS-007) with its more complex session flow, open-ended knowledge capture, and GitHub PR creation, the same foundation extends naturally — but if the programme grows to include multiple AI tools serving many concurrent users, migrating the AI engine layer to a dedicated microservice becomes worth revisiting.

---

## 6. Implementation Phases

### Phase 1 — Meeting Assistant (MVP)

**Objective:** Reproduce ENG-TASK-002 functionality as a Doctis page.

| Item | Work required |
|------|--------------|
| Database schema | `meeting_sessions` table; migration script |
| Configuration | `$g_anthropic_api_key`, `$g_hcrqms_repo_path` in `config_defaults_inc.php` |
| Claude API wrapper | `core/meeting_dwg_api.php` — `meeting_claude_chat()`, session CRUD functions |
| System prompt | Encode the ENG-TASK-002 CLAUDE.md session driver as a PHP string constant or template function; parameterise with current session state |
| API endpoint | `meeting_api.php` — receive message, update history, call Claude, return reply |
| Page shell | `meeting_page.php` — auth check, session init or resume, chat UI HTML |
| Frontend JS | Fetch-based chat loop; session state display |
| File write | `meeting_dwg_write_file()` — write Markdown to HCRQMS worktree |
| Git commit | `meeting_dwg_commit()` — shell_exec with correct `GIT_AUTHOR_*` env vars |
| Sidebar link | Add Meeting Assistant icon to MantisBT sidebar (same pattern as QMS button) |
| Access threshold | `$g_meeting_assistant_threshold` check in `meeting_page.php` |

**Estimated scope:** 400–600 lines of PHP + 150 lines of JavaScript. No new dependencies beyond a working Anthropic API key and a locally accessible HCRQMS worktree on the Doctis server.

### Phase 2 — Doctis Document Registration

**Objective:** When a meeting record is completed and committed, automatically register it as a Doctis document record.

- Call `dwg_add()` (or `DwgAddCommand`) with the meeting record's metadata
- Set the document reference to the HCRQMS file path (or git SHA if using GIT backend)
- Set status to `received` (or a meeting-appropriate status)
- Link back: the chat session record stores the created `dwg_id` for future reference

This phase makes the meeting record visible in the Doctis document list, eligible for the review workflow, and traceable in the audit log.

### Phase 3 — Action Item Tracking

**Objective:** Create Doctis issues for action items captured during the minutes session.

- At M7 (Action Items phase), each captured action is offered as a candidate Doctis issue
- Issue is linked to the meeting record document
- Assignee is set from the Doctis user lookup (matched by name to the action owner)
- Due date is set from the action deadline

### Phase 4 — SOP Interview Tool

**Objective:** Add the more complex SOP knowledge-capture interview (GUID-SYS-007 Part B) as a second mode of the same AI engine infrastructure.

The Phase 1 session management layer (database table, Claude wrapper, streaming infrastructure) is directly reused. The SOP interview adds: department-specific system prompt construction, OFI log update, and GitHub PR creation via the GitHub REST API (replacing the `gh` CLI dependency that cannot run from a web server context).

---

## 7. Required Back-End Tooling

### Must-have for Phase 1

| Tool / component | Notes |
|-----------------|-------|
| **Anthropic API key** | Organisation-level key; stored in `config/config_inc.php` as `$g_anthropic_api_key`; never committed to git |
| **HCRQMS local clone** | Bare repo or working tree on the Doctis server; `www-data` write access to worktree; `git push` configured to remote (GitHub or internal server) |
| **PHP cURL extension** | Almost certainly already present on any MantisBT server; confirm with `php -m | grep curl` |
| **PHP `proc_open()` or `shell_exec()`** | For git operations; already used in Doctis git backend; confirm not disabled in `php.ini` `disable_functions` |
| **MariaDB `{meeting_sessions}` table** | One new table; added via Doctis schema migration |
| **Apache output buffering disabled for `/meeting_api.php`** | Only needed if implementing SSE streaming; add `php_flag output_buffering Off` in `.htaccess` or vhost config for that path |

### Nice-to-have for Phase 2+

| Tool / component | Notes |
|-----------------|-------|
| **GitHub REST API access** | For PR creation without `gh` CLI; requires a GitHub personal access token or GitHub App credential stored in config |
| **Redis or similar job queue** | Only needed if background processing is required; not needed for MVP |
| **PHP Composer (already installed)** | If a third-party HTTP client library (e.g. Guzzle) is preferred over raw cURL for the API wrapper |

---

## 8. Outstanding Decisions

Before committing to the Doctis-hosted approach, the following should be confirmed:

1. **HCRQMS repository location on the Doctis server.** Is the HCRQMS repo accessible from the Doctis server (same machine, NFS mount, or local clone that gets pushed to GitHub)? If the Doctis server has no access to the HCRQMS filesystem, the GitHub Contents API must be used instead of local git operations — feasible but more complex.

2. **API key management.** The Anthropic API key is a billing credential. It must be in `config/config_inc.php` (not committed) and should be access-restricted at the OS level. Confirm the key is shared organisationally (not tied to an individual account) and that the Doctis server can reach `api.anthropic.com` over HTTPS.

3. **Streaming vs non-streaming for MVP.** Non-streaming simplifies the Apache configuration requirement and is sufficient for a tools-oriented meeting assistant. Confirm whether the UX trade-off (waiting 3–6 seconds per reply vs watching text stream in) is acceptable for Phase 1.

4. **Scope of Doctis document registration.** Should every completed meeting record be auto-registered in Doctis (creating a document record), or should this be optional (user clicks "Register" at the end of the session)? Mandatory registration is cleaner for audit purposes; optional gives the minute-taker control.

5. **Department mapping to Doctis projects.** The Meeting Assistant uses a department code (ENG, SYS, HR…). Doctis uses projects. Is there a clean 1:1 mapping between the organisation's Doctis projects and the HCRQMS department codes? This determines whether project selection can be automated from the department answer, or must be a separate question.

---

## 9. Relationship to the Broader QMS AI Programme

This plan establishes an **AI engine layer within Doctis**: a pattern for multi-turn Claude sessions that produce structured documents, commit them to Git, and register them in the document control system. Once built for the Meeting Assistant, this layer is the foundation for:

- The SOP Interview Tool (GUID-SYS-007) — a more complex session driver on the same infrastructure
- Any future AI-assisted QMS tool: document review assistants, audit question generators, risk assessment aides

The investment in Phase 1 is therefore not just a meeting assistant — it is the architecture for the organisation's AI-augmented QMS capability, hosted on and integrated with the document control platform that is already deployed and maintained.

---

## Revision History

| Revision | Date | Description | Author |
|----------|------|-------------|--------|
| — | 2026-06-16 | Initial concept draft | QMS Lead / Claude |
