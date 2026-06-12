---
doc_id:         PLAN-DOC-001
title:          Doctis QMS Integration Plan
revision:       A
status:         Draft
owner:          QMS Lead
approver:
effective_date:
review_period:  12 months
classification: Internal
---

# Doctis QMS Integration Plan

**Document ID:** PLAN-DOC-001 | **Revision:** A | **Status:** Draft | **Owner:** QMS Lead

## Related Documents

| Document | Relationship |
|----------|-------------|
| [QMS Document Access and Distribution Plan](../../../../Robo/HCRQMS/system/guidance/QMS-Document-Access-and-Distribution-Plan.md) | Original two-layer architecture this plan supersedes |
| [Fundamental Principle of Data Management](../../../../Robo/HCRQMS/system/guidance/Fundamental-Principle-of-Data-Management.md) | Core docs-as-code and separation-of-content principles; preserved in the new design |
| [Open Standards Documentation Methodology](../../../../Robo/HCRQMS/system/guidance/Open-Standards-Documentation-Methodology.md) | Text-based, version-controlled authoring methodology; preserved in the new design |
| [Git Storage Backend](GIT_STORAGE_BACKEND.md) | Technical design for the git-backed document store that underpins this plan |

---

## 1. Purpose

This plan records a fundamental architectural decision: **Doctis replaces GitHub as the
primary interface to the QMS document control system and becomes the single point of
access for all controlled QMS documents.**

The original design, described in PLAN-SYS-007, operated as a two-layer architecture:

```text
LAYER 1 — Authoring and approval
  GitHub (private) · Git · Pull Requests · CI/CD pipeline
  Audience: document authors, reviewers, approvers

              ↓  on merge to main

LAYER 2 — Distribution and access
  Intranet · SharePoint · Doctis (as register/index only)
  Audience: all staff
```

Under that design, Doctis was explicitly an adjunct — a register of document metadata
with links pointing to GitHub-hosted sources and a separately provisioned intranet or
SharePoint distribution target. The document store lived in GitHub; Doctis carried no
files.

**That design is now revised.** The two-layer principle is preserved, but the
authoring/approval layer moves entirely from GitHub to Doctis:

```text
LAYER 1 — Authoring, review, and approval
  Doctis · dedicated git server · Pandoc pipeline
  Master source (Markdown/AsciiDoc) stored in git; never rendered output
  Audience: document authors, reviewers, approvers

              ↓  on approval:
              Pandoc renders source → pushes HTML to intranet server

LAYER 2 — Distribution and access
  Corporate intranet web server · rendered HTML
  Audience: all staff (no Doctis account required to read published docs)
```

GitHub is no longer in scope. SharePoint is no longer in scope. Doctis is no
longer a passive register pointing elsewhere — it is the active authoring, approval
and publishing system. The intranet web server role is unchanged; what changes is
that it is now fed by a Doctis-triggered pipeline rather than GitHub Actions.

---

## 2. What Does Not Change

The design change is architectural — not a change of underlying principles. The following
commitments from the QMS guidance documents remain in force:

| Principle | Source | How it is preserved |
|-----------|--------|-------------------|
| Separation of content and presentation | GUID-SYS-002 | Source documents remain plain-text (Markdown/AsciiDoc/LaTeX); rendering is a separate step triggered on upload or approval |
| Docs-as-code — version control | GUID-SYS-002, GUID-SYS-003 | Every document file is committed to a git repository on the dedicated server; full revision history, SHA-addressable blobs |
| Tamper-evident audit trail | GUID-SYS-002 | Git commit hashes provide cryptographic proof of content at each point in time; Doctis surfaces this in the document history view |
| Multi-format publishing | GUID-SYS-003 | Pandoc rendering pipeline is triggered by Doctis on approval; rendered outputs (HTML to intranet, DOCX on demand) are generated from the master source but never stored in git |
| Access control by classification | PLAN-SYS-007 | Implemented via Doctis projects (organisational boundary) and Doctis licenses (user qualifications / clearances) rather than GitHub repository access and SharePoint groups |
| Single source of truth | GUID-SYS-002 | The Doctis register entry backed by the git object hash is the canonical version; all access routes through Doctis |

---

## 3. The New Architecture in Detail

### 3.1 System Components

| Component | Role | Technology |
|-----------|------|-----------|
| **Doctis** | Document lifecycle management: register, authoring workflow, review, approval, access control; stores and version-controls master source content | PHP/MariaDB (this codebase) |
| **Dedicated git server** | Master source file store; version history; content integrity; one repo per Doctis project | Self-hosted bare git (Gitea/Forgejo recommended) |
| **Pandoc pipeline** | Renders approved master source to HTML and (where required) DOCX on demand; pushes rendered HTML to the intranet web server | Pandoc + CI job triggered by Doctis approval event |
| **Intranet web server** | Publication surface for approved HTML documents; accessible to all staff without authentication | Nginx/Apache on corporate LAN |
| **MariaDB** | Document register metadata, user accounts, workflow state | MariaDB (existing) |

No GitHub. No SharePoint dependency. The two-layer architecture from PLAN-SYS-007
is preserved — authoring/approval layer (Doctis + git server) and distribution layer
(intranet web server) — but the authoring layer moves from GitHub to Doctis.

### 3.2 Per-Project Git Repositories

Documents are organised by Doctis **project** — the existing MantisBT/Doctis concept
of a top-level administrative boundary. Each project maps to one git repository on
the dedicated git server:

```text
git-server:/repos/
    <project-name>/           ← one bare repo per Doctis project
        <dwg_reference>/
            source.<md|adoc>  ← master source content ONLY
```

Rendered outputs (HTML, DOCX, PDF) are never committed to the repository. They are
generated on demand from the master source by the Pandoc pipeline and pushed directly
to the intranet web server (HTML) or delivered to recipients (DOCX). The git repository
contains only the canonical source material.

The per-project boundary enables:
- Repository-level read clones to be issued to external reviewers without
  exposing documents from other projects
- Independent backup and archival schedules per project
- Clear ownership and retention scoping

For the full technical design of the git storage mechanism, see
[Git Storage Backend](GIT_STORAGE_BACKEND.md).

### 3.3 How Doctis Licenses Support Document Access Control

In Doctis, a **license** is a skill, security clearance, or professional qualification
registered against a user account. Document access can be restricted to users who hold
specified licenses.

This maps onto the QMS classification scheme as follows:

| QMS Classification | Access control mechanism in Doctis |
|-------------------|------------------------------------|
| `Internal — All Staff` | No license requirement; all registered Doctis users |
| `Internal — Department` | Doctis license representing department membership or role |
| `Restricted` | Doctis license representing the specific clearance or need-to-know |
| `Confidential` | Doctis license granted only to named individuals; document may additionally be marked non-downloadable |

This replaces the SharePoint folder groups and GitHub repository access controls
described in PLAN-SYS-007. The classification scheme itself (from PLAN-SYS-007 §3.2)
is preserved; only the enforcement mechanism changes.

### 3.4 Document Lifecycle in the New System

```text
1. REGISTER
   Author creates document record in Doctis (reference, title, project,
   classification, owner). Doctis creates an empty git object in the
   project repository.

2. DRAFT
   Author uploads source file (Markdown/AsciiDoc/LaTeX) via Doctis.
   Doctis commits the file to the project git repository; records the
   commit SHA in {dwg_file}.content_hash. Status: DRAFT.

3. REVIEW
   Author advances document to review. Doctis notifies assigned reviewers.
   Reviewers add dwgnotes (comments/observations) against specific revisions.
   Reviewer can upload a revised source; each upload is a new git commit.

4. APPROVAL
   Approver (determined by document classification and project configuration)
   marks document as APPROVED in Doctis. Doctis:
   (a) Records the approval event and approver identity in the history log
   (b) Tags the git commit as the approved revision (e.g. v1.0-approved)
   (c) Triggers the Pandoc rendering pipeline

5. PUBLICATION (triggered by approval)
   The Pandoc pipeline checks out the approved source from git, renders it,
   and publishes the output:
   - HTML → pushed to the intranet web server, accessible to all staff
   - DOCX → generated on demand or delivered to named recipients where
             required; not stored in the git repository or in Doctis
   Rendered outputs are always ephemeral products of the master source.
   If the intranet copy is lost or corrupted, it is simply regenerated
   from the git-controlled source.
   Doctis's role at this stage is to hold the master source and the
   approval record — not to serve rendered output.

6. REVISION
   A new revision increments the document's revision field and moves
   status back to DRAFT. Previous approved version remains accessible
   in the git history and is surfaced in the Doctis revision history view.

7. SUPERSESSION / ARCHIVAL
   Superseded documents are marked ARCHIVED in Doctis. The git history
   is preserved. The document remains discoverable in Doctis but is
   clearly marked as no longer current.
```

---

## 4. What Doctis Must Be Extended to Support

The following capabilities are required beyond current Doctis functionality. This
section constitutes the development requirements backlog for this integration.

### 4.1 Git Storage Backend (Priority: Critical)

Implement the GIT storage method described in [GIT_STORAGE_BACKEND.md](GIT_STORAGE_BACKEND.md).

Minimum viable scope:
- Approach C (storage backend interface) or Approach B (czproject/git-php) as described
- One git repository per Doctis project, initialised when the project is created
- Content hash recorded in `{dwg_file}.content_hash` on every upload
- Commit message includes document reference, revision, and uploading user

### 4.2 Approval Workflow (Priority: Critical)

Current Doctis status transitions are informal. A formal approval gate is required:

- Define an APPROVED status in `$g_dwg_status_enum_string`
- Approval action must be restricted to users holding the approver role for the project
- Approval event must be recorded in the history log with approver identity and timestamp
- Approval must trigger git tag and (optionally) the Pandoc render job

### 4.3 Pandoc Rendering Integration (Priority: High)

On document approval, Doctis triggers a render job that:
- Checks out the approved master source from the git repository
- Runs Pandoc with the QMS corporate template (`reference.dotx`)
- Publishes the rendered HTML to the intranet web server
- Optionally generates DOCX for stakeholders who require Word format

Rendered outputs are **not** stored in the git repository and are **not** served
by Doctis. The git repository holds only the master source. The intranet web server
holds only the rendered HTML. If either rendered form is needed again, the pipeline
reruns from the unchanged git source.

DOCX is a legacy accommodation. Where stakeholder buy-in requires a Word deliverable
during the transition period, DOCX is generated on demand. The goal is for HTML on
the intranet to become the primary reading format as adoption matures.

Implementation options:
- Shell out to Pandoc on the Doctis web server (simplest; requires Pandoc installed
  in the Docker image)
- Invoke an external CI job via webhook (decoupled; requires a CI runner alongside
  the git server)
- Defer render to a background PHP job that polls a queue (balanced approach)

### 4.4 Document Classification Enforcement (Priority: High)

The `classification` field in the document source frontmatter must be read by Doctis
and used to enforce access:
- Parse the `classification` value on upload (or allow selection from a controlled list
  in the Doctis document creation form)
- Map classification to required Doctis license(s) automatically, or allow the document
  owner to configure the license requirement on the document record
- Restrict access to the master source in Doctis to users holding the required license;
  for the intranet-published HTML, access control is enforced at the intranet server
  level (e.g. htpasswd, VPN, or network segmentation) for `Restricted` and above

### 4.5 Staff Self-Registration and Onboarding (Priority: High)

All staff must be able to register in Doctis without administrator intervention for
`Internal — All Staff` documents. For classified documents, a license grant workflow
is already partially implemented (`email_dwg_license_apply_for_access()` — see recent
commits). This workflow should be completed and tested end-to-end.

### 4.6 Revision History Surface (Priority: Medium)

The git commit history for each document must be surfaced in the Doctis document view:
- Show each upload as a revision entry (commit hash, date, uploader, commit message)
- Link approved revisions to their git tag
- Allow download of any previous approved version

The `dwg_revision_view_page.php` infrastructure already exists; it requires extension
to read from git metadata rather than only the Doctis database history log.

### 4.7 Per-Project Clone URL Exposure (Priority: Low — Phase 2)

For external reviewers or auditors who require direct git access, expose a read-only
clone URL per project in the Doctis project administration pages. This requires the
dedicated git server to support either SSH or HTTP git protocol.

---

## 5. What Is No Longer Required

The following items described in PLAN-SYS-007 are no longer needed in the new design:

| Item (from PLAN-SYS-007) | Status | Reason |
|--------------------------|--------|--------|
| GitHub private repository | Not required | Dedicated self-hosted git server replaces it |
| GitHub Actions workflow (`build.yml`) | Not required | Pandoc pipeline triggered by Doctis approval event, not a GitHub push hook |
| GitHub deploy SSH key / secrets | Not required | No GitHub dependency |
| SharePoint for `Internal — Department` tier | Not required | Doctis license-based access control replaces SharePoint groups |
| Microsoft Graph API integration | Not required | No SharePoint publishing pipeline |
| Corporate intranet web server (Nginx/Apache) | **Still required** | Publication surface for rendered HTML; fed by the Pandoc pipeline on document approval |
| DNS entry `qms.hcrobotics.internal` | Still required | Points to the Doctis server; a second hostname may be used for the intranet publication surface |

The QMS infrastructure reduces to: **Doctis server + dedicated git server + intranet
web server**. The intranet web server requirement is unchanged from PLAN-SYS-007 —
what changes is that it is now fed by Doctis-triggered rendering rather than a
GitHub Actions workflow.

---

## 6. Infrastructure Requirements

| Component | Specification |
|-----------|-------------|
| Doctis web server | PHP 8.2, Apache/Nginx, MariaDB — existing production Docker image (`docker-live/`) |
| Dedicated git server | Gitea or Forgejo (recommended); or bare git with SSH access; self-hosted on corporate LAN or private VPS |
| Pandoc pipeline | Pandoc installed on Doctis web server or on a dedicated build runner; triggered on approval; pushes HTML to intranet server |
| Intranet web server | Nginx or Apache on corporate LAN; receives rendered HTML from the Pandoc pipeline; no login required for `Internal — All Staff` documents |
| QMS corporate template | `reference.dotx` (in progress) — required before rendered DOCX output carries correct branding; HTML output is available without it |
| Network | Doctis: accessible to all staff; git server: accessible to Doctis and Pandoc pipeline only (not staff-facing); intranet server: accessible to all staff on corporate LAN |
| DNS | Hostname for Doctis (e.g. `docs.hcrobotics.internal`); hostname for intranet publication surface (e.g. `qms.hcrobotics.internal`) |
| TLS | Recommended for Doctis traffic; intranet server may be plain HTTP on an isolated corporate LAN |
| Backup | Git repositories on the dedicated server are the single source of truth — all rendered output can be regenerated. Back up with `rsync` or `git bundle`; rendered HTML on the intranet server is expendable |

---

## 7. Migration from the Original Design

For teams currently working under the PLAN-SYS-007 architecture:

| Step | Action | Notes |
|------|--------|-------|
| 1 | Provision dedicated git server | Gitea/Forgejo recommended for web UI, SSH keys, and access token management |
| 2 | Configure Doctis production instance with `$g_file_upload_method = GIT` | Requires Phase 1 of the git backend implementation (see §4.1) |
| 3 | Create Doctis projects corresponding to QMS document groupings | One project per document set; projects create git repos on the dedicated server |
| 4 | Import existing approved documents | Upload current approved source files via Doctis; first commit establishes the git history baseline |
| 5 | Configure Doctis licenses for each classification tier | Map classification values to license requirements; grant licenses to users per role |
| 6 | Enable approval workflow | Configure approver roles per project; test end-to-end approval → git tag → render |
| 7 | Communicate access point to all staff | Single URL: the Doctis instance |
| 8 | Retire GitHub repository (documents) | After all documents are imported and access confirmed in Doctis; retain read-only archive |

---

## 8. Open Questions

| # | Question | Impact |
|---|----------|--------|
| 1 | Self-hosted Gitea vs. bare git with SSH? | Gitea adds a web UI and access token management useful for auditors; bare git is simpler to operate. Determine based on whether external reviewer git access (§4.7) is in scope for Phase 1 |
| 2 | Pandoc on Doctis server vs. external CI runner? | If Doctis runs in Docker (`docker-live/`), the Dockerfile must be extended to include Pandoc and `texlive` (for PDF). An external runner avoids this but adds infrastructure |
| 3 | `reference.dotx` availability? | Rendered DOCX output requires the corporate Word template. Until it exists, HTML output is the primary rendered format |
| 4 | Approval workflow: single approver vs. multi-stage sign-off? | ISO 9001 typically requires author and approver to be different individuals; some documents may require a department head and QMS Lead sign-off chain. Define before implementing the approval gate |
| 5 | Doctis user provisioning: self-registration vs. administrator-created accounts? | `Internal — All Staff` documents should require no barrier to registration; classified documents require a license grant step that implies an administrator or supervisor in the loop |
| 6 | Handling of documents currently only in DOCX (not source Markdown)? | Some QMS documents may only exist as Word files. A migration path is needed: either accept DOCX as the "source" (treating it as an opaque binary in git) or require conversion to Markdown before import |
| 7 | Retention and legal hold? | ISO 9001 clause 7.5 requires that obsolete documents are retained for a defined period. Confirm that the git history (permanent unless force-deleted) satisfies this requirement, or whether a separate archival export is needed |

---

## 9. Relationship to Existing Doctis Development Work

The following active work items in Doctis directly support this plan:

| Work item | Relevance |
|-----------|-----------|
| [Git Storage Backend design](GIT_STORAGE_BACKEND.md) | Core enabler — must be implemented first |
| `email_dwg_license_apply_for_access()` | License grant notification; supports the classification-based access flow (§3.3) |
| `document_get_number()` added to `document_api.php` | Feeds the Pandoc commit message and rendered document header |
| `$f_issue_id` → `$f_dwg_id` rename in `dwg_view_inc.php` | Code hygiene; reduces confusion during the expanded development work this plan requires |
| `dwgnote_*` `bug_id` → `dwg_id` fixes | Correctness fixes required before the review workflow (§4.2) can be relied upon |

---

## Revision History

| Revision | Date | Description | Author |
|----------|------|-------------|--------|
| A | 2026-06-09 | Initial draft — records architectural decision to make Doctis the single QMS interface, backed by dedicated git server; supersedes the two-layer GitHub+distribution design of PLAN-SYS-007 | QMS Lead |
