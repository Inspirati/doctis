# Native nginx development installation

**The native Debian 13 VM at `10.0.0.109` is the primary development and test
environment.** It was installed with this profile. Its nginx serves the
running clone at `/var/www/html/doctis` as `http://10.0.0.109/doctis/`, and
development happens in the working clone `/home/robert/Documents/doctis` on
the same VM. Day-to-day procedure: [DEV-SETUP.md](../DEV-SETUP.md) and the
Development Environment section of [CLAUDE.md](../CLAUDE.md). The former
development host, vaio (`10.0.0.10`, Apache with an NFS-mounted checkout), is
no longer used for development. It remains a LAN file server, and its old
checkout is still mounted on the VM at `/home/robert/html/doctis`; leave that
checkout alone.

The `dev` branch includes a Doctis-only native nginx profile, originally
developed on the now-deprecated `nginx` feature branch. Apache remains the
default legacy profile. This profile omits DokuWiki, reference MantisBT,
phpMyAdmin, and GUI/editor installation. It uses the distribution's PHP-FPM and
MariaDB packages. Debian 13 currently provides PHP 8.4 and MariaDB 11.8; the
company Docker reference specifies PHP 8.2 and MariaDB 10.11. The owner accepted
this version difference for initial local development. Docker compatibility must
still be checked separately.

## Installation

Run as a normal account with sudo access. The bootstrap needs `wget`; the LAN
preflight checks also need `git` and `curl`. Snapshot the test VM first. Download
scripts into a fresh staging directory so cached scripts cannot select an older
installation path. Use the published `dev` branch:

```bash
mkdir -p ~/doctis-dev-install
cd ~/doctis-dev-install
wget -O install.sh https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install.sh
DOCTIS_BRANCH=dev DOCTIS_WEB_SERVER=nginx bash install.sh
```

Older VM templates may contain `/home/robert/install.sh` with a hardcoded
`dev` dispatcher URL. Replace that file with the tracked `dev` bootstrap above,
or download the bootstrap into a fresh staging directory and run it there.
Setting `DOCTIS_BRANCH` alone does not change that older bootstrap's URL.

From an existing checkout, select an explicit host/IP:

```bash
DOCTIS_BRANCH=dev DOCTIS_WEB_SERVER=nginx bash admin/tools/install-option.sh install all localhost
```

`install system` installs packages; `install target` configures the application
using installed packages. The nginx path generates a database password and salt
instead of using the legacy positional credentials. The generated config is
readable by its owner and `www-data`; existing config is retained.

`DOCTIS_GIT_REPO` selects the application repository. `DOCTIS_SCRIPT_URL`
selects downloaded scripts. LAN installations require the LAN server to serve
the matching scripts and Git branch. An environment variable cannot switch the
server's working tree for it. The older `/home/robert/get-doctis.sh` fetches
cached root-level scripts and does not set these overrides; use the tracked
`install-lan.sh` from the `dev` branch instead. Its default application
source is `ssh://robert@10.0.0.10/home/robert/html/doctis`, which requires SSH
Git access from the test VM. `/git/doctis` on vaio is the Doctis document Git
gateway and cannot serve as the application source repository. Check the LAN
source before running the installer:

```bash
git ls-remote --exit-code --heads ssh://robert@10.0.0.10/home/robert/html/doctis dev
curl -fsS http://10.0.0.10/doctis/admin/tools/install-option.sh | grep -q 'DOCTIS_WEB_SERVER'
```

The vaio HTTP-served checkout must have the matching `dev` installer scripts.
Fetching `dev` over SSH does not update files served by the HTTP checkout.
Use a separate served worktree if that checkout must stay on another branch.
As of 2026-10-13 that checkout is still at `8939aea32` (2026-09-26): it serves
`install-lan.sh`, but its `install-option.sh` has no nginx support, so the
second check fails until the checkout is updated. The GitHub bootstrap above
does not depend on vaio. Once both checks pass, run the LAN bootstrap in a
fresh staging directory:

```bash
mkdir -p ~/doctis-dev-lan-install
cd ~/doctis-dev-lan-install
wget -O install-lan.sh http://10.0.0.10/doctis/admin/tools/install-lan.sh
DOCTIS_BRANCH=dev DOCTIS_WEB_SERVER=nginx bash install-lan.sh
```

## Layout and behavior

- Application checkout: `/var/www/html/doctis`, separate from the working clone
  and the vaio NFS mount. Repeated installation does not pull, reset, or delete it.
- nginx site: `/etc/nginx/sites-available/doctis`; PHP pool:
  `/etc/php/<version>/fpm/pool.d/doctis.conf`, socket `/run/php/doctis.sock`.
- Database: local TCP, database/account `doctis`; tables have no prefix/suffix.
- Issue attachments: disk uploads. Documents: Git repositories under
  `/var/git/doctis` with worktrees under `/var/www/doctis/worktrees`.
- Logs: `/var/log/doctis` and `/var/log/nginx/doctis_*`.
- Sessions: `/var/lib/doctis/sessions`.
- Cron flushes mail each minute as `www-data`. External notifications are
  disabled initially; configure test SMTP deliberately before testing delivery.
  On the current VM SMTP is configured (Gmail), so its email is live: see
  DEV-SETUP.md before running anything that queues mail.

Only an empty database is initialized automatically. An existing database's
schema version is displayed, not automatically upgraded. Partially initialized
or incompatible schemas require investigation. Configuration/salt is not reset.
The standard initial administrator login is `administrator` / `root`. Sample
users are not automatically seeded by this profile.

The application installer is reachable over loopback only. Administrative
scripts, uploads, hidden files and internal directories are not served directly.
Downloads use the application's authorization checks. Existing Apache must be
stopped explicitly before running the nginx system installation.

## Historical validation — 2026-09-27

The original validation used feature branch `nginx` at runtime commit
`86170d2fa`. The feature branch was merged into `dev`; on 2026-10-02 the
running native checkout switched to `dev` and its login page returned HTTP
200. A new clean installation from `dev` has not been separately tested.
Local URL: `http://10.0.0.109/doctis/`.

- GitHub bootstrap fetched the selected branch and installed dependencies; PHP
  platform requirements passed on PHP 8.4.26/MariaDB 11.8.6/nginx 1.26.3.
- Initial schema installation succeeded after retrying an nginx reload race.
  The installer now waits for its site health response before installation and
  retires the packaged default nginx site to prevent alternate-host exposure.
- A fresh GitHub bootstrap download completed the full rerun with exit status 0.
  Configuration checksum, schema version and user count were unchanged.
- Administrator login reached the authenticated dashboard without application
  errors. SOAP WSDL returned 200. Config, Git metadata, admin scripts, uploads,
  and REST internals returned 403; unauthenticated Git returned 401.
- Git mechanics: 15 checks passed. Application/Git mapping integration:
  59 passed, 0 failed; its synthetic fixtures were removed by the test.
- nginx/FPM configuration checks passed; nginx, FPM, MariaDB and cron are active.
  The scheduled email command completed as www-data; outbound mail is disabled.

Still to validate: snapshot-restored clean installation, reboot recovery, LAN
source installation, authenticated REST/SOAP workflows, Git HTTP push, captured
SMTP delivery, coordinated backup/restore, and the Docker reference comparison.
Database backup/rebuild/config-edit admin pages need the System Operations
account described below; the Git setup helper grants only its existing scoped
application-pull command.

### Administrative Git pull

The System Operations Git Pull feature was validated end to end on 2026-09-27.
An administrator invoked the web action while the deployed checkout was one
commit behind `origin/nginx`. The web process used the scoped sudoers rule to
run Git as `robert`, fast-forwarding the deployed checkout from `a108b09c7` to
`d8f69bf86`. The resulting commit exactly matched the remote branch. A second
web invocation returned “already up to date” with identical before/after SHAs.

The result page now distinguishes an actual update from a successful no-op,
shows the full commit before and after the pull, and records both SHAs in the
PHP error log. The confirmation page shows the tracked upstream branch.

This validates the feature for the native installation, where the deployed
application is a persistent Git checkout. It does not validate self-update as a
Docker deployment method. The supplied `.dockerignore` excludes `.git`, so the
image normally contains copied source rather than a checkout. Changes made in a
container writable layer also do not update the image and can disappear when
the container is replaced. Production Docker updates should therefore publish
and deploy a new image, unless IT has deliberately mounted a persistent Git
checkout and accepts the operational consequences.

### When Git pull is blocked by local changes

Git pull refuses an update that touches any tracked file differing from its
commit, and its error lists only the files that update touches. System
Operations → **Git Checkout Status** (`manage_git_checkout_page.php`) shows
every difference: permission-only changes, content changes, deleted and
untracked files, stashes, and unpushed commits. It can download a full report
and a `.tar.gz` of the application files without `.git`. It offers two repairs:

- **Ignore permission changes** sets `core.fileMode=false` and changes no files.
- **Restore files** saves content changes as `config/git_local_changes_*.patch`,
  then checks the committed versions out again.

A Docker image built with `chmod -R 775` on the application directory makes
every non-executable file show as a permission change. That blocked the remote
deployment at `e021cbe1a` in October 2026. Its `.dockerignore` excluded only the
top-level `.git`, so the image did contain the application's checkout.

### Switching the deployed branch

System Operations → **Git Branches** (`manage_git_branch_page.php`) lists the
local branches and those on `origin`, as last fetched. **Fetch from origin**
refreshes that list. For each branch the page shows:
- its tip;
- how it compares with `origin`, and how far it is ahead of or behind the
  running code;
- the schema version it expects;
- whether Doctis could switch back from it.

Each switch goes through a review step listing commits gained and lost, the
file change summary and any schema mismatch. The switch itself never discards
local changes. It uses the local branch if one exists; otherwise it creates one
tracking `origin`, so Git Pull follows the new branch from then on.

Safety rules:
- A branch that carries the branch switcher can be switched to freely.
- A branch that only has Git Pull is a one-way switch and needs an explicit
  acknowledgement.
- Anything else is refused, because a server without console access could
  not recover.

Fetch and switch run git as the System Operations account, like Git Pull, so
file ownership stays consistent. If sudo refuses, they retry as the web server
account. A native install's setup only grants the scoped `git pull` rule, and
its checkout is not writable by `www-data`, so switching there needs a wider
sudoers rule. The Docker image allows `/usr/bin/git` with any arguments.

The working clone is `/home/robert/Documents/doctis`. Update the deployed
checkout deliberately, **as `hcr`, never as `robert`** (see "System
Operations account" below). For a pushed branch, use System Operations → Git
Pull. To test an unpushed branch, use a bundle:

```bash
git -C ~/Documents/doctis bundle create /tmp/doctis.bundle <branch>
cd /tmp && sudo -u hcr -H git -C /var/www/html/doctis fetch /tmp/doctis.bundle <branch>
cd /tmp && sudo -u hcr -H git -C /var/www/html/doctis merge --ff-only FETCH_HEAD   # or reset --hard
sudo -u www-data php /var/www/html/doctis/admin/upgrade_unattended.php   # when admin/schema.php gained steps
```

Rerunning the installer intentionally does not pull or migrate an existing
schema.

### System Operations account: matching the live Docker image

On live, System Operations run as an **unprivileged** operator account,
`hcr`, through this rule (live's `sudo -n -l`, 2026-10-16):

```
Defaults>hcr always_set_home
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh, /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh, /var/www/html/doctis/admin/tools/doctis-write-config.sh, /var/www/html/doctis/admin/tools/doctis-backup-database.sh, /var/www/html/doctis/admin/tools/doctis-generate-stress-data.py, /usr/bin/git
```

Git with any arguments, and scripts in a checkout the web server can write,
let the web server run anything as that account. The account must therefore
have **no sudo rights**: never point the rule at `robert` or another
administrator. With only the scoped `git pull` rule, Rebuild Database,
Load Sample Data, Write Config and the branch switcher fail on a native
install ("sudo: a password is required").

To make a native install match (all done on the VM, 2026-10-16):
1. `hcr` has no sudo rights: removed from the `sudo` group and its
   `NOPASSWD: ALL` line removed from `/etc/sudoers`.
2. A MariaDB login for `hcr` by socket, for the scripts' `mysql` and
   `mysqldump` calls, with no password file:
   `CREATE USER 'hcr'@'localhost' IDENTIFIED VIA unix_socket;
   GRANT ALL PRIVILEGES ON doctis.* TO 'hcr'@'localhost';`
   (Live uses `~/.my.cnf`, because its database is in another container.)
3. `hcr`'s git configuration:
   `safe.directory=/var/www/html/doctis`, plus a user name and email.
4. The rule above in `/etc/sudoers.d/doctis-web`
   (`sudo visudo -f /etc/sudoers.d/doctis-web`).
5. A checkout and `config_inc.php` that `hcr` can write, as live's
   `chmod -R 775` gives:

   ```bash
   chmod -R g+w /var/www/html/doctis
   chmod 660 /var/www/html/doctis/config/config_inc.php
   cd /tmp && sudo -u hcr -H git -C /var/www/html/doctis config core.sharedRepository group
   ```

6. `$g_updater_run_as_user = 'hcr';` in `config_inc.php`.

**After step 5, never run git in `/var/www/html/doctis` as `robert`.** The
web server can then write its `.git`, and a planted hook or `core.fsmonitor`
setting would run as `robert`, who has root-equivalent sudo. Deploy as `hcr`
instead, from a directory `hcr` can enter. Use System Operations → Git Pull
for a pushed branch, or a bundle for an unpushed one:

```bash
git -C ~/Documents/doctis bundle create /tmp/doctis.bundle <branch>
cd /tmp && sudo -u hcr -H git -C /var/www/html/doctis fetch /tmp/doctis.bundle <branch>
cd /tmp && sudo -u hcr -H git -C /var/www/html/doctis reset --hard FETCH_HEAD
```

`admin/tools/doctis-drop-and-create-new-database.sh` calls `install.php` at
`127.0.0.1`, because the nginx profile allows only localhost. Inside Docker
it falls back to the container address. It checks that the installer answers
before dropping the database, and stops with the database untouched if not.
`--check` runs only that check. Run it inside the container before nginx there
is limited to localhost, as the VM's is.

Verified on the VM, 2026-10-16. The whole reload ran through the System
Operations pages exactly as on live: Git Pull and Rebuild Database as `hcr`,
then Reset Git Store, Load Data and Import. The imported tree was identical
to live's.

## Application settings

New configurations include `admin/tools/templates/native-app-settings.php`
(contents appended at creation). This matches production severity choices,
form fields, padding, news navigation, sender branding, reauthentication policy,
and example-project placeholder text. AI and application logging retain optional
environment-based configuration, with logs under `/var/log/doctis`. Supplying
these environment variables to FPM/cron requires explicit service configuration;
exporting them in an interactive shell is insufficient.

Existing configurations are preserved on installer reruns. The current local
instance received these settings explicitly on 2026-09-27, retaining its database
credentials, salt, URL, operator account, and disabled email/wiki/QMS settings.
