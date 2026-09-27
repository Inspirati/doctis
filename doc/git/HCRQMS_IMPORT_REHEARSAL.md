# One-time GitHub-to-Doctis migration: HCRQMS rehearsal

Status: 2026-09-27. A private source copy has been staged and a dry-run has
completed on the native nginx VM. **No real HCRQMS import or reset has run.**
The owner does not require a VM clone or snapshot for this development test:
the Doctis database and Git store can be regenerated together if necessary.

## Migration contract

An existing GitHub-hosted repository is a one-time input. At a recorded,
frozen source commit, the importer creates a **new Doctis project and a new
Doctis-owned bare repository** under
`/var/git/doctis/<project-slug>-r<repository-id>.git`, plus a server worktree
under `/var/www/doctis/worktrees/`. It registers selected committed files as
Doctis documents pointing to their paths and Git SHAs. Doctis then becomes the
only writable home for that project's document history and subsequent edits.

The importer neither changes the source clone nor deletes or archives its
GitHub repository. The adopted bare repository has no inherited GitHub
remote. After verifying the migration, the team must separately make GitHub
read-only/archive it, switch contributors' clone URLs/remotes to Doctis, and
stop GitHub writes. Until then, GitHub is a retained source and recovery copy,
not a synchronized second host. There is no choice of an arbitrary existing
Doctis repository: `--name` selects the new project, and Doctis assigns its
repository ID. The existing `--update` option is **outside this migration's
scope** and is not a recovery strategy; on failure, reset and repeat from the
frozen source.

The local test may reset the entire Doctis database and Git store without a
VM snapshot. That is only appropriate while **all** data in both stores is
disposable. The current Git reset script deletes *every* repository and
worktree, and the database reset drops *every* project. If several repositories
have already been migrated into a production deployment, a global reset would
destroy successful migrations too. Either migrate into a still-empty
deployment that can be rebuilt in full, or implement and test rollback scoped
to the failed project/repository before using this process on a populated
production service.

## HCRQMS source and staged copy

`/home/robert/Documents/HCRQMS` is a clean clone on `dev` at
`8b2d172fc119c0369b65c4a7e1c57f59faf22cfc`. Its `origin` points to
GitHub. It has no committed `.doctis` manifest. At this commit, `content/`
and `system/` contain 99 tracked Markdown files. Only files selected at the
committed `HEAD` are registered; uncommitted work is not. The only other
tracked files under those roots are 51 `.gitkeep` placeholders, so the
`*.md` selection covers the current document set. The source's history
reachable from the staged refs is cloned, so inventory all required branches
and tags before production cutover. Also check for LFS and submodules; the
importer rejects an LFS filter and has no submodule migration procedure.
This local clone currently has only the `dev` local branch, no tags, no
submodule entries, and no tracked `.gitattributes` or `.gitmodules`; verify
GitHub's remote refs separately at cutover.
Five other Markdown files sit outside these roots: root `README.md`, `TODO.md`,
`CLAUDE.md`, and two `engine/Task-Instruction-*.md` files. They are not selected
by the current dry-run. Confirm whether any should be registered as Doctis
documents; the rest of `engine/` is build tooling and templates.

The source cannot be read by `www-data` in place because `/home/robert` is
mode `0700`. A separate copy now exists at `/srv/doctis-import/HCRQMS`.
`www-data` can read its verified `HEAD`; its SHA matches the original. This
staging copy is **input**, not the Doctis-owned repository and not a VM clone.
The source clone and its GitHub `origin` remain unchanged. The staging clone's
`origin` points to the original local clone; the Doctis bare repository will
have no remote. The database's `adopted_from` records the staging path, so
record the GitHub URL and source SHA separately in the migration report.

For a fresh staging path, use the following procedure. `--no-hardlinks`
prevents the ownership change from affecting the original clone's Git object
files. Verify the destination does not already exist before repeating it.

```bash
git -C /home/robert/Documents/HCRQMS status --short --branch
git -C /home/robert/Documents/HCRQMS rev-parse HEAD
sudo install -d -o robert -g robert -m 0700 /srv/doctis-import
git clone --no-hardlinks --branch dev \
  /home/robert/Documents/HCRQMS /srv/doctis-import/HCRQMS
git -C /srv/doctis-import/HCRQMS rev-parse HEAD
sudo chown -R www-data:www-data /srv/doctis-import/HCRQMS
sudo chmod -R go-rwx /srv/doctis-import/HCRQMS
sudo chown root:www-data /srv/doctis-import
sudo chmod 0750 /srv/doctis-import
sudo -n -u www-data git -C /srv/doctis-import/HCRQMS rev-parse --verify HEAD
```

Keep the original `/home/robert` permissions intact. The staging path must
remain available until the import has completed; afterward it can be removed
once the source SHA and migration report have been retained.

## Local test sequence

1. Record the source SHA, source GitHub URL, source refs, Doctis application
   commit, and existing project/repository counts. Confirm the intended new
   project name is unused. Choose the document set, visibility, and treatment
   of metadata warnings before a real run. HCRQMS uses role names in some
   frontmatter `owner` fields; these are not Doctis account names.
2. Run the current deployed importer dry-run. It uses the same PHP script as
   this checkout. No `.doctis` manifest exists, so pass selection explicitly:

   ```bash
   bash /var/www/html/doctis/admin/tools/doctis-git-import.sh \
     --source /srv/doctis-import/HCRQMS --user administrator \
     --name 'HCRQMS Import Rehearsal' \
     --directories content,system --patterns '*.md' \
     --subprojects none --frontmatter yes --category-from directory \
     --dry-run
   ```

   Observed result: **99 candidates, 99 would import, 0 failed, 67 warnings**.
   Of those warnings, 65 are unmatched `owner` values (16 distinct values,
   mostly role names) and two are `Agenda` status values in templates that
   currently fall back to pending. A database check confirmed the dry-run
   created zero projects and zero repository rows for the rehearsal name. Decide
   whether to map roles to real Doctis accounts, leave handlers unassigned,
   and map the two statuses; do not silently present those warnings as a
   complete metadata migration.
3. Fix or explicitly accept the one-time importer gaps below, then run the
   same command without `--dry-run` on the disposable local VM. Record its
   complete report, assigned project/repository IDs, and exit status. A
   nonzero exit or any unregistered candidate means the migration failed.
4. Verify the new bare repository's `HEAD`, required branches/tags, history,
   and absence of inherited remotes. Compare the 99 selected paths with the
   registered document and primary-file rows, including SHA, category,
   status, classification, and approved refs. Open representative documents,
   compare downloaded bytes with `git show HEAD:<path>`, and clone through
   Doctis Git HTTP. Confirm read/write access and visibility with accounts at
   the intended access levels. Verify the source clone's SHA and GitHub remote
   are unchanged.
5. If the local import fails, reset the **database and Git store together**,
   reinstall Doctis/sample data as needed, and repeat from the same frozen
   source SHA. Do not run only `doctis-git-reset.sh`: it leaves database rows
   referring to missing repositories. Before relying on the existing reset
   pair, check their credentials, installer URL, exit-status handling, and
   post-reset empty-store/schema checks on this native VM. The current
   `doctis-drop-and-create-new-database.sh` does not reliably stop on every
   failed command, so a success-looking message alone is insufficient.

## Production cutover and outstanding work

Plan production import before the new Doctis service accepts edits. For each
selected GitHub project: freeze writes; record the exact commit and required
refs; stage a private, readable source; preview and resolve warnings; import;
verify content, metadata, access, and Git HTTP; then announce the Doctis clone
URL and make the old GitHub repository read-only/archive it. Retain the old
repository through the agreed verification window. If a migration fails while
the new service is still disposable, rebuild the coordinated database and Git
store and repeat all imports. A populated service needs a separately tested
project-scoped rollback; deleting a project in the UI does not establish that
its repository row, bare repo, and worktree were removed.

Before calling the one-time path production-ready:

- Make imported project/document visibility explicit. Current code creates a
  public project and `VS_PUBLIC` documents regardless of frontmatter
  classification. Prefer private by default, with an intentional override and
  an access check in the rehearsal.
- Fail clearly on an invalid import user or unsupported options. Preflight
  source, target name, storage paths, refs, and document choices before
  creating rows; clean up Phase A and partially created documents on failure,
  or provide a tested project-scoped rollback. Current Phase A is not atomic,
  and a primary-file registration error can leave an unregistered document.
- Make the report identify the source SHA, repository ID, every failed path,
  and the decision on the 67 HCRQMS metadata warnings. Add an end-to-end test
  with a small synthetic repository that proves one-time import and failure
  recovery. Updating from GitHub, two-way sync, and automatic discovery of
  future pushed files are separate features, not migration requirements.
