# Doctis User Manual

Doctis is a document issue-tracking system built on MantisBT.  It extends
MantisBT's issue (bug) tracking with a parallel **document tracking** layer.
A document record represents a controlled deliverable — a drawing, report,
specification, or any managed file — and issues are raised against specific
documents as they move through a formal review lifecycle.

This manual covers the Doctis-specific features.  Standard MantisBT features
(user accounts, projects, filters, email notifications, etc.) are documented
in the [MantisBT documentation](https://mantisbt.org/documentation.php).

---

## Contents

1. [Core concepts](#1-core-concepts)
2. [The document list](#2-the-document-list)
3. [Creating a document](#3-creating-a-document)
4. [The document view page](#4-the-document-view-page)
5. [The primary document file](#5-the-primary-document-file)
6. [Document status workflow](#6-document-status-workflow)
7. [Raising an issue against a document](#7-raising-an-issue-against-a-document)
8. [Licenses](#8-licenses)
9. [Administration notes](#9-administration-notes)

---

## 1. Core concepts

### Document vs Issue

| Concept | MantisBT term | Doctis term | Purpose |
|---------|--------------|-------------|---------|
| A tracked deliverable file | — | **Document** | The thing being reviewed |
| A problem found during review | Bug | **Issue** | Something wrong with a document |

A **document** is a first-class record: it has its own metadata (title, author,
number, revision, reference), its own status workflow, and its own file storage.
One or more **issues** can be raised against a document at any point during its
lifecycle.

### The reference field

Every document has a **Reference** — the canonical identifier that locates the
document in whichever system stores it:

| Storage type | Reference looks like | Behaviour |
|---|---|---|
| External PLM / drawing system | Alphanumeric ID, e.g. `bkGZWgqUFOdQBZ` | Renders as a hyperlink to the external system |
| Doctis git backend | 40-character hex SHA, e.g. `b815a329…` | Renders as a download link; displayed abbreviated to 8 characters |
| Physical / published document | ISBN or catalogue number | Displayed as plain text |

When a document's primary file is stored in the Doctis git backend, the
reference field is **automatically updated** to the new git SHA each time a
primary file is uploaded or synced.  For external references, the field is set
manually at document creation time and can be edited later.

The reference field is optional — a document can be registered as a placeholder
before its reference is known.

### Access levels

Doctis uses the same access level hierarchy as MantisBT:

| Level | Value | Typical role |
|-------|-------|-------------|
| VIEWER | 10 | Read-only access to document metadata |
| REPORTER | 25 | Can view and download primary document files; can raise issues |
| UPDATER | 40 | Can edit document records |
| DEVELOPER | 55 | Can add notes; can edit others' notes |
| MANAGER | 70 | Full document management; can sync HEAD, manage licenses |
| ADMINISTRATOR | 90 | Full system access |

> **Note:** Users below REPORTER cannot view or download the primary document
> file, even if they can see the document record.

---

## 2. The document list

The main document list is at **My View → Documents** or `view_dwg_page.php`.

### Columns

| Column | Meaning |
|--------|---------|
| P | Priority indicator |
| ID | Document ID — click to open the document |
| ≡ (notes) | Number of document notes |
| ⊕ (attachments) | Number of note attachments |
| ℹ (issues) | Number of open issues raised against this document — click to filter the issue list |
| Category | Document category within the project |
| Status | Current workflow status |
| Updated | Date of last change |
| Title | Document title — click to open |
| Number | Document number |
| Revision | Revision identifier |
| Reference | External reference or git SHA (click to download for git-stored documents) |

### Filtering

The filter panel above the list works the same as MantisBT's issue filter.
Doctis-specific filter fields include document status, number, revision, and
reference.

---

## 3. Creating a document

Navigate to **Report Document** (`dwg_create_page.php`).

### Required fields

| Field | Description |
|-------|-------------|
| **Title** | Full document title |
| **Number** | Document number (drawing number, report number, etc.) |

### Optional fields

| Field | Description |
|-------|-------------|
| **Reference** | External identifier or left blank if not yet known (auto-filled when a primary file is uploaded to git storage) |
| **Revision** | Revision designator, e.g. `Rev A`, `Issue 2` |
| **Author** | Document author(s) |
| **Publisher** | Issuing organisation |
| **Classification** | Security or distribution classification |
| **Category** | Project-defined category |
| **Primary document file** | Upload the document file at creation time (optional — can be added later from the document view page) |

### Notes on the reference field

- Leave blank if registering a placeholder before the document or its external
  reference is available.
- For documents managed externally (PLM, Objective, Siemens, etc.), enter the
  system's identifier here.
- For documents that will be stored in Doctis git storage, the reference will be
  set automatically on first upload — you can leave this blank.

---

## 4. The document view page

Opening a document (`dwg_view.php?id=N`) shows several panels.

### View Document Details

The main metadata panel.  Fields shown depend on configuration, but always
include status, title, number, revision, reference, and author.

### Primary Document

Shows the file currently held in Doctis.  See [section 5](#5-the-primary-document-file).

> Not visible to users below REPORTER access level.

### Relationships

Links to related documents or issues.  Works identically to MantisBT's bug
relationship panel.

### Licenses

Lists the access licenses (skills, clearances, or qualifications) required to
view this document.  See [section 8](#8-licenses).

### Document Notes

A threaded notes panel, equivalent to MantisBT's bug notes.  Used for informal
annotations during review.  Can be disabled site-wide — see
[section 9](#9-administration-notes).

### History

Full audit trail of all changes to the document record.

---

## 5. The primary document file

The **Primary Document** panel manages the actual document file stored in
Doctis.

### Rows in the panel

| Row label | Meaning |
|-----------|---------|
| **On Record** | The version currently registered in Doctis.  Click the filename to download.  The abbreviated SHA is shown for git-stored files. |
| **Draft** | The most recent commit in the git repository.  Shown only when git storage is active and the HEAD differs from the On Record version.  Downloading this version shows a warning. |

### Actions

| Action | Access required | Description |
|--------|----------------|-------------|
| **Replace Document** | UPDATER | Upload a new primary file, replacing the current On Record version.  The git SHA is updated automatically and written to the document's Reference field. |
| **Sync to HEAD** | MANAGER | Update the On Record record to match the current git HEAD (for cases where a file has been committed to git outside Doctis). |
| **Tag** | MANAGER | Apply a named git tag to the current On Record SHA (e.g. `approved-rev-A`). |
| **Touch** | MANAGER | Re-commit the current file to git without content change, creating a new SHA.  Useful to force a new commit timestamp. |

### File history

The full version history of the primary document is preserved in the git
repository and remains accessible via git tooling regardless of how many times
the file is replaced in Doctis.

---

## 6. Document status workflow

Document records move through a configurable status sequence during their
review lifecycle.  The default workflow is:

```
pending → received → triage → JoS → assigned to → review → rework
       → independent review → accepted → incorporated → archived
```

| Status | Meaning |
|--------|---------|
| **pending** | Registered but not yet submitted for review |
| **received** | Submission received, awaiting triage |
| **triage** | Being assessed for completeness and routing |
| **JoS** | Judgment of Suitability — initial technical assessment |
| **assigned to** | Assigned to a reviewer |
| **review** | Under active review |
| **rework** | Returned to originator for correction |
| **independent review** | Secondary review by an independent party |
| **accepted** | Review complete; document accepted as-is |
| **incorporated** | Comments incorporated; final version on record |
| **archived** | Superseded or withdrawn; no longer active |

Status transitions are made from the **Update Document** page
(`dwg_update_page.php?bug_id=N`) or via the quick-status buttons on the document
view page.

---

## 7. Raising an issue against a document

Issues represent specific problems, queries, or actions arising from a document
review.  They use MantisBT's standard issue tracking internally but are linked
to a document record.

### Creating an issue

From the document view page, click **Report Issue** (or navigate to
`bug_report_page.php` and select the document from the **Document** field).

The issue form is a standard MantisBT bug report form with an additional
**Document** field that links the issue to a document record.

### The document context in an issue

When viewing an issue (`view.php?id=N`) that is linked to a document, the
**View Issue Details** panel includes a **Document** section showing the linked
document's title, reference, number, revision, and release date.  The reference
shown is the document's current reference at the time of viewing.

### Issue lifecycle relative to the document

Issues linked to a document are counted in the **ℹ** column of the document
list.  Clicking that count opens a filtered issue list for that document.

When reviewing issues raised during a document review cycle, the document's
reference field indicates the version the issue was raised against (provided the
document has not been replaced since then).

### Severity and priority

Doctis uses a simplified severity scale by default:

| Severity | Intended use |
|----------|-------------|
| comment | Non-blocking observation |
| query | Question requiring a response |
| minor | Minor defect or required change |
| major | Significant defect; must be resolved before acceptance |

---

## 8. Licenses

A **license** in Doctis is a skill, security clearance, or professional
qualification registered against a user account.  The term is Doctis-specific
and is unrelated to software licensing.

Documents can require users to hold one or more licenses before being granted
access.  This supports controlled-distribution documents where access depends
on a user's certifications or clearance level.

### Managing licenses on a document

On the document view page, the **Licenses** panel lists required licenses.
Users at MANAGER level or above can add or remove license requirements from
this panel.

### Managing user licenses

User license holdings are managed from the user administration pages.  A user
who does not hold a required license will be denied access to documents that
require it.

---

## 9. Administration notes

### Disabling the document notes feature

To hide the Document Notes panel and remove the notes/attachment count columns
from the document list, add the following to `config/config_inc.php`:

```php
$g_dwg_view_page_fields = array_diff( $g_dwg_view_page_fields, ['dwgnotes'] );
```

This removes the notes panel, the Add Note form, the Jump to Notes button, and
the two count columns in the list view in a single step.  Remove or comment
out this line to re-enable the feature.

### QMS sidebar link

Doctis can display a sidebar button linking to an external Quality Management
System.  In `config/config_inc.php`:

```php
$g_qms_url  = 'http://your-qms-server/';   # URL of the QMS
$g_qms_icon = 'fa-institution';            # Font Awesome 4 icon name
```

Leave `$g_qms_url` empty (the default) to suppress the button entirely.

### Primary document access threshold

By default, users below REPORTER cannot view or download the primary document
file.  To change this:

```php
$g_dwg_primary_document_threshold = VIEWER;   # open to all authenticated users
# or
$g_dwg_primary_document_threshold = DEVELOPER; # restrict further
```
