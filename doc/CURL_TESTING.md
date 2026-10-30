# Driving Doctis with curl

How to exercise a running Doctis instance programmatically when browser
access is not possible: the two-step login, project context, CSRF tokens,
creating documents, uploading and downloading primary files, and diagnosing
failures. Split out of CLAUDE.md on 2026-10-30.

These are the mechanics. For *what to test with them*, see
[TESTING.md](TESTING.md) §7, which indexes the curl-based test procedures
per feature. The same recipes work against the live server with the
credentials in `~/.config/doctis/live-claude.env`; see
[LIVE_RUNBOOK.md](LIVE_RUNBOOK.md).

---

Use `curl` to exercise the VM instance programmatically when browser access is
not possible. All state lives in a cookie jar file; keep it in the scratchpad
and delete it afterwards. The examples use:

```bash
B=http://10.0.0.109/doctis          # the $g_path host; 127.0.0.1 breaks the login cookie
C=<scratchpad>/doctis_cookies.txt
```

Accounts: `administrator` / `root` exists after every rebuild. The sample role
accounts (`manager`, `developer`, `reporter`, `viewer` and others) have blank
passwords; they exist once sample data or `vm-users.sql` is loaded. Some pages
(Manage, Load Data) ask for the password again; repeat the login if a POST is
redirected to `login_password_page.php`.

## One-time setup — login

MantisBT login is a two-step POST. Do both in sequence:

```bash
# Step 1 — submit username; server redirects to password page
curl -s -c "$C" -b "$C" \
  -X POST "$B/login_password_page.php" \
  -d "username=manager&return=index.php" -o /dev/null

# Step 2 — submit password (blank for the default manager account)
curl -s -c "$C" -b "$C" \
  -X POST "$B/login.php" \
  -d "username=manager&password=&return=index.php&secure_session=0" -o /dev/null
```

Verify success by fetching any authenticated page and grepping for the username:

```bash
curl -s -L -c "$C" -b "$C" \
  "$B/my_view_dwg_page.php" | grep -o 'manager'
```

## Project context

Pages that require a project selected will redirect to
`login_select_proj_page.php`, which in turn redirects to
`set_project.php?project_id=1&ref=<target>`. Follow redirects with `-L` to
handle this transparently:

```bash
curl -s -L -c "$C" -b "$C" \
  "$B/dwg_create_page.php" -o /tmp/page.html
```

## Reading CSRF tokens from a page

Every MantisBT form embeds a `form_security_field()` token. Extract it before
POSTing:

```bash
TOKEN=$(grep -oP 'name="dwg_report_token" value="\K[^"]+' /tmp/page.html)
# general pattern:
TOKEN=$(grep -oP 'name="<token_name>" value="\K[^"]+' /tmp/page.html)
```

In Claude Code's shell, `grep` is a ugrep wrapper that skips gitignored files
and can reject complex `-P` patterns; use `/bin/grep` when that gets in the way.

Token names follow the pattern `<action>_token`, e.g.:
- `dwg_report_token` — document create form
- `dwg_primary_file_update_token` — primary document upload on view page

## Creating a document

```bash
curl -s -L -c "$C" -b "$C" \
  "$B/dwg_create_page.php" -o /tmp/create.html
TOKEN=$(grep -oP 'name="dwg_report_token" value="\K[^"]+' /tmp/create.html)

curl -s -c "$C" -b "$C" \
  -X POST "$B/dwg_create.php" \
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

## Uploading/replacing a primary document (view page)

```bash
curl -s -L -c "$C" -b "$C" \
  "$B/dwg_view.php?id=<N>" -o /tmp/view.html
UPLOAD_TOKEN=$(grep -oP 'dwg_primary_file_update[^"]*" value="\K[^"]+' /tmp/view.html)

curl -s -c "$C" -b "$C" \
  -X POST "$B/dwg_primary_file_update.php" \
  -F "dwg_primary_file_update_token=${UPLOAD_TOKEN}" \
  -F "dwg_id=<N>" \
  -F "primary_document_file=@/tmp/myfile.pdf;type=application/pdf;filename=myfile.pdf" \
  -F "primary_document_description=New revision" \
  -v 2>&1 | grep "Location:"
# Redirects to dwg_view.php?id=<N> on success
```

## Downloading a primary document

```bash
curl -s -c "$C" -b "$C" \
  "$B/file_download.php?type=dwg_primary&id=<N>"
```

## Diagnosing curl failures

- **0-byte response** — the page redirected without `-L`; add `-L` to follow
- **302 to `login_select_proj_page.php`** — project cookie missing; follow the
  redirect chain with `-L` and Doctis will auto-select a project
- **HTTP 500 with `<p>error message</p>`** — extract with:
  `grep -oP "(?<=<p>)[^<]+(?=</p>)" /tmp/result.html`
- **Empty `Location:` header** — POST was rejected (CSRF mismatch, access
  denied, or validation error); save `-o /tmp/result.html` and inspect body
- **Git `Author identity unknown`** — `ensure_git_home()` in
  `GitFileStorageBackend` sets `GIT_AUTHOR_*` env vars; check gitconfig at
  `/var/www/.gitconfig` exists and is readable by `www-data`
