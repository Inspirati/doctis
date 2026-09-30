# Native nginx development installation

The `nginx` development branch adds a Doctis-only Debian profile. Apache remains
the default legacy profile. This profile omits DokuWiki, reference MantisBT,
phpMyAdmin, and GUI/editor installation. It uses the distribution's PHP-FPM and
MariaDB packages. Debian 13 currently provides PHP 8.4 and MariaDB 11.8; the
company Docker reference specifies PHP 8.2 and MariaDB 10.11. The owner accepted
this version difference for initial local development. Docker compatibility must
still be checked separately.

## Installation

Run as a normal account with sudo access. The bootstrap needs `wget`; the LAN
preflight checks also need `git` and `curl`. Snapshot the test VM first. Download
scripts into a fresh staging directory so cached scripts cannot select an older
installation path. After this branch has been published:

```bash
mkdir -p ~/doctis-nginx-install
cd ~/doctis-nginx-install
wget -O install.sh https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/nginx/admin/tools/install.sh
DOCTIS_BRANCH=nginx DOCTIS_WEB_SERVER=nginx bash install.sh
```

Older VM templates may contain `/home/robert/install.sh` with a hardcoded
`dev` dispatcher URL. Replace that file with the `nginx` branch bootstrap above,
or download the bootstrap into a fresh staging directory and run it there.
Setting `DOCTIS_BRANCH` alone does not change that older bootstrap's URL.

From an existing checkout, select an explicit host/IP:

```bash
DOCTIS_BRANCH=nginx DOCTIS_WEB_SERVER=nginx bash admin/tools/install-option.sh install all localhost
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
`install-lan.sh` from the `nginx` branch instead. Its default application
source is `ssh://robert@10.0.0.10/home/robert/html/doctis`, which requires SSH
Git access from the test VM. `/git/doctis` on vaio is the Doctis document Git
gateway and cannot serve as the application source repository. Check the LAN
source before running the installer:

```bash
git ls-remote --exit-code --heads ssh://robert@10.0.0.10/home/robert/html/doctis nginx
curl -fsS http://10.0.0.10/doctis/admin/tools/install-option.sh | grep -q 'DOCTIS_WEB_SERVER'
```

The vaio HTTP-served checkout must be on `nginx` for the second check to pass.
Fetching `nginx` while leaving that checkout on `dev` is insufficient. Use a
separate served worktree if the vaio development checkout must remain on `dev`.
Once both checks pass, run the LAN bootstrap in a fresh staging directory:

```bash
mkdir -p ~/doctis-nginx-lan-install
cd ~/doctis-nginx-lan-install
wget -O install-lan.sh http://10.0.0.10/doctis/admin/tools/install-lan.sh
DOCTIS_BRANCH=nginx DOCTIS_WEB_SERVER=nginx bash install-lan.sh
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

Only an empty database is initialized automatically. An existing database's
schema version is displayed, not automatically upgraded. Partially initialized
or incompatible schemas require investigation. Configuration/salt is not reset.
The standard initial administrator login is `administrator` / `root`. Sample
users are not automatically seeded by this profile.

The application installer is reachable over loopback only. Administrative
scripts, uploads, hidden files and internal directories are not served directly.
Downloads use the application's authorization checks. Existing Apache must be
stopped explicitly before running the nginx system installation.

## Validation status — 2026-09-27

Published branch: `nginx`. Runtime code tested: `86170d2fa`.
Local URL: `http://10.0.0.94/doctis/`.

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
Database backup/rebuild/config-edit admin pages need separate portability review;
the Git setup helper grants only its existing scoped application-pull command.

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

The working clone is `/home/robert/Documents/doctis`. Update the deployed
checkout deliberately with `git -C /var/www/html/doctis pull --ff-only`; rerunning
the installer intentionally does not pull or migrate an existing schema.

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
