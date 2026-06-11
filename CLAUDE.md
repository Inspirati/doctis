# CLAUDE.md — Doctis Project Guide

## What is Doctis?

Doctis (**Doc**ument **I**ssue **T**racking **S**ystem) is a fork of [MantisBT](https://github.com/mantisbt/mantisbt) v2.27.x extended to track **documents** and the issues raised against them during formal review cycles. It is a PHP/MariaDB web application.

The project is actively developed (beta) and maintained on the `dev` branch, tracking MantisBT upstream via the `upstream_sync` branch.

## Repository Layout

```
/                           PHP page entry-points (bug_*, dwg_*, manage_*, login_*, etc.)
core/                       API library functions (*_api.php, classes/, commands/, exceptions/)
api/rest/restcore/          Slim-framework REST endpoints (MantisBT standard; no dwg routes yet)
api/rest/restcore/issues_rest.php  — issues (bugs) REST; no document equivalent
config/                     Local config (config_inc.php, not committed)
config_defaults_inc.php     All defaults including Doctis-specific $g_dwg_* settings
admin/                      Admin/diagnostic PHP scripts (db_stats.php, test_langs.php, etc.)
admin/tools/                Install/setup shell scripts
doc/                        Design and planning documents for Doctis-specific features
plugins/                    MantisBT plugin system (Gravatar, MantisGraph, etc.)
lang/strings_english.txt    All UI strings including Doctis additions
tests/                      PHPUnit + REST/SOAP integration tests
docker/                     Legacy — remote deployment only, not used for active development
docker-live/                Legacy — remote deployment only, not used for active development
docker-test/                Legacy — remote deployment only, not used for active development
```

## Key Naming Conventions

| Term | Meaning |
|------|---------|
| `bug` / `issue` | A tracked issue (MantisBT original) |
| `dwg` / `document` | A tracked document (Doctis addition); "dwg" = drawing, avoids collision with reserved words |
| `doc` | **Avoid** — overloaded keyword |
| `license` | Doctis-specific: a skill, security clearance, or professional qualification held by a registered user; controls document access |

Variable prefixes (inherited from MantisBT):

- `g_` — global
- `p_` — function parameter (do not modify inside function)
- `f_` — form variable
- `c_` — sanitised for DB insertion
- `t_` — temporary

Tabs for indentation, spaces for alignment. Files edited at 4-column tab width.

## Primary Goal: Minimise Diff with MantisBT

Doctis deliberately avoids renaming MantisBT variables and follows upstream structure closely so that periodic upstream syncs (via `upstream_sync` branch) remain feasible. When adding Doctis functionality, prefer **parallel** files/functions rather than in-place edits to shared MantisBT code.

## Document (dwg) Entity

Documents are stored in a dedicated `documents` table alongside the MantisBT `bugs` table. Key Doctis-specific APIs:

| File | Purpose |
|------|---------|
| [core/dwg_api.php](core/dwg_api.php) | Core document CRUD (mirrors `bug_api.php`) |
| [core/document_api.php](core/document_api.php) | Document/category support functions |
| [core/license_api.php](core/license_api.php) | License (review programme) management |
| [core/filter_dwg_api.php](core/filter_dwg_api.php) | Document filter logic |
| [core/columns_dwg_api.php](core/columns_dwg_api.php) | Column display for document list |
| [core/email_dwg_api.php](core/email_dwg_api.php) | Email notifications for documents |
| [core/classes/DwgFilterQuery.class.php](core/classes/DwgFilterQuery.class.php) | DB query builder for dwg filters |
| [core/commands/DwgAddCommand.php](core/commands/DwgAddCommand.php) | Command layer for adding documents |

Document-specific config keys (in [config_defaults_inc.php](config_defaults_inc.php)):
- `$g_dwg_status_enum_string` — custom workflow status enum (110:pending … 195:archived)
- `$g_dwg_submit_status`, `$g_dwg_assigned_status`, `$g_dwg_reopen_status`, etc.
- `$g_dwg_report_page_fields`, `$g_dwg_view_page_fields`, `$g_dwg_update_page_fields`

## License Entity

A Doctis license is a skill, security clearance, or professional qualification
registered against a user account. Documents can require users to hold one or
more licenses before access is granted. This is entirely unrelated to software
licensing; the term is Doctis-specific.

API: [core/license_api.php](core/license_api.php). Command layer: [core/commands/License*Command.php](core/commands/).

## Development Environment

Doctis runs directly under Apache on **vaio** (`10.0.0.10`). There is one
running instance; it serves as both the development and local test environment.

| Item | Detail |
|------|--------|
| Apache webroot | `/var/www/html/doctis/` on vaio |
| URL | `http://10.0.0.10/doctis/` |
| Database | MariaDB on `localhost`, database `testdb`, user `admin` |
| NFS mount (dev machine) | `/home/hcr/html/doctis/` → vaio:`/var/www/html/doctis/` |

Edit PHP files locally via the NFS mount; changes are immediately live on
vaio's Apache — no sync or restart required.

Commands that must run in vaio's environment (Composer installs, git
infrastructure setup, schema migrations) are executed via SSH:

```bash
ssh hcr@vaio "<command>"
ssh hcr@vaio "sudo -u www-data <git-command>"
```

Because `admin/` is NFS-mounted on vaio, scripts placed there can be run
directly without a copy step:

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/<script>.php"
```

**When development moves to a new host:** all vaio-specific infrastructure
(git storage directories, `.gitconfig`, Composer vendor/) must be recreated on
the new host from scratch. Follow [doc/doctis-git-server-setup.txt](doc/doctis-git-server-setup.txt)
in full, then run the PHP integration test before resuming development.

## Diagnosing PHP/Apache Errors on Vaio

Understanding the error reporting stack is essential. There are three distinct
places errors appear, with different information in each.

### Log file locations

```bash
# PHP exceptions caught by MantisBT — most useful for application errors
ssh hcr@vaio "sudo tail -50 /var/log/apache2/error.log | grep -v Xdebug"

# PHP warnings/notices not caught by MantisBT
ssh hcr@vaio "sudo tail -50 /var/log/apache2/php_error.log | grep -v imap"

# HTTP status codes — confirms which URL/script is failing
ssh hcr@vaio "sudo tail -20 /var/log/apache2/access.log"
```

### How MantisBT handles exceptions

MantisBT catches PHP exceptions in its error handler and, when
`$g_show_detailed_errors = ON` (set in `config/config_inc.php`), renders a full
HTML stack trace in the browser response. This is the primary diagnostic for
`APPLICATION ERROR #0` pages — read the browser output, not just the logs.

The stack trace shows filename, line number, class, function, and all arguments
at each frame, including the contents of arrays passed to the failing function.
This is often enough to identify the exact error without adding debug logging.

MantisBT also calls `error_log()` in its exception handler, so caught exceptions
appear in `/var/log/apache2/error.log` as `[php:notice]` entries with a
condensed stack trace on one line.

### Xdebug noise

Xdebug is installed on vaio but no step-debug listener is running. This produces
harmless `[php:notice] Xdebug: [Step Debug] Could not connect to debugging
client` messages in the Apache error log for every request. Filter them out
with `grep -v Xdebug` on every log read.

### Adding temporary debug logging

When the HTML error page doesn't show enough detail (e.g. inside git operations
where exceptions are caught and re-thrown), use `error_log()`:

```php
error_log( 'MyClass::method() reached with var=' . $t_var );
```

Output goes to `/var/log/apache2/error.log`. Remove all `error_log()` calls
before committing.

### Typical error sources for the GIT backend

| Symptom | Likely cause | Where to look |
|---------|-------------|---------------|
| `Class "ServiceException" not found` | Missing `use Mantis\Exceptions\ServiceException;` in a `.class.php` file | Top of the failing backend class |
| `Author identity unknown` | Apache didn't set `HOME`; `ensure_git_home()` not called | `GitFileStorageBackend::store()` or `delete()` |
| `git commit` exit-code 128 | Same as above — identity missing | Apache error log stderr capture |
| `git commit` exit-code 1 | Nothing to commit (duplicate file content) | Handled gracefully; returns existing HEAD SHA |
| `An error occurred during this action` | MantisBT caught an exception; check browser for stack trace | Browser HTML output |
| `APPLICATION ERROR #0` with `trigger_error` | A switch on `file_upload_method` is missing a `GIT` case | The file and line shown in the stack trace |

## Upstream Sync Process

Upstream MantisBT changes flow in via the `upstream_sync` branch, then merged into `dev`. The `original` and `original-as-forked` branches serve as before-fork reference points.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

REST/SOAP integration tests live under `tests/`. Bootstrap: `tests/bootstrap.php.sample` → copy to `tests/bootstrap.php` and configure.

## Config File

Never commit `config/config_inc.php`. Use `config/config_inc.php.sample` as the template. Override defaults from [config_defaults_inc.php](config_defaults_inc.php) there.

## Claude Memory — Project-Local Storage

Claude's auto-memory system writes to `~/.claude/projects/<path>/memory/` on
the current machine, which is user- and machine-specific. To make memory
portable across machines and user accounts, this project stores its canonical
memory in:

```
.claude/memory/MEMORY.md              index (read this first)
.claude/memory/project_doctis_overview.md
.claude/memory/user_role.md
```

**REQUIRED — session start:** You MUST read `.claude/memory/MEMORY.md` and
every file it lists before responding to any request. This is the authoritative
project memory and replaces whatever `~/.claude/projects/...` may contain.

**REQUIRED — saving memories:** Write all new memories to `.claude/memory/`
using the standard frontmatter format (`name`, `description`, `type`), and
update `.claude/memory/MEMORY.md`. Never write only to `~/.claude/projects/...`;
that path is machine-local and will not survive a move to another host.

The `.claude/` directory is intentionally not committed to git — it contains
conversation history files alongside the memory `.md` files.

## MantisBT Autoloader and Namespace Rules

These are critical to get right when adding new classes.

**Autoloader** (`autoload_mantis` in `core.php`) maps class name suffixes to paths:

| Class name suffix | Loaded from |
|-------------------|-------------|
| `Command` | `core/commands/<Name>.php` |
| `Exception` | `core/exceptions/<Name>.php` |
| anything else | `core/classes/<Name>.class.php` (flat — no subdirectory) |

**Namespace rule:** `ServiceException` and `ClientException` live in
`namespace Mantis\Exceptions`. Any `.class.php` file that throws these MUST
have explicit `use` statements at the top:

```php
use Mantis\Exceptions\ClientException;
use Mantis\Exceptions\ServiceException;
```

Without these, the autoloader loads the file (defining
`Mantis\Exceptions\ServiceException`) but the unqualified name
`ServiceException` remains undefined in the calling file's namespace. The
runtime error is `Class "ServiceException" not found` — it appears in the
Apache error log and takes effect only when the exception is first thrown, not
at class load time.

See `core/commands/DwgAddCommand.php` for the canonical correct pattern.

## File Storage Backend Architecture

The file storage system supports three methods, selected by `$g_file_upload_method`:

| Constant | Value | Backend class | Stores in |
|----------|-------|--------------|-----------|
| `DISK` | 1 | `DiskFileStorageBackend` | Server filesystem |
| `DATABASE` | 2 | `DatabaseFileStorageBackend` | `{dwg_file}.content` BLOB |
| `GIT` | 3 | `GitFileStorageBackend` | Per-project bare git repo |

**Interface:** [core/classes/FileStorageBackendInterface.class.php](core/classes/FileStorageBackendInterface.class.php)

```
store(tmp_file, size, unique_name, file_path, browser_upload, metadata[]) → [diskfile, folder, content]
retrieve(row, project_id) → [type, content] | false
delete(diskfile, project_id, metadata[]) → void
```

**Factory:** `file_dwg_get_storage_backend()` in [core/file_dwg_api.php](core/file_dwg_api.php)

**Scope:** GIT is a document-only backend. Bug/bugnote attachments (`file_api.php`)
always fall back to DATABASE when `GIT` is configured. This is enforced by
fall-through case statements in `file_api.php`.

### GIT backend specifics

**Repository layout per project:**
```
/var/git/doctis/<slug>.git        bare repo (authoritative store)
/var/www/doctis/worktrees/<slug>  working tree (write staging area)
```

**File path within repo:** `<dwg_id>/<filename>` (e.g. `4/report.pdf`)

**`{dwg_file}` columns used by GIT backend:**

| Column | GIT value |
|--------|-----------|
| `diskfile` | full commit SHA (40 hex chars) |
| `folder` | absolute path to the bare repo |
| `content` | empty string |

**`{dwg_file}` schema note:** the table has NO `project_id` column. Project ID
must always be derived via `dwg_get_field($dwg_id, 'project_id')`. The original
MantisBT code had a latent bug here that was fixed when implementing the GIT
backend.

**Push is mandatory:** `git commit` only writes to the working tree's local
history. The bare repo receives nothing until `git push` is called.
`GitFileStorageBackend::store()` always pushes as its final step.

**Apache HOME fix:** Apache does not set `HOME` for `www-data` worker processes,
so git cannot find `/var/www/.gitconfig`. `GitFileStorageBackend::ensure_git_home()`
calls `putenv('HOME=...')` using `posix_getpwuid()` before every git operation.
This is transparent at runtime but explains why manual SSH tests (`sudo -u www-data`)
always work while Apache requests failed before the fix was in place.

**Branch name:** `init.defaultBranch = main` in `/var/www/.gitconfig` ensures
new repos use `main`. The PHP backend calls `getCurrentBranchName()` before
every push rather than hardcoding `'main'`, so both `main` and `master` repos
are handled correctly. See [doc/doctis-git-server-setup.txt](doc/doctis-git-server-setup.txt)
Step 5a for how to rename an existing `master` branch.

**Soft delete:** `git rm` + commit + push. The file disappears from HEAD but
the full commit history is retained in the bare repo.

**Duplicate content:** if `git commit` exits 1 (nothing to commit — identical
content re-uploaded), the backend treats it as a successful store and returns
the existing HEAD SHA.

### Files modified to support GIT method

Beyond the new backend classes, every existing file that switches on
`file_upload_method` needed a `GIT` case:

| File | What was added |
|------|----------------|
| [core/constant_inc.php](core/constant_inc.php) | `define('GIT', 3)` |
| [config_defaults_inc.php](config_defaults_inc.php) | `$g_git_storage_root`, `$g_git_worktree_root` defaults |
| [core/file_dwg_api.php](core/file_dwg_api.php) | Factory `file_dwg_get_storage_backend()`, updated `file_dwg_add()` / `file_dwg_get_content()` / `file_dwg_delete()` |
| [core/file_api.php](core/file_api.php) | GIT falls through to DATABASE in upload and retrieve switches (bug attachments unaffected) |
| [core/print_dwg_api.php](core/print_dwg_api.php) | GIT case in `print_dwg_attachment_preview_text()` |
| [file_download.php](file_download.php) | GIT case in MIME detection and content output; `require_api('file_dwg_api.php')` added |

### Integration test

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php"
```

Source: [admin/test-git-php.php](admin/test-git-php.php) — self-contained 15-step
test (init, clone, add, commit, push, retrieve-by-SHA, HEAD retrieve, SHA256
integrity, soft-delete, history retention). Creates and tears down its own
temporary repos. Must pass before enabling `GIT` on any new server.

### Server infrastructure status (vaio) — current state

| Item | Status |
|------|--------|
| `/var/git/doctis/` | Created; `www-data:www-data`, mode `2770` |
| `/var/www/doctis/worktrees/` | Created; `www-data:www-data`, mode `2770` |
| `/var/www/.gitconfig` | Written as root; `www-data` identity set (`doctis@vaio.local`, `init.defaultBranch=main`) |
| `czproject/git-php v4.4.0` | Installed via Composer into `vendor/` |
| `example` project bare repo | `/var/git/doctis/example.git` — live data, `main` branch |
| `example` project worktree | `/var/www/doctis/worktrees/example` — `main` branch, tracking `origin/main` |
| `$g_file_upload_method` | Set to `GIT` in `config/config_inc.php` — active on vaio |

## Known Architectural Trade-offs

1. **Filter hacks** — [core/filter_api.php](core/filter_api.php) lines 101, 1156, 1192 contain admitted "quick'n'dirty" hacks to route document queries through the bug filter pipeline without changing all call sites.
2. **`document_api.php`** — currently a thin wrapper; some category-style logic was copied from `category_api.php` and has a stale file header.
3. **REST API** — no dwg/document/license REST endpoints exist yet; Commands are used internally only.
4. **Bulk import** — no UI; must use `phpMyAdmin` or CLI directly.
5. **`file_upload_method` is global** — one config key controls both bug and document file storage. Setting it to `GIT` causes bug attachments to fall back silently to DATABASE. This is intentional for now but means bug attachments do not benefit from git versioning.
6. **`file_api.php` switch statements** — MantisBT's original bug attachment code has multiple `switch($file_upload_method)` blocks. Each new storage method requires a case in all of them. Discovered locations: upload (around line 1009), MIME detection (line 1314), content output (`file_download.php` line 238). Search for `file_upload_method` when adding future methods.
7. **GIT config written before infrastructure exists** — `install-target.sh`:`configure_target()` writes `$g_dwg_upload_method = GIT` (and the storage paths) into `config_inc.php` at config-generation time, before `install_git_storage()` has run. If `install_git_storage()` subsequently fails (e.g. git not installed, permission error creating `/var/git/doctis/`), the config will advertise GIT but the bare repos and worktrees will not exist. Document file uploads will then error at runtime. **Future hardening:** `install_git_storage()` should revert those three config keys to their DATABASE defaults if it exits non-zero.
