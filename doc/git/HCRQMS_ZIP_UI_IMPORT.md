# HCRQMS ZIP import through the administrator UI

Status: 2026-09-30, native nginx test VM. This is a disposable development
installation, not the company production service. The earlier command-line
rehearsal is recorded in [HCRQMS_IMPORT_REHEARSAL.md](HCRQMS_IMPORT_REHEARSAL.md).

## Procedure

1. The owner authorized a full reset of this test installation. The Git reset
   removed its Doctis repositories and worktrees; the database reset recreated
   the schema. The sample-data loader was **not** run. Before import the schema
   had zero projects, one projectless document placeholder, no primary files,
   and only the administrator account.
2. Sign in as a global administrator and open **Manage > Import Data**.
   Select a ZIP snapshot from the local computer, enter a new project name,
   select the source's top-level directories and document filename patterns,
   and choose project visibility. For this rehearsal the values were
   `HCRQMS-dev.zip`, `HCRQMS`, `content,system`, `*.md`, and **Private**.
3. Doctis extracts the selected directories into a private temporary location,
   makes one new `dev` commit, creates a new Doctis-owned project/repository,
   and invokes the existing document importer. It then removes the temporary
   source. The GitHub repository and its history are not transferred or changed.
   An existing Doctis project name is rejected; this is a one-time creation
   workflow, not an update operation.

The upload limit is 64 MiB; the ZIP may contain up to 10,000 entries and
256 MiB of selected extracted data. The UI defaults to `content,system` and
`*.md` but these are editable. Other files within the selected directories
are kept in the Git repository even when they are not registered as documents.
The source's user identities are not imported; assign document responsibility
and project access in Doctis after import. A failed partial import requires
inspection and a deliberate reset/retry on this test VM. Do not use the global
reset scripts on a populated production installation.

## Observed result

The authenticated form submission completed in about three seconds and
reported **99 imported, zero skipped, zero failed, 67 metadata warnings**.
The warnings have the same pattern as the previous CLI rehearsal: 65 owner
values do not match Doctis users and two `Agenda` status values map to pending.

The resulting private project is ID 1, with repository ID 1 at
`/var/git/doctis/hcrqms-r1.git`. Its single import commit is
`cb2b1fd0f424e40277af5e7d04eaebe5fd507efe`; the stored source label is
`Uploaded ZIP: HCRQMS-dev.zip`. There are 99 registered primary files and
99 distinct document paths; each has a nonempty Git SHA and matching
`documents.reference`. There are no approved refs, assigned handlers, or
draft rows. The 100 `dwg` rows comprise those 99 documents plus the schema's
projectless placeholder. Only the administrator user exists, confirming that
sample data was not loaded.

The imported Git tree hash equals the tree produced by extracting only
`content/` and `system/` from the supplied ZIP:
`963c2a99ecf4bbcd060f596f499dc37c8f16116a` (150 files). All 99
registered paths match the selected Markdown paths. `git fsck` passed. An
authenticated document page loaded without an application error, and a
downloaded primary document was byte-for-byte identical to its ZIP entry.
The import page returned HTTP 403 without authentication.

This validates the native VM workflow for this ZIP and admin account. It does
not establish Docker/production parity, behavior with other archives, or a
safe per-project rollback after a partial production import. Long imports may
also need a longer nginx/FPM request timeout than this three-second run.
