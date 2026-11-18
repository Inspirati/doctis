# Remote backup and recovery through Doctis

Assessment date: 2026-11-16. Audience: Doctis owner and server administrator.
This records verified coverage, remaining requirements and proposed development.
Proposed capabilities below are not yet implemented.

## Conclusion and scope

**Console access was not required to restore the current live database and
Git/LFS dataset to dev.** The rehearsal completed through Doctis, including
the destination software update, database rebuild, SQL reload and Git/LFS
replacement. See [Backup and Restore](BACKUP_AND_RESTORE.md) for the tested
procedure and results.

The scope is application-data backup and restore onto an operational Doctis
installation with its required infrastructure already provisioned. It includes
disk attachments, wiki content where used, application configuration and
recovery from failures caused by the restore operation itself, such as a
partially imported database. The rehearsal does not establish coverage for
all those cases.

The owner explicitly excludes catastrophic server or Docker-container failure
from this task. Direct console intervention for those situations is understood
and accepted. Infrastructure reconstruction, container replacement, deployment
secret recovery and an external recovery portal are not deliverables here.

The owner relayed the server administrator's confirmation that dev is
independent of live with separate stores for everything. No further console
inspection of mounts is a prerequisite for treating dev as the independent
test destination. This confirmation does not establish the permissions or
recovery provisions within each store.

## What is available now

| Operation | Current capability and evidence | Remaining limitation |
| --- | --- | --- |
| Database backup | Full SQL/gzip download with definitions and rows; tested on live | Separate from Git backup; no coordinated snapshot across stores |
| Database rebuild and reload | System Operations rebuild and Load Data; tested on VM and remote dev | Depends on working application authentication before each action; failed DDL import can prevent subsequent login |
| Git and LFS backup/restore | Download, chunked upload, validation, replacement and recovery; tested on VM and remote dev | Restore progress and maintenance marker are temporary files; replacement and validation are long HTTP operations |
| Git worktrees | Cleared and recreated as caches | Independent writers must remain stopped during recovery |
| Application configuration | Download and checked upload; used on dev | Requires existing delegated config-write helper; no independent recovery if the installed configuration stops Doctis loading |
| Disk issue/document attachments | Database records are backed up | Their external file bytes are outside the database/Git archive; no corresponding complete restore operation |
| Database-stored attachments | Included in full SQL backup | Large BLOBs increase SQL import requirements |
| Wiki content | Integration settings can exist | No integrated wiki filesystem backup/restore in this procedure |
| Published HTML/PDF sites | Can be retained separately or regenerated where inputs/tooling remain available | No integrated site restore or verified bulk republish step |
| Old Git/LFS/worktree data | Undeletable previous directories are retained and listed to administrators | Three retained paths on dev need eventual cleanup by an account with permission; restored service is usable meanwhile |

This is evidence for the rehearsed dataset: 73 tables, six repositories and
84 LFS objects. It contained no disk attachments, queued emails or active
recurring meeting series. Those empty categories need separate test fixtures;
their absence is not evidence that a general full restore handles them.

## Remaining requirements within the restore scope

The following identifies prerequisites and operations which can affect an
application-data restore on otherwise operational infrastructure:

| Condition | Can Doctis handle it today? | Requirement / possible remote solution |
| --- | --- | --- |
| Healthy destination, matching database schema, writable Git/LFS roots | Yes, as rehearsed | Existing procedure; no console required |
| Readable old files cannot be deleted after replacement | Restore succeeds; cleanup remains | Scoped cleanup helper or suitable ownership established by administrator |
| Storage root is absent, read-only or inaccessible; nested mount cannot be renamed | No general repair | Administrator provisions/repairs the destination volume; Doctis can report the exact failing capability |
| Import drops required tables and then fails | Existing pages may become unavailable | Recovery controller independent of the affected tables, with saved pre-restore data and independent authorisation |
| Archive or SQL operation exceeds upload/request/memory limits | Git upload is chunked; SQL is not | Persistent jobs, streaming SQL and resumable transfers within existing per-request limits |
| Restore request or browser session is interrupted | Upload resumption and Git replacement recovery exist; broader job recovery is incomplete | Durable job state and a recovery route usable after a partial import |
| Required database grants or delegated helpers are absent | Cannot grant its own privileges | Readiness report identifies the specific prerequisite for administrator provisioning |

External classified-content bytes remain with their owning service; this task
restores their Doctis registrations and references.

The current Git restore does not need a new sudoers rule. Database rebuild
and configuration upload already use delegated OS-account helpers. Extending
those privileges or creating mounts is a deployment task, rather than
something a PHP page can grant itself.

## Recommended Doctis development

### 1. Recovery readiness report — prerequisite, relatively small

Add an administrator page that produces a downloadable, sanitised report:

- Destination URL, application commit, schema definitions/fingerprint and
  table prefix/suffix; do not rely on `database_version` alone.
- All configured data components: Git, LFS, worktrees, per-project/default
  attachment locations, publication targets, wiki locations where configured,
  and the proposed persistent recovery directory.
- Presence, readability and free space for each filesystem, plus writable
  staging and rename/delete capability checked with temporary probe files
  created by the test itself. Do not alter existing content or permissions.
- Availability of required binaries, delegated helpers and database privileges;
  PHP upload/memory limits and observed transfer failures. An application
  cannot infer every outer proxy limit just by reading its own configuration.
- Missing files, unaccounted components and retained previous storage, with
  an explicit distinction between “empty”, “excluded” and “not checked”.

This should turn a vague request for console access into a specific operation
for the administrator, and show whether a restore can proceed entirely in
Doctis. It cannot prove isolation from another container merely from local
paths; the administrator's separation confirmation remains the evidence here.

### 2. Protect user-table replacement — first restore priority

The owner requires restoration of `user` to be the first independent database
restore phase, and to be atomic as far as the database permits. Apply the same
principle to standalone bulk user reloads and updates. A failed user import
must leave the previous complete table available, rather than a missing,
empty or partly loaded active table. Merely moving its SQL statements to the
start of a dump does not provide this guarantee.

**Current limitation:** the full SQL uploader executes the dump in its supplied
order. It does not isolate `user` or stage its replacement. The existing rebuild
helper drops the database, including `user`. Consequently the proposed protected
workflow cannot simply call that helper or replay the unchanged full dump after
restoring users. The rehearsed procedure remains the historical baseline, not
an implementation of this requirement.

The proposed workflow is:

1. Enter destination maintenance and establish the independently authorised
   recovery job before changing authentication data. Pause account writes and
   jobs, including login counters/session changes during the switch.
2. Extract the user component using a supported dump parser/importer or an
   isolated staging database. Do not split arbitrary SQL with regular expressions.
   Load and validate it away from the active table, preserving source IDs,
   passwords, account states and relationships exactly. Use configured table
   prefix/suffix rules; short staging/previous names must be job-tracked,
   collision-checked and never overwrite unrelated existing tables.
3. Check the staged schema, unique keys, row count/content and authentication
   fields. Require an enabled administrator whose credentials the operator can
   validate against the staged account using the configured login method before
   promotion. An administrator row alone does not establish usable login.
   Do not silently add an account, change passwords, enable users or carry a
   destination administrator into the restored source data.
4. Commit **only** the validated user replacement. For a matching InnoDB schema,
   use one controlled transaction for a data-only replacement/update, validating
   before `COMMIT` and rolling back on any error. A complete data replacement
   can use `DELETE` plus column-named inserts; do not use `TRUNCATE`, DDL,
   autocommit changes or dump-supplied transaction boundaries in that transaction.
   Inspect actual engines, triggers and constraints first; the canonical
   `admin/schema.php` defines `user` as InnoDB.
5. Where schema replacement is required, prepare a separate regular staging
   table, then switch names in **one** multi-table `RENAME TABLE` statement,
   retaining the previous table for controlled rollback. Verify database-version
   support, privileges, table-specific grants, triggers and foreign-key behaviour
   before selecting this strategy. The active name must never be dropped first.
   MariaDB documents the atomic multi-table rename operation and rollback when
   a rename in that statement fails in its
   [RENAME TABLE reference](https://mariadb.com/docs/server/reference/sql-statements/data-definition/rename-table).
   This is statement atomicity, not an enclosing transaction for the full restore.
6. Record and verify the committed result before continuing. A lost response
   during commit is an uncertain outcome: inspect the journal and actual table
   state before retrying. Never assume a disconnect means the commit failed.
7. Restore remaining database components without dropping, truncating, updating
   or importing `user` again. A rebuild must stage/rebuild the remaining tables
   while preserving the committed users and the independent recovery job.
   Validate user-ID relationships against the same backup set before releasing
   normal traffic; keep the previous data protected until the whole job passes.

`CREATE`, `DROP`, `TRUNCATE` and `RENAME` cause implicit commits, so checking
the current uploader's transaction box cannot make a DDL-containing user dump
atomic. See MariaDB's
[implicit-commit reference](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).

After the user switch, the operator's old login cookie may no longer identify
the same account. Normal bootstrap also reads `user_pref` for timezone, and
configuration/access checks use other data. A complete `user` table therefore
protects the most critical component but does not guarantee that the ordinary
login/admin pages work during a partial restore. Keep the job-specific recovery
route usable independently of those tables. Verify a fresh administrator login
once its dependencies are ready, before resuming the application.

Acceptance tests must cover bad user rows and duplicate keys, missing/disabled
administrators, unusable credentials, failure midway through data load,
disconnects before/after commit, session identity changes, and a later failure
in another table. Confirm that the active user table is always a complete old
or complete validated replacement, the recovery job remains accessible, and
remaining phases cannot replay the user import. Perform these tests with
disposable VM data before remote dev; never test these failures on live.

### 3. Persistent recovery controller — substantial

Build a common restore job around the existing Git archive validation and
chunk transport, rather than a second unrelated Git restore implementation.

1. Save uploads, checksums, job state, journal and reports outside the database
   being restored, on a configured persistent destination volume outside the
   webroot. Validate access and disk headroom before any destructive action.
2. Authorise an administrator before starting. Issue an expiring recovery
   credential limited to that destination and job, stored as a verifier outside
   the replaced tables. Provide a minimal recovery route which does not require
   the normal user/config tables. Rate-limit it, protect state-changing requests,
   audit use and revoke the credential after completion or cancellation. This
   must not become an unrestricted SQL runner or general login bypass.
3. Stage and validate the entire backup set before touching active data. Compare
   actual schema definitions; require a tested migration for incompatible dumps.
   Recognise table naming configuration explicitly; never silently rewrite SQL
   or guess a mapping between source and destination schemas.
4. Pause normal destination traffic, Git/LFS writes, email dispatch, recurring
   meeting jobs and other participating workers for the **whole** restore,
   including database import. Preserve the pause after a failed job or restart.
5. Import SQL as a bounded stream through a supported importer. The existing
   uploader reads/decompresses the complete file and sends it in one
   `multi_query`, bounded by `max_allowed_packet`; compression alone does not
   remove that limit. A command-line database client can run behind a web
   operation without an operator needing console access, using protected
   credentials and only the intended destination database privileges.
6. Keep progress independent of the browser connection. Use an installed worker
   or bounded requests with durable checkpoints. Arbitrary SQL cannot safely
   resume at a guessed byte offset: retry into an empty staging database where
   provisioned, or restart a documented destructive import from the beginning.
7. Retain a pre-restore database and file set until verification succeeds.
   Journal phases and provide explicit retry/rollback. DDL is not transactional;
   a combined database/files restore needs a recovery plan across both stores,
   rather than a promise of an atomic rollback.

For stronger isolation, an administrator could pre-provision a staging database
and a scoped mechanism to select the validated replacement. This adds storage,
privilege and connection-switch requirements. The simpler first implementation
can use an offline import into the destination database, provided its recovery
route and pre-restore backup remain accessible after import failure.

The present request lock is local to the application path and temporary
filesystem. It covers requests and CLI jobs loading that copy of `core.php`
during Git replacement; it is not a durable full-restore coordinator. A new
controller must coordinate all participating instances and independent writers.
The inherited `mantis_offline.php`/`mbadmin` mechanism is not sufficient for this.
Also account for the current rebuild helper calling `admin/install.php` over
HTTP: placing it under a new blanket lock without a controlled installer path
could block the rebuild itself.

### 4. Complete application-data backup set — substantial

Add one manifest tying together the SQL, Git/LFS and optional filesystem
archives, with component checksums, sizes, source commit, schema definitions,
extraction times, consistency method and explicit exclusions. Finalise and
verify each archive before reporting success; a started download is not proof
that the archive-producing process completed successfully.

Inventory actual attachment records and their storage backends, rather than
assuming every file lives below one upload folder. Include per-project paths,
issue/document attachments and any plugin-owned stores. For restores, map
logical components to destination-approved roots, update stored paths where
required, verify all referenced bytes, and refuse arbitrary archive paths.
Do not restore source absolute paths over destination deployment settings.

Add optional wiki backup/restore with its own quiescence requirements, and a
choice to restore published outputs or regenerate them through a supported
bulk publish job. Publication recovery must retain the destination's URL/path
configuration and record any missing rendering dependencies or external assets.
Generated outputs are only reproducible if the required inputs and tools exist.

For a consistent full backup, coordinate database/filesystem writers across
the capture interval, or integrate with an administrator-provided coordinated
snapshot service. Per-file hashes and database-reference checks detect many
problems but do not prove a single point-in-time snapshot. The current task
permits extraction only on live: do not enable maintenance, pause its jobs or
change its configuration to obtain such a snapshot without new authorisation.

### 5. Destination isolation and completion checks — medium

Provide a destination-owned restore/testing mode which copied database
configuration cannot overwrite. It should suppress both newly generated mail
and dispatch of already queued mail, pause meeting automation and outbound
integrations, and require review of copied publication targets. Separate data
stores do not automatically imply separate SMTP, API or external service
destinations. Preserve queued records for deliberate later handling.

Before leaving maintenance, produce a report covering actual schema, row
comparisons where applicable, Git refs/policies, LFS hashes, attachment hashes,
downloads, authentication and intended target URLs. Keep the report accessible
after the restored database replaces the original administrator session.

### 6. Retained-storage cleanup — small UI, possible deployment dependency

Offer a preview of the exact inactive retained paths, their sizes and the
restore job which created them. Allow cleanup when the web account already
has sufficient rights. Otherwise use a narrowly scoped installed helper,
authorised only for verified inactive restore artifacts below approved roots.
Reject symlinks, path traversal, active stores and mount points; audit results.
Do not add a general web shell, arbitrary recursive delete or blanket ownership
change. Administrator installation of such a helper may be needed once; routine
cleanup can then happen from Doctis. Cleanup is optional for service recovery,
but retained copies consume capacity and need a retention policy.

## Rehearsal plan

The aim is complete application-data backup and restore through Doctis on the
provisioned VM and remote dev installations, including recovery from an
interrupted or failed restore. No infrastructure disaster-recovery implementation
or rehearsal is required for this task.

Develop and test on the native VM, then on independent remote dev. Use
synthetic fixtures for disk attachments, wiki content where enabled, queued
mail and recurring series. Test interrupted uploads, browser/session expiry,
invalid SQL after partial DDL, database-login loss, checksum failures, storage
permission failures and insufficient space. Verify that notifications/integrations
remain paused, rollback restores matching database and files, and normal login
returns only after verification. Live remains extraction-only for this task.

Make protected, independent user-table replacement the first restore milestone,
with the readiness checks and recovery authorisation needed to support it.
Extend filesystem coverage once database failure recovery works. Track work in
[Git TODO](git/GIT_TODO.md#remote-backup-and-recovery).

## Source evidence

- [Database backup](../manage_db_backup_download.php),
  [SQL uploader](../manage_db_load_script_action.php) and
  [SQL execution](../core/db_script_api.php): snapshot options, upload/memory
  limits and single-call import behaviour.
- [Rebuild action](../manage_db_rebuild_action.php) and
  [rebuild helper](../admin/tools/doctis-drop-and-create-new-database.sh):
  delegated execution, precheck and HTTP schema installer dependency.
- [Git restore](../core/git_restore_api.php) and
  [request lock](../core/git_restore_lock_api.php): temporary state, root
  checks, validation, replacement, recovery and retained paths.
- [Configuration uploader](../manage_config_file_upload.php),
  [checks](../core/system_ops_api.php) and
  [write helper](../admin/tools/doctis-write-config.sh): checked upload and
  delegated config replacement, rather than an independent recovery service.
- [Issue attachment paths](../core/file_api.php),
  [document storage](../core/file_dwg_api.php) and
  [publication targets](../core/dwg_publish_api.php): components outside bare Git.
- [Mail worker](../scripts/send_emails.php),
  [meeting scheduler](../scripts/meeting_schedule.php) and
  [plugin scheduler](../scripts/cronjob.php): workers requiring coordination.
- [Rehearsal results](BACKUP_AND_RESTORE.md#rehearsal-result--2026-11-16):
  verified remote operations and the limits of that dataset. This assessment
  involved source inspection and existing evidence, with no new server changes
  or destructive failure tests.
