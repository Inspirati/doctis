# Managed company records

Implemented in source, 6 November 2026. Deployment requires the schema and
data migration described below; older schema-80 databases cannot run the new
profile pages until migrated.

## Model and behaviour

`company` stores `id`, `name` (up to 128 characters, unique under the database
collation) and `enabled`. `user.company_id` replaces the old free-text
`user.company` field and is indexed. Names are looked up rather than copied
into user rows. Both table names honour the configured prefix/suffix in the
application schema and queries.

The reserved record **ID 1, Not specified, enabled** is the default for new
accounts and every account migrated from the old schema. Migration deliberately
discards all previous company strings; it neither recognises variations nor
creates companies from those strings. Preserve an external SQL backup if the
old text may need to be inspected later.

Both My Configuration View and administrator Edit User use the same selector.
The profile and administrator/API update commands accept `company_id` and
validate it before changing any account fields. The old free-text `company`
write parameter is rejected with an instruction to use `company_id`.
Omitting `company_id` preserves the current association. An explicit blank,
zero, unknown or malformed ID is rejected; select ID 1 to clear an association.

An enabled company can be selected. A disabled company remains visible to its
existing users and may be retained when saving their other details, but cannot
be newly assigned to another user. Organisation charts, CSV/PDF chart exports
and meeting-assistant rosters resolve the current company name, including
disabled companies retained by existing users.

## Administration

**Manage → Companies** is available at the configured `admin_site_threshold`.
Administrators can add, rename, enable and disable companies. There is no
delete action, so existing associations are not orphaned. The default entry
cannot be renamed or disabled. Administrative writes require reauthentication,
CSRF protection and the same access check in the company API. Ordinary users
can choose a company for their own profile but cannot maintain the list.

## Schema and migration

The flat canonical schema is in `admin/schema.php`:

- Step 47 defines `user.company_id` with default 1 and `idx_company_id`.
- Step 81 creates `company`.
- Step 82 inserts the reserved default company.
- A fresh installation finishes at **schema version 82**.

The automatic schema upgrader alone is insufficient for existing users: the
changed step-47 definition does not ALTER an existing table. Use the reviewed
[schema update SQL](../admin/tools/schema-updates/2026-11-06_companies.sql)
for an existing schema-80 database, or rebuild and reload appropriately.
The SQL is for an unprefixed installation. For a deployment with configured
prefixes/suffixes, adapt all three table identifiers (`company`, `user`,
`config`) before running it. Do not add a hard-coded `doctis_` prefix.

For a coordinated update of an existing database:

1. Save a protected database recovery dump outside version control. Do not
   create backup tables in the application database.
2. Put the application into maintenance, including background jobs, and allow
   active requests to finish. Deploy code and schema together in this window.
3. Run the SQL once. It creates the company table/default record, replaces
   the old user column, assigns every user to ID 1 and records schema 82 last.
4. Compare `SHOW CREATE TABLE` for `company` and `user` against fresh canonical
   definitions. Confirm that the company table has exactly the one default
   record and that every migrated user has `company_id=1`.
5. Verify the two profile forms and **Manage → Companies**, then resume service.

The source must already have the meeting timezone updates appropriate to
schema 80; this company migration does not apply earlier unrelated updates.
No production migration has been performed as part of implementation.

**Old user snapshots:** dumps containing the old `company` column cannot be
loaded directly into the new user table. For a full reload, restore the old
schema/data and then run this migration, or prepare a column-aware data
transformation that drops the old company value and supplies `company_id=1`.
Do not match company names or retain a second free-text source of truth.
After migration, a full database snapshot must include `company` as well as
`user` to retain any subsequently maintained associations.

## Verification

Tests use isolated databases and disposable fixtures, with outgoing email
disabled. `CompanyTest` covers default records/new users, both update commands,
invalid selections without partial writes, disabled selections, renaming in
charts/AI rosters, unique/default-name protection, management access, escaped
selector markup and the SQL migration against temporary legacy tables.
Existing profile tests use managed company IDs.

The affected company, profile, AI, organisation-chart, login and meeting
regression suites passed: **122 tests, 630 assertions**.

An isolated copy of the 44-user VM database was migrated successfully: exactly
one company was created, all 44 users were assigned ID 1, and both affected
table definitions matched fresh schema-82 definitions. A separate HTTP test
instance verified administrator add/rename/disable, both selectors and save
actions, disabled-selection retention and exclusion, escaping, CSRF rejection
and ordinary-user access denial. The deployed VM database was not changed.
