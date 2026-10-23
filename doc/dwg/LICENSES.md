# Licenses — design intent and the state of the implementation

Status: **half-baked concept, under development** (owner, 2026-10-24). This
document is **context and a record of findings, not a specification, and
nothing here is to be acted on** until the owner revisits the feature. It
exists so that the next person to touch licenses knows what was intended,
what is actually built, and where the two have drifted apart.

A Doctis **license** is a skill, security clearance or professional
qualification recorded against a user account. The term is Doctis-specific and
has nothing to do with software licensing.

---

## 1. The original design intent

Doctis was originally designed as a **document index system only**. It was
never intended to hold the documents themselves.

Within that design, a license was an **indicator only** — a record of a user's
granted license access. The actual restriction of access to the material was
to be enforced by **the external data storage system**, whatever that happened
to be. Doctis said "this document requires Security Clearance, and this user
holds it"; the store decided whether that user could open anything.

This is why a user can apply any license they like to their own profile. Doing
so does not mean they can log in to a particular external data store and read
the indexed material. The list is a **self-administered record of applications
and acceptances**, not a security grant — and that was the point, not an
oversight.

## 2. Why that premise no longer holds

Doctis now stores primary documents itself, in git, and **may become a
legitimate storage location for restricted material**. The moment it is the
store rather than the index, an indicator-only license model stops being
sufficient: the thing holding the material is the thing that must enforce
access.

The owner's position (2026-10-24) is that this **may require an extensive
revision** of the feature, and that the revision is not being undertaken now.

## 3. What is actually built

### 3.1 Data

| Table | Holds |
|-------|-------|
| `{license}` | the license definition: `name`, `match_str`, `type`, `status`, `view_state`, `access_min`, `description`, `project_id` |
| `{license_user_list}` | which users have a row against a license: `user_id`, `license_id`, `status`, `date_added` |
| `{license_dwg_list}` | which documents require a license: `dwg_id`, `license_id`, `status`, `date_added` |

`$g_licenses_enabled` is **ON** by default. There are 13 license config keys,
including `$g_manage_license_threshold`, `$g_license_user_threshold` and
`$g_show_license_list_threshold`.

### 3.2 Name matching is deliberately loose

`license_is_name_unique()` ([core/license_api.php:263](../../core/license_api.php#L263))
compares both the name as typed **and** a `match_str` — the name uppercased
with every non-alphanumeric character stripped. `license_create()` stores that
reduced form, and `license_get_id_by_name()` looks up on either.

So `Security Clearance`, `security clearance`, `SECURITY-CLEARANCE`,
`SecurityClearance` and `Security  Clearance` are all the same license
(verified by probe, 2026-10-24).

**This is intentional and its purpose is to help the person applying, not to
police the person naming.** Users apply for their own licenses and type the
same qualification in every combination of capitals, spaces, hyphens and
brackets; reducing both sides to letters and digits means the application
still finds the license it was meant to find. The side effect — that two
licenses cannot differ by punctuation alone — is accepted.

### 3.3 The applied / accepted distinction is half-wired

`{license_user_list}.status` is meant to carry it, and the profile page
displays it: `my_view_cnf_page.php` renders a license in brown when
`license_user_has_applied( $id, 10 )` and normally when
`license_user_has_applied( $id, 20 )`. So **10 = applied, 20 = accepted**.

But:

- **Nothing ever writes 20.** `license_add_users()`
  ([core/license_api.php:853](../../core/license_api.php#L853)) inserts
  `license_id, user_id, date_added` only, so `status` falls to its column
  default of 10. There is no grant action anywhere.
- **Its update path writes an access level into the same column.** The same
  function's UPDATE sets `status = :new_value` from a parameter its callers
  pass as an access level, and `manage_license_user_update.php` feeds it
  `user_access_level`. So `status` can hold 10/25/40/55/70/90, and VIEWER's 10
  is indistinguishable from "applied".
- `license_add_user( $license_id, $user_id, $p_access_level )` still has the
  old parameter name; the commented-out `access_level` column it referred to
  is gone from the schema.
- `user_get_granted_licenses()`
  ([core/user_api.php:1548](../../core/user_api.php#L1548)) ignores `status`
  despite its name, and is wrapped in an `if( true )`.

So "accepted" is currently **unreachable**, and the column's values are
ambiguous.

### 3.4 Two consumers, with inconsistent rules

Only two places in the codebase ask whether a user holds a license, and they
disagree:

| Consumer | Rule | Effect |
|----------|------|--------|
| `topic_user_holds_license()` ([core/topic_expectation_api.php:730](../../core/topic_expectation_api.php#L730)) | requires `status = 20` **and** `license.enabled = 1` | matches the intended design — but because nothing writes 20, it can never return true |
| `access_dwg_user_holds_licenses()` ([core/access_dwg_api.php:489](../../core/access_dwg_api.php#L489)) | any row counts | a user satisfies a document's requirement by applying to themselves |

The Topics one is the faithful implementation. **The document-ownership one is
the divergent one**: it was added on 2026-10-20 for ownership decision D5
("an owner must hold a licence the document requires") without noticing that
the list is self-administered or that Topics had already set a precedent. See
[OWNERSHIP.md](OWNERSHIP.md) §10, D5.

The Topics check is latent rather than harmful because
`$g_topic_assessor_licenses` defaults to `array()`, and `topic_can_assess()`
short-circuits to true when no license is configured for the Topic. Configure
it and nobody can assess anything.

### 3.5 What licenses do and do not affect

They affect:

- whether a user may be recorded as a document's **Owner**
  (`access_dwg_can_be_owner()`);
- whether a user may **assess a Topic expectation**, when
  `$g_topic_assessor_licenses` is configured;
- whether a document can be **published** — `dwg_publish_api.php` refuses with
  *"documents restricted by a license cannot be published"* if the document has
  any license requirement at all, regardless of who is asking.

They do **not** affect:

- reading a document. There is no license check in `access_dwg_api.php`,
  `access_api.php`, on `dwg_view.php`, or in `file_download.php`.
- downloading the primary file or any attachment.
- cloning the project repository over the git gateway.

**The manual used to claim otherwise.** `doc/MANUAL.md` §8 stated that a user
without a required license "will be denied access to documents that require
it", and the AI Assistant answers from that manual, so it was repeating a
guarantee that did not exist. Corrected 2026-10-24 to describe what the list
actually is and what it actually affects.

## 4. Defects noted in passing, not fixed

Recorded here rather than acted on, per the owner's instruction:

- `manage_license_user_add.php` lines 49 and 52 contain leftover debugging —
  two `var_dump()` calls that write to the page output on a live request.
- `{license}` has no unique key on `name` or `match_str`; uniqueness is
  enforced only in PHP, so a direct insert or a race could create duplicates.
- `license_add_user()`'s third parameter is named `$p_access_level` but is
  written to `status`, and only on update.
- `user_get_granted_licenses()` has a dead `if( true )` wrapper and does not do
  what its name says.

## 5. Questions a revision will have to answer

Not decisions — just what is open, so the next pass does not have to rediscover
it:

- If Doctis is the store, does a license become an **enforced** access control,
  or does it stay an indicator with enforcement elsewhere?
- If enforced: enforced on what — reading, downloading, repository clone, all
  three? The git gateway is the hardest, since a clone bypasses the web UI.
- Who **grants** a license, by what action, and what does the audit trail of a
  grant look like? Today there is no grant.
- Does the self-application flow survive, and does an application notify
  somebody who can accept it?
- Should `status` be split into two columns — one for the application state,
  one for any access level — given its current values are ambiguous?
- Does `access_dwg_user_holds_licenses()` align with
  `topic_user_holds_license()`, and which of the two rules is right?
- Should a licensed document really be unpublishable outright, or should the
  publication target's own access control decide?

## 6. Related

- [OWNERSHIP.md](OWNERSHIP.md) — decision D5 and its dependency on this list.
- [USER_LIFECYCLE.md](USER_LIFECYCLE.md) — licenses are deliberately *not*
  transferred when a user is disabled; they record what a person held.
- `doc/MANUAL.md` §8 — the user-facing description, corrected 2026-10-24.
- `doc/topics/TOPICS_PLAN.md` — the assessor-license mechanism.
