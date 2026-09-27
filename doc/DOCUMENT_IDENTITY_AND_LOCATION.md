# Document identity, reference, location, and revision

Assessment: 2026-09-27. This is a design recommendation, not an implemented
schema or data migration. The disposable native VM still holds the 99-file
HCRQMS repeat import for evaluation.

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
Therefore Git SHA should not be stored in `documents.reference` as the
document's identifier. The September 2026 import did so deliberately for
the trial; it exposed this model conflict and should not be copied into a
production migration without a policy change.

The database has ordinary, **non-unique** indexes on `documents.reference`
and `documents.number`, and allows empty values. It already has
`documents.link_url` (2048 characters), but also a duplicate `dwg.link_url`.
Creation writes the same value to both. The create/edit page's Link URL input
is inside an HTML comment; the regular update path and SOAP/REST update do
not persist changes to `documents` metadata. Reference rendering ignores
`link_url` and guesses the destination from the Reference text (SHA, URL, or
an old external-system pattern). These are incomplete features, not a usable
location model yet. The manual's statement that external references can be
edited later is not supported by the current normal update path.

## What the HCRQMS data shows

Of 99 imported documents, 34 have an empty `documents.number` (mapped from
frontmatter `doc_id`). The 65 nonempty values contain 64 distinct values:
`TMPL-SYS-001` occurs in two different templates. All 99 Reference values
are the same snapshot commit SHA. Consequently, neither `doc_id` nor Git SHA
can currently satisfy a globally unique Reference rule. The two templates
need a source-data decision; the missing values need either authoritative
numbers or an explicit fallback. No identifier should be fabricated silently
to look like a source-issued `doc_id`.

## Recommended policy

Keep the **Doctis record ID** as the guaranteed unique selector. If users need
a portable, human-readable Doctis citation, add a separately named immutable
accession code (or a UUID plus display code) with a unique constraint. Treat
the **source reference** as an attributed identifier: its scheme/issuer and
scope matter, and it may be absent or repeated. An ISBN can identify an
edition while multiple physical copies share it; a correspondence number
may recur between senders. Warn about likely duplicates within the relevant
scheme/project, but do not impose a global unique constraint on these values.

For HCRQMS, keep the literal `doc_id` in `documents.number`. A decision is
needed on whether `documents.reference` should display that same value,
display a Doctis accession code, or remain an optional source reference while
the accession code appears alongside it. Do not use Git SHA there. The
source-data duplicate and 34 missing numbers should be reported during import
and resolved or explicitly accepted before production cutover.

Use `dwg_primary_file.git_sha` and `git_path` to locate an exact Git file; keep
approval/promotion separate. Make `documents.link_url` the canonical URL for
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
3. Stop Git registration/upload/sync from overwriting the source Reference;
   preserve SHA in the primary-file row and approval refs. Update the importer
   to map `doc_id` under the chosen policy and report missing/duplicate IDs.
4. Select one canonical URL field, expose it on create/view/edit, and make
   normal and API updates persist document metadata with validation/history.
   Add physical location support separately. Replace heuristic Reference links
   with explicit Git/external/physical rendering.
5. Rehearse a coordinated migration or reset/re-import on the disposable VM.
   Verify identifiers, links, exact file downloads, approvals, and updates
   before planning production data migration. The current import stays intact
   until the policy and migration are agreed.

Source pointers: `admin/schema.php` (documents/dwg columns),
`core/dwg_api.php` (creation), `core/file_dwg_api.php` (SHA writes),
`core/string_api.php` (Reference link heuristics), `dwg_create_page.php`,
`dwg_edit_page.php`, `dwg_update.php`, `api/soap/mc_dwg_api.php`, and
`admin/import-git-repo.php`. See also `doc/api/REST-AND-SOAP-API.md` for
the current update limitation.
