# HCRQMS import rehearsal and Git ownership decision

Status: proposed procedure, 2026-09-27. No real HCRQMS import has been run on
this VM. Resolve the ownership decision before anyone writes to both hosts.

## What the current importer does

`admin/tools/doctis-git-import.sh` runs `admin/import-git-repo.php` as
`www-data`. The importer reads the source repository at its committed `HEAD`,
creates a Doctis project and repository database row, and makes a **new bare
clone** under `/var/git/doctis/<project-slug>-r<repository-id>.git`. It also
creates a server worktree under `/var/www/doctis/worktrees/`. Matching files
become Doctis documents whose primary-file rows refer to paths and commits in
that new bare repository. Registration does not add commits to the source or
change its GitHub `origin`. There is no option to select an arbitrary existing
Doctis repository: `--name` selects the new project, and the importer assigns
its repository ID and path. `--update` reuses that project's existing repository.

The copied bare repository has its inherited remotes removed by
`repository_adopt()`. Neither the source clone nor GitHub is updated by the
importer. The database's `adopted_from` value records the **staging path**, so
record the original GitHub location and source commit separately in the test
report. The staged copy can be discarded after a one-time import has been
verified, but the current `--update` command still requires a readable
`--source` path for preflight even though it reads content from the adopted
bare repository. The staging clone's `origin` points to the original local
clone, while the original clone retains its GitHub `origin`.

Observed source: `/home/robert/Documents/HCRQMS` is a clean 4.1 MiB clone on
`dev` at `8b2d172fc119c0369b65c4a7e1c57f59faf22cfc`, with a GitHub
`origin`. There is no committed `.doctis` manifest. At this commit,
`content/` and `system/` contain 99 tracked `*.md` files. These facts are
the starting point for the rehearsal, not a promise that all 99 will pass
document validation. Earlier documentation's 33-document result was for an
older source state.

## Choose one ongoing authority

| Mode | Writing and updates | Suitability |
| --- | --- | --- |
| One-time local rehearsal | GitHub and the original clone remain as they are. Doctis receives an independent test copy. No subsequent synchronization is expected. | Recommended first step; it does not settle production ownership. |
| Doctis primary after cutover | Contributors clone/push to Doctis. Keep GitHub as an archive or publish selected branches/tags to it through a deliberate one-way mirror. Doctis UI file replacements also write to the Doctis repository. | Matches the importer's adoption model. Decide backup, access control, and the cutover point before production. |
| GitHub primary | Contributors write to GitHub. A controlled, fast-forward-only process must update the Doctis bare repository before `--update` registers new files. Doctis file editing must be governed so the histories do not diverge. | Requires a synchronization design; the current importer does not fetch from its `--source` on update. |
| Both writable | Changes can originate at GitHub and Doctis, with explicit reconciliation and conflict handling. | Defer until a two-way synchronization policy and tooling exist. Two independent writable repositories will diverge. |

GitHub is a hosting remote, not the owner of the local clone's objects. Keeping
both hosts is possible, but one must be the authority for the `dev` branch and
document edits. A second remote in a developer clone does not synchronize the
servers by itself. Do not mirror `refs/doctis/*` to GitHub without defining
how Doctis's approved-document pins should be handled.

## Stage a readable, private source copy

`www-data` cannot traverse `/home/robert` (mode `0700`), despite the HCRQMS
directory itself being readable. Keep the original clone in place. Put a
separate copy outside the web root and outside `/var/git/doctis`, for example
`/srv/doctis-import/HCRQMS`. That staging location is input; it is not the
Doctis-owned repository. The existing `/var/git/doctis` and worktree roots
already pass the `www-data` write check on this VM and should retain their
installer-managed permissions.

The following is a proposed one-time staging procedure. First confirm that
`/srv/doctis-import` does not contain an earlier rehearsal and record the
source SHA. `--no-hardlinks` prevents a later ownership change to staged Git
objects from also changing objects in the original clone.

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

The two SHAs must match. The last command must work without a Git
`safe.directory` exception; making the staged copy owned by `www-data`
provides that. The staging tree is private to `www-data` and root. Do not
relax `/home/robert` permissions merely to make the import work. An ACL on
the original path is possible, but it exposes that path to the web-service
identity and requires a Git ownership exception; it is less suitable for a
reproducible rehearsal. This stages the `dev` branch and its reachable
history; if other GitHub branches or tags matter, inspect and stage those
refs explicitly before adoption.

## Rehearsal sequence

1. Create a fresh VM snapshot or a coordinated backup of the database and
   `/var/git/doctis` before a real run. Record the staged source SHA, the
   Doctis application commit, project/repository/document counts, and the
   chosen project name. Use a unique test name such as `HCRQMS Import
   Rehearsal`.
2. Review and address the importer gaps below. Until then, restrict real
   runs to disposable VM state. Verify no existing project has the chosen
   name; `--update` is not a substitute for a first import.
3. Run the wrapper dry-run with explicit options; no `.doctis` manifest is
   present. Review all 99 candidates, statuses, categories, warnings, and
   whether the files selected are actually documents:

   ```bash
   bash /var/www/html/doctis/admin/tools/doctis-git-import.sh \
     --source /srv/doctis-import/HCRQMS --user administrator \
     --name 'HCRQMS Import Rehearsal' \
     --directories content,system --patterns '*.md' \
     --subprojects none --frontmatter yes --category-from directory \
     --dry-run
   ```

4. Confirm the dry-run changed no project, repository, document, or Git-store
   state. Run the same command without `--dry-run` only after the snapshot and
   failure-handling decision. Save the report and the new repository ID; do
   not guess the `-r<ID>` suffix.
5. Verify source and adopted `HEAD` match, the selected branch's history and
   required tags are present, and the
   adopted bare repo has no inherited remote. Compare successful registrations
   with database document and primary-file rows, including native `git_path`,
   status, metadata, category, and approved refs. Open representative
   documents, download their primary files and compare bytes with `git show
   HEAD:<path>`, then clone through the Doctis Git HTTP gateway. Confirm the
   original clone still has its GitHub `origin` and unchanged `HEAD`.
6. Exercise idempotency and a new file on the **adopted** repository. With
   current code, a new commit made only in the staged or GitHub source will
   not appear in `--update`. Push a synthetic new commit to the Doctis-hosted
   repository, then run `--update`; expect one new registration and no
   duplicates. Also test a removed path (reported, not deleted) and a
   deliberately failed registration on disposable state.
7. Restore the snapshot after the rehearsal if this VM must return to its
   previous test state. Do not use a blanket Git-store reset against the
   populated local installation. If a production cutover is later chosen,
   repeat against a separately approved dataset and backup/restore plan.

## Importer changes before relying on repeated real imports

- Make Phase A failure cleanup real: project/repository rows and a partial
  bare repo or worktree can currently remain if adoption fails. The design
  document claims Phase A is all-or-nothing.
- Make document creation and primary-file registration atomic or clean up a
  newly created document when registration fails. The current per-file catch
  reports a failure but can leave an unregistered document behind.
- Define `--update` as either "read the Doctis bare repo after a push" or
  "fetch from a checked source". Implement and test that contract. Its
  `--dry-run` currently does not apply registered-path skipping, so it can
  overstate would-be imports.
- Verify that an existing project and repository really belong to the
  intended import before accepting `--update`; check subproject parentage,
  importer identity, branch, and source commit. Fail clearly on an invalid
  `--user`, an unreadable source, or unsupported options.
- Review visibility before importing real business content: the current
  importer creates a public project and sets every imported document to
  `VS_PUBLIC`, regardless of frontmatter classification.
- Add an end-to-end test using small synthetic repositories for the flat and
  subproject layouts, including rerun and failure recovery. Existing tests
  cover the underlying Git primitives, while the earlier full importer
  evidence came from vaio and an older HCRQMS commit.
