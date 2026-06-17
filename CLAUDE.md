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

### Variable and parameter names in `dwg_*` modules — intentionally unchanged

Most `dwg_*` modules were created by duplicating a MantisBT module of similar
functionality and then making the minimum changes needed: function names were
renamed (adding a `dwg_` prefix, `_dwg` suffix, or equivalent), but internal
parameter and variable names were deliberately left as-is.

This means names like `$p_bug_id`, `$t_bug`, `$p_issue_id`, or `$t_file_id`
appear inside document-specific functions where "bug" and "issue" refer to a
document, not an issue. **These names are misleading when read in isolation, but
the inconsistency is intentional.**

The rationale is maintainability over readability: keeping internal names
unchanged minimises the diff against the original MantisBT source file, making
it straightforward to compare the two with `diff` or `git diff` and identify
which changes are Doctis-specific. This is expected to significantly reduce the
effort required to incorporate future MantisBT upstream changes into the
parallel `dwg_*` equivalents.

When reading or modifying a `dwg_*` module, treat `$p_bug_id` / `$p_issue_id`
/ `$t_file_id` etc. as referring to whatever the document-domain equivalent is
(dwg_id, primary file row, etc.) — the type is determined by context, not by
the name.

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
| Database | MariaDB on `localhost`, database `doctis`, user `admin` |
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
| `Author identity unknown` / exit-code 128 | `GIT_AUTHOR_*` env vars not set; `ensure_git_home()` now sets them from gitconfig | `GitFileStorageBackend::ensure_git_home()` |
| `git commit` exit-code 1 | Nothing to commit (duplicate file content) | Handled gracefully; returns existing HEAD SHA |
| `An error occurred during this action` | MantisBT caught an exception; check browser for stack trace | Browser HTML output |
| `APPLICATION ERROR #0` with `trigger_error` | A switch on `file_upload_method` is missing a `GIT` case | The file and line shown in the stack trace |
| `Cannot use object of type DwgData as array` | `dwg_get()` returns a `DwgData` object — use `->property`, not `['key']` | The file and line in the stack trace |
| `Undefined array key 0` on `list(...)=backend->store()` | `store()` returns named-key array; use `$r['diskfile']` etc., not positional `list()` | Caller of `store()` |

## Upstream Sync Process

Upstream MantisBT changes flow in via the `upstream_sync` branch, then merged into `dev`. The `original` and `original-as-forked` branches serve as before-fork reference points.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

REST/SOAP integration tests live under `tests/`. Bootstrap: `tests/bootstrap.php.sample` → copy to `tests/bootstrap.php` and configure.

## Resetting to a Clean Slate for Testing

A full clean-room reset wipes both the MariaDB database and the git document
store, then reinstalls the schema and reloads example data.  This is required
before any test session that must start from a known-empty state.

**Always run both steps together** — the database and the git store reference
each other.  Running one without the other leaves them out of sync.

### Step 1 — Reset the git document store

The script must be run as root (it touches `/var/git/doctis/` and
`/var/www/doctis/worktrees/`).  It prompts for confirmation when invoked
directly:

```bash
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"
```

Expected output ends with: `Git store reset: N item(s) removed.`

### Step 2 — Drop, recreate, and install the database

```bash
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"
```

Expected output includes:
- `✔ doctis database install successful.`
- `Example data loaded.`
- `Database doctis loaded.`

A timestamped HTML log of the installer output is saved to
`admin/tools/doctis_install_<YYYYMMDD_HHMMSS>.html` (excluded from git via
`.gitignore`).

### Verifying the reset

After both steps, confirm the state is clean:

```bash
# Database: should show only the example project and test users
ssh hcr@vaio "mysql -e 'SELECT id, name FROM doctis.project; SELECT id, username FROM doctis.user;'"

# Git store: directories should be absent or empty
ssh hcr@vaio "ls /var/git/doctis/ /var/www/doctis/worktrees/ 2>&1"
```

## Curl-Based Live Testing

Use `curl` to exercise the live vaio instance programmatically when browser
access is not possible. All state lives in a cookie jar file.

### One-time setup — login

MantisBT login is a two-step POST. Do both in sequence:

```bash
# Step 1 — submit username; server redirects to password page
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/login_password_page.php" \
  -d "username=manager&return=index.php" -o /dev/null

# Step 2 — submit password (blank for the default manager account)
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/login.php" \
  -d "username=manager&password=&return=index.php&secure_session=0" -o /dev/null
```

Verify success by fetching any authenticated page and grepping for the username:

```bash
curl -s -L -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  "http://10.0.0.10/doctis/my_view_dwg_page.php" | grep -o 'manager'
```

### Project context

Pages that require a project selected will redirect to
`login_select_proj_page.php`, which in turn redirects to
`set_project.php?project_id=1&ref=<target>`. Follow redirects with `-L` to
handle this transparently:

```bash
curl -s -L -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  "http://10.0.0.10/doctis/dwg_create_page.php" -o /tmp/page.html
```

### Reading CSRF tokens from a page

Every MantisBT form embeds a `form_security_field()` token. Extract it before
POSTing:

```bash
TOKEN=$(grep -oP 'name="dwg_report_token" value="\K[^"]+' /tmp/page.html)
# general pattern:
TOKEN=$(grep -oP 'name="<token_name>" value="\K[^"]+' /tmp/page.html)
```

Token names follow the pattern `<action>_token`, e.g.:
- `dwg_report_token` — document create form
- `dwg_primary_file_update_token` — primary document upload on view page

### Creating a document

```bash
curl -s -L -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  "http://10.0.0.10/doctis/dwg_create_page.php" -o /tmp/create.html
TOKEN=$(grep -oP 'name="dwg_report_token" value="\K[^"]+' /tmp/create.html)

curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/dwg_create.php" \
  -F "dwg_report_token=${TOKEN}" \
  -F "m_dwg_id=0" -F "project_id=1" -F "category_id=0" \
  -F "dwg_title=Test Document" -F "dwg_author=Test Author" \
  -F "dwg_reference=REF-001" -F "dwg_number=12345" \
  -F "dwg_edition=Ed 1" -F "dwg_revision=Rev A" \
  -F "dwg_publisher=Test" -F "dwg_discipline=Testing" \
  -F "dwg_classification=UNCLASSIFIED" \
  -F "view_state=10" -F "description=Test" -F "dwg_entry_stay=0" \
  -v 2>&1 | grep "Location:"
# Successful create redirects to dwg_view.php?id=<N>
```

To include a primary document file on create, add:

```bash
  -F "primary_document_file=@/tmp/myfile.pdf;type=application/pdf;filename=myfile.pdf" \
  -F "primary_document_description=Initial revision"
```

### Uploading/replacing a primary document (view page)

```bash
curl -s -L -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  "http://10.0.0.10/doctis/dwg_view.php?id=<N>" -o /tmp/view.html
UPLOAD_TOKEN=$(grep -oP 'dwg_primary_file_update[^"]*" value="\K[^"]+' /tmp/view.html)

curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/dwg_primary_file_update.php" \
  -F "dwg_primary_file_update_token=${UPLOAD_TOKEN}" \
  -F "dwg_id=<N>" \
  -F "primary_document_file=@/tmp/myfile.pdf;type=application/pdf;filename=myfile.pdf" \
  -F "primary_document_description=New revision" \
  -v 2>&1 | grep "Location:"
# Redirects to dwg_view.php?id=<N> on success
```

### Downloading a primary document

```bash
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  "http://10.0.0.10/doctis/file_download.php?type=dwg_primary&id=<N>"
```

### Diagnosing curl failures

- **0-byte response** — the page redirected without `-L`; add `-L` to follow
- **302 to `login_select_proj_page.php`** — project cookie missing; follow the
  redirect chain with `-L` and vaio will auto-select project 1
- **HTTP 500 with `<p>error message</p>`** — extract with:
  `grep -oP "(?<=<p>)[^<]+(?=</p>)" /tmp/result.html`
- **Empty `Location:` header** — POST was rejected (CSRF mismatch, access
  denied, or validation error); save `-o /tmp/result.html` and inspect body
- **Git `Author identity unknown`** — `ensure_git_home()` in
  `GitFileStorageBackend` sets `GIT_AUTHOR_*` env vars; check gitconfig at
  `/var/www/.gitconfig` exists and is readable by `www-data`

## SOAP API Testing and Diagnostics

The Doctis SOAP API is the primary programmatic interface for document
operations.  Use raw `curl` SOAP calls to exercise and diagnose individual
endpoints without requiring a browser or a configured SoapClient.

### Smoke test — all Doctis SOAP endpoints in one command

```bash
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh"
```

Runs 13 tests covering every Doctis-specific SOAP endpoint (enum, document
fetch, primary file lifecycle, attachment lifecycle).  Non-fatal — all
steps run even if earlier ones fail.  Idempotent — cleans up leftover state
at the start.  Exit code 0 = all pass.  Full description in
[admin/tools/README.md](admin/tools/README.md).

Override defaults to test a specific document or user:

```bash
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh \
  http://10.0.0.10/doctis manager '' 3"
#                           host   user  pw  dwg_id
```

### SOAP endpoint and WSDL

```
SOAP endpoint : http://10.0.0.10/doctis/api/soap/mantisconnect.php
WSDL          : http://10.0.0.10/doctis/api/soap/mantisconnect.php?wsdl
```

List all available operations (useful to confirm new endpoints appear after
a WSDL change):

```bash
curl -s "http://10.0.0.10/doctis/api/soap/mantisconnect.php?wsdl" \
  | grep -oP 'operation name="\K[^"]+' | sort
```

Look up parameter names for a specific method:

```bash
curl -s "http://10.0.0.10/doctis/api/soap/mantisconnect.php?wsdl" \
  | grep -A8 '"mc_dwg_primary_uploadRequest"'
```

### Raw curl SOAP call template

Every SOAP call needs two headers and an XML envelope:

```bash
curl -s \
  -H "Content-Type: text/xml; charset=utf-8" \
  -H 'SOAPAction: ""' \
  --data '<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope
    xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body>
    <man:OPERATION_NAME>
      <username>manager</username>
      <password></password>
      PARAMETERS
    </man:OPERATION_NAME>
  </soapenv:Body>
</soapenv:Envelope>' \
  "http://10.0.0.10/doctis/api/soap/mantisconnect.php"
```

### Useful diagnostic one-liners

**Connectivity / version check:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_version><username>manager</username><password></password></man:mc_version></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.10/doctis/api/soap/mantisconnect.php" | grep -oP '(?<=<return[^>]*>)[^<]+'
```

**List all document status values:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_enum_dwg_status><username>manager</username><password></password></man:mc_enum_dwg_status></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.10/doctis/api/soap/mantisconnect.php" \
  | grep -oP '(?<=<name xsi:type="xsd:string">)[^<]+'
```

**Fetch a document by id (note: parameter is `issue_id`):**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_dwg_get><username>manager</username><password></password><issue_id>2</issue_id></man:mc_dwg_get></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.10/doctis/api/soap/mantisconnect.php" \
  | grep -oP '(?<=<title[^>]*>)[^<]+'
```

**Check primary file metadata for a document:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_dwg_primary_get><username>manager</username><password></password><dwg_id>2</dwg_id></man:mc_dwg_primary_get></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.10/doctis/api/soap/mantisconnect.php"
# Returns empty PrimaryFileData if no file; filename/filesize/file_type/download_url if present
```

### base64Binary encoding for file upload/download

PHP's SoapServer **pre-decodes** `xsd:base64Binary` input parameters before
passing them to the PHP handler.  PHP's SoapClient compensates by
double-encoding.  Raw `curl` callers must therefore also double-encode:

```bash
# Upload: double-encode the content
CONTENT="file bytes here"
B64=$(printf '%s' "$CONTENT" | base64 -w 0 | base64 -w 0)
# Send <content>${B64}</content> in the SOAP body

# Download (mc_dwg_attachment_get): double-decode the returned value
RETURNED_B64="... value from <return> element ..."
printf '%s' "$RETURNED_B64" | base64 -d | base64 -d
```

This does **not** affect PHP SoapClient or PHPUnit tests — the SoapClient
handles the double-encoding transparently.  It only matters for raw curl
diagnostic calls against base64Binary parameters/returns.

### Verifying git commit attribution after a file operation

After any upload or delete via SOAP (or the web UI), confirm the commit is
attributed to the correct Doctis user:

```bash
ssh hcr@vaio "git --git-dir=/var/git/doctis/example.git \
  log --format='%h %an <%ae> %s' -5"
# Should show the Doctis user's realname and email, not "Doctis <doctis@vaio.local>"
```

### Diagnosing "Procedure not present" SOAP errors

This error means the SoapServer received a call for an operation it has no
registered PHP function for.  Work through in order:

1. **Is the operation in the WSDL?**
   ```bash
   grep 'mc_my_function' /var/www/html/doctis/api/soap/mantisconnect.wsdl
   # Must appear in <message>, <portType>, and <binding> sections
   ```

2. **Clear the WSDL cache** (PHP caches parsed WSDL in `/tmp/wsdl-*`):
   ```bash
   ssh hcr@vaio "sudo rm -f /tmp/wsdl-*"
   ```

3. **Force OPcache recompilation of `mc_core.php`** — the most common cause
   after adding new `require_once` lines.  OPcache caches compiled bytecode
   keyed on file mtime; touching the file forces recompilation on the next
   request:
   ```bash
   ssh hcr@vaio "touch /var/www/html/doctis/api/soap/mc_core.php"
   ```
   Alternatively, adding any whitespace edit and saving achieves the same
   effect via the NFS mount.

4. **Confirm the PHP function is defined** by loading the SOAP stack from
   CLI as the correct user:
   ```bash
   # Write a check script:
   cat > /tmp/check_fns.php << 'EOF'
   <?php
   # Bootstrap MantisBT, then load the SOAP stack
   $t_mantis_dir = '/var/www/html/doctis/';
   require_once $t_mantis_dir . 'core.php';
   require_once $t_mantis_dir . 'api/soap/mc_core.php';
   $fns = array_filter(get_defined_functions()['user'],
       fn($f) => strpos($f, 'mc_dwg') === 0 || $f === 'mc_enum_dwg_status');
   sort($fns);
   echo implode("\n", $fns) . "\n";
   EOF
   ssh hcr@vaio "php /tmp/check_fns.php"
   # If the function is absent, the require_once chain has a gap
   ```

5. **Check Apache error log** for include-time PHP errors that silently abort
   the SOAP bootstrap:
   ```bash
   ssh hcr@vaio "sudo tail -30 /var/log/apache2/error.log | grep -v Xdebug"
   ```

---

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

## AI Assistant Feature

The Doctis AI Assistant (`ai_assist_page.php`) embeds a Claude-powered chat interface
using the Anthropic Messages API.  Before working on any AI-related code, read:

- **[doc/ai-todo.md](doc/ai-todo.md)** — living implementation log: what is built, how
  the pipeline works, configuration reference, known constraints, and the phased to-do
  list (Phases 2–5).  Update this document as work is completed or decisions change.
- **[doc/ai-engine.md](doc/ai-engine.md)** — architecture concept plan and platform
  assessment.  Read before making structural changes to the AI pipeline.

Key files:

| File | Purpose |
|------|---------|
| `ai_assist_page.php` | Page shell: auth, tab layout, chat HTML, inline CSS, `<script src>` |
| `ai_assist_api.php` | AJAX endpoint: validation, cURL to Anthropic, JSON response |
| `js/ai_assist.js` | All client-side JS — **must** be external (CSP `script-src 'self'`) |

The API key (`$g_anthropic_api_key`) is set in `config/config_inc.php` (not committed).
The sidebar button is suppressed entirely when the key is blank.

## Known Architectural Trade-offs

1. **Filter hacks** — [core/filter_api.php](core/filter_api.php) lines 101, 1156, 1192 contain admitted "quick'n'dirty" hacks to route document queries through the bug filter pipeline without changing all call sites.
2. **`document_api.php`** — currently a thin wrapper; some category-style logic was copied from `category_api.php` and has a stale file header.
3. **REST API** — no dwg/document/license REST endpoints exist yet; Commands are used internally only.
4. **Bulk import** — no UI; must use `phpMyAdmin` or CLI directly.
5. **`file_upload_method` is global** — one config key controls both bug and document file storage. Setting it to `GIT` causes bug attachments to fall back silently to DATABASE. This is intentional for now but means bug attachments do not benefit from git versioning.
6. **`file_api.php` switch statements** — MantisBT's original bug attachment code has multiple `switch($file_upload_method)` blocks. Each new storage method requires a case in all of them. Discovered locations: upload (around line 1009), MIME detection (line 1314), content output (`file_download.php` line 238). Search for `file_upload_method` when adding future methods.
7. **GIT config written before infrastructure exists** — `install-target.sh`:`configure_target()` writes `$g_dwg_upload_method = GIT` (and the storage paths) into `config_inc.php` at config-generation time, before `install_git_storage()` has run. If `install_git_storage()` subsequently fails (e.g. git not installed, permission error creating `/var/git/doctis/`), the config will advertise GIT but the bare repos and worktrees will not exist. Document file uploads will then error at runtime. **Future hardening:** `install_git_storage()` should revert those three config keys to their DATABASE defaults if it exits non-zero.
