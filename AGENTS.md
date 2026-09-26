# Doctis project guidance

Initial assessment: 2026-09-26. Based on local source inspection at commit
`e021cbe1a` on branch `dev`, plus the expanded deployment tree in `../1.0.9/`
and the external bootstrap scripts in `../../`. Updated after inspection of
the newly supplied configuration and application source.
No installer, container build, database operation, or runtime test was executed
for this assessment. The supplied files describe a deployment; they do not
establish the company's actual running configuration.

## Implementation status — 2026-09-27

The owner has created a VM snapshot and authorized proceeding with installation
work on this local Debian 13.2 VM. The local and NFS development checkouts were
synchronized at `8939aea32` on `dev`; implementation now uses branch `nginx`
in this checkout. Preserve `/home/robert/html/doctis` on vaio unchanged.

The `robert` account already has `NOPASSWD: ALL` sudo access. Codex sandbox
approvals remain separate and are required for system changes and Git metadata.
No sudoers changes are needed. The nginx system phase has installed server
packages successfully; application installation and the GitHub test cycle are
pending the commit identity needed to publish the new branch.
Available package candidates are PHP 8.4, MariaDB 11.8, and nginx 1.26.3;
these differ from the Docker PHP 8.2/MariaDB 10.11 baseline. The owner accepted these Debian package versions for local testing; retain
Docker PHP 8.2/MariaDB 10.11 as a separate compatibility baseline.

Use `DOCTIS_BRANCH=nginx` for the GitHub installer test cycle; ordinary installs
continue to default to `dev`. Pass this selection through bootstrap, dispatcher,
and application checkout. Validate clean installation after iterative local work
using the snapshot or a fresh VM.

## Owner's requirement

The project owner develops Doctis and normally tests installation on a clean
Debian-based Linux system, using either a GitHub bootstrap or a LAN repository
bootstrap. The first company production deployment uses the IT team's Docker
service and nginx instead of the developer's Apache environment.

The immediate requirement is a reproducible local development and test
environment on a clean Debian Linux VM, **without Docker in the daily
development workflow**, that closely matches the company application runtime.
The owner already has a separate Docker VM built using IT's instructions; use
that as a reference and for deployment-specific comparisons. Adapt the existing
setup scripts to install nginx, PHP-FPM, MariaDB, and Doctis natively. Retain the
existing Apache path as an explicit option while introducing this new profile.
The initial assessment is complete; native installer implementation is now authorized.

Stage one excludes DokuWiki and the separately installed reference MantisBT
instance. Doctis still uses its inherited MantisBT libraries and schema installer;
those remain required. A new Docker development harness is not the objective.

A native VM can closely reproduce application behavior when versions,
configuration, routing, permissions, and scheduled tasks match. Docker supplies
an isolated filesystem/process/network environment and a packaged runtime; it
does not require a different application architecture. Native system services
can provide nginx, PHP-FPM, and MariaDB directly. Container startup, networking,
mounts, and image upgrades remain distinct and need checks in the reference VM.

## Evidence and current installation paths

- Doctis is a PHP/MariaDB application derived from MantisBT, with document and
  issue workflows, Git-backed document storage, REST/SOAP APIs, and wiki integration.
- The actual external bootstrap paths are `../../install.sh` and
  `../../get-doctis.sh` (both found under `/home/robert/`). The former is
  byte-for-byte identical to `admin/tools/install.sh`.
- `../../get-doctis.sh` is an older LAN wrapper: it fetches `install-option.sh`,
  `install-system.sh`, and `install-target.sh` from the root of `10.0.0.10`, only
  when each local file is absent, then invokes the dispatcher. It does not set
  `DOCTIS_SCRIPT_URL` or `DOCTIS_GIT_REPO`; source selection downstream depends
  on the downloaded script versions. Do not assume it is equivalent to tracked
  `install-lan.sh`. It embeds credentials; do not copy those values into Git.
- `admin/tools/install.sh` downloads `install-option.sh`, which dispatches to
  `install-system.sh` and `install-target.sh`. The system script installs Apache,
  mod_php, MariaDB, PHP extensions, Composer, and development tooling including
  Xdebug. PHP and database versions depend on the target distribution's packages.
- The target script clones application code, installs Composer dependencies,
  writes configuration, invokes `admin/install.php`, loads example data, and
  invokes Git storage setup. It also includes MantisBT and companion web tooling
  installation paths; it is broader than a container application bootstrap.
- The owner confirms **all development and deployment uses `dev`**. “Master
  repository” means the authoritative GitHub repository, not branch `master`.
  Bootstrap/dispatcher downloads and `install_doctis()` agree with this intent.
  Keep `dev` as the normal branch and record exact commits for reproducibility;
  use the explicit `nginx` override during this implementation cycle.
- `install-lan.sh` sets `DOCTIS_SCRIPT_URL` and `DOCTIS_GIT_REPO` to the LAN
  server at `10.0.0.10`. It changes the source of scripts/code, not the basic
  Apache installation architecture.
- `composer.json` permits PHP `^7.4 || ^8.0` and resolves dependencies using a
  configured platform PHP version of `8.1.0`. This is not evidence of the running
  PHP version. Preserve and use `composer.lock` when comparing environments.

Source references: `admin/tools/README.md`, the installation scripts above,
`composer.json`, `composer.lock`, `CLAUDE.md`, and `doc/TESTING.md`.

## Supplied Docker deployment

`../1.0.9/` now contains `Dockerfile`, `docker-compose.yml`, both Docker guides,
`.dockerignore`, `.env`, `docker/`, `doctis/`, `site/`, and a partial `data/` tree.
This is external reference material copied from the owner's Docker VM. The
previous assessment's missing-build-input conclusion has been superseded:
all explicit local Dockerfile COPY sources and the nginx bind-mount file are
now present. No build has been attempted.

The copied `doctis/` Git checkout is clean on `dev` at `e021cbe1a`, matching this
checkout's assessed commit; `composer.lock` and `doctis-git-setup.sh` also match.
This establishes the copied source baseline, not which revision is inside the
running image or whether production has additional changes.

Some protected `data/` paths were intentionally not copied. Raw database files,
sessions, and private runtime state are not needed for initial installer design.
Keep their permissions intact. The copied `.env` contains local configuration;
inspect only relevant sanitized settings and never reproduce secrets in project
documentation. Use synthetic fixtures for validation.

| Aspect | Evidence in supplied build/Compose files |
| --- | --- |
| Application | Image named `doctis-1.0.9`; PHP `8.2-fpm` base with nginx installed in the same container |
| Database | Separate `mariadb:10.11` service, addressed as `db`, with a readiness healthcheck |
| Admin UI | `phpmyadmin:5` service; described as optional, but no optional Compose profile is defined |
| Ports | App defaults to host port 8080; phpMyAdmin defaults to 8081; database has no published host port |
| Source | `COPY doctis/ .` into `/var/www/html/doctis`; no application source bind mount |
| Dependencies | `composer install --no-dev --optimize-autoloader` during build |
| Companion sites | Copies `site/`; clones DokuWiki's moving `stable` branch |
| Configuration | Environment variables plus a PHP template; nginx site config mounted from `./docker/nginx.conf` |
| Runtime helpers | Git, Graphviz, MariaDB client, sudo, cron, Python; an `hcr` operator account and supplied sudoers rules |
| Persistence | Bind mounts under `./data/` for database, uploads, app config, Git repositories, worktrees, wiki data/config, logs, and PHP sessions |

The Dockerfile configures 64M PHP upload/post limits, a 256M PHP memory limit,
OPcache, persistent sessions, and PHP error logging. Effective request limits
also depend on application and proxy configuration. The supplied nginx site
sets `client_max_body_size 64M`, while the application template sets
`$g_max_file_size` to 2 MiB; these are distinct limits.

### Newly verified configuration and remaining unknowns

Static inspection now covers `docker/nginx.conf`, `docker/config_inc.php`,
`docker/entrypoint.sh`, `docker/doctis-cron-exec.sh`, `docker/doctis.cron`,
`docker/doctis-web.sudoers`, `.dockerignore`, and the application checkout.

- nginx serves `/doctis/`, redirects `/` there, forwards PHP to
  `127.0.0.1:9000`, rewrites REST requests, and maps `/git/` to `git_http.php`
  with `PATH_INFO`. It includes internal-directory restrictions and CAPTCHA
  exceptions, as well as optional wiki and static-site routes.
- The PHP template reads environment variables, selects disk issue attachments
  and Git document storage by default, uses empty database table prefix/suffix,
  and configures `hcr` as the operator. It enables wiki integration and links
  QMS navigation to `../site/`; disable wiki for stage one and omit the QMS link
  if the static site is not installed.
- The entrypoint copies the application template only if configuration is absent,
  initializes permissions, invokes Git setup, writes the operator's database
  client/Git configuration, and exports selected variables to a protected cron
  environment file. Existing persisted configuration can differ from the template.
- Cron runs `scripts/send_emails.php` every minute as `www-data` through the
  environment wrapper. The wrapper exports the sourced values before execution.
- The entrypoint waits for MariaDB, starts PHP-FPM and nginx, invokes the schema
  installer **via HTTP**, and seeds sample data when its schema probe fails.
  Bash waits on nginx and installs signal traps; nginx is not PID 1 as claimed
  in `DOCKER.md`. These are code findings, not verified runtime outcomes.
- If `CRYPTO_SALT` is empty, the entrypoint generates a fresh value each start
  without persisting it. The copied `.env` supplies a nonempty salt, avoiding
  that fallback if this environment is used. Native setup should generate once
  and persist it explicitly.

Still missing: `.env.example` and `DOCKER-DEVELOPER-INTENT.md`. Neither blocks
native installer design. Still unverified: running image revision/digests,
effective PHP-FPM configuration and environment, actual package versions,
persisted application configuration, database state, outer proxy/TLS settings,
and differences between the reference VM and company production. Obtain these
through a targeted runtime inventory rather than copying protected raw data.
Base image tags, package versions, and DokuWiki remain mutable build inputs.

### Compatibility issues requiring attention

1. **Git setup assumes Apache.** The current `admin/tools/doctis-git-setup.sh`
   invokes `a2enmod`, `a2enconf`, `apache2ctl`, and `systemctl reload apache2`
   when its bundled `git-serve.conf` exists. It can therefore fail in this nginx
   image. The copied script matches this checkout, and the entrypoint invokes
   it with failure downgraded to a warning. This confirms the mismatch is not
   resolved by a separate supplied Git setup implementation. Separate shared
   storage setup from server-specific routing in subsequent work.
2. **Routing and access rules need verification.** Inspect all repository
   `.htaccess` files and translate required behavior into nginx rules. Cover
   REST rewrites, restricted directories, PHP execution, and `/git/` gateway
   routing with `PATH_INFO` and request authorization passed to PHP-FPM.
   The actual nginx file uses `fastcgi_pass_header Authorization`; this alone
   does not establish request-header forwarding. Inspect effective FastCGI
   parameters and validate authenticated requests. Review location precedence
   and the CAPTCHA PHP exception, whose regex is broader than its comment.
3. **Host tooling differs.** Existing maintenance paths assume local database
   access, Apache paths, OS accounts, and sudo. Container database access uses
   `db`; inspect backup, restore, configuration editing, and Git update tools
   before treating them as portable. The `.dockerignore` exclusion of `.git` also
   conflicts with an admin UI workflow that expects an application `git pull`.
4. **Development tools differ.** The production build omits Composer development
   dependencies and does not install Xdebug. Add these only in a development
   variant. Check test-specific extensions such as SOAP instead of assuming the
   production extension list supports every test suite.
5. **Documentation has drift.** The guides advertise phpMyAdmin on 8082 but
   Compose defaults to 8081. They disagree about initial passwords and describe
   nginx configuration copying where the current file mounts it. Treat startup
   and reset examples as unverified, especially destructive database rebuilds
   and blanket ownership changes across database and application bind mounts.

6. **Schema detection is inconsistent.** The entrypoint probes
   `mantis_config_table`, but the template specifies empty table prefix/suffix
   (the expected configuration table is `config`). This can cause the installer
   and seed path to run again against an existing unprefixed database. Native
   setup must detect the actual schema and handle upgrades explicitly; copying
   this probe would not provide reliable repeatable installation.

## Recommended next steps

1. **Inventory the existing Docker VM.** Compare its actual application revision,
   PHP/extensions/nginx/MariaDB versions, effective nginx/FPM configuration,
   application settings, schema version, cron jobs, user/group ownership, and
   Git setup against the now-available source/configuration. Focus on effective
   runtime settings and persisted overrides, not requesting files already copied. Confirm with IT any
   differences between this reference VM and production, including outer proxy
   and TLS settings. Record sanitized findings without copying private state.
2. **Choose the native baseline.** Select a Debian release and supported package
   source capable of providing the confirmed versions. The supplied files suggest
   PHP 8.2 and MariaDB 10.11, but inspect the reference VM before fixing these as
   requirements. Check actual package availability when implementing; do not
   silently substitute whatever the new VM installs by default. Preserve the
   Composer lockfile; use `dev` and record the installed commit.
3. **Add a native nginx installation profile.** Reuse the GitHub/LAN bootstrap
   and shared application setup. Make the web-server choice explicit in the
   dispatcher and propagate it to sub-scripts. Keep source selection independent
   of runtime selection. Install and manage nginx, PHP-FPM, and MariaDB through
   Debian services, with a dedicated nginx site and an identified FPM pool.
4. **Separate common and server-specific setup.** Keep database/schema setup,
   application configuration, and Git storage initialization reusable. Isolate
   Apache routing/reload logic from nginx configuration. Supply an explicit
   webroot instead of depending on `apache2ctl`. Adapt PHP configuration paths,
   FPM endpoint, Xdebug setup, service restart commands, and log locations.
5. **Make Doctis-only installation possible.** `install-target()` currently calls
   `install_mantis` unconditionally; the Doctis path also calls `install_webkit`,
   which starts phpMyAdmin and DokuWiki installation. Add explicit feature
   selection so stage one skips the reference MantisBT instance and DokuWiki.
   Set `$g_wiki_enable = OFF` in generated configuration. Treat phpMyAdmin as
   optional; it is not needed to run Doctis.
6. **Match application semantics and validate.** Implement the mapping below,
   install into a clean disposable VM, and run the same functional checks against
   both native and reference Docker instances using equivalent synthetic data.
   Record deliberate differences. Keep development tools optional so production
   PHP behavior can be tested with debugging disabled.
7. **Deliver through the company workflow.** Develop fixes natively and provide
   reviewed commits/releases to IT. Use the existing Docker VM for final checks
   involving container startup, image updates, mounts, or deployment configuration.
   Agree non-destructive schema upgrades, backup, and rollback with IT.

### Native VM mapping and important differences

| Docker reference | Native development VM approach |
| --- | --- |
| nginx plus PHP-FPM in app container | nginx and PHP-FPM system services; equivalent routes, FastCGI parameters, and limits |
| PHP configuration under `/usr/local/etc/php` | Debian's versioned FPM/CLI configuration; compare effective settings in both execution contexts |
| FPM environment populated through deployment | Explicit protected local config or FPM environment settings; a shell export alone is not a configuration strategy |
| Database service hostname `db` | Explicit local endpoint, preferably `127.0.0.1` TCP to retain network-style access; match grants, charset, collation, SQL mode, and timezone |
| `/var/www/html/doctis` application path | Retain the path and `/doctis/` URL prefix where practical; editable checkout with intentional ownership |
| Bind-mounted state | Ordinary persistent directories at equivalent application paths with appropriate service ownership |
| Git roots | Keep `/var/git/doctis` and `/var/www/doctis/worktrees`, including identity and hook setup |
| Runtime operator `hcr` and sudoers | Explicitly configured local operator and scoped commands; verify application tools use that account |
| Entrypoint startup and cron bridge | Idempotent installer plus Debian service/cron configuration; preserve job environment, schedule, and execution user |
| Published ports and external proxy | Explicit local URL/port; reproduce forwarded headers and TLS behavior for relevant tests |

Do not assume the generated application settings already match: the inspected
native installer sets issue attachments to `DATABASE`, whereas the supplied
Docker PHP template explicitly sets `DISK`. Compare the real Docker configuration and align stage
one with it. Also compare document storage, upload limits, table prefix/suffix,
crypto salt persistence, mail queue settings, and session configuration. Changing
a storage setting does not migrate existing content.

## Acceptance checks for the future environment

- Install Doctis alone on a clean Debian VM using the nginx profile from both
  supported source paths (GitHub and LAN). Confirm neither Apache/mod_php,
  DokuWiki, nor a separate MantisBT site is required by that profile.
- Check nginx and FPM configuration, service readiness, database connectivity,
  and recorded runtime versions. Repeat setup without destroying existing state
  or replacing the crypto salt; reboot and verify service recovery.
- Test login/logout, sessions and CSRF, issue/document CRUD, upload/download and
  revision history, permissions, REST/SOAP authentication, Git HTTP clone/push,
  and hook enforcement against equivalent data in both VM environments.
- Verify restricted paths cannot be fetched directly; check proxy-aware URLs,
  redirects, HTTPS cookies when applicable, upload limits, scheduled email, and
  useful logs. Disable unused wiki integration.
- Exercise relevant tests from `doc/TESTING.md`: PHPUnit with a local bootstrap,
  `admin/test-git-php.php`, `admin/test-git-doctis.php`, and
  `admin/tools/doctis-soap-test.sh`. Some tests modify data: use disposable local
  fixtures and explicitly supply the intended endpoint.
- Demonstrate persistence and coordinated restore of database, authoritative Git
  repositories, uploads, and required configuration. Verify an existing dataset
  survives an application upgrade; test container-specific lifecycle separately
  in the reference Docker VM.

## Instructions for agents working in this repository

- Keep findings, assumptions, and verified runtime behavior distinct. Update
  this assessment as the reference VM and IT supply deployment evidence.
- Implement the native Debian/nginx profile; retain Apache as an explicit option. Keep changes
  scoped and maintain Doctis/MantisBT conventions: minimal upstream differences,
  existing naming patterns, tabs for indentation, and spaces for alignment.
- `CLAUDE.md` and `doc/TESTING.md` contain useful application context but assume
  the historical vaio/NFS environment and describe Docker as legacy. Those
  environment assumptions do not override the owner's native nginx development
  requirement and use of Docker as a separate reference. Do not automatically SSH to vaio or run database rebuilds merely
  because those documents show such commands.
- Inspect installation/maintenance scripts before executing them: they can
  modify host packages, sudoers, databases, and document repositories. Use a
  disposable test target for installation and destructive fixture operations.
  Never treat a development rebuild procedure as a production schema migration.
- Keep credentials, tokens, database dumps, and customer documents out of Git.
  Run relevant checks in the intended runtime and report what was actually
  tested, along with missing inputs or remaining parity gaps.
