# Updater Plan — Admin System Operations

**Goal:** Add six system-administration actions to `manage_overview_page.php`:
1. Download / upload `config/config_inc.php`
2. `git pull` to self-update the application
3. Re-initialise the database via `doctis-drop-and-create-new-database.sh`
4. Load sample data via `doctis-load-sample-data.sh`
5. Download a backup archive of the database (`mysqldump` → `.sql.gz`)
6. Download a backup archive of the git document store (`.tar.gz`)

---

## 1. Security Model

All six features are sensitive — they must never be reachable by ordinary
users.  Every page and action enforces:

```php
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
```

Action pages additionally verify a CSRF token via `form_security_validate()` /
`form_security_purge()` (the standard MantisBT pattern used by every `*_action`
/ `*_set` page).

---

## 2. The Permission Problem

The web process runs as `www-data`.  Relevant facts:

| Resource | Owner | Group | Mode | www-data access |
|---|---|---|---|---|
| `/var/www/html/doctis/config/` | robert | share | drwxrwsr-x | traverse only |
| `config/config_inc.php` | hcr | share | -rw-rw-r-- | **read** only |
| `/var/www/html/doctis/.git/` | robert | share | drwxrwsr-x | traverse only |
| working tree files | robert/hcr | share | -rw-rw-r-- | **read** only |
| `/var/git/doctis/` | www-data | www-data | drwxr-sr-x | **full access** |
| `/var/www/doctis/worktrees/` | www-data | www-data | drwxr-sr-x | **full access** |

`www-data` is not in the `share` group, so it cannot write the working tree or
config directory.  `hcr` **is** in both `www-data` and `share`, owns
`~/.my.cnf` (MariaDB credentials used by the DB scripts), and can write the
entire working tree.

Critically, `www-data` **owns** `/var/git/doctis/` (the bare document repos),
so Feature 6 (git backup) requires no privilege escalation at all.

### Solution: targeted `sudo` rules

Add `/etc/sudoers.d/doctis-web` on **vaio** (one-time manual step — see
§9 for the full setup procedure):

```sudoers
# Allow the Apache www-data process to run specific Doctis admin operations
# as user hcr, who owns the working tree and holds the DB credentials.

www-data ALL=(hcr) NOPASSWD: /usr/bin/git -C /var/www/html/doctis pull
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-write-config.sh
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-backup-database.sh
```

> **Why run as `hcr`, not root?**  
> The git working tree and config directory are owned by `share`-group members.
> The DB scripts read `~/.my.cnf` for MariaDB credentials, which only exists in
> hcr's home.  Running as `hcr` avoids granting root powers to the web process.

---

## 3. Feature 1 — Config File Download / Upload

### Pages to create

| File | Role |
|---|---|
| `manage_config_file_page.php` | Shows current file size/mtime, download link, and upload form |
| `manage_config_file_download.php` | Streams `config_inc.php` to the browser as an attachment |
| `manage_config_file_upload.php` | Receives the uploaded file and writes it via the sudo wrapper |

### New helper script: `admin/tools/doctis-write-config.sh`

Receives new config content on stdin and overwrites `config/config_inc.php`,
creating a timestamped backup first.  Invoked only via sudo from the PHP action.

```bash
#!/bin/bash
CONFIG=/var/www/html/doctis/config/config_inc.php
BACKUP="${CONFIG}.bak.$(date +%Y%m%d_%H%M%S)"
cp "$CONFIG" "$BACKUP"
cat > "$CONFIG"
echo "Config written. Backup: $BACKUP"
```

### PHP upload flow (`manage_config_file_upload.php`)

1. `auth_reauthenticate()` + `access_ensure_global_level(ADMINISTRATOR)`
2. `form_security_validate('manage_config_file_upload')`
3. Validate uploaded file: must begin with `<?php`, must not exceed ~128 KB
4. Pipe content to the wrapper via `proc_open`:
   ```php
   $t_proc = proc_open(
       'sudo -u hcr /var/www/html/doctis/admin/tools/doctis-write-config.sh',
       [ 0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w'] ],
       $t_pipes
   );
   fwrite( $t_pipes[0], file_get_contents( $_FILES['config_file']['tmp_name'] ) );
   fclose( $t_pipes[0] );
   $t_output = stream_get_contents( $t_pipes[1] );
   proc_close( $t_proc );
   ```
5. Redirect to `manage_config_file_page.php` with success/error notice.

### Download flow (`manage_config_file_download.php`)

`www-data` can already read the file (world-readable).  No sudo required:

```php
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="config_inc.php"');
readfile( dirname(__DIR__) . '/config/config_inc.php' );
```

---

## 4. Feature 2 — Git Pull (Self-Update)

### Pages to create

| File | Role |
|---|---|
| `manage_git_pull_page.php` | Confirmation page (shows current branch/commit, warns of consequences) |
| `manage_git_pull_action.php` | Runs `git pull`, displays output |

### PHP action (`manage_git_pull_action.php`)

```php
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
form_security_validate( 'manage_git_pull' );
form_security_purge( 'manage_git_pull' );

exec( 'sudo -u hcr /usr/bin/git -C /var/www/html/doctis pull 2>&1', $t_lines, $t_rc );
$t_output = implode( "\n", $t_lines );
```

Display `$t_output` in a `<pre>` block. Surface `$t_rc !== 0` as an error.

`git pull` fails cleanly (exit 1, no destructive action) if there are
uncommitted local changes — display the raw message verbatim.

The `safe.directory` for `/var/www/html/doctis` is already set in the system
gitconfig.  The sudoers stanza does not need `env_keep HOME` because
`/usr/bin/git` run as hcr will find `/home/hcr/.gitconfig` naturally.

---

## 5. Feature 3 — Database Rebuild

**Most destructive action**: all database content is permanently deleted and
recreated from schema.php.  The git document store is unaffected.

### Pages to create

| File | Role |
|---|---|
| `manage_db_rebuild_page.php` | Red confirmation page; requires typing `REBUILD` |
| `manage_db_rebuild_action.php` | Pipes `yes` to the script and displays output |

### Confirmation UX

> **This will destroy all data in the Doctis database and rebuild it from
> schema.php.  Sample data will NOT be reloaded.  This action cannot be
> undone.**

Require the administrator to type `REBUILD` in a text field (not a checkbox)
to reduce the risk of accidental clicks.

### PHP action (`manage_db_rebuild_action.php`)

```php
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
form_security_validate( 'manage_db_rebuild' );
form_security_purge( 'manage_db_rebuild' );

if( gpc_get_string( 'confirm', '' ) !== 'REBUILD' ) {
    error_parameters( 'Confirmation text did not match.' );
    trigger_error( ERROR_GENERIC, ERROR );
}

$t_script = '/var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh';
exec( 'echo yes | sudo -u hcr bash ' . escapeshellarg( $t_script ) . ' 2>&1',
      $t_lines, $t_rc );
$t_output = implode( "\n", $t_lines );
```

The script exits 0 and prints `✔ doctis database install successful.` on
success.  `echo yes |` satisfies the interactive confirmation prompt.

---

## 6. Feature 4 — Load Sample Data

### Pages to create

| File | Role |
|---|---|
| `manage_db_load_sample_page.php` | Amber confirmation page; warns about idempotency |
| `manage_db_load_sample_action.php` | Pipes `yes` to the script and displays output |

### Confirmation UX

> **This will INSERT the example project, licences, and test user accounts
> into the current database.  The INSERT statements are NOT idempotent —
> running this against a database that already contains these rows will fail
> with duplicate-key errors.  Only run on a freshly rebuilt (empty) database.**

Require the administrator to type `LOAD` in a text field.

### PHP action (`manage_db_load_sample_action.php`)

```php
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
form_security_validate( 'manage_db_load_sample' );
form_security_purge( 'manage_db_load_sample' );

if( gpc_get_string( 'confirm', '' ) !== 'LOAD' ) {
    error_parameters( 'Confirmation text did not match.' );
    trigger_error( ERROR_GENERIC, ERROR );
}

$t_script = '/var/www/html/doctis/admin/tools/doctis-load-sample-data.sh';
exec( 'echo yes | sudo -u hcr bash ' . escapeshellarg( $t_script ) . ' 2>&1',
      $t_lines, $t_rc );
$t_output = implode( "\n", $t_lines );
```

Success output contains `Test user accounts loaded.` and
`Example project/licence data loaded.`.

> **Typical workflow for a full development reset:**
> 1. Click "Rebuild Database" → type `REBUILD` → confirm
> 2. Click "Load Sample Data" → type `LOAD` → confirm

---

## 7. Feature 5 — Database Backup (Download)

### Pages to create

| File | Role |
|---|---|
| `manage_db_backup_page.php` | Shows DB name, triggers the download |
| `manage_db_backup_download.php` | Streams `mysqldump \| gzip` to the browser |

### New helper script: `admin/tools/doctis-backup-database.sh`

A thin wrapper around `mysqldump` so the sudoers rule points to a fixed path
rather than the `mysqldump` binary with arguments (which would be hard to lock
down).  The script reads credentials from hcr's `~/.my.cnf`.

```bash
#!/bin/bash
exec /usr/bin/mysqldump \
    --single-transaction \
    --routines \
    --triggers \
    --add-drop-table \
    doctis
```

### PHP download flow (`manage_db_backup_download.php`)

Stream the dump through gzip directly to the browser — avoids writing
anything to disk and sidesteps the 87%-full filesystem concern.

```php
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_filename = 'doctis_db_backup_' . date( 'Ymd_His' ) . '.sql.gz';
header( 'Content-Type: application/gzip' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache' );

// Flush any output buffering so headers go out immediately
while( ob_get_level() ) ob_end_flush();
flush();

$t_cmd = 'sudo -u hcr /var/www/html/doctis/admin/tools/doctis-backup-database.sh | /bin/gzip';
$t_handle = popen( $t_cmd, 'r' );
while( !feof( $t_handle ) ) {
    echo fread( $t_handle, 65536 );
    flush();
}
pclose( $t_handle );
exit;
```

No CSRF token is needed here because this is a read-only GET request that
produces a download — it does not modify state.  The `auth_reauthenticate()`
and `access_ensure_global_level(ADMINISTRATOR)` guards are sufficient.

### Typical backup size

The current database is small (development data only).  A compressed dump
will be a few kilobytes.  Even a production instance with thousands of
document records is unlikely to exceed a few megabytes in SQL form.

---

## 8. Feature 6 — Git Document Store Backup (Download)

### Pages to create

| File | Role |
|---|---|
| `manage_git_backup_page.php` | Shows store size, triggers the download |
| `manage_git_backup_download.php` | Streams `tar czf -` of the bare repos to the browser |

### No sudo required

`/var/git/doctis/` is owned by `www-data:www-data` with mode `drwxr-sr-x`.
The web process can read and traverse it directly.

Current size: ~1.2 MB for the bare repos + ~1.3 MB for worktrees — a
total archive will be small.

### What to archive

Archive only the bare repos under `/var/git/doctis/` — these are the
authoritative store.  The worktrees under `/var/www/doctis/worktrees/`
are working copies that can be reconstructed with `git clone` from the
bare repos and are redundant to back up.

### PHP download flow (`manage_git_backup_download.php`)

```php
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_filename = 'doctis_git_backup_' . date( 'Ymd_His' ) . '.tar.gz';
header( 'Content-Type: application/gzip' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache' );

while( ob_get_level() ) ob_end_flush();
flush();

// -C changes into the parent directory so paths in the archive are relative
$t_handle = popen( '/bin/tar czf - -C /var/git/doctis .', 'r' );
while( !feof( $t_handle ) ) {
    echo fread( $t_handle, 65536 );
    flush();
}
pclose( $t_handle );
exit;
```

No CSRF token needed — read-only GET download, no state change.

### Verifying a backup

To confirm a downloaded archive is valid:

```bash
tar tzf doctis_git_backup_YYYYMMDD_HHMMSS.tar.gz | head -20
# Should list  ./example.git/HEAD  ./example.git/objects/... etc.

# Test-restore into a temp directory:
mkdir /tmp/git-restore && tar xzf doctis_git_backup_*.tar.gz -C /tmp/git-restore
git --git-dir=/tmp/git-restore/example.git log --oneline -5
```

---

## 9. UI — Buttons on `manage_overview_page.php`

Add a new widget **below** the existing Site Information widget, visible only
to administrators.  The six actions are grouped by risk level:

```php
<?php if( current_user_is_administrator() ) { ?>
<div class="col-md-12 col-xs-12">
    <div class="space-10"></div>
    <div class="widget-box widget-color-orange">
    <div class="widget-header widget-header-small">
        <h4 class="widget-title lighter">
            <?php print_icon( 'fa-wrench', 'ace-icon' ); ?>
            System Operations
        </h4>
    </div>
    <div class="widget-body">
    <div class="widget-main">
        <!-- Config -->
        <a href="manage_config_file_page.php" class="btn btn-sm btn-default">
            <?php print_icon( 'fa-file-text', 'ace-icon' ); ?> Config File
        </a>
        &nbsp;
        <!-- Backups -->
        <a href="manage_db_backup_page.php" class="btn btn-sm btn-default">
            <?php print_icon( 'fa-database', 'ace-icon' ); ?> Backup Database
        </a>
        &nbsp;
        <a href="manage_git_backup_page.php" class="btn btn-sm btn-default">
            <?php print_icon( 'fa-archive', 'ace-icon' ); ?> Backup Git Store
        </a>
        &nbsp;
        <!-- Update -->
        <a href="manage_git_pull_page.php" class="btn btn-sm btn-primary">
            <?php print_icon( 'fa-download', 'ace-icon' ); ?> Git Pull (Update)
        </a>
        &nbsp;
        <!-- Destructive -->
        <a href="manage_db_load_sample_page.php" class="btn btn-sm btn-warning">
            <?php print_icon( 'fa-refresh', 'ace-icon' ); ?> Load Sample Data
        </a>
        &nbsp;
        <a href="manage_db_rebuild_page.php" class="btn btn-sm btn-danger">
            <?php print_icon( 'fa-trash', 'ace-icon' ); ?> Rebuild Database
        </a>
    </div>
    </div>
    </div>
</div>
<?php } ?>
```

Visual risk hierarchy: grey (safe) → blue (reversible update) → amber
(additive but not idempotent) → red (destructive).

---

## 10. Files to Create / Modify

### New PHP pages (12 files)

| File | Description |
|---|---|
| `manage_config_file_page.php` | Config file info + download link + upload form |
| `manage_config_file_download.php` | Streams config_inc.php as a file download |
| `manage_config_file_upload.php` | Receives upload, writes via sudo wrapper |
| `manage_git_pull_page.php` | Git pull confirmation page |
| `manage_git_pull_action.php` | Executes git pull, shows output |
| `manage_db_rebuild_page.php` | DB rebuild confirmation page (red) |
| `manage_db_rebuild_action.php` | Executes rebuild script, shows output |
| `manage_db_load_sample_page.php` | Load sample data confirmation page (amber) |
| `manage_db_load_sample_action.php` | Executes sample data script, shows output |
| `manage_db_backup_page.php` | DB backup info page + download trigger |
| `manage_db_backup_download.php` | Streams mysqldump \| gzip to browser |
| `manage_git_backup_page.php` | Git backup info page + download trigger |
| `manage_git_backup_download.php` | Streams tar czf of bare repos to browser |

### New shell scripts (3 files)

| File | Description |
|---|---|
| `admin/tools/doctis-write-config.sh` | Writes config_inc.php from stdin (with backup) |
| `admin/tools/doctis-backup-database.sh` | Runs mysqldump with appropriate flags |

### Modified files

| File | Change |
|---|---|
| `manage_overview_page.php` | Add System Operations widget (admin-only) |
| `/etc/sudoers.d/doctis-web` | New file on vaio — manual, not in git |

---

## 11. One-Time Infrastructure Setup (vaio, manual)

```bash
# 1. Create sudoers file
sudo tee /etc/sudoers.d/doctis-web << 'EOF'
www-data ALL=(hcr) NOPASSWD: /usr/bin/git -C /var/www/html/doctis pull
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-write-config.sh
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh
www-data ALL=(hcr) NOPASSWD: /var/www/html/doctis/admin/tools/doctis-backup-database.sh
EOF

# 2. Verify syntax
sudo visudo -c -f /etc/sudoers.d/doctis-web

# 3. Make all wrapper scripts executable
chmod +x /var/www/html/doctis/admin/tools/doctis-write-config.sh
chmod +x /var/www/html/doctis/admin/tools/doctis-backup-database.sh

# 4. Smoke-test each sudo rule as www-data:
# Config write (non-destructive — uses backup, but restores itself)
sudo -u www-data bash -c 'echo "<?php # test" | sudo -u hcr /var/www/html/doctis/admin/tools/doctis-write-config.sh'

# Git pull
sudo -u www-data sudo -u hcr /usr/bin/git -C /var/www/html/doctis status

# DB backup (just check it produces output)
sudo -u www-data bash -c 'sudo -u hcr /var/www/html/doctis/admin/tools/doctis-backup-database.sh | head -5'

# Git backup (no sudo needed — www-data owns /var/git/doctis/)
sudo -u www-data bash -c 'tar czf - -C /var/git/doctis . | wc -c'
```

---

## 12. Implementation Order

1. **Sudoers setup** on vaio — all sudo-dependent features depend on this
2. **Shell scripts** — write and test `doctis-write-config.sh` and
   `doctis-backup-database.sh` manually before wiring into PHP
3. **Feature 6 (git backup)** — simplest: no sudo, no state change, just tar
4. **Feature 5 (DB backup)** — read-only, but needs sudo; good early test of the sudo rules
5. **Feature 1 (config download)** — read-only, no sudo; then add the upload half
6. **Feature 2 (git pull)** — reversible if the remote is stable
7. **Feature 4 (load sample data)** — additive; test on a freshly rebuilt DB
8. **Feature 3 (DB rebuild)** — most destructive; implement last
9. **UI widget** — add to `manage_overview_page.php` once all action pages exist

---

## 13. Open Questions

- **Output streaming for long-running operations?** The DB rebuild may take
  5–10 seconds. `exec()` buffers all output until completion. If this feels
  slow, replace with `proc_open` + chunked `fread`/`flush` loop (same pattern
  used for the streaming downloads). For now, the browser spinner is sufficient.

- **Audit log?** These operations leave no Doctis-level audit trail. Consider
  writing `error_log( 'ADMIN: ' . current_user_get_field('username') . ' executed X at ' . date('c') )` in each action page, which leaves a breadcrumb in `/var/log/apache2/error.log`.

- **Config backup cleanup?** The `doctis-write-config.sh` script accumulates
  `.bak.YYYYMMDD_HHMMSS` files in `config/`. After a few uploads these pile
  up. Either keep the most recent N (add a `find … -delete` line to the
  script) or accept manual cleanup — the files are small.

- **Disk space guard for backups?** The server is at 87% disk usage. Both
  backups stream directly to the browser without touching disk, so this is
  not a concern for Features 5 and 6 as implemented. No guard needed.
