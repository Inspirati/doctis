# Managed email domains — requirements and implementation proposal

Source assessment and implementation: 18 November 2026. The initial editor is
implemented; the design and alternative choices are retained below.

## Recommendation

Following the owner's clarification, company/domain association is unnecessary
for this use case. Keep the two lists in the existing **`config` database table**
and add an **Email Domains** editor on `manage_company_page.php`. Its location
alongside company maintenance is a UI convenience, not a company relationship.

Both settings already support database storage through `config_get()`, including
before login. No new table, company column or schema change is needed.

Keep permission to use an email domain separate from exemption from the
sign-up CAPTCHA. Neither property should determine whether an existing account
can log in, its access level or its selected company.

Preserve the existing empty-permitted-list meaning (unrestricted), but make
that choice explicit in the editor: reject an empty restricted list rather
than silently opening registration when its last entry is removed. No database
rebuild or changes to `user` or `company` are required.

## Current behaviour and evidence

| Setting | Current purpose | Default |
| --- | --- | --- |
| `$g_limit_email_domains` | Restricts domains during sign-up and shared email validation | Empty array: no domain restriction |
| `$g_signup_captcha_exempt_domains` | Exempts matching domains from the sign-up CAPTCHA when CAPTCHA is otherwise enabled | Empty array: no exemptions |

For an illustrative configuration, permit `example.com`, `example.org` and
`example.net`, while exempting only the first two from CAPTCHA. This allows
an external domain without giving it the staff CAPTCHA exemption. These are
synthetic examples, not an inventory of any deployment's configuration.

The lists overlap; they are not two mutually exclusive domain types. Matching
currently ignores case and matches the whole domain: `sub.example.com` does
not match `example.com`. There is no wildcard/subdomain inheritance.

The relevant paths are:

- [Defaults](../config_defaults_inc.php): both arrays, and their availability
  through the generic configuration API.
- [Sign-up API](../core/signup_api.php): domain eligibility, server-side CAPTCHA
  decision and staff-domain error messages.
- [Sign-up form](../signup_page.php) and [JavaScript](../js/signup.js): the
  public form embeds CAPTCHA-exempt domains for immediate display changes.
  [Sign-up submission](../signup.php) repeats checks on the server.
- [Shared email validator](../core/email_inc_api.php): domain checks also affect
  account creation/updates, email-valued custom fields and other callers.
  Explicitly allowed domains currently bypass MX checks; unrestricted domains
  follow the configured MX/DNS behaviour. Existing `validate_email`, LDAP-email
  and blank-email exceptions must be assessed and preserved deliberately.
- [Password recovery](../lost_pwd.php): validates the submitted email, so
  removing a domain can prevent password recovery for an existing account.
- [Authentication](../core/authentication_api.php): existing username/email
  login matches stored account data without the registration-domain validator.
  [LoginIdentifierTest](../tests/Mantis/LoginIdentifierTest.php) explicitly
  protects login after a domain-policy change.
- [Company API](../core/company_api.php), [management page](../manage_company_page.php)
  and [action](../manage_company_update.php): existing administrator checks,
  reauthentication, CSRF protection, enabled records and shared selectors.

The login page links into sign-up; it does not itself consume either domain
list for ordinary credential login. These are nevertheless pre-login features
and must work without an authenticated session or an anonymous user account.

One further distinction: the comment on `limit_email_domains` says it limits
outgoing mail, but the inspected common queue/send path does not centrally
apply that list to every To/CC/BCC address or recheck already queued messages.
The initial migration should preserve actual behaviour and correct that
description. A universal outbound-mail filter would be a separate behaviour
change, requiring explicit decisions about queued mail and password resets.

Both existing keys are already eligible for database-backed `config_get()`;
neither is in `$g_global_settings`. A dedicated editor can maintain these
database records directly. Without company associations or other per-domain
metadata, a relational domain table adds no necessary capability.

## Storage alternatives

| Design | Assessment |
| --- | --- |
| **Existing `config` table, two arrays and a dedicated editor** | Recommended: already supported before login, no schema change, sufficient for the two independent lists |
| File-defined baseline plus database additions | Preserves deployment-managed domains while allowing UI additions; needs explicit merge/source rules and prevents the UI removing baseline entries; assess below |
| Standalone `email_domain` table, without company association | Valid if per-domain attributes, retained disabled records or detailed record history become requirements; extra schema/API/migration work is unnecessary for the present use case |
| Domain columns or JSON/list fields in `company` | Unnecessary coupling: domain policy has no need to identify a company |
| Child domain table referencing `company` | Earlier proposal; defer unless a real company/domain relationship requirement appears |

Do not infer or change a user's company from their email address. Company
records, enablement and profile selections remain independent of domain policy.

### File baseline plus database additions — considered, not selected

The owner suggested retaining relatively permanent lists in `config_inc.php`
and appending UI-maintained database entries. This is possible, but is different
from the existing configuration behaviour: a matching database value currently
overrides the file value; it does not append to it. Existing file-only
configuration remains supported as fallback when no database override exists.

An append design would need a defined, case-normalised union for each list,
with an effective-policy preview showing **File**, **Database** or **Both** for
every domain. File entries would be read-only in the UI and could only be
removed by editing the deployment configuration. Removing a database entry
would not remove a matching file entry. This applies to CAPTCHA exemptions as
well as permitted domains: a file exemption could not be revoked in the panel.

Further consequences are:

- UI additions can widen the permitted set; a fixed baseline is not an upper
  limit on allowed domains. An exclusions/deny mechanism would be a separate
  policy with additional precedence rules, not ordinary append behaviour.
- The unrestricted empty-list convention needs careful handling: adding the
  first database domain to an empty baseline could inadvertently switch an
  unrestricted deployment into a restricted one. Mode selection and the result
  of clearing database additions must be explicit.
- Every pre-login/email-validation consumer must use the same merge logic;
  simply changing the management page would leave inconsistent behaviour.
- Effective policy depends on both file and database backups. Restoring only
  the database can produce different results under a different file baseline.
  Cache invalidation, testing and administrator explanations must cover both.

There is no current requirement for a deployment-enforced, non-removable
baseline. Retain the simpler recommendation: migrate managed deployments to
database-maintained lists, using the existing override/fallback behaviour and
removing stale populated file overrides after verification. Do not introduce
automatic append semantics in the initial implementation. The hybrid option
remains available if a concrete need justifies its additional rules.

## Database representation and policy rules

Retain the existing option names and supported configuration API:

| Global `config_id` | Stored value |
| --- | --- |
| `limit_email_domains` | Array of permitted domains; empty explicitly means unrestricted |
| `signup_captcha_exempt_domains` | Array of domains exempt from the sign-up CAPTCHA |

Store both for `ALL_USERS` / `ALL_PROJECTS`, with administrative write access.
Use the existing complex-value JSON serialization rather than introducing
another encoding. The writer uses prepared SQL in the configuration API's
format so a failed write can be caught and rolled back before an error response;
the generic `config_set()` query handler can terminate the request instead.
Read these particular policy values with explicit
global scope so the active project/account cannot select a different policy.
Audit any existing scoped overrides during migration before changing that
behaviour; do not merge conflicting policies automatically.

The editor can display one row per domain in the union of both arrays, with
separate **Permitted** and **Skip sign-up CAPTCHA** checkboxes. On save, derive
the two arrays from the complete validated form and commit both together.
Domain rows need no IDs, company references or new enabled flag: removing a
row removes it from the two policy lists. Record administrative changes in
the application log. A row belonging to neither set is omitted.

- With **Restrict email addresses to permitted domains** selected, require at
  least one permitted domain. Refuse a save which removes the final permitted
  domain while restrictions are selected; leave the existing policy intact.
- Choosing unrestricted mode explicitly stores an empty permitted array. The
  list is not a blocklist: removing a domain does not ban it in this mode.
- CAPTCHA exemption remains independent and never grants registration permission,
  activation or access rights. Report exemptions unusable under the selected
  restriction rather than silently adding domains to the permitted set.
- Keep exact, case-insensitive whole-domain matching, without wildcards or
  automatic subdomain inheritance. Normalize case, trim outer whitespace and
  remove duplicates before storing arrays.
- Validate domain-only input: no email address, scheme, path, port, wildcard,
  internal whitespace, empty labels or invalid DNS labels. Accept ASCII and
  punycode forms initially, with clear errors for unsupported Unicode input.

If future requirements include a restricted policy with zero permitted domains,
add an explicit stored mode then. The existing two-array model cannot represent
that state, and the initial editor must not pretend otherwise.

## Email Domains panel

Place a separate panel below company maintenance, containing the restriction
control, the combined domain rows, add/remove controls and one **Save Email
Domains** action. Explain the distinction between permitted and CAPTCHA-exempt
entries and that stored-account login remains independent.

### Required self-documenting behaviour

The owner requires **`manage_company_page.php` to explain every editable field
and record type on the page itself**. Visible introductory and adjacent help
text must let an administrator understand and safely maintain the lists without
reading this plan, PHP source or external documentation. Tooltips alone are
insufficient. Cover the existing company panel as well as Email Domains.

| Field / record / action | Required on-page explanation |
| --- | --- |
| Company record and name | A managed identity selected in user profiles; renaming changes the displayed company name for associated users. It does not establish domain ownership or change login permissions. |
| Company Enabled | Controls availability for new profile selections. Existing users can retain a disabled company; disabling it does not disable their accounts or change email-domain policy. |
| Not specified | Reserved default company record, retained for users without a selected company; it cannot be renamed or disabled. |
| Email-domain record / Domain | An exact domain such as `example.com`, not a whole email address or URL. Matching ignores case; subdomains need their own entries. There is no association with a company record. |
| Restrict email addresses to permitted domains | When selected, addresses must match the permitted list where domain validation applies. Clearing this control explicitly permits unlisted domains subject to ordinary validation. It does not itself enable self-registration if registration is otherwise disabled. |
| Permitted | Includes this domain in the restriction list. When restrictions are off, this is not an exclusive permission list or a blocklist. It does not grant an account, activation or access rights. |
| Skip sign-up CAPTCHA | Removes only the CAPTCHA requirement for this domain when CAPTCHA is otherwise enabled. It does not permit an otherwise refused domain, prove email ownership, bypass confirmation or change existing-user login. Show when global CAPTCHA settings make the exemption inactive. |
| Add / remove domain | Changes list membership only. Removing a permitted domain under restrictions may affect new accounts, email changes and password recovery for existing users, but does not delete users or prevent stored-credential login. Removing an exemption restores the normal CAPTCHA requirement. |
| Save Email Domains | Validates and saves both lists together. Removing the last permitted domain while restriction remains selected is rejected; unrestricted operation must be chosen explicitly. |

Also explain that these settings participate in shared email validation and
are not a universal outgoing-mail firewall; existing queued mail is not
reclassified by this panel. Show the effective restricted/unrestricted state,
whether values come from database records or file/default fallback, and any
unusable exemption or validation exception relevant to the current settings.
Use plain language for this summary, without exposing secrets or internal
configuration syntax an operator does not need to edit.

If the optional append design is ever selected, extend this help to explain
both sources, read-only file entries, duplicates and removal limitations, and
show the effective combined result. The initial single-policy editor needs
no baseline/append controls.

Preview affected existing account addresses before narrowing permitted domains,
including password-recovery implications for the acting administrator. Do not
mutate, disable or reassign accounts. No company dropdown is needed.

Use the configured `admin_site_threshold`, reauthentication and CSRF protection,
checked again in the write API. Escape values, validate the entire submission
before writing and commit both configuration values in one transaction. Detect
stale edits so one administrator does not silently overwrite another. Preserve
the previous policy on validation/save failure, log actor/before/after changes
and invalidate configuration caches after commit or rollback. Do not expose
uncommitted values through the request's configuration cache.

Use translatable strings with English fallback; add Hindi translations for the
new panel alongside the maintained Hindi catalogue. Keep company maintenance
and profile controls separate. Direct administrators to this panel for these
settings. Generic configuration/API writes to these keys are rejected with a
link-path instruction to use this panel, including deletion and key renaming.

## Pre-login behaviour and restore implications

Reuse the existing configuration mechanism, rather than adding a new mandatory
lookup table or requiring the company-management API during authentication.
A small policy helper can centralise globally scoped reads and normalization;
it must not depend on an authenticated user or trigger lookups simply by being
included. Do not add a domain check to existing username/email authentication.

The application already needs its `config` table. This design introduces no
additional table that can break login if omitted during upgrade or restore.
Normal defaults/file fallback remain part of `config_get()`; removing a database
record is not the same as intentionally storing an empty array. A full restore
must preserve both global rows. Keep registration paused during an incomplete
configuration restore, consistent with the
[remote recovery design](REMOTE_RECOVERY_ASSESSMENT.md).

Do not catch configuration read failures and manufacture an unrestricted array.
For dependent operations, report policy unavailability and grant no CAPTCHA
exemption; do not introduce that failure into credential authentication. Once
PHP overrides are removed, deleting the permitted-domain DB row can reveal the
unrestricted default: the panel must save valid arrays rather than delete rows.
An administrator diagnostic should distinguish stored policy from fallback.

Embed only the exemption information already public on the sign-up form.
Recheck current policy on submission, since it can change after the form loads.
JavaScript controls presentation only. Retain existing email confirmation;
CAPTCHA exemption does not establish email ownership.

## Implementation and migration sequence

1. **Inventory effective settings.** Read the two PHP defaults/overrides and all
   relevant global/scoped `config` rows per deployment. Resolve scoped conflicts
   explicitly; do not import only file literals or union incompatible settings.
2. **Add the panel and protected write action.** Store validated arrays in the
   existing configuration format and refresh its cache. Add a shared helper for
   explicit global reads; update the validator, sign-up checks/messages and
   exemption data to use that scope consistently. Preserve MX behaviour,
   validation/LDAP/blank-email exceptions and stored-account login matching.
3. **Seed global DB records once.** Import the intended effective lists in one
   transaction, preserving memberships. Show a dry-run comparison and verify
   effective values after commit. Reruns must not overwrite subsequent UI edits.
   No schema migration or `user`/`company` data update is needed.
4. **Remove deployment file overrides after verification.** Retain the existing
   option names and their documented fresh-install defaults; do not mark them
   obsolete. Remove the populated arrays from each deployment's `config_inc.php`
   after the DB records exist and are verified. This avoids stale lists returning
   if a DB record is removed. Do not reseed from PHP on ordinary requests. Update
   config samples/install guidance to point administrators to the new panel.
5. **Verify on isolated data, then VM and remote dev.** Save protected external
   backups and rollback instructions covering both DB rows and file settings.
   Live deployment is a later, separately authorised step.

## Verification required before deployment

- Normalization, exact matching, duplicate rejection, malformed input, mixed
  case, explicit subdomains and external domains such as `example.net`.
- Overlapping lists, unrestricted mode and rejecting an empty restricted-list
  save without changing either stored array.
- Unauthenticated sign-up with and without CAPTCHA, JavaScript disabled/tampered
  with and policy changes between form load and submission.
- Existing username/email login and administrator access after permitted-domain
  changes, with no new dependency on `company` or any domain table.
- Password recovery, account/admin/API email changes, email custom fields,
  blank addresses, validation/LDAP exceptions, MX behaviour and actual queued
  mail behaviour. Do not send real staff messages during tests.
- Reauthentication, CSRF, ordinary-user denial, escaped markup, concurrent edits,
  transaction failure and cache invalidation; no partial update of the two lists.
- A self-documentation review of both panels: every editable field, record type
  and action has visible explanatory text, including disabled-company behaviour,
  the default record, unrestricted mode, exact domain matching, CAPTCHA scope,
  password recovery, existing-user login and save/removal consequences.
- Migration from PHP-only settings or DB overrides, scoped conflicts, reruns,
  missing versus empty DB records, configured table prefixes/suffixes, fresh
  defaults and full-backup/restore of the global records.
- Keep/adapt `MantisCoreBase`, `UserNamePolicyTest` and `LoginIdentifierTest`
  fixtures deliberately; isolate DB-backed policy tests from real deployment
  lists and confirm company/profile records are unchanged.

The recommendation is UI management of the existing global configuration rows,
without company associations or a new table. A universal outbound-mail filter,
automatic company assignment or richer per-domain record metadata are separate
future requirements.

## Implemented operation

The panel shows effective policy and its source, explains every field, and
provides combined domain rows. **Review Email Domains** validates the complete
submission and previews newly affected accounts; **Save Email Domains** commits
both lists. Reviews expire after 15 minutes and cannot overwrite a newer policy.
The editor supports up to 200 submitted rows and detects truncated forms.

Malformed list data can be replaced through the administrator editor while
public domain-dependent operations refuse it; credential login does not read
the domain lists. No account or company record is changed by a policy save.
An explicit confirmation is required to remove legacy user/project overrides.

For an existing deployment, first run this command from its application root:

```sh
php admin/tools/doctis-migrate-email-domains.php
```

This is read-only and reports counts, migration state and a revision hash,
without displaying domains. Review any scoped conflicts before proceeding.
To persist the effective global file/fallback lists as database records:

```sh
php admin/tools/doctis-migrate-email-domains.php --apply --admin-user=ADMIN_USERNAME
```

Run as an authorised local operator and specify an existing enabled
administrator. If both global DB records already exist, the tool leaves them
unchanged. It refuses scoped conflicts. No schema change or rebuild is needed.
Preserve an external configuration/database backup, verify the policy, then
remove the two populated file assignments to avoid stale fallback. Keep all
other configuration and deployment secrets unchanged.

The browser-based alternative is to review and save the existing values from
**Manage → Companies → Email Domains**. File-only installations continue to use
the existing file/default fallback until database overrides are saved.
