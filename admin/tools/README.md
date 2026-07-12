# admin/tools — Doctis Shell Scripts

Scripts for installing, configuring, and maintaining a Doctis server.
All scripts use the same colour-coded output convention (cyan = info,
green = success/diagnostic, yellow = warning, red = failure).

---

## Script overview

| Script | Purpose | Run as |
|--------|---------|--------|
| `install.sh` | Bootstrap: fetch `install-option.sh` from GitHub and run a full install | user |
| `install-lan.sh` | Like `install.sh` but fetches scripts from the local vaio server instead of GitHub | user |
| `install-option.sh` | Dispatcher — parses verbs (`install all\|system\|target`) and sources sub-scripts | user |
| `install-system.sh` | System-level setup: LAMP stack, extra packages, MariaDB, VS Code, Xdebug | sudo |
| `install-target.sh` | Application-level setup: clone repo, configure DB, run MantisBT installer, git storage | sudo |
| `install-check.sh` | Virtualization detection library (`virtualbox`, `qemu-kvm`, `digitalocean`, `baremetal`) | user |
| `check.sh` | Development scratch / URL sanity check (incomplete, not for production use) | user |
| `doctis-git-setup.sh` | One-time git document-store infrastructure setup (directories, gitconfig, Composer) | sudo |
| `doctis-git-reset.sh` | Wipe the git document store to an empty state (for testing clean-room resets) | sudo |
| `doctis-drop-and-create-new-database.sh` | Drop and recreate the MariaDB database, run the installer, reload example data | user (mysql via ~/.my.cnf) |
| `doctis-soap-test.sh` | Exercise Doctis-specific SOAP endpoints end-to-end; prints pass/fail per test | user |
| `doctis-git-import.sh` | Import an existing git repository as a Doctis project (wrapper for `admin/import-git-repo.php`; see `--help` and doc/git/GIT_IMPORTER.md) | sudo (runs as www-data) |

---

## Installation scripts

### `install.sh`

The simplest entry point for a fresh internet-connected server.  Fetches
`install-option.sh` from GitHub and runs a full install.

Edit the credentials at the top of the file before running:

```bash
email_addr="my.email@gmail.com"
email_hash="GmailAppPassword"
mysql_pass="password"
```

Then run on the target server:

```bash
bash install.sh
```

This is equivalent to:

```bash
./install-option.sh install all "<ip>" "<mysql_pass>" "<email>" "<app_pass>" "doctis"
```

---

### `install-lan.sh`

LAN variant of `install.sh`.  Fetches scripts from vaio (`10.0.0.10`) instead
of GitHub.  Use this when the target VM can reach vaio but not the internet,
or to test install scripts that have not yet been pushed to GitHub.

```bash
# On the target VM:
wget http://10.0.0.10/doctis/admin/tools/install-lan.sh
bash install-lan.sh
```

**Note:** `sudo` is required internally.  `HEADLESS=1` suppresses interactive
prompts (except the `sudo` password, which always requires a TTY).

```bash
export HEADLESS=1
bash install-lan.sh
```

---

### `install-option.sh`

Dispatcher script — not usually run directly.  Called by `install.sh` /
`install-lan.sh`, or manually for partial installs.

```
Usage:
  ./install-option.sh install all    <domain> <mysql_pass> <email> <app_pass> <target>
  ./install-option.sh install system <domain> <mysql_pass> <email> <app_pass> <target>
  ./install-option.sh install target <domain> <mysql_pass> <email> <app_pass> <target>
```

- `all` — runs `install-system.sh` then `install-target.sh`
- `system` — runs `install-system.sh` only (packages, MariaDB)
- `target` — runs `install-target.sh` only (clone repo, configure, install schema)

Scripts are sourced (not sub-shelled), so variables and functions from each
are available in the caller's environment.

By default scripts are fetched from GitHub.  Override with:

```bash
export DOCTIS_SCRIPT_URL="http://10.0.0.10/doctis/admin/tools"  # LAN source
export DOCTIS_GIT_REPO="http://10.0.0.10/git/doctis"            # LAN git repo
```

---

### `install-system.sh`

Installs and configures system-level packages.  Sourced by `install-option.sh`;
can also be run standalone.

What it does:
- Detects virtualization type via `install-check.sh`
- Installs LAMP stack (`apache2`, `php`, `mariadb-server`, PHP extensions)
- Installs CLI tools (`git`, `composer`, `wget`, `curl`, etc.)
- Installs VS Code (GUI environments only)
- Configures Xdebug
- Initialises MariaDB (creates `admin` user)
- Adds `www-data` to the current user's group and vice-versa

Entry function: `install_system "$@"`

**Headless mode** (`export HEADLESS=1`): auto-confirms all prompts.  The
`sudo` password is still required at the terminal.

---

### `install-target.sh`

Installs and configures the Doctis application.  Sourced by `install-option.sh`;
can also be run standalone.

What it does:
- Clones the Doctis git repository into the Apache webroot
- Writes `config/config_inc.php` (database credentials, email, domain, GIT storage paths)
- Runs the MantisBT/Doctis web installer (`admin/install.php`) and saves HTML log
- Installs phpMyAdmin (optional, GUI environments)
- Installs DokuWiki (optional)
- Runs `doctis-git-setup.sh` via `sudo bash` to set up git document storage
- Loads example project data via SQL

Entry function: `install_target "$@"`

**Key parameters** (positional, passed from `install-option.sh`):

| Position | Example | Description |
|----------|---------|-------------|
| 1 | `10.0.0.10` | Domain / IP address used in URLs |
| 2 | `password` | MariaDB admin password |
| 3 | `me@gmail.com` | Email address for notifications |
| 4 | `AppPassword` | Gmail app password |
| 5 | `doctis` | Target project name |

The installer HTML log is saved to the **current working directory** as
`doctis_install_<YYYYMMDD_HHMMSS>.html`.

---

### `install-check.sh`

Virtualization detection library.  Not run directly — sourced by
`install-system.sh`.  Provides `detect_virtualization()` which returns one of:
`virtualbox`, `qemu-kvm`, `digitalocean`, `baremetal`.

---

### `check.sh`

Development scratch script.  Prints what the installer URL would be for a
given project name — used during development to verify URL construction logic.
Not intended for production use.

---

## Maintenance scripts

### `doctis-git-setup.sh`

One-time setup of the Doctis git document-storage infrastructure.  Idempotent —
safe to re-run on an already-configured server.

What it does:
- Creates `/var/git/doctis/` (bare repo root, owned `www-data:www-data`, mode `2770`)
- Creates `/var/www/doctis/worktrees/` (working tree root, same ownership)
- Writes `/var/www/.gitconfig` with `www-data` identity and `init.defaultBranch = main`
- Installs `czproject/git-php` via Composer into the Doctis webroot

Must be run as root:

```bash
# Via SSH from dev machine:
ssh hcr@vaio "sudo bash /var/www/html/doctis/admin/tools/doctis-git-setup.sh"

# With explicit webroot (default is /var/www/html/doctis):
ssh hcr@vaio "sudo bash /var/www/html/doctis/admin/tools/doctis-git-setup.sh /var/www/html/doctis"
```

This script is also called automatically by `install-target.sh` during a full install.

---

### `doctis-git-reset.sh`

Wipes the git document store to an empty state.  Used for clean-room test
resets.  **Always run together with `doctis-drop-and-create-new-database.sh`**
— the two stores must be kept in sync.

What it does:
- Removes all per-project bare repositories under `/var/git/doctis/`
- Removes all per-project working trees under `/var/www/doctis/worktrees/`
- Leaves the root directories themselves intact

Must be run as root.  Prompts for confirmation when invoked directly;
runs without prompting when sourced:

```bash
# Interactive (direct invocation):
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"

# Non-interactive (sourced from another script):
# source doctis-git-reset.sh   →  main() is called automatically
```

---

### `doctis-drop-and-create-new-database.sh`

Drops the `doctis` MariaDB database, recreates it, runs the Doctis schema
installer, and reloads example data (project, licenses, test users).

**Must be run from its own directory.**  It uses the relative path
`../../../doctis` to construct the correct installer URL.  Running from any
other directory causes a 404 and the schema is not installed.

```bash
# Correct — cd into the script's directory first:
ssh hcr@vaio "echo 'yes' | bash -c 'cd /var/www/html/doctis/admin/tools && bash doctis-drop-and-create-new-database.sh'"

# Wrong — will 404 and leave the database empty:
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"
```

Accepts optional positional parameters to override defaults:

```
doctis-drop-and-create-new-database.sh [target] [mysql_pass] [domain_ip]
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `target` | `doctis` | Database name and URL path |
| `mysql_pass` | `password` | MariaDB admin password |
| `domain_ip` | auto-detected | IP/hostname used to reach the installer URL |

A timestamped HTML log of the installer output is saved to the current
directory as `doctis_install_<YYYYMMDD_HHMMSS>.html` (excluded from git).

---

---

## SOAP smoke test

### `doctis-soap-test.sh`

End-to-end test for the Doctis-specific SOAP endpoints introduced in
`git-structure-2`.  Exercises all three priority groups in one script:

| Step | Endpoint | What is tested |
|------|----------|----------------|
| 0 | `mc_version` | Connectivity — endpoint reachable |
| 1 | `mc_enum_dwg_status` | Returns all 11 document statuses |
| 2 | `mc_dwg_get` | Retrieves a document by id |
| 3 | `mc_dwg_primary_get` | Returns empty before any upload |
| 4 | `mc_dwg_primary_upload` | Upload a small test file |
| 5 | `mc_dwg_primary_get` | Metadata correct after upload |
| 6 | (HTTP download) | Authenticated download — content matches byte-for-byte |
| 7 | `mc_dwg_primary_delete` | Delete the primary file |
| 8 | `mc_dwg_primary_get` | Returns empty after delete |
| 9 | `mc_dwg_attachment_add` | Add a note attachment to a document |
| 10 | `mc_dwg_attachment_get` | Retrieved content matches what was uploaded |
| 11 | `mc_dwg_attachment_delete` | Delete the attachment |
| 12 | `mc_dwg_attachment_get` | Returns "not found" fault after delete |

Failures are non-fatal — the script runs all steps and prints a summary.
Exit code is 0 if all tests pass, 1 if any fail.

**Usage:**

```bash
# Run with defaults (host=http://10.0.0.10/doctis, user=manager, dwg_id=2):
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh"

# Override all parameters:
bash admin/tools/doctis-soap-test.sh <host> <username> <password> <dwg_id>

# Example — different document:
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh http://10.0.0.10/doctis manager '' 3"
```

**Requirements:**
- `curl`, `base64`, `grep`, `sed` — all standard on Debian/Ubuntu.
- At least one document must exist in the database.
- The test account must have Manager-level access to the target document's project.
- The GIT file storage backend must be configured and operational.

**Note on base64 encoding:** PHP's SoapServer automatically decodes
`xsd:base64Binary` parameters before passing them to PHP handlers.  The
PHP handlers then call `base64_decode()` themselves (matching how PHP's
SoapClient double-encodes).  This script compensates by **double-encoding**
all uploaded content (`base64 | base64`) and **double-decoding** all
retrieved content (`base64 -d | base64 -d`).  A PHP SoapClient (used by
the PHPUnit test suite) handles this transparently.

---

## Full clean-room reset (database + git store)

Run these two commands in order before any test session that must start from
a known-empty state:

```bash
# 1. Wipe git document store
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"

# 2. Drop, recreate, and reinstall the database
ssh hcr@vaio "echo 'yes' | bash -c 'cd /var/www/html/doctis/admin/tools && bash doctis-drop-and-create-new-database.sh'"
```

Verify afterwards:

```bash
# Git store should be empty
ssh hcr@vaio "ls /var/git/doctis/ /var/www/doctis/worktrees/"

# Database should have example project and test users only
ssh hcr@vaio "mysql -e 'SELECT id,name FROM doctis.project; SELECT id,username FROM doctis.user;'"
```

---

## Test users loaded by default

| Username | Access level | Password |
|----------|-------------|----------|
| `viewer` | 10 — Viewer | _(blank)_ |
| `user` | 25 — Reporter | _(blank)_ |
| `reporter` | 25 — Reporter | _(blank)_ |
| `updater` | 40 — Updater | _(blank)_ |
| `developer` | 55 — Developer | _(blank)_ |
| `manager` | 70 — Manager | _(blank)_ |
| `admin` | 90 — Administrator | `password` |
