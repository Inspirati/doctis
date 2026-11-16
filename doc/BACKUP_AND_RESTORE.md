# Doctis database and Git/LFS backup and restore

This procedure recovers the Doctis database and the complete Git document
store, including its separate Git LFS object store. An administrator can
perform it through **Manage → System Operations**, without container-console
access or changes to nginx, PHP, Linux packages or sudoers.

The procedure was rehearsed on 2026-11-16 by extracting the live server's data
and restoring it onto the separate remote **dev** instance. Live received
only authentication, read and backup-extraction requests. Development of the
restore tool and its first restore rehearsal used the native VM.

## Environments and scope

| Environment | Purpose |
| --- | --- |
| Native VM | Local development and initial testing |
| `https://qms.dev.hcrobo.com/doctis/` | Remote Docker development and experimental feature testing |
| `https://qms.hcrobo.com/doctis/` | Live service; changes require the owner's authorisation |

The restore feature is initially on branch **`git-store-restore`**. A destination
must run code containing **Restore Git Store** before following that part of
this procedure. Record the actual deployed commit as well as the branch.

| Component | Backup / recovery method |
| --- | --- |
| Database | **Backup Database → Download Database Backup**; includes table definitions and rows |
| Bare Git repositories | **Backup Git Store**; includes commits, branches, tags and Doctis refs |
| Git LFS objects | Included automatically by **Backup Git Store** when present |
| Server worktrees | Disposable caches; cleared by restore and recreated on the next write |
| Application configuration | **Config File → Download**; retain destination-specific settings when cloning to dev |
| Container environment, secrets and deployment files | Separate protected backup managed by the server administrator; a config file containing `getenv()` references does not contain these values |
| Disk attachments, wiki files and other external content | Separate filesystem backup where used; the database/Git downloads do not include these |
| Published websites and PDFs | Separate backup if required; reproducible outputs can instead be republished after verifying the destination |

A Git clone or bundle alone is insufficient: it does not contain the separate
LFS store. Registered external documents also remain dependent on their external
storage service. The rehearsal dataset had no disk-based issue or document
attachments, queued emails or active recurring meeting series.

## 1. Prepare the destination

1. Confirm its URL and that its database, Git, LFS and worktree storage are
   separate from the source. Verify independent filesystem mounts with the
   server administrator where inventories do not establish this. Replacing
   shared storage would also change the source.
2. Preserve the destination's database, Git/LFS store and application config
   before replacing them, if its current data is needed.
3. Record the source and destination software commits and deployed branches.
   Use **Git Checkout Status**, then **Git Pull** or **Git Branches** on the
   destination to install the intended version. Updating an application
   checkout does not update the Docker image's build context.
4. Arrange for users to stay off the destination until both database and
   files have been restored and verified. Check scheduled jobs too.
5. For a dev clone, suppress outbound notifications before loading staff
   accounts. The rehearsal set `$g_enable_email_notification = OFF;` in dev's
   config through the test-loading Config File uploader. Existing queued
   mail can still be sent by the mail worker: inspect the queue and suppress
   transport/cron delivery separately when necessary. Review recurring
   meeting jobs before allowing them to run against copied data.
6. Keep the destination's database endpoint, public URL and storage paths.
   Review copied publication targets and integration settings before using
   those features on dev. For disaster recovery, retain the source's required
   cryptographic secrets in the protected deployment backup.

## 2. Extract and protect the backup set

1. On the source, download **Backup Database → Download Database Backup**
   (`.sql.gz`). This is the complete dump, rather than a selection of tables.
2. Download **Backup Git Store** (`.tar.gz`). When LFS data exists, the archive
   contains `repositories/` and `lfs/`, and its filename includes `_lfs`.
   Older Git-only archives contain the bare repositories at the top level;
   the restore tool accepts both layouts.
3. Download the application configuration where required. Keep deployment
   environment backups separately; do not copy live's environment wholesale
   into dev.
4. Store the files together in protected storage outside Git. Record the
   source URL, extraction time, application commit, filenames, sizes and
   SHA-256 checksums. For example:

   ```sh
   sha256sum -- /path/to/database.sql.gz /path/to/git-backup.tar.gz
   ```

The SQL export uses `--single-transaction`, providing a consistent InnoDB
data snapshot. The database and Git downloads are separate operations; they
are **not an atomic snapshot across both stores**. Prefer a quiet period or
an authorised pause in source writes. When the source must remain untouched,
extract online, then validate the paired data as below. If a referenced
repository, commit, path or LFS object is absent, take another backup set;
do not force an incomplete restore through.

## 3. Compare actual schemas

Do not use `database_version` alone to determine compatibility. Doctis edits
existing table definitions in `admin/schema.php` without necessarily changing
that number.

Compare `SHOW CREATE TABLE` for every table in the backup with a disposable
database freshly built from the intended destination code. Ignore only the
next `AUTO_INCREMENT` counter. Include columns, defaults, indexes, character
sets, collations, missing tables and unexpected tables.

The full-dump reload below requires the backup definitions to match the
intended schema. Otherwise prepare and test the appropriate data migration;
see [the live runbook](LIVE_RUNBOOK.md). Loading an old full dump after a
rebuild would recreate its old definitions. A data-only snapshot with named
columns is another migration input, but does not automatically reconcile
renamed fields or changed data types.

## 4. Rebuild and restore the database

On the **destination only**:

1. Run **Rebuild Database**, confirming `REBUILD`.
2. Sign in as the newly seeded `administrator` / `root` account.
3. Optionally download this freshly rebuilt database for schema verification.
4. Open **Load Data** and upload the full `.sql.gz` backup. Type `LOAD`.
   Leave the transaction checkbox **off** for this full dump: it contains
   DDL, which MariaDB commits implicitly.
5. Confirm **Script loaded**. Sign in using an administrator restored from
   the source backup. Restoring `user` also restores its account enabled
   states, so the seeded account may become disabled again.
6. Download the restored database and compare it with the source backup.

The full dump includes `DROP TABLE` / `CREATE TABLE`, so it recreates the
backup's definitions as well as its rows. The rebuild establishes a known
starting point; verify the definitions again after loading. This rehearsal's
dump loaded through the SQL uploader. Dumps containing client-only commands
such as `DELIMITER` require a compatible import method rather than being
assumed to work through this uploader.

Restoring the database does not restore primary files. Leave document use
paused until the next step is complete. **Reset Git Store** is not required;
the restore operation replaces the store itself and validates it against the
already-restored database.

## 5. Restore Git repositories and LFS

1. On the destination, open **Restore Git Store**. Check the destination URL
   shown on the page.
2. Select the matching `.tar.gz` backup. Enter its previously recorded
   SHA-256 checksum, then choose **Upload and validate**.
3. Wait for validation to pass and review the checksum and repository/LFS
   counts. The tool checks archive paths, full Git object integrity, LFS
   checksums and sizes, and the database's primary, draft and recorded commit
   references. It refuses missing LFS objects or database references.
4. Type `RESTORE` and choose **Restore Git Store**. This replaces repositories
   and LFS data together, clears cached worktrees, and preserves each
   repository's document/source push policy. Git configuration and the two
   Doctis receive hooks are regenerated for the destination application.
5. Confirm the success message. Retain the original downloaded backups until
   verification is complete.

Uploads use 512 KiB chunks, so an archive can exceed a proxy's individual
request limit. A saved upload can be selected again on the page; choose the
same local file to resume an incomplete transfer. After signing in again,
return to this page and select the saved upload. Discard an unwanted upload
using **Discard upload**. Abandoned non-installing uploads expire after
24 hours when their owner starts another upload.

The server needs writable, separate configured Git/LFS/worktree roots and
space for the uploaded archive, unpacked data and incoming replacement.
The roots may themselves be Docker bind mounts; the tool keeps those root
directories in place. Nested mount points that cannot be renamed require
administrator attention. Default limits are 4 GiB compressed, 16 GiB unpacked
and 200,000 archive entries. The size settings are
`$g_git_restore_max_archive_size` and `$g_git_restore_max_unpacked_size`.

Archives produced by Doctis use GNU tar/ustar. Filesystem links, devices,
traversal paths, external Git object dependencies and unsupported tar entry
types are rejected; committed Git symlinks remain ordinary Git object data.

### Interrupted replacement and old storage

During replacement, the application coordinates web requests and CLI jobs
using a local lock. Other requests receive a temporary HTTP 503 response.
If another request is active when replacement starts, retry when the server
is idle. The database must not have changed since validation.

If a request is interrupted, go directly to the destination's
`manage_git_restore_page.php`, sign in if required, and use **Recover
interrupted restore**, typing `RECOVER`. An uncommitted replacement rolls
back; a committed one finishes cleanup. Recovery information and uploads
live in the container's temporary filesystem: avoid container recreation
during this operation. The local lock coordinates one application instance;
pause other independent writers if several containers share the same store.

Existing directories may be readable but not writable by the web account.
Same-parent rollback renames handle this without changing their ownership.
After success, undeletable previous data is retained under hidden
`.doctis-retained-*` names and its paths are shown in the restore report.
The Restore Git Store page also lists these paths for every administrator,
independently of the saved upload receipt.
It is excluded from subsequent Git backups and is not the active store.
The server administrator can remove those reported paths during routine
maintenance; their retention does not prevent use of the restored service.

## 6. Verify before releasing the destination

- Compare every table definition and every table's row content, including
  empty tables. Account for expected authentication changes to session
  `tokens` and the operator's `user.login_count` / `user.last_visit`.
- Confirm **Git Store** reports **Database and disk agree**.
- Download another Git/LFS backup from the destination. Compare repository
  object/refs/HEAD content and all LFS object bytes with the source archive.
  Compare push policies too. Regenerated Git config/hooks, tar timestamps,
  ownership and archive compression can legitimately differ.
- Open document pages and download a registered primary file. Download an
  LFS-backed repository file and confirm its size and SHA-256 against the
  saved LFS object.
- Test authenticated Git clone/fetch and LFS download from the destination
  URL. Use a destination API token and revoke a temporary verification token
  afterwards. No source token or account needs modifying.
- Check PHP errors, notification settings, scheduled jobs and target URLs.
  Do not resume editing or publishing until the destination checks pass.

## Rehearsal result — 2026-11-16

The remote dev database was rebuilt and restored from all 73 live tables.
All definitions and data matched, allowing the authentication changes above.
The restored store contained six repositories and 84 LFS objects totalling
71,765,033 bytes. All 410 content files compared identically between the
source and restored archives, excluding regenerated config/hooks and the
storage lock. Push policies were preserved.

Registered-document download, a 523,412-byte LFS-backed repository download,
authenticated LFS transfer and authenticated Git clone passed on remote dev.
The temporary dev token was revoked. Dev notifications remained disabled.
Read-only previous storage was retained for later administrator cleanup;
no console access or global host configuration change was required.
