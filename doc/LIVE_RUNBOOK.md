# Live version runbook

The live version is `https://qms.hcrobo.com/doctis`: a remote Docker
deployment of `dev` with **no console access**. Everything is done through
the browser or with curl, by an administrator.

This document holds the step-by-step procedures, which are needed rarely.
The standing rules — reading pages is fine, **ask the owner before any
change** — are in [../CLAUDE.md](../CLAUDE.md).

Claude's credentials for the `claude` administrator account are outside the
repository in `~/.config/doctis/live-claude.env`.

---

The **live version** is `https://qms.hcrobo.com/doctis`: a remote Docker
deployment of `dev`, the production path. Nobody has
console access. It is updated through System Operations (Git Pull, Rebuild
Database, Reset Git Store, Load Data). Reading pages is fine; ask the owner
before any change to it. It does not get sample data.

## First: does the update need a rebuild at all?

A rebuild is needed only when live's table definitions differ from what the
new code's `admin/schema.php` defines. Do not infer the answer from the
schema version: an in-place edit to an existing table does not bump it.
Compare instead:

1. On live: **Backup Database** (a full dump).
2. Load the dump into a disposable database on the VM, and compare
   `SHOW CREATE TABLE` for every table with a database freshly built from the
   new code, ignoring `AUTO_INCREMENT` values. Drop the disposable database
   afterwards.

If every definition matches, **Git Pull alone updates live**, and nothing
else is needed. If only a few columns differ, a schema update script through
**Load Data** (CLAUDE.md, *Database Schema Changes*) avoids a rebuild. Rebuild
when neither fits, or to clear tables `schema.php` does not define. On
2026-11-06 the only differences were two ad hoc backup tables, and the
rebuild below removed them.

## Rebuilding with a full data reload (no re-import)

Keeps every record, including documents, repositories and history, and keeps
the git store as it is. Done on 2026-11-06 after a VM rehearsal; the record
is in the project memory.

1. **Backups.** **Backup Database**, **Backup Git Store**.
2. **Git Pull.**
3. **Data Snapshot of every table `schema.php` defines**, REPLACE mode, taken
   **immediately before** the rebuild, so nothing done on live in between is
   lost.
   - Select every table, empty ones included. Do not hand-pick: on
     2026-11-06 a hand-picked set had omitted `project` and `config`, which
     would have orphaned every document and removed the publishing settings.
     Selecting all of them costs nothing, and catches rows that appear later.
   - Leave out only tables `schema.php` does not define (ad hoc backups).
     The rebuild does not recreate them; their contents survive in the full
     dump.
4. **Rebuild Database** (type `REBUILD`).
5. Log in as `administrator` / `root`.
6. **Load Data**: the snapshot, typing `LOAD`, with the transaction option
   ticked. The schema versions of the script and the database must match.
   - **Do not use Reset Git Store.** The restored `{repository}` and
     `{dwg_primary_file}` rows point at the repositories already on disk.
   - Loading `user` restores `administrator` as it was, normally disabled.
     Continue as a restored administrator.
7. **Verify.**
   - Take a second **Backup Database** and compare it with the first: the
     same table definitions, and the same content apart from your own
     sessions (`user` last visit, `tokens`).
   - **Git Store**: "Database and disk agree".
   - A document page offers **Publish**, which shows `config` came back.
   - The PHP Error Log shows no errors.

**Rehearsing on the VM** needs live's git store as well as its data. The
VM's repositories hold other commits, so restored records would point at
nothing. Back up the VM's database, store, worktrees and LFS objects. Then
replace the store with the contents of live's **Backup Git Store** archive
(`repositories/` and `lfs/`), and rebuild and load as above. The archive
holds no worktrees; `repository_ensure_on_disk()` recreates them, and Doctis
calls it before any write. Re-enable `administrator` on the VM afterwards for
the test suites.

## Updating the live version with a database rebuild and re-import

Everything below is done in the browser, or with curl as in
[Curl-Based Testing](#curl-based-testing), by an administrator. Pages that
change anything call `auth_reauthenticate()`, so log in afresh just before
each action. This was done on 2026-10-14 and the account-disabling step was
tested then.

**Before starting**, with the owner's approval and `dev` pushed:
1. Check Manage → System Operations → **Git Checkout Status** shows nothing
   blocking a pull.
2. Download backups.
   - **Backup Git Store** gives a `.tar.gz` of the store.
   - **Backup Database** (a full `mariadb-dump`), or a **Data Snapshot** of
     all tables. Backup Database works on live since 7cb107677 (2026-10-14),
     which connects without TLS (`--skip-ssl`) like the application. Earlier
     builds failed because MariaDB 11.4+ dump clients demand TLS.
   - **Data Snapshot** of the `user` **and `config`** tables, in REPLACE mode.
     `user` restores the accounts. `config` restores every **per-project
     setting**, which a rebuild also deletes: publication target and URL,
     per-project thresholds, anything set on a Manage Project tab rather than
     in `config_inc.php`. Omitting `config` silently removes features from
     the restored site — the Publish button disappears because
     `dwg_publish_site()` finds no target, and nothing reports an error.
     (Learned the hard way on 2026-10-27; live held three `dwg_publish_*`
     rows and they had to be recovered from the full dump afterwards.)
3. Note any projects other than the imported one. A rebuild deletes them, and
   they are not restored unless you load them back from the snapshot.

**Steps:**
1. **Git Pull.**
2. **Rebuild Database** (type `REBUILD`). This drops and reinstalls the
   database at the new schema and ends every session. Only the seeded
   `administrator` / `root` account exists afterwards.
3. Log in as `administrator` / `root`.
4. **Reset Git Store** (type `RESET`). The import would otherwise fail: its
   new repository reuses the old `<slug>-r<id>` name. The page refuses while
   any `{repository}` row exists, so it only works straight after a rebuild.
5. **Load Data**: upload the `user` snapshot, then the `config` snapshot,
   typing `LOAD` and keeping the transaction ticked for each. This restores every account, including the owner's
   administrators and `claude`; Claude's credentials are kept outside the
   repository (see project memory).
   - The schema-version warning is expected when the snapshot is older; the
     columns must still match.
   - If `administrator` was disabled when the snapshot was taken, this step
     disables it again and ends that session. Continue as another
     administrator.
6. Log in with a restored administrator account.
7. **Import Data**: project name `HCRQMS`, the repository ZIP, directories
   `content,system`, patterns `*.md`, visibility **Public**.
   - Expect "N imported, 0 skipped, 0 failed".
   - Warnings that owners such as "QMS Lead" match no account are normal.
   - The front proxy must accept uploads larger than the ZIP.
8. **Verify:**
   - Overview: the database schema version equals the code's.
   - Manage → **Git Store**: one repository, "Database and disk agree", and
     every document "On Record = HEAD".
   - A document's view page still offers **Publish** — proof the per-project
     settings came back. The schema version is *not* a useful check here: a
     change to an existing table does not bump it.
   - Manage → Users lists the restored accounts.
9. **Disable `administrator`**, but only once a restored administrator
   account has been confirmed to log in.
   - Manage → Users → `administrator`: untick **Enabled**, untick **Send
     email notification**, then **Update User**.
   - Confirm that logging in as `administrator` / `root` is refused.
   - Doctis will not disable the last enabled administrator.
   - Every rebuild re-creates the account enabled, so repeat this step after
     each one.
