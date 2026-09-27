# One-time HCRQMS document import into Doctis

Status: 2026-09-27. The initial import was reset and repeated after changing
the Reference policy. The current native nginx test installation has private
project `HCRQMS` (project ID **2**) and Doctis repository
`/var/git/doctis/hcrqms-r1.git`. All **99** selected Markdown documents were
registered with `documents.reference` equal to the import Git SHA. Approval
pins were deliberately deferred. The original GitHub repository and local
source clone remain unchanged. This local import does not itself archive
GitHub or constitute a production cutover.

## Scope and repository ownership

This is a one-way migration. Doctis takes ownership of a new repository and
all future document edits; GitHub is only the source for this one-time load.
The importer does not delete or modify GitHub. After a **production** import
has been validated, the team must make the old GitHub repository read-only or
archive it and switch contributors' clone URLs/remotes to Doctis. The existing
`--update` feature and two-way synchronization are outside this migration's
scope. The imported project and repository are new; the importer cannot
select an arbitrary existing Doctis repository as its destination.

Only `content/` and `system/` from source commit
`8b2d172fc119c0369b65c4a7e1c57f59faf22cfc` were selected. These roots
contain 99 tracked `*.md` files and 51 `.gitkeep` placeholders. The five
Markdown files at the repository root and under `engine/`, plus all build
tooling, were excluded. **Prior GitHub commit history is not required**: the
staged source is a fresh repository with a single import commit, and that is
what Doctis adopted. The original source's `content/` and `system/` Git tree
hashes exactly match the staged and adopted trees; the document bytes were
preserved. Git creation dates and authors now reflect the snapshot commit
where frontmatter does not provide equivalent metadata. Record the original
source SHA separately for provenance.

Frontmatter `owner` values were **not** converted into Doctis user accounts.
All 99 imported `handler_id` values are zero. Assign responsibility and
project access inside Doctis after import, as requested. The importer did
retain the textual frontmatter author/owner information where available.

## Staging and command used

`www-data` cannot traverse `/home/robert` (mode `0700`), so the importer reads
a private copy under `/srv/doctis-import/`. The import used
`/srv/doctis-import/HCRQMS-snapshot`, owned by `www-data`, under a
`root:www-data` parent of mode `0750`. This staging repository has branch
`dev`, one commit (`8f0fbb6384f98f706c03eb9d0d8761fca2a3fbc7`), and
only the two selected directories. It is an input copy, not the Doctis-owned
bare repository. The earlier full staging clone at
`/srv/doctis-import/HCRQMS` was not used for this import.

To rebuild the same snapshot from a clean original clone, create a private
temporary directory, archive only the desired paths, and commit them once.
Use a new staging destination if one already exists; do not change the
original source checkout or relax `/home/robert` permissions.

```bash
git -C /home/robert/Documents/HCRQMS status --short --branch
git -C /home/robert/Documents/HCRQMS rev-parse HEAD
mkdir -m 0700 /tmp/doctis-hcrqms-one-time-source
bash -o pipefail -c \
  'git -C /home/robert/Documents/HCRQMS archive HEAD content system |
   tar -x -C /tmp/doctis-hcrqms-one-time-source'
git -C /tmp/doctis-hcrqms-one-time-source init -b dev
git -C /tmp/doctis-hcrqms-one-time-source add -- content system
git -C /tmp/doctis-hcrqms-one-time-source \
  -c user.name='Doctis Import' -c user.email='doctis-import@localhost' \
  commit -m 'Import HCRQMS content and system documents'
sudo install -d -o root -g www-data -m 0750 /srv/doctis-import
sudo mv /tmp/doctis-hcrqms-one-time-source /srv/doctis-import/HCRQMS-snapshot
sudo chown -R www-data:www-data /srv/doctis-import/HCRQMS-snapshot
sudo chmod -R go-rwx /srv/doctis-import/HCRQMS-snapshot
sudo -u www-data git -C /srv/doctis-import/HCRQMS-snapshot rev-parse HEAD
```

The dry-run and real run used the same command, removing `--dry-run` for the
real run. The importer now defaults new projects to private; the explicit
option records that choice for this migration. Document rows remain
`VS_PUBLIC` *within the private project*, so authorized project members can
read them without individually private-document access.

```bash
sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
  --source /srv/doctis-import/HCRQMS-snapshot \
  --user administrator --name HCRQMS \
  --directories content,system --patterns '*.md' \
  --subprojects none --frontmatter yes --category-from directory \
  --project-visibility private
```

The dry-run reported 99 candidates, zero failures, and 67 metadata warnings.
The real run reported **99 imported, 0 skipped, 0 failed, 67 warnings**. Of
the warnings, 65 are unmatched frontmatter `owner` values (mostly role names)
and two are `Agenda` status values in templates, which fell back to pending.
These are metadata decisions for evaluation in Doctis, not missing files.

## Verification performed

- Project `HCRQMS` has view state `50` (private), and its repository row
  records branch `dev` and the staging source path. Doctis created 99
  documents (IDs 2–100) with 99 title records and 99 distinct primary-file
  paths. Every primary record points to the single import commit. The DB path
  set exactly matches the 99 source Markdown paths: no missing or extra path.
- The adopted bare repository has exactly one commit, no inherited remote,
  matching `content/` and `system/` tree hashes, and passes `git fsck`. The
  server worktree exists and is clean. There are **no** approved-document
  refs. Document states remain 95 pending and four accepted from source
  frontmatter; these statuses did not create an approval pin. All 99 handlers
  are unassigned for later work.
- nginx, PHP-FPM, and MariaDB remain active. The login page responds HTTP
  200. An unauthenticated document URL redirects to login (302); an
  unauthenticated Git HTTP read receives 401. An authenticated UI session and
  document download still need human review.

The administrator can start with
`http://10.0.0.94/doctis/dwg_view_page.php?bug_id=2` after logging in, then
select the `HCRQMS` project to inspect the rest. The document title is in the
`documents` record. As in the existing document creation path, `dwg.summary`
is blank even though titles are populated; check whether any list or search
view needs that field before production migration.

### Reference field observation

The imported `Reference` is the registered Git SHA, not the human-readable
frontmatter `doc_id` (which maps to document `number`). All 99 references are
nonempty and match the corresponding `dwg_primary_file.git_sha`, including
Draft/Pending documents and `TODO.md` placeholders. Each SHA is the single
snapshot commit `8f0fbb6384f98f706c03eb9d0d8761fca2a3fbc7`. No import
registration created an approved Git ref. Approval remains a separate Doctis
action. The four `accepted` document statuses were copied from source
frontmatter and should be reviewed in the UI; status mapping was not changed
as part of the Reference fix.

## Repeat testing and production cutover

The current repeat import is left in place for UI evaluation. **Do not re-run
the importer against project `HCRQMS`**: this one-time path rejects an existing
project. For another trial, `admin/tools/doctis-reset-native-test.sh` can
reset the whole disposable native instance with `--preview` then `--execute`.
It coordinates the existing Git and database reset scripts, reloads sample
data, then checks that no project documents, primary registrations, or
repositories remain and that the login page responds. The schema installer
creates one projectless placeholder document; the reset correctly leaves it
in place. The wrapper's first `--execute` exposed an incorrect zero-document
assertion after the database and Git stores had already been reset; this was
fixed, and a second `--execute` passed before the 99-file repeat import.
Never run only one of the underlying reset scripts, and never use this global
reset on an installation with data to retain.

For production, freeze each selected GitHub source at a recorded commit,
create a private snapshot of its chosen document paths, dry-run, import into
a new project, verify file counts/content and permissions, then switch users
to Doctis. Make GitHub read-only/archive it only after verification. A global
database-and-Git reset is suitable only while the entire target deployment
is disposable. If other projects are already live, recovery must be scoped
to the failed project/repository or use a separately planned full rebuild.
