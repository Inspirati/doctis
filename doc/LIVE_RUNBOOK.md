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

## Updating the live version with a database rebuild

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
