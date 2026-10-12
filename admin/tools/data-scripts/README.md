# Local data scripts

SQL data scripts used to repopulate a Doctis database after **Rebuild
Database**. Everything in this directory except this README is ignored by git,
because the scripts hold real account data and password hashes.

Load them with Manage → System Operations → **Load Data**. Create new ones
with Backup Database → **Data Snapshot**, which writes data only, as REPLACE or
INSERT statements that name their columns.

Load in this order after a rebuild:

| Script | Contents |
|--------|----------|
| `live-users.sql` | Live (qms.hcrobo.com) user accounts: administrator, robert, admin, claude, and the colleague accounts bharath, Atchyutha, saicharan, saisudheer. REPLACE, schema version 64 |

Keep each script's schema version header current. After a schema change that
renames or removes columns, regenerate the script from a database that has
the new schema.
