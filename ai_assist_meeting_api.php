<?php
# Doctis — AI Assistant Meeting mode functions
#
# Contains all server-side logic specific to Meeting mode:
#   ai_assist_get_meeting_candidates() — query user table for potential invitees
#   ai_assist_meeting_system_prompt()  — build the meeting session system prompt
#   ai_assist_process_meeting_document() — extract, save, email <<<MEETING_DOCUMENT>>> blocks
#   ai_assist_send_agenda_emails()     — email agenda to matched invitees
#   ai_assist_git()                    — run a git command in the HCRQMS repo
#   ai_assist_git_commit_meeting()     — stage and commit a meeting record file
#   ai_assist_register_meeting_doctis() — register committed record in Doctis
#
# Required by ai_assist_api.php via require_once.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );


# ═══════════════════════════════════════════════════════════════════════════════
# Candidate invitees
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Query the user table for potential meeting invitees (meeting_invite != 0).
 * Returns an array of rows: id, username, realname, position_title,
 * company, department, email, email_secondary, meeting_invite.
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
		$t_candidates[] = $t_row;
	}
	return $t_candidates;
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
 * @param int $p_user_id  Current user ID.
 * @return string
 */
function ai_assist_meeting_system_prompt( int $p_user_id ): string {
	$t_departments = config_get_global( 'ai_meeting_departments' );
	$t_repo_path   = config_get_global( 'hcrqms_repo_path' );
	$t_repo_set    = !is_blank( $t_repo_path );
	$t_candidates  = ai_assist_get_meeting_candidates();

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
			$t_notify_email = !is_blank( $t_c['email_secondary'] )
				? $t_c['email_secondary']
				: $t_c['email'];
			$t_scope = $t_c['meeting_invite'] == 1 ? 'dept' : 'all';
			$t_cand_lines[] = sprintf(
				'id=%-3s | %-14s | %-28s | %-28s | %-18s | %-18s | %s (%s)',
				$t_c['id'],
				$t_c['username'],
				is_blank( $t_c['realname'] ) ? '—' : $t_c['realname'],
				is_blank( $t_c['position_title'] ) ? '—' : $t_c['position_title'],
				is_blank( $t_c['department'] ) ? '—' : $t_c['department'],
				is_blank( $t_c['company'] ) ? '—' : $t_c['company'],
				$t_notify_email,
				$t_scope
			);
		}
		$t_candidates_text = implode( "\n", $t_cand_lines );
	}

	# ── HCRQMS file-save instruction ─────────────────────────────────────────
	$t_file_save_instruction = $t_repo_set
		? 'When you generate the document wrap it in the MEETING_DOCUMENT markers
below. Doctis will save the file and email it to matched invitees automatically.'
		: 'HCRQMS repository is not configured on this server. You can still
produce the document in the markers; the content will be shown to the user
but will not be saved to a file automatically.';

	# ── Meeting template ──────────────────────────────────────────────────────
	$t_template_text = '';
	if( $t_repo_set ) {
		$t_tmpl_path = $t_repo_path . '/system/templates/Meeting-Agenda-and-Minutes.md';
		if( is_readable( $t_tmpl_path ) ) {
			$t_raw = file_get_contents( $t_tmpl_path );
			$t_cut = strpos( $t_raw, '<!-- markdownlint-disable MD025 -->' );
			$t_template_text = $t_cut !== false ? trim( substr( $t_raw, $t_cut ) ) : $t_raw;
		}
	}

	$t_template_section = !is_blank( $t_template_text )
		? "## MEETING TEMPLATE (TMPL-SYS-001)\n\n" . $t_template_text
		: "## MEETING TEMPLATE\n\n" .
		  "(Template not available. Follow standard HC-Robotics format: YAML frontmatter, " .
		  "then sections: Invitees, Pre-Reading, Agenda, Attendees, Minutes, Decisions, " .
		  "Actions, Next Meeting, Distribution, Approval.)";

	# ── Current user name for chair field ────────────────────────────────────
	$t_chair_name = user_get_field( $p_user_id, 'realname' );
	if( is_blank( $t_chair_name ) ) {
		$t_chair_name = user_get_field( $p_user_id, 'username' );
	}

	# ── Assemble prompt ───────────────────────────────────────────────────────
	$t_prompt = <<<PROMPT
You are the HC-Robotics Meeting Assistant embedded in Doctis. Your job is to
produce QMS-compliant meeting agendas and minutes records with minimal friction.

{$t_file_save_instruction}

---

## CORE PRINCIPLE: INFER, DON'T ASK

Extract everything possible from what the user provides.
Fill gaps using context and common knowledge.
Do not ask for information you can infer.
Reserve questions only for information that is entirely absent AND genuinely required.

---

## SESSION FLOW

### Turn 1 — Extract, Infer, Draft (your first response)

From the user's opening message, extract:
- **Date and time** — stated directly; convert "20 June 2026 at 11am" → date: 2026-06-20, time: 11:00
- **Duration** — if stated ("less than one hour", "90 minutes"), use that; otherwise **default to 60 minutes**
- **Attendees** — all names mentioned; match each to the CANDIDATE INVITEES list below
- **Subject / title** — what the meeting is about; infer meeting type from keywords
- **Department** — infer from attendee departments or subject keywords; pick best match from DEPARTMENTS list
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

## NAME MATCHING

Match names the user mentions to the CANDIDATE INVITEES list. Rules:
- First-name match: "Phil" matches any candidate whose realname starts with "Phil" or "Philip"
- Surname match: "Wright" matches realname containing "Wright"
- Username match: "sanjay" matches username "sanjay"
- Partial / fuzzy match is acceptable — accuracy is the user's responsibility to correct
- If a name has no match in the list, include them as a plain-text attendee (no user_id)
- Collect matched IDs for the invitee_ids attribute; these drive email distribution

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

When generating the document, wrap it in these exact markers:

For an agenda:
<<<MEETING_DOCUMENT type="agenda" doc_id="MIN-{dept}-{YYYYMMDD}" dept="{dept}" title="{title}" invitee_ids="{comma-separated matched user IDs}">>>
[complete Markdown document following TMPL-SYS-001]
<<<END_MEETING_DOCUMENT>>>

For completed minutes:
<<<MEETING_DOCUMENT type="minutes" doc_id="MIN-{dept}-{YYYYMMDD}" dept="{dept}" title="{title}" invitee_ids="{comma-separated matched user IDs}">>>
[complete Markdown document following TMPL-SYS-001]
<<<END_MEETING_DOCUMENT>>>

After the closing marker add a brief confirmation. For agendas: state which attendees
will receive an email (by name). For minutes: state the record has been saved and committed.

---

## DEPARTMENTS

{$t_dept_text}

---

## CANDIDATE INVITEES (from Doctis user database, meeting_invite ≠ 0)

Match names from the user's message against this list. Use the id values in invitee_ids.
The email column shows the address that will be used (email_secondary if set, otherwise email).
Scope: dept = departmental meetings only, all = all meetings.

{$t_candidates_text}

---

{$t_template_section}
PROMPT;

	return $t_prompt;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Document processing
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Scan the AI reply for <<<MEETING_DOCUMENT ...>>> markers.
 * If found: extract the Markdown, save to HCRQMS, email invitees for agendas,
 * git-commit for minutes.
 * Returns an array with save results (and 'stripped_reply' key), or null.
 *
 * @param string $p_reply    Raw AI reply text.
 * @param int    $p_user_id  Current user (for git commit attribution).
 * @return array|null
 */
function ai_assist_process_meeting_document( string $p_reply, int $p_user_id ): ?array {
	$t_pattern = '/<<<MEETING_DOCUMENT\s+([^>]+)>>>([\s\S]*?)<<<END_MEETING_DOCUMENT>>>/';
	if( !preg_match( $t_pattern, $p_reply, $t_matches ) ) {
		return null;
	}

	# Parse attributes from the opening tag
	$t_attr_str = $t_matches[1];
	preg_match_all( '/(\w+)="([^"]*)"/', $t_attr_str, $t_attr_pairs );
	$t_attrs = array_combine( $t_attr_pairs[1], $t_attr_pairs[2] );

	$t_type          = $t_attrs['type']         ?? 'agenda';
	$t_doc_id        = $t_attrs['doc_id']        ?? '';
	$t_dept          = $t_attrs['dept']          ?? '';
	$t_title         = $t_attrs['title']         ?? $t_doc_id;
	$t_invitee_ids   = $t_attrs['invitee_ids']   ?? '';

	$t_content  = trim( $t_matches[2] );
	$t_stripped = trim( preg_replace( $t_pattern, '', $p_reply ) );

	$t_result = [
		'type'           => $t_type,
		'doc_id'         => $t_doc_id,
		'stripped_reply' => $t_stripped,
		'saved'          => false,
		'file_path'      => null,
		'committed'      => false,
		'commit_sha'     => null,
		'dwg_id'         => null,
		'emails_sent'    => [],
		'error'          => null,
	];

	$t_repo_path = config_get_global( 'hcrqms_repo_path' );
	if( is_blank( $t_repo_path ) ) {
		# No repo configured — still email if invitees given
		if( $t_type === 'agenda' && !is_blank( $t_invitee_ids ) ) {
			$t_result['emails_sent'] = ai_assist_send_agenda_emails(
				$t_invitee_ids, $t_doc_id, $t_title, $t_content
			);
		}
		return $t_result;
	}

	# Determine output subdirectory from department config
	$t_departments = config_get_global( 'ai_meeting_departments' );
	$t_dept_config = $t_departments[ $t_dept ] ?? null;
	if( $t_dept_config === null ) {
		$t_result['error'] = 'Unknown department code: ' . $t_dept;
		error_log( 'ai_assist_meeting_api: unknown dept "' . $t_dept . '" in meeting document' );
		return $t_result;
	}

	$t_rel_dir  = rtrim( $t_dept_config['path'], '/' );
	$t_filename = $t_doc_id . '.md';
	$t_rel_path = $t_rel_dir . '/' . $t_filename;
	$t_abs_dir  = rtrim( $t_repo_path, '/' ) . '/' . $t_rel_dir;
	$t_abs_path = $t_abs_dir . '/' . $t_filename;

	if( !is_dir( $t_abs_dir ) ) {
		if( !mkdir( $t_abs_dir, 0775, true ) ) {
			$t_result['error'] = 'Could not create output directory.';
			error_log( 'ai_assist_meeting_api: mkdir failed for ' . $t_abs_dir );
			return $t_result;
		}
	}

	if( file_put_contents( $t_abs_path, $t_content ) === false ) {
		$t_result['error'] = 'Could not write meeting record file.';
		error_log( 'ai_assist_meeting_api: file_put_contents failed for ' . $t_abs_path );
		return $t_result;
	}

	$t_result['saved']     = true;
	$t_result['file_path'] = $t_rel_path;

	# ── Agenda: email invitees; no git commit (draft) ─────────────────────
	if( $t_type === 'agenda' ) {
		if( !is_blank( $t_invitee_ids ) ) {
			$t_result['emails_sent'] = ai_assist_send_agenda_emails(
				$t_invitee_ids, $t_doc_id, $t_title, $t_content
			);
		}
		return $t_result;
	}

	# ── Minutes: git add + commit ─────────────────────────────────────────
	$t_user_name  = user_get_field( $p_user_id, 'realname' );
	$t_user_email = user_get_field( $p_user_id, 'email' );
	if( is_blank( $t_user_name ) ) {
		$t_user_name = user_get_field( $p_user_id, 'username' );
	}

	$t_commit_msg = 'Add ' . $t_doc_id . ': ' . $t_title . ' — Draft Minutes';
	$t_sha        = ai_assist_git_commit_meeting(
		$t_repo_path, $t_rel_path, $t_commit_msg, $t_user_name, $t_user_email
	);

	if( $t_sha !== false ) {
		$t_result['committed']  = true;
		$t_result['commit_sha'] = $t_sha;
	} else {
		$t_result['error'] = 'File saved but git commit failed — check Apache error log.';
	}

	# Doctis document registration (department must have project_id)
	$t_project_id = (int)( $t_dept_config['project_id'] ?? 0 );
	if( $t_project_id > 0 && $t_result['committed'] ) {
		$t_dwg_id = ai_assist_register_meeting_doctis(
			$t_project_id, $t_doc_id, $t_title, $t_rel_path, $p_user_id
		);
		if( $t_dwg_id !== null ) {
			$t_result['dwg_id'] = $t_dwg_id;
		}
	}

	return $t_result;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Email distribution
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Email a meeting agenda to a list of Doctis user IDs.
 *
 * Uses email_secondary if set (user's preferred notification address),
 * otherwise falls back to the primary email.
 * The agenda content is sent as the message body.
 *
 * @param string $p_invitee_ids_str  Comma-separated Doctis user IDs.
 * @param string $p_doc_id           HCRQMS document ID (e.g. MIN-ENG-20260620).
 * @param string $p_title            Meeting title.
 * @param string $p_content          Full Markdown agenda content.
 * @return array  Each element: ['name' => '...', 'email' => '...']
 */
function ai_assist_send_agenda_emails(
	string $p_invitee_ids_str,
	string $p_doc_id,
	string $p_title,
	string $p_content
): array {
	require_api( 'email_api.php' );

	$t_subject = '[Doctis] Meeting Agenda: ' . $p_doc_id .
		( !is_blank( $p_title ) ? ' — ' . $p_title : '' );

	$t_sent = [];
	$t_ids  = array_filter( array_map( 'intval', explode( ',', $p_invitee_ids_str ) ) );

	foreach( $t_ids as $t_uid ) {
		if( $t_uid <= 0 ) continue;
		if( !user_exists( $t_uid ) ) continue;

		# Use email_secondary if the user has set one
		$t_email = user_get_field( $t_uid, 'email_secondary' );
		if( is_blank( $t_email ) ) {
			$t_email = user_get_email( $t_uid );
		}
		if( is_blank( $t_email ) ) continue;

		$t_name = user_get_field( $t_uid, 'realname' );
		if( is_blank( $t_name ) ) {
			$t_name = user_get_field( $t_uid, 'username' );
		}

		email_store( $t_email, $t_subject, $p_content );
		$t_sent[] = [ 'name' => $t_name, 'email' => $t_email ];
	}

	return $t_sent;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Git helpers
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Run a git command inside the HCRQMS repository, returning [exit_code, output].
 *
 * @param string $p_repo   Absolute path to the git repository.
 * @param string $p_cmd    Git subcommand and arguments (shell-unescaped).
 * @param string $p_env    Optional env-var prefix (e.g. 'GIT_AUTHOR_NAME=...').
 * @return array{int, string}
 */
function ai_assist_git( string $p_repo, string $p_cmd, string $p_env = '' ): array {
	$t_output = [];
	$t_code   = 0;
	$t_full   = 'cd ' . escapeshellarg( $p_repo ) . ' && ';
	if( !is_blank( $p_env ) ) {
		$t_full .= $p_env . ' ';
	}
	$t_full .= 'git ' . $p_cmd . ' 2>&1';
	exec( $t_full, $t_output, $t_code );
	return [ $t_code, implode( "\n", $t_output ) ];
}

/**
 * Git-add and commit a meeting record in the HCRQMS repository.
 *
 * @param string $p_repo         Absolute repository path.
 * @param string $p_rel_path     File path relative to repo root.
 * @param string $p_message      Commit message.
 * @param string $p_author_name  Committer's real name.
 * @param string $p_author_email Committer's email.
 * @return string|false  Commit SHA on success, false on failure.
 */
function ai_assist_git_commit_meeting(
	string $p_repo,
	string $p_rel_path,
	string $p_message,
	string $p_author_name,
	string $p_author_email
): string|false {
	# Ensure git can find HOME (same fix as GitFileStorageBackend::ensure_git_home)
	if( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_getuid' ) ) {
		$t_pw = posix_getpwuid( posix_getuid() );
		if( $t_pw && isset( $t_pw['dir'] ) ) {
			putenv( 'HOME=' . $t_pw['dir'] );
		}
	}

	$t_env = 'GIT_AUTHOR_NAME='     . escapeshellarg( $p_author_name ) .
	         ' GIT_AUTHOR_EMAIL='   . escapeshellarg( $p_author_email ) .
	         ' GIT_COMMITTER_NAME=' . escapeshellarg( $p_author_name ) .
	         ' GIT_COMMITTER_EMAIL=' . escapeshellarg( $p_author_email );

	[ $t_code, $t_out ] = ai_assist_git( $p_repo, 'add ' . escapeshellarg( $p_rel_path ) );
	if( $t_code !== 0 ) {
		error_log( 'ai_assist_meeting_api: git add failed: ' . $t_out );
		return false;
	}

	[ $t_code, $t_out ] = ai_assist_git(
		$p_repo, 'commit -m ' . escapeshellarg( $p_message ), $t_env
	);
	if( $t_code !== 0 && strpos( $t_out, 'nothing to commit' ) === false ) {
		error_log( 'ai_assist_meeting_api: git commit failed: ' . $t_out );
		return false;
	}

	[ $t_code, $t_out ] = ai_assist_git( $p_repo, 'rev-parse HEAD' );
	if( $t_code !== 0 ) {
		error_log( 'ai_assist_meeting_api: git rev-parse failed: ' . $t_out );
		return false;
	}

	return trim( $t_out );
}


# ═══════════════════════════════════════════════════════════════════════════════
# Doctis registration
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Register a committed meeting record as a Doctis document.
 *
 * @param int    $p_project_id  Doctis project ID for the department.
 * @param string $p_doc_id      HCRQMS document ID (e.g. MIN-ENG-20260618).
 * @param string $p_title       Meeting title.
 * @param string $p_rel_path    File path relative to HCRQMS repo root.
 * @param int    $p_user_id     User performing the registration.
 * @return int|null  Created dwg_id, or null on failure.
 */
function ai_assist_register_meeting_doctis(
	int $p_project_id,
	string $p_doc_id,
	string $p_title,
	string $p_rel_path,
	int $p_user_id
): ?int {
	# TODO (Phase 3 — Doctis registration): see doc/ai-todo.md
	return null;
}
