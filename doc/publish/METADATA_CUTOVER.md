# Metadata Cut-over

Owner decision, 2026-10-15: **lifecycle metadata lives only in Doctis.** The
git document store is the source of document *content*, and Doctis is the
source of document *status*. The publication pipeline is the document
compiler that combines the two. It prints the Doctis values in each page's
document-control block, header and footer
([PUBLISHING.md](PUBLISHING.md) §4).

Lifecycle metadata written into the documents themselves needs manual
updating, goes stale, and contradicts Doctis. It also cannot be correct by
design. Writing `status: Approved` into a file makes a new commit, which is
never the commit Doctis approved. The cut-over removes it.

Code: [core/dwg_metadata_api.php](../../core/dwg_metadata_api.php)
(pure transformation), [admin/doctis-metadata-cutover.php](../../admin/doctis-metadata-cutover.php)
(the script), and `tests/Mantis/DwgMetadataStripTest.php`.

---

## What is removed

| Where | Removed | Kept |
|-------|---------|------|
| YAML frontmatter | `title`, `status`, `revision`, `owner`, `approver`, `effective_date`, `review_period`, `classification` | `doc_id` (identity: it lets a file be matched to its Doctis record outside Doctis); meeting keys (`date`, `time`, `chair`, `location`, ...); anything else. A block left empty is removed. |
| Header lines under the first heading | `**Document ID:**`, `**Revision:**`, `**Status:**`, `**Owner:**`, `**Classification:**`, `**Approver:**`, `**Effective Date:**`, `**Review Period:**`, as whole lines or as ` \| `-separated segments of a line | Other labels (`**Date:**`, `**Chair:**`, `**Associated ICD:**`, ...). A horizontal rule that only separated the removed lines from the body goes with them. |

Only the header region is touched. It runs from the line after the H1 up to
the first line that is not a `**Label:**` line, a blank line or an HTML
comment. Body text, Revision History tables and code blocks are never
changed; the frontmatter example in a ```` ```yaml ```` block in
PROC-SYS-001, for instance, stays.

Where the values live in Doctis: Number (doc_id), Title (from the import),
Revision, Status, Classification, assigned user (owner), Release date
(effective), Due date (next review). Role-based "approver" is defunct (owner
decision): Doctis access levels decide who approves.

Text in a lifecycle field that is not a status is lost with it. For example,
the Quality Policy's `status: Draft — requires CEO signature before issue`.
Record any such note in Doctis (a document note) before the cut-over if it
matters.

---

## Running it

From the running clone, as the web server user (the repositories belong to
it):

```bash
cd /var/www/html/doctis
# what would change, with diffs
sudo -u www-data php admin/doctis-metadata-cutover.php --project HCRQMS --user administrator --dry-run --diff | less
# do it
sudo -u www-data php admin/doctis-metadata-cutover.php --project HCRQMS --user administrator
```

For the project and the sub-projects that share its repository, the script:
1. reads each registered Markdown document at the repository HEAD;
2. strips it;
3. makes **one commit** for all of them, recorded as the `--user` account;
4. pushes;
5. promotes each changed document to On Record, logged in its history;
6. republishes the documents that were published, so their pages show the
   new version.

Documents with a Draft (unpromoted changes) are skipped unless
`--include-drafts` is given. It is repeatable: a second run finds nothing to
change.

Other options:
- `--no-promote` leaves the changes as Drafts.
- `--no-republish` skips republishing.
- `--keys` and `--labels` change what is removed.

The importer still reads the frontmatter. In a reload it seeds Doctis, so the
cut-over comes after the import:
`admin/tools/data-scripts/README.md`, Native VM rebuild, step 6.

**Run 2026-10-15 on the native VM** (HCRQMS, projects 1 and 3):
- 99 Markdown documents; 65 changed in one commit (`3a1e986`) in 10 s, all
  promoted, and the 4 published pages republished. A second run changed
  nothing.
- A dry run over the 101 GitHub HCRQMS files had changed the 67 with
  frontmatter: one field per line, pipe-separated lines and meeting records
  all handled.

---

## Related changes

- **Meeting assistant.**
  - The meeting document's Doctis status follows the meeting
    (`$g_meeting_document_status`: agenda → draft, minutes → review,
    approved → approved, cancelled → archived).
  - Chair approval sets the release date.
  - Both are logged in the history.
  - Approval no longer stamps "Approved Minutes" into the record, and no longer
    commits a revision to do so; cancellation no longer revises it.
  - Stored records are stripped of lifecycle metadata, and the AI prompt tells
    the model not to write it.
- **Publishing.**
  - The document-control block (§4 of PUBLISHING.md) shows number, title,
    revision, status, classification, owner, approval (who and when, from
    the history), effective date, next review, On Record commit and publish
    date.
  - Classification is a template field, shown in the page banner and footer
    and at the top and bottom of every PDF page.

**Not done yet (owner decision: after the above is settled):**
- revise HCRQMS PROC-SYS-001 §3.2–3.3 and the document templates;
- retire `build-pdf.sh`'s frontmatter footer;
- run the cut-over on the live version.
