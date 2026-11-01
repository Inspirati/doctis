# Doctis Teams integration and transcript-assisted minutes

**Status:** Draft feature proposal; no Microsoft integration has been implemented.
**Prepared:** 1 November 2026.
**Audience:** Company DevOps / Doctis server administrator, Microsoft 365 IT
administrators, and the Doctis development team.
**Purpose:** Obtain IT's requirements, permissions and pilot environment for
an integrated meeting workflow, then implement and validate it in Doctis.

## Why this feature matters

Professional engineering product design and development requires a reliable
record of the decisions made throughout the development cycle: what was
decided, why, by whom, and what work must follow. That record supports design
reviews, verification, change control and the transfer of knowledge between
teams. Its value continues into production and through-life support, when
engineers need to understand the reasoning behind a product's design long
after the original discussion and its participants have moved on.

Company operations and continual improvement require the same discipline.
Formal meetings must turn discussion into recorded decisions, action items,
identified responsible people, and delivery dates or measurable targets.
The QMS Management Meeting already stipulated by our QMS is one concrete
example: its decisions and follow-up actions must become part of how the
organisation operates.

Meeting records, together with evidence that agreed actions are followed
through, help demonstrate that the QMS is operating within the organisation.
This evidence supports ISO 9001 certification. ISO's guidance on documented
information identifies records of management review results among the
required evidence, alongside records supporting design and development.
Agendas, approved minutes and tracked actions provide a practical way to
capture and maintain that evidence. [ISO guidance][iso-records-guidance].

The practical obstacle is the work required to sustain this discipline.
Someone must prepare the agenda, send invitations, take notes, draft and
distribute minutes, identify action owners and deadlines, and follow up.
When this depends entirely on individuals finding spare time around their
engineering and operational duties, it too often does not happen. Meetings
are deferred, decisions remain in people's memories, minutes are never
completed, and actions lose their owners or momentum. The management system
fails to deliver its intended results, and company performance suffers.

> “A meeting without minutes didn’t happen.”
>
> — As used by Norman Walsh in [“On meetings” (January 2019)][meeting-quote].

The saying captures the organisational problem: without an accessible record,
people who were absent—or who join the work later—cannot reliably establish
what was agreed or what should happen next.

Doctis is already the home of the organisation's official records. The
AI Meeting Assistant is intended to make structured meetings, reliable
records and accountable follow-up a routine, achievable part of staff work.
Connecting agendas, Teams meetings, transcripts, draft minutes and tracked
actions reduces the administrative effort that currently prevents that
workflow from being completed consistently.

We are a forward-looking, R&D-centred company with divisions already
developing AI solutions for the marketplace. Applying AI to one of our most
fundamental operating practices brings that same capability into our own
organisation. The purpose is to preserve engineering knowledge, improve
execution and make our QMS an effective part of everyday corporate operations.

## 1. Requested outcome

Extend the Doctis AI Meeting Assistant so a meeting can progress through:

1. Plan and confirm the agenda in Doctis.
2. Automatically create an Outlook calendar event with a Teams joining link,
   send invitations, and synchronise subsequent changes or cancellation.
3. Conduct the meeting in Teams with transcription enabled and started.
4. Retrieve the resulting transcript into the correct Doctis meeting record.
5. Generate an initial draft of the minutes, decisions and proposed actions,
   using the agenda and transcript as evidence.
6. Have an authorised person review/correct the draft and the chair approve it.
7. Publish the approved minutes and create the associated Doctis action issues
   through the existing workflow; schedule the next occurrence where applicable.

The target is automation from agenda through **draft** minutes. Human review
and chair approval remain required before minutes become the controlled record
or proposed actions become assigned issues. Transcription and AI can mishear,
omit or misattribute statements; automatic publication is outside this proposal.

**Request to DevOps:** take this proposal to IT, obtain the decisions and pilot
resources in sections 4 and 11, and return the agreed configuration and access
boundaries to the Doctis developer. This document does not authorise changes
to the company tenant or production server.

## 2. Existing Doctis capability and proposed additions

Source inspection confirms that Doctis already has meeting records, invitees,
agendas, changes/cancellation, recurring scheduling, draft minutes, chair
approval, and action-to-issue creation. It sends `invite.ics` / `cancel.ics`
attachments and provides a calendar download. Named meeting timezones preserve
local recurrence times across daylight-saving changes.

The current location text “Microsoft Teams” does not create a Teams meeting.
There is no Microsoft Graph authentication, Microsoft event mapping,
transcript retrieval or transcript-driven draft generation in the current
implementation. Current AI conversation history is an audit of assistant
requests, not an audio or Teams meeting transcript.

| Area | Addition required |
|---|---|
| Microsoft access | Tenant-specific authentication, scoped permissions and credential lifecycle |
| Calendar / Teams | Create and maintain a calendar-backed Teams event and store its identifiers |
| Invitations | Coordinate Microsoft invitations with Doctis agenda emails and calendar downloads |
| Transcripts | Discover, download, associate, protect and retain transcript artefacts |
| AI minutes | Parse transcripts, generate evidenced draft minutes/actions, and support review |
| Operations | Background jobs, retries, monitoring, migration, backup and support procedures |

## 3. Recommended architecture

Use Microsoft Graph **v1.0 calendar events with Teams enabled**, rather than
creating a standalone `onlineMeeting`. The event creation request sets
`isOnlineMeeting: true` and `onlineMeetingProvider: teamsForBusiness`.
Microsoft's transcript APIs require calendar-associated meetings; a standalone
online meeting is unsuitable for this objective. Sources: [create event][1],
[transcript support restrictions][2].

Doctis remains the authority for agenda content, schedule and the controlled
minutes. Microsoft owns the calendar invitation and Teams session. A stored
mapping links the two. The first release should support scheduled, ordinary
non-channel meetings organised in the company tenant. Externally organised
meetings, channel meetings, ad hoc calls and historical imports need separate
evaluation and are outside the initial pilot.

Use **application authentication** for background scheduling and transcript
retrieval, without requiring a user to remain logged in to Microsoft. Start
with a designated, appropriately licensed organiser account restricted to a
pilot group. Its Teams organiser identity will differ from the Doctis chair
unless they are the same person; make that distinction visible. If IT requires
each chair to own the Microsoft meeting, map every approved chair to their
Microsoft user object ID and provision access accordingly. A delegated
sign-in alternative is possible, but adds user consent, token renewal and
disconnection handling; it is not the proposed starting point.

For recurring meetings, initially create one Microsoft event per occurrence
generated by Doctis. Retain the existing Doctis series scheduler and named
timezone rules. Do not also create a Microsoft recurring series: that would
introduce a second scheduler and complicate transcript-to-occurrence matching.

## 4. Requirements for the company IT department

### 4.1 Tenant, organiser and pilot accounts

IT must confirm:

- The Microsoft 365 tenant ID and cloud environment. The proposed endpoints
  assume the commercial/global cloud; other clouds require compatibility checks.
- The organiser account(s), immutable Entra user object IDs, mailbox addresses,
  Exchange Online calendars, Teams availability, and supported online-meeting
  provider. A shared mailbox must not be assumed to be an eligible organiser.
- Licences and assigned policies that permit scheduling Teams meetings and
  producing transcripts, including the chosen automatic transcription option.
- Two or more pilot attendees and one excluded account for access-boundary tests.
- Whether external attendees, lobby settings, presenters, language and sensitivity
  labels require particular defaults. Doctis chair status alone does not grant
  Teams organiser, co-organiser or transcription rights.

IT should validate the actual tenant capabilities rather than procure a
particular SKU based on this proposal. Microsoft currently states that the
Teams APIs are no longer metered from 25 August 2025; this does not remove
Microsoft 365 licensing, storage or Doctis AI-provider costs. [API billing][9].

### 4.2 Application identity and authentication

Register a single-tenant Entra application for Doctis. Prefer separate pilot
and production registrations and credentials so testing cannot affect real
meetings. Assign owners and document renewal/revocation responsibilities.

Use OAuth 2.0 client credentials with a certificate, or another IT-approved
workload credential. If a client secret is used for the pilot, specify its
expiry and rotation plan. Authentication uses the tenant token endpoint and
the `https://graph.microsoft.com/.default` scope; the application requests
pre-authorised access, not an interactive user password. [Authentication][8].

DevOps supplies the credential to the application runtime through existing
protected environment/secret mechanisms. Private keys, secrets and tokens
must not be committed, placed in the web root, included in AI prompts or
written to logs. Both the PHP web process and background worker need access
to the relevant runtime configuration. `config_inc.php` should reference
environment variables, consistent with the live server's existing practice.

### 4.3 Permissions and scope

The following are the proposed **capabilities**, not a request for unrestricted
access to every employee's meetings. IT must configure and demonstrate the
effective organiser/mailbox boundaries before production.

| Permission / role | Purpose | Initial requirement |
|---|---|---|
| `Calendars.ReadWrite` capability | Create, read, update and cancel organiser calendar events | Required; restrict to approved organiser mailboxes |
| `OnlineMeetings.Read.All` application permission | Resolve the Teams online-meeting ID from its joining URL | Required for the proposed lookup flow |
| `OnlineMeetingTranscript.Read.All` application permission | List and retrieve meeting transcripts | Required for automatic transcript retrieval |
| `OnlineMeetings.ReadWrite.All` application permission | Change Teams-specific meeting options if the agreed automatic-transcription route requires it | Conditional; replaces read-only meeting permission where appropriate |

For calendar access, IT should use scoped **Exchange Online Application RBAC**
where applicable, with the `Application Calendars.ReadWrite` role. Exchange
RBAC grants and Entra grants are independent/additive: leaving an unrestricted
Entra calendar permission can defeat the intended RBAC restriction. IT must
choose a coherent grant model and prove that an excluded mailbox is denied.
This is separate from the Teams access policy below. [Exchange scoping][6].

For online-meeting lookup and transcript application permissions, grant the
necessary Entra admin consent and configure a **Teams/cloud-communications
application access policy** for the approved organiser users. Consent alone
is insufficient for these calls. Do not grant a tenant-wide policy merely
for convenience. [Meeting lookup permissions][3], [Teams access policy][5].

The transcript permission has a resource-specific-consent alternative,
`OnlineMeetingTranscript.Read.Chat`, involving a Teams app installed in the
relevant meeting. That is a different deployment model and is not requested
for this server-side pilot. [Transcript permissions][2].

No general mailbox reading, Graph `Mail.Send`, broad directory reading,
recording download, chat export, bot/call-media access or attendance-report
permission is requested. Organiser IDs can be supplied by IT without a
directory-wide lookup permission. Any later addition requires its own reason.

### 4.4 Transcription and information handling

IT must enable transcription for the organiser and the person starting it,
allow Graph access to transcripts, and confirm whether speaker-attributed
transcripts may be retrieved. These are separate tenant/policy controls.
Teams can allow transcription but still prevent the application from fetching
the result. [Transcription policies][10], [transcript access controls][4].

**A transcript is not generated merely because a Teams link exists.** The
pilot must establish how transcription starts. Initially an authorised
participant may start it manually. For unattended operation IT must validate
a supported automatic meeting option/template and its licensing/policy
requirements. Enabling `allowTranscription` permits transcription; it does
not mean “start transcription now”. Do not assume `recordAutomatically`
provides a supported transcript-only automation path. If only a recording
and transcription combination is available, agree that explicitly before
enabling it. [Meeting options][11], [automatic recording policy][12].

Agree the participant notice/consent process, permitted meeting categories,
spoken languages, retention periods, deletion/legal-hold requirements and
whether transcript content may be sent to the configured Doctis AI provider.
The latter is a separate processing decision from storing data in Microsoft
365. Confirm approved provider, region, contractual retention/training terms
and redaction requirements with the company's information owner. No claim
is made here that current provider settings already meet those requirements.

## 5. Doctis development work

The main integration points in the current source are:

| Existing component | Expected change |
|---|---|
| `ai_assist_meeting_api.php`, `core/meeting_api.php` | Queue Microsoft operations after confirmed meeting changes; connect transcript drafts to the existing controlled minutes path |
| `core/meeting_calendar_api.php`, `meeting_ics.php`, meeting email sender | Coordinate calendar identity and avoid duplicate invitations |
| `meeting_view_page.php` and meeting edit pages | Joining link, integration/transcript/draft state, authorised retry and review controls |
| `scripts/meeting_schedule.php` | Ensure each scheduled occurrence enters the same integration workflow |
| New Graph client and worker modules | Authentication, remote operations, retrieval and resumable background processing |
| `admin/schema.php`, schema-update scripts, `config_defaults_inc.php` | Additive migration and documented configuration defaults |
| Existing AI provider/history modules and PHPUnit suites | Bounded generation, protected evidence/retention, regression and failure tests |

### 5.1 Graph client and configuration

Implement a small, shared Graph client for authentication, bounded token
caching, HTTP requests, pagination, throttling and sanitised errors. Use
supported PHP 8.2+ and existing HTTP facilities where suitable; evaluate a
maintained SDK only if it reduces implementation and maintenance work.

Add documented configuration for enablement, tenant/cloud, client ID,
credential reference, approved organisers, default organiser, allowed meeting
types, transcript retrieval interval/window, storage/retention limits,
AI drafting enablement and processing limits. These are proposed settings,
not configuration keys available in today's release. Default integration
and automatic AI processing to disabled until configured.

Provide an administrator diagnostic that checks token acquisition, the
configured organiser calendar and effective permissions without creating a
meeting or exposing credentials. An explicitly requested pilot operation
should then create a test meeting and verify the complete route.

### 5.2 Database mapping and reliable operations

Add versioned, non-destructive schema migrations; no database rebuild is
needed or proposed. Final table/column names belong to implementation design.
Store at least:

- Doctis meeting ID, tenant and organiser object ID, calendar/event ID,
  Microsoft calendar UID, Teams online-meeting ID and joining URL. Calendar
  event IDs, online-meeting IDs and calendar UIDs are distinct identifiers.
- Desired schedule/version, remote version/change marker, sync state,
  operation/idempotency identifier, attempts, retry time and last safe error.
- Transcript IDs, source meeting identity, timestamps, format, content hash,
  protected storage reference and ingestion state.
- Draft-generation job ID, transcript versions used, model/prompt version,
  processing status and resulting draft revision reference.

Use a durable job/outbox design: commit local state and pending work together,
then perform remote calls outside the user request/database transaction.
Handle a remote success followed by a local failure without duplicating the
meeting. Use Graph event `transactionId` where applicable plus local
reconciliation and uniqueness constraints. Retry transient failures with
bounded backoff, `Retry-After` support and a visible exhausted-retry state.

Do not mark an invitation sent or a Teams meeting ready until Microsoft has
confirmed it. An unsuccessful creation must show “Teams setup pending/failed”
and offer a retry; never invent a joining link. Bound parallel jobs and use
leases/locks so scheduler or worker restarts cannot duplicate side effects.

### 5.3 Event lifecycle and invitations

On agenda confirmation, queue creation of the Microsoft event with its
subject, start/end, approved timezone representation, attendees, agenda
summary/Doctis link and Teams enabled. Retain Doctis's named timezone and UTC
instant; convert to a timezone Microsoft accepts and test both DST transitions.

Microsoft sends invitations when an event contains attendees. Therefore,
for integrated meetings, let Microsoft own calendar requests, updates and
cancellations. Send the Doctis agenda as a separate content email **without
a second competing `.ics` invitation**. Preserve current `.ics` behaviour
for meetings without integration. Calendar downloads for integrated meetings
must use the Microsoft calendar identity or direct users to the existing
event; do not retain a conflicting Doctis UID. [Event behaviour][1].

Rescheduling and attendee changes update the mapped event. Cancellation uses
Microsoft's event cancellation operation and preserves local audit evidence.
Preserve Teams joining information when updating the event body. Treat the
Teams link as meeting data with the same visibility restrictions as the
meeting page. Provide a clear **Join Teams** button once synchronised.

For the first release, users should edit schedules/attendees in Doctis.
Detect/report conflicting Microsoft-side edits or deletion during reconciliation;
do not silently overwrite them. Bidirectional calendar editing and RSVP
synchronisation are possible later work, not implied by this proposal.

### 5.4 Transcript retrieval

After a meeting, a background job resolves its online-meeting ID, lists
transcripts and downloads each unseen transcript. Proposed API sequence:

```text
POST  /users/{organiser-id}/events
GET   /users/{organiser-id}/onlineMeetings?$filter=JoinWebUrl eq '{join-url}'
GET   /users/{organiser-id}/onlineMeetings/{online-meeting-id}/transcripts
GET   /users/{organiser-id}/onlineMeetings/{online-meeting-id}/transcripts/{transcript-id}/content
```

Requests require correct URL/OData escaping and pagination. Preserve the
identifiers returned by Microsoft; do not derive them by decoding a Teams URL.
Validate downloaded content and enforce size/time limits. [Lookup][3],
[listing][2], [content retrieval][4].

Start with bounded polling through a dedicated CLI worker using the existing
deployment's scheduled-job mechanism. Poll after the expected meeting end,
allow delayed publication and overruns, and periodically discover additional
segments until the agreed retrieval window closes. A missing transcript is
not necessarily a failed request: show “awaiting transcript”, “not available”
or a permissions/policy error as appropriate. Support manual retry/import.
Retrieve promptly before meeting expiry or retention removes the source.

Accept multiple transcript artefacts if transcription is stopped/restarted;
deduplicate by source ID and hash, retain source ordering and avoid duplicate
drafts. Initially create one Teams event/link per Doctis occurrence, so a
transcript cannot be casually attached to another week of a series.

WebVTT provides timestamped speech and, where allowed, speaker names. If tenant
policy disallows speaker attribution, support Microsoft's unattributed text
format and flag owner/attendance ambiguity for review rather than guessing.
Transcript retrieval downloads text; it does not require Doctis to receive
live audio or join the meeting as a recording bot. [Content formats][4].

Webhook notifications are an optional later optimisation. They require a
public HTTPS callback, validation and `clientState` checks, fast acknowledgement,
queued processing, subscription renewal/lifecycle handling and reconciliation.
Subscriptions must be established in time to receive transcript notifications;
polling remains a recovery path. Prefer scoped notifications over tenant-wide
collection. The initial polling design needs no new inbound callback route.
[Transcript notifications][7].

### 5.5 Transcript processing and initial draft minutes

Parse timestamps, speakers, language and transcript segments into a normalised
representation, retaining source references. Combine it with the confirmed
agenda, authorised participant roster, previous minutes and open actions as
appropriate. Split long input into bounded sections, preserve evidence links,
and set per-meeting token/cost limits; never silently truncate a meeting.

Use the existing AI provider abstraction and controlled document-writing path.
Treat transcript text as untrusted source material, not instructions to call
tools, change permissions, invite people or approve documents. Validate any
structured output before writing it. Require the model to distinguish recorded
decisions from proposals, flag uncertainties, and avoid inventing attendees,
owners, deadlines, votes or resolutions. Include transcript timestamp/segment
references for important decisions and proposed actions.

Generate an **initial draft for review**, preserving the agenda and the
existing chair approval route. Unknown speakers and ambiguous action owners
must remain unresolved until a human maps them to a Doctis account. A name
appearing in speech is not sufficient proof of identity or attendance.

Automatic drafting must not impersonate the chair or bypass existing write
permissions. Introduce an explicit, auditable background-processing authority
scoped to configured meetings. Retain who enabled the process, source hashes,
model/prompt version, processing diagnostics and the generated revision.

The existing minutes writer circulates draft minutes immediately. Introduce
a separate pre-circulation review state for machine-generated drafts: notify
the chair/minute taker when ready, let them correct and explicitly circulate
it, and continue with chair approval and action-issue creation. Reprocessing
must not overwrite human edits, generate duplicate emails/issues or modify
already approved minutes; it should produce an explicitly requested revision.

### 5.6 Storage, access and retention

Store transcripts in protected application storage outside the public web
root, with authorised download endpoints. Keep content out of general logs
and public/static QMS publishing. Apply meeting-specific access rules plus
any additional transcript restrictions agreed with IT; a participant's right
to see a meeting must not automatically imply a right to export its transcript.
Test direct download URLs and cross-meeting access boundaries.

Choose explicit retention/deletion periods for raw transcripts, normalised
text, generated drafts and AI evidence. Current assistant diagnostics retain
prompts and raw responses with **no automatic purge**; reusing them unchanged
could retain transcript copies indefinitely. Extend retention and access
controls to those copies, exports, temporary files and backups. See
[current AI history behaviour](ai-session-history.md). Ordinary Git history
is unsuitable for short-lived raw transcripts because deleting a working-tree
file does not erase historical copies. Approved minutes can continue through
the existing controlled-document/Git workflow.

## 6. Deployment and operating requirements for DevOps

| Requirement | Proposed provision |
|---|---|
| Runtime | Existing Doctis PHP 8.2+ application and database; validate required HTTP/TLS/credential-signing extensions in the actual image |
| Network | Outbound HTTPS/DNS to tenant identity and Microsoft Graph endpoints for the approved cloud, plus the existing AI provider; confirm proxy/CA requirements |
| Credentials | Protected runtime secret/certificate injection available to web and worker, with expiry alerts and rotation procedure |
| Processing | Dedicated scheduled CLI worker, concurrency control, timeouts and resource limits; extend the existing cron environment mechanism |
| Data | Persistent protected transcript storage, database job/mapping tables, agreed capacity/retention and coordinated backups |
| Monitoring | Failed sync/retrieval/drafting jobs, job backlog, credentials, policy errors, retrieval delays, throttling and AI spend |
| Deployment | Reviewed release, additive migration, staged enablement per organiser, pilot validation and rollback/runbook |

For the live Docker deployment, update the application image and documented
runtime configuration through the company release process. Do not rely on
changes inside a running container persisting after replacement. Validate
worker environment and storage permissions in the real deployment.

The polling design requires no new global nginx/PHP/Linux settings or inbound
firewall opening. It does require an application job schedule, environment
configuration and persistent storage. Review those specific deployment changes;
do not rerun the system installer or rebuild the database.

Rollback must distinguish disabling new meeting creation from stopping all
processing. If synchronisation is disabled, existing Microsoft meetings still
exist and may still send invitations; identify them for reconciliation/manual
management. Preserve mappings, transcripts and local meeting records. Do not
automatically delete remote events or revoke credentials as a rollback side
effect. IT retains an independent emergency revocation option.

## 7. Ownership and support responsibilities

| Owner | Responsibility |
|---|---|
| Company IT / Microsoft 365 administrator | Tenant application, consent, scoped calendar and Teams access, licences, transcription/consent policies and credential revocation |
| DevOps / Doctis server administrator | Coordinate IT handoff; runtime secrets, image deployment, jobs/storage, monitoring, backups and recovery |
| Doctis developer | Graph integration, migrations, reliable processing, UI, transcript parser, AI draft workflow, permissions, diagnostics, tests and documentation |
| Product owner / information owner | Organiser model, meeting scope, consent/retention/AI-processing decisions, review policy and pilot acceptance |
| Chair / minute taker | Ensure transcription starts where manual, review evidence and draft, resolve identities/actions and approve via existing roles |

Failures should be traceable through Doctis meeting/job IDs and Microsoft's
request IDs, without requiring support staff to inspect full transcript text.
Document who handles credential expiry, tenant `403` errors, missing transcripts,
calendar conflicts and AI-provider failures.

## 8. Delivery stages

| Stage | Deliverable and exit condition |
|---|---|
| 1. IT feasibility pilot | Approved organiser and app; scoped access demonstrated; calendar-backed Teams event produces a transcript retrievable by the server |
| 2. Calendar integration | Confirm/change/cancel synchronise reliably; joining link shown; invitations have one calendar identity; manual minutes still work |
| 3. Transcript ingestion | Worker retrieves and protects the right transcript segments with status, retry, manual fallback and retention controls |
| 4. AI initial drafts | Evidence-based minutes/actions generated into review state; human edits protected; circulation/approval/issues remain controlled |
| 5. Unattended workflow | Proven automatic transcription path, bounded automatic draft generation, operational alerts and recovery tested before wider rollout |

Stage 1 should settle the transcript and transcription questions **before**
substantial workflow development. Calendar integration alone does not satisfy
the complete requirement. Estimates and a delivery schedule should follow
IT's answers and a successful tenant proof of concept.

## 9. Acceptance tests

Use synthetic pilot meetings; avoid customer discussions until access and
processing arrangements are accepted. Test the actual company tenant and
deployment as well as mocked/unit tests in development.

1. The app can access approved organiser mailboxes/meetings and is denied an
   excluded mailbox and organiser. Verify calendar and transcript boundaries
   separately, plus transcript access from an unauthorised Doctis user.
2. Confirming one agenda produces exactly one Outlook event, functioning Teams
   link and invitation. A timeout/retry or worker restart creates no duplicate.
   Doctis agenda email has no conflicting calendar attachment.
3. Reschedule across DST, change attendees and cancel: Outlook reflects the
   intended change and participants receive the appropriate notification.
   Recurring Doctis occurrences stay separate and use correct local times.
4. A real short meeting yields a retrievable transcript associated with the
   correct Doctis record. Exercise manually started and proposed automatically
   started transcription separately; record which path is proven.
5. Handle delayed/missing transcripts, multiple segments, no speaker attribution,
   revoked consent, policy denial, expiry, `429`/`5xx` responses and job retries
   with accurate status and no fabricated or duplicated output.
6. Draft minutes accurately separate decisions, discussion and proposed actions.
   Unknown speakers/owners/deadlines are flagged; important items cite evidence.
   Malicious instructions spoken in the transcript cannot change system actions.
7. Machine drafts are not automatically circulated or approved. Human edits
   survive retries/regeneration; chair approval creates action issues once.
8. Confirm prompt/diagnostic copies, exports, storage and backups follow the
   agreed access/retention policy; logs and errors reveal no credentials.
9. Prove credential rotation, worker restart, failed deployment recovery,
   Microsoft-side edit conflict and integration disablement without data loss.

## 10. Cost and scope boundaries

Budget for development/testing, IT setup, organiser licensing, storage/backups,
operations and additional AI input/output tokens. Transcript lengths may be
much greater than current assistant conversations; introduce usage reporting
and configurable ceilings before unattended drafting.

This proposal does not require a Teams client plugin, live media bot, audio
recording ingestion, Copilot-generated minutes, chat surveillance or complete
two-way Outlook synchronisation. A raw transcript upload can serve as an
explicit fallback, with the same access/AI-processing rules. It does not prove
that Microsoft-side automatic capture works.

## 11. Information and decisions requested from IT

Please return the following through the normal company support/change process.
Credentials/private keys must use the approved secret-transfer mechanism,
not this document or an ordinary email attachment.

| Item | Response needed |
|---|---|
| IT contact / tenant | Microsoft 365 administrator, tenant ID and cloud environment |
| Organiser model | Designated scheduling account or individual chairs; approved object IDs/mailboxes and licences |
| Application | Pilot/production app IDs, owners, credential method, rotation and revocation contacts |
| Effective access | Agreed calendar RBAC/grant model and Teams application access policy; evidence excluded accounts are denied |
| Transcription | Allowed policies/languages; Graph access and speaker-attribution settings; manual and proposed automatic start method |
| Information handling | Participant notice/consent, meeting exclusions, transcript/draft/audit retention, AI provider/region approval |
| Network / hosting | Outbound endpoint/proxy requirements, secret injection, worker scheduling and protected storage location |
| Pilot | Test organiser/attendees, synthetic meeting slot, administrator support and acceptance owner |
| Automation decision | Whether transcript-only unattended capture is supported, or recording is required and approved |

## 12. References

Microsoft documentation checked on 1 November 2026. Tenant availability,
licensing and policies must be confirmed during the pilot; these links are
technical references rather than evidence of the company's current settings.

1. [Create calendar event, including Teams-enabled events][1].
2. [List transcripts: supported meetings, permissions and access policy][2].
3. [Get online meeting, including lookup by joining URL][3].
4. [Get transcript metadata/content, formats and tenant controls][4].
5. [Cloud-communications application access policy][5].
6. [Exchange Online RBAC for Applications][6].
7. [Transcript and recording change notifications][7].
8. [Microsoft Graph authentication without a signed-in user][8].
9. [Current Graph metered API position][9].
10. [Teams transcription and captions policies][10].
11. [Online meeting resource and options][11].
12. [Teams automatic recording policy][12].

Background sources for the preamble:

- [ISO guidance on the requirements for documented information in ISO 9001:2015][iso-records-guidance].
- [Norman Walsh, “On meetings” (January 2019)][meeting-quote] — source for the
  quoted wording, without a claim of original authorship.

[1]: https://learn.microsoft.com/en-us/graph/api/user-post-events?view=graph-rest-1.0
[2]: https://learn.microsoft.com/en-us/graph/api/onlinemeeting-list-transcripts?view=graph-rest-1.0
[3]: https://learn.microsoft.com/en-us/graph/api/onlinemeeting-get?view=graph-rest-1.0
[4]: https://learn.microsoft.com/en-us/graph/api/calltranscript-get?view=graph-rest-1.0
[5]: https://learn.microsoft.com/en-us/graph/cloud-communication-online-meeting-application-access-policy
[6]: https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac
[7]: https://learn.microsoft.com/en-us/graph/teams-changenotifications-callrecording-and-calltranscript
[8]: https://learn.microsoft.com/en-us/graph/auth-v2-service
[9]: https://learn.microsoft.com/en-us/graph/metered-api-list
[10]: https://learn.microsoft.com/en-us/microsoftteams/meeting-transcription-captions
[11]: https://learn.microsoft.com/en-us/graph/api/resources/onlinemeeting?view=graph-rest-1.0
[12]: https://learn.microsoft.com/en-us/microsoftteams/manage-teams-auto-recording
[iso-records-guidance]: https://www.iso.org/files/live/sites/isoorg/files/standards/docs/en/iso_9001_2015_guidance_documented_information.pdf
[meeting-quote]: https://so.nwalsh.com/2019/01/24/meetings
