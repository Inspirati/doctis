# CLAUDE.md — Doctis Project Guide

## What is Doctis?

Doctis (**Doc**ument **I**ssue **T**racking **S**ystem) is a fork of [MantisBT](https://github.com/mantisbt/mantisbt) v2.27.x extended to track **documents** and the issues raised against them during formal review cycles. It is a PHP/MariaDB web application.

The project is actively developed (beta) and maintained on the `dev` branch, tracking MantisBT upstream via the `upstream_sync` branch.

## Repository Layout

```
/                           PHP page entry-points (bug_*, dwg_*, manage_*, login_*, etc.)
core/                       API library functions (*_api.php, classes/, commands/, exceptions/)
api/rest/restcore/          Slim-framework REST endpoints: MantisBT's, plus Doctis documents_rest.php,
                            licenses_rest.php and topics_rest.php (read-only)
api/soap/                   SOAP API (mantisconnect), including the Doctis mc_dwg_* operations
config/                     Local config (config_inc.php, not committed)
config_defaults_inc.php     All defaults including Doctis-specific $g_dwg_* settings
admin/                      Installer, schema.php, importer and diagnostic PHP scripts
admin/tools/                Install, reset, backup and test shell scripts
admin/tools/data-scripts/   Local SQL data scripts and backups (gitignored except README.md)
doc/                        Design, planning and user documentation for Doctis-specific features
plugins/                    MantisBT plugin system (Gravatar, MantisGraph, etc.)
lang/strings_english.txt    All UI strings including Doctis additions
scripts/                    Cron entry points (send_emails.php, meeting_schedule.php)
tests/                      PHPUnit + REST/SOAP integration tests
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

## Document Ownership

A document names three different people, and they are not interchangeable
(owner decision, 2026-10-20):

| Role | Column | UI label | Changes |
|------|--------|----------|---------|
| Creator | `creator_id` | "Created by" | Never — read-only provenance, as a MantisBT reporter is |
| Owner | `owner_id` | "Owner" | Rarely; when staff are replaced. This is the name published in the document-control block |
| Assignee | `handler_id` | "Assigned To" | Often — the task-step workflow |

**The column and variable names are deliberately unchanged**, so `dwg_*`
modules stay diff-able against MantisBT; `handler_id` is the *assignee*, never
the owner. Consistency lives in the lang strings, history labels, exports and
API instead. Beware three MantisBT-inherited traps: `email_dwg_owner_changed()`
is the **assignee** notification (the owner one is
`email_dwg_ownership_changed()`), `{topic_expectation}.owner_id` is an
unrelated owner, and the notify-flag key `category` means the *Category*
owner.

Self-claim is `dwg_claim.php`, which only ever writes the current user's id;
naming somebody else goes through `dwg_update.php` at a higher threshold.
Thresholds: `$g_own_dwg_threshold` and `$g_dwg_claim_owner_threshold`
(DEVELOPER), `$g_dwg_assign_owner_threshold` (MANAGER, and the only way to take
over an already-owned document), `$g_dwg_claim_threshold` (UPDATER).
Ownership changes are exempt from the read-only-status check, so a departing
owner can be replaced on an archived document. Ownership never changes the
status. API: [core/access_dwg_api.php](core/access_dwg_api.php)
(`access_dwg_can_*`). Design, decisions, as-built notes and the test record:
[doc/dwg/OWNERSHIP.md](doc/dwg/OWNERSHIP.md).

Two document statuses are deliberately inert: `$g_dwg_auto_set_status_to_assigned`
and `$g_dwg_reassign_on_feedback` are OFF, separate from the shared bug keys,
because a document status is a lifecycle state and not a queue state.
`$g_dwg_assigned_status` must be `ASSIGNED_TO` (150) — MantisBT's `ASSIGNED`
(50) is a bug status and is not in `$g_dwg_status_enum_string`.

## Project Ownership

A project has a **responsible person**, `{project}.owner_id`, and
**sub-projects inherit it** (owner decision 2026-10-23). `0` means inherit.
`project_get_owner()` climbs the hierarchy until a project names one, the way
`dwg_publish_site()` and repository resolution do;
`project_get_owner_source()` says which project supplied it, so a form can tell
*set here* from *inherited*. Pass `$inherited = false` to read only a project's
own field — the edit form needs that, or saving would pin an inherited value. A
disabled owner reports as none.

Wired through `project_create()` / `project_update()` (both take `$p_owner_id`
and `$p_classification`, `null` meaning leave alone), `ProjectAddCommand` /
`ProjectUpdateCommand`, the SOAP/REST project row (`owner` and
`effective_owner`), the WSDL, the create and edit forms, and a sortable Owner
column on the project list. Consumers: the successor suggestion in
`user_transfer_suggest_successor()` and the new-document owner fallback in
`DwgData::create()`.

`{project}.classification` is wired the same way but **nothing reads it yet** —
deliberately, as the classification scheme is unresolved; it is free text like
the document field. `{project}.reference_url1` and `reference_url2` remain
unwired dead columns. Design and test record:
[doc/dwg/PROJECT_OWNERSHIP.md](doc/dwg/PROJECT_OWNERSHIP.md).

**Sharp edge:** `project_hierarchy_cache()` builds once per request and
nothing clears it, so adding a hierarchy row and resolving an owner in the same
request would see the old parent.

## User Lifecycle

**A staff member who leaves is removed by disabling their account, never by
deleting it** (owner decision 2026-10-20). Disabling already blocks login and
email, drops the user from every picker and the org chart, and keeps their name
on every document, note and history entry — struck through, via
`prepare_user_name()`. Deleting cleans up only four tables and leaves 40-odd
others dangling, replacing the name with `user<id>`, which destroys the
`{dwg_history}` audit trail.

Deleting stays available for mistakes (fake sign-ups, registrations to revert)
at `$g_delete_user_threshold` (ADMINISTRATOR, above
`$g_manage_user_threshold`); **Prune Accounts** only removes accounts that have
never been logged into. Anonymisation is explicitly not required: user records
are company property, not personal data.

Disabling does not hand on what the person held, so **Transfer
Responsibilities** does: documents owned and assigned, issues, meetings yet to
be held, open meeting actions, Topic expectations and category defaults.
Disabling an account that still holds something redirects to it, and Manage →
Users carries a standing link. `{user}.reports_to` supplies the *default*
successor only — it is optional and self-editable, so it is never treated as
authority — and eligibility is checked per row (`$g_own_dwg_threshold` plus any
required licence, `$g_handle_dwg_threshold`, `$g_handle_bug_threshold`), with
ineligible rows skipped and reported. Historical attribution is never moved.
API: [core/user_transfer_api.php](core/user_transfer_api.php); design and test
record: [doc/dwg/USER_LIFECYCLE.md](doc/dwg/USER_LIFECYCLE.md) §5.

## License Entity

A Doctis license is a skill, security clearance, or professional qualification
registered against a user account. Documents can require users to hold one or
more licenses before access is granted. This is entirely unrelated to software
licensing; the term is Doctis-specific.

API: [core/license_api.php](core/license_api.php). Command layer: [core/commands/License*Command.php](core/commands/).

## Documentation Topics

Projects can classify their documents by the numbered Topics of a managed
catalogue (e.g. STD-ENG-004: 00–60, with 21–23 grouped under 20) and record
which Topics they need. A tag marks a document as part of, or the whole of, a
Topic's documentation, with optional sections. Tags are declarations, not
assessments. Each Topic's coverage comes from its own tags only: parent and
child Topics never affect each other. Off unless `$g_topics_enabled = ON`.

API: [core/topic_api.php](core/topic_api.php) (tables `{topic_catalogue}`,
`{topic}`, `{topic_project}`, `{topic_applicability}`, `{dwg_topic}`). Pages:
`manage_topic_*.php` (catalogues), `topic_page.php`, `topic_detail_page.php`
and `topic_export.php` (project view, CSV/print), `dwg_view_topics_inc.php`,
`dwg_topic_*.php` and `dwg_create_topics_inc.php` (document tags),
`dwg_actiongroup_add_topic_inc.php` (bulk tagging), the document list's
`topics` column and `core/classes/TopicFilter.class.php` (list filter;
registered in `filter_dwg_get_plugin_filters()`). SOAP/REST `DwgData` has
`topics`. Phase 2 (milestones, expectations, evidence, adequacy assessments,
coverage states, profile authorisation):
[core/topic_expectation_api.php](core/topic_expectation_api.php),
`topic_milestone_*.php`, `topic_expectation_*.php`, `topic_evidence_action.php`,
`topic_assessment_add.php`, `topic_profile_authorise.php`. Phase 3:
[core/topic_snapshot_api.php](core/topic_snapshot_api.php) (frozen review
snapshots), `topic_snapshot_*.php`, `topic_activity_page.php`,
`topic_catalogue_migrate*.php`, read-only REST in
`api/rest/restcore/topics_rest.php`. Plan, phases and as-built notes:
[doc/topics/TOPICS_PLAN.md](doc/topics/TOPICS_PLAN.md).

## Document Publishing

**Publish** on the document view page converts a Markdown document's On Record
version to HTML with pandoc. The page goes into the project's publication
target directory (a static website) at its repository path, `.md` → `.html`.
The page header shows the Doctis status. This is a one-document port of HCRQMS
`engine/scripts/build-html.sh`, with the same layout and sidebar. Each page
ends with a `doctis-publish` marker comment; the sidebar and `index.html` are
rebuilt from these markers, and files without one are never touched. Off
unless `$g_dwg_publish_roots` (config_inc.php only) is set. Per-project
settings (target, URL, template and logo repository paths) are on Manage
Project → Publishing and inherit down the project tree. Templates using pandoc
partials are refused. API: [core/dwg_publish_api.php](core/dwg_publish_api.php);
default template and link filter in `core/publish/`. Design, setup and
limitations: [doc/publish/PUBLISHING.md](doc/publish/PUBLISHING.md).

**Lifecycle metadata lives only in Doctis** (owner decision, 2026-10-15). The
git store holds content, Doctis holds status, and publishing combines them.
It prints a document-control block (`core/publish/control-block.lua`) and the
classification in headers and footers. `admin/doctis-metadata-cutover.php`
removes status, revision, owner, classification etc. from document
frontmatter and header lines in one commit, and promotes the changes.
Meeting records get their status in Doctis (`$g_meeting_document_status`),
never stamped into the file. See
[doc/publish/METADATA_CUTOVER.md](doc/publish/METADATA_CUTOVER.md).

## Development Environment

Development and testing happen on a native **Debian 13 VM** (`10.0.0.109`),
with nginx, PHP-FPM 8.4 and MariaDB 11.8. Claude Code runs on the same VM, so
every command below runs locally; no SSH is needed. The former `vaio` host
(`10.0.0.10`, Apache) is no longer the development environment. Its old
checkout is still NFS-mounted here at `/home/robert/html/doctis`; leave it
alone. Day-to-day handover and current VM state:
[DEV-SETUP.md](DEV-SETUP.md). Install profile and layout:
[doc/NATIVE-DEVELOPMENT.md](doc/NATIVE-DEVELOPMENT.md).

The VM was cloned on 2026-10-17, with its disk grown to 40 GB. The clone came
up at `10.0.0.109`; the old VM, at `10.0.0.94`, is off. The address comes from
the router's DHCP. If it changes again, update:
- `$g_path` in the running clone's `config/config_inc.php`;
- `MANTIS_TESTSUITE_SOAP_HOST` in its `tests/bootstrap.php`;
- `server_name` in `/etc/nginx/sites-available/doctis` (then reload nginx).

Then update these docs (`git grep` the old address).

| Item | Detail |
|------|--------|
| Working clone | `/home/robert/Documents/doctis`: edit and commit here |
| Running clone | `/var/www/html/doctis`: what nginx serves; owned `robert:www-data`, not edited directly |
| URL | `http://10.0.0.109/doctis/` (matches `$g_path`; use this host, not 127.0.0.1, so login cookies match) |
| Database | MariaDB on `127.0.0.1`, database and app account both `doctis`; Linux user `robert` has no MariaDB account |
| nginx / PHP-FPM | site `/etc/nginx/sites-available/doctis`; pool `/etc/php/8.4/fpm/pool.d/doctis.conf` |
| Git document store | `/var/git/doctis/` (bare repos) and `/var/www/doctis/worktrees/` |
| Cron (`/etc/cron.d/doctis`) | `scripts/send_emails.php` every minute and `scripts/meeting_schedule.php` hourly, as `www-data` |

### Deploying a change to the running clone

The running clone fetches from the working clone, so unpushed branches can be
tested. Run as `robert`: `www-data` cannot read `/home/robert`. Check
`git status` in the running clone first; the reset discards tracked changes
(`config/config_inc.php` and `tests/bootstrap.php` are gitignored and survive).

```bash
cd /var/www/html/doctis
git fetch /home/robert/Documents/doctis <branch> && git reset --hard FETCH_HEAD
php admin/upgrade_unattended.php      # only when admin/schema.php gained steps
```

Pushing to GitHub (`origin`, `Inspirati/doctis`) needs the owner's explicit
approval. Since 2026-10-16 the VM runs System Operations like live does: as the
unprivileged `hcr`, under live's sudoers rule. So Git Pull, Rebuild Database,
Load Sample Data and config upload all work through their pages. **Never run
git in `/var/www/html/doctis` as `robert`.** Deploy as `hcr`, with the Git Pull
page or a bundle (doc/NATIVE-DEVELOPMENT.md, "System Operations account").

### Querying the database

Use the app account, reading its password from the running clone at run time.
Never print it or copy it into a file:

```bash
export MYSQL_PWD=$(php -r 'require "/var/www/html/doctis/core/constant_inc.php";
  include "/var/www/html/doctis/config/config_inc.php"; echo $g_db_password;')
mysql -h 127.0.0.1 -u doctis doctis -e "SELECT id, name FROM project;"
```

Root access goes through the unix socket (`sudo mysql doctis`); the reset and
rebuild scripts below rely on it.

### Outbound email is live

The VM sends real email through Gmail SMTP, and cron flushes the queue every
minute. Several sample accounts have `@gmail.com` addresses. Before anything
that queues notifications in bulk (imports, reloads, scripted document
creation), add `$g_enable_email_notification = OFF;` to `config/config_inc.php`,
and remove it afterwards.

### Rebuilding on a new host

Git storage directories, `/var/www/.gitconfig`, Composer `vendor/`, the
nginx/PHP-FPM configuration and the cron and sudoers entries are host-specific.
Install with the native profile ([doc/NATIVE-DEVELOPMENT.md](doc/NATIVE-DEVELOPMENT.md)),
check git storage against [doc/git/GIT_SERVER_SETUP.md](doc/git/GIT_SERVER_SETUP.md),
then run the git integration tests (below) before resuming development.

## Live Version

The **live version** is `https://qms.hcrobo.com/doctis`: a remote Docker
deployment of `dev` that is expected to become production. Nobody has
console access. It is updated through System Operations (Git Pull, Rebuild
Database, Reset Git Store, Load Data). Reading pages is fine; ask the owner
before any change to it. It does not get sample data.

### Updating the live version with a database rebuild

Everything below is done in the browser, or with curl as in
[Curl-Based Testing](#curl-based-testing), by an administrator. Pages that
change anything call `auth_reauthenticate()`, so log in afresh just before
each action. This was done on 2026-10-14 and the account-disabling step was
tested then.

**Before starting**, with the owner's approval and `dev` pushed:
1. Check Manage → System Operations → **Git Checkout Status** shows nothing
   blocking a pull.
2. Download backups.
   - **Backup Git Store** gives a `.tar.gz` of the store.
   - **Backup Database** (a full `mariadb-dump`), or a **Data Snapshot** of
     all tables. Backup Database works on live since 7cb107677 (2026-10-14),
     which connects without TLS (`--skip-ssl`) like the application. Earlier
     builds failed because MariaDB 11.4+ dump clients demand TLS.
   - **Data Snapshot** of the `user` table only, in REPLACE mode. This is the
     file that restores the accounts.
3. Note any projects other than the imported one. A rebuild deletes them, and
   they are not restored unless you load them back from the snapshot.

**Steps:**
1. **Git Pull.**
2. **Rebuild Database** (type `REBUILD`). This drops and reinstalls the
   database at the new schema and ends every session. Only the seeded
   `administrator` / `root` account exists afterwards.
3. Log in as `administrator` / `root`.
4. **Reset Git Store** (type `RESET`). The import would otherwise fail: its
   new repository reuses the old `<slug>-r<id>` name. The page refuses while
   any `{repository}` row exists, so it only works straight after a rebuild.
5. **Load Data**: upload the `user` snapshot, type `LOAD`, and keep the
   transaction ticked. This restores every account, including the owner's
   administrators and `claude`; Claude's credentials are kept outside the
   repository (see project memory).
   - The schema-version warning is expected when the snapshot is older; the
     columns must still match.
   - If `administrator` was disabled when the snapshot was taken, this step
     disables it again and ends that session. Continue as another
     administrator.
6. Log in with a restored administrator account.
7. **Import Data**: project name `HCRQMS`, the repository ZIP, directories
   `content,system`, patterns `*.md`, visibility **Public**.
   - Expect "N imported, 0 skipped, 0 failed".
   - Warnings that owners such as "QMS Lead" match no account are normal.
   - The front proxy must accept uploads larger than the ZIP.
8. **Verify:**
   - Overview: the database schema version equals the code's.
   - Manage → **Git Store**: one repository, "Database and disk agree", and
     every document "On Record = HEAD".
   - Manage → Users lists the restored accounts.
9. **Disable `administrator`**, but only once a restored administrator
   account has been confirmed to log in.
   - Manage → Users → `administrator`: untick **Enabled**, untick **Send
     email notification**, then **Update User**.
   - Confirm that logging in as `administrator` / `root` is refused.
   - Doctis will not disable the last enabled administrator.
   - Every rebuild re-creates the account enabled, so repeat this step after
     each one.

## Diagnosing PHP Errors

### Log file locations

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

### How MantisBT handles exceptions

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

### Adding temporary debug logging

When the HTML error page doesn't show enough detail (e.g. inside git operations
where exceptions are caught and re-thrown), use `error_log()`:

```php
error_log( 'MyClass::method() reached with var=' . $t_var );
```

Output goes to `/var/log/doctis/php_errors.log`. Remove all `error_log()`
calls before committing.

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

**[doc/TESTING.md](doc/TESTING.md) indexes every test method** (PHPUnit, SOAP
smoke test, git mechanics/mapping-layer integration tests, repository import,
remote clone/push gateway test, curl-based testing, clean-room reset)
with what each covers and how to run it — check there before writing a new
ad hoc test script.

PHPUnit runs in the running clone (after deploying the change), as `www-data`.
`tests/bootstrap.php` is local and gitignored; it already exists there
(template: `tests/bootstrap.php.sample`).

```bash
cd /var/www/html/doctis
sudo -u www-data vendor/bin/phpunit --testsuite mantis
```

The Doctis PHPUnit tests live in `tests/Mantis/` (e.g. the `Topic*Test.php`
files, `MeetingApiTest.php` and `UserNamePolicyTest.php`), alongside
MantisBT's own.

## Database Schema Changes

**Rule: `admin/schema.php` is the single source of truth for the database schema.
Never apply `ALTER TABLE` directly. Never write standalone migration scripts.**

Every table is defined once, in its current final form, as a
`CREATE TABLE IF NOT EXISTS` step in [admin/schema.php](admin/schema.php). Each
step's index is the `database_version` the installer records after running it.

- **New table:** append a step at the end, label it "Step N" with its real
  index, and update the trailer comment (`$t_idx = N+1 → database_version = N`).
  Deploy, then run `php admin/upgrade_unattended.php` in the running clone. No
  rebuild is needed.
- **Change to an existing table** (add, rename or retype a column; change a
  key): edit its `CREATE TABLE` block in place, then rebuild the database (see
  below). There is no production release yet, so rebuilds are acceptable.
- Never insert a step between existing ones, and never remove or renumber one;
  replace an obsolete step with `null`.

**Rebuilding the VM database** replaces the imported projects and documents
too. **No backup is needed first** (owner decision 2026-10-20): the VM is a
development and test scratchpad, and clean feature testing is expected to
start from a rebuild. Reset the database and the git store together (next
section) and reload whatever the test needs. The reload order and the data
scripts are in [admin/tools/data-scripts/README.md](admin/tools/data-scripts/README.md).

Clean up test artefacts on the VM only where they would affect later tests in
the same session; otherwise leave them. A rebuild clears them.

The **live version** is the opposite: back up before any rebuild there, and
follow *Updating the live version with a database rebuild* above.

### Conventions

- Use `INT UNSIGNED` for timestamp columns — `db_now()` returns a Unix integer,
  not a datetime string.
- Use `NOT NULL DEFAULT ''` for optional string columns rather than `DEFAULT NULL`,
  to avoid nullable string comparisons throughout the PHP layer.
- After a schema change, confirm the result (with `MYSQL_PWD` set as in
  **Querying the database**):
  ```bash
  mysql -h 127.0.0.1 -u doctis doctis -e 'DESCRIBE <table_name>;'
  ```
- The schema comment at the top of `schema.php` restates these rules — keep it
  accurate if conventions change.

### What NOT to do

| Prohibited | Why |
|------------|-----|
| `ALTER TABLE doctis.foo ADD COLUMN ...` run directly against the database | Schema.php will not reflect the change; a future rebuild will silently drop the column |
| Standalone `admin/foo_migrate.php` scripts | The installer does not call them; they create false confidence that the schema is up to date |
| `AddColumnSQL` / `AlterColumnSQL` entries appended to schema.php | Contradicts the flat-schema design; makes the definition hard to read and impossible to audit against the live DB |

## Resetting to a Clean Slate for Testing

A full clean-room reset wipes both the MariaDB database and the git document
store and reinstalls the schema. **Always reset both together**: the database
and the git store reference each other, and resetting one alone leaves them
out of sync. The scripts run as root, so MariaDB is reached through its unix
socket and no password is needed.

### Option A: one command, with sample data

```bash
sudo bash /var/www/html/doctis/admin/tools/doctis-reset-native-test.sh --preview
sudo bash /var/www/html/doctis/admin/tools/doctis-reset-native-test.sh --execute
```

`--execute` resets the git store, rebuilds the database, and loads the sample
data: the `example` project, licences and the test accounts. It then checks
the result and ends with `Reset complete: ...`.

### Option B: step by step, without sample data

Use this when the reload comes from data scripts and imports instead
([admin/tools/data-scripts/README.md](admin/tools/data-scripts/README.md)):

```bash
# 1. Git store: bare repos and worktrees. Ends "Git store reset: N item(s) removed."
sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh <<< yes

# 2. Database: drop, recreate, install from admin/schema.php. The installer is
#    served over loopback only, hence 127.0.0.1; the password argument is unused.
sudo bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh doctis not-used 127.0.0.1 <<< yes

# 3. (optional) the standard sample data
sudo bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh <<< yes
```

Step 2 prints `✔ doctis database install successful.` and saves the installer
output to `/tmp/doctis-install.XXXXXX.html`. It leaves only the
`administrator` account (password `root`).

### Verifying the reset

```bash
sudo mysql doctis -e 'SELECT id, name FROM project; SELECT id, username FROM user;'
ls /var/git/doctis/ /var/www/doctis/worktrees/    # empty after a reset
```

## Curl-Based Testing

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

### One-time setup — login

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

### Project context

Pages that require a project selected will redirect to
`login_select_proj_page.php`, which in turn redirects to
`set_project.php?project_id=1&ref=<target>`. Follow redirects with `-L` to
handle this transparently:

```bash
curl -s -L -c "$C" -b "$C" \
  "$B/dwg_create_page.php" -o /tmp/page.html
```

### Reading CSRF tokens from a page

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

### Creating a document

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

### Uploading/replacing a primary document (view page)

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

### Downloading a primary document

```bash
curl -s -c "$C" -b "$C" \
  "$B/file_download.php?type=dwg_primary&id=<N>"
```

### Diagnosing curl failures

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

## SOAP API Testing and Diagnostics

The Doctis SOAP API is the primary programmatic interface for document
operations.  Use raw `curl` SOAP calls to exercise and diagnose individual
endpoints without requiring a browser or a configured SoapClient.

### Smoke test — all Doctis SOAP endpoints in one command

```bash
bash /var/www/html/doctis/admin/tools/doctis-soap-test.sh \
  http://10.0.0.109/doctis manager '' <dwg_id>
#  host                    user    pw  test document
```

Runs 13 tests covering every Doctis-specific SOAP endpoint (enum, document
fetch, primary file lifecycle, attachment lifecycle).  Non-fatal — all
steps run even if earlier ones fail.  Exit code 0 = all pass.  Full
description in [admin/tools/README.md](admin/tools/README.md).

The document id is required (the script refuses to run without one); host,
user and password default to the values shown. **Point it only at a throwaway
document**, created for the test in a scratch project: it deletes the target's
primary file at the start and again in step 7, and on the VM every document
holds imported content. Turn email notifications off while it runs, and
remove the scratch project and its repository afterwards
([doc/TESTING.md](doc/TESTING.md) §3).

### SOAP endpoint and WSDL

```
SOAP endpoint : http://10.0.0.109/doctis/api/soap/mantisconnect.php
WSDL          : http://10.0.0.109/doctis/api/soap/mantisconnect.php?wsdl
```

List all available operations (useful to confirm new endpoints appear after
a WSDL change):

```bash
curl -s "http://10.0.0.109/doctis/api/soap/mantisconnect.php?wsdl" \
  | grep -oP 'operation name="\K[^"]+' | sort
```

Look up parameter names for a specific method:

```bash
curl -s "http://10.0.0.109/doctis/api/soap/mantisconnect.php?wsdl" \
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
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php"
```

### Useful diagnostic one-liners

**Connectivity / version check:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_version><username>manager</username><password></password></man:mc_version></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php" | grep -oP '<return[^>]*>\K[^<]+'
```

**List all document status values:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_enum_dwg_status><username>manager</username><password></password></man:mc_enum_dwg_status></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php" \
  | grep -oP '(?<=<name xsi:type="xsd:string">)[^<]+'
```

**Fetch a document by id (note: parameter is `issue_id`):**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_dwg_get><username>manager</username><password></password><issue_id>2</issue_id></man:mc_dwg_get></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php" \
  | grep -oP '<title[^>]*>\K[^<]+'
```

**Check primary file metadata for a document:**

```bash
curl -s -H "Content-Type: text/xml" -H 'SOAPAction: ""' \
  --data '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:man="http://futureware.biz/mantisconnect"><soapenv:Body><man:mc_dwg_primary_get><username>manager</username><password></password><dwg_id>2</dwg_id></man:mc_dwg_primary_get></soapenv:Body></soapenv:Envelope>' \
  "http://10.0.0.109/doctis/api/soap/mantisconnect.php"
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
git --git-dir=/var/git/doctis/<slug>-r<N>.git log --format='%h %an <%ae> %s' -5
# (repo basename is <slug>-r<repository_id> — ls /var/git/doctis/ to find it)
# Should show the Doctis user's realname (or username) and email, not the
# server identity "Doctis <doctis@vaio.local>" from /var/www/.gitconfig
```

### Diagnosing "Procedure not present" SOAP errors

This error means the SoapServer received a call for an operation it has no
registered PHP function for.  Work through in order:

1. **Is the operation in the WSDL?**
   ```bash
   grep 'mc_my_function' /var/www/html/doctis/api/soap/mantisconnect.wsdl
   # Must appear in <message>, <portType>, and <binding> sections
   ```

2. **Clear the WSDL cache** (PHP caches parsed WSDL in `/tmp/wsdl-*`;
   PHP-FPM has no private `/tmp` on the VM):
   ```bash
   sudo rm -f /tmp/wsdl-*
   ```

3. **Force OPcache recompilation of `mc_core.php`** — the most common cause
   after adding new `require_once` lines.  OPcache caches compiled bytecode
   keyed on file mtime; touching the file forces recompilation on the next
   request:
   ```bash
   touch /var/www/html/doctis/api/soap/mc_core.php
   ```
   If that is not enough, `sudo systemctl reload php8.4-fpm` clears OPcache.

4. **Confirm the PHP function is defined** by loading the SOAP stack from
   CLI as the correct user:
   ```bash
   # Write a check script:
   cat << 'EOF' | sudo -u www-data php
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
   # If the function is absent, the require_once chain has a gap
   ```

5. **Check the PHP error log** for include-time PHP errors that silently abort
   the SOAP bootstrap:
   ```bash
   tail -30 /var/log/doctis/php_errors.log
   ```

---

## Config File

Never commit `config/config_inc.php`. Use `config/config_inc.php.sample` as the template. Override defaults from [config_defaults_inc.php](config_defaults_inc.php) there.

System Operations → Config File uploads are test-loaded before they are
installed (`system_ops_config_check()`, `admin/tools/doctis-config-check.php`).
The file runs in a separate PHP process, loaded as core.php loads it, and is
refused if it:
- has a syntax error, a fatal error or an exception;
- produces output;
- lacks the database settings or a 16-character crypto master salt;
- has database credentials that fail to connect.

Warnings, including settings the new file drops, show after the upload. Each
upload keeps a uniquely named `config_inc.php.bak.*` (git-ignored). Never put
`?>` in a `#` comment of a PHP file: it ends PHP mode.

## Claude Memory — Project-Local Storage

Claude's auto-memory system writes to `~/.claude/projects/<path>/memory/` on
the current machine, which is user- and machine-specific. To make memory
portable across machines and user accounts, this project stores its canonical
memory in:

```
.claude/memory/MEMORY.md              index (read this first)
.claude/memory/<type>_<topic>.md      one fact per file, listed in MEMORY.md
```

**REQUIRED — session start:** You MUST read `.claude/memory/MEMORY.md` and
every file it lists before responding to any request. This is the authoritative
project memory and replaces whatever `~/.claude/projects/...` may contain.

**REQUIRED — saving memories:** Write all new memories to `.claude/memory/`
using the standard frontmatter format (`name`, `description`, `type`), and
update `.claude/memory/MEMORY.md`. Never write only to `~/.claude/projects/...`;
that path is machine-local and will not survive a move to another host.

The `.claude/` directory is intentionally not committed to git (`.gitignore`) —
it holds the memory files and the local Claude Code settings
(`settings.local.json`). Conversation transcripts are stored separately under
`~/.claude/projects/`.

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
PHP error log and takes effect only when the exception is first thrown, not
at class load time.

See `core/commands/DwgAddCommand.php` for the canonical correct pattern.

## File Storage Backend Architecture

**Primary registered documents are stored exclusively in git** — there is no
configurable method for them (`file_dwg_get_storage_backend()` returns
`GitFileStorageBackend` unconditionally; a working `git` binary is an install
prerequisite).  `DISK`/`DATABASE` remain for **attachments only**:

| Applies to | Governed by | Backends |
|-----------|-------------|----------|
| Primary registered document | (nothing — always GIT) | `GitFileStorageBackend` |
| Document attachments (`{dwg_file}`) | `$g_dwg_upload_method` | `DISK` / `DATABASE` (`GIT` value maps to `DATABASE`) |
| Bug/issue attachments (`{bug_file}`) | `$g_file_upload_method` | `DISK` / `DATABASE` (`case GIT:` falls through to `DATABASE` in `file_api.php` and `print_dwg_attachment_preview_text()`; `file_download.php` remaps `GIT` to `DATABASE` before its switches) |

Rationale: the registered document is the only item a `git clone` user should
see and the only revision-controlled record; attachments are Doctis
"assisting metadata".  Full architecture:
[doc/git/GIT_ARCHITECTURE.md](doc/git/GIT_ARCHITECTURE.md); design rationale:
[doc/git/GIT_SOLUTION_SPACE.md](doc/git/GIT_SOLUTION_SPACE.md).

**Interface:** [core/classes/FileStorageBackendInterface.class.php](core/classes/FileStorageBackendInterface.class.php)

```
store(tmp_file, size, unique_name, file_path, browser_upload, metadata[]) → [diskfile, folder, content]
retrieve(row, project_id) → [type, content] | false
delete(diskfile, project_id, metadata[]) → void
```

For the GIT backend, `metadata['git_path']` (repo-relative path) is required
by `store()`/`delete()`, and `retrieve()` reads `row['git_path']`.

### Repository entity and naming

A git repository is a first-class entity — a `{repository}` row managed by
[core/repository_api.php](core/repository_api.php).  Projects map to
repositories via the `{project_repository}` link table: explicit link →
that repo; no link → inherit from the nearest linked ancestor project; no
ancestor either → created on demand at the **top-level project**.  A project
tree therefore shares one repository by default (monorepo/sub-project model);
a sub-project can be explicitly linked to its own.

```
/var/git/doctis/<slug>-r<id>.git        bare repo (authoritative store)
/var/www/doctis/worktrees/<slug>-r<id>  working tree (write staging area)
```

Basename `<slug>-r<repository_id>` (e.g. `example-r1`); the stored `slug`
column is authoritative (nothing scans the disk); resolution is by the
immutable `-r<id>` suffix.  Renaming a project relocates its owned repos
(`repository_rename_for_owner_project()`, via the `dwg_project_repo_rename()`
wrapper called from `project_update()`).  Document code resolves through the
project-keyed wrappers in `core/file_dwg_api.php`
(`dwg_project_bare_repo_path()` etc.) — never derive a repo path from a
project name directly.

### Path-as-data

Each primary file's repo-relative path is stored in
`{dwg_primary_file}.git_path` — never computed at read time.  New documents
get a path from `$g_dwg_repo_path_template` (tokens `{dwg_id}`, `{filename}`,
`{category}`; default `{dwg_id}/{filename}`; per-project overridable);
imported documents keep native paths.  Replacements are directory-sticky
(basename may change; old path soft-deleted when it changes).  Path
uniqueness is enforced per repository across all projects sharing it.
`file_dwg_primary_register()` is the single registration choke point — it
verifies `<sha>:<git_path>` exists, checks collisions, writes the row, and
pins the approved ref; uploads layer on it.

**`{dwg_primary_file}` columns:** `git_path`, `git_sha` (on-record SHA),
`git_branch`, `filename` (= basename of git_path), `filesize`, `file_type`.
No `folder`/`content` columns, and NO `project_id` column — project ID must
always be derived via `dwg_get_field($dwg_id, 'project_id')`.

**Push is mandatory:** `git commit` only writes to the working tree's local
history. The bare repo receives nothing until `git push` is called.
`GitFileStorageBackend::store()` always pushes as its final step.

**HOME fix:** web-server PHP workers run without `HOME` (PHP-FPM clears the
environment by default; Apache did the same), so git cannot find
`/var/www/.gitconfig`. `GitFileStorageBackend::ensure_git_home()` calls
`putenv('HOME=...')` using `posix_getpwuid()` before every git operation.
This is transparent at runtime but explains why manual `sudo -u www-data`
tests always worked while web requests failed before the fix was in place.

**Branch name:** `init.defaultBranch = main` in `/var/www/.gitconfig` ensures
new repos use `main`. The PHP backend calls `getCurrentBranchName()` before
every push rather than hardcoding `'main'`, so both `main` and `master` repos
are handled correctly. ZIP imports create `dev`. See "Branch naming: main vs
master" in [doc/git/GIT_SERVER_SETUP.md](doc/git/GIT_SERVER_SETUP.md) for how
to rename an existing `master` branch.

**Soft delete:** `git rm` + commit + push. The file disappears from HEAD but
the full commit history is retained in the bare repo.

**Duplicate content:** if `git commit` exits 1 (nothing to commit — identical
content re-uploaded), the backend treats it as a successful store and returns
the existing HEAD SHA.

### Key files (git storage)

| File | Role |
|------|------|
| [core/repository_api.php](core/repository_api.php) | `{repository}` entity: CRUD, project resolution, disk lifecycle, `repository_adopt()`, rename relocation, storage lock |
| [core/file_dwg_api.php](core/file_dwg_api.php) | `file_dwg_primary_register()`, path template/sanitizer/collision, project-keyed wrappers, worktree sync, approved-SHA pinning |
| [core/classes/GitFileStorageBackend.class.php](core/classes/GitFileStorageBackend.class.php) | store/retrieve/delete via `git_path` |
| [core/git_http_api.php](core/git_http_api.php) | Smart HTTP gateway — resolves `-r<id>`, authorises against the repository's owner project |
| [core/git_store_api.php](core/git_store_api.php) / [manage_git_store_page.php](manage_git_store_page.php) | Manage → **Git Store** (administrators, read-only, work in progress). Lists every repository in the database or on disk and flags mismatches. For a selected repository it shows: files by folder with search; per-file history, preview and download (`manage_git_store_download.php`); registered documents with On Record vs HEAD state; commits; refs, including approved-version pins; and the server worktree |
| [core/constant_inc.php](core/constant_inc.php) | `define('GIT', 3)` (still used by attachment mapping) |
| [config_defaults_inc.php](config_defaults_inc.php) | `$g_git_storage_root`, `$g_git_worktree_root`, `$g_dwg_repo_path_template` |
| [core/file_api.php](core/file_api.php) / [core/print_dwg_api.php](core/print_dwg_api.php) / [file_download.php](file_download.php) | Attachment-side `GIT → DATABASE` fall-throughs |
| [admin/import-git-repo.php](admin/import-git-repo.php) | Repository importer: adopt an existing git repo as a project (+ sub-projects) and register its files as documents by reference. `--dry-run`/`--update`; `.doctis` manifest or CLI flags; run as www-data (wrapper: `admin/tools/doctis-git-import.sh`). See [doc/git/GIT_IMPORTER.md](doc/git/GIT_IMPORTER.md) |

### Integration tests

```bash
# Pure git mechanics (no core bootstrap)
sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php

# Mapping layer (bootstraps core) — sanitizer, repository entity/resolution,
# path templates, register-by-reference, collisions, dangling paths,
# sync-to-HEAD, repository adoption, rename relocation
sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php
```

Both create and tear down their own state and must pass before enabling git
storage on any new server.

### Server infrastructure status (native VM) — current state

| Item | Status |
|------|--------|
| `/var/git/doctis/` | `www-data:www-data`, mode `2770`; `robert` reads it through the `www-data` group |
| `/var/www/doctis/worktrees/` | `www-data:www-data`, mode `2770` |
| `/var/www/.gitconfig` | Server identity `Doctis <doctis@vaio.local>` (carried over from vaio), `init.defaultBranch=main`, `safe.directory=*` |
| `czproject/git-php v4.4.0` | Installed via Composer into `vendor/` |
| Repositories | One per top-level project, created on import or at first upload: currently `hcrqms-r1` and `lidar-r2` (imported, branch `dev`) and `hcr571-r3` (`main`); each has the pre-receive hook and `http.receivepack=true` |
| `$g_file_upload_method` / `$g_dwg_upload_method` | `DISK` / `GIT` in `config/config_inc.php` — attachments only (`GIT` maps to DATABASE); primaries are always git |

## AI Assistant Feature

The Doctis AI Assistant (`ai_assist_page.php`) embeds a Claude-powered chat interface
using the Anthropic Messages API.  The Help tab answers from the user manual
(`doc/MANUAL.md` — keep it current), a live map of the pages the user can open, and a
knowledge base users teach and managers review (`{ai_knowledge}`, shared with the Meeting
tab; see [doc/ai/ai-knowledge.md](doc/ai/ai-knowledge.md)).  The Meeting tab plans and minutes
meetings (`{meeting}`, `{meeting_action}`; `core/meeting_api.php`,
`core/meeting_calendar_api.php`, `core/meeting_action_api.php`), stores agendas and
minutes as Doctis documents in `$g_meeting_project_id`, emails participants with
calendar invitations, and turns approved actions into issues.  Repeating series are
scheduled by `scripts/meeting_schedule.php` (cron; makes API calls and sends email
unattended).  Meetings are listed on the **My Meetings** My View tab and managed on
`meeting_view_page.php`.  Built on branch `ai-meeting`, verified end to end on the
native VM, and merged into `dev` on 2026-10-08 after the PHPUnit `mantis` suite
passed; the owner's wider test sweep is still open (`doc/ai/ai-todo.md` §7).  SOP
and Other tabs are placeholders.  Before working on any AI-related code, read:

- **[doc/ai/ai-todo.md](doc/ai/ai-todo.md)** — living status and to-do: what is built,
  what is broken, how the pipeline works, configuration, and the prioritised to-do
  list.  Update this document as work is completed or decisions change.
- **[doc/ai/ai-engine.md](doc/ai/ai-engine.md)** — original concept plan and platform
  assessment, with an as-built differences section.  Read before making structural
  changes to the AI pipeline.

Key files (one PHP and one JS module per tab):

| File | Purpose |
|------|---------|
| `ai_assist_page.php` | Page shell: auth, shared CSS, tab layout, `<script src>`; includes `ai_assist_help_page.php` / `ai_assist_meeting_page.php` |
| `ai_assist_api.php` | AJAX endpoint: validation, `load`/`clear`/`chat`, cURL to Anthropic, `{ai_sessions}` |
| `ai_assist_help_api.php` / `ai_assist_meeting_api.php` | Server-side system prompts; meeting document processing |
| `js/ai_assist.js` (+ `_help.js`, `_meeting.js`) | Client-side JS — **must** be external (CSP `script-src 'self'`) |

The API key (`$g_anthropic_api_key`) is set in `config/config_inc.php` (not committed).
The sidebar button is suppressed entirely when the key is blank.

**Adding new AI tables:** Follow the standard schema change workflow: append a
step to `admin/schema.php` and run the upgrade; changing an existing table
needs a rebuild.  See **Database Schema Changes** above for the full procedure
and conventions.  See §3 and §6 of `doc/ai/ai-todo.md` for `{ai_sessions}`
notes.  On the native VM a rebuild also wipes imported data and the meetings
stored in it — follow
[admin/tools/data-scripts/README.md](admin/tools/data-scripts/README.md).

## Known Architectural Trade-offs

1. **Filter hacks** — [core/filter_api.php](core/filter_api.php) lines 101, 1156, 1192 contain admitted "quick'n'dirty" hacks to route document queries through the bug filter pipeline without changing all call sites.
2. **`document_api.php`** — currently a thin wrapper; some category-style logic was copied from `category_api.php` and has a stale file header.
3. **REST API** — `api/rest/restcore/documents_rest.php` and `licenses_rest.php` provide CRUD for documents and licenses, and `topics_rest.php` is read-only. Document file operations (primary file, attachments) are SOAP-only (`mc_dwg_*`). See [doc/api/REST-AND-SOAP-API.md](doc/api/REST-AND-SOAP-API.md).
4. **Bulk import** — documents can be imported only from files: Manage → **Import Data** (a ZIP upload becomes a new project and repository; file patterns default to `*.md`) or `admin/import-git-repo.php` (adopt an existing git repository). Neither imports document metadata from a spreadsheet or other structured source.
5. **Two attachment config keys; primaries are git-only** — bug/issue attachments use `$g_file_upload_method`; document attachments use `$g_dwg_upload_method` (*separate* keys, attachments only). A `GIT` value in either falls back to DATABASE for attachments. The **primary registered document** ignores both — it is always stored in git (no configuration). This is intentional — attachments are "assisting metadata", not revision-controlled. See `doc/git/GIT_ARCHITECTURE.md`.
6. **`file_api.php` switch statements** — MantisBT's original bug attachment code has multiple `switch($file_upload_method)` blocks. Each new storage method requires a case in all of them. Discovered locations: upload (around line 1007), MIME detection (around line 1317), content output (`file_download.php`, which remaps `GIT` around line 324). Search for `file_upload_method` and `dwg_upload_method` when adding future methods.
7. **Git infrastructure is an install prerequisite** — primary-document storage requires the git binary, `$g_git_storage_root`, and `$g_git_worktree_root` to be functional; there is no DATABASE fallback for primaries. `install-target.sh`:`configure_target()` writes the storage paths into `config_inc.php` before `install_git_storage()` has run — if `install_git_storage()` subsequently fails (git not installed, permission error creating `/var/git/doctis/`), primary-document uploads will error at runtime. **Future hardening:** `install_git_storage()` failure should abort the install, and `admin/check` should verify the git binary and storage-root permissions.
