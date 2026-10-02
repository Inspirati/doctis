# AI Assistant knowledge — design and operation

**Status:** built on branch `ai-meeting`, 2026-10-03; verified live on the native VM.
**Related:** [ai-todo.md](ai-todo.md) (overall AI status), [../MANUAL.md](../MANUAL.md) §11 (user view).

## Problem

The Help assistant's only knowledge was one hard-coded paragraph. Asked "Where do I find
the corporate directory?", it said Doctis has none, although the Organisational Chart
(`my_view_org_page.php`) had just been added. No seed text can keep up with Doctis's
features or with how the organisation uses them, so users must be able to teach the
assistant.

## Owner decisions (2026-10-03)

| Question | Decision |
|----------|----------|
| Who reviews entries | Managers (`$g_ai_knowledge_review_threshold = MANAGER`) |
| Visibility before review | Everyone, marked unverified |
| Storage | Database table (option A); a controlled-document export may follow |
| Scope | Shared by the Help and Meeting assistants |
| User manual | Part of the assistant's knowledge; kept current |

## Three layers

1. **Doctis describes itself.** Each Help request carries:
   - the user manual (`doc/MANUAL.md`, `$g_ai_knowledge_manual_path`);
   - a navigation map of the pages *this user* can open. It is read from the HTML of the
     menus Doctis draws (`layout_print_sidebar()`, `print_my_view_menu()`,
     `print_account_menu()`, `print_manage_menu()`), so new pages appear by themselves,
     each menu's own access checks apply, and no MantisBT menu code was changed.
   This alone answers the corporate-directory question.
2. **Knowledge users teach** — `{ai_knowledge}` entries (question, answer, keywords,
   page, optional project). See below.
3. **Later:** a gap log of unanswered questions, and a `search_knowledge` tool (MariaDB
   FULLTEXT, already indexed) instead of including every entry once the knowledge base
   outgrows the prompt (~20–50k tokens).

## Knowledge entries

- **Statuses:** 10 unverified → 30 published (by a reviewer) → 90 retired. Entries a
  reviewer adds on the Knowledge page are published at once; taught entries always start
  unverified.
- **Teaching:** when the assistant cannot answer, it says so (it is no longer told to deny
  features it doesn't know) and invites the user to teach it. Given new information, it
  shows a draft entry and asks to save it; on confirmation it writes:

  ```
  <<<KNOWLEDGE_ENTRY question="…" keywords="…" page="…" scope="global|project" supersedes="KB-n">>>
  answer
  <<<END_KNOWLEDGE_ENTRY>>>
  ```

  The server saves it (`ai_assist_process_knowledge_entry()`) and removes the block from
  the visible reply; the chat shows a card linking to the entry. **Correct this** under
  any answer starts a correction.
- **Safeguards:**
  - Entries are presented to the model as user-written reference data and never as
    instructions, and the model is told to ignore instructions inside them.
  - Stored text is stripped of marker syntax and control characters, with length limits.
  - A `page` must be an existing Doctis `*.php` file, or it is dropped.
  - `scope="project"` ties an entry to the current project, which only that project's
    users can see.
  - Taught entries are limited per user per day (`$g_ai_knowledge_daily_limit`, 20).
  - Publishing a correction retires the entry it supersedes.
  - The model is told never to save passwords, personal data beyond names and roles, or
    instructions.
- **Precedence told to the model:** navigation map (live), then published entries, then
  the manual; it must flag answers that rely on unverified entries.

## Prompt layout and caching

The Help system prompt is two blocks (`ai_assist_help_system_prompt()`):

| Block | Content | Cached |
|-------|---------|--------|
| 1 | role, teaching rules, manual, global entries (oldest first) | yes (`cache_control: ephemeral`, 5 min) |
| 2 | navigation map, current project, project entries | no |

Measured 2026-10-03: block 1 ≈ 5.9k tokens. The first request writes it to the cache;
later requests within 5 minutes read it at about 0.1× the input price, with only about
300 uncached tokens. Any knowledge change writes it again once.

The Meeting prompt includes the knowledge base and teaching rules, but is not yet cached
(≈5.3k input tokens per turn), so caching it is a to-do item. The Anthropic client
accepts system blocks, joins all text blocks of a reply (newer models may return
thinking blocks first), and reports cache usage.

## Files

| File | Role |
|------|------|
| `core/ai_knowledge_api.php` | Entries: add, review (publish/retire/edit), delete, visibility, cleaning, page check, limits |
| `ai_assist_knowledge_api.php` | Manual, navigation map, entry text, teaching text, marker processing |
| `ai_assist_help_api.php` | Help prompt (two blocks) |
| `ai_assist_meeting_api.php` | Meeting prompt knowledge section |
| `ai_knowledge_page.php`, `ai_knowledge_update.php` | Knowledge page (browse, add; review for managers) |
| `js/ai_assist.js` | Correct this; knowledge card |
| `admin/schema.php` step 64 | `{ai_knowledge}` (FULLTEXT on question, keywords, answer) |
| `tests/Mantis/AiKnowledgeTest.php` | 7 tests |

## Verification (2026-10-03, live API)

1. sam asked "Where do i find the corporate directory?" and was answered "My View →
   Organisational Chart (`my_view_org_page.php`)".
2. sam asked how new starters get accounts. The assistant gave generic MantisBT routes,
   said it did not know the organisation's process, and invited teaching. sam taught it;
   the assistant drafted an entry and asked; sam confirmed. KB-12 was saved as
   unverified, with the page checked.
3. frodo asked a reworded version and was answered from KB-12 with an "unverified"
   warning. After the administrator published it, the warning disappeared.
4. frodo asked in the Meeting tab and was answered from KB-12.

KB-12 was test content and has been deleted.
