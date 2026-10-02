# Local development handover

Status: 2026-10-02. This is the native Debian 13 development VM, not the
company's Docker deployment. Read [AGENTS.md](AGENTS.md) for project guidance
and [doc/TESTING.md](doc/TESTING.md) for test procedures. Some dated status
in AGENTS.md describes earlier VM snapshots; use the current checks below.

## Checkouts and current branches

- **Working clone:** `/home/robert/Documents/doctis` (this directory), owned by
  `robert`. Develop and commit here. Its `dev` branch is the main development
  branch and matches `origin/dev` at merge commit `49456edb5`. The former
  `nginx` development branch was merged into `dev` with its full history.
- **Running application:** `/var/www/html/doctis`, a separate Git clone owned
  by `robert`, served at `http://10.0.0.94/doctis/`. It is still clean on
  `nginx` at `b0578d131` and tracks only `origin/nginx`. The merge commit on
  `dev` has the same application tree, but future `dev` commits will not appear
  here until this clone changes branches.
- **Other source:** `/home/robert/html/doctis` is the vaio NFS checkout. Do not
  alter it as part of this VM's ordinary development cycle.

Do not edit application code in the running clone. Its
`config/config_inc.php` contains local settings and secrets and must stay out
of Git. The installer creates an installation; rerunning it does **not** pull
new code or update an existing database schema.

## Edit, publish, and test

1. In the working clone, check `git status`, edit on `dev`, run relevant checks,
   commit, and push to `origin/dev`. Do not push test data, credentials, or
   customer documents.
2. **One-time transition for this VM:** after confirming the running clone is
   clean, add `dev` to its single-branch fetch configuration and switch it to
   track `origin/dev`:

   ```bash
   git -C /var/www/html/doctis status --short --branch
   git -C /var/www/html/doctis remote set-branches --add origin dev
   git -C /var/www/html/doctis fetch origin
   git -C /var/www/html/doctis switch --track origin/dev
   ```

   The running clone has **not** yet been switched as of this document's date.
   Run Git there as `robert`, its owner; perform the transition deliberately
   before expecting deployments from `dev`.
3. For subsequent commits, update the running clone with
   `git -C /var/www/html/doctis pull --ff-only` and confirm its HEAD matches
   the intended `origin/dev` commit. The administrator **Git Pull (Update)**
   button invokes a scoped pull of the running clone's tracked branch; until
   the transition above, that branch is `nginx`.
4. Exercise the change through the running site and inspect nginx/PHP logs as
   needed. Use `php -l` for changed PHP files and `bash -n` for changed shell
   scripts; choose deeper tests from `doc/TESTING.md`. Some Git and integration
   tests alter the development database or repositories, so inspect their scope
   before running them.

## Native runtime and state

nginx 1.26.3 serves PHP through the PHP 8.4 FPM pool; MariaDB is 11.8.6.
The active services are `nginx`, `php8.4-fpm`, `mariadb`, and `cron`. The nginx
site is `/etc/nginx/sites-available/doctis`, and the FPM socket is
`/run/php/doctis.sock`. Doctis uses the local `doctis` database, disk issue
uploads, bare document repositories under `/var/git/doctis`, worktrees under
`/var/www/doctis/worktrees`, and sessions under `/var/lib/doctis/sessions`.
Logs are under `/var/log/doctis` and `/var/log/nginx`. Test SMTP delivery is
not configured. The Docker reference uses PHP 8.2/MariaDB 10.11; verify
deployment-specific behavior separately.

This VM's data is **not empty**: on 2026-10-02 it had four projects, 126
project documents, two document repositories, and one user. A whole-instance
reset deletes both database and Git data. The wrapper
`admin/tools/doctis-reset-native-test.sh --execute` also reloads sample data;
it is not the no-sample reset used for the HCRQMS ZIP rehearsal. See
[doc/git/HCRQMS_ZIP_UI_IMPORT.md](doc/git/HCRQMS_ZIP_UI_IMPORT.md) for that
rehearsal. Never use a development reset as a production rollback.

For a *new* VM installation, use the tracked `admin/tools/install.sh` from
`dev` with `DOCTIS_BRANCH=dev DOCTIS_WEB_SERVER=nginx`; see
[doc/NATIVE-DEVELOPMENT.md](doc/NATIVE-DEVELOPMENT.md) for the installation
profile; its `nginx` branch examples predate the merge into `dev`. The older
`/home/robert/install.sh` and `/home/robert/get-doctis.sh`
bootstrap copies may be stale. The latter is not the tracked
`admin/tools/install-lan.sh`.
