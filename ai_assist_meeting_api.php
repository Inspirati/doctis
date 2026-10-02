<?php
# Doctis — AI Assistant Meeting mode functions
#
# Contains all server-side logic specific to Meeting mode:
#   ai_assist_get_meeting_candidates()   — users who accept meeting invitations
#   ai_assist_meeting_focus()            — the meeting whose minutes are being written
#   ai_assist_meeting_system_prompt()    — build the meeting session system prompt
#   ai_assist_process_meeting_document() — act on a <<<MEETING_DOCUMENT>>> block
#
# Meetings, invitees, document storage and email are handled by
# core/meeting_api.php.
# Everything the model supplies in a document marker is validated here: the
# meeting reference is built server-side, and user IDs are accepted only from
# the candidate list (agenda) or the meeting's invitees (minutes).
#
# Required by ai_assist_api.php via require_once.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'meeting_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );


# ═══════════════════════════════════════════════════════════════════════════════
# Candidates and focus meeting
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Query the user table for potential meeting invitees (meeting_invite != 0).
 * Returns an array of rows keyed by user id: id, username, realname,
 * position_title, company, department, email, email_secondary, meeting_invite.
 *
 * @return array
 */
function ai_assist_get_meeting_candidates(): array {
	$t_result = db_query(
		'SELECT id, username, realname, position_title, company, department,' .
		'       email, email_secondary, meeting_invite' .
		' FROM {user}' .
		' WHERE meeting_invite != 0 AND enabled = 1' .
		' ORDER BY realname, username'
	);

	$t_candidates = [];
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_candidates[(int)$t_row['id']] = $t_row;
	}
	return $t_candidates;
}

/**
 * Resolve the meeting this conversation is minuting (the page's meeting_id
 * parameter). Only meetings the user may write minutes for are returned.
 *
 * @param int $p_user_id
 * @param int $p_meeting_id Requested meeting (0 = none).
 * @return array|null Meeting row.
 */
function ai_assist_meeting_focus( int $p_user_id, int $p_meeting_id ): ?array {
	$t_meeting = $p_meeting_id > 0 ? meeting_get( $p_meeting_id ) : null;
	if( $t_meeting === null || !meeting_user_can_write_minutes( $t_meeting, $p_user_id ) ) {
		return null;
	}
	return $t_meeting;
}


# ═══════════════════════════════════════════════════════════════════════════════
# System prompt
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build the server-side system prompt for Meeting mode.
 *
 * Design goal: the AI should draft a complete agenda in one turn from a
 * single natural-language opening message, then confirm and generate in
 * one or two more turns.  Total interaction: 2-4 exchanges.
 *
 * @param int        $p_user_id Current user ID.
 * @param array|null $p_focus   Meeting being minuted, if any.
 * @return string
 */
function ai_assist_meeting_system_prompt( int $p_user_id, ?array $p_focus = null ): string {
	$t_departments = config_get_global( 'ai_meeting_departments' );
	$t_candidates  = ai_assist_get_meeting_candidates();
	$t_stored      = meeting_project_id() > 0;

	# ── Today, in the user's timezone ────────────────────────────────────────
	$t_today = date( 'l j F Y, H:i' ) . ' (' . date_default_timezone_get() . ')';

	# ── Department list ──────────────────────────────────────────────────────
	$t_dept_lines = [];
	foreach( $t_departments as $t_code => $t_dept ) {
		$t_dept_lines[] = $t_code . ' — ' . $t_dept['name'];
	}
	$t_dept_text = implode( "\n", $t_dept_lines );

	# ── Candidate invitees table ──────────────────────────────────────────────
	if( empty( $t_candidates ) ) {
		$t_candidates_text = "(No users have opted in to meeting invitations yet.)";
	} else {
		$t_cand_lines = [];
		foreach( $t_candidates as $t_c ) {
			$t_scope = $t_c['meeting_invite'] == 1 ? 'dept' : 'all';
			$t_cand_lines[] = sprintf(
				'id=%-3s | %-14s | %-28s | %-28s | %-18s | %-18s | %s',
				$t_c['id'],
				$t_c['username'],
				is_blank( $t_c['realname'] ) ? '—' : $t_c['realname'],
				is_blank( $t_c['position_title'] ) ? '—' : $t_c['position_title'],
				is_blank( $t_c['department'] ) ? '—' : $t_c['department'],
				is_blank( $t_c['company'] ) ? '—' : $t_c['company'],
				$t_scope
			);
		}
		$t_candidates_text = implode( "\n", $t_cand_lines );
	}

	# ── Meetings awaiting minutes ─────────────────────────────────────────────
	$t_awaiting_lines = [];
	foreach( meeting_get_awaiting_minutes( $p_user_id ) as $t_m ) {
		$t_awaiting_lines[] = sprintf( 'meeting_id=%d | %s | %s | %s',
			$t_m['id'], $t_m['doc_ref'], date( 'Y-m-d H:i', (int)$t_m['date_start'] ), $t_m['title'] );
	}
	$t_awaiting_text = empty( $t_awaiting_lines ) ? '(none)' : implode( "\n", $t_awaiting_lines );

	# ── Focus meeting (minutes) ───────────────────────────────────────────────
	$t_focus_section = '';
	if( $p_focus !== null ) {
		$t_inv_lines = [];
		foreach( meeting_invitees_get( (int)$p_focus['id'] ) as $t_inv ) {
			$t_inv_lines[] = ( (int)$t_inv['user_id'] > 0 ? 'id=' . $t_inv['user_id'] . ' | ' : 'guest | ' ) . $t_inv['name'];
		}
		$t_record = meeting_record_content( $p_focus );
		$t_focus_section = "\n---\n\n## MEETING BEING MINUTED\n\n"
			. 'meeting_id=' . $p_focus['id'] . ' | ' . $p_focus['doc_ref'] . ' | ' . $p_focus['title'] . "\n"
			. 'Scheduled: ' . date( 'Y-m-d H:i', (int)$p_focus['date_start'] ) . ', ' . $p_focus['duration'] . " min\n"
			. 'Chair: ' . meeting_user_display_name( (int)$p_focus['chair_id'] )
			. ( (int)$p_focus['minute_taker_id'] > 0 ? ' | Minute taker: ' . meeting_user_display_name( (int)$p_focus['minute_taker_id'] ) : '' ) . "\n\n"
			. "Invitees:\n" . ( empty( $t_inv_lines ) ? '(none recorded)' : implode( "\n", $t_inv_lines ) ) . "\n\n"
			. ( is_blank( $t_record )
				? "(The agenda document is not available; work from the details above.)\n"
				: "Current record (the issued agenda):\n\n" . $t_record . "\n" )
			. "\nThe user has opened this conversation to write the minutes for this meeting.\n"
			. "Start by asking for attendance and apologies, then work through the agenda items.\n";
	}

	# ── Meeting template ──────────────────────────────────────────────────────
	# The template's preamble (instructions) precedes the marker; it holds the
	# YAML frontmatter that every completed record must start with.
	$t_template_raw  = meeting_template_get();
	$t_template_text = $t_template_raw;
	$t_frontmatter   = '';
	$t_cut = strpos( $t_template_raw, '<!-- markdownlint-disable MD025 -->' );
	if( $t_cut !== false ) {
		$t_template_text = trim( substr( $t_template_raw, $t_cut ) );
		if( preg_match( '/```yaml\s*\n(---\n[\s\S]*?\n---)\s*\n```/', substr( $t_template_raw, 0, $t_cut ), $t_m ) ) {
			$t_frontmatter = $t_m[1];
		}
	}
	$t_frontmatter_text = !is_blank( $t_frontmatter )
		? "Every record MUST begin with this YAML frontmatter, filled in for the meeting\n"
		  . "(remove the # comments). status is Agenda for an agenda and Draft Minutes for minutes;\n"
		  . "owner and approver are the chair; leave effective_date blank — Doctis sets it on approval.\n\n"
		  . $t_frontmatter . "\n\nThen the body:\n\n"
		: '';
	$t_template_section = !is_blank( $t_template_text )
		? "## MEETING TEMPLATE (TMPL-SYS-001)\n\n" . $t_frontmatter_text . $t_template_text
		: "## MEETING TEMPLATE\n\n" .
		  "(Template not available. Follow standard HC-Robotics format: YAML frontmatter, " .
		  "then sections: Invitees, Pre-Reading, Agenda, Attendees, Minutes, Decisions, " .
		  "Actions, Next Meeting, Distribution, Approval.)";

	# ── Storage instruction ───────────────────────────────────────────────────
	$t_storage = $t_stored
		? 'When you generate a document, Doctis records the meeting, stores the agenda as a controlled
document, emails it to the matched invitees, and stages minutes as a draft revision for approval.'
		: 'Doctis records the meeting and emails the agenda to matched invitees, but no meeting project
is configured on this server, so no controlled document is created.';

	# ── Current user name for chair field ────────────────────────────────────
	$t_chair_name = meeting_user_display_name( $p_user_id );

	# ── Assemble prompt ───────────────────────────────────────────────────────
	$t_prompt = <<<PROMPT
You are the HC-Robotics Meeting Assistant embedded in Doctis. Your job is to
produce QMS-compliant meeting agendas and minutes records with minimal friction.

Today is {$t_today}. Resolve relative dates ("next Tuesday", "tomorrow") from this.

{$t_storage}

---

## CORE PRINCIPLE: INFER, DON'T ASK

Extract everything possible from what the user provides.
Fill gaps using context and common knowledge.
Do not ask for information you can infer.
Reserve questions only for information that is entirely absent AND genuinely required.

---

## AGENDA SESSION FLOW

### Turn 1 — Extract, Infer, Draft (your first response)

From the user's opening message, extract:
- **Date and time** — stated directly; convert "20 June 2026 at 11am" → date: 2026-06-20, time: 11:00
- **Duration** — if stated ("less than one hour", "90 minutes"), use that; otherwise **default to 60 minutes**
- **Attendees** — all names mentioned; match each to the CANDIDATE INVITEES list below
- **Subject / title** — what the meeting is about; infer meeting type from keywords
- **Department** — infer from attendee departments or subject keywords; pick best match from DEPARTMENTS list
- **Location** — always **Microsoft Teams** unless the user explicitly states otherwise
- **Chair** — always the Doctis user running this session: **{$t_chair_name}**
  Use this exact value in the `chair:` YAML field. Never write "Current User".
- **Minute taker** — the **first person named** in the user's invitee list
  (the first name they mention after "with", "for", or similar phrasing).
  The rationale: you list the minute taker first because a meeting requires one.
  Never default to the current user. Never write "Current User".

Then produce a complete, time-allocated draft agenda:
- Standard opening items: apologies / quorum (2 min), approval of last minutes if not first meeting (3 min)
- Main items: inferred from the meeting subject, allocated proportionally to fill the available time
- Any other business (5 min)
- Close / next meeting (3 min)
- All items must sum to the stated or default duration

**Present the draft plan** to the user in a concise readable format (not the raw document — a clean summary they can review).

End with **one question only**: "Ready to generate and send? Or let me know what to change."

### Turn 2+ — Adjust or Generate

If the user confirms (any of: "yes", "send", "looks good", "go ahead", "that's fine", or similar):
→ Generate the <<<MEETING_DOCUMENT>>> block immediately. Do not ask again.

If the user requests changes:
→ Apply every change mentioned, show a brief updated summary.
→ Ask once more: "Anything else, or shall I generate and send?"

**Never ask more than one question per turn. Never prompt for confirmation of individual details.**

---

## MINUTES SESSION FLOW

Minutes are written for a meeting that already has an agenda. If a MEETING BEING
MINUTED section appears below, use that meeting. Otherwise, if the user asks for
minutes, pick the matching entry from MEETINGS AWAITING MINUTES (ask which one only
if it is ambiguous); if none matches, say so.

1. Ask who attended and who sent apologies (one question).
2. For each agenda item, capture discussion, decisions and actions (owner and due date)
   from what the user tells you. Accept rough notes and tidy them; do not interrogate.
3. Present a concise summary of the minutes and ask: "Ready to save the minutes? Or let me know what to change."
4. On confirmation, generate the minutes document: the full record following the template,
   with `status: Draft Minutes` in the frontmatter and the revision advanced by one letter.

---

## NAME MATCHING

Match names the user mentions to the CANDIDATE INVITEES list. Rules:
- First-name match: "Phil" matches any candidate whose realname starts with "Phil" or "Philip"
- Surname match: "Wright" matches realname containing "Wright"
- Username match: "sanjay" matches username "sanjay"
- Partial / fuzzy match is acceptable — accuracy is the user's responsibility to correct
- If a name has no match in the list, include them as a guest (plain-text name, no id)
- Matched ids drive email distribution; guests receive no email

---

## AGENDA ITEM TIME ALLOCATION

Use common knowledge to infer realistic items and times. Examples by meeting type:

**Progress review (60 min):**
3.1 Apologies / quorum — 2 min (Chair)
3.2 Approval of last minutes — 3 min (Chair)
3.3 Open action items review — 5 min (Minute Taker)
3.4 Development progress update — 20 min (Lead Dev / owner)
3.5 Issues and blockers — 10 min (All)
3.6 Upcoming milestones and priorities — 10 min (All)
3.7 Any other business — 5 min (Chair)
3.8 Next meeting and close — 5 min (Chair)
Total: 60 min

**Design review (60 min):**
3.1 Apologies / quorum — 2 min
3.2 Approval of last minutes — 3 min
3.3 Document walkthrough — 25 min
3.4 Issues raised — 15 min
3.5 Decisions and action items — 10 min
3.6 Any other business — 5 min
Total: 60 min

Adjust proportionally for other durations.

---

## DOCUMENT GENERATION

When generating a document, wrap it in these exact markers. Attribute values must not
contain double quotes.

For an agenda:
<<<MEETING_DOCUMENT type="agenda" doc_id="MIN-{dept}-{YYYYMMDD}" dept="{dept}" title="{title}" date="{YYYY-MM-DD}" time="{HH:MM}" duration="{minutes}" location="{location}" minute_taker_id="{id or empty}" invitee_ids="{comma-separated matched ids}" guests="{semicolon-separated unmatched names}">>>
[complete Markdown document following TMPL-SYS-001]
<<<END_MEETING_DOCUMENT>>>

For minutes:
<<<MEETING_DOCUMENT type="minutes" meeting_id="{meeting_id}" attended_ids="{comma-separated ids}" apology_ids="{comma-separated ids}">>>
[complete Markdown document following TMPL-SYS-001]
<<<END_MEETING_DOCUMENT>>>

Doctis may adjust the doc_id to keep it unique; use the one you were given for minutes.
After the closing marker add a brief confirmation. For agendas: state which attendees
will receive an email (by name). For minutes: state that the draft has been emailed to the
participants for corrections (3 business days) and awaits approval by the chair (by name),
who approves them under My Meetings.

---

## DEPARTMENTS

{$t_dept_text}

---

## CANDIDATE INVITEES (from Doctis user database, meeting_invite ≠ 0)

Match names from the user's message against this list. Use the id values in invitee_ids.
Scope: dept = departmental meetings only, all = all meetings.

{$t_candidates_text}

---

## MEETINGS AWAITING MINUTES (for this user)

{$t_awaiting_text}
{$t_focus_section}
---

{$t_template_section}
PROMPT;

	return $t_prompt;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Document processing
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Scan the AI reply for a <<<MEETING_DOCUMENT ...>>> block and act on it.
 * Returns the processing result (with a 'stripped_reply' key), or null when
 * the reply contains no document.
 *
 * @param string $p_reply   Raw AI reply text.
 * @param int    $p_user_id Current user.
 * @return array|null
 */
function ai_assist_process_meeting_document( string $p_reply, int $p_user_id ): ?array {
	$t_pattern = '/<<<MEETING_DOCUMENT\s+([^>]+)>>>([\s\S]*?)<<<END_MEETING_DOCUMENT>>>/';
	if( !preg_match( $t_pattern, $p_reply, $t_matches ) ) {
		return null;
	}

	preg_match_all( '/(\w+)="([^"]*)"/', $t_matches[1], $t_attr_pairs );
	$t_attrs   = array_combine( $t_attr_pairs[1], $t_attr_pairs[2] );
	$t_type    = ( $t_attrs['type'] ?? 'agenda' ) === 'minutes' ? 'minutes' : 'agenda';
	$t_content = trim( $t_matches[2] );

	$t_result = [
		'type'           => $t_type,
		'stripped_reply' => trim( preg_replace( $t_pattern, '', $p_reply ) ),
		'meeting_id'     => null,
		'doc_id'         => null,
		'saved'          => false,
		'stored'         => false,
		'staged'         => false,
		'dwg_id'         => null,
		'file_path'      => null,
		'commit_sha'     => null,
		'emails_sent'    => [],
		'error'          => null,
	];

	try {
		if( $t_type === 'agenda' ) {
			ai_assist_meeting_process_agenda( $t_attrs, $t_content, $p_user_id, $t_result );
		} else {
			ai_assist_meeting_process_minutes( $t_attrs, $t_content, $p_user_id, $t_result );
		}
	} catch( Throwable $e ) {
		$t_result['error'] = $e->getMessage();
		error_log( 'ai_assist_meeting_api: ' . get_class( $e ) . ': ' . $e->getMessage() );
	}

	return $t_result;
}

/**
 * Agenda: record the meeting, store the document, email invitees.
 *
 * @param array  $p_attrs   Marker attributes.
 * @param string $p_content Markdown document.
 * @param int    $p_user_id
 * @param array  $p_result  Result array, updated in place.
 * @return void
 */
function ai_assist_meeting_process_agenda( array $p_attrs, string $p_content, int $p_user_id, array &$p_result ): void {
	$t_departments = config_get_global( 'ai_meeting_departments' );
	$t_dept = strtoupper( trim( $p_attrs['dept'] ?? '' ) );
	if( !isset( $t_departments[$t_dept] ) ) {
		throw new Exception( 'Unknown department code "' . $t_dept . '" — meeting not recorded.' );
	}

	$t_start = DateTime::createFromFormat( '!Y-m-d H:i',
		trim( $p_attrs['date'] ?? '' ) . ' ' . trim( $p_attrs['time'] ?? '' ) );
	if( $t_start === false ) {
		throw new Exception( 'Meeting date/time missing or invalid — meeting not recorded.' );
	}
	$t_duration = (int)( $p_attrs['duration'] ?? 60 );
	$t_duration = ( $t_duration >= 5 && $t_duration <= 600 ) ? $t_duration : 60;

	# Only candidates may be invited or named minute taker.
	$t_candidates = ai_assist_get_meeting_candidates();
	$t_invitees = [];
	foreach( ai_assist_meeting_id_list( $p_attrs['invitee_ids'] ?? '' ) as $t_id ) {
		if( isset( $t_candidates[$t_id] ) ) {
			$t_invitees[$t_id] = [ 'user_id' => $t_id, 'name' => meeting_user_display_name( $t_id ) ];
		}
	}
	foreach( explode( ';', $p_attrs['guests'] ?? '' ) as $t_guest ) {
		if( !is_blank( $t_guest ) ) {
			$t_invitees[] = [ 'user_id' => 0, 'name' => trim( $t_guest ) ];
		}
	}
	$t_minute_taker = (int)( $p_attrs['minute_taker_id'] ?? 0 );
	if( !isset( $t_candidates[$t_minute_taker] ) ) {
		$t_minute_taker = 0;
	}

	# The reference is built here, never taken from the model.
	$t_ref = meeting_unique_ref( 'MIN-' . $t_dept . '-' . $t_start->format( 'Ymd' ) );
	$t_model_ref = $p_attrs['doc_id'] ?? '';
	if( $t_model_ref !== $t_ref && preg_match( '/^MIN-[A-Z]+-\d{8}(-\d+)?$/', $t_model_ref ) ) {
		$p_content = str_replace( $t_model_ref, $t_ref, $p_content );
	}

	$t_meeting_id = meeting_create( [
		'doc_ref'         => $t_ref,
		'title'           => trim( $p_attrs['title'] ?? '' ) ?: $t_ref,
		'department'      => $t_dept,
		'chair_id'        => $p_user_id,
		'minute_taker_id' => $t_minute_taker,
		'date_start'      => $t_start->getTimestamp(),
		'duration'        => $t_duration,
		'location'        => trim( $p_attrs['location'] ?? '' ) ?: 'Microsoft Teams',
		'created_by'      => $p_user_id,
	], array_values( $t_invitees ) );

	$p_result['meeting_id'] = $t_meeting_id;
	$p_result['doc_id']     = $t_ref;
	$p_result['saved']      = true;
	$t_meeting = meeting_get( $t_meeting_id );

	# Store as a controlled document when a meeting project is configured.
	# A storage failure leaves the meeting recorded; the error is reported.
	if( meeting_project_id( $t_dept ) > 0 ) {
		try {
			ai_assist_meeting_store( $t_meeting, $p_content, $p_user_id, 'Agenda', $p_result );
			$t_meeting = meeting_get( $t_meeting_id );
		} catch( Throwable $e ) {
			$p_result['error'] = 'Meeting recorded, but the agenda document was not stored: ' . $e->getMessage();
			error_log( 'ai_assist_meeting_api: agenda store failed: ' . $e->getMessage() );
		}
	}

	$p_result['emails_sent'] = meeting_email_agenda( $t_meeting, $p_content );
}

/**
 * Minutes: store as a draft revision of the meeting document and record attendance.
 *
 * @param array  $p_attrs   Marker attributes.
 * @param string $p_content Markdown document.
 * @param int    $p_user_id
 * @param array  $p_result  Result array, updated in place.
 * @return void
 */
function ai_assist_meeting_process_minutes( array $p_attrs, string $p_content, int $p_user_id, array &$p_result ): void {
	$t_meeting = meeting_get( (int)( $p_attrs['meeting_id'] ?? 0 ) );
	if( $t_meeting === null ) {
		throw new Exception( 'The minutes do not identify a recorded meeting — not saved.' );
	}
	if( !meeting_user_can_write_minutes( $t_meeting, $p_user_id ) ) {
		throw new Exception( 'Only the chair, minute taker or organiser can record minutes for ' . $t_meeting['doc_ref'] . '.' );
	}
	if( !in_array( (int)$t_meeting['status'], [ MEETING_AGENDA, MEETING_MINUTES ], true ) ) {
		throw new Exception( 'Minutes for ' . $t_meeting['doc_ref'] . ' are already approved or the meeting was cancelled.' );
	}

	$p_result['meeting_id'] = (int)$t_meeting['id'];
	$p_result['doc_id']     = $t_meeting['doc_ref'];

	# Attendance may name only the meeting's own invitees.
	$t_invitee_ids = [];
	foreach( meeting_invitees_get( (int)$t_meeting['id'] ) as $t_inv ) {
		$t_invitee_ids[] = (int)$t_inv['user_id'];
	}
	meeting_set_attendance( (int)$t_meeting['id'],
		array_intersect( ai_assist_meeting_id_list( $p_attrs['attended_ids'] ?? '' ), $t_invitee_ids ),
		array_intersect( ai_assist_meeting_id_list( $p_attrs['apology_ids'] ?? '' ), $t_invitee_ids )
	);

	if( (int)$t_meeting['dwg_id'] > 0 || meeting_project_id( $t_meeting['department'] ) > 0 ) {
		ai_assist_meeting_store( $t_meeting, $p_content, $p_user_id, 'Minutes', $p_result );
	}
	meeting_update( (int)$t_meeting['id'], [ 'status' => MEETING_MINUTES ] );
	$p_result['saved'] = true;

	# Circulate for corrections; the chair is also asked to approve.
	$p_result['emails_sent'] = meeting_email_draft_minutes( meeting_get( (int)$t_meeting['id'] ), $p_content, $p_user_id );
}

/**
 * Store the record and copy the outcome into the result.
 *
 * @param array  $p_meeting
 * @param string $p_content
 * @param int    $p_user_id
 * @param string $p_description Revision note.
 * @param array  $p_result      Result array, updated in place.
 * @return void
 */
function ai_assist_meeting_store( array $p_meeting, string $p_content, int $p_user_id, string $p_description, array &$p_result ): void {
	# Callers have checked the meeting role (organiser for an agenda; chair,
	# minute taker or organiser for minutes), which authorises the write.
	# Creating the agenda document still needs create_dwg_threshold.
	$t_stored = meeting_store_record( $p_meeting, $p_content, $p_user_id, $p_description, false );
	$p_result['stored']     = true;
	$p_result['staged']     = $t_stored['staged'];
	$p_result['dwg_id']     = $t_stored['dwg_id'];
	$p_result['file_path']  = $t_stored['git_path'];
	$p_result['commit_sha'] = $t_stored['git_sha'];
}

/**
 * Parse a comma-separated list of positive integer ids.
 *
 * @param string $p_list
 * @return int[]
 */
function ai_assist_meeting_id_list( string $p_list ): array {
	$t_ids = array_filter( array_map( 'intval', explode( ',', $p_list ) ), function( $p_id ) {
		return $p_id > 0;
	} );
	return array_values( array_unique( $t_ids ) );
}
