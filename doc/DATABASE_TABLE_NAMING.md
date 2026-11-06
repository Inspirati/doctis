# Database table names and maintenance snapshots

Owner's requirements and VM verification, 6 November 2026.

## Naming rules

- Keep logical/base table names descriptive and **26 characters or fewer**.
- Do not hard-code a `doctis_` prefix. Deployments may configure their own
  uniform table prefix/suffix; the owner's installations use neither.
- Do not append dates or timestamps to table names.
- Use `db_get_table()` in the PHP schema/application code so deployment-specific
  prefixes/suffixes remain supported. The 26-character target applies to the
  base name, before any explicitly configured prefix/suffix.
- `admin/schema.php` defines the application schema. Do not add persistent
  backup/import-staging tables to the application database during maintenance.
  Keep recovery copies in protected SQL files outside version control instead.
  Dates and provenance belong in backup filenames and the maintenance record.
- If a scratch table is necessary for a test, use a temporary table or a
  disposable test database, a short descriptive name, and explicit cleanup.

For example, `meeting` contains meeting occurrences and `meeting_series`
contains recurrence rules. A name such as `meeting_before_tz_20261101` is
neither an application entity nor a sufficiently clear permanent record of a
maintenance operation.

## What the reported names represented

The long names raised by the owner were **ad hoc maintenance backups/import
tables**, not additions to `admin/schema.php`. No application code referenced
them. The VM inventory contained:

| Former VM table | Observed contents / role |
|---|---|
| `doctis_meeting_before_timezones_20261101` | One meeting row in the pre-timezone table structure; no `timezone` column |
| `doctis_meeting_series_before_timezones_20261101` | Empty copy of the recurrence table's pre-timezone structure; no `timezone` column |
| `doctis_user_before_invite_default` | User-table snapshot, 25 rows; name associates it with the invitation-default maintenance |
| `doctis_user_before_invite_sequence_fix` | Separate user-table snapshot, 25 rows; name associates it with the invitation-sequence correction |
| `doctis_user_before_live_import_20261031` | Earlier user-table snapshot, 25 rows |
| `doctis_user_live_import_20261031` | User import table, 39 rows |

The user-backup names identify their apparent maintenance context, but there
was no application schema definition giving those tables an ongoing role.
They are retained in the recovery dump, not promoted into new permanent
tables with shorter names.

The separately reported `doctis_meeting_before_tz_20261101` and
`doctis_series_before_tz_20261101` were not present on the VM and do not appear
in the tracked schema/code. Their exact origin or relationship to other
backups must be checked on the server where they exist. This VM work does
not establish that those other copies are interchangeable or change live.

## VM rebuild and reload

The owner authorised a VM rebuild and supplied the live-user snapshot:

```text
/home/robert/Downloads/doctis_data_user_20261106_111519.sql
```

The canonical schema already complied with the naming rules: **72 application
tables**, with `topic_expectation_revision` the longest base name at 26
characters. No canonical table rename or schema-version increment was needed.

The reload was rehearsed in a separate database using the `CREATE TABLE`
definitions from schema version 80. Existing non-user table definitions,
row counts and data checksums matched the rehearsal. All 39 previous VM user
IDs existed in the supplied 44-user snapshot with the same usernames, allowing
existing ownership, project and document references to remain associated with
the same accounts.

For the VM cutover:

1. Briefly put the application into maintenance, covering web requests and
   scheduled scripts that initialise through `core.php`.
2. Save a full SQL recovery dump and a Git-store/configuration archive.
3. Recreate the database from the canonical table definitions.
4. Reload existing non-user application data, retaining document/repository
   IDs, settings, preferences and history.
5. Load the supplied live-user snapshot, replacing the previous user contents.
   Use the existing VM adaptation to enable `administrator` for testing;
   retain the imported passwords and invitation preferences unchanged.
6. Preserve the Git stores and their mappings, verify checksums, and remove
   the maintenance gate. Remove the disposable rehearsal database.

This was a **schema rebuild with a coordinated data reload**, rather than a
clean-slate document/Git reset. Because the document and repository records
were reloaded unchanged, their existing Git stores were retained. The
whole-instance reset script was not used: it would erase those stores and
seed unrelated sample data.

Verified immediately after the reload:

| Check | Result |
|---|---|
| Application tables | 72; exactly the canonical inventory |
| Hard-coded `doctis_` tables / names over 26 characters | None |
| Schema version | 80 |
| Users | 44, from the new live snapshot with the VM-only administrator adaptation |
| Documents / repositories | 108 / 3, preserved |
| Non-user table contents | Row counts and data checksums unchanged |
| Git repositories, worktrees and LFS objects | File-content manifest unchanged |
| Application configuration | Checksum unchanged |

Post-reload checks passed on the running VM: **85 tests / 422 assertions**
across login identifiers, username policy, organisation charts and the meeting
API/timezone suites. Tests used disposable fixtures with outgoing email
disabled. Final counts remained 44 users, 108 documents, 3 repositories and
1 meeting; there were no recurring-series rows or queued emails. The login
page returned HTTP 200, and the rehearsal database and maintenance gate were
removed.

Protected recovery material is under the gitignored local directory
`admin/tools/data-scripts/vm-schema-cleanup-20261106/`. It includes the full
pre-rebuild database (including the six old backup tables), Git/config archive,
original and VM-adapted user snapshots, checksums and reload results. These
contain private runtime data and must not be committed. No live-server
database was modified.
