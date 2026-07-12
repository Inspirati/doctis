# Doctis Testing Guide

This document indexes every test method available in the Doctis codebase:
what each one covers, when to reach for it, and how to run it. It is a map,
not a tutorial — each section links to the authoritative source (script
`--help`, source file, or a design doc) for full detail.

All server-side commands run **on vaio** via `ssh hcr@vaio "..."` per
[CLAUDE.md](../CLAUDE.md)'s Development Environment section, unless a section
says otherwise (the git-import client script explicitly does not).

---

## 1. Overview — which test method for which change

| You changed... | Use |
|---|---|
| PHP core/API logic with no git or SOAP surface | §2 PHPUnit |
| SOAP endpoint (dwg, primary file, attachment) | §3 `doctis-soap-test.sh` |
| Git storage backend / repository resolution / path handling | §4 `test-git-php.php` + `test-git-doctis.php` |
| Repository import (`admin/import-git-repo.php`) | §5 Import dry-run/real-run against a scratch repo |
| Smart HTTP gateway (clone/push, auth, hook enforcement) | §6 remote clone/push script, or §4's hook-enforcement steps |
| A page, form, or workflow with no good headless test | §7 curl-based live testing |
| Anything touching schema | Rebuild first — §8 — then re-run the relevant suite above |
| You aren't sure what else broke | §9 full regression sweep |

---

## 2. PHPUnit (`tests/`)

**What it covers:** MantisBT core API unit tests (`tests/Mantis/`) and SOAP
integration tests (`tests/soap/`) via PHP's SoapClient. Doctis has not yet
added its own PHPUnit suites for `dwg_api.php` / document workflows — new
document-domain unit tests belong here as they're written.

**Setup (one-time):**
```bash
cd /var/www/html/doctis   # on vaio
composer install
cp tests/bootstrap.php.sample tests/bootstrap.php
# edit tests/bootstrap.php: MANTIS_TESTSUITE_SOAP_HOST, USERNAME, PASSWORD
```

**Run:**
```bash
ssh hcr@vaio "cd /var/www/html/doctis && ./vendor/bin/phpunit"
```

Config: [phpunit.xml](../phpunit.xml) — three suites (`mantis`, `soap`,
`mantis core formatting`; a `rest` suite exists but is commented out, tracking
the "no REST endpoints for dwg/document" gap in CLAUDE.md).

---

## 3. SOAP endpoint smoke test — `doctis-soap-test.sh`

**What it covers:** all 13 Doctis-specific SOAP operations end-to-end against
a *live* instance: status enum, document fetch, primary-file lifecycle
(upload → metadata → download → delete), attachment lifecycle. This is the
fastest way to confirm the whole PHP → SOAP → git/DB stack is wired correctly
after a change, without a browser.

**Properties:** non-fatal (later steps run even if earlier ones fail so you
get a full picture), idempotent (cleans up leftover state from a prior run at
the start), needs a real `dwg_id` in a real project — the schema's seed
row (`dwg` id 1) has `project_id=0` and will fail step 4 onward; create or
pick a real document first.

**Run:**
```bash
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh \
  http://10.0.0.10/doctis manager '' <dwg_id>"
#                           host          user  pw  target document
```

Full endpoint reference, raw-curl SOAP templates, and base64Binary
double-encoding notes: CLAUDE.md §"SOAP API Testing and Diagnostics".

---

## 4. Git storage integration tests

Two complementary scripts — deliberately layered, so a failure immediately
tells you whether the fault is in raw git mechanics or in the Doctis mapping
layer on top of them (see
[doc/git/GIT_SOLUTION_SPACE.md](git/GIT_SOLUTION_SPACE.md) for why the two
layers are architecturally separate).

### 4a. `admin/test-git-php.php` — pure git mechanics

**What it covers:** the czproject/git-php integration itself, with no
Doctis core bootstrap: init, clone, add, commit, push, retrieve-by-SHA, HEAD
retrieve, SHA256 integrity, soft-delete, history retention, and
**pre-receive hook enforcement** (force-push rejection, ref-delete rejection,
`refs/doctis/*` client-write rejection). Self-contained — creates and tears
down its own temporary bare repo.

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php"
```

Must pass before enabling git storage on any new server.

### 4b. `admin/test-git-doctis.php` — the Doctis mapping layer

**What it covers:** everything `core/repository_api.php` and the
path-as-data model in `core/file_dwg_api.php` add on top of raw git — 59
checks across 13 steps: path sanitiser, repository-entity CRUD, project→
repository resolution (explicit link → hierarchy inheritance → create at
top-level project), on-disk materialisation, primary upload with the default
and a `{category}/{filename}` template, same-filename vs. new-filename
replacement (directory-sticky, HEAD stays single-copy), register-by-reference
(`file_dwg_primary_register()`), cross-project path-collision rejection,
dangling-path detection (`file_dwg_git_head_info()` after an external
delete), sync-to-HEAD promotion, repository adoption (`repository_adopt()`
— full history, remotes stripped), and rename relocation. Bootstraps the
full core, creates and tears down its own projects/documents/repositories.

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php"
```

Architecture reference: [doc/git/GIT_ARCHITECTURE.md](git/GIT_ARCHITECTURE.md).

---

## 5. Repository import — `admin/import-git-repo.php`

**What it covers:** adopting an existing git repository as a Doctis project
(optionally with sub-projects sharing its repository) and registering its
qualifying files as documents by reference, with no commits made to the
source content. See [doc/git/GIT_IMPORTER.md](git/GIT_IMPORTER.md) for the
full design (`.doctis` manifest format, metadata-gleaning rules, use-case
walkthroughs) and [doc/git/GIT_TODO.md](git/GIT_TODO.md) §6/WP7 for the
implementation record.

**Always dry-run first.** `--dry-run` performs Phase A/B discovery and
metadata gleaning and prints the full would-be result — projects, documents,
categories, status mapping, and every warning (unmapped frontmatter status,
unmatched owner account) — without writing anything to the database or
touching git.

```bash
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php --help"

# Dry run — flat *.md tree, directory allowlist (HCRQMS-style)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
  --source /var/git/MyDocs --name 'My Docs' \
  --directories content,system --patterns '*.md' --dry-run"

# Dry run — one sub-project per top-level directory (monorepo-style)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/import-git-repo.php \
  --source /var/git/MyHardware --name 'My Hardware' \
  --subprojects subdirs --patterns '*.pdf' --filename-parse yes --dry-run"

# Real run (same flags, minus --dry-run); wrapper form:
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-git-import.sh \
  --source /var/git/MyDocs --name 'My Docs' --directories content,system --patterns '*.md'"

# Re-run later to pick up new commits — registers only new/unregistered
# paths, never deletes, reports any registered path missing at HEAD:
ssh hcr@vaio "... import-git-repo.php --source /var/git/MyDocs --name 'My Docs' \
  --directories content,system --patterns '*.md' --update"
```

**Source repository placement:** stage the source repo anywhere readable by
`www-data` *outside* `$g_git_storage_root` (`/var/git/doctis/` by default) —
e.g. `/var/git/<name>/` alongside it, not inside it. The importer clones
*from* that path; it never modifies the source. `doctis-git-reset.sh` (§8)
only touches `$g_git_storage_root`, so staged sources survive a clean-room
reset untouched.

**Verifying an import:** after a real run, confirm content integrity
end-to-end — this is what actually proves the registration is correct, not
just that rows were inserted:
```bash
# Compare a downloaded document against the git blob directly
curl -s -b cookies.txt "http://10.0.0.10/doctis/file_download.php?type=dwg_primary&id=<dwg_id>" | md5sum
ssh hcr@vaio "git --git-dir=/var/git/doctis/<repo-basename>.git show 'HEAD:<git_path>'" | md5sum
```
Both hashes must match. See §7 for the curl login/session pattern.

---

## 6. Remote clone / edit / push — external contributor simulation

**What it covers:** the Smart HTTP gateway's write path
(`$g_git_http_enabled`, API token auth, `$g_git_http_write_threshold`) from
the perspective of an actual external contributor: clone with a personal API
token, edit a file, commit, push — over the network, with no server shell
access. This is the one workflow the other test methods above don't reach,
because they all run *on* vaio.

**Script:** [doc/git/doctis-remote-clone-edit-push.sh](git/doctis-remote-clone-edit-push.sh)
— run on a **workstation** (this machine), not via `ssh hcr@vaio`. It talks
to the Doctis host only over HTTP(S), exactly as a real remote user would.

**Prerequisites:**
- `$g_git_http_enabled = ON` on the target instance
- A personal API token (*My Account → API Tokens* in the Doctis UI, or
  `api_token_create()` for scripted setup — see the script's own header for
  a one-liner)
- The repository basename (`<slug>-r<id>`, e.g. `hcrqms-r5`) — shown on a
  document's "Advanced: Direct Git Repository Access" panel
  (`dwg_primary_head_warn.php`), or `ls /var/git/doctis/` on the server

**Run:**
```bash
bash doc/git/doctis-remote-clone-edit-push.sh --help

bash doc/git/doctis-remote-clone-edit-push.sh \
  --host 10.0.0.10 --repo hcrqms-r5 --user manager
# prompts for the API token; edits the first *.md file found, commits, pushes
```

> **Gateway host note:** the gateway is mounted at the **vhost root**
> (`Alias /git /var/www/html/doctis/git_http.php` in
> `admin/tools/git-serve.conf`), *not* under the app path — `--host` is
> `10.0.0.10`, not `10.0.0.10/doctis`, even though the web UI itself lives at
> `http://10.0.0.10/doctis/`.

**What a successful run proves:** authentication, read access, write access,
and — critically — that the push advanced only the repository **Draft**
(`HEAD`); the Doctis **On-Record** version (if any) stays pinned at its
recorded SHA until a manager explicitly promotes the new HEAD (*Sync to
HEAD* in the Primary Document panel, or `dwg_primary_file_sync_head.php`).
Check this directly:
```bash
ssh hcr@vaio "mysql -N -e \"SELECT git_sha FROM doctis.dwg_primary_file WHERE dwg_id=<id>;\""
ssh hcr@vaio "git --git-dir=/var/git/doctis/<repo-basename>.git rev-parse HEAD"
# the two will now differ — that's the expected, correct outcome
```
The document's view page should show a "missing at HEAD" or "updated" badge
in the Draft row (`dwg_view_inc.php`) reflecting exactly this divergence.

**Negative-path check (auth rejection):** re-run with a bad token; expect a
clean `fatal: Authentication failed` from git and a non-zero exit — this is
what `git_http_require_auth()` is supposed to produce. Force-push and
ref-deletion rejection are already covered by §4a's hook-enforcement steps;
this script does not repeat them.

---

## 7. Curl-based live testing

**What it covers:** anything with real HTTP/session/CSRF/redirect behaviour
that the API-level tests above don't exercise — page rendering, form
submission, login flow, project-context redirects, file upload via
multipart. The closest thing to a browser test without a browser.

**Login (two-step, cookie jar):**
```bash
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/login_password_page.php" \
  -d "username=manager&return=index.php" -o /dev/null
curl -s -c /tmp/doctis_cookies.txt -b /tmp/doctis_cookies.txt \
  -X POST "http://10.0.0.10/doctis/login.php" \
  -d "username=manager&password=&return=index.php&secure_session=0" -o /dev/null
```

Full pattern library (CSRF token extraction, document creation, primary-file
upload/replace, download, diagnosing 0-byte/302/500 responses): CLAUDE.md
§"Curl-Based Live Testing".

---

## 8. Clean-room database and git-store reset

**What it covers:** returning to a known-empty state before a test session
that must start clean — required before re-running any suite above if a
prior session left ad hoc test data behind.

**Always run both steps together** — they reference each other:

```bash
# 1. Wipe the git document store (bare repos + worktrees)
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"

# 2. Drop, recreate, and install the database from admin/schema.php
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"

# 3. (optional) reload the standard example project/licenses/test users
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh"
```

Verify: `mysql -e 'SELECT id, name FROM doctis.project;'` should show only
`administrator` (no sample data) or the example project (sample data
loaded); `ls /var/git/doctis/ /var/www/doctis/worktrees/` should be empty.
Full procedure and rationale: CLAUDE.md §"Resetting to a Clean Slate for
Testing".

**After any schema change:** rebuild (steps 1–2 above) before running §4 or
§5 — a stale schema is the most common cause of confusing failures in both.
See CLAUDE.md §"Database Schema Changes".

---

## 9. Suggested full regression sweep

Run in this order after a change that touches the git/document storage
layer — each step's failure mode points cleanly at the layer above it:

```bash
# 0. Rebuild clean (§8)
ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"
ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh"

# 1. Git mechanics (§4a)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php"

# 2. Mapping layer (§4b)
ssh hcr@vaio "sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php"

# 3. SOAP lifecycle against a real document (§3 — create one first if needed)
ssh hcr@vaio "bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh http://10.0.0.10/doctis manager '' <dwg_id>"

# 4. Gateway, from a workstation (§6)
bash doc/git/doctis-remote-clone-edit-push.sh --host 10.0.0.10 --repo <repo-basename> --user manager

# 5. PHPUnit (§2), if core API surface changed
ssh hcr@vaio "cd /var/www/html/doctis && ./vendor/bin/phpunit"
```

All must be green (or, for step 4, produce the expected Draft/On-Record
divergence) before considering the change verified.
