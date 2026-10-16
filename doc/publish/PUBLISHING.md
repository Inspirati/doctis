# Document Publishing

Status: **built on branch `publish` and tested on the native VM**
(2026-10-15). Not merged into `dev`; not on the live version. Test record: §8.

Doctis can publish a Markdown document to a static website. **Publish** on
the document view page converts the document's **On Record** version to HTML
with pandoc and writes it into the project's *publication target directory*.
The header of every page shows the document's Doctis status (DRAFT, APPROVED,
...).

The build is a port of HCRQMS `engine/scripts/build-html.sh`, one document at
a time. It produces the same page layout, banner and collapsible sidebar, and
rewrites `.md` links to `.html`. A site built by Doctis therefore looks like the
`build-html.sh` preview site. It holds only the documents someone has
published.

Code: [core/dwg_publish_api.php](../../core/dwg_publish_api.php),
[dwg_publish.php](../../dwg_publish.php),
[dwg_view_publish_inc.php](../../dwg_view_publish_inc.php),
[manage_proj_publish_inc.php](../../manage_proj_publish_inc.php) /
[manage_proj_publish_update.php](../../manage_proj_publish_update.php),
[core/publish/](../../core/publish/) (default template and link filter).
No schema change.

---

## 1. What a publish does

1. Checks that the document can be published. Its project, or the nearest
   ancestor project, must have a usable target directory. It must have a
   registered `.md` or `.markdown` file. It must not be private (view state),
   and it must not be restricted by a license.
2. Reads the On Record file (`{dwg_primary_file}.git_sha` at its `git_path`)
   from the project repository. A Draft is never published.
3. Runs pandoc with the same options as `build-html.sh`:
   - `--from=markdown-raw_tex --to=html5`. Raw TeX is off, so text such as
     `CzProject\GitPhp` is kept rather than dropped as a LaTeX command.
   - the site template
   - [core/publish/rewrite-links.lua](../../core/publish/rewrite-links.lua)
   - a generated banner and sidebar (`--include-before-body`)
   - Doctis metadata for the template (§4), and the document-control block
     (`core/publish/control-block.lua`)
4. Writes the page at `<target>/<git_path with .md replaced by .html>`, e.g.
   `system/procedures/Document-Control-Procedure.html`. Relative links between
   published documents therefore work as they do in the repository.
5. Rebuilds the sidebar in every other page of the site, and rewrites
   `index.html`, a table of the published documents with number, revision,
   status and publish date.
6. Logs a **Published** entry in the document history: commit, status and
   page.
7. Sets the document's **URL** field (`link_url`) to the page's address, and
   logs that in the history too. The field only accepts absolute http(s)
   URLs, so a relative website URL such as `/site/` takes the scheme and host
   of `$g_path`. On the command line (the cut-over script), `$g_path` must be
   set in `config_inc.php` for this to produce the right host. The field is
   left alone when the site has no website URL.
   - Publishing overwrites any earlier value, including a hand edit; the old
     value stays in the history.
   - Unpublish clears the field only if it still holds the published URL.
   - `$g_dwg_publish_set_link_url` (default ON) turns this off.

**Republish** does the same again. Use it after a newer version is promoted
to On Record or the status changes. The Published row on the view page marks
the page **out of date** when either has changed since the page was
published. **Unpublish** removes the page, prunes empty directories and
rebuilds the sidebar and index. Deleting a document unpublishes it too.

### The site describes itself

Every page Doctis writes ends with a marker comment:

```html
<!-- doctis-publish {"dwg_id":12,"project_id":1,"path":"system/...md","sha":"…","title":"…","number":"PROC-SYS-001","revision":"A","status":180,"status_label":"APPROVED","published":1791…,"user_id":3} -->
```

The sidebar and index are built from these markers by scanning the target
directory, not from the database. Projects that share a target directory
therefore share one site. The rules that follow from this:

- A file without a marker is never overwritten or removed. Publishing to an
  occupied path fails, and so does a path published from another document.
  A hand-made `index.html` is left alone and no index is generated.
- A document published at the site root as `index.md` replaces the generated
  index.
- Hidden files and directories and symbolic links are skipped. The lock file
  is `<target>/.doctis-publish.lock`.

The sidebar sits between `<!-- doctis-nav -->` and `<!-- /doctis-nav -->` in
each page, so it can be replaced without running pandoc again.

---

## 2. Setting it up

### Server (administrator)

1. **Install pandoc 3.x** (`apt-get install pandoc`). Both installers now
   include it. The VM already has 3.1.11 at `/usr/bin/pandoc`. Debian's
   package works, and the HCRQMS scripts also need pandoc 3.
2. **Allow publishing roots** in `config/config_inc.php`. Only this file can
   set them; the database cannot override them:

   ```php
   $g_dwg_publish_roots  = array( '/var/www/html/site' );
   $g_dwg_publish_pandoc = '/usr/bin/pandoc';   # default
   ```

   With no roots (the default), publishing is off and its controls are hidden.
3. **Let the web server write there.** On the VM, `/var/www/html/site` is
   `robert:www-data 0755`, so `www-data` cannot write yet:

   ```bash
   sudo chmod 2775 /var/www/html/site     # group www-data, setgid keeps the group
   ```

4. **Serve it.** The site is plain static files. The web server must use
   `index.html` as a directory index, so that `/site/` serves the generated
   index. The native nginx profile had only `index index.php;`, so `/site/`
   returned 403. Its template (`admin/tools/templates/nginx-doctis.conf`) and
   the VM now use `index index.php index.html;` (2026-10-15). Doctis writes
   only `.html` files there. Note that this nginx runs any `.php` file under
   `/var/www/html` (for example `site/phpinfo.php`).

### Project (manager)

**Manage → Projects → (project) → Publishing:**

| Field | Example (HCRQMS on the VM) | Notes |
|-------|----------------------------|-------|
| Target directory | `/var/www/html/site` | Absolute; at or below a root. Created if missing. Blank: use the parent project's site. |
| Website URL | `/site/` | Where the site is served. Used for the links on the view page. Must start with `/`, `http://` or `https://`. |
| Template | `engine/pandoc/html-template.html` | Repository path on the default branch. Blank: the Doctis default template. |
| Banner logo | `engine/pandoc/logo.svg` | SVG/PNG/JPEG repository path, embedded in each page. Blank: the project name. |
| PDF | ticked | Also publish a PDF of each document (§9). Needs the PDF packages. |

The settings are ordinary per-project config values (`dwg_publish_target`,
`dwg_publish_url`, `dwg_publish_template`, `dwg_publish_logo`). A sub-project
without its own target uses the nearest ancestor's settings, like repository
inheritance, so HCRQMS sub-projects publish into the HCRQMS site.
`$g_dwg_publish_threshold` (default MANAGER, per project) controls who may
publish and unpublish. Saving checks the target against the roots, and checks
that the template and logo exist on the default branch.

**HCRQMS on the VM and the live version.** The imported repositories hold
only `content/` and `system/`, so `engine/pandoc/` is not there. Until it is
added (pushed through the Git gateway, or included in a re-import), leave
Template and Banner logo blank. The Doctis default template is the HCRQMS
layout with a neutral title.

### Live version (Docker)

The image has no pandoc, and only uploads, config, the git store, worktrees,
logs and sessions are bind-mounted. Before enabling publishing there, the
administrator needs to:

- add `pandoc` (version 3 or later) to the Dockerfile packages, plus the PDF
  packages in §9 if PDFs are wanted;
- create the site directory as a bind mount, so pages survive a container
  rebuild;
- set `$g_dwg_publish_roots`;
- make the front proxy serve the site.

---

## 3. Using it

On the document view page:

- **Publish** / **Republish** sits with Monitor, Stick, Clone, Close, Move and
  Delete. It is shown when the user meets `$g_dwg_publish_threshold` and the
  document can be published.
- The **Published** row of the Primary Document panel shows:
  - the page, linked when the site has a URL;
  - the status it was published with;
  - when it was published, and by whom;
  - the commit, with **out of date** when the On Record commit or the status
    has changed;
  - **Unpublish**.

Errors appear as `Publishing: <reason>`, e.g. `Publishing: only Markdown
documents (.md) can be published.`

---

## 4. Templates

A template is a pandoc HTML template, like HCRQMS
`engine/pandoc/html-template.html`. Doctis supplies the banner and sidebar
through `$include-before$`, using the `build-html.sh` markup: `.site-banner`,
`.nav-toggle`, `.sidebar`, `.nav-tree`, `.nav-dir`, `.nav-file` and
`.sidebar-resizer`. It also supplies these values:

| Variable | Example | |
|----------|---------|---|
| `pagetitle` | Document Control Procedure | Doctis title (filename if blank) |
| `doctis-status` | APPROVED | Status label, upper case |
| `doctis-status-key` | `approved`, `independent-review` | For CSS classes |
| `doctis-status-color` | `#a2b9bc` | From `$g_status_colors` |
| `doctis-number`, `doctis-revision`, `doctis-title` | PROC-SYS-001, A | Document fields |
| `doctis-id`, `doctis-project` | 12, HCRQMS | |
| `doctis-commit` | `8feccbc1` | On Record commit (short) |
| `doctis-published` | 2026-10-15 10:00 | Publish time |
| `doctis-url` | `http://…/dwg_view.php?id=12` | The document in Doctis |
| `doctis-classification` | Internal | Classification; shown in the banner, footer and PDF margins |
| `doctis-owner`, `doctis-approved`, `doctis-effective`, `doctis-review`, `doctis-record` | | Owner (assigned user); "date by name" of the approval while the status is in `$g_dwg_publish_approved_statuses`; release date; due date; On Record commit and date |

**The document-control block.** `core/publish/control-block.lua` (a Doctis
filter) inserts a table after the document's first heading, in the HTML page
and the PDF alike. Its rows are number, title, revision, status,
classification, owner, approved, effective, next review, On Record and
published; empty rows are left out. Pandoc builds it from the metadata
above, so it works with any template and every value is escaped. HTML
templates can style `div.doctis-control`. `$g_dwg_publish_control_block`
(default ON, per project) turns it off. This is what replaces the
hand-written metadata lines in the documents (METADATA_CUTOVER.md).

The index page gets only `pagetitle` and `doctis-project`. User-entered values
are passed with `--metadata`, which pandoc HTML-escapes. Only the computed key
and the validated colour are passed with `--variable`, whose values pandoc
inserts unescaped.

**The status indicator.** Both templates show `doctis-status` as a badge at
the right of the banner, coloured with `doctis-status-color`:

```html
$if(doctis-status)$
<div class="doctis-status" title="Document status in Doctis"><span style="--doctis-status-color: $doctis-status-color$">$doctis-status$</span></div>
$endif$
```

The second template is the HCRQMS `engine/pandoc/html-template.html`; this
change is in the `../HCRQMS` working tree and is not committed yet.
`build-html.sh` sets no `doctis-status`, so its preview site is unchanged.
The Doctis default template also has a footer: number, revision, status,
publish time, commit and a link to the document in Doctis.

**Restrictions.**
- Templates that use pandoc **partials** (`${ name() }`, `$name()$`) are
  refused. A partial can read any `.html` file the web server can read, e.g.
  `${ /var/www/x() }`, and pandoc's `--sandbox` does not stop this (tested).
- Doctis runs only its own Lua filter, never one from a repository, because
  Lua filters can run programs.
- Pandoc runs without a shell, with `--sandbox`, in a private temporary
  directory.

---

## 5. Security notes

- **Where files go.** Targets must lie under `$g_dwg_publish_roots`, which only
  `config_inc.php` can set. Doctis also refuses:
  - targets that overlap the Doctis installation, the git store, the worktrees
    or the default upload folder;
  - symbolic links that lead outside the root;
  - paths with `..`.
  Doctis writes only `.html` pages, atomically, and never replaces a symbolic
  link.
- **What gets published.** Private documents and license-restricted documents
  are refused. Publishing makes a document readable by anyone who can reach
  the site. The site has no Doctis access control.
- **Same origin.** On the VM the site is served from the same origin as
  Doctis (`http://10.0.0.109/site/` and `/doctis/`). Pandoc passes raw HTML in
  Markdown through, so a document containing `<script>` would run with Doctis's
  origin when the page is viewed. Publishing is limited to managers for this
  reason. A separate host name for the site removes the risk. Stripping raw
  HTML is an option (§7).

---

## 6. Files

| File | Role |
|------|------|
| `core/dwg_publish_api.php` | Settings, validation, page rendering, sidebar/index rebuild, unpublish |
| `core/publish/dwg_publish_template.html` | Default template (HCRQMS layout, status badge, footer) |
| `core/publish/rewrite-links.lua` | `.md` → `.html` link filter |
| `dwg_publish.php` | POST action: publish / unpublish |
| `dwg_view_publish_inc.php` | Published row on the view page |
| `manage_proj_publish_inc.php`, `manage_proj_publish_update.php` | Project settings |
| `dwg_view_inc.php` | Publish button (`dwg_view_action_buttons()`), row include |
| `manage_proj_edit_page.php` | Settings include |
| `core/dwg_api.php` | `dwg_delete()` unpublishes |
| `config_defaults_inc.php` | `$g_dwg_publish_*`; roots, pandoc and PDF engine in `$g_global_settings` |
| `tests/Mantis/DwgPublishTest.php` | Unit and filesystem tests; opt-in end-to-end test |

---

## 7. Limitations and next steps

- **One document at a time.** The next step is a project-level **Publish all**
  (or "publish everything approved") that rebuilds the whole site.
- **Status changes do not republish.** A page keeps the status it was
  published with until someone republishes it; the view page flags it. Options:
  - republish automatically when the status or the On Record version changes;
  - unpublish automatically on archive. ISO 9001 7.5.3 asks for obsolete
    documents to be withdrawn.
- **Status gate.** Any status can be published, and the badge shows it. A
  per-project setting such as "publish only Approved" may be wanted.
- **Images and other assets** referenced from Markdown are not copied. Only
  Markdown documents can be published. The PDF has no logo yet.
- **Moving a document to a project with another site** leaves its old page
  behind. Unpublish it first.
- **Raw HTML** passes through (see §5). A `$g_dwg_publish_raw_html` option
  could add `--from=markdown-raw_html`.
- **The pages show the Doctis status**, but the HCRQMS documents also state
  "Status: Draft" in their text. Remove that line from the documents, or
  generate it from metadata.

---

## 8. Testing

```bash
cd /var/www/html/doctis
sudo -u www-data vendor/bin/phpunit --testsuite mantis --filter DwgPublishTest --testdox
# end-to-end: publish and unpublish a real public Markdown document into a temporary site
sudo -u www-data DOCTIS_PUBLISH_TEST_DWG=<id> vendor/bin/phpunit --testsuite mantis --filter DwgPublishTest
```

Page checks (curl as `manager`, or a browser):
1. set `$g_dwg_publish_roots` and make the site directory writable (§2);
2. on HCRQMS → Publishing, set target `/var/www/html/site` and URL `/site/`;
3. publish two documents in different folders. Check the badge, the sidebar
   in both pages, `index.html`, the Published row and the history entry;
4. change the status: the row says out of date; Republish clears it;
5. Unpublish one document: the other page's sidebar and the index drop it.

**PDF, 2026-10-15:** `DwgPublishTest` grew to 19 tests. They include a PDF
build that must print raw `\input{...}` as text without reading the file,
and must keep an em dash in strikethrough. The end-to-end test now also
checks the PDF and its removal. All passed, and the full suite passed (314
tests). With PDF ticked for HCRQMS, republishing documents 93 and 2 wrote
both PDFs, served as `application/pdf`. The footer read `PLAN-ENG-001 Rev A
— DRAFT`, with links on the page, the index and the view page.

**Run 2026-10-15 on the native VM** (running clone on `publish`):
- `DwgPublishTest`: 17 tests passed, including the end-to-end test on document
  2. The full `mantis` suite passed: 312 tests, 1 pre-existing skip.
- All five page checks passed over HTTP as `manager`:
  - HCRQMS Publishing settings were saved, and Engineering showed that it
    inherits them;
  - documents 93 (`system/procedures/Document-Control-Procedure.md`) and 2
    (`content/engineering/Engineering-Management-Plan.md`) published;
  - pages, sidebars and index were served at `http://10.0.0.94/site/`;
  - setting document 93 to approved directly in the database raised "out of
    date", and Republish showed the APPROVED badge (status restored
    afterwards);
  - Unpublish/Publish of document 2 pruned and restored the folders, sidebar
    and index;
  - history entries were logged, with no PHP errors.

**VM state after the run.**
- `config_inc.php` sets `$g_dwg_publish_roots = array( '/var/www/html/site' )`.
- `/var/www/html/site` is mode 2775.
- HCRQMS (project 1) publishes to `/var/www/html/site` at `/site/`.
- Documents 93 and 2 are published (DRAFT) as a demonstration; unpublish them
  from their view pages if they are not wanted.

---

## 9. PDF output

**Built 2026-10-15.** When a project ticks **PDF** in its Publishing
settings, each Publish also writes a PDF next to the page: same path, `.pdf`.
- The HTML page footer, the index (a PDF column) and the Published row on the
  view page link to it.
- Unpublish and document deletion remove it. Republishing with PDF turned off
  removes it too.
- It is built from the same On Record version
  (`dwg_publish_build_pdf()`), with the settings below.
- The footer's number, revision and status come from Doctis and are
  LaTeX-escaped.
- `$g_dwg_publish_pdf_engine` (default `/usr/bin/xelatex`; config_inc.php only)
  and `$g_dwg_publish_pdf_papersize` (default `letter`, like `build-pdf.sh`;
  or `a4`) control the build.
- The PDF engine adds about 2 s to each Publish.

The tool chain was installed and tested on the native VM on 2026-10-15. It is the LaTeX route
of HCRQMS `engine/scripts/build-pdf.sh`: xelatex, DejaVu Sans/Mono, and a
footer with the document reference, "Page X of Y" and "UNCONTROLLED COPY WHEN
PRINTED OR TRANSMITTED".

**Packages** (Debian 13, `--no-install-recommends`, as in the Docker image).
They add about 460 MB on the VM:

```
pandoc texlive-xetex texlive-latex-recommended texlive-latex-extra
texlive-plain-generic texlive-fonts-recommended lmodern fonts-dejavu-core
librsvg2-bin
```

- `texlive-plain-generic` provides `soul.sty`, which pandoc's template loads
  for ~~strikethrough~~. Without it, 2 of the 101 HCRQMS documents failed.
  `texlive-latex-extra` only recommends it, so `--no-install-recommends` leaves
  it out.
- `librsvg2-bin` (`rsvg-convert`) is needed for SVG images, such as the
  HCRQMS logo.
- `graphviz` is not used by publishing; it is already in the image for
  MantisBT.
- No diagram tools (Mermaid, PlantUML) are needed. The HCRQMS documents have
  no images or diagrams, and their symbols (✓ ⚠ ✗, box drawing) are all in
  DejaVu.
- WeasyPrint (HTML/CSS to PDF, about 190 MB) is the lighter alternative. It
  was not chosen because the LaTeX route reproduces the existing
  `build-pdf.sh` design.

**Results.**
- `build-pdf.sh`, unchanged, built Document-Control-Procedure.md: 18 pages,
  1.6 s, no warnings.
- Run as `www-data` the way Doctis would run it, the build also worked:
  `--sandbox`, an empty environment, `HOME` set to a private temporary
  directory, and the footer status supplied by Doctis.
- All 101 HCRQMS documents (`content/` and `system/`) built with the final
  settings below: 656 pages, 1.2–2.7 s each (median 1.9 s), about 50 s for all
  of them four at a time.

**Settings a Doctis PDF build must use**:
- `--from=markdown-raw_tex-raw_attribute`, and `openin_any=p` in pandoc's
  environment. Debian's TeX lets a document read any file (`openin_any = a`).
  With plain `markdown`, `\input{/path}` or a ```` ```{=latex} ```` block
  copied a server file (`/etc/hostname` in the test) into the PDF.
  `config_inc.php` could be read the same way. Turning off both raw-LaTeX
  syntaxes stopped it. `openin_any=p` alone makes the build fail instead of
  leaking, so it works as a second safeguard. Pandoc's `--sandbox` does not
  restrict the TeX engine.
- Footer values (number, revision, status) must reach LaTeX escaped. Pass them
  as pandoc metadata in a Doctis LaTeX template. Never interpolate them into
  the header file the way `build-pdf.sh` does with frontmatter.
- `\usepackage[normalem]{ulem}` with `\renewcommand{\st}[1]{\sout{#1}}` in
  the header. `soul`'s `\st` loses the em dash under xelatex (one HCRQMS
  document had 4 missing glyphs); `ulem` keeps it.
- Paper size: `build-pdf.sh` produces US Letter. Set `papersize: a4` if A4 is
  the company standard.

**Content found while testing.** `system/guidance/Doctis-Git-Storage-Backend.md`
has `CzProject\GitPhp` in a heading. Pandoc's default Markdown reads `\GitPhp`
as LaTeX: `build-html.sh` drops it from the HTML, and `build-pdf.sh` fails.
Write it as `` `CzProject\GitPhp` `` in the source. Doctis now reads documents
with `markdown-raw_tex` (§1), so its own pages keep the text.
