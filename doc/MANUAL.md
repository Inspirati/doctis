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
9. [My View and your profile](#9-my-view-and-your-profile)
10. [Meetings](#10-meetings)
11. [AI Assistant and knowledge base](#11-ai-assistant-and-knowledge-base)
12. [Documentation Topics](#12-documentation-topics)
13. [Administration notes](#13-administration-notes)

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

### Who is responsible for a document

Every document names three people, and they mean different things.

| Field | Meaning | How often it changes |
|-------|---------|----------------------|
| **Created by** | Whoever registered the document in Doctis. | Never. It is a record of who put it there, like the reporter of an issue, and stays the same whether or not that person is still with the company. |
| **Owner** | The person *responsible* for the document. | Rarely — when someone is replaced or moves to a new position. The Owner is the name printed on the published page. |
| **Assigned To** | Whoever holds the document for the **current task step** — reviewing it, reworking it, approving it. | Often. This is how documents get passed around. |

Ownership and assignment are separate on purpose. A procedure can be owned by
a department manager for years while being passed to half a dozen reviewers
along the way.

You can take either role yourself. On the document view page:

- **Claim Ownership** makes you the Owner of a document nobody owns.
  **Release Ownership** gives it up.
- **Claim** makes you the assignee, picking the document up from whoever had
  it. **Release** puts it back down.

Managers and administrators can also use **Set Owner** to name somebody else,
including taking a document off its current owner — useful when that person
has left. Every change of either field is recorded in the document's History
with who made it and when.

Who may own a document is an access-level question, not a rule about job
titles: managers typically own quality-system documents and engineers own
project documents, and Doctis does not try to enforce which. If a document
requires a licence, its Owner must hold that licence.

To change the owner of several documents at once, select them in the document
list and choose **Set Owner** from the action menu at the bottom.

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
primary file is uploaded or synced. For external references, the field is set
manually at document creation time. The normal update form does not currently
edit the Reference.

Use the optional **Link URL** for an intranet or external published copy. It
appears as a clickable link on the document details page and can be changed
or cleared from the document update form. A Link URL does not change the
Reference or the Doctis-managed primary file.

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
| Owner | The person responsible for the document |
| Assigned To | Whoever holds the current task step |
| Updated | Date of last change |
| Title | Document title — click to open |
| Number | Document number |
| Revision | Revision identifier |
| Reference | External reference or git SHA (click to download for git-stored documents) |

### Filtering

The filter panel above the list works the same as MantisBT's issue filter.
Doctis-specific filter fields include document status, number, revision,
reference, **Owner** and **Created by**. Owner accepts `[myself]` and
`[none]`, so you can list your own documents or find ones nobody owns yet.

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
include status, title, number, revision, reference, and author, together with
the document's **Owner**, **Assigned To** and **Created by** (see
[Who is responsible for a document](#who-is-responsible-for-a-document)).

### Primary Document

Shows the file currently held in Doctis.  See [section 5](#5-the-primary-document-file).

> Not visible to users below REPORTER access level.

### Relationships

Links to related documents or issues.  Works identically to MantisBT's bug
relationship panel.

### Licenses

Lists the access licenses (skills, clearances, or qualifications) required to
view this document.  See [section 8](#8-licenses).

### Documentation Topics

When the project uses Documentation Topics, this panel lists the Topics the
document is tagged with, each as part of the Topic or the whole of it, with
the relevant sections. Developers can add, change and remove tags here. See
[section 12](#12-documentation-topics).

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
| **On Record** | The approved version, including its original filename and content, until a manager promotes a replacement. Click the filename to download it. |
| **Draft** | Appears only when this document has a staged upload or its file was edited directly in Git. It shows that document's revision, which may have a different filename and content. A commit to another document does not create a Draft here. |

### Actions

| Action | Access required | Description |
|--------|----------------|-------------|
| **Replace Document** | UPDATER | Upload a replacement as Draft. On Record and the document Reference remain unchanged. A second upload supersedes the current Draft. |
| **Promote Draft** | MANAGER | Promote this document's Draft revision to On Record, including its filename, content, and Reference. If its file changed in Git after upload, re-upload before promotion. |
| **Tag** | MANAGER | Apply a named git tag to the current On Record SHA (e.g. `approved-rev-A`). |

### Publishing to the website

If the project has a website set up (see *Publishing settings* in §13), a
Markdown document (`.md`) can be published to it. Click **Publish** among the
buttons at the top of the view page (MANAGER by default). Doctis converts the
**On Record** version to a web page at the document's repository path, with
`.md` replaced by `.html`. The page header shows the document's status, e.g.
DRAFT or APPROVED. The website's navigation and its index of published
documents update at the same time.

Once published, the panel shows a **Published** row with:
- the page, linked when the site has a URL;
- the status it was published with;
- the date, and who published it;
- the commit.

Publishing also sets the document's **URL** field to the published page, so
the address appears with the document's details, in the document list and
through the APIs. It replaces any earlier value; the old value stays in the
history. Unpublish clears the field, unless it has been changed to something
else since.

The row says **out of date** after a newer version is promoted to On Record or
the status changes. Click **Republish** to update the page. **Unpublish**
removes the page from the website; deleting the document also removes it.
Each publish and unpublish appears in the document history.

Every published page starts with a **document-control block** under the
title. Its values all come from Doctis:
- number, title, revision, status and classification;
- owner (the person responsible for the document — not its current assignee);
- who approved the document and when;
- effective date and next review;
- the On Record commit, and when it was published.

The classification is also shown in the page header and footer. Documents no
longer need these details written into their text (see
`doc/publish/METADATA_CUTOVER.md`).

If the project also publishes PDFs, a PDF of the document is written next
to the page. Its footer carries the document number, revision, status and
classification (also at the top of each page),
"Page X of Y" and "UNCONTROLLED COPY WHEN PRINTED OR TRANSMITTED". The page,
the website index and the Published row link to it.

Private documents and documents restricted by a license cannot be published.
Only Markdown documents can be published, and only their On Record version,
never a Draft.

### Moving a document

**Move** (on the view page, or in the document list's action menu) can take a
document to a project that keeps its files in another repository. The primary
document file then moves with it:
- Its On Record version is committed into the target project's repository,
  at the same path unless that path is taken there.
- It becomes On Record there, and the Reference follows.
- The file is removed from the old repository, which keeps its history.

A document with a **Draft** cannot be moved this way. Promote or discard the
Draft first; the Move reports the reason. A published page on a different
website is withdrawn, so republish the document in its new project.

### File history

The full version history of the primary document is preserved in the git
repository and remains accessible via git tooling regardless of how many times
the file is replaced in Doctis.
New uploads and Draft promotions also appear in the Document History
panel with their registered commit SHA and filename. Earlier Git revisions
remain in Git even if they predate this history logging.

### Cloning the project repository to your workstation

You can copy a project's whole document repository to your own computer with
ordinary git. No server login is needed. The warning page that opens when a
document's file was changed in Git (Draft differs from HEAD) shows the exact
command for your project.

1. Under **My Account → API Tokens**, create a personal token. It is shown
   only once, so copy it straight away.
2. Run the clone command, using the same scheme (`http` or `https`) as the
   address you use for Doctis in your browser:
   ```
   git clone https://<your-username>@<server>/git/<project>-r<N>.git
   ```
   Git asks for a password. Paste the **API token**. Your account password
   does not work.

To avoid the prompt, put the token in the URL
(`https://<your-username>:<token>@<server>/git/…`). That works, but the token
is then kept in your shell history and in the clone's `.git/config`. Treat it
as a password, and revoke it under API Tokens if it is ever exposed (pasted
into a chat or ticket, for example).

**If the clone fails**

| Symptom | Cause and remedy |
|---------|------------------|
| `Failed to connect to <server> port 80 … Couldn't connect to server` after a long wait | The server accepts only HTTPS, so nothing answers on port 80. Use `https://`. Git never reached Doctis, so it could not ask for a password. This is the usual cause of "git never asked for my token". |
| `HTTP 401`, or `Authentication failed` | Wrong username, or you gave the account password. Use your Doctis username and an API token. Check the token has not been revoked. |
| `HTTP 403` | Your account lacks the access level needed on the project that owns the repository (reading and pushing have separate thresholds). Ask a project manager. |
| `HTTP 404 Repository not found` | The repository name is wrong. It is `<project>-r<N>`, shown on the warning page and on Manage → Git Store. |
| `404` on every repository | Direct git access is switched off on the server (`$g_git_http_enabled`), or the front proxy does not pass `/git/` to Doctis. Ask an administrator. |
| Git does not prompt, and fails with `could not read Password` | Prompting is disabled on your computer: `GIT_TERMINAL_PROMPT=0` is set, there is no terminal, or a credential helper or `GIT_ASKPASS`/`SSH_ASKPASS` program is answering for you. Unset it, or put the token in the URL as above. |

To check the address without credentials, run
`curl -sI "https://<server>/git/<project>-r<N>.git/info/refs?service=git-upload-pack"`.
A `401` with `WWW-Authenticate: Basic realm="Doctis Git"` means the address is
right and git will ask for credentials. A timeout means the server is not
reachable on that scheme and port.

Pushing needs a higher access level than cloning. A push that changes a
registered document's file moves that document's **Draft** only, and a manager
promotes it as described above.

---

## 6. Document status workflow

Document records move through a configurable status sequence during their
review lifecycle.  The default workflow is:

```
pending → received → triage → JoS → assigned to → review → draft
       → independent review → approved → incorporated → archived
```

| Status | Meaning |
|--------|---------|
| **pending** | Registered but not yet submitted for review |
| **received** | Submission received, awaiting triage |
| **triage** | Being assessed for completeness and routing |
| **JoS** | Judgment of Suitability — initial technical assessment |
| **assigned to** | Assigned to a reviewer |
| **review** | Under active review |
| **draft** | Being drafted, or returned to the author for correction |
| **independent review** | Secondary review by an independent party |
| **approved** | Review complete; document approved |
| **incorporated** | Comments incorporated; final version on record |
| **archived** | Superseded or withdrawn; no longer active |

The **draft** and **approved** statuses were named rework and accepted before
2026-10-13; their values (165 and 180) did not change. The status set is
provisional and will be aligned with the QMS approval workflow.

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

## 9. My View and your profile

**My View** (sidebar) opens a set of tabs:

| Tab | Page | What it shows |
|-----|------|---------------|
| My Issues View | `my_view_bug_page.php` | Issues assigned to you, reported by you, recently changed, etc. |
| My Documents View | `my_view_dwg_page.php` | **Documents I Own**, documents assigned to you, unassigned documents, documents you created, and recent activity |
| My Meetings | `my_view_meeting_page.php` | Your meetings (see §10) |
| My Configuration View | `my_view_cnf_page.php` | Your profile, licences and system information |
| Organisational Chart | `my_view_org_page.php` | Who's who in the organisation |

### Creating your account

Use **Signup for a new account** on the login page. Enter a username and
your email address. Doctis emails you a link to set your password.

- **Username:** your first name in lowercase where it is still free (for
  example `hermione`). It must be 2 to 20 characters of lowercase letters,
  digits, dots, hyphens or underscores, starting with a letter and ending
  with a letter or digit. Capitals are converted to lowercase. An email
  address, spaces and reserved names such as `admin` are refused. You can
  log in with any mix of capitals.
- **First come, first served:** if your username is already taken, choose
  another, such as a nickname your colleagues know you by.
- **Email address:** staff use their work address. Only approved email
  domains can sign up. Staff domains skip the captcha (the image code);
  other approved domains must enter it.

### Your profile (My Configuration View)

Keep your profile current: other pages and the AI Assistant use it.

| Field | Used for |
|-------|----------|
| Real name, Position title, Company, Department, Phone | Shown on the Organisational Chart and in meeting records |
| Reports To | Your manager, if registered in Doctis; places you on the Organisational Chart |
| Alternative | Your manager's name when they are not registered in Doctis |
| Meeting Invites | Whether the Meeting Assistant may invite you: never, departmental meetings only, or all meetings |
| Notification Email | An alternative address for Doctis notifications (used instead of your account email) |

### Organisational Chart (corporate directory)

**My View → Organisational Chart** (`my_view_org_page.php`) is Doctis's staff
directory: every registered user with their position, department and company,
arranged by reporting line from each user's **Reports To** setting. Managers who
are not registered appear as dashed "external" boxes. Use it to find a
colleague's role, department or manager. Administrators can click a name to
open that user's account.

---

## 10. Meetings

Meetings are planned and minuted with the AI Assistant's **Meeting** tab and
tracked under **My View → My Meetings**. Each meeting record (agenda, then
minutes) is a controlled Doctis document in the meeting project's repository,
following the HCRQMS meeting template (TMPL-SYS-001), with a reference such as
`MIN-QA-20261020`.

### Planning a meeting

1. Open **AI Assistant → Meeting** (or **My Meetings → Plan a Meeting**).
2. Describe the meeting in one sentence, e.g. *"Agenda for Frodo, Sam and
   Gandalf on 20 October at 10am: weekly QMS progress review, 45 minutes."*
   You are the chair; the first person named is the minute taker. Only people
   whose **Meeting Invites** setting allows it can be invited; others become
   named guests.
3. Review the draft agenda; ask for changes or confirm.

On confirmation the agenda is stored and goes On Record at once, and the
invitees are emailed it with a calendar invitation. Say "every Tuesday",
"every two weeks" or "monthly" to make the meeting repeat (see below).

### My Meetings and the meeting page

My Meetings lists your upcoming and past meetings with your role (chair, minute
taker, organiser or invitee), the participants and the status (agenda issued,
minutes awaiting approval, minutes approved, cancelled). Draft minutes are due
two business days after a meeting; overdue ones are shown in red. Managers can
switch to **All meetings**.

Click a meeting's title for its page: details, attendance, actions and their
issues, the series it belongs to, and the actions open to you:

| Button | Who | Does |
|--------|-----|------|
| Write / Revise Minutes | Chair, minute taker, organiser | Opens the Meeting tab for this meeting's minutes |
| Approve Minutes | Chair | Approves the draft minutes (see below) |
| Change Meeting | Chair, organiser (agenda stage) | Reschedule, change location, minute taker or invitees; invitees get the update |
| Cancel Meeting | Chair, organiser (agenda stage) | Cancels; invitees get a calendar cancellation |
| Plan Next Meeting | Chair, minute taker, organiser | Plans the following meeting of the series |
| Repeats | Chair, organiser | Makes the series repeat weekly, every two weeks or monthly, or stops it |
| Calendar (.ics) | Anyone who can see the meeting | Downloads the meeting for your calendar |

### Minutes and approval

Write the minutes from the meeting page: give attendance, then notes on each
agenda item (rough notes are fine), including actions with an owner and a due
date. The saved minutes are a **draft revision** of the meeting document, and
are emailed to the participants for corrections within three business days.

The **chair** approves them (Approve Minutes): the minutes become On Record,
the meeting document's status becomes approved, its release (effective) date
is set, and it is emailed to the participants. The record itself is not
changed: like every document, its status and dates live in Doctis. Each action
becomes a Doctis issue linked to the meeting document, assigned to its owner
with its due date. An owner who is not a member of the meeting project is added
to it so the issue can be assigned. A revision uploaded by hand on the meeting
document's page also counts as draft minutes.

### Series and repeating meetings

**Plan Next Meeting** starts the next meeting of a series: the assistant keeps
the same people, place and format, and carries forward approval of the
previous minutes and every open action. When a series **repeats**, Doctis
creates each next meeting itself a few days ahead and emails the invitees;
stop it with **Repeats → Does not repeat**.

---

## 11. AI Assistant and knowledge base

The **AI Assistant** (sidebar) has these tabs:

| Tab | Use |
|-----|-----|
| Help | Ask how to do something in Doctis, where to find a page, or about document control |
| Meeting | Plan meetings and write minutes (§10) |
| SOP, Other | Not yet available |
| Knowledge | The knowledge base the assistant answers from |

Conversations are kept between visits; **Clear** starts afresh.

### Teaching the assistant

The assistant knows the pages you can open, this manual, and its knowledge
base. When it gets something wrong or doesn't know, click **Correct this** under
its answer (or just tell it), and give the right answer. It offers to save what
you said as a knowledge base entry; once you agree, the entry is used for
everyone at once, marked **unverified**.

### The knowledge base

**AI Assistant → Knowledge** (`ai_knowledge_page.php`) lists every entry with
its question, answer, keywords, related page and status. Anyone can add an entry
there. Managers review entries: **Publish** the correct ones, **Edit** unclear
ones, **Retire** or **Delete** wrong ones. Publishing a correction retires the
entry it corrects. An entry can be limited to one project; only users with
access to that project see it.

---

## 12. Documentation Topics

Documentation Topics classify a project's documents by the numbered Topics of
a catalogue such as STD-ENG-004 (Engineering Project Document Topic System):
00 Project Governance and Documentation Planning, 10 System Concept and
Requirements, 20 Design and Descriptive Data with 21–23 beneath it, 30
Verification and Validation, and 40–60 for production, in-service use and
retirement. They show which Topics a project needs and which documents cover
them. Doctis is the index: there is no separate index document to maintain.

Topics are optional. An administrator switches the feature on, and each
project chooses whether to use it.

### The Topics page

Open **Topics** in the sidebar with a project selected. The page lists every
Topic of the project's catalogue with:

- **Required**: the project's decision for the Topic: Applicable, Not
  applicable (with its rationale) or Undecided. Every Topic starts Undecided.
- **Documents**: the documents tagged with the Topic, with their status,
  whether each is part of the Topic or the whole of it, and the sections.
- **Coverage**: Complete (declared) when a document is tagged as the whole
  Topic, Partial when only parts are tagged, No documents, or Not applicable.

The header counts the Topics in each state. Below the table, **Untagged
documents** lists the project's documents with no Topic yet, each with a
form to tag it.

Coverage is a declaration by the documents' authors, not an assessment: a tag
does not say the content is adequate, and it does not approve a document or a
release. Each Topic's coverage comes from its own tags only. Topics 21–23 are
grouped under 20 for display: tagging 21 does not cover 20, and 20 being Not
applicable does not affect 21–23. Archived documents stay listed (struck
through) but do not count.

If some tagged documents are hidden from you (for example private documents),
the page says so without naming them; coverage still counts them.

### Tagging a document

Tag from the document's **Documentation Topics** panel, from **Untagged
documents** on the Topics page, or from a Topic's own page (click its title).
Choose:

- **Whole Topic**: this document is all of the Topic's required
  documentation.
- **Part of Topic**: it is one contributor among several.
- **Sections** (optional): which parts of the document are relevant, for
  example "§3–5, Appendix B". Use this for combined documents, so one
  document can cover several Topics.

You can also tick Topics when creating a document: the create form lists the
project's Topics, each with part or whole and sections.

To tag many documents at once, select them in the document list and choose
**Add Topic** from the action menu at the bottom. Pick the Topic, part or
whole, and optional sections; documents that already have the Topic are
listed as failed and left unchanged.

A document can have any number of Topics, and a Topic any number of documents.
Every tag change is recorded in the document's history.

### Topics in the document list

In a project that uses Topics, the document list has a **Topics** column with
each document's Topic codes; whole-Topic tags are in bold, and hovering over a
code shows the Topic, extent and sections. The filter has a **Topics** field:
choose one or more Topics, or **[no Topic]** for documents not yet tagged.
Choosing a principal Topic such as 20 also finds documents tagged with its
children (21–23); the column shows each document's own codes.

### Printing and exporting

**Print** on the Topics page opens a printable copy, and **CSV** downloads the
same rows for a spreadsheet: one row per Topic and document, with
applicability, rationale, coverage, document status, extent and sections.
Both state the project, the catalogue, when and by whom they were generated,
and that they are a live view rather than a review snapshot. They show only
what you may see.

### Deciding whether a Topic applies

Project managers click **Decide** (or open the Topic's page). Choose
Applicable, Not applicable or Undecided and record a rationale. Not applicable
requires a rationale. A missing document, lack of time or work planned for
later is not a reason (STD-ENG-004 §5.1). Every decision is kept, with who
made it and when, in the Topic's decision history.

### Enabling Topics on a project

A project manager opens **Topics** with the project selected, chooses a
catalogue and clicks **Enable Topics**. **Disable Topics** at the bottom of
the page hides them again but keeps every tag and decision. A project keeps
its catalogue: changing to another catalogue is not possible once it has
records.

### Milestones, expectations and assessment

Tags say which documents belong to a Topic. Expectations say what information
the project actually needs, and assessments record whether the evidence
provides it.

- **Milestones.** Open **Milestones** from the Topics page. A project manager
  can load the STD-ENG-004 list (Planning / ECP, SRR / PDR, CDR, TRR,
  Release / Incorporation Approval, In-service review, Retirement / closure)
  or define their own, and mark each one achieved. The **current**
  milestone is the first not yet achieved. The Topics pages have a
  milestone selector; states follow milestone order, not dates.
- **Expectations.** On a Topic's page a project manager adds expectations:
  what information is needed, its owner, the milestone it is due from, and
  the maturity it needs (Draft, Under Review or Approved). A Topic can have
  several, for example V&V planning, traceability, procedures and results
  under Topic 30. Every change to an expectation makes a new revision.
- **Evidence.** On an expectation's page, link the documents that provide
  the information, with the relevant sections. Only documents of the project
  tagged with the Topic can be evidence; removing the tag removes the link.
- **Assessment.** An authorised assessor records **Adequate** or **Gap**,
  with a rationale. The assessment records each evidence document's version
  (its On Record commit, or its edition and revision without a primary
  file).

Each expectation then has a state at the selected milestone:

| State | Meaning |
|-------|---------|
| Not due | Due from a later milestone |
| Reassessment required | The evidence, its version or sections, or the expectation changed after the assessment; the reasons say what |
| Missing | No evidence linked |
| Gap | The assessment found the content insufficient |
| Pending | Not assessed yet, or evidence below the required maturity, or inactive; the reasons say which |
| Satisfied | Assessed adequate, and every evidence document has the required maturity |

Document status gives maturity: Draft for draft (165) and the earlier
statuses (pending to assigned), Under Review for review and independent
review, Approved for approved (180) and incorporated. Approved meets an
Under Review or Draft requirement, and Under Review meets Draft. A staged
draft of a document does not change its version; promoting it does.

A Topic is **Satisfied** when every expectation due is satisfied,
**Incomplete** otherwise, **Not due** when none is due yet, and **No
expectations** when an applicable Topic has none. A child Topic never
affects its parent.

### Profile and documentation readiness

The project's **profile** is its applicability decisions, expectations and
milestones. A project manager authorises it in the **Topic profile**
section of the Topics page, once every Topic is decided, every applicable
Topic has an expectation, and every expectation has an owner. Each
authorisation is a numbered revision; later changes show as **Changed since
authorisation** until it is authorised again. Achieving a milestone,
linking evidence and assessing are not profile changes.

**Documentation readiness** for the selected milestone is:

- **Ready**: the profile is authorised and unchanged, and every expectation
  due is satisfied;
- **Not ready**: some expectation due is not satisfied;
- **No expectations due**;
- **Profile unresolved**: not authorised, changed since, Topics undecided, or
  no milestones.

Readiness describes documentation only. It does not approve documents or
authorise a review, gate or release. Print and CSV include the milestone,
readiness, profile and expectations.

### Snapshots for reviews

At a technical review or baseline decision, a project manager takes a
**snapshot** in the Snapshots section of the Topics page. It freezes the
coverage at the selected milestone: decisions, documents, expectations,
states and reasons, evidence versions, assessments, readiness and the
profile revision. Unresolved states are kept as they were. A snapshot is
never changed afterwards; it carries a fingerprint (SHA-256), and its page
says if the stored record no longer matches it. Snapshots print and export
to CSV like the live page and are labelled as frozen records. A snapshot is
evidence of a decision, not an approval.

When you open a snapshot, documents you cannot see now are not named, even
if they were in the snapshot.

### Documents from other projects

A project can use another project's document, for example a shared interface
specification, in its own Topics. In the **Documents from other projects**
section of the Topics page, enter the document's ID with the Topic, part or
whole, and sections. It then appears as "from <project>" and can be evidence
for this project's expectations, assessed by this project. The document stays
in its own project; its page lists the projects that use it.

### Activity, changes and conflicts

**Activity** (linked from the Topics page) lists everything done with the
project's Topics: decisions, tags, milestones, expectations, evidence,
assessments, authorisations, snapshots and catalogue changes, with who and
when.

If someone else changes a decision, an expectation, the evidence of an
expectation or the profile while you have its page open, your change is
refused with a message to reload and check. This makes sure an assessor
judges, and an authoriser approves, exactly what they saw.

Evidence needs an identifiable version: a primary file, or an edition or
revision for documents held elsewhere (standards, external reports). Without
one the expectation stays Pending.

### Catalogues (administrators)

**Manage → Topics** lists the catalogues. **Load STD-ENG-004 Rev A** creates
the bundled catalogue, transcribed from the standard.

- **Draft**: a catalogue can be edited freely (Topics, codes, parents,
  descriptions), and projects may adopt it for pilots. Their Topics page
  shows that the catalogue is a Draft.
- **Published**: the catalogue is frozen. Topics can be retired or restored,
  but not edited, and codes are never reused. Publishing requires a source
  document (the standard's Doctis record) that is accepted or incorporated.
- **Retired**: no new project can adopt it.

Topics nest one level only (principal Topic and child). A retired Topic can
no longer be added to documents; existing tags stay.

To revise a Published catalogue, use **New revision** on its page: it copies
the catalogue and its Topics as a Draft with the new revision. Edit and
publish it. Each project then moves to it from its Topics page (**Review the
change**): a preview matches every Topic by code and shows which are
unchanged, changed, added, retired or missing, with the project's records.
A missing code that still has records blocks the move. After the move,
expectations of changed Topics need reassessment and the profile needs
authorising again; earlier snapshots keep the old revision.

---

## 13. Administration notes

### Project owner

Each project names an **Owner** on Manage → Projects → the project: the person
responsible for it. The project list shows the owner in its own column.

A sub-project left on **inherit from parent project** follows its parent, so a
project tree has one responsible person unless a sub-project names its own. The
edit form tells you which project an inherited owner is coming from. Clearing a
sub-project's owner makes it inherit again.

The owner is used to suggest who should take over a departing user's documents
(see Transfer Responsibilities below), and as the owner of a new document whose
creator is not permitted to own documents. An owner whose account is disabled
counts as no owner.

The **Classification** field beside it is recorded but not yet used anywhere;
it is free text, pending the classification scheme being settled.

### Removing a user — disable, do not delete

**When someone leaves, disable their account.** Manage → Users → the account →
untick **Enabled** → **Update User**.

Disabling is the correct way to remove a staff member because it keeps the
record intact:

- they can no longer log in, and receive no further email;
- they disappear from the Owner, Assigned To and every other person picker, and
  from the organisational chart;
- **their name is kept** on every document, note and history entry they ever
  touched, shown struck through — so the audit trail still reads correctly;
- their username and email stay reserved, so a new joiner cannot take a
  departed colleague's name and appear to inherit their history.

Disabled accounts are hidden from the user list by default; tick **show
disabled** to see them.

**Deleting an account is for mistakes only** — a fake sign-up, or a
registration that needs reverting — and is an administrator action
(`$g_delete_user_threshold`). Deleting does **not** tidy anything up: the
person's documents, notes and history entries keep pointing at the removed
account and their name is replaced by a meaningless `user<id>`. Never delete
an account that has been used.

**Prune Accounts** (on the Unused filter) is the safe bulk form: it only
removes accounts that have never been logged into.

**Handing on their work.** Disabling stops the account but does not pass on
what the person was responsible for. When you disable someone who still holds
anything, Doctis takes you straight to **Transfer Responsibilities**, which
lists what they hold — documents they own or are assigned, issues, meetings
they were due to chair, open meeting actions, Topic expectations, and
categories that assign to them by default — and asks who takes it over. The
picker suggests their manager from **Reports To** where one is recorded; change
it if someone else should take over.

Items the new holder is not permitted to take on are left alone and reported,
so check the count afterwards. You can reach the same page at any time from the
user's page, so an account disabled earlier can still be tidied up.

A transfer never changes who *created* a document, or any note or history
entry. Those keep the original person's name.

### Documentation Topics settings

In `config/config_inc.php`:

| Setting | Purpose |
|---------|---------|
| `$g_topics_enabled` | `ON` shows Topics (default `OFF`); records are kept when off |
| `$g_manage_topic_threshold` | Who manages catalogues (default ADMINISTRATOR) |
| `$g_topic_profile_threshold` | Who enables Topics on a project and decides applicability (default MANAGER) |
| `$g_topic_tag_threshold` | Who tags documents (default DEVELOPER) |
| `$g_topic_view_threshold` | Who sees Topics (default REPORTER) |
| `$g_topic_inactive_status` | Statuses whose tags are shown but not counted (default archived) |
| `$g_topic_catalogue_publish_status` | Source document statuses that allow publishing a catalogue (default approved, incorporated) |
| `$g_topic_maturity_map` | Maturity (Draft, Under Review, Approved) of each document status; unmapped statuses keep expectations Pending |
| `$g_topic_assess_threshold` | Who may record assessments (default MANAGER) |
| `$g_topic_snapshot_threshold` | Who takes snapshots (default MANAGER) |
| `$g_topic_assessor_licenses` | Licenses assessors must also hold, by Topic code (`'*'` for all), e.g. `array( '*' => 'Topic Assessor', '23' => 'Safety Assessor' )`; empty = threshold only. Unlike an access level, a license does not pass to administrators automatically |

### Publishing settings

Publishing is off until an administrator allows website directories in
`config/config_inc.php`, and pandoc 3 is installed:

```php
$g_dwg_publish_roots = array( '/var/www/html/site' );   # empty = publishing off
```

Each project then sets its website on **Manage → Projects → (project) →
Publishing**:
- **Target directory**: at or below an allowed root, and writable by the web
  server.
- **Website URL**: e.g. `/site/`.
- Optionally, a **Template** and a **Banner logo**, given as repository paths
  on the default branch.
- **PDF**: also publish a PDF of each document. This needs the TeX packages
  listed in `doc/publish/PUBLISHING.md` §9. `$g_dwg_publish_pdf_papersize`
  sets `letter` or `a4`.

A sub-project without its own target publishes into its parent's site.
`$g_dwg_publish_threshold` (default MANAGER) controls who may publish. Details:
`doc/publish/PUBLISHING.md`.

### AI Assistant and meeting settings

In `config/config_inc.php`:

| Setting | Purpose |
|---------|---------|
| `$g_anthropic_api_key` | Enables the AI Assistant (blank = hidden) |
| `$g_meeting_project_id` | Project whose repository holds meeting records (0 = no documents) |
| `$g_meeting_view_all_threshold` | Who sees all meetings (default MANAGER) |
| `$g_ai_knowledge_review_threshold` | Who reviews the knowledge base (default MANAGER) |
| `$g_ai_knowledge_manual_path` | The manual the assistant reads (this file) |

Repeating meetings need the scheduler in cron (installed by the nginx
installer): `scripts/meeting_schedule.php`, hourly.

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
