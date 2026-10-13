# Local development handover

Status: 2026-10-13. This native Debian 13 VM (`10.0.0.94`) is the primary
development and test environment, not the company's Docker deployment. Its
nginx serves the running clone at `/var/www/html/doctis`. Read
[CLAUDE.md](CLAUDE.md) and [AGENTS.md](AGENTS.md) for project guidance and
[doc/TESTING.md](doc/TESTING.md) for test procedures. Some dated status in
AGENTS.md describes earlier VM snapshots; use the current checks below.

## Checkouts and current branches

- **Working clone:** `/home/robert/Documents/doctis` (this directory), owned by
  `robert`. Develop and commit here: on `dev`, the main development branch, or
  on a feature branch (currently `topics`, Documentation Topics, unpushed until
  the feature is complete). The former `nginx` and `ai-meeting` feature
  branches were merged into `dev` with their full history and are deprecated.
- **Running application:** `/var/www/html/doctis`, a separate Git clone owned
  by `robert` (group `www-data`), served at `http://10.0.0.94/doctis/`. Its
  `origin` is GitHub and it tracks `origin/dev`; on 2026-10-13 it was checked
  out on a local `topics` branch fetched from the working clone, to test
  unpushed work.
- **Other source:** `/home/robert/html/doctis` is the former development
  checkout on vaio (`10.0.0.10`), NFS-mounted here and last updated at
  `8939aea32`. vaio is no longer the development host. Do not alter that
  checkout as part of this VM's ordinary development cycle.

Do not edit application code in the running clone. Its
`config/config_inc.php` contains local settings and secrets and must stay out
of Git. The installer creates an installation; rerunning it does **not** pull
new code or update an existing database schema.

## Edit, publish, and test

1. In the working clone, check `git status`, edit, run relevant checks and
   commit. Push to `origin` only with the owner's approval. Do not push test
   data, credentials, or customer documents.
2. Run `git -C /var/www/html/doctis status --short --branch`, then update the
   running clone as `robert`. For pushed `dev`, use
   `git -C /var/www/html/doctis pull --ff-only` (the administrator **Git Pull
   (Update)** button does the same). For an unpushed branch, fetch from the
   working clone: in `/var/www/html/doctis`, run
   `git fetch /home/robert/Documents/doctis <branch> && git reset --hard FETCH_HEAD`.
   Confirm its HEAD is the intended commit. If `admin/schema.php` gained steps,
   run `php admin/upgrade_unattended.php` there.
3. Exercise the change through the running site and inspect nginx/PHP logs as
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
Logs are under `/var/log/doctis` and `/var/log/nginx`. The Docker reference
uses PHP 8.2/MariaDB 10.11; verify deployment-specific behavior separately.

**Outbound email is live.** The running `config_inc.php` sends through Gmail
SMTP (`smtp.gmail.com`, from `doctis.web@gmail.com`), and
`/etc/cron.d/doctis` runs `scripts/send_emails.php` every minute, so anything
queued is delivered within a minute (checked 2026-10-02). The sample LOTR
users have reserved `.example` addresses (undeliverable; bounces return to
the sender), but the sample role accounts (`user`, `manager`, …) have
`@gmail.com` addresses. Before tests that queue email, confirm the
recipients, or set `$g_enable_email_notification = OFF`.

**Recurring meetings run unattended** (now merged into `dev`). The same cron
file runs `scripts/meeting_schedule.php` hourly (minute 17). For each
repeating meeting series it creates the next meeting a few days ahead, which
makes an Anthropic API call (billed to the configured key) and emails the
invitees. Check `SELECT * FROM meeting_series WHERE active=1` before leaving a
test series repeating; stop one from its meeting page (Repeats → Does not
repeat).

This VM's data is **not empty**. It was rebuilt on 2026-10-13 (schema 77) and
reloaded with: 15 users; HCRQMS (project 1) and LiDAR (2), imported from ZIPs,
with their sub-projects Engineering (3) and LiDAR/Engineering (4); and the
Documentation Topics pilot HCR571 (5), which also holds a test document added
in the browser. That is 130 project documents in three document repositories,
and no meetings. A whole-instance reset deletes both
database and Git data. Back up and reload with
[admin/tools/data-scripts/README.md](admin/tools/data-scripts/README.md). The
wrapper `admin/tools/doctis-reset-native-test.sh --execute` also reloads sample
data; it is not the no-sample reset used for the HCRQMS ZIP rehearsal. See
[doc/git/HCRQMS_ZIP_UI_IMPORT.md](doc/git/HCRQMS_ZIP_UI_IMPORT.md) for that
rehearsal. Never use a development reset as a production rollback.

For a *new* VM installation, use the tracked `admin/tools/install.sh` from
`dev` with `DOCTIS_BRANCH=dev DOCTIS_WEB_SERVER=nginx`; see
[doc/NATIVE-DEVELOPMENT.md](doc/NATIVE-DEVELOPMENT.md) for the installation
profile. The older `/home/robert/install.sh` and
`/home/robert/get-doctis.sh` bootstrap copies may be stale. The latter is not
the tracked `admin/tools/install-lan.sh`.
