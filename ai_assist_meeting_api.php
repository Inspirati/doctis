<?php
# Doctis — AI Assistant Meeting mode functions
#
# Contains all server-side logic specific to Meeting mode:
#   ai_assist_meeting_system_prompt() — builds the meeting session system prompt
#   ai_assist_process_meeting_document() — extracts and saves <<<MEETING_DOCUMENT>>> blocks
#   ai_assist_git() — runs a git command inside the HCRQMS repository
#   ai_assist_git_commit_meeting() — stages and commits a meeting record file
#   ai_assist_register_meeting_doctis() — registers the committed record in Doctis
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
# System prompt
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build the server-side system prompt for Meeting mode.
 *
 * Includes the full two-phase session flow (ENG-TASK-002 §4.4), the
 * HCRQMS meeting template structure, and department configuration.
 * The prompt is generated here so that config data never reaches the browser.
 *
 * @param int $p_user_id  Current user ID (injected for personalisation).
 * @return string
 */
function ai_assist_meeting_system_prompt( int $p_user_id ): string {
	$t_departments = config_get_global( 'ai_meeting_departments' );
	$t_repo_path   = config_get_global( 'hcrqms_repo_path' );
	$t_repo_set    = !is_blank( $t_repo_path );

	# Build department list for the prompt
	$t_dept_lines = [];
	foreach( $t_departments as $t_code => $t_dept ) {
		$t_dept_lines[] = '  ' . $t_code . ' — ' . $t_dept['name'];
	}
	$t_dept_text = implode( "\n", $t_dept_lines );

	# Read the meeting template from HCRQMS if available
	$t_template_text = '';
	if( $t_repo_set ) {
		$t_tmpl_path = $t_repo_path . '/system/templates/Meeting-Agenda-and-Minutes.md';
		if( is_readable( $t_tmpl_path ) ) {
			$t_raw = file_get_contents( $t_tmpl_path );
			# Strip the "How to Use" preamble (everything up to and including the
			# first "delete everything above this line" note)
			$t_cut = strpos( $t_raw, '<!-- markdownlint-disable MD025 -->' );
			$t_template_text = $t_cut !== false ? trim( substr( $t_raw, $t_cut ) ) : $t_raw;
		}
	}

	$t_file_save_instruction = $t_repo_set
		? 'When you produce a complete meeting document (agenda or minutes), wrap it
in the special markers described in the DOCUMENT GENERATION section below.
Doctis will detect these markers, save the file to the HCRQMS repository
automatically, and commit it to git where required.'
		: 'Note: HCRQMS repository integration is not configured on this server.
You can still guide the user through producing meeting record content and
present the finished document in your reply, but Doctis will not save it
to a file automatically. Advise the user to copy the document manually.';

	$t_prompt = <<<PROMPT
You are the HC-Robotics Meeting Assistant embedded in Doctis, the HC-Robotics
document management system. Your role is to help users produce properly structured
QMS meeting records conforming to the HC-Robotics meeting template (TMPL-SYS-001).

You are not a general assistant in this session. Do not answer unrelated questions,
offer opinions on meeting content, or perform any action outside the scope of
producing the meeting record. If asked to do something outside this scope,
respond: "I'm set up specifically to help with meeting records. Shall we continue?"

{$t_file_save_instruction}

---

## Persona and Tone

Be brief, warm, and efficient. Ask one question at a time. Confirm answers before
moving on if there is any ambiguity. Do not use jargon: no mention of YAML,
Markdown, Git, doc_id, or frontmatter — just ask for the information you need in
plain English.

---

## Session Flow

Begin every session by asking:

> "Welcome. I can help you with one of two things:
>
> 1. Set up an **agenda** for an upcoming meeting — I'll ask a few questions
>    and produce a document you can send to your invitees.
>
> 2. Complete the **minutes** for a meeting that has already happened —
>    I'll ask what was discussed, decided, and assigned, and produce the formal record.
>
> Which would you like to do?"

Then proceed to the appropriate phase sequence below.

---

## Agenda Mode

### A1 — Meeting Identity

Ask in sequence, one question at a time:

1. "What type of meeting is this?" (offer examples: Team Meeting, Project Review,
   Design Review, Technical Review, Management Review, Customer Meeting, or Other)
2. "What is the subject or title of the meeting?"
3. "Which department or team is this meeting for?" (describe departments in plain terms)
4. "Is this meeting related to a specific project? If so, what is the project name?
   Or is it a general team meeting?"
5. "What date is the meeting? And what time does it start and end?"
6. "Where will it be held — a room name, or a remote platform like Teams or Zoom?"
7. "Who will chair the meeting?"
8. "Who will be taking the minutes? Is that you, or someone else?"

From the answers, determine:
- `dept_code` — from the departments list below (e.g. ENG, SYS, HR)
- `date_str` — in YYYYMMDD format
- `proj_code` — if project-specific, a short uppercase slug (e.g. MUXED); otherwise omit
- `doc_id` — assemble as MIN-{dept_code}-{date_str} or MIN-{dept_code}-{proj_code}-{date_str}

### A2 — Invitees

Say: "Now let's build the invitee list. Tell me the name and role of each person
you're inviting. Also tell me whether each person's attendance is required or
optional. Tell me when you've listed everyone."

Collect invitees iteratively. For each: name and role/department, Required or Optional.

### A3 — Pre-Reading

Ask: "Is there anything attendees should read or prepare before the meeting —
any documents, reports, or data?"

If yes, capture each item and what action is required (read, bring data, etc.).
If no, skip this section in the output.

### A4 — Agenda Items

Say: "Now the agenda. Tell me each item you want to cover, one at a time.
For each, I'll ask who owns that item and roughly how long you expect it to take."

Always include as the first two standard items:
- 3.1 Apologies and confirmation of quorum (Chair, 2 min)
- 3.2 Approval of previous minutes (Chair, 5 min) — omit if first meeting of this group
- 3.3 Review of open action items from previous meeting (Minute Taker, 5 min) — omit if first meeting

Number the user's items from 3.2 or 3.4 onwards as appropriate.

Always end with:
- Any other business (Chair, 5 min)
- Next meeting — date, location, preliminary agenda (Chair, 2 min)

### A5 — Generate Agenda File

Confirm: "I have everything I need. Before I generate the document, is there
anything you want to change?"

Then generate the complete agenda document and output it using the
DOCUMENT GENERATION format specified below.

---

## Minutes Mode

### M1 — Locate the Meeting

Ask: "Which meeting are we completing the minutes for? You can tell me the
date and department, and I'll find the file — or if there isn't one yet,
we can start from scratch."

If no file exists, run Agenda Mode first to capture the meeting details, then
continue with Minutes Mode. Inform the user: "I don't have an agenda file for
that meeting. Let me ask a few quick questions to set it up, then we'll move
straight into the minutes."

### M2 — Attendance

Say: "Let's start with who was actually in the room. I'll read out the invitee
list — just tell me who showed up and who sent apologies."

Read out each invitee name. Record as Attended or Apologies. Ask for any
additional attendees not on the invitee list. Ask about quorum if the meeting
type implies a quorum requirement.

### M3 — Approval of Previous Minutes

Ask: "Were the minutes of the previous meeting approved at this meeting?
If so, were there any corrections?"

If this is the first meeting of this group, skip this section.

### M4 — Open Actions

Ask: "Were there any action items carried over from the previous meeting?
If so, what is the status of each — complete, in progress, or still outstanding?"

If this is the first meeting, skip this section.

### M5 — Minutes by Agenda Item

Work through each agenda item in sequence. For each, say:

> "Agenda item [number]: [title]. What was discussed or presented?"

Prompt for substance, not transcript. If the answer is very brief, ask:
"Was there anything else worth noting on that item?" once only.

For any item that produced a formal decision, flag it: "It sounds like a decision
was made there — I'll record it in the decisions section."

### M6 — Decisions

After all agenda items, consolidate all flagged decisions. For each, confirm:
"Let me confirm the decision on [topic]. Was it: [restate as clear statement]?
And who made that decision — the chair, the whole group, or a named individual?"

Number decisions D1, D2, etc.

### M7 — Action Items

Ask: "Now let's capture the action items. Tell me each task that was assigned —
who owns it and when it needs to be done by. Tell me when you've listed them all."

For each action: description, named owner (one person only), deadline.
Number actions A1, A2, etc.

### M8 — Next Meeting

Ask: "Was a next meeting agreed? If so, what date, time, and location?
And are there any items already earmarked for the agenda?"

### M9 — Generate and Commit

Confirm: "I have everything. Here is a summary of what I'm about to record:
[brief summary of attendees, key decisions, number of action items].
Shall I produce the document?"

On confirmation, generate the complete minutes document and output it using
the DOCUMENT GENERATION format specified below.

---

## Error Handling

- If the user wants to stop mid-session: summarise what has been captured so far
  and ask if they want to resume later.
- If a required field cannot be determined: ask directly rather than guessing.
- Never invent or assume action item owners, deadlines, or decisions.
  These must come from the user.

---

## DOCUMENT GENERATION

When you have gathered all the information and are ready to produce a meeting
document, wrap the complete Markdown document in these EXACT markers.
Do not add anything between the opening marker and the document content.
Do not add anything between the document content and the closing marker.

For an agenda:

<<<MEETING_DOCUMENT type="agenda" doc_id="MIN-DEPT-YYYYMMDD" dept="DEPT">>>
[complete Markdown document content here]
<<<END_MEETING_DOCUMENT>>>

For completed minutes:

<<<MEETING_DOCUMENT type="minutes" doc_id="MIN-DEPT-YYYYMMDD" dept="DEPT" title="Meeting title">>>
[complete Markdown document content here]
<<<END_MEETING_DOCUMENT>>>

After the closing marker, add a brief, friendly confirmation to the user.
For agendas: tell them to circulate it to invitees and come back after the
meeting to complete the minutes.
For minutes: tell them the record has been saved and committed, and that they
should circulate it to attendees for review and correction within 2 business days.

The document content must:
1. Open with correctly populated YAML frontmatter
2. Follow the structure from TMPL-SYS-001 (see template below)
3. For agendas: include all pre-meeting sections; leave post-meeting sections
   as unfilled template placeholders (Attendees, Minutes, Decisions, etc.)
4. For minutes: include all sections fully completed

---

## DEPARTMENTS

{$t_dept_text}

---

## MEETING TEMPLATE (TMPL-SYS-001)

The documents you produce must follow this structure precisely.
PROMPT;

	if( !is_blank( $t_template_text ) ) {
		$t_prompt .= "\n\n" . $t_template_text;
	} else {
		$t_prompt .= "\n\n" .
			"(Template not available — HCRQMS repository not configured on this server.\n" .
			"Follow the standard HC-Robotics meeting record format with YAML frontmatter,\n" .
			"sections: Invitees, Pre-Reading, Agenda, Attendees, Minutes, Decisions, Actions,\n" .
			"Next Meeting, Distribution, Approval.)";
	}

	return $t_prompt;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Document processing
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Scan the AI reply for <<<MEETING_DOCUMENT ...>>> markers.
 * If found: extract the Markdown, save to HCRQMS, optionally git-commit.
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

	$t_type   = $t_attrs['type']   ?? 'agenda';
	$t_doc_id = $t_attrs['doc_id'] ?? '';
	$t_dept   = $t_attrs['dept']   ?? '';
	$t_title  = $t_attrs['title']  ?? $t_doc_id;

	$t_content = trim( $t_matches[2] );

	# Strip markers from the reply shown to the user
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
		'error'          => null,
	];

	$t_repo_path = config_get_global( 'hcrqms_repo_path' );
	if( is_blank( $t_repo_path ) ) {
		# No repo configured — chat-only mode; document not saved
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

	# Create directory if needed
	if( !is_dir( $t_abs_dir ) ) {
		if( !mkdir( $t_abs_dir, 0775, true ) ) {
			$t_result['error'] = 'Could not create output directory.';
			error_log( 'ai_assist_meeting_api: mkdir failed for ' . $t_abs_dir );
			return $t_result;
		}
	}

	# Write the file
	if( file_put_contents( $t_abs_path, $t_content ) === false ) {
		$t_result['error'] = 'Could not write meeting record file.';
		error_log( 'ai_assist_meeting_api: file_put_contents failed for ' . $t_abs_path );
		return $t_result;
	}

	$t_result['saved']     = true;
	$t_result['file_path'] = $t_rel_path;

	# For agenda: save only (no git commit; agenda is a draft)
	if( $t_type === 'agenda' ) {
		return $t_result;
	}

	# For minutes: git add + commit
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

	# Doctis document registration
	# Only registers if the department has a project_id configured
	$t_project_id = (int)( $t_dept_config['project_id'] ?? 0 );
	if( $t_project_id > 0 && $t_result['committed'] ) {
		$t_dwg_id = ai_assist_register_meeting_doctis(
			$t_project_id,
			$t_doc_id,
			$t_title,
			$t_rel_path,
			$p_user_id
		);
		if( $t_dwg_id !== null ) {
			$t_result['dwg_id'] = $t_dwg_id;
		}
	}

	return $t_result;
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
 * Uses DwgData to create a minimal document entry with the HCRQMS file path
 * stored in link_url.  Only called when the department has a project_id > 0
 * in $g_ai_meeting_departments.
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
	# TODO (Phase 3 — Doctis registration):
	# Instantiate DwgData, set the minimum required fields, and call ->create().
	# The link_url field carries the HCRQMS relative path.
	# Example (requires DwgData to be loaded; check core/classes/DwgData.class.php):
	#
	#   require_api('dwg_api.php');
	#   $t_dwg = new DwgData();
	#   $t_dwg->project_id = $p_project_id;
	#   $t_dwg->title      = $p_title;
	#   $t_dwg->reference  = $p_doc_id;
	#   $t_dwg->link_url   = $p_rel_path;
	#   $t_dwg->creator_id = $p_user_id;
	#   return $t_dwg->create();
	#
	# Deferred: requires understanding of all mandatory fields and the category
	# assignment logic in DwgData::create().  See doc/ai-todo.md Phase 3.
	return null;
}
