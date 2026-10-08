# Document Topics — Implementation Plan

Status: proposal, 2026-10-08. Nothing is implemented yet.

This plan describes how Doctis can support the Engineering Project Document
Topic System. The QMS sources are in the HCRQMS repository:

| Document | Path in HCRQMS | Role |
|----------|----------------|------|
| STD-ENG-004 Engineering Project Document Topic System Standard | `content/engineering/standards/Engineering-Project-Document-Topic-System.md` | Topic catalogue, applicability and coverage rules |
| STD-IT-001 Doctis Project Document Topics — Feature Functional Specification | `content/it/standards/Doctis-Project-Document-Topics-Functional-Specification.md` | Full functional specification (29 requirements, 15 acceptance tests) |

STD-IT-001 describes the finished system and makes all of it the first
release. This plan delivers it in phases. Phase 1 does only the core task:
keep a list of defined Topics, and tag documents within a project as part of,
or the whole of, a Topic's required documentation.

## Contents

1. [Decisions recorded](#1-decisions-recorded)
2. [Review of the QMS documents](#2-review-of-the-qms-documents)
3. [What Doctis already provides](#3-what-doctis-already-provides)
4. [Phase 1 — Topic list and document tagging](#4-phase-1--topic-list-and-document-tagging)
5. [Phase 1b — Conveniences](#5-phase-1b--conveniences)
6. [Pilot projects](#6-pilot-projects)
7. [Phase 2 — Expectations and assessment](#7-phase-2--expectations-and-assessment)
8. [Phase 3 — Evidence records](#8-phase-3--evidence-records)
9. [Requirement mapping](#9-requirement-mapping)
10. [Changes proposed to the QMS documents](#10-changes-proposed-to-the-qms-documents)
11. [Open questions](#11-open-questions)

## 1. Decisions recorded

Owner decisions, 2026-10-08:

- **No production release exists yet.** Existing tables in `admin/schema.php`
  may be changed, and a database rebuild is expected. Phase 1 tables can be
  revised when Phase 2 is designed.
- **The lifecycle statuses are provisional.** The current document statuses
  (pending, received, triage, JoS, assigned, review, rework, independent
  review, accepted, incorporated, archived) only demonstrate what is possible.
  Production will use a smaller set aligned with the QMS and engineering
  approval workflow, which is not yet defined. Topic code must not hard-code
  status values: any status-to-maturity rule is configuration (Phase 2).
- **Engineering projects are standalone.** HCR571 and every other product
  development project are top-level Doctis projects, not sub-projects of
  HCRQMS. HCRQMS is the standalone QMS site-content project.
- **Pilot and test projects** will be created to scope and develop the feature
  (section 6).
- **Parent Topics do not depend on their children.** Topics 21–23 are grouped
  under 20 for display and filtering only. A child's state never affects the
  parent's state, and the parent's applicability does not constrain the
  children's.

## 2. Review of the QMS documents

### STD-ENG-004 (standard)

The catalogue and principles map directly onto Doctis data. A tag is not an
assessment; one document can cover several Topics and several documents can
cover one; combined documents identify sections; Not Applicable needs a
rationale; and Doctis is the index. Points that affect implementation:

1. **It assumes Doctis capabilities that Phase 1 will not have.** §5.2
   (expectations), §6 (milestone maturity), §7 (the six coverage states) and §9
   (snapshots) depend on Phases 2 and 3. Add an interim clause: until Doctis
   supports assessments, Doctis holds Topic tags and applicability decisions,
   and adequacy judgements stay in technical review records (PROC-ENG-005).
2. **Lifecycle vocabulary.** It refers to Draft / Under Review / Approved
   (PROC-SYS-001 adds Superseded and Archived). Doctis statuses differ and are
   provisional (section 1). The maturity rule needs a defined mapping once
   the production status set exists.
3. **"Unassessed" applicability** clashes with adequacy "assessment".
   "Undecided" is clearer; this plan uses it.
4. **Unintended parent/child dependency.** Two sentences create the
   dependency that section 1 rules out, and should be revised:
   - §5.1: "When Topic 20 is excluded, applicable child Topics are a conflict
     requiring resolution."
   - §7: "A principal Topic with subtopics is satisfied only when its own due
     expectations and those of all applicable child Topics are satisfied."

   As written, Topic 20 (Design) could never be satisfied while Topic 23
   (Safety, Risk and Compliance) had outstanding evidence.
5. **Pilot location.** §10 step 3 and Appendix B name PRD-, ICD- and
   TP-HCR571. These are QMS exemplars in HCRQMS `content/engineering/designs/`
   and, in Doctis, belong to the HCRQMS project. The pilot needs them
   registered in a standalone HCR571 project (section 6).

### STD-IT-001 (functional specification)

§1 makes the whole model the first release:
- catalogue versioning;
- profile authorisation and revisions;
- milestones, expectations and evidence sets;
- assessments and the six-state calculation with dependency tracking;
- immutable snapshots and export;
- conflict detection and redaction rules.

The hardest requirements are the least needed to start: FR-018 (reassessment
tracking), FR-020 (configuration context), and FR-022 / FR-028 (snapshot
consistency and concurrent edits). Keep the TOP-FR identifiers and assign each
to a release (section 9). FR-006, §6.2 and TOP-AT-008 also carry the
parent/child dependency and need the same revision as STD-ENG-004.

## 3. What Doctis already provides

| Existing feature | Use for Topics |
|------------------|----------------|
| Licenses: a managed list (Manage → Licenses), a document link table (`license_dwg_list`), add/remove controls in a block on the document view page (`dwg_view_inc.php`), a global on/off switch | The pattern to copy for the catalogue, the document link table and the view-page block |
| Document history (`dwg_history`, `core/history_dwg_api.php`) | Records Topic tag changes on each document |
| `dwg_primary_file.git_sha`: the On Record commit of each document; `dwg_primary_draft`: a staged replacement awaiting promotion | Phase 2 evidence version identity; "evidence changed" detection; a new draft visible beside the baseline |
| Licenses held by named users | Phase 2 assessor competence. Access levels are hierarchical, so an administrator passes every threshold; a license does not have that problem. This meets STD-IT-001's rule that administrator access alone must not confer assessment |
| `access_has_dwg_level()` | Per-document visibility on Topic views, including license restrictions |

The existing free-form document tags (`tag` / `dwg_tag`) are not suitable.
They are global and uncontrolled, and have no extent. Their display on the
view page is already commented out.

**Known issue relevant to pilots.** `dwg_move()` (`core/dwg_api.php`) moves
attachments, but not the git primary file. The primary file is looked up
through the project's repository. Moving a document into a project with a
different repository therefore leaves its primary file unreachable. Pilot
documents are registered fresh rather than moved (section 6). Fixing or
blocking cross-repository moves is a separate task.

## 4. Phase 1 — Topic list and document tagging

### 4.1 Data

New tables in `admin/schema.php`:

| Table | Columns (outline) |
|-------|-------------------|
| `{topic_catalogue}` | `id`, `name`, `source_dwg_id` (the registered standard, e.g. STD-ENG-004), `revision`, `status` (10 Draft, 30 Published, 90 Retired), `user_id`, `date_created`, `date_updated` |
| `{topic}` | `id`, `catalogue_id`, `code` (varchar, so "00" is kept), `parent_id` (0 = principal; one level only), `title`, `description` (expected information), `sort_order`, `enabled` (0 = retired); unique (`catalogue_id`, `code`) |
| `{topic_project}` | `project_id` (PK), `catalogue_id`, `enabled`, `user_id`, `date_enabled` |
| `{topic_applicability}` | `id`, `project_id`, `topic_id`, `applicability` (0 Undecided, 10 Applicable, 20 Not applicable), `rationale`, `user_id`, `date_decided`. Rows are added, never updated; the latest row per (project, topic) is current, earlier rows are the decision history |
| `{dwg_topic}` | `id`, `project_id`, `dwg_id`, `topic_id`, `extent` (10 part of the Topic, 20 the whole Topic), `locator` (sections, e.g. "§3–5, Appendix B"), `user_id`, `date_added`; unique (`project_id`, `dwg_id`, `topic_id`) |

Notes:

- **Extent** records the owner's "part of, or the entirety of": whether the
  document is all of the Topic's required documentation or one contributor to
  it. **Locator** records which parts of the document are relevant. They are
  independent.
- `{dwg_topic}.project_id` is the project whose Topic profile the tag belongs
  to (TOP-FR-009). Phase 1 only creates tags where it equals
  `dwg.project_id`. Phase 3 can allow shared evidence from other projects
  without changing the table.
- One level of nesting matches the standard ("No other parent relationships
  are implied"), so cycles cannot occur.
- Absence of an applicability row means Undecided (TOP-FR-004).

### 4.2 Catalogue administration — Manage → Topics

Pages `manage_topic_*.php`, modelled on `manage_license_*.php`:

- List catalogues; within a catalogue, list and edit its Topics: code, title,
  parent, description, order and retired flag.
- Seed STD-ENG-004 Rev A as a **Draft** catalogue. Sample data loads it for
  test environments; on a real system it is loaded from Manage → Topics.
  Seeding does not approve the standard.
- A **Draft** catalogue is fully editable.
- **Publishing** requires the source document (`source_dwg_id`) to be
  approved in Doctis. A **Published** catalogue is frozen: Topics can be
  retired but not edited or deleted, and codes are never reassigned. Revising
  it means a new catalogue (Phase 3 adds migration).
- A project using a Draft catalogue shows the Draft label prominently on its
  Topics page. Which projects may adopt a Draft catalogue is open question 4.

### 4.3 Project Documentation Topics page

A new page in the project context, for example `topic_page.php`.

**Access**
- A sidebar entry appears when the current project has Topics enabled.
- When Topics are disabled, users who can manage the project profile see an
  **Enable Topics** action and a catalogue choice. Other users see nothing.

**Layout**
```
Documentation Topics — HCR571              Catalogue: STD-ENG-004 Rev A (Draft)
Tags are declarations by document authors, not adequacy assessments.
Applicable: 6 · Not applicable: 1 · Undecided: 3 · Untagged documents: 2

Code Topic                               Required     Documents                    Coverage
00   Project Governance & Doc Planning   Undecided    —                            —
10   System Concept and Requirements     Applicable   PRD-HCR571 (pending) whole   Complete (declared)
20   Design and Descriptive Data         Applicable   ICD-HCR571 (pending) part    Partial
 21  Hardware and Physical Design        Applicable   —                            No documents
 …
30   Verification and Validation         Applicable   TP-HCR571 (pending) part     Partial
Untagged documents (2) ▸
```

**Behaviour**
- Applicability is set on this page. Not applicable requires a rationale.
  Each Topic's decision history (who, when, rationale) can be expanded.
- Coverage for each Topic uses its own tags only. It is never derived from
  child or parent Topics.

  | Coverage | Shown when |
  |----------|------------|
  | Not applicable | the Topic is decided Not applicable |
  | No documents | no current tags |
  | Partial | tags exist, but none has extent "whole" |
  | Complete (declared) | at least one current tag has extent "whole" |

  Applicability is shown in its own column; coverage is still calculated
  while it is Undecided.
- Wording stays "declared" until Phase 2 assessments exist (TOP-FR-021).
- Each document shows its current lifecycle status. Documents in configured
  inactive statuses (for example archived) are shown but not counted. That
  status list is configuration, not code.
- Untagged documents in the project are listed with a quick-tag action
  (TOP-FR-013).
- Tagged documents the reader cannot view are not listed. If any exist for a
  Topic, a generic "restricted documents" note appears, with no count
  (TOP-FR-026), and the reader's coverage label says the view is limited.
- The header shows counts per state. There are no percentages.

### 4.4 Document view page

- Add a **Documentation Topics** block like the Licenses block, shown only when
  the document's project has Topics enabled.
- Current tags appear as chips: code, title, extent and locator.
- An add form takes the Topic (from the project's catalogue, children
  indented under parents), part or whole, and sections. Each chip has a
  remove control. Editing extent or locator replaces the tag.
- Place the block in a separate include file with a one-line hook in
  `dwg_view_inc.php`, to keep the diff from the MantisBT original small.

### 4.5 History

Add new history types to `core/constant_inc.php` (Topic attached, updated,
detached), rendered by `core/history_dwg_api.php`. The old and new values
carry code, extent and locator, so the document's history keeps removed tags
(TOP-FR-024). Applicability history is the append-only table (4.1).

### 4.6 Configuration and permissions

Defaults in `config_defaults_inc.php`:

| Option | Default | Controls |
|--------|---------|----------|
| `$g_topics_enabled` | `OFF` | Global switch; with `OFF`, no Topic UI appears |
| `$g_manage_topic_threshold` | `ADMINISTRATOR` | Manage → Topics (catalogue) |
| `$g_topic_profile_threshold` | `MANAGER` | Enable Topics on a project; set applicability (project level) |
| `$g_topic_tag_threshold` | `DEVELOPER` | Add, change and remove document tags (document level) |
| `$g_topic_view_threshold` | `REPORTER` | View the Topics page and block |
| `$g_topic_inactive_status` | `array( ARCHIVED )` | Statuses whose documents are shown but not counted |

All checks run on the server, including for direct requests to action pages
(TOP-FR-025).

### 4.7 Code layout

| New | Purpose |
|-----|---------|
| `core/topic_api.php` | Catalogue, project enablement, applicability, tags, coverage calculation |
| `manage_topic_page.php`, `manage_topic_edit_page.php`, `manage_topic_update.php`, `manage_topic_catalogue_*.php` | Catalogue administration |
| `topic_page.php`, `topic_project_enable.php`, `topic_applicability_update.php` | Project Topics page and its actions |
| `dwg_topic_add.php`, `dwg_topic_update.php`, `dwg_topic_delete.php`, `dwg_view_topics_inc.php` | Document block and its actions (named like `dwg_license_*`) |

Small hooks in existing files:

| File | Hook |
|------|------|
| `admin/schema.php` | The tables |
| `config_defaults_inc.php` | Options |
| `core/constant_inc.php` | History types |
| `core/history_dwg_api.php` | History rendering |
| `core/html_api.php` | Manage menu entry |
| `core/layout_api.php` | Sidebar entry |
| `dwg_view_inc.php` | Include |
| `lang/strings_english.txt` | Strings |
| `core/dwg_api.php` | `dwg_delete()` removes tags. `dwg_move()` retargets tags when the destination uses the same catalogue and drops them otherwise, with history in both cases |

### 4.8 Tests and documentation

- PHPUnit tests for `topic_api.php`:
  - code uniqueness and "00" preservation;
  - one-level parent rule;
  - Published catalogue frozen;
  - Not applicable requires a rationale;
  - coverage labels;
  - no parent/child roll-up.
- Curl-based checks of permissions on every action page.
- An entry in `doc/TESTING.md`.
- A Topics section in `doc/MANUAL.md`, which also feeds the AI Assistant's
  Help tab.
- Phase 1 acceptance subset of STD-IT-001: TOP-AT-001, 002 (no versioning),
  003 (applicability part), 004, 011, 013, and 015 (tag part).

## 5. Phase 1b — Conveniences

Any order, after Phase 1:

- A Topics column on the document list (`core/columns_dwg_api.php`).
- A Topic filter (`core/filter_dwg_api.php`, `DwgFilterQuery`). Filtering on
  a principal Topic also offers its children, each shown with its own code.
- A bulk "Add Topic" group action (`dwg_actiongroup*.php`), for adopting
  existing projects.
- Topic selection on the document create form.
- CSV and print versions of the Topics page (TOP-FR-023, live view).
- Topics included in SOAP `mc_dwg_get`.

## 6. Pilot projects

Pilot and test projects are standalone top-level projects with their own
repositories. They are never sub-projects of HCRQMS.

- **HCR571.** Register the PRD, ICD and TP as new documents in HCR571 by
  upload. Do not move them from HCRQMS (see the `dwg_move()` issue in section
  3). Enable Topics, tag 10 / 20 / 30, decide applicability for every Topic,
  and record what is missing. Whether HCRQMS keeps its exemplar copies is a
  QMS content decision.
- **A second, smaller pilot** with a different shape, for example a
  software-only or prototype project, to exercise Not applicable decisions
  and combined documents.
- **Reloading after a rebuild.** System Operations → Load Data (2026-10-08)
  runs an uploaded SQL data script. Backup Database → Data Snapshot produces
  such a script from selected tables. Rebuild, then load:
  1. the users snapshot;
  2. a pilot template script (project rows, categories, Topic catalogue,
     enablement and applicability).

  Documents have primary files in git, which a database script cannot
  restore. Either recreate pilot documents from a small source repository
  with `admin/import-git-repo.php`, or restore the git store together with
  the database. Template scripts must also be revised when the schema
  changes.

## 7. Phase 2 — Expectations and assessment

- **Milestones** for each project: ordered and editable, defaulting to the
  STD-ENG-004 §6 list. The Topics page gets a milestone selector, and state
  follows milestone order, not dates.
- **Expectations** for each Topic: statement, owner, the milestone from which
  it is due, and required maturity. A Topic can have several.
- **Evidence** links an expectation to tagged documents, with a locator for
  each. One document can support several expectations.
- **Assessments**: Adequate or Gap, with a rationale.
  - Each records the expectation revision, the milestone and, for every
    evidence document, the On Record commit SHA.
  - Rows are added, never updated.
  - The assessor must hold an assessor license, optionally one per Topic
    (for example a safety license for 23).
- **Coverage states**: the six states in STD-IT-001 §6.1.
  - "Reassessment required" means an evidence document's current On Record
    SHA differs from the assessed SHA, or the expectation changed after the
    assessment.
  - A staged draft (`dwg_primary_draft`) does not trigger reassessment;
    promoting it does (TOP-FR-019).
- **Maturity mapping** in configuration: an array mapping each document status
  to Draft, Under Review, Approved or Inactive. It is defined once the
  production status set exists. Unmapped statuses fail closed as Pending, with
  the reason shown.
- **Profile authorisation** checks that:
  - every Topic is decided;
  - each applicable Topic has at least one expectation.

  It then records who authorised it and when, and increments the profile
  revision. Later edits show as changes awaiting authorisation (TOP-FR-007).
  There is no parent/child consistency rule (section 1).

## 8. Phase 3 — Evidence records

- **Snapshots**: the computed view frozen as JSON with evidence SHAs,
  milestone and profile revision. They can be viewed and printed with a
  "snapshot" banner, and permissions are re-applied on viewing (TOP-FR-022).
- **Catalogue revisions**: copy a catalogue to a new revision, publish it, and
  move a project across by Topic code with a change preview.
- **Shared evidence**: tags on documents from other projects, with per-project
  assessments (TOP-FR-009).
- **External evidence**: a version or citation is required for linked and
  referenced items before they can enter a snapshot (TOP-FR-011).
- **Conflict detection**: a version counter on profiles and assessments
  rejects stale writes (TOP-FR-028).
- **API**: SOAP and REST endpoints.

Not planned, as STD-IT-001 §1 already excludes them: automatic content
assessment, percentage scores, notifications, and roll-up across projects.

## 9. Requirement mapping

| Phase | TOP-FR covered |
|-------|----------------|
| 1 | All of 001, 002, 004, 008, 012, 021, 025, 026, 027, 029. Part of: 003 (frozen Published catalogue), 005 (applicability and rationale), 009 (table ready, same-project only), 010 (locator), 011 (any registered document type can be tagged), 013 (untagged list), 016 (declared view), 024 (tag and applicability history) |
| 1b | The rest of 013 (filter); 023 (live export) |
| 2 | The rest of 005, 010 and 016; 006 (without the parent/child rule), 007, 014, 015, 017, 018, 019, 020 |
| 3 | The rest of 003, 009, 011, 023 and 024; 022, 028 |

## 10. Changes proposed to the QMS documents

STD-ENG-004:

- Add the interim clause from section 2, item 1.
- Remove the parent/child dependency from §5.1 and §7. State that 21–23 are
  grouped under 20 for presentation only.
- Rename "Unassessed" to "Undecided".
- Replace the Draft / Under Review / Approved wording with a reference to the
  maturity levels defined by the QMS approval workflow, when that exists.
- §10 step 3 and Appendix B: the pilot uses a standalone HCR571 project.

STD-IT-001:

- §1: the first release delivers Phase 1. Later requirements are listed with
  their release (section 9).
- Remove the parent/child rules from FR-006, §6.2 and TOP-AT-008.
- Rename Unassessed to Undecided throughout.
- §6.1 maturity: refer to a configured mapping rather than fixed statuses.

## 11. Open questions

1. Should Topics be enabled on HCRQMS itself? The QMS content is not an
   engineering project, so this plan assumes not.
2. Should HCRQMS keep the HCR571 exemplars once the pilot project holds them?
3. Who owns the catalogue: is `ADMINISTRATOR` right for the Engineering Lead,
   or should it be a global `MANAGER`?
4. Should a Draft catalogue be adoptable by any project, or only by projects
   flagged as pilots?
