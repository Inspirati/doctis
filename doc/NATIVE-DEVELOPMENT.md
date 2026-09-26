# Native nginx development installation

The `nginx` development branch adds a Doctis-only Debian profile. Apache remains
the default legacy profile. This profile omits DokuWiki, reference MantisBT,
phpMyAdmin, and GUI/editor installation. It uses the distribution's PHP-FPM and
MariaDB packages. Debian 13 currently provides PHP 8.4 and MariaDB 11.8; the
company Docker reference specifies PHP 8.2 and MariaDB 10.11. The owner accepted
this version difference for initial local development. Docker compatibility must
still be checked separately.

## Installation

Run as a normal account with sudo access. Snapshot the test VM first. Download
scripts into a fresh staging directory so cached scripts cannot select an older
installation path. After this branch has been published:

```bash
wget -O install.sh https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/nginx/admin/tools/install.sh
DOCTIS_BRANCH=nginx DOCTIS_WEB_SERVER=nginx bash install.sh
```

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
server's working tree for it.

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

## Validation status

The system phase completed on the local VM with PHP 8.4.26 and MariaDB 11.8.6.
nginx, PHP-FPM, MariaDB, and cron are active; default nginx and FPM configuration
checks pass. Shell syntax and whitespace checks cover the new scripts. Full application
installation, nginx routing, fresh schema initialization, rerun preservation,
and Git/API tests must pass before this profile is considered ready. Database
backup/rebuild/config-edit admin pages still need separate portability review;
the Git setup helper grants only its existing scoped application-pull command.
