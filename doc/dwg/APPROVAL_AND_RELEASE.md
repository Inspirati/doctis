# Approval and Release Authorisation

Status: **proposal, 2026-11-01. Nothing here is built.** This document starts
the process of settling the functional requirements for Doctis's decision
point: the moment an authorised person approves a document, a change or a
release, and what Doctis then does. It proposes a design and lists the
questions the owner needs to settle (§11). Once settled, the candidate
requirements in §10 become the specification, and later sections will record
decisions, as-built notes and tests, as [OWNERSHIP.md](OWNERSHIP.md) does.

**Sources.** The QMS documents that define the process, cited by ID:

| ID | Document | What it requires of this feature |
|----|----------|----------------------------------|
| PROC-SYS-001 | Document Control Procedure | §7: approval is a Doctis lifecycle event against an identified commit; approval records, pins and publishes |
| POL-SYS-004 | Configuration Control Board Policy | the decision outcomes (§10.2), engineering vs executive authority (§8), delegation (§9.3), emergencies (§11), dissent (§8.4); "Doctis is the authoritative register and audit trail" (§12) |
| PLAN-SYS-003 | Configuration Management Plan | change workflow (§7.2), status accounting (§8), baselines (§6), release preconditions and manifest (§10); recommends CRs as a Doctis issue type (§11.3) |
| PLAN-DOC-001 | Doctis QMS Integration Plan | §3.4 lifecycle, §4.2 "Approval Workflow — partial", open question 4 (single vs multi-stage sign-off) |
| GUID-ENG-001 | Engineering Authority and Delegation Framework | named authority holders, Level 1/2/3, by domain |

Related Doctis documents: [OWNERSHIP.md](OWNERSHIP.md) (who a document's
people are), [../git/SOURCE_REPOSITORIES.md](../git/SOURCE_REPOSITORIES.md)
(lines, releases, Cut Release), [../git/GIT_TODO.md](../git/GIT_TODO.md) §8.1
(what deletion may remove, which waits on §6 here),
[../publish/PUBLISHING.md](../publish/PUBLISHING.md).

---

## 1. The problem

The QMS treats approval as **one event with consequences**. PROC-SYS-001 §7.3:
when an approver marks a document Approved, Doctis records the event, pins the
approved commit, and publishes. POL-SYS-004 widens this to every controlled
change, and PLAN-SYS-003 §10 to releases. The CCB's decision is the point at
which a publication, a manufacturing release or a shipment to a customer
becomes authorised. That decision has to be recorded, and its consequences
have to follow from it.

In Doctis today these are **separate, unconnected actions**:

| What the QMS treats as one event | What Doctis does now |
|----------------------------------|----------------------|
| The decision | A status change. Setting "approved" (180) needs only `$g_update_dwg_status_threshold` (developer), unless `$g_set_status_threshold` names that status. `$g_dwg_status_enum_workflow` is empty, so any status can follow any other. There is no rationale, no outcome other than the status, and no record that cannot be edited |
| Identifying the content approved | A separate **Promote Draft** action, hard-coded to manager level. It does not look at the status, so a document can be promoted while "draft" or approved without being promoted |
| Publishing | A separate **Publish** action. Approval does not trigger it |
| Who approved, for the published control block | Read back from the history: the entry that set a status in `$g_dwg_publish_approved_statuses` (180 and 190) |
| Notifying | The generic status-change email, the same as for any other status |
| Change requests, conditions, baselines, release manifests | None |

The one existing exception shows the intended shape. **Approving a meeting's
minutes** (`meeting_minutes_approve()`) promotes the Draft, sets the status
(`$g_meeting_document_status['approved']`) and the release date, and turns
the agreed actions into issues: one decision, a chain of consequences.

Three consequences of the gap matter now:

1. **No audit answer to "show me where this was approved".** Status history
   says the status changed; it does not say what content was approved, on what
   basis, or with what conditions. And a status can be edited back.
2. **"Ever approved" cannot be defined.** Deletion rules (GIT_TODO §8.1) need
   it. Status is editable; promotion and pins happen without approval.
3. **Nothing stops an inconsistent record.** An "approved" document whose On
   Record version is not what was reviewed, or an On Record version nobody
   approved.

---

## 2. Proposal in one paragraph

Make the **decision** a first-class, immutable Doctis record, and make
everything else follow from it. An authorised person records a decision
about an identified subject: a document at a commit, later a change request
or a baseline. The decision carries an outcome, a rationale, any conditions,
its authority and its forum. Recording a positive decision runs a
**configurable sequence of effects**: promote the approved commit, set the
status and effective date, publish, notify, and open issues for the
conditions. Statuses stay what they are: the visible lifecycle state. But
entering an approval status happens **only** through a decision. The same
mechanism serves three scales (§4), built in that order.

---

## 3. Concepts

| Term | Meaning |
|------|---------|
| **Decision** | An immutable record: *who* decided *what* about *which identified content*, with what *outcome*, on what *authority*, in what *forum*, with what *rationale* and *conditions*. Never edited; a later decision supersedes it |
| **Subject** | What the decision is about: a document at a commit (§4.1), a change request (§4.2), or a baseline, meaning a set of document revisions and source releases (§4.3) |
| **Outcome** | POL-SYS-004 §10.2: Approved; Approved with conditions; Deferred; Rejected; Returned for further investigation |
| **Approval status** | A document status whose *entry* is a decision event (§5). Default: 180 *approved* |
| **Approved family** | Statuses that *mean* the document stands approved, for display and publishing. Default: 180, 190 (today's `$g_dwg_publish_approved_statuses`) |
| **Effect** | One consequence of a positive decision: promote, freeze, set status, set date, publish, notify, open condition issues, close a CR (§7) |
| **Condition** | A binding obligation attached to an approval (POL-SYS-004 §10.2), tracked to closure as an issue |
| **Forum** | Where the decision was made: a convened CCB meeting (a Doctis meeting record), asynchronously in Doctis, under delegation (Class II), or as an emergency |

---

## 4. Three scales of decision

The owner's examples span all three: authorising an initial release or update
of a publication, sending a product design out for production, and shipping
an engineering change to a customer. One decision mechanism, three kinds of
subject:

### 4.1 Document approval (PROC-SYS-001 §7)

- **Subject:** one registered document, at one identified commit.
- **Positive effects:** the commit becomes On Record and is pinned; status
  becomes the approval status; release (effective) date set; published.
- **Covers:** "authorising an initial release or update to a publication".
  Also any controlled document: procedure, PRD, ICD, test report.

### 4.2 Change authorisation (POL-SYS-004 §10, PLAN-SYS-003 §7)

- **Subject:** a **Change Request** (CR/ECR). Per PLAN-SYS-003 §11.3, that is a
  Doctis *issue* of a CR category, against the affected items. Issues can
  carry custom fields, which documents cannot (see
  [../api/REST-AND-SOAP-API.md](../api/REST-AND-SOAP-API.md)).
- **Outcomes:** all five. A positive outcome **authorises the change**; it does
  not approve any document. The resulting document revisions each come back
  through §4.1. POL-SYS-004 §3.3: "the CCB authorises the change; PROC-SYS-001
  controls the paperwork that implements it."
- **Positive effects:** CR status moves on; condition issues opened; affected
  document owners notified.
- **Covers:** "shipping an engineering change": the CR decision authorises it,
  and §4.3 releases it.

### 4.3 Baseline and release authorisation (PLAN-SYS-003 §6, §10)

- **Subject:** a **baseline**, meaning a named set of approved document
  revisions and source releases, each at its commit. That is PLAN-SYS-003
  §6.2's "tag plus a Doctis record that says what that tag means, who
  approved it, and what it was verified against".
- **Preconditions** (PLAN-SYS-003 §10.1): every member approved; every
  included CR's conditions discharged; verification evidence present; release
  notes present.
- **Positive effects:** the baseline record is frozen; members are frozen at
  their commits; the release manifest is produced; distribution is notified.
- **Covers:** "sending a product design out for production" (Product
  baseline) and "shipping to a customer" (Release baseline).
- **Builds on:** Cut Release, which already makes a frozen record of a source
  tree at a commit, and Topics snapshots (`{topic_snapshot}`), which already
  freeze a computed state as hashed JSON.

**Recommendation:** build §4.1 first, but design the decision record so that
§4.2 and §4.3 are new subject types, not a redesign.

---

## 5. Which status is "approved"

The enum has 180 *approved* (constant `ACCEPTED`) and 190 *incorporated*
(`INCORPORATED`). Publishing treats both as approved. The meeting module uses
180. The owner's words, "approved, accepted and/or incorporation approval",
leave the choice open and require it to be configurable either way.

Two settings, because two different questions are being asked:

| Setting | Question it answers | Proposed default |
|---------|--------------------|------------------|
| `$g_dwg_approval_statuses` | Entering which statuses **is** a decision (goes through §6, runs §7)? | `array( ACCEPTED )` |
| `$g_dwg_approved_statuses` (rename of `$g_dwg_publish_approved_statuses`) | Being in which statuses **means** "stands approved", for display, publishing and the control block? | `array( ACCEPTED, INCORPORATED )` |

Both can be set per project, like any configuration. If the organisation
treats *incorporated* as a second decision (for example, a CCB approves
incorporating an approved change into a baseline), it adds 190 to the first
list too. Each entry is then its own decision record.

**Entry only through a decision.** A status in `$g_dwg_approval_statuses`
cannot be chosen in the ordinary status list, Edit Document or the API. The
status control instead offers **Approve…**, which opens the decision form.
Leaving an approval status (for example, back to *draft* for a revision) stays
an ordinary status change. It does not undo the decision, which stays on
record, superseded only by the next one.

---

## 6. The decision record

### 6.1 What it holds

- the subject, and for every document in it, the **exact commit**;
- the outcome, rationale (required for every outcome but Approved, and
  recommended for that), and conditions;
- the decider, the time, and the **authority basis**: the threshold or grant
  that allowed it (§8);
- the forum, with a meeting reference when convened (§9), and an emergency
  flag with its justification (POL-SYS-004 §11);
- dissent entries (POL-SYS-004 §8.4), each attributed;
- an integrity hash over the record. Topic snapshots already do this
  (`data_hash`).

### 6.2 Rules

- **Immutable.** No update and no delete, through the UI or the API. A
  correction is a new decision that supersedes the old one, and says so.
- **Survives the document's other edits.** It is not stored in, or derived
  from, the editable history.
- **"Ever approved" = a positive decision exists for the document.** This
  answers the sub-question in GIT_TODO §8.1, and with the owner's tentative
  answer 1 there, such a document cannot be deleted. The decision, and the
  pins it made, therefore always have a live document to belong to.

### 6.3 Storage sketch (for discussion, not a schema)

`{dwg_decision}` (id, subject type, subject id, outcome, rationale, forum,
meeting id, emergency, authority basis, user, date, supersedes, data hash) and
`{dwg_decision_item}` (decision id, dwg id, git sha, pin ref). The item table
is what lets one decision cover a baseline's many members. CR decisions point
at an issue.

---

## 7. What a positive decision does

Effects run **in order, after the decision is recorded**. The decision is
the fact; effects are consequences. A failed effect (pandoc errors, the
repository is locked) never removes or alters the decision. It is shown on
the document as *pending* with the reason, and can be retried. Each effect is
idempotent, so retrying is safe.

| # | Effect | Exists today as | Configurable |
|---|--------|-----------------|--------------|
| 1 | Check preconditions (§7.1); refuse before recording if any fails | — | per project |
| 2 | Promote the decided commit to On Record and pin it | `file_dwg_primary_sync_head()` | always for §4.1 |
| 3 | Freeze the record (no further Drafts) | Freeze | per project / kind; default on for source releases |
| 4 | Set the approval status | status change | — |
| 5 | Set the release (effective) date | meetings do this | default: decision date |
| 6 | Supersede the previous approved revision in history and publishing | — | — |
| 7 | Publish | `dwg_publish()` | per project, if publishing is configured |
| 8 | Notify: owner, assignee, monitors, and a distribution list | status email | per project / category |
| 9 | Open one issue per condition, linked to the decision | meeting actions → issues | — |
| 10 | Advance and close a linked CR (§4.2) | — | — |

### 7.1 Candidate preconditions

Each is a separate, configurable gate, so the organisation can tighten them
as its process matures (POL-SYS-004 §7.4, proportionate review):

- the commit is identified, and is the one that was reviewed. That is
  PLAN-DOC-001 *Risk 2*, "review and approval reference different content";
- **independence:** the decider is not the document's author or last
  committer (POL-SYS-004 §7.5; PLAN-DOC-001 question 4). If the organisation
  allows exceptions for team size, the decision records the exception;
- no open issue against the document of a blocking severity;
- required licences held. Licences already gate publishing, but see
  [LICENSES.md](LICENSES.md) for why they are not yet a security control;
- for baselines: all members approved; all included CRs' conditions closed.

---

## 8. Who may decide

Today Doctis decides by **access level**. The owner decided on 2026-10-15 that
role-based "approval authority" is defunct, and that Doctis access levels
decide approval. The CCB Policy, however, binds decisions to **named
authority holders by domain** (GUID-ENG-001), with a quorum for convened
CCBs.

Proposal: a staged answer.

- **Stage 1 (§4.1).** A per-project, per-category threshold,
  `$g_dwg_approve_threshold` (default MANAGER, matching promotion today),
  plus the independence gate. The decision records the access level as its
  authority basis.
- **Stage 2 (§4.2, §4.3).** Authority grants: a user holds decision authority
  for a domain at a level. Decisions check them, and convened decisions check
  quorum. Whether this is a new entity or a reworked Doctis licence is for
  §11; licences are self-applied today and cannot carry authority as they
  stand.
- **Executive authority** (POL-SYS-004 §8): an executive decision can only
  record *Deferred* or *Rejected* against an engineering-approved subject,
  never turn a technical rejection into an approval. Doctis can enforce this
  mechanically once decisions carry a type: engineering or executive.

---

## 9. Forum: CCB meetings and asynchronous decisions

The meeting module already plans, minutes and approves meetings, and turns
actions into issues. Proposal:

- A meeting can be marked as a **CCB session** (Tier 1 or Tier 2,
  POL-SYS-004 §9). Its agenda lists the subjects: CRs, documents and
  baselines.
- Decisions taken in it are **recorded individually** (§6), each referencing
  the meeting. Approving the minutes then checks that every agenda subject has
  a decision or an explicit deferral.
- Asynchronous decisions (POL-SYS-004 §9.1: "for most changes convenes
  asynchronously in Doctis") need **multi-party sign-off**: a decision is
  *proposed*, the required authority holders concur or dissent, and it
  becomes final at quorum. This is PLAN-DOC-001 question 4. It belongs in
  stage 2; stage 1 is a single decider.

---

## 10. Candidate functional requirements

Proposed, for the owner to accept, amend or strike. IDs are stable once
accepted.

| ID | Requirement | Stage |
|----|-------------|:-----:|
| DEC-FR-001 | Doctis shall record each approval decision as an immutable record identifying the decider, time, outcome, rationale, authority basis, forum and the exact commit of each document decided | 1 |
| DEC-FR-002 | A document shall enter a configured approval status only by a recorded decision, through any interface (web, SOAP, REST) | 1 |
| DEC-FR-003 | The approval statuses, and the statuses that count as approved, shall be configurable per project | 1 |
| DEC-FR-004 | A positive document decision shall make the decided commit the On Record version and pin it | 1 |
| DEC-FR-005 | A positive decision shall run the configured effects in order; a failed effect shall not alter the decision, and shall be visible and retryable | 1 |
| DEC-FR-006 | The decider shall hold the configured approval threshold for the project and category | 1 |
| DEC-FR-007 | Doctis shall refuse a decision whose decider is the document's author or last committer, unless the project allows it, in which case the exception is recorded | 1 |
| DEC-FR-008 | A document with a positive decision on record shall not be deletable (GIT_TODO §8.1, answer 1) | 1 |
| DEC-FR-009 | The document page, the published control block and the API shall show each decision, latest first | 1 |
| DEC-FR-010 | Conditions attached to a decision shall each open a tracked issue linked to the decision | 1 |
| DEC-FR-011 | Doctis shall support Change Requests as an issue category with the fields of PLAN-SYS-003 §11.3, and decisions on them with the five outcomes of POL-SYS-004 §10.2 | 2 |
| DEC-FR-012 | Authority holders shall be able to record attributed dissent against a decision | 2 |
| DEC-FR-013 | A decision may be marked an emergency with justification, and shall then appear on a retrospective-review list until reviewed (POL-SYS-004 §11) | 2 |
| DEC-FR-014 | A decision may reference the CCB meeting it was taken in; approving that meeting's minutes shall check that every agenda subject has a decision or deferral | 2 |
| DEC-FR-015 | Asynchronous decisions shall support proposal, concurrence and quorum | 2 |
| DEC-FR-016 | Doctis shall record baselines as named, frozen sets of decided document revisions and source releases | 3 |
| DEC-FR-017 | A release decision shall check the release preconditions of PLAN-SYS-003 §10.1 and produce a release manifest | 3 |
| DEC-FR-018 | Doctis shall answer the status-accounting queries of PLAN-SYS-003 §8 from decision, CR and baseline records | 3 |

---

## 11. Questions to settle

Each has a recommendation; the owner's answer replaces it.

| # | Question | Recommendation |
|---|----------|----------------|
| Q1 | Which status or statuses are decisions by default: 180 only, or 180 and 190? | 180 only; 190 configurable as a second decision |
| Q2 | Should entering an approval status be *only* through the decision form (DEC-FR-002)? | Yes. Otherwise "approved" stays a field anyone with the status threshold can type |
| Q3 | Should **Promote Draft** remain as an action of its own for controlled documents? | No: On Record means approved (PROC-SYS-001 §7.2), so promotion becomes an effect of a decision. Keep it only where no approval applies, which needs Q4 |
| Q4 | Are there registered items that need no approval: developer documentation in a source repository, or meeting agendas? | Yes. A per-project or per-category setting, "approval required", default on |
| Q5 | Is Cut Release a decision (a release authorisation, §4.3) or stays a registration step? | A decision, at stage 3; until then, unchanged |
| Q6 | Default approval threshold, and does it vary by category? | MANAGER, per project and category |
| Q7 | Is independence (DEC-FR-007) enforced from stage 1, and against author, last committer, or both? | Both, with a recorded per-project exception for small teams |
| Q8 | Does a decision on an *incorporated* or *archived* document need its own outcome (withdrawal, supersession)? | Yes: *Withdrawn* as a sixth outcome for documents, recorded the same way |
| Q9 | Authority holders: a new entity, or reworked licences? | A new entity; licences are the wrong shape (self-applied, see LICENSES.md) |
| Q10 | Publishing on approval: automatic, or offered? | Automatic where publishing is configured, with the result shown on the decision |
| Q11 | What do existing documents become? Records "approved" today have no decision. | A one-time migration writes a decision per document from its history, marked *migrated*, so the rules apply uniformly |

---

## 12. Scenarios

Each can be reproduced on any installation once the stage is built.

**A. A procedure revision approved and published (stage 1).**
1. The owner of a procedure pushes a revision. Its page shows a Draft.
2. A reviewer (not the author) chooses **Approve…** and enters a rationale.
3. Doctis records the decision against the Draft's commit, promotes and pins
   it, sets *approved* and today's effective date, publishes the page, and
   emails the distribution list.
4. A later attempt to set the status back and delete the document is
   refused: a decision is on record.

**B. A conditional approval (stage 1).**
1. An approver approves a test procedure "with conditions: re-run test 4.2 on
   the Rev B board".
2. The decision is recorded and its effects run. One issue is opened for the
   condition and linked to the decision.
3. The document stands approved. The condition shows as open until its issue
   is resolved, and a baseline including this document cannot be released
   while it is open (stage 3).

**C. An interface change rejected by the CCB (stage 2).**
1. A CR proposes changing a shared wire-protocol header. It is Class I.
2. In a CCB session, the domain authority rejects it on technical grounds; a
   member records dissent.
3. An executive attempts to record *Approved*. Doctis refuses, because an
   executive decision can only defer or reject (POL-SYS-004 §8.2).

**D. A firmware release shipped to a customer (stage 3).**
1. A baseline names the approved PRD, ICD and test report revisions, and a
   source release of the firmware at its commit.
2. The release decision checks every member is approved and every condition
   closed, then freezes the baseline, writes the release manifest and
   notifies the customer distribution list.

---

## 13. What this changes elsewhere

- **Promote Draft** becomes an effect of approval for documents that require
  it (Q3, Q4). The "status gate on promotion" in
  [../git/SOURCE_REPOSITORIES.md](../git/SOURCE_REPOSITORIES.md) §11 is closed
  by this, not by a separate gate.
- **Meeting minutes approval** records a decision rather than setting the
  status directly. It becomes the first consumer.
- **Publishing** reads approver and date from the decision record, not from
  the history; `$g_dwg_publish_approved_statuses` becomes
  `$g_dwg_approved_statuses`.
- **Deletion** (GIT_TODO §8.1) gets its definition of "ever approved".
- **The SOAP and REST status fields** must refuse an approval status
  (DEC-FR-002). Approval through the API becomes an explicit operation of its
  own.
- **PROC-SYS-001 §7.5** ("review integration — pending specification") can
  reference this document once accepted.
