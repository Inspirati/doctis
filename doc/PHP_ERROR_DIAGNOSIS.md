# Diagnosing PHP errors in Doctis

Log file locations, how MantisBT's exception handler behaves, the
Manage → System Operations → PHP Error Log page, and the error signatures
that recur with the git storage backend. Split out of CLAUDE.md on
2026-10-30.

---

## Log file locations

```bash
# PHP errors, warnings and exceptions logged by MantisBT's handler (PHP-FPM error_log)
tail -50 /var/log/doctis/php_errors.log

# nginx errors (FastCGI failures, forbidden paths) and requests with HTTP status
sudo tail -50 /var/log/nginx/doctis_error.log
sudo tail -20 /var/log/nginx/doctis_access.log

# Cron output: email flush and meeting scheduler
tail -50 /var/log/doctis/cron.log
```

MantisBT's own logging (`$g_log_level`) is off unless `LOG_LEVEL` is set in the
PHP-FPM pool (`env[LOG_LEVEL]`; FPM does not pass the shell environment); it
then writes to `/var/log/doctis/mantis.log`. There is no Xdebug on the VM.

Manage → System Operations → **PHP Error Log** (`manage_php_error_log_page.php`,
administrators only) shows the newest entries of whatever file PHP logs to,
with a text filter. It also shows whether `display_errors` is on. It is the
only way to read PHP errors on live, which has no console.

## How MantisBT handles exceptions

MantisBT catches PHP exceptions in its error handler. With
`$g_show_detailed_errors = ON` it renders a full HTML stack trace in the
browser response. The VM's `config/config_inc.php` does not set it (default
OFF); add it temporarily when an `APPLICATION ERROR #0` page needs diagnosing.

The stack trace shows filename, line number, class, function, and all arguments
at each frame, including the contents of arrays passed to the failing function.
This is often enough to identify the exact error without adding debug logging.

MantisBT also calls `error_log()` in its exception handler, so caught
exceptions appear in `/var/log/doctis/php_errors.log` with a condensed stack
trace on one line.

## Adding temporary debug logging

When the HTML error page doesn't show enough detail (e.g. inside git operations
where exceptions are caught and re-thrown), use `error_log()`:

```php
error_log( 'MyClass::method() reached with var=' . $t_var );
```

Output goes to `/var/log/doctis/php_errors.log`. Remove all `error_log()`
calls before committing.

## Typical error sources for the GIT backend

| Symptom | Likely cause | Where to look |
|---------|-------------|---------------|
| `Class "ServiceException" not found` | Missing `use Mantis\Exceptions\ServiceException;` in a `.class.php` file | Top of the failing backend class |
| `Author identity unknown` / exit-code 128 | `GIT_AUTHOR_*` env vars not set; `ensure_git_home()` now sets them from gitconfig | `GitFileStorageBackend::ensure_git_home()` |
| `git commit` exit-code 1 | Nothing to commit (duplicate file content) | Handled gracefully; returns existing HEAD SHA |
| `An error occurred during this action` | MantisBT caught an exception; check browser for stack trace | Browser HTML output |
| `APPLICATION ERROR #0` with `trigger_error` | A switch on `file_upload_method` is missing a `GIT` case | The file and line shown in the stack trace |
| `Cannot use object of type DwgData as array` | `dwg_get()` returns a `DwgData` object — use `->property`, not `['key']` | The file and line in the stack trace |
| `Undefined array key 0` on `list(...)=backend->store()` | `store()` returns named-key array; use `$r['diskfile']` etc., not positional `list()` | Caller of `store()` |
