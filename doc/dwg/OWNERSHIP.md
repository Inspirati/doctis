# Document Creator, Owner and Assignee

Status: **built and tested on the native VM, 2026-10-20**, on branch
`dwg-ownership`. Not merged into `dev`; not on the live version. As-built
notes are in §11 and the test record in §12.

Owner decision of 2026-10-20: a document has **three distinct person fields**,
not two. All decisions in §10 are settled; §10 records the two that were
amended from the original recommendation.

Doctis currently has two: `{dwg}.creator_id` and `{dwg}.handler_id`. This note
defines the third (owner), fixes what is wrong with the two that exist, and
sets the naming so the three read consistently in the UI.

---

## 1. The three roles

| Role | Meaning | Changes | Who may set it |
|------|---------|---------|----------------|
| **Creator** | The user who registered the document in Doctis. | Auto-populated at create; effectively never changes for the document's lifetime. | Nobody, in normal use (see §3.3). |
| **Owner** | The person *responsible* for the document. | Rarely — a staff member is replaced, or moves to a new position. | Self-claim, or a manager/administrator, individually or in bulk. |
| **Assignee** | The person holding the document for the **current task step** — review, approval, rework. | Frequently; this is the document processing workflow. | Self-claim, or a manager/administrator, individually or in bulk. |

Owner answers "whose document is this?". Assignee answers "whose desk is it on
right now?". Creator is a provenance record.

---

## 2. Naming

### 2.1 Recommendation: rename the labels, not the columns

The three roles should read consistently **in the UI, the history, the exports
and the API** — not necessarily in the database or in PHP variable names.

CLAUDE.md, *Primary Goal: Minimise Diff with MantisBT*, is explicit that
`dwg_*` modules keep MantisBT's internal names on purpose so that `diff`
against the upstream original stays useful. `handler_id` is MantisBT's name
for the assignee and it appears in **19 files** on the document side alone
(filter plumbing, columns, summary, history, email, SOAP, My View). Renaming
the column and the variables would touch every forked file, buy nothing a
label cannot buy, and raise the cost of every future upstream merge.

So:

| Layer | Creator | Owner | Assignee |
|-------|---------|-------|----------|
| DB column | `creator_id` (keep) | `owner_id` (**new**) | `handler_id` (keep) |
| PHP property | `$creator_id` | `$owner_id` | `$handler_id` (keep) |
| UI label | "Created by" | "Owner" | "Assigned To" |
| Lang string | `$s_dwg_creator` | `$s_dwg_owner` (**new**) | `$s_assigned_to` (keep) |
| REST/SOAP | `creator` | `owner` (**new**) | `handler` (keep) |

A short comment block at the top of [core/dwg_api.php](../../core/dwg_api.php)
should state the mapping, so the next reader of `handler_id` knows it is the
assignee and not the owner.

### 2.2 The label collisions to clear first

Three different things are currently presented as "owner":

- `$s_dwg_creator = 'Owned by'` — [lang/strings_english.txt:816](../../lang/strings_english.txt#L816).
  The **creator** is labelled "Owned by" on the document view page. This is the
  most misleading string in the feature and must become **"Created by"**.
- `$s_dwg_publish_owner = 'Owner'` — the published document-control block's
  Owner row, fed from `handler_id`
  ([core/dwg_publish_api.php:1159](../../core/dwg_publish_api.php#L1159)).
  Must be re-pointed at `owner_id` (§8).
- `email_dwg_owner_changed()` — [core/email_dwg_api.php:854](../../core/email_dwg_api.php#L854)
  and the `'owner'` email action. Despite the name this fires on a **handler**
  change. Keep the function (it matches MantisBT's `email_owner_changed`) but
  do **not** reuse it for the new owner field; add
  `email_dwg_ownership_changed()` with its own action key (§7).

`{topic_expectation}.owner_id` ([core/topic_expectation_api.php](../../core/topic_expectation_api.php))
is a fourth, unrelated owner — the owner of a Topic *expectation*. It is in a
different table and needs no change, but the term is now used twice and the
Topics docs should say so.

---

## 3. Current state

### 3.1 The assignee already works end to end

Verified on the VM, 2026-10-20, by curl against the running clone at
`5596d5098`:

- The "Assign To:" button renders on the document view page with `[Myself]`
  first and pre-selected — self-claim already exists
  ([dwg_view_inc.php:1962](../../dwg_view_inc.php#L1962)).
- `POST dwg_update.php` with `action_type=assign` set `handler_id` correctly
  and logged it in `{dwg_history}`.
- By access level: `developer` (55) and above see the control;
  `viewer`, `reporter` and `updater` see only a read-only row. The gate is
  `update_dwg_assign_threshold`
  ([DwgViewPageCommand.php:307](../../core/commands/DwgViewPageCommand.php#L307)).
- Bulk assign, filter-by-assignee, the "Assigned to Me" / "Unassigned" My View
  boxes, the summary "by developer" report and `handler` on REST/SOAP create
  all exist.

Nothing has ever used it: every document on the VM has `handler_id = 0`,
because `admin/import-git-repo.php` maps frontmatter `owner:` to a handler only
on an exact account-name match ([lines 487-492](../../admin/import-git-repo.php#L487-L492))
and the HCRQMS frontmatter names roles ("QMS Lead"), not accounts.

### 3.2 Two bugs to fix as part of this work

**(a) `create()` silently drops the handler, but logs it anyway.**
[`DwgData::create()`](../../core/dwg_api.php#L443) resolves a category default
handler and computes an auto-assign status, then its `INSERT INTO {dwg}`
([line 525](../../core/dwg_api.php#L525)) omits **both** `handler_id` and the
computed `$t_status`. It then writes
`history_dwg_log_event_direct( $this->id, 'handler_id', 0, $this->handler_id )`.

Verified: a document created by POST with `handler_id=7` came back with an
empty Assigned To and a history row reading **"Assigned To => manager"**. The
audit trail asserts an assignment that was never stored. REST and SOAP create
with a `handler` are affected the same way
([DwgAddCommand.php:283](../../core/commands/DwgAddCommand.php#L283)).

This must be fixed before any create-time owner or assignee is offered, and
the same `INSERT` has to carry `owner_id`.

**(b) `$g_dwg_assigned_status` is a bug status, not a document status.**
`ASSIGNED = 50` ([core/constant_inc.php:87](../../core/constant_inc.php#L87))
is MantisBT's value; the document enum is 110-195 with `150:assigned to`, and
no constant exists for 150. Consequences:

- `dwg_get_status_for_assign()`
  ([core/dwg_api.php:2432](../../core/dwg_api.php#L2432)) can never fire
  (`110 < 50` is false), so auto-set-on-assign silently does nothing.
  Confirmed: an assigned document stayed at 110.
- Two paths **write** the value rather than compare it:
  [dwg_update.php:437](../../dwg_update.php#L437) and
  [DwgNoteAddCommand.php:271](../../core/commands/DwgNoteAddCommand.php#L271)
  set the status to `dwg_assigned_status` → **50, which is not in
  `$g_dwg_status_enum_string`**. Trigger: `$g_reassign_on_feedback` is ON, the
  document sits at `$g_dwg_feedback_status` (REWORK = 165, "draft"), it has an
  assignee, and its creator adds a note. It has not fired only because no
  document has ever had an assignee — which this feature changes.
- [dwg_change_status_page.php:105](../../dwg_change_status_page.php#L105)
  compares against 50, so the sponsorship and handler-access checks in that
  block are dead code.

Both switches are the shared *bug* config keys (`reassign_on_feedback`,
`auto_set_status_to_assigned`); there is no `dwg_` variant, so turning either
off would also change issue behaviour. See §9.1 for the recommended fix.

### 3.3 Creator is editable, but the control is hidden by config

The Update page gives Creator an **[edit]** link and a user picker
([dwg_update_page.php:303-325](../../dwg_update_page.php#L303-L325)),
validated only against `create_dwg_threshold`
([dwg_update.php:118-138](../../dwg_update.php#L118-L138)). This is a Doctis
addition; MantisBT does not let you reassign a reporter.

It does not render by default, which is why it looks absent. The page tests
`in_array( 'creator', $t_fields )` at
[dwg_update_page.php:104](../../dwg_update_page.php#L104), but
`$g_dwg_update_page_fields` contains MantisBT's `'reporter'` and **not**
`'creator'` — a leftover of the fork. Adding `'creator'` to the array makes
the control appear, verified on the VM 2026-10-20. The create page has the
same kind of mismatch in the other direction: it tests `'creator_id'`
([dwg_create_page.php:251](../../dwg_create_page.php#L251)) while
`$g_dwg_report_page_fields` supplies `'creator'`.

**Owner decision 2026-10-20:** the creator becomes **read-only** — it is a
provenance record, and as with a MantisBT reporter the person who registered a
document is always that person, whether or not they are still a current user.
The existing [edit]-link-and-picker control on the Update page is **repurposed
as the Owner field**, which is the one that legitimately changes. So this
section describes the UI that Owner inherits, not a control to keep for
Creator.

The VM's `config/config_inc.php` now enables every field each document page
tests, so that "not built" can be told apart from "not configured" (owner
request, 2026-10-20). That block is VM-only and is not a recommendation for
live.

---

## 4. Schema

`owner_id` is a change to an **existing** table, so per CLAUDE.md
(*Database Schema Changes*) edit the `CREATE TABLE` for `{dwg}` in place —
**Step 18**, [admin/schema.php:338](../../admin/schema.php#L338) — and rebuild
the database. Do not append a step and do not write an `ALTER TABLE`.

```
  `creator_id` int(10) unsigned NOT NULL DEFAULT 0,
  `owner_id`   int(10) unsigned NOT NULL DEFAULT 0,   -- new
  `handler_id` int(10) unsigned NOT NULL DEFAULT 0,
  ...
  KEY `idx_dwg_creator` (`creator_id`),
  KEY `idx_dwg_owner`   (`owner_id`),                 -- new
  KEY `idx_dwg_handler` (`handler_id`),
```

`0` means unowned, matching `handler_id`'s `NO_USER` convention. The schema
trailer comment stays at `database_version = 77`; no step is added.

Rebuild cost is acceptable: no production release yet, and the owner confirmed
on 2026-10-20 that the VM is a scratchpad and rebuilds are frequent. The live
version would need a rebuild too, with the reload sequence in
[admin/tools/data-scripts/README.md](../../admin/tools/data-scripts/README.md).

---

## 5. Access model

Five thresholds, keeping "claim for myself" separate from "assign anybody".
The existing pair conflates them, which is why no one below DEVELOPER can
currently claim anything.

| New config key | Default | Controls |
|----------------|---------|----------|
| `$g_own_dwg_threshold` | `DEVELOPER` | Who may *appear* in the Owner picker — who is eligible to be an owner. |
| `$g_dwg_claim_owner_threshold` | `DEVELOPER` | Who may make **themselves** the owner of an *unowned* document. |
| `$g_dwg_assign_owner_threshold` | `MANAGER` | Who may set the owner to **somebody else**, who may **take over** an already-owned document, and who may use bulk Set Owner. |
| `$g_dwg_claim_threshold` | `UPDATER` | Who may make **themselves** the assignee. |
| `$g_handle_dwg_threshold` | `VIEWER` → **`UPDATER`** | Who may appear in the Assignee picker. Existing key; the default should rise (§9.4). |

`$g_update_dwg_assign_threshold` (DEVELOPER) keeps its current meaning:
assign the document to somebody else.

**Why DEVELOPER may own (owner decision 2026-10-20).** Department and project
managers are the right responsible person for most high-level QMS content, but
on engineering projects it makes sense for a developer to own a document. The
eligibility and claim thresholds are therefore `DEVELOPER`, and *who should
own what* is left to **administrative control — user training** rather than
enforced by Doctis.

**Takeover rule (decision D1, as amended).** A self-claim of ownership
succeeds when either:

- the document is **unowned** (`owner_id = 0`) and the user meets
  `$g_dwg_claim_owner_threshold`; **or**
- the user meets `$g_dwg_assign_owner_threshold` — a **takeover**, permitted
  for managers and administrators even when the document is already owned.

The owner's rationale for allowing takeover: the action is logged, and a
privileged Doctis user is supposed to be able to carry out whatever step a
task requires without the existing owner having to perform a transaction for
them. The current owner may always hand ownership on, and may always release
it.

Self-claim of the **assignee** has no such restriction — picking up the next
task step from a colleague is the normal workflow.

---

## 6. Where the owner has to appear

`owner_id` becomes available as a list column for free: `columns_dwg_get_standard()`
reflects over `DwgData`'s default properties
([core/columns_dwg_api.php:153-155](../../core/columns_dwg_api.php#L153-L155)),
so adding `protected $owner_id = 0;` registers it. Everything else is explicit.

| Area | File(s) | Work |
|------|---------|------|
| Data object | [core/dwg_api.php](../../core/dwg_api.php) | `$owner_id` property + `@property` docblock; `__set`/`__get` case (line 247); `create()` INSERT (**and fix the dropped `handler_id`**, §3.2a); `update()` UPDATE + history (lines 642, 682); `dwg_copy()` (line 1300) |
| View page | [dwg_view_inc.php:632-668](../../dwg_view_inc.php#L632-L668), [DwgViewPageCommand.php:287](../../core/commands/DwgViewPageCommand.php#L287) | `owner_show` flag, Owner row, `can_claim_owner` / `can_set_owner` flags |
| Claim buttons | **new** `dwg_claim.php` | See §6.1 |
| Create / edit | [dwg_create_page.php:294](../../dwg_create_page.php#L294), [dwg_edit_page.php:288](../../dwg_edit_page.php#L288) | Owner picker; **delete the hardcoded `$t_show_handler = false;` lines** so an assignee can be set too; add `owner` to `$g_dwg_report_page_fields` |
| Update page | [dwg_update_page.php:298-345](../../dwg_update_page.php#L298-L345), [dwg_update.php](../../dwg_update.php) | Owner field + validation; add `owner` to `$g_dwg_update_page_fields` |
| Status change | [dwg_change_status_page.php:212](../../dwg_change_status_page.php#L212) | Leave as assignee-only — a task step, not an ownership change |
| List columns | [core/columns_dwg_api.php](../../core/columns_dwg_api.php) | `print_dwg_column_owner_id()` + `print_dwg_column_title_owner_id()`, mirroring the `handler_id` pair at lines 1535-1550; add `owner_id` to `$g_view_dwg_page_columns` and `$g_print_dwg_page_columns` (and `handler_id`, which is also missing) |
| Filter | `FILTER_PROPERTY_OWNER_ID` in [core/filter_constants_inc.php](../../core/filter_constants_inc.php); [core/filter_dwg_api.php](../../core/filter_dwg_api.php), [core/filter_dwg_form_api.php](../../core/filter_dwg_form_api.php), [DwgFilterQuery::build_prop_owner()](../../core/classes/DwgFilterQuery.class.php#L850) | Mirror the handler filter. ~8 dwg-side files |
| My View | `$g_my_view_dwg_boxes`, [my_view_dwg_inc.php](../../my_view_dwg_inc.php), [my_view_cnf_inc.php](../../my_view_cnf_inc.php) | New **"Documents I Own"** box; keep "Assigned to Me" |
| Bulk | [core/dwg_group_action_api.php:235](../../core/dwg_group_action_api.php#L235), [dwg_actiongroup_page.php:193](../../dwg_actiongroup_page.php#L193), [dwg_actiongroup.php:168](../../dwg_actiongroup.php#L168) | New `SET_OWNER` command beside `ASSIGN` |
| History | [core/history_dwg_api.php:579](../../core/history_dwg_api.php#L579), [:818](../../core/history_dwg_api.php#L818), [:403](../../core/history_dwg_api.php#L403) | `owner_id` label case; add to the `handler_id`/`creator_id` user-name formatting case; visibility guard against `view_handler_threshold`'s owner equivalent |
| Exports | `$g_csv_dwg_columns`, `$g_excel_dwg_columns` | Add `owner_id` |
| Summary | [core/summary_dwg_api.php](../../core/summary_dwg_api.php) | Optional "documents by owner" beside `summary_dwg_print_by_developer()` |
| REST / SOAP | [api/soap/mc_dwg_api.php:1658](../../api/soap/mc_dwg_api.php#L1658) `mci_dwg_data_as_array()`, [api/soap/mantisconnect.wsdl](../../api/soap/mantisconnect.wsdl) `DwgData`, [DwgAddCommand.php](../../core/commands/DwgAddCommand.php) | `owner` as an `AccountData` element beside `handler`; WSDL cache must be cleared after the change (CLAUDE.md, *Procedure not present*) |
| Publishing | [core/dwg_publish_api.php:1159](../../core/dwg_publish_api.php#L1159) | `doctis-owner` from `owner_id`, not `handler_id` (§8) |
| Manual | [doc/MANUAL.md:247](../MANUAL.md#L247) | The control-block description says owner is "the assigned user" — update |

### 6.1 The claim action

A parallel `dwg_claim.php`, in the spirit of the project's other `dwg_*` POST
handlers, rather than new branches inside `dwg_update.php`:

```
POST dwg_claim.php
  dwg_id, role = owner | assignee, action = claim | release
```

It only ever writes the current user's id, or `0` to release. That keeps the
privileged "assign somebody else" path in `dwg_update.php` untouched, keeps the
new thresholds in one readable place, and means a lower-privileged claim can
never be escalated into assigning a third party. It must still validate the
claimant against `$g_own_dwg_threshold` / `$g_handle_dwg_threshold` the way
[dwg_update.php:279](../../dwg_update.php#L279) does, honour the takeover rule
(§5), and log to `{dwg_history}`.

Two buttons on the view page — **Claim Ownership** / **Release Ownership** and
**Claim** / **Release** for the assignee — shown per threshold.

---

## 7. Audit trail

`{dwg_history}` already carries field-level before/after rows with user and
timestamp, so ownership changes need no new table: a row with
`field_name = 'owner_id'` is the audit trail. Required work:

- `owner_id` case in the label switch
  ([history_dwg_api.php:579](../../core/history_dwg_api.php#L579)) → `lang_get( 'dwg_owner' )`.
- Add `owner_id` to the `handler_id`/`creator_id` case at
  [line 818](../../core/history_dwg_api.php#L818) so ids render as user names
  and `0` renders as blank.
- A visibility guard beside the `handler_id` one at
  [line 403](../../core/history_dwg_api.php#L403), if owner visibility is
  thresholded at all (decision D3).

Because a claim is a normal field change, "who claimed this and when" is
answered by the existing Document History panel with no extra UI.

One caveat worth stating in the UI text: history rows are retained, but
`user_delete()` ([core/user_api.php:881](../../core/user_api.php#L881)) clears
only `reports_to` — a deleted account leaves its name resolved as a
placeholder in old history rows. See §9.3.

---

## 8. Publishing

The published document-control block already prints an **Owner** row, fed from
`handler_id`. Under the three-role model it must be fed from `owner_id` — the
person responsible for the document is what a reader of a controlled document
needs, not whoever happens to hold the current review step.

This matters more than it looks.
[doc/publish/METADATA_CUTOVER.md](../publish/METADATA_CUTOVER.md) records the
owner decision that lifecycle metadata lives only in Doctis, and the cut-over
script strips `owner:` from document frontmatter on that promise. But
[core/publish/control-block.lua:36-44](../../core/publish/control-block.lua#L36-L44)
drops rows whose value is empty, so with nothing populating the field the
Owner line **silently disappears from every published page**. The promise is
only honoured once owners are populated. Nothing is published on live right
now and the cut-over has not run there, so this has not yet bitten.

Also: there is no republish-on-change. PUBLISHING.md §7 lists
republish-on-status-change as open work; an ownership change has the same
problem, and a stale Owner on a published page is worse than a stale status.

---

## 9. Workflow implications

### 9.1 Status coupling

Fixing `$g_dwg_assigned_status` to 150 would *switch on* auto-promotion to
"assigned to" whenever an unassigned document gains an assignee. For a QMS that
is probably wrong: status should be a lifecycle state (draft, under review,
approved), not a queue state. A document at "under review" that gets passed to
a second reviewer must not fall back to "assigned to".

Recommended: fix the two **write** paths and the dead comparison so no
out-of-enum status can ever be stored, and leave the auto-transition off —
either by introducing `$g_dwg_auto_set_status_to_assigned` (default OFF) rather
than inheriting the shared bug key, or by removing the auto-assign call from
the document path entirely. Decision D2.

Ownership changes should **never** touch the status.

### 9.2 Archived documents cannot change hands

ASSIGN sets `$t_check_readonly = true`
([dwg_update.php:200](../../dwg_update.php#L200)) and `dwg_is_readonly()` fires
at `ARCHIVED`. So an archived document can neither be reassigned nor, as
designed, re-owned — and it keeps whatever owner it had. Ownership must
therefore transfer *before* archiving, or the readonly check must exempt the
owner field. Recommended: exempt ownership changes from the readonly check, so
a departing owner can be replaced on archived documents too. An archived
document attributed to someone who left years ago is a real QMS finding.

### 9.3 Leavers — the case that motivates the field

The owner's stated reason for a separate owner field is staff replacement, so
transfer has to be a first-class operation, not an afterthought:

- `user_delete()` clears only `reports_to`; nothing resets `{dwg}.owner_id` or
  `handler_id`. Deleting an account orphans its documents silently.
- Recommended: a **Transfer Ownership** action on Manage → Users (move all
  documents owned by A to B, in bulk, with history rows), plus a warning when
  disabling or deleting an account that still owns documents. Bulk Set Owner
  (§6) covers the manual path in the meantime.

### 9.4 Who may own what

`$g_handle_dwg_threshold` currently defaults to `VIEWER`, so a user who cannot
revise a document can be made its assignee — and would be eligible as its
owner on the same default. Both the owner and assignee pickers should be
limited to users who can actually act on the document; hence `UPDATER` and
`DEVELOPER` in §5.

### 9.5 Email volume

`handler` is ON in `$g_default_notify_flags`, so an assignee receives every
update on their documents. The owner should get its own notify flag (`owner`,
ON) and its own `email_dwg_ownership_changed()` — distinct from the
misleadingly named `email_dwg_owner_changed()`, which is the assignee one
(§2.2).

Back-filling owners onto the 100+ imported documents would otherwise send one
email per document through live Gmail SMTP. Per CLAUDE.md, set
`$g_enable_email_notification = OFF` for the back-fill and remove it
afterwards.

### 9.6 Importer

`admin/import-git-repo.php` should map frontmatter `owner:` to `owner_id`
rather than `handler_id`, resolve by realname and email as well as username,
and report unmatched owners as one collected list to fix rather than a warning
per file. Imported documents should land **unassigned** — an import is not a
task step.

### 9.7 Licences

Licences ([core/license_api.php](../../core/license_api.php)) gate document
*access* and have no bearing on who may own or be assigned. Worth an explicit
decision: should a document that requires a licence refuse an owner who does
not hold it? Recommended yes for owner, no for assignee — but it is a policy
call, not a technical one. Decision D5.

---

## 10. Decisions — all settled 2026-10-20

| # | Decision | Outcome |
|---|----------|---------|
| D1 | May a user self-claim ownership of an already-owned document? | **Amended.** Unowned → `$g_dwg_claim_owner_threshold`. Already owned → permitted as a **takeover** at `$g_dwg_assign_owner_threshold` (MANAGER). The action is logged, and a privileged user must not need the existing owner to act for them. See §5. |
| D2 | Should assigning a document auto-move its status to "assigned to"? | **No.** Give `$g_dwg_assigned_status` a valid document status so nothing out-of-enum can be written, then suppress both transitions for documents with `$g_dwg_auto_set_status_to_assigned = OFF` and `$g_dwg_reassign_on_feedback = OFF`, rather than inheriting the shared bug keys. Ownership changes never touch the status. |
| D3 | Is the owner visible to everyone, or thresholded like the assignee? | **Visible** to anyone who can view the document. |
| D4 | Should Creator stay editable on the Update page? | **Amended — creator becomes read-only.** As with a MantisBT reporter, the registrant is always that person. Its existing [edit] picker is repurposed as the Owner control. See §3.3. |
| D5 | Must an owner hold a licence the document requires? | **Yes for owner, no for assignee.** |
| D6 | Should a newly created document default to owner = creator? | **Yes**, with the field editable on the create form. |
| D7 | Does an owner get edit rights regardless of threshold? | **No**, not initially. Access level stays the only gate. |

Also decided: **email notification is permanently OFF on the VM** until email
is deliberately tested, and every optional document-page field is enabled
there (§3.3).

---

## 11. As built

Everything in §§2-8 is implemented, plus the importer (§9.6) and the
publishing re-point (§8). One item from §9 is **not** built and is the main
outstanding gap:

- **Transfer Ownership on Manage → Users (§9.3).** Bulk Set Owner covers the
  manual path, but there is still no single action to move every document from
  a departing owner to their replacement, and no warning when an account that
  still owns documents is disabled or deleted. `user_delete()` continues to
  leave `{dwg}.owner_id` pointing at the removed account.

Also not done, as decided: the Topics docs are not yet updated to note that
"owner" now means two things (§2.2), and there is no republish-on-ownership-
change (§8) — a published page keeps its old Owner line until someone
republishes.

### Files

| Area | Files |
|------|-------|
| Schema | [admin/schema.php](../../admin/schema.php) step 18: `owner_id` + `idx_dwg_owner` |
| Data object | [core/dwg_api.php](../../core/dwg_api.php): `$owner_id`, `create()`, `update()`, `dwg_set_field()`, `dwg_copy()`, `dwg_cache_columns_data()`, `dwg_get_status_for_assign()` |
| Access | [core/access_dwg_api.php](../../core/access_dwg_api.php): `access_dwg_user_holds_licenses()`, `_can_be_owner()`, `_can_set_owner()`, `_can_claim_owner()`, `_can_release_owner()`, `_can_claim_handler()` |
| Claim / release | [dwg_claim.php](../../dwg_claim.php) (new) |
| Pages | [dwg_view_inc.php](../../dwg_view_inc.php), [DwgViewPageCommand.php](../../core/commands/DwgViewPageCommand.php), [dwg_update_page.php](../../dwg_update_page.php), [dwg_update.php](../../dwg_update.php), [dwg_create_page.php](../../dwg_create_page.php), [dwg_edit_page.php](../../dwg_edit_page.php), [dwg_create.php](../../dwg_create.php) |
| Bulk | [core/dwg_group_action_api.php](../../core/dwg_group_action_api.php), [dwg_actiongroup_page.php](../../dwg_actiongroup_page.php), [dwg_actiongroup.php](../../dwg_actiongroup.php) |
| List / filter | [core/columns_dwg_api.php](../../core/columns_dwg_api.php), [core/filter_constants_inc.php](../../core/filter_constants_inc.php), [core/filter_dwg_api.php](../../core/filter_dwg_api.php), [core/filter_dwg_form_api.php](../../core/filter_dwg_form_api.php), [core/classes/DwgFilterQuery.class.php](../../core/classes/DwgFilterQuery.class.php) |
| My View | [my_view_dwg_inc.php](../../my_view_dwg_inc.php), [my_view_cnf_inc.php](../../my_view_cnf_inc.php), [my_view_dwg_page.php](../../my_view_dwg_page.php) |
| History / email | [core/history_dwg_api.php](../../core/history_dwg_api.php), [core/email_dwg_api.php](../../core/email_dwg_api.php) |
| API | [core/commands/DwgAddCommand.php](../../core/commands/DwgAddCommand.php), [api/soap/mc_dwg_api.php](../../api/soap/mc_dwg_api.php), [api/soap/mantisconnect.wsdl](../../api/soap/mantisconnect.wsdl) |
| Publishing | [core/dwg_publish_api.php](../../core/dwg_publish_api.php) |
| Importer | [admin/import-git-repo.php](../../admin/import-git-repo.php) |
| Tests | [tests/Mantis/DwgOwnershipTest.php](../../tests/Mantis/DwgOwnershipTest.php) |

### Two things worth knowing when extending this

- **`dwg_set_field()` already writes the `{dwg_history}` row and touches
  `last_updated`.** Calling `history_dwg_log_event_direct()` beside it
  duplicates the audit entry. This was caught in testing and fixed in
  30d508058; do not reintroduce it in new ownership code paths.
- **`$g_dwg_update_page_fields` carries MantisBT's `'reporter'`, never
  `'creator'`**, and the create page tests `'creator_id'` while the report
  field list supplies `'creator'`. Both mismatches predate this work. An
  optional field that "does not exist" may simply be spelled differently in
  the page and the config.

### Deploying it elsewhere

`owner_id` is a change to an existing table, so the live version needs a
**database rebuild**, not an upgrade step — follow the runbook in CLAUDE.md,
*Updating the live version with a database rebuild*. After the rebuild and
re-import, every document's owner comes from frontmatter where it matched an
account; the rest are unowned and need Bulk Set Owner. Turn email
notification off before any bulk back-fill (§9.5).

---

## 12. Test record

PHPUnit, in the running clone as `www-data`, after the rebuild:

- `DwgOwnershipTest`: **14 tests, 47 assertions, all pass.** Covers the three
  fields, the five thresholds, the takeover rule, the licence requirement, the
  owner filter and list column, the history label, and regressions for both
  bugs in §3.2.
- Full `mantis` suite: **357 tests, 1140 assertions, 1 skipped, no failures**
  (343 before this work).

Through the browser interface, by curl, on the rebuilt VM (project `example`,
sample accounts):

- **Create form** offers Owner and Assigned To. The Owner picker lists only
  DEVELOPER and above; the Assignee picker lists UPDATER and above; `viewer`,
  `user` and `reporter` appear in neither. The Owner picker preselects the
  creator (D6).
- **Create persists all three fields.** A document posted with
  `owner_id=frodo, handler_id=updater` stored creator=manager, owner=frodo,
  handler=updater, with one history row each, and its status stayed at 110 —
  no auto-promotion (D2). Before the fix the handler was silently dropped.
- **An unspecified owner falls back to the creator**: two documents created
  without an `owner_id` came out owned by `manager`.
- **View page** shows `Owner | Assigned To | Created by` in one row.
- **Controls by access level** on a document owned by somebody else:

  | | Claim Ownership | Release Ownership | Set Owner | Claim | Release | Assign To |
  |---|---|---|---|---|---|---|
  | viewer | . | . | . | . | . | . |
  | reporter | . | . | . | . | . | . |
  | updater | . | . | . | . | Y (is the assignee) | . |
  | developer | . | . | . | Y | . | Y |
  | manager | Y | Y | Y | Y | . | Y |

- **Takeover is refused server-side.** `developer` has no Claim Ownership
  button on an owned document, and a forged POST to `dwg_claim.php` returned
  **HTTP 403** with `owner_id` unchanged. On the same document once unowned,
  the button appeared and the claim succeeded.
- **Release** by the owner worked and logged `owner_id 9 → 0`.
- **Bulk Set Owner** moved two documents to `gandalf`, one history row each,
  attributed to the acting user.
- **Document list** shows the Owner and Assigned To columns with values; the
  filter box has an Owner field, and filtering by `frodo` narrowed three rows
  to one.
- **My View** shows "Documents I Own", and it listed exactly the two documents
  `gandalf` owns.
- **Update page**: Owner carries the `[Edit]` link and picker (eligible users
  only, current owner preselected); Created by is plain text and no
  `creator_id` input is emitted at all.
- **SOAP** `mc_dwg_get` returned `creator=manager, owner=frodo,
  handler=updater`; the WSDL publishes the `owner` element. The WSDL cache was
  cleared first.
- **Publishing**: a Markdown document owned by `gandalf` with no assignee
  published a control block reading **Owner: gandalf**. Under the old code
  that row came from `handler_id` and would have been dropped as empty — this
  is the §8 consequence closed.

Email notification is **permanently off** on the VM (owner decision
2026-10-20), so none of the above sent mail. The database and git store were
backed up to `admin/tools/data-scripts/vm-doctis-full-20261020.sql.gz` and
`vm-git-store-20261020.tar.gz` before the rebuild.

### Not exercised

- `email_dwg_ownership_changed()` only reached its logging path, because email
  is off; the recipient set and subject line are untested.
- The importer's new owner resolution (realname and email fallbacks, the
  collected unmatched-owner report) is not covered by a test and was not run
  against a real repository after the change.
- `$g_dwg_reassign_on_feedback` is OFF, so the note-add path that used to
  write an out-of-enum status is now unreachable; the fix is proven by the
  constant test, not by exercising that path.
