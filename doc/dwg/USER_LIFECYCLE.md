# Removing a user: disable, not delete

Owner decision, 2026-10-20: **a staff member who leaves is removed by
disabling their account, never by deleting it.** Deleting remains available,
as an administrator action, for mistakes — a fake sign-up, or a registration
that needs reverting.

Anonymisation is explicitly **not** required: user data records are treated as
company property rather than personal data, at least for now.

---

## 1. Why disable rather than delete

`user_delete()` ([core/user_api.php:881](../../core/user_api.php#L881)) cleans
up four things — profiles, preferences, project access levels and API tokens —
nulls `reports_to` on the account's direct reports, and deletes the `{user}`
row.

**It does not touch the other 40-odd tables that hold a user reference.**
`{dwg}.creator_id`, `owner_id` and `handler_id`, `{dwg_history}.user_id`,
`{dwgnote}.creator_id`, `{dwg_monitor}`, `{dwg_tag}`,
`{dwg_primary_file}.user_id`, `{dwg_revision}.user_id`,
`{license_user_list}`, `{category}.user_id`, `{filters}` / `{dwg_filters}`,
`{config}.user_id`, `{bug}.handler_id`, `{meeting}.chair_id`,
`{meeting_action}.owner_id` and `{topic_expectation}.owner_id` all keep
pointing at the deleted id.

So deleting does not clean anything up. It keeps every dangling reference and
throws away the one thing that made them readable:

| | Owner row renders as |
|---|---|
| Account disabled | `frodo` (struck through) |
| Account deleted | `user269` (struck through) |

Both go through `prepare_user_name()`
([core/prepare_api.php:138-146](../../core/prepare_api.php#L138-L146)), which
presents a disabled *or* missing user identically: struck through, with no
profile link. `user_get_name()` falls back to
`$s_prefix_for_deleted_users . <id>` for an account that no longer exists.

For a quality-management system this is decisive: `{dwg_history}` is the audit
trail, and a document-control record that reads "user269 moved this to
Approved" is not auditable. Deletion is the only operation in Doctis that
destroys audit information, and it buys nothing.

A second benefit: a disabled account **keeps its username and email
reserved**. Given the first-come-first-served lowercase-first-name sign-up
rules, that stops a new joiner taking a departed colleague's name and
appearing to inherit their history.

## 2. What disabling already does

Nothing extra had to be built for any of this:

| Area | Effect of `enabled = 0` |
|------|-------------------------|
| Login | refused ([core/authentication_api.php:337](../../core/authentication_api.php#L337)) |
| Email | dropped as a recipient ([core/email_api.php:270](../../core/email_api.php#L270), [core/email_dwg_api.php:289](../../core/email_dwg_api.php#L289)) |
| Owner, Assignee and every other user picker | gone — they all resolve through `project_get_all_user_rows()`, which filters `WHERE enabled = ON` |
| Org chart, Reports To picker, profile selectors | gone (`user_get_enabled_rows()`) |
| Manage → Users | hidden by default; there is a **show disabled** toggle |
| Last administrator | cannot be disabled ([UserUpdateCommand.php:259-270](../../core/commands/UserUpdateCommand.php#L259-L270)) |
| Historical display | name preserved, struck through, no link |

## 3. When deleting is still right

Deleting is for accounts that never became a person in the system:

- a fake or spam sign-up;
- a registration made in error, or with the wrong username, that should be
  reverted rather than left as a disabled shell.

**Prune Accounts** is the safe bulk form of this. It only touches accounts
with `login_count = 0 AND date_created = last_visit`
([manage_user_prune.php](../../manage_user_prune.php)) — sign-ups that have
never been used and therefore cannot have touched a document.

## 4. Access

`$g_delete_user_threshold` (default `ADMINISTRATOR`) governs every delete
path, deliberately above `$g_manage_user_threshold` (`MANAGER`), which still
governs creating, editing and disabling accounts:

| Path | Gate |
|------|------|
| Manage → Users → **Delete User** | `UserDeleteCommand::validate()`, and the button is hidden below the threshold |
| Manage → Users → **Prune Accounts** | [manage_user_prune.php](../../manage_user_prune.php), button hidden too |
| `DELETE /api/rest/users/{id}` | the same command |
| **Delete Account** on your own profile | [account_delete.php](../../account_delete.php), and still off unless `$g_allow_account_delete = ON` |

The pre-existing guards are unchanged: a protected account cannot be deleted,
nor one with a higher access level than the actor, nor the last enabled
administrator, and the command refuses to delete the acting user's own
account.

## 5. Transferring what a disabled user holds

Disabling preserves the *name*; it does not hand on the *responsibilities*.
**Transfer Responsibilities** does that.

A disabled user otherwise remains the Owner and Assignee of their documents,
the handler of their issues, the chair of meetings yet to be held, the owner of
open meeting actions and Topic expectations, and a category's default
assignee — and because they have dropped out of every picker, there is no easy
way to see what they still hold.

### How it works

Disabling an account that still holds something redirects the administrator to
the transfer page instead of back to the user list, so the step cannot be
quietly skipped. The page is also linked from Manage → Users whenever a user
holds anything, so an account disabled before this existed can still be dealt
with.

The page lists the holdings by category with a tick box each, and a successor
picker. **The successor is chosen by an administrator, not derived.**

| Category | Field | Scope |
|----------|-------|-------|
| Documents owned | `{dwg}.owner_id` | all |
| Documents assigned | `{dwg}.handler_id` | all |
| Issues assigned | `{bug}.handler_id` | all |
| Meetings to chair | `{meeting}.chair_id` | agenda or minutes stage only |
| Meetings to minute | `{meeting}.minute_taker_id` | agenda or minutes stage only |
| Open meeting actions | `{meeting_action}.owner_id` | not cancelled, and not already an issue |
| Topic expectations owned | `{topic_expectation}.owner_id` | enabled only |
| Categories assigned by default | `{category}.user_id` | all |

Approved and cancelled meetings are historical records and are left alone. An
action that has already become an issue is counted under *Issues assigned*
rather than twice.

**Historical attribution is never transferred.** `creator_id`, `{dwg_history}`,
notes, tags, revisions, primary-file rows and the git commit trail all keep the
original person's name. Every transferred change is written through the normal
set-field path, so it lands in the history attributed to the administrator who
performed it — "administrator changed Owner from frodo to gandalf" — which
audits correctly, where a silent automatic move would not.

### `reports_to` is the default, not the authority

The successor picker is pre-filled with the first **enabled** account up the
user's `reports_to` chain. Disabled links are skipped and the walk continues,
because `user_ensure_valid_reports_to()`
([core/user_api.php:938](../../core/user_api.php#L938)) only validates at write
time: a manager disabled later still has reports pointing at them. Cycles
terminate the walk.

It is only a suggestion, deliberately (owner decision 2026-10-20). `reports_to`
is optional — most accounts have none, so there is often nothing to suggest and
the administrator simply picks — and it is editable by the user themselves on
My Configuration View, so it carries no authority over who inherits a
controlled document. The owner chose to leave it that way rather than make it
mandatory and administrator-only.

### Eligibility is checked per row

A successor one step up the chain may not be allowed to hold a given item. The
requirement is per category: `access_dwg_can_be_owner()` for document
ownership (which covers `$g_own_dwg_threshold` **and** any licence the document
requires), `$g_handle_dwg_threshold` for document assignment and category
defaults, `$g_handle_bug_threshold` for issues.

Rows the successor may not take on are **left where they are and reported as
skipped**, so a partial transfer is honest rather than failing the whole
operation or, worse, recording an ineligible holder.

### Code

| File | Role |
|------|------|
| [core/user_transfer_api.php](../../core/user_transfer_api.php) | `user_transfer_categories()`, `_holdings()`, `_summary()`, `_has_holdings()`, `_suggest_successor()`, `_eligibility()`, `_row_allowed()`, `_perform()` |
| [manage_user_transfer_page.php](../../manage_user_transfer_page.php) | the holdings list and successor picker |
| [manage_user_transfer.php](../../manage_user_transfer.php) | the POST handler |
| [manage_user_update.php](../../manage_user_update.php) | redirects to the page when an update disables an account that still holds something |
| [manage_user_edit_page.php](../../manage_user_edit_page.php) | the standing **Transfer responsibilities** link |
| [tests/Mantis/UserTransferTest.php](../../tests/Mantis/UserTransferTest.php) | 13 tests |

Access is `$g_manage_user_threshold` (MANAGER) — transferring is part of
managing an account, not deleting one, so it does not need
`$g_delete_user_threshold`.

### Two defects fixed with it

Both were the same question — *is this user still valid?* — asked in places
that only checked whether the account existed:

- `topic_expectation_readiness_blockers()` raised `topic_blocker_no_owner`
  only when `owner_id == 0`, so an expectation owned by a disabled account
  looked owned and passed readiness. It now also blocks on a missing or
  disabled owner.
- `DwgData::create()` checked `user_exists()` but not `user_is_enabled()` on
  the category's default assignee, so a disabled user kept collecting
  auto-assigned documents.

### Test record

2026-10-20 on the VM: `UserTransferTest` 13 tests / 59 assertions pass, and
the full `mantis` suite passes (370 tests, 1 pre-existing skip). Through the
browser interface as `administrator`:

- the transfer page lists holdings by category and suggests the user's manager
  from `reports_to`;
- disabling a user who still owns a document redirected to
  `manage_user_transfer_page.php?user_id=9` rather than back to the edit page,
  with the account disabled;
- the transfer moved the document's owner and left `creator_id` alone, writing
  one history row attributed to the administrator who performed it;
- transferring two owned documents to a REPORTER returned
  `moved=0&skipped=2` and both documents kept their existing owner, confirming
  the per-row eligibility guard.

**Not exercised:** the issue, meeting, meeting-action and Topic-expectation
categories have unit coverage of the category definitions but were not run
against real rows, because the rebuilt VM has none.

See [OWNERSHIP.md](OWNERSHIP.md) §9.3 for how this was originally scoped.
