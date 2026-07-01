# Doctis Git Storage — Server Setup

This document covers every step required to configure the git document storage
infrastructure on a fresh Doctis server installation, including the Smart HTTP
remote-access gateway and the application self-update prerequisites.

**Context:** Doctis stores document source files in per-project git repositories
on the same server that runs Apache/PHP. PHP executes git operations as
`www-data`. Repositories are created lazily on first document upload.

**Environment assumed:**

| Item | Value |
|------|-------|
| Server | vaio (`10.0.0.10`) |
| OS | Debian / Ubuntu |
| Apache user | `www-data` (uid 33, gid 33) |
| www-data HOME | `/var/www` (verify: `getent passwd www-data`) |
| Bare repo root | `/var/git/doctis/` |
| Working tree root | `/var/www/doctis/worktrees/` |
| SSH user | `hcr` |

All commands are run from a remote dev machine via SSH, or directly in a
terminal on the server. Substitute `hcr@vaio` with your own SSH user/hostname.

---

## Step 1 — Ensure git is installed

```bash
ssh hcr@vaio "git --version || sudo apt install -y git"
```

---

## Step 2 — Create storage root directories

The bare repo root and worktree root must be owned by `www-data` so that PHP
can create per-project subdirectories without privilege escalation. Mode `2770`
(setgid) ensures all new files and subdirectories inherit the `www-data` group,
preventing permission drift over time.

```bash
ssh hcr@vaio "
  sudo mkdir -p /var/git/doctis &&
  sudo mkdir -p /var/www/doctis/worktrees &&
  sudo chown www-data:www-data /var/git/doctis /var/www/doctis/worktrees &&
  sudo chmod 2770 /var/git/doctis /var/www/doctis/worktrees
"
```

Verify:

```bash
ssh hcr@vaio "sudo ls -la /var/git/ && sudo ls -la /var/www/doctis/"
```

---

## Step 3 — Set global git identity for www-data

PHP commits require a git author identity. Git reads this from `~/.gitconfig`,
which for `www-data` resolves to `/var/www/.gitconfig`.

**Important:** `/var/www/` is owned by root (mode `755`). The `www-data` user
can read files there but cannot create them. Running
`sudo -u www-data git config --global ...` fails because git tries to create a
lock file alongside `.gitconfig`, which requires write permission on `/var/www/`
itself — not just on the file. The workaround is to write the config file
directly as root; git only needs read access at runtime.

```bash
ssh hcr@vaio "sudo bash -c 'printf \
  \"[user]\n\temail = doctis@vaio.local\n\tname = Doctis\n\
[init]\n\tdefaultBranch = main\n\" \
  > /var/www/.gitconfig && \
  chown www-data:www-data /var/www/.gitconfig'"
```

Verify (www-data can read it):

```bash
ssh hcr@vaio "sudo -u www-data git config --global --list"
```

Expected output:

```
user.email=doctis@vaio.local
user.name=Doctis
init.defaultbranch=main
```

Any future change must also be written as root:

```bash
ssh hcr@vaio "sudo sed -i 's/old-value/new-value/' /var/www/.gitconfig"
```

### Step 3a — Apache HOME issue and the `ensure_git_home()` workaround

Even with `/var/www/.gitconfig` correctly written, PHP git commits fail at
runtime under Apache with:

```
fatal: unable to auto-detect email address (got 'www-data@vaio.(none)')
Author identity unknown
```

Root cause: Apache does not set the `HOME` environment variable for its worker
processes. Without `HOME`, git cannot locate `~/.gitconfig`. When you run
`sudo -u www-data ...` interactively, `sudo` inherits the calling user's `HOME`,
so the problem does not appear in manual testing.

The PHP backend compensates in `GitFileStorageBackend::ensure_git_home()`:

```php
putenv( 'HOME=' . posix_getpwuid( posix_getuid() )['dir'] );
```

This reads `www-data`'s home directory from the passwd database and sets `HOME`
before any git operation. No server-side action is required — this is handled
entirely in code.

**If "Author identity unknown" appears in the Apache error log:**
1. Verify `/var/www/.gitconfig` exists and is readable by `www-data`.
2. Verify `ensure_git_home()` is called before `Git::open()` in `store()` and `delete()`.
3. Verify the `posix` extension is enabled: `php -m | grep posix`.

---

## Step 4 — Install czproject/git-php via Composer

The PHP backend uses the `czproject/git-php` library (v4.4.0 or later) to
drive git operations. If the Doctis webroot is an NFS mount, run Composer on
the server directly so it writes to the server's local filesystem and respects
the server's PHP version:

```bash
ssh hcr@vaio "cd /var/www/html/doctis && composer install"
```

If `czproject/git-php` is not yet in `composer.json`:

```bash
ssh hcr@vaio "cd /var/www/html/doctis && composer require czproject/git-php:^4.4"
```

Verify (as `www-data` to mirror the Apache runtime):

```bash
ssh hcr@vaio "sudo -u www-data php -r \"
  require '/var/www/html/doctis/vendor/autoload.php';
  \\\$git = new CzProject\\\GitPhp\\\Git;
  echo 'czproject/git-php loaded OK\n';
\""
```

Expected: `czproject/git-php loaded OK`

---

## Step 5 — Verify the full create/commit/push/retrieve cycle

This test simulates exactly what PHP does when a document is uploaded to a new
project. Run each command in sequence.

**5a.** Create a bare repository (simulates project creation):

```bash
ssh hcr@vaio "sudo -u www-data git init --bare /var/git/doctis/test-project.git"
```

Expected: `Initialized empty Git repository in /var/git/doctis/test-project.git/`

**5b.** Clone a working tree from the bare repo:

```bash
ssh hcr@vaio "sudo -u www-data git clone \
  /var/git/doctis/test-project.git \
  /var/www/doctis/worktrees/test-project"
```

Expected: `warning: You appear to have cloned an empty repository.`

**5c.** Write a file, stage, commit, and push (simulates file upload):

```bash
ssh hcr@vaio "sudo -u www-data bash -c '
  cd /var/www/doctis/worktrees/test-project &&
  mkdir -p PROC-TEST-001 &&
  printf \"# Test Document\n\nContent.\n\" > PROC-TEST-001/source.md &&
  git add PROC-TEST-001/source.md &&
  git commit -m \"dwg_id=1 rev=A upload by testuser\" &&
  git push origin main
'"
```

> **The push is mandatory.** `git commit` only writes to the working tree's
> local history. The bare repo — the durable store — receives nothing until
> `git push` is called. If push is omitted, retrieval from the bare repo
> returns stale or absent content. `GitFileStorageBackend::store()` always
> pushes as its final step.

**5d.** Confirm the bare repo received the commit:

```bash
ssh hcr@vaio "sudo -u www-data git \
  --git-dir=/var/git/doctis/test-project.git log --oneline"
```

**5e.** Retrieve document content by commit SHA (replace `xxxxxxx`):

```bash
ssh hcr@vaio "sudo -u www-data git \
  --git-dir=/var/git/doctis/test-project.git \
  show xxxxxxx:PROC-TEST-001/source.md"
```

**5f.** Retrieve using HEAD:

```bash
ssh hcr@vaio "sudo -u www-data git \
  --git-dir=/var/git/doctis/test-project.git \
  show HEAD:PROC-TEST-001/source.md"
```

**5g.** Verify content integrity (SHA256 must match):

```bash
ssh hcr@vaio "
  sudo -u www-data git --git-dir=/var/git/doctis/test-project.git \
    show HEAD:PROC-TEST-001/source.md | sha256sum &&
  sudo cat /var/www/doctis/worktrees/test-project/PROC-TEST-001/source.md \
    | sha256sum
"
```

**5h.** Clean up:

```bash
ssh hcr@vaio "
  sudo rm -rf /var/git/doctis/test-project.git &&
  sudo rm -rf /var/www/doctis/worktrees/test-project
"
```

### Branch naming: main vs master

The global gitconfig (Step 3) sets `init.defaultBranch = main`, ensuring all
new repos use `main`. Repos created before that setting was written will use
`master`. The PHP backend calls `getCurrentBranchName()` before every push so
both are handled. To rename an existing `master` branch:

```bash
ssh hcr@vaio "
  sudo -u www-data git -C /var/www/doctis/worktrees/<slug> \
    branch -m master main &&
  sudo -u www-data git -C /var/www/doctis/worktrees/<slug> \
    push --set-upstream origin main
"
```

---

## Step 6 — Run the PHP integration test

`admin/test-git-php.php` exercises the full czproject/git-php store/retrieve/
delete cycle. It is self-contained: creates a temporary bare repo and worktree,
runs 15 checks, then tears everything down regardless of outcome.

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php"
```

Expected output:

```
=== czproject/git-php integration test ===
    slug: phptest-NNNNN

── Setup ──────────────────────────────────────────────────────
  1. git init --bare   ... OK
  2. git clone         ... OK

── Store cycle ────────────────────────────────────────────────
  3. czproject open    ... OK
  4. write source file ... OK
  5. git add           ... OK
  6. git commit        ... OK (SHA: xxxxxxxx...)
  7. git push          ... OK

── Retrieval ──────────────────────────────────────────────────
  8. retrieve by SHA   ... OK
  9. retrieve via HEAD ... OK
 10. SHA256 integrity  ... OK

── Delete cycle ───────────────────────────────────────────────
 11. git rm           ... OK
 12. commit delete    ... OK (SHA: xxxxxxxx...)
 13. push delete      ... OK

── History integrity ──────────────────────────────────────────
 14. absent from HEAD ... OK
 15. history retained ... OK

── Teardown ───────────────────────────────────────────────────
  Removed: /var/www/doctis/worktrees/phptest-NNNNN
  Removed: /var/git/doctis/phptest-NNNNN.git

── Result ─────────────────────────────────────────────────────
  15 / 15 tests passed
  All tests passed.
```

This test must pass before enabling `$g_dwg_upload_method = GIT`.

If any test fails, check the Apache error log:

```bash
ssh hcr@vaio "sudo tail -30 /var/log/apache2/error.log | grep -v Xdebug"
```

---

## Step 7 — Add GIT storage config keys to `config_inc.php`

Activate the GIT backend by adding these keys to
`/var/www/html/doctis/config/config_inc.php`:

```php
$g_dwg_upload_method = GIT;               // constant 3 (defined in constant_inc.php)
$g_git_storage_root  = '/var/git/doctis';
$g_git_worktree_root = '/var/www/doctis/worktrees';
```

`GIT` applies **only** to the primary registered document. Bug/issue and
document attachments are always stored via `DISK`/`DATABASE`. Leave
`$g_file_upload_method` (the separate bug-attachment key) as `DISK` or
`DATABASE`.

---

## Step 8 — Remote Git access over HTTP (Smart HTTP gateway)

Lets advanced users `git clone` a project's document repository from a remote
workstation, authenticated with a Doctis API token — no Linux account required.
Current phase: **read-only** (clone/fetch only; push rejected).

### Prerequisites on the server

```bash
# Confirm git-http-backend is present
ls -l /usr/lib/git-core/git-http-backend

# Enable required Apache modules (cgi is NOT required — the gateway uses proc_open)
sudo a2enmod alias setenvif
```

### Install the Apache configuration

```bash
sudo cp /var/www/html/doctis/admin/tools/git-serve.conf \
        /etc/apache2/conf-available/git-serve.conf
sudo a2enconf git-serve
sudo apache2ctl configtest && sudo systemctl reload apache2
```

The config routes `/git/<slug>.git/<service>` to the PHP gateway:

```apache
Alias /git /var/www/html/doctis/git_http.php
AcceptPathInfo On
SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

**Important:** remove any earlier, unauthenticated `git-serve.conf`. An older
template exported all of `/var/www/html` anonymously (with
`GIT_HTTP_EXPORT_ALL` and `Require all granted`) — that must not remain
enabled. Overwriting as above replaces it.

### Enable the feature in Doctis

```php
// config/config_inc.php
$g_git_http_enabled = ON;             // OFF by default
```

Defaults in `config_defaults_inc.php` (override only if needed):

```php
$g_git_http_backend         = '/usr/lib/git-core/git-http-backend';
$g_git_http_read_threshold  = DEVELOPER;   // minimum project level for clone
$g_git_http_write_threshold = MANAGER;     // reserved for push (Phase 2)
```

### Authentication

Users create a personal API token at *My Account → API Tokens*. The token is
used as the HTTP Basic *password*; the Basic username is the Doctis username.

```bash
git clone http://<username>:<API_TOKEN>@vaio/git/<slug>.git /tmp/<slug>
```

### Verify

```bash
# 401 without credentials
curl -s -o /dev/null -w '%{http_code}\n' \
  "http://vaio/git/<slug>.git/info/refs?service=git-upload-pack"

# 200 with a valid token (DEVELOPER+)
curl -s -u "<user>:<API_TOKEN>" \
  "http://vaio/git/<slug>.git/info/refs?service=git-upload-pack" | head

# Full clone
git clone "http://<user>:<API_TOKEN>@vaio/git/<slug>.git" /tmp/<slug>
```

Expected: 401 without credentials, 200 + clone with a valid token (DEVELOPER+),
403 for a low-privilege user (`viewer`), 404 for an unknown repo, 403 for
push.

### Security notes

- Tokens travel as HTTP Basic credentials — use HTTPS/TLS in production. The
  dev vhost on vaio is plain HTTP on the LAN only.
- Push (`git-receive-pack`) is rejected in this phase.
- Disable entirely: `sudo a2disconf git-serve && sudo systemctl reload apache2`,
  or set `$g_git_http_enabled = OFF` and reload Apache.

---

## Step 9 — Application self-update (`git pull`) prerequisites

This concerns the System Operations "Git Pull / Self-Update" feature
(`manage_git_pull_page.php` / `manage_git_pull_action.php`), not the document
repos. It is documented here because it is a git-related server requirement
most often missed when moving to a new host.

### Deploy user

`git pull` runs as a real account that (a) can read the application source tree
and (b) holds the git remote credentials needed to reach `origin` — **not**
`www-data`. On vaio this is `hcr`. The examples below use `$USER`, which
expands to whoever you are logged in as; make sure that is the deploy account
(not root).

This account is configured in Doctis by `$g_updater_run_as_user`
(`config/config_inc.php`). All System Operations pages build privileged
commands as `sudo -u <that user>` via `system_ops_sudo_prefix()`
(`core/system_ops_api.php`). If left empty the operation reports "not
configured" rather than running as the wrong user.

### The ownership problem

The application source tree (`/var/www/html/doctis`) is typically owned by the
deploying user (e.g. `robert:share`), not by `www-data`. Modern git (≥ 2.35.2)
refuses to operate on a repository owned by a different user:

```
fatal: detected dubious ownership in repository at '/var/www/html/doctis'
```

This affects both ends of the feature:
- The confirmation page runs git directly as `www-data` (to read branch/commit).
- The action runs the pull as the deploy user (also not the owner).

### Required fix 1 — system gitconfig `safe.directory`

```bash
sudo git config --system --add safe.directory /var/www/html/doctis
```

Use `--system`, not a per-user `~/.gitconfig`: the setting must apply to both
`www-data` (the page's direct calls) and the deploy user (the sudo'd pull). A
setting in the deploy user's `~/.gitconfig` would not help `www-data`.

Verify:

```bash
sudo git config --system --get-all safe.directory
# must list /var/www/html/doctis
```

### Required fix 2 — sudoers rule for the pull

Create `/etc/sudoers.d/doctis-web` with the deploy account substituted (run as
that account, not root — the heredoc must expand `$USER`):

```bash
sudo tee /etc/sudoers.d/doctis-web > /dev/null << EOF
www-data ALL=($USER) NOPASSWD: /usr/bin/git -C /var/www/html/doctis pull
EOF
sudo visudo -c -f /etc/sudoers.d/doctis-web   # validate syntax
```

Only the exact `git ... pull` command is allowed NOPASSWD. Other subcommands
are deliberately excluded and will prompt for a password if invoked.

### Verify (non-destructive)

```bash
# Page's direct read as www-data — must print the branch, not an error
sudo -u www-data git -C /var/www/html/doctis rev-parse --abbrev-ref HEAD
sudo -u www-data git -C /var/www/html/doctis status

# Confirm the sudoers rule is present
sudo -l -U www-data | grep 'doctis pull'
```

Do **not** use `sudo -u www-data sudo -u $USER git ... status` as a smoke test:
`status` is not in the sudoers allowlist, so it prompts for a password.

### Troubleshooting

| Symptom | Cause |
|---------|-------|
| `detected dubious ownership` | Required fix 1 missing or path incorrect |
| Password prompt for `www-data` | Command run is not the exact allowlisted `git ... pull`, or sudoers file absent/invalid |
| Blank branch/commit on the manage page | Required fix 1 missing |

---

## Legacy cleanup

An earlier version of the setup created a single monolithic bare repo:

```
/var/git/doctis-store.git
/var/www/doctis/document_workspace/
```

These predate the per-project design and are not used by the current
implementation. Confirm they contain no commits, then remove:

```bash
ssh hcr@vaio "sudo -u www-data git \
  --git-dir=/var/git/doctis-store.git log --oneline 2>&1 | head -5"
# If empty / no commits:
ssh hcr@vaio "
  sudo rm -rf /var/git/doctis-store.git &&
  sudo rm -rf /var/www/doctis/document_workspace
"
```

---

## Quick Reference — git operations by SSH

| Operation | Command |
|-----------|---------|
| Check bare repos | `sudo ls -la /var/git/doctis/` |
| Check worktrees | `sudo ls -la /var/www/doctis/worktrees/` |
| Create bare repo | `sudo -u www-data git init --bare /var/git/doctis/<slug>.git` |
| Create worktree | `sudo -u www-data git clone /var/git/doctis/<slug>.git /var/www/doctis/worktrees/<slug>` |
| Store file | `git add <path> && git commit -m "..." && git push origin main` |
| Retrieve by SHA | `git --git-dir=<bare> show <sha>:<path>` |
| Retrieve HEAD | `git --git-dir=<bare> show HEAD:<path>` |
| List file history | `git --git-dir=<bare> log --oneline -- <path>` |
| Soft delete | `git rm <path> && git commit -m "..." && git push origin main` |
| Recover worktree | `rm -rf <worktree> && git clone <bare> <worktree>` |
| Verify www-data config | `sudo -u www-data git config --global --list` |
| Check git commit attribution | `git --git-dir=/var/git/doctis/example.git log --format='%h %an <%ae> %s' -5` |
