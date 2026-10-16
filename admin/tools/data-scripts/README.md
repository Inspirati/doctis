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
| `vm-users.sql` | Native VM (10.0.0.109) user accounts: administrator and the 14 sample role accounts, plus their HCRQMS (project 1) memberships. REPLACE, schema version 77 |

Keep each script's schema version header current. After a schema change that
renames or removes columns, regenerate the script from a database that has
the new schema.

## Other local files

| File | Contents |
|------|----------|
| `hcr571-pilot/` | The PRD, ICD and TP exemplars uploaded to the HCR571 Documentation Topics pilot |
| `vm-doctis-full-<date>.sql.gz`, `vm-git-store-<date>.tar.gz` | A full database dump and the git store (`/var/git/doctis`, `/var/www/doctis/worktrees`) taken before a rebuild |

## Native VM rebuild

1. Back up the database, the git store and the `user` table (Data Snapshot,
   REPLACE). Then run **Rebuild Database** and **Reset Git Store** in System
   Operations. Since 2026-10-16 these pages work on the VM as on live, because
   the VM runs them as `hcr`
   ([NATIVE-DEVELOPMENT.md](../../../doc/NATIVE-DEVELOPMENT.md)).
2. Load the `user` snapshot (or `vm-users.sql`) with **Load Data**.
3. Manage → **Import Data**, as administrator. Import `HCRQMS-dev.zip` as
   HCRQMS first, so it becomes project 1, which `$g_meeting_project_id` uses.
   Use directories `content,system`. Then import the LiDAR handbook ZIP as
   LiDAR, with directories `procedures,systems-engineering,templates`. Use
   pattern `*.md` and Private visibility for both.
4. Create the sub-projects Engineering (under HCRQMS: `content/engineering/`)
   and LiDAR/Engineering (under LiDAR: `templates/F-*`), and move those
   documents into them.
5. Create the top-level project HCR571 and load the STD-ENG-004 catalogue.
   Enable Topics, upload the three exemplars with their Topic tags, and mark
   Topics 10, 20 and 30 Applicable.
6. Move the lifecycle metadata into Doctis
   ([doc/publish/METADATA_CUTOVER.md](../../../doc/publish/METADATA_CUTOVER.md)).
   Run as `www-data` from the running clone, checking the dry run first:

   ```bash
   sudo -u www-data php admin/doctis-metadata-cutover.php --project HCRQMS --user administrator --dry-run
   sudo -u www-data php admin/doctis-metadata-cutover.php --project HCRQMS --user administrator
   ```

   Repeat for LiDAR if wanted. The import reads the frontmatter, so this must
   come after step 3. A second run changes nothing.

Turn email notifications off while reloading. The VM sends real email.
