# Document identity, reference, location, and revision

Assessment: 2026-09-27. This is a design discussion, not an implemented
schema or data migration. The disposable native VM still holds the 99-file
HCRQMS repeat import for evaluation. **Interim decision:** keep the importer
and its Git-SHA Reference behaviour unchanged while final field definitions
are considered.

## The distinction to preserve

| Concept | Meaning | Current representation |
| --- | --- | --- |
| Doctis record identity | Unambiguously selects one registered record | `dwg.id` (and `documents.id` internally) |
| Source reference | Identifier printed or assigned by an outside authority, such as ISBN, correspondence number, or QMS `doc_id` | `documents.reference` and/or `documents.number` |
| Location | Where a copy can be obtained or found | Git repository/path, `link_url`, or currently unmodelled physical location |
| Revision/content identity | Exact electronic version | `dwg_primary_file.git_sha` plus `git_path`; approved Git refs separately |

A Git commit SHA identifies a repository snapshot, not a particular document.
The one-commit HCRQMS snapshot gives all 99 registered files the same SHA;
`git_path` distinguishes the files at that commit. A later revision changes
the SHA without changing the document's identity or its source reference.
Nevertheless, the current Git-SHA Reference is a usable *version locator*:
the Doctis hyperlink includes the document ID and downloads its registered
file at the stored SHA/path. Its repetition across this one-commit import is
visually unusual, but does not make those links ambiguous. The unresolved
question is whether Reference is meant to be a version locator, a source
identifier, a Doctis accession code, or something else across storage types.

The database has ordinary, **non-unique** indexes on `documents.reference`
and `documents.number`, and allows empty values. It already has
`documents.link_url` (2048 characters), but also a duplicate `dwg.link_url`.
Creation writes the same value to both. The document create and clone forms
now expose Link URL. The normal update form and SOAP update persist URL changes
to both copies, but other `documents` metadata is not updated through those
paths. Reference rendering ignores
`link_url` and guesses the destination from the Reference text (SHA, URL, or
an old external-system pattern). The URL is displayed separately on the
document detail page; this remains a limited location model. The manual's
statement that external references can be edited later is not supported by
the current normal update path.

## What the HCRQMS data shows

Of 99 imported documents, 34 have an empty `documents.number` (mapped from
frontmatter `doc_id`). The 65 nonempty values contain 64 distinct values:
`TMPL-SYS-001` occurs in two different templates. All 99 Reference values
are the same snapshot commit SHA. Consequently, neither `doc_id` nor Git SHA
can currently satisfy a globally unique Reference rule. The two templates
need a source-data decision; the missing values need either authoritative
numbers or an explicit fallback. No identifier should be fabricated silently
to look like a source-issued `doc_id`.

## Design considerations and interim decision

Keep the **Doctis record ID** as the guaranteed unique selector. If users need
a portable, human-readable Doctis citation, add a separately named immutable
accession code (or a UUID plus display code) with a unique constraint. Treat
the **source reference** as an attributed identifier: its scheme/issuer and
scope matter, and it may be absent or repeated. An ISBN can identify an
edition while multiple physical copies share it; a correspondence number
may recur between senders. Warn about likely duplicates within the relevant
scheme/project, but do not impose a global unique constraint on these values.

For HCRQMS, keep the literal `doc_id` in `documents.number`. For now,
`documents.reference` remains the registered Git SHA. Future options include
keeping that Git-specific version locator, displaying the source `doc_id`, or
showing a separate Doctis accession code alongside either value. The
source-data duplicate and 34 missing numbers should be reported and resolved
or explicitly accepted before production cutover if `doc_id` gains an
identity role. No source-issued identifier should be fabricated silently.

Use `dwg_primary_file.git_sha` and `git_path` to locate an exact Git file; keep
approval/promotion separate. An intranet publication URL fits the URL field
better than Reference. `documents.link_url` is a candidate canonical URL for
external electronic material, with explicit `http`/`https` validation and safe
output; reconcile the existing `dwg.link_url` values and update the read path.
For physical material, add a location/custodian field or a typed location
record; a URL cannot describe a shelf, archive box, or off-site holding.
If one document may have several locations or copies, introduce a
`document_location` table rather than overloading one URL field. Rendering
should choose a link from the declared location type, not from the shape of
the Reference string.

## Implementation sequence after the policy is chosen

1. Define the fields and labels for Doctis accession, source reference,
   source `doc_id`/number, location, current Git revision, and approved
   revision. Decide which values must be immutable and which are editable.
2. Audit existing records and import sources for missing/duplicate source
   identifiers and external links. Define namespace/scope and collision
   handling before adding any uniqueness constraint.
3. Once Reference semantics are chosen, adjust Git registration/upload/sync
   only if that policy requires it. Keep SHA in the primary-file row and
   approval refs either way. Update the importer to map `doc_id` under the
   chosen policy and report missing/duplicate IDs.
4. Select one canonical URL field and reconcile the two stored copies. URL
   entry, safe display, and normal/SOAP updates are available in the interim;
   the broader document metadata correction workflow remains to be defined.
   Add physical location support separately. Replace heuristic Reference links
   with explicit Git/external/physical rendering.
5. Rehearse a coordinated migration or reset/re-import on the disposable VM.
   Verify identifiers, links, exact file downloads, approvals, and updates
   before planning production data migration. The current import stays intact
   until the policy and migration are agreed.

## Would one document per Git commit make the SHA a Reference?

It could make *introducing commit IDs* distinct for newly added documents,
but it does not make a commit ID a stable document identity by itself. A later
edit produces a new commit ID for the same document; another document's commit
also advances repository HEAD. The current view compares each document's
saved `git_sha` with repository HEAD, so **one later commit makes every other
document appear "updated" even when its blob is unchanged**. Compare the
file's blob at `<registered-sha>:<git_path>` with `HEAD:<git_path>` to detect
real content changes. A commit can still be retained as version provenance.
The Primary Document panel also labels every registered primary row
"Approved" even when the import deliberately created no approved ref;
approval display should read actual workflow/pin state.

A server-side `pre-receive` hook can reject a push if any newly reachable
commit changes more than one qualifying document path. The current hook
checks only ref deletion, non-fast-forward updates, and protected refs. A
validator would need a persisted definition of qualifying paths, to inspect
**every commit introduced by the push**, and explicit rules for merges,
renames, metadata/auxiliary files, new branches, and the initial import. It
would also require all repository writes to use the receive path; direct
server-side ref updates do not run this hook. The HCRQMS one-commit snapshot
would fail such a rule unless migration were given a deliberate exception or
rewritten as 99 commits. Rewriting history solely to manufacture unique
Reference values is not recommended.

The separate [Git content-detection plan](git/GIT_DETECT_CONTENT.md) already
proposes discovering unregistered paths and registering them through an
operator-facing scan, with optional asynchronous notification. It does not
require one file per commit. That plan should compare the **current HEAD file
set** with registered paths, so an unregistered file remains visible across
repeated scans; a diff only from `last_scanned_sha` to HEAD can lose a pending
candidate after the first scan.

Git documentation: [server-side hooks](https://git-scm.com/docs/githooks),
[receive-pack and pre-receive quarantine](https://git-scm.com/docs/git-receive-pack),
and [per-commit tree diffs](https://git-scm.com/docs/git-diff-tree).

Source pointers: `admin/schema.php` (documents/dwg columns),
`core/dwg_api.php` (creation), `core/file_dwg_api.php` (SHA writes),
`core/string_api.php` (Reference link heuristics), `dwg_create_page.php`,
`dwg_edit_page.php`, `dwg_update.php`, `api/soap/mc_dwg_api.php`, and
`admin/import-git-repo.php`. See also `doc/api/REST-AND-SOAP-API.md` for
the current update limitation.
