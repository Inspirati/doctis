# Retained AI conversations and request diagnostics

Implemented 2026-10-31. Applies to the interactive Help and Meeting tabs.
The unattended meeting scheduler still uses the shared model client but does
not create interactive conversation/request records.

## User behavior

- **New conversation** replaces the old Clear button. It archives the selected
  conversation and starts a new one after the server confirms the archive.
- Switching meetings preserves their independent conversations. A general
  Assistant visit restores the latest active conversation for that mode.
- **AI Conversation History** lists retained conversations, including archives,
  with pagination. Each has a readable transcript and JSON export containing
  its request evidence. There is no automatic purge or archive-delete action.
- Owners may read/export their conversations. Administrators may investigate
  other users after reauthentication. Ordinary users cannot read, export or
  continue another user's conversation, even by supplying its ID.
- A failed request leaves the successful conversation history unchanged. The
  failure and attempted message remain in diagnostics. The browser restores
  the input for retry rather than including a fictitious response in history.
- Stop cancels browser waiting, not server execution. The UI says so. The
  server refuses overlapping requests and archiving while a request owns the
  conversation lease. A three-minute lease allows recovery from a killed
  worker; old pending/processing requests become interrupted on the next attempt.

## Evidence

`{ai_conversation}` stores owner, mode, context, created/updated/archive times,
displayed transcript, document reference, and concurrency state.

`{ai_request}` records the user message before prompt construction or network
access. It then retains:

- Exact submitted history and system prompt, plus the candidate roster supplied
  to that Meeting prompt. A single roster snapshot feeds both prompt and audit.
- Requested model, maximum tokens, PHP/application version, and SHA-256 hashes
  of the request endpoint, Meeting prompt, provider client and session API.
- Provider request/message IDs, returned model, token usage, stop reason,
  HTTP status, duration, raw provider body and raw text reply.
- Displayed reply after marker extraction, document/knowledge processing results,
  error and status (`pending`, `processing`, `success`, `partial`, `failed`,
  `rejected`, or `interrupted`). Start/finish timestamps are per attempt.

Raw replies are recorded **before** document/knowledge processing. Generated
document blocks remain available even though they are removed from the chat
display. An ordinary PHP shutdown tries to finalize an interrupted request;
hard termination or a database outage may leave a pending record. The audit
does not claim that a partial operation was rolled back.

Configured API credentials and authentication request headers are not recorded.
Prompts, staff details and user-supplied text are retained as conversation data,
so access is limited to the owner and administrators. HTML views escape stored
text; JSON downloads are attachments with no-cache/no-store headers. JSON is
stored with Unicode escapes to preserve emoji over existing utf8mb3 database
connections without changing the global connection character set.

## Installation and existing data

`admin/schema.php` appends three steps:

| Step | Effect |
|---|---|
| 78 | Create `{ai_conversation}` |
| 79 | Create `{ai_request}` |
| 80 | Copy legacy transcripts, preserving created/updated times and document links |

Run the standard `php admin/upgrade_unattended.php` on the intended instance.
This is an additive upgrade to schema **80**. No database/Git rebuild or
existing-table ALTER is required. `{ai_sessions}` remains intact; new code no
longer overwrites it. The copy is idempotent, and a lazy importer also covers
legacy rows for a user/mode not already imported.

Deploy code and schema as one coordinated update, allowing old chat requests
to finish before cutover. Old workers can still update the legacy table;
already imported conversations do not continually resynchronize with it.
Existing transcripts have no historical prompt/provider evidence: the History
page explains this rather than inventing it. Backups should include all three
AI tables until legacy data is deliberately retired.

The live server is unchanged by local development. Its deployment needs the
owner's approval and the usual live backup/upgrade procedure.

## Verification on the native VM

- Additive schema upgrade: three steps executed successfully, without a rebuild.
- `AiSessionTest` (13 tests): retention through Clear, independent meeting/mode contexts,
  legacy import, failure/retry, Unicode raw document retention, overlapping and
  stale requests, archived IDs and user isolation. No model calls or emails.
- Temporary HTTP installation with a mock model: successful prompt/roster/model
  recording, escaped HTML, archive/export, simulated provider outage and invalid
  document processing, cross-user denial and administrator export. Fixture users
  and audit records removed afterwards; the temporary HTTP server stopped.
- Real external provider requests were not made by these checks. A production
  error/performance/retention policy and live provider behavior remain separate
  deployment checks.
- `node tests/javascript/ai_assist_session_test.js`: the actual chat script's
  load, failed retry, archive failure/success and new conversation ID behavior,
  using a minimal DOM/XHR harness with no browser dependencies.
