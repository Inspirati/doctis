# CLAUDE.md — Doctis Project Guide

## What is Doctis?

Doctis (**Doc**ument **I**ssue **T**racking **S**ystem) is a fork of [MantisBT](https://github.com/mantisbt/mantisbt) v2.27.x extended to track **documents** and the issues raised against them during formal review cycles. It is a PHP/MariaDB web application.

The project is actively developed (beta) and maintained on the `dev` branch.

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

## Minimise — but do not avoid — Changes to Shared MantisBT Code

Doctis follows upstream structure closely and avoids renaming MantisBT
variables, so that comparing a `dwg_*` module against the file it was copied
from stays useful. When adding Doctis functionality, **prefer** parallel
files and functions over in-place edits to shared code.

**This is a preference, not a prohibition** (owner clarification 2026-10-23).
The fork was taken years ago and MantisBT has moved on since; keeping the
codebase merge-compatible with upstream is no longer a credible mandate. So:

- Modify shared MantisBT code when it is justified — a defect in it, a missing
  invalidation, a gate that is wrong for documents.
- Fix upstream defects you find rather than working around them. A helper that
  should exist and does not is a reason to add it, not a reason to reach into
  globals from a caller.
- Keep such changes small, commented with why, and separable in the history.

Do not treat "it is MantisBT code" as a reason to leave something broken, or
to build an awkward parallel path around it.

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
registered against a user account. Documents can be marked as requiring one or
more licenses. This is entirely unrelated to software licensing; the term is
Doctis-specific.

**Read [doc/dwg/LICENSES.md](doc/dwg/LICENSES.md) before touching this
feature.** It is a **half-baked concept still under development** (owner,
2026-10-24) and the code does not mean what it looks like it means:

- A license was designed as an **indicator only**. Doctis began as a document
  *index*, and enforcement of access to the material was to be the external
  data store's job. A user may apply any license to their own profile; the list
  is a self-administered record of applications and acceptances, **not a
  security grant**.
- That premise no longer holds now Doctis stores documents itself and may hold
  restricted material, so the feature **may need extensive revision**. The
  owner has deferred that; do not redesign it unprompted.
- Names are matched loosely on purpose — `match_str` folds case and strips all
  punctuation — to help users applying, not to police naming.
- `{license_user_list}.status` carries applied (10) vs accepted (20), but
  **nothing ever writes 20**, and its update path writes access levels into the
  same column.
- The two consumers disagree: `topic_user_holds_license()` requires status 20
  (faithful, but therefore never true), while `access_dwg_user_holds_licenses()`
  counts any row (so self-application satisfies it). Licenses gate document
  **ownership**, Topic **assessment** and **publishing** — they do **not** gate
  reading, downloading or cloning.

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
version to HTML with pandoc. **Publish All Documents** on Manage Project →
Publishing does the whole site (`dwg_publish_all()`, `dwg_publish_all.php`),
deferring the navigation rebuild to the end instead of after every document.
Measured on 101 documents that saves only ~5% (131.6 s vs 138.1 s) — pandoc
dominates at ~1.3 s a document — so the point is one action with one report,
not speed. It runs in **budgeted 20-second steps** that resume via a
self-submitting form, because an unbounded pass exceeds nginx's 60-second
`fastcgi_read_timeout` and returns 504 while PHP carries on invisibly. **Strip path prefix**
(`dwg_publish_strip`) drops a leading repository directory from page paths,
so HCRQMS's `content/system/x.md` publishes as `system/x.html` rather than
burying the site one level down. The page goes into the project's publication
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

`https://qms.hcrobo.com/doctis` — a remote Docker deployment of `dev`, the
production path. **Nobody has console access**, so everything is done through
the browser or with curl. It gets no sample data.

**Reading pages is fine. Ask the owner before any change to it** — Git Pull,
Rebuild Database, Reset Git Store, repairs, user or config edits. Claude's
`claude` administrator credentials live outside the repository in
`~/.config/doctis/live-claude.env`; pages that change anything call
`auth_reauthenticate()`, so log in afresh immediately before each action.

Step-by-step procedures, including the full rebuild-and-reimport runbook:
**[doc/LIVE_RUNBOOK.md](doc/LIVE_RUNBOOK.md)**.

## Diagnosing PHP Errors

PHP errors and exceptions go to `/var/log/doctis/php_errors.log`; nginx to
`/var/log/nginx/doctis_error.log`; cron to `/var/log/doctis/cron.log`.
Manage → System Operations → **PHP Error Log** shows the newest entries and is
the only way to read them on live, which has no console. There is no Xdebug.

Log locations in full, how MantisBT's exception handler behaves,
`$g_show_detailed_errors`, adding temporary `error_log()` calls, and the error
signatures that recur with the git backend:
**[doc/PHP_ERROR_DIAGNOSIS.md](doc/PHP_ERROR_DIAGNOSIS.md)**.

## Upstream Sync Process

Upstream MantisBT changes are intended to flow in on a dedicated sync branch,
then be merged into `dev`. **No such branch currently exists** — neither
locally nor on `origin` — so there is no sync in progress; create one when a
sync is actually undertaken. `origin/original` and `origin/original-as-forked`
are the before-fork reference points and `origin/master` is the pre-fork
default; all three are kept deliberately and must not be deleted.

Since 2026-10-23 the practice is that **a feature branch is deleted as soon as
it is merged**, and the merge commit — not the branch name — is what gets
recorded in the documentation. A document naming a branch that no longer exists
has already misled a session into reporting merged work as unmerged.

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
Never apply an `ALTER TABLE` that `schema.php` does not already describe.**

Every table is defined once, in its current final form, as a
`CREATE TABLE IF NOT EXISTS` step in [admin/schema.php](admin/schema.php). Each
step's index is the `database_version` the installer records after running it.

- **New table:** append a step at the end, label it "Step N" with its real
  index, and update the trailer comment (`$t_idx = N+1 → database_version = N`).
  Deploy, then run `php admin/upgrade_unattended.php` in the running clone. No
  rebuild is needed.
- **Change to an existing table** (add, rename or retype a column; change a
  key; change a default): edit its `CREATE TABLE` block in place. Then bring
  each database up to it in one of two ways:
  - **Rebuild** (see below). The default on the VM.
  - **Schema update script** (owner decision 2026-10-31): an `.sql` file in
    [admin/tools/schema-updates/](admin/tools/schema-updates/), run through
    System Operations → **Load Data**. This is how live takes a release
    without a rebuild. The script's `ALTER`s must produce exactly what
    `schema.php` now says. If the release also appends steps, the script runs
    them (generated from `schema.php`, not retyped) and sets
    `database_version` last. Test it on a VM database at the old schema first.
    Afterwards compare `SHOW CREATE TABLE` on the target with a fresh install
    — an `ALTER` does not bump `database_version`, so nothing else will notice
    a missed or wrong one. Data-moving changes (renames, retypes of filled
    columns) need extra care, and a rebuild stays the fallback.
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
| `ALTER TABLE doctis.foo ADD COLUMN ...` with no matching edit to schema.php | Schema.php will not reflect the change; a future rebuild will silently drop the column |
| Standalone `admin/foo_migrate.php` scripts | The installer does not call them; they create false confidence that the schema is up to date. A schema update script is a one-off Load Data file for a database built before the edit, not a migration layer |
| `AddColumnSQL` / `AlterColumnSQL` entries appended to schema.php | Contradicts the flat-schema design; makes the definition hard to read and impossible to audit against the live DB |

## Resetting to a Clean Slate for Testing

**Always reset the database and the git store together** — they reference each
other, and resetting one alone leaves them inconsistent. On the VM **no backup
is needed first** (owner decision 2026-10-20); clean feature testing is
expected to start from a rebuild.

```bash
# One command, with sample data:
sudo bash /var/www/html/doctis/admin/tools/doctis-reset-native-test.sh --execute
```

Step-by-step without sample data, the individual scripts, and how to verify the
result: [doc/TESTING.md](doc/TESTING.md) §8. Reload order and data scripts:
[admin/tools/data-scripts/README.md](admin/tools/data-scripts/README.md).

## Curl-Based Testing

Doctis can be driven entirely over HTTP when a browser is not available —
useful for both the VM and live. MantisBT's login is a **two-step POST**, every
form carries a `form_security_field()` token named `<action>_token`, and pages
needing a project redirect through `set_project.php`, so follow redirects
with `-L`.

The mechanics — login, project context, reading CSRF tokens, creating
documents, uploading and downloading primary files, and diagnosing failures:
**[doc/CURL_TESTING.md](doc/CURL_TESTING.md)**. For *what* to test with them,
[doc/TESTING.md](doc/TESTING.md) §7.

## SOAP API Testing and Diagnostics

The SOAP API (`api/soap/mantisconnect.php`) is the primary programmatic
interface for document *file* operations — the `mc_dwg_*` endpoints. REST does
not cover them.

Two traps worth knowing before you start: raw curl callers must
**double-encode** `base64Binary` parameters (PHP's SoapServer pre-decodes them,
and SoapClient compensates), and a *Procedure not present* fault is usually
OPcache holding a stale `mc_core.php` rather than a missing function.

The smoke test, raw curl envelope template, diagnostic one-liners, commit
attribution checks and the full *Procedure not present* checklist:
**[doc/api/SOAP_DIAGNOSTICS.md](doc/api/SOAP_DIAGNOSTICS.md)**. Operation
reference: [doc/api/DOCTIS-SOAP.md](doc/api/DOCTIS-SOAP.md).

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

**Primary registered documents are stored exclusively in git.** There is no
configurable method for them — `file_dwg_get_storage_backend()` returns
`GitFileStorageBackend` unconditionally, and a working `git` binary is an
install prerequisite. `$g_file_upload_method` and `$g_dwg_upload_method` govern
**attachments only**, where a `GIT` value falls back to `DATABASE`.

Four rules that bite if forgotten:

- **Path-as-data.** A primary file's repo-relative path is stored in
  `{dwg_primary_file}.git_path`, never computed at read time. There is **no
  `project_id` column** — derive it with `dwg_get_field( $dwg_id, 'project_id' )`.
- **`file_dwg_primary_register()` is the single registration choke point.** It
  verifies `<sha>:<git_path>` exists, checks collisions, writes the row and
  pins the approved ref.
- **Push is mandatory.** `git commit` only writes the worktree's local history;
  the bare repo gets nothing until `git push`. `GitFileStorageBackend::store()`
  always pushes as its final step.
- **Web PHP workers have no `HOME`**, so git cannot find `/var/www/.gitconfig`.
  `ensure_git_home()` sets it before every git operation — which is why manual
  `sudo -u www-data` tests passed while web requests failed before the fix.

Repositories are a first-class entity (`{repository}`, basename
`<slug>-r<id>`); projects map to them via `{project_repository}`, inheriting
from the nearest linked ancestor, so a project tree shares one repository by
default.

| Read this | For |
|-----------|-----|
| [doc/git/GIT_ARCHITECTURE.md](doc/git/GIT_ARCHITECTURE.md) | the architecture: separation of concerns, repository entity, backend classes, operations, On-Record vs Draft, Smart HTTP gateway |
| [doc/git/GIT_STORAGE_NOTES.md](doc/git/GIT_STORAGE_NOTES.md) | working notes: naming and collision rules, the Git Store page, key source files, VM infrastructure state |
| [doc/git/SOURCE_REPOSITORIES.md](doc/git/SOURCE_REPOSITORIES.md) | source release records (`{dwg}.kind`, `{dwg}.frozen`) and the per-repository push policy |
| [doc/git/GIT_LFS.md](doc/git/GIT_LFS.md) | Doctis as the LFS server |
| [doc/git/GIT_SOLUTION_SPACE.md](doc/git/GIT_SOLUTION_SPACE.md) | why it was built this way |

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
`meeting_view_page.php`.  Built and verified end to end on the
native VM, and merged into `dev` as 3d1fb2f3b on 2026-10-08 after the PHPUnit `mantis` suite
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
