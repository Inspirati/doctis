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

## 5. Open: transferring what a disabled user still holds

Disabling preserves the *name*. It does **not** release the *holdings*, and
this is not yet solved.

A disabled user remains the Owner and Assignee of their documents, the handler
of their issues, the chair of future meetings, the owner of open meeting
actions and Topic expectations, and a holder of licences. Because they have
dropped out of every picker, there is also no easy way to see what they still
hold.

Two consequences are worth fixing regardless of which transfer mechanism is
chosen:

- **A disabled Topic expectation owner passes readiness silently.**
  `topic_expectation_readiness_blockers()`
  ([core/topic_expectation_api.php:1073-1081](../../core/topic_expectation_api.php#L1073-L1081))
  only raises `topic_blocker_no_owner` when `owner_id == 0`, so an expectation
  owned by a disabled user looks owned.
- **A disabled category default assignee still gets new documents.**
  `DwgData::create()` ([core/dwg_api.php:487-499](../../core/dwg_api.php#L487-L499))
  checks `user_exists()` on the category's `user_id` but not
  `user_is_enabled()`.

### The `reports_to` escalation idea

The owner's suggested approach is to escalate everything a disabled user holds
up their chain of command, using `{user}.reports_to`.

The field is a sound foundation in one respect: `user_ensure_valid_reports_to()`
([core/user_api.php:938](../../core/user_api.php#L938)) already rejects
self-reference, a manager who does not exist or is disabled, and reporting
cycles, by walking the chain.

Three problems have to be answered before it can carry ownership transfer:

1. **It is sparsely populated.** On the VM only 3 of 17 enabled accounts have
   a manager set. The column is `NOT NULL DEFAULT 0` and nothing requires it,
   so for most users escalation would have nowhere to go and the documents
   would stay orphaned — the problem the mechanism is meant to solve.
2. **It is self-declared.** `reports_to` is editable by the user on My
   Configuration View ([my_view_cnf_update.php:37](../../my_view_cnf_update.php#L37)),
   not only by an administrator. Using it as the authority for who inherits a
   document means a departing staff member effectively chooses their
   successor.
3. **The chain can contain disabled links.** Validation runs at write time
   only, so a manager disabled later still has reports pointing at them. An
   escalation walk must skip disabled links and keep climbing, not take
   `reports_to` once.

There is also an eligibility question: the inherited owner must satisfy
`$g_own_dwg_threshold` and hold any licence the document requires
(`access_dwg_can_be_owner()`), and an inherited assignee must satisfy
`$g_handle_dwg_threshold`. A manager one step up may meet neither.

See [OWNERSHIP.md](OWNERSHIP.md) §9.3 for the originally scoped Transfer
Ownership action, which remains unbuilt.
