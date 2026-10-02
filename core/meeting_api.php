<?php
# Doctis — Meeting API
#
# A meeting is planned through the AI Assistant Meeting tab and listed on the
# My Meetings page. Its agenda and minutes are two revisions of one Doctis
# document stored in the meeting project's git repository:
#
#   agenda  → new document, primary file registered On Record (issued at once)
#   minutes → replacement of the same file, staged as Draft until the chair
#             approves it (meeting_minutes_approve()), which promotes it to
#             On Record and marks the meeting approved
#
# With no meeting project configured ($g_meeting_project_id = 0) the meeting
# row and its invitees are still recorded, but no document is created.
#
# Tables: {meeting}, {meeting_invitee}, {meeting_action} (admin/schema.php
# steps 60–62). Calendar invitations: core/meeting_calendar_api.php. Actions
# and their issues: core/meeting_action_api.php.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'access_dwg_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'lang_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );
require_api( 'meeting_calendar_api.php' );
require_api( 'meeting_action_api.php' );

use Mantis\Exceptions\ClientException;

define( 'MEETING_AGENDA', 10 );
define( 'MEETING_MINUTES', 20 );
define( 'MEETING_APPROVED', 30 );
define( 'MEETING_CANCELLED', 90 );

define( 'MEETING_INVITED', 0 );
define( 'MEETING_ATTENDED', 10 );
define( 'MEETING_APOLOGIES', 20 );

/** Columns of {meeting} that meeting_update() may change. */
const MEETING_UPDATABLE_FIELDS = array(
	'title', 'department', 'project_id', 'dwg_id', 'git_path', 'chair_id',
	'minute_taker_id', 'date_start', 'duration', 'location', 'status',
	'series_id', 'sequence',
);


# ═══════════════════════════════════════════════════════════════════════════════
# Retrieval
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Get a meeting row by id.
 *
 * @param int $p_meeting_id
 * @return array|null
 */
function meeting_get( int $p_meeting_id ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting} WHERE id=' . db_param(), array( $p_meeting_id ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

/**
 * Get a meeting row by its document reference (e.g. MIN-SYS-20260917).
 *
 * @param string $p_doc_ref
 * @return array|null
 */
function meeting_get_by_ref( string $p_doc_ref ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting} WHERE doc_ref=' . db_param(), array( $p_doc_ref ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

/**
 * Get the meeting whose record is stored in a document, if any.
 *
 * @param int $p_dwg_id
 * @return array|null
 */
function meeting_get_by_dwg( int $p_dwg_id ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting} WHERE dwg_id=' . db_param(), array( $p_dwg_id ), 1 );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

/**
 * Get a meeting's invitees with display names resolved.
 *
 * @param int $p_meeting_id
 * @return array Rows: id, user_id, name, attendance.
 */
function meeting_invitees_get( int $p_meeting_id ): array {
	db_param_push();
	$t_result = db_query(
		'SELECT id, user_id, name, attendance FROM {meeting_invitee}'
		. ' WHERE meeting_id=' . db_param() . ' ORDER BY id',
		array( $p_meeting_id )
	);
	$t_rows = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		if( (int)$t_row['user_id'] > 0 && user_exists( (int)$t_row['user_id'] ) ) {
			$t_row['name'] = meeting_user_display_name( (int)$t_row['user_id'] );
		}
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Meetings in which a user takes part (chair, minute taker, organiser or
 * invitee), newest first, including cancelled ones. Each row gains a 'role' key.
 *
 * @param int $p_user_id
 * @return array
 */
function meeting_get_for_user( int $p_user_id ): array {
	db_param_push();
	$t_result = db_query(
		'SELECT m.* FROM {meeting} m'
		. ' WHERE ( m.chair_id=' . db_param() . ' OR m.minute_taker_id=' . db_param()
		. ' OR m.created_by=' . db_param()
		. ' OR EXISTS ( SELECT 1 FROM {meeting_invitee} i'
		. ' WHERE i.meeting_id = m.id AND i.user_id=' . db_param() . ' ) )'
		. ' ORDER BY m.date_start DESC',
		array( $p_user_id, $p_user_id, $p_user_id, $p_user_id )
	);
	$t_rows = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_row['role'] = meeting_user_role( $t_row, $p_user_id );
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Meetings the user may write minutes for that have none yet, most recent first.
 *
 * @param int $p_user_id
 * @return array
 */
function meeting_get_awaiting_minutes( int $p_user_id ): array {
	$t_rows = array();
	foreach( meeting_get_for_user( $p_user_id ) as $t_row ) {
		if( (int)$t_row['status'] === MEETING_AGENDA && meeting_user_can_write_minutes( $t_row, $p_user_id ) ) {
			$t_rows[] = $t_row;
		}
	}
	return $t_rows;
}

/**
 * Recent meetings the user could plan a follow-up to (chair, organiser or
 * minute taker; not cancelled), newest first.
 *
 * @param int $p_user_id
 * @param int $p_limit
 * @return array
 */
function meeting_get_recent_led( int $p_user_id, int $p_limit = 10 ): array {
	$t_rows = array();
	foreach( meeting_get_for_user( $p_user_id ) as $t_row ) {
		if( (int)$t_row['status'] !== MEETING_CANCELLED && meeting_user_can_write_minutes( $t_row, $p_user_id ) ) {
			$t_rows[] = $t_row;
			if( count( $t_rows ) >= $p_limit ) {
				break;
			}
		}
	}
	return $t_rows;
}

/**
 * Series id of a meeting: its series_id, or its own id when it starts one.
 *
 * @param array $p_meeting
 * @return int
 */
function meeting_series_root( array $p_meeting ): int {
	return (int)$p_meeting['series_id'] > 0 ? (int)$p_meeting['series_id'] : (int)$p_meeting['id'];
}

/**
 * All meetings in a meeting's series (including itself), oldest first.
 * A meeting not in a series returns just itself.
 *
 * @param array $p_meeting
 * @return array
 */
function meeting_series_get( array $p_meeting ): array {
	$t_root = meeting_series_root( $p_meeting );
	db_param_push();
	$t_result = db_query(
		'SELECT * FROM {meeting} WHERE id=' . db_param() . ' OR series_id=' . db_param()
		. ' ORDER BY date_start, id',
		array( $t_root, $t_root )
	);
	$t_rows = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Roles and labels
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * The user's role in a meeting: 'chair', 'minute_taker', 'organiser',
 * 'invitee', or '' when not involved.
 *
 * @param array $p_meeting Meeting row.
 * @param int   $p_user_id
 * @return string
 */
function meeting_user_role( array $p_meeting, int $p_user_id ): string {
	if( (int)$p_meeting['chair_id'] === $p_user_id ) {
		return 'chair';
	}
	if( (int)$p_meeting['minute_taker_id'] === $p_user_id ) {
		return 'minute_taker';
	}
	if( (int)$p_meeting['created_by'] === $p_user_id ) {
		return 'organiser';
	}
	db_param_push();
	$t_result = db_query(
		'SELECT 1 FROM {meeting_invitee} WHERE meeting_id=' . db_param() . ' AND user_id=' . db_param(),
		array( (int)$p_meeting['id'], $p_user_id ), 1
	);
	return db_result( $t_result ) !== false ? 'invitee' : '';
}

/**
 * Whether the user may record minutes: the chair, minute taker or organiser.
 *
 * @param array $p_meeting Meeting row.
 * @param int   $p_user_id
 * @return bool
 */
function meeting_user_can_write_minutes( array $p_meeting, int $p_user_id ): bool {
	return in_array( meeting_user_role( $p_meeting, $p_user_id ), array( 'chair', 'minute_taker', 'organiser' ), true );
}

/**
 * Whether the user may approve the minutes: only the chair, and only while
 * the minutes are in draft.
 *
 * @param array $p_meeting Meeting row.
 * @param int   $p_user_id
 * @return bool
 */
function meeting_user_can_approve_minutes( array $p_meeting, int $p_user_id ): bool {
	return (int)$p_meeting['status'] === MEETING_MINUTES && (int)$p_meeting['chair_id'] === $p_user_id;
}

/**
 * Whether the user may reschedule, change invitees or cancel: the chair or
 * organiser, while only the agenda has been issued.
 *
 * @param array $p_meeting
 * @param int   $p_user_id
 * @return bool
 */
function meeting_user_can_manage( array $p_meeting, int $p_user_id ): bool {
	return (int)$p_meeting['status'] === MEETING_AGENDA
		&& in_array( meeting_user_role( $p_meeting, $p_user_id ), array( 'chair', 'organiser' ), true );
}

/**
 * Whether the user may see a meeting's details: any participant, or anyone
 * who can view its document.
 *
 * @param array $p_meeting
 * @param int   $p_user_id
 * @return bool
 */
function meeting_user_can_view( array $p_meeting, int $p_user_id ): bool {
	if( meeting_user_role( $p_meeting, $p_user_id ) !== '' ) {
		return true;
	}
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	return $t_dwg_id > 0 && dwg_exists( $t_dwg_id )
		&& access_has_dwg_level( config_get( 'view_dwg_threshold' ), $t_dwg_id, $p_user_id );
}

/**
 * Localised label for a meeting status.
 *
 * @param int $p_status
 * @return string
 */
function meeting_status_label( int $p_status ): string {
	switch( $p_status ) {
		case MEETING_AGENDA:    return lang_get( 'meeting_status_agenda' );
		case MEETING_MINUTES:   return lang_get( 'meeting_status_minutes' );
		case MEETING_APPROVED:  return lang_get( 'meeting_status_approved' );
		case MEETING_CANCELLED: return lang_get( 'meeting_status_cancelled' );
	}
	return (string)$p_status;
}

/**
 * The address meeting mail goes to: the user's notification email
 * (email_secondary) if set, else their primary email.
 *
 * @param int $p_user_id
 * @return string
 */
function meeting_user_notify_email( int $p_user_id ): string {
	$t_email = (string)user_get_field( $p_user_id, 'email_secondary' );
	return is_blank( $t_email ) ? (string)user_get_email( $p_user_id ) : $t_email;
}

/**
 * Real name, falling back to username.
 *
 * @param int $p_user_id
 * @return string
 */
function meeting_user_display_name( int $p_user_id ): string {
	$t_name = user_get_field( $p_user_id, 'realname' );
	return is_blank( $t_name ) ? user_get_field( $p_user_id, 'username' ) : $t_name;
}


# ═══════════════════════════════════════════════════════════════════════════════
# Creation and update
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Return $p_doc_ref, or $p_doc_ref-2, -3 … when already taken.
 *
 * @param string $p_doc_ref
 * @return string
 */
function meeting_unique_ref( string $p_doc_ref ): string {
	$t_ref = $p_doc_ref;
	for( $i = 2; meeting_get_by_ref( $t_ref ) !== null; $i++ ) {
		$t_ref = $p_doc_ref . '-' . $i;
	}
	return $t_ref;
}

/**
 * Create a meeting and its invitee list.
 *
 * @param array $p_fields   {meeting} column values (doc_ref, title, department,
 *                          chair_id, minute_taker_id, date_start, duration,
 *                          location, created_by).
 * @param array $p_invitees Each ['user_id' => int, 'name' => string].
 * @return int New meeting id.
 */
function meeting_create( array $p_fields, array $p_invitees ): int {
	$t_now = db_now();
	db_param_push();
	db_query(
		'INSERT INTO {meeting}'
		. ' ( series_id, doc_ref, title, department, chair_id, minute_taker_id, date_start,'
		. '   duration, location, status, created_by, date_created, date_updated )'
		. ' VALUES ( ' . implode( ', ', array_fill( 0, 13, db_param() ) ) . ' )',
		array(
			(int)( $p_fields['series_id'] ?? 0 ),
			$p_fields['doc_ref'],
			substr( $p_fields['title'], 0, 255 ),
			$p_fields['department'],
			(int)$p_fields['chair_id'],
			(int)$p_fields['minute_taker_id'],
			(int)$p_fields['date_start'],
			(int)$p_fields['duration'],
			substr( $p_fields['location'], 0, 128 ),
			MEETING_AGENDA,
			(int)$p_fields['created_by'],
			$t_now,
			$t_now,
		)
	);
	$t_meeting_id = (int)db_insert_id( db_get_table( 'meeting' ) );

	foreach( $p_invitees as $t_invitee ) {
		meeting_invitee_add( $t_meeting_id, (int)$t_invitee['user_id'], $t_invitee['name'] );
	}
	return $t_meeting_id;
}

/**
 * Add an invitee (user_id 0 = guest named $p_name).
 *
 * @param int    $p_meeting_id
 * @param int    $p_user_id
 * @param string $p_name
 * @return void
 */
function meeting_invitee_add( int $p_meeting_id, int $p_user_id, string $p_name ): void {
	db_param_push();
	db_query(
		'INSERT INTO {meeting_invitee} ( meeting_id, user_id, name, attendance )'
		. ' VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_meeting_id, $p_user_id, substr( $p_name, 0, 128 ), MEETING_INVITED )
	);
}

/**
 * Remove an invitee row.
 *
 * @param int $p_meeting_id
 * @param int $p_invitee_id {meeting_invitee}.id
 * @return void
 */
function meeting_invitee_remove( int $p_meeting_id, int $p_invitee_id ): void {
	db_param_push();
	db_query( 'DELETE FROM {meeting_invitee} WHERE meeting_id=' . db_param() . ' AND id=' . db_param(),
		array( $p_meeting_id, $p_invitee_id ) );
}

/**
 * Update meeting columns; unknown keys are ignored.
 *
 * @param int   $p_meeting_id
 * @param array $p_fields
 * @return void
 */
function meeting_update( int $p_meeting_id, array $p_fields ): void {
	$t_set = array();
	$t_params = array();
	db_param_push();
	foreach( $p_fields as $t_field => $t_value ) {
		if( in_array( $t_field, MEETING_UPDATABLE_FIELDS, true ) ) {
			$t_set[] = $t_field . '=' . db_param();
			$t_params[] = $t_value;
		}
	}
	if( empty( $t_set ) ) {
		return;
	}
	$t_set[] = 'date_updated=' . db_param();
	$t_params[] = db_now();
	$t_params[] = $p_meeting_id;
	db_query( 'UPDATE {meeting} SET ' . implode( ', ', $t_set ) . ' WHERE id=' . db_param(), $t_params );
}

/**
 * Record attendance for invitees with Doctis accounts.
 *
 * @param int   $p_meeting_id
 * @param int[] $p_attended_ids
 * @param int[] $p_apology_ids
 * @return void
 */
function meeting_set_attendance( int $p_meeting_id, array $p_attended_ids, array $p_apology_ids ): void {
	$t_states = array( MEETING_ATTENDED => $p_attended_ids, MEETING_APOLOGIES => $p_apology_ids );
	foreach( $t_states as $t_state => $t_ids ) {
		foreach( $t_ids as $t_user_id ) {
			db_param_push();
			db_query(
				'UPDATE {meeting_invitee} SET attendance=' . db_param()
				. ' WHERE meeting_id=' . db_param() . ' AND user_id=' . db_param(),
				array( $t_state, $p_meeting_id, (int)$t_user_id )
			);
		}
	}
}


# ═══════════════════════════════════════════════════════════════════════════════
# Document storage
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Project whose repository holds a department's meeting records: the
 * department's own project_id if set, else $g_meeting_project_id. Returns 0
 * when none is configured or the project does not exist.
 *
 * @param string $p_department Department code.
 * @return int
 */
function meeting_project_id( string $p_department = '' ): int {
	$t_departments = config_get_global( 'ai_meeting_departments' );
	$t_project_id = (int)( $t_departments[$p_department]['project_id'] ?? 0 );
	if( $t_project_id === 0 ) {
		$t_project_id = (int)config_get_global( 'meeting_project_id' );
	}
	return ( $t_project_id > 0 && project_exists( $t_project_id ) ) ? $t_project_id : 0;
}

/**
 * Read a file from the HEAD of a project's repository.
 *
 * @param int    $p_project_id
 * @param string $p_git_path   Repo-relative path.
 * @return string|null Content, or null when unavailable.
 */
function meeting_repo_file_get( int $p_project_id, string $p_git_path ): ?string {
	$t_path = file_dwg_git_path_sanitize( $p_git_path );
	if( $p_project_id === 0 || $t_path === false || repository_id_for_project( $p_project_id ) === 0 ) {
		return null;
	}
	$t_bare = dwg_project_bare_repo_path( $p_project_id );
	if( $t_bare === '' || !is_dir( $t_bare ) ) {
		return null;
	}
	return meeting_git_blob( $t_bare, 'HEAD:' . $t_path );
}

/**
 * Byte-exact content of a blob ("<rev>:<path>") in a bare repository.
 * (exec() would strip trailing whitespace from each line.)
 *
 * @param string $p_bare Bare repository path.
 * @param string $p_spec Object spec, e.g. "<sha>:<path>".
 * @return string|null
 */
function meeting_git_blob( string $p_bare, string $p_spec ): ?string {
	$t_git = 'git --git-dir=' . escapeshellarg( $p_bare );
	exec( $t_git . ' cat-file -e ' . escapeshellarg( $p_spec ) . ' 2>/dev/null', $t_out, $t_rc );
	if( $t_rc !== 0 ) {
		return null;
	}
	return (string)shell_exec( $t_git . ' cat-file blob ' . escapeshellarg( $p_spec ) . ' 2>/dev/null' );
}

/**
 * The meeting template text from the meeting project's repository, or ''.
 *
 * @return string
 */
function meeting_template_get(): string {
	$t_content = meeting_repo_file_get( meeting_project_id(), (string)config_get_global( 'meeting_template_path' ) );
	return $t_content ?? '';
}

/**
 * The meeting's current record content: the staged draft if any, otherwise
 * the On Record file. '' when the meeting has no document.
 *
 * @param array $p_meeting Meeting row.
 * @return string
 */
function meeting_record_content( array $p_meeting ): string {
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	if( $t_dwg_id === 0 || !dwg_exists( $t_dwg_id ) ) {
		return '';
	}
	$t_row = file_dwg_primary_draft_get( $t_dwg_id ) ?: file_dwg_primary_get( $t_dwg_id );
	if( !$t_row ) {
		return '';
	}
	$t_bare = dwg_project_bare_repo_path( (int)dwg_get_field( $t_dwg_id, 'project_id' ) );
	return meeting_git_blob( $t_bare, $t_row['git_sha'] . ':' . $t_row['git_path'] ) ?? '';
}

/**
 * Store meeting record content as the meeting's Doctis document.
 *
 * First call (agenda) creates the document in the meeting project and
 * registers the file On Record. Later calls (minutes) replace the same file;
 * file_dwg_primary_add() stages the replacement as a Draft for approval.
 *
 * @param array  $p_meeting     Meeting row.
 * @param string $p_content     Markdown content.
 * @param int    $p_user_id     Acting user (git author).
 * @param string $p_description Revision note.
 * @param bool   $p_check_access Require update access for a replacement.
 *                               False when a meeting role authorises the
 *                               write instead (minute taker writing minutes,
 *                               chair stamping approval): meeting records live
 *                               in a project the participants may not belong to.
 * @return array{dwg_id: int, git_path: string, git_sha: string, staged: bool}
 * @throws ClientException when no project is configured or access is denied.
 */
function meeting_store_record( array $p_meeting, string $p_content, int $p_user_id, string $p_description, bool $p_check_access = true ): array {
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	$t_staged = $t_dwg_id > 0;

	if( $t_dwg_id === 0 ) {
		$t_project_id = meeting_project_id( $p_meeting['department'] );
		if( $t_project_id === 0 ) {
			throw new ClientException( 'No meeting project is configured', ERROR_GENERIC );
		}
		$t_departments = config_get_global( 'ai_meeting_departments' );
		$t_dir = trim( $t_departments[$p_meeting['department']]['path'] ?? 'meetings', '/' );
		$t_git_path = file_dwg_git_path_sanitize( $t_dir . '/' . $p_meeting['doc_ref'] . '.md' );
		if( $t_git_path === false ) {
			throw new ClientException( 'Invalid meeting record path', ERROR_INVALID_FIELD_VALUE, array( 'git_path' ) );
		}
		$t_dwg_id = meeting_document_create( $p_meeting, $t_project_id );
	} else {
		# Throw rather than access_ensure_*(), which prints a page and exits.
		if( $p_check_access && !access_has_dwg_level( config_get( 'update_dwg_threshold' ), $t_dwg_id, $p_user_id ) ) {
			throw new ClientException(
				'User ' . $p_user_id . ' may not update document ' . $t_dwg_id, ERROR_ACCESS_DENIED
			);
		}
		$t_git_path = '';
	}

	$t_tmp = tempnam( sys_get_temp_dir(), 'doctis-meeting-' );
	file_put_contents( $t_tmp, $p_content );
	try {
		$t_stored = file_dwg_primary_add(
			$t_dwg_id, $p_user_id, $t_tmp, $p_meeting['doc_ref'] . '.md', strlen( $p_content ),
			'text/markdown', $p_description, $t_git_path
		);
	} finally {
		@unlink( $t_tmp );
	}

	meeting_update( (int)$p_meeting['id'], array(
		'dwg_id'     => $t_dwg_id,
		'project_id' => (int)dwg_get_field( $t_dwg_id, 'project_id' ),
		'git_path'   => $t_stored['git_path'],
	) );

	return array(
		'dwg_id'   => $t_dwg_id,
		'git_path' => $t_stored['git_path'],
		'git_sha'  => $t_stored['git_sha'],
		'staged'   => $t_staged,
	);
}

/**
 * Chair approval of the minutes: promote the minutes Draft to On Record
 * (when the meeting has a document) and mark the meeting approved.
 *
 * @param array  $p_meeting      Meeting row.
 * @param int    $p_user_id      Acting user; must be the chair.
 * @param string $p_expected_sha Draft revision the chair reviewed ('' = current).
 * @return array Action issue results (meeting_actions_create_issues()).
 * @throws ClientException when the user is not the chair or nothing awaits approval.
 */
function meeting_minutes_approve( array $p_meeting, int $p_user_id, string $p_expected_sha = '' ): array {
	if( !meeting_user_can_approve_minutes( $p_meeting, $p_user_id ) ) {
		throw new ClientException(
			'Only the chair can approve draft minutes for ' . $p_meeting['doc_ref'],
			ERROR_ACCESS_DENIED
		);
	}
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	if( $t_dwg_id > 0 && file_dwg_primary_draft_get( $t_dwg_id ) ) {
		$t_head = file_dwg_git_head_info( $t_dwg_id );
		if( $p_expected_sha !== '' && ( !$t_head || !hash_equals( $p_expected_sha, $t_head['sha'] ) ) ) {
			throw new ClientException( 'The draft minutes changed while approval was pending',
				ERROR_INVALID_FIELD_VALUE, array( 'draft' ) );
		}
		# Stamp the record itself (TMPL-SYS-001 lifecycle), then promote it.
		$t_draft = meeting_record_content( $p_meeting );
		$t_approved = meeting_record_mark_approved( $t_draft, date( 'Y-m-d' ) );
		if( $t_draft !== '' && $t_approved !== $t_draft ) {
			meeting_store_record( $p_meeting, $t_approved, $p_user_id, 'Minutes approved', false );
		}
		file_dwg_primary_sync_head( $t_dwg_id, $p_user_id );
	}
	meeting_update( (int)$p_meeting['id'], array( 'status' => MEETING_APPROVED ) );
	$t_meeting = meeting_get( (int)$p_meeting['id'] );
	$t_issues = meeting_actions_create_issues( $t_meeting, $p_user_id );
	meeting_email_approved_minutes( $t_meeting, meeting_record_content( $t_meeting ), $p_user_id );
	return $t_issues;
}

/**
 * Mark a minutes record approved: frontmatter status → Approved Minutes,
 * effective_date → the approval date, and the body's **Status:** line.
 *
 * @param string $p_content Markdown record.
 * @param string $p_date    YYYY-MM-DD.
 * @return string
 */
function meeting_record_mark_approved( string $p_content, string $p_date ): string {
	if( !preg_match( '/\A---\n/', $p_content ) ) {
		return $p_content;
	}
	return meeting_record_set( $p_content,
		array( 'status' => 'Approved Minutes', 'effective_date' => $p_date ),
		array( 'Status' => 'Approved Minutes' ) );
}

/**
 * Rewrite fields of a record: YAML frontmatter keys (existing lines only,
 * value aligned as in the template) and the first "**Label:**" line of the
 * body for each label. Other content is untouched.
 *
 * @param string $p_content
 * @param array  $p_front   key => value
 * @param array  $p_body    label => value
 * @return string
 */
function meeting_record_set( string $p_content, array $p_front, array $p_body ): string {
	$t_front = '';
	$t_body = $p_content;
	if( preg_match( '/\A---\n([\s\S]*?)\n---\n/', $p_content, $t_m ) ) {
		$t_front = $t_m[1];
		$t_body = substr( $p_content, strlen( $t_m[0] ) );
		foreach( $p_front as $t_key => $t_value ) {
			# Callbacks insert values literally ($ and \ are not references).
			$t_line = rtrim( str_pad( $t_key . ':', 16 ) . str_replace( "\n", ' ', $t_value ) );
			$t_front = preg_replace_callback( '/^' . preg_quote( $t_key, '/' ) . ':.*$/m',
				function() use ( $t_line ) { return $t_line; }, $t_front, 1 );
		}
	}
	foreach( $p_body as $t_label => $t_value ) {
		$t_line = '**' . $t_label . ':** ' . str_replace( "\n", ' ', $t_value );
		$t_body = preg_replace_callback( '/^\*\*' . preg_quote( $t_label, '/' ) . ':\*\*.*$/m',
			function() use ( $t_line ) { return $t_line; }, $t_body, 1 );
	}
	return $t_front === '' ? $t_body : "---\n" . $t_front . "\n---\n" . $t_body;
}

/**
 * Replace the rows of the first table under the record's "Invitees" heading.
 * The header and separator rows are kept; each new row is padded to the
 * header's column count.
 *
 * @param string $p_content
 * @param array  $p_rows    Each a list of cell values.
 * @return string Unchanged when no such table exists.
 */
function meeting_record_set_invitees_table( string $p_content, array $p_rows ): string {
	$t_lines = explode( "\n", $p_content );
	$t_heading = null;
	foreach( $t_lines as $i => $t_line ) {
		if( preg_match( '/^#{1,6}\s.*\bInvitees\b/i', $t_line ) ) {
			$t_heading = $i;
			break;
		}
	}
	if( $t_heading === null ) {
		return $p_content;
	}
	$t_start = null;
	for( $i = $t_heading + 1; $i < count( $t_lines ); $i++ ) {
		if( preg_match( '/^#{1,6}\s/', $t_lines[$i] ) ) {
			return $p_content;   # next section reached without a table
		}
		if( strpos( ltrim( $t_lines[$i] ), '|' ) === 0 ) {
			$t_start = $i;
			break;
		}
	}
	if( $t_start === null || !isset( $t_lines[$t_start + 1] ) ) {
		return $p_content;
	}
	$t_end = $t_start + 2;
	while( $t_end < count( $t_lines ) && strpos( ltrim( $t_lines[$t_end] ), '|' ) === 0 ) {
		$t_end++;
	}
	$t_columns = max( 1, substr_count( trim( $t_lines[$t_start] ), '|' ) - 1 );
	$t_new = array();
	foreach( $p_rows as $t_row ) {
		$t_cells = array_pad( array_slice( array_values( $t_row ), 0, $t_columns ), $t_columns, '' );
		$t_cells = array_map( function( $p_cell ) {
			return str_replace( array( '|', "\n" ), array( '\|', ' ' ), (string)$p_cell );
		}, $t_cells );
		$t_new[] = '| ' . implode( ' | ', $t_cells ) . ' |';
	}
	array_splice( $t_lines, $t_start + 2, $t_end - $t_start - 2, $t_new );
	return implode( "\n", $t_lines );
}

/**
 * Store a new revision of the record and put it On Record at once, on the
 * chair's or organiser's authority (agenda changes, cancellation).
 *
 * @param array  $p_meeting
 * @param string $p_content
 * @param int    $p_user_id
 * @param string $p_description
 * @return void
 */
function meeting_record_revise( array $p_meeting, string $p_content, int $p_user_id, string $p_description ): void {
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	if( $t_dwg_id === 0 || $p_content === '' || $p_content === meeting_record_content( $p_meeting ) ) {
		return;
	}
	meeting_store_record( $p_meeting, $p_content, $p_user_id, $p_description, false );
	file_dwg_primary_sync_head( $t_dwg_id, $p_user_id );
}


# ═══════════════════════════════════════════════════════════════════════════════
# Managing a meeting (chair or organiser, while only the agenda is issued)
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Invitee table row for the record.
 *
 * @param array $p_invitee {meeting_invitee} row with resolved name.
 * @return array
 */
function meeting_invitee_table_row( array $p_invitee ): array {
	$t_role = '—';
	$t_uid = (int)$p_invitee['user_id'];
	if( $t_uid > 0 && user_exists( $t_uid ) ) {
		$t_parts = array_filter( array(
			(string)user_get_field( $t_uid, 'position_title' ),
			(string)user_get_field( $t_uid, 'department' ),
		), function( $p_s ) { return !is_blank( $p_s ); } );
		$t_role = empty( $t_parts ) ? '—' : implode( ' / ', $t_parts );
	}
	return array( $p_invitee['name'], $t_role, 'Required' );
}

/**
 * Display name with position, as the template writes chair/minute taker.
 *
 * @param int $p_user_id
 * @return string
 */
function meeting_user_name_with_title( int $p_user_id ): string {
	$t_title = (string)user_get_field( $p_user_id, 'position_title' );
	return meeting_user_display_name( $p_user_id ) . ( is_blank( $t_title ) ? '' : ', ' . $t_title );
}

/**
 * Reschedule a meeting and/or change its minute taker and invitees. The
 * agenda record is rewritten and put On Record; invitees receive the updated
 * agenda with a calendar update, and removed invitees a cancellation.
 *
 * @param array $p_meeting
 * @param int   $p_user_id      Acting chair or organiser.
 * @param array $p_fields       Any of date_start, duration, location, minute_taker_id.
 * @param int[] $p_add_user_ids Users to invite (must accept meeting invitations).
 * @param array $p_add_guests   Guest names to invite.
 * @param int[] $p_remove_ids   {meeting_invitee}.id values to remove.
 * @return array{changed: bool, notified: array, removed: array}
 * @throws ClientException when not permitted or values are invalid.
 */
function meeting_change( array $p_meeting, int $p_user_id, array $p_fields, array $p_add_user_ids, array $p_add_guests, array $p_remove_ids ): array {
	if( !meeting_user_can_manage( $p_meeting, $p_user_id ) ) {
		throw new ClientException( 'Only the chair or organiser can change ' . $p_meeting['doc_ref']
			. ', and only before minutes are written', ERROR_ACCESS_DENIED );
	}
	$t_id = (int)$p_meeting['id'];

	# ── Validate and collect field changes ───────────────────────────────────
	$t_update = array();
	if( isset( $p_fields['date_start'] ) && (int)$p_fields['date_start'] !== (int)$p_meeting['date_start'] ) {
		$t_update['date_start'] = (int)$p_fields['date_start'];
	}
	if( isset( $p_fields['duration'] ) && (int)$p_fields['duration'] !== (int)$p_meeting['duration'] ) {
		$t_duration = (int)$p_fields['duration'];
		if( $t_duration < 5 || $t_duration > 600 ) {
			throw new ClientException( 'Duration must be 5–600 minutes', ERROR_INVALID_FIELD_VALUE, array( 'duration' ) );
		}
		$t_update['duration'] = $t_duration;
	}
	if( isset( $p_fields['location'] ) && trim( $p_fields['location'] ) !== $p_meeting['location'] ) {
		$t_update['location'] = substr( trim( $p_fields['location'] ), 0, 128 );
	}

	# ── Invitees ─────────────────────────────────────────────────────────────
	$t_before = meeting_invitees_get( $t_id );
	$t_current_ids = array_map( 'intval', array_column( $t_before, 'user_id' ) );
	$t_removed_users = array();
	foreach( $t_before as $t_invitee ) {
		if( in_array( (int)$t_invitee['id'], $p_remove_ids, true ) ) {
			meeting_invitee_remove( $t_id, (int)$t_invitee['id'] );
			if( (int)$t_invitee['user_id'] > 0 ) {
				$t_removed_users[] = (int)$t_invitee['user_id'];
			}
		}
	}
	foreach( $p_add_user_ids as $t_uid ) {
		$t_uid = (int)$t_uid;
		if( $t_uid <= 0 || in_array( $t_uid, $t_current_ids, true ) || in_array( $t_uid, $t_removed_users, true ) ) {
			continue;
		}
		if( !user_exists( $t_uid ) || (int)user_get_field( $t_uid, 'meeting_invite' ) === 0 ) {
			throw new ClientException( 'User ' . $t_uid . ' does not accept meeting invitations',
				ERROR_INVALID_FIELD_VALUE, array( 'invitee' ) );
		}
		meeting_invitee_add( $t_id, $t_uid, meeting_user_display_name( $t_uid ) );
	}
	foreach( $p_add_guests as $t_guest ) {
		if( !is_blank( $t_guest ) ) {
			meeting_invitee_add( $t_id, 0, trim( $t_guest ) );
		}
	}
	$t_after = meeting_invitees_get( $t_id );
	$t_invitees_changed = array_column( $t_before, 'id' ) !== array_column( $t_after, 'id' );

	# Minute taker: an invitee with an account, or none.
	if( array_key_exists( 'minute_taker_id', $p_fields ) && (int)$p_fields['minute_taker_id'] !== (int)$p_meeting['minute_taker_id'] ) {
		$t_taker = (int)$p_fields['minute_taker_id'];
		if( $t_taker !== 0 && !in_array( $t_taker, array_map( 'intval', array_column( $t_after, 'user_id' ) ), true ) ) {
			throw new ClientException( 'The minute taker must be an invitee', ERROR_INVALID_FIELD_VALUE, array( 'minute_taker_id' ) );
		}
		$t_update['minute_taker_id'] = $t_taker;
	}

	if( empty( $t_update ) && !$t_invitees_changed ) {
		return array( 'changed' => false, 'notified' => array(), 'removed' => array() );
	}

	$t_update['sequence'] = (int)$p_meeting['sequence'] + 1;
	meeting_update( $t_id, $t_update );
	$t_meeting = meeting_get( $t_id );

	# ── Rewrite the agenda record ────────────────────────────────────────────
	$t_content = meeting_record_content( $t_meeting );
	if( $t_content !== '' ) {
		$t_start = (int)$t_meeting['date_start'];
		$t_end = $t_start + 60 * (int)$t_meeting['duration'];
		$t_time = date( 'H:i', $t_start ) . ' – ' . date( 'H:i', $t_end );
		$t_taker = (int)$t_meeting['minute_taker_id'] > 0
			? meeting_user_name_with_title( (int)$t_meeting['minute_taker_id'] ) : '—';
		$t_content = meeting_record_set( $t_content,
			array( 'date' => date( 'Y-m-d', $t_start ), 'time' => $t_time,
				'location' => $t_meeting['location'], 'minute_taker' => $t_taker ),
			array( 'Date' => date( 'j F Y', $t_start ), 'Time' => $t_time,
				'Location' => $t_meeting['location'], 'Minute Taker' => $t_taker ) );
		if( $t_invitees_changed ) {
			$t_content = meeting_record_set_invitees_table( $t_content,
				array_map( 'meeting_invitee_table_row', $t_after ) );
		}
		meeting_record_revise( $t_meeting, $t_content, $p_user_id, 'Agenda updated' );
	}

	# ── Notify ───────────────────────────────────────────────────────────────
	$t_notified = meeting_email_agenda( $t_meeting, meeting_record_content( $t_meeting ), true );
	$t_removed = meeting_email_send( $t_meeting, $t_removed_users,
		'Removed from meeting: ' . $t_meeting['doc_ref'] . ' — ' . $t_meeting['title'],
		'You are no longer invited to ' . $t_meeting['title'] . ', '
			. date( 'l j F Y, H:i', (int)$t_meeting['date_start'] ) . '.',
		'', array(), 'CANCEL' );

	return array( 'changed' => true, 'notified' => $t_notified, 'removed' => $t_removed );
}

/**
 * Cancel a meeting: status Cancelled, record stamped, invitees sent a
 * calendar cancellation.
 *
 * @param array  $p_meeting
 * @param int    $p_user_id Acting chair or organiser.
 * @param string $p_reason  Optional note for the invitees.
 * @return array Recipients notified.
 * @throws ClientException when not permitted.
 */
function meeting_cancel( array $p_meeting, int $p_user_id, string $p_reason = '' ): array {
	if( !meeting_user_can_manage( $p_meeting, $p_user_id ) ) {
		throw new ClientException( 'Only the chair or organiser can cancel ' . $p_meeting['doc_ref']
			. ', and only before minutes are written', ERROR_ACCESS_DENIED );
	}
	$t_id = (int)$p_meeting['id'];
	meeting_update( $t_id, array( 'status' => MEETING_CANCELLED, 'sequence' => (int)$p_meeting['sequence'] + 1 ) );
	$t_meeting = meeting_get( $t_id );

	$t_content = meeting_record_content( $t_meeting );
	if( $t_content !== '' ) {
		meeting_record_revise( $t_meeting, meeting_record_set( $t_content,
			array( 'status' => 'Cancelled' ), array( 'Status' => 'Cancelled' ) ),
			$p_user_id, 'Meeting cancelled' );
	}

	$t_ids = array();
	foreach( meeting_invitees_get( $t_id ) as $t_invitee ) {
		if( (int)$t_invitee['user_id'] > 0 ) {
			$t_ids[] = (int)$t_invitee['user_id'];
		}
	}
	return meeting_email_send( $t_meeting, $t_ids,
		'Cancelled: ' . $t_meeting['doc_ref'] . ' — ' . $t_meeting['title'],
		$t_meeting['title'] . ', ' . date( 'l j F Y, H:i', (int)$t_meeting['date_start'] )
			. ', has been cancelled by ' . meeting_user_display_name( $p_user_id ) . '.'
			. ( is_blank( $p_reason ) ? '' : "\n\nReason: " . trim( $p_reason ) ),
		'', array(), 'CANCEL' );
}


# ═══════════════════════════════════════════════════════════════════════════════
# Hand uploads to a meeting document (treated as minutes)
# ═══════════════════════════════════════════════════════════════════════════════

/**
 * Why a user may not upload a new revision of a meeting document by hand,
 * or null when allowed (or the document is not a meeting record). Hand
 * uploads count as draft minutes: only the chair, minute taker or organiser,
 * and only before the minutes are approved.
 *
 * @param int $p_dwg_id
 * @param int $p_user_id
 * @return string|null
 */
function meeting_primary_upload_refusal( int $p_dwg_id, int $p_user_id ): ?string {
	$t_meeting = meeting_get_by_dwg( $p_dwg_id );
	if( $t_meeting === null ) {
		return null;
	}
	if( !in_array( (int)$t_meeting['status'], array( MEETING_AGENDA, MEETING_MINUTES ), true ) ) {
		return 'This is the record of meeting ' . $t_meeting['doc_ref'] . ', which is '
			. strtolower( meeting_status_label( (int)$t_meeting['status'] ) ) . '; it can no longer be changed.';
	}
	if( !meeting_user_can_write_minutes( $t_meeting, $p_user_id ) ) {
		return 'This is the record of meeting ' . $t_meeting['doc_ref']
			. '; only its chair, minute taker or organiser can upload minutes.';
	}
	return null;
}

/**
 * After a hand upload to a meeting document: the upload is the draft
 * minutes. Moves the meeting to minutes awaiting approval and circulates it.
 *
 * @param int $p_dwg_id
 * @param int $p_user_id Uploader.
 * @return void
 */
function meeting_primary_uploaded( int $p_dwg_id, int $p_user_id ): void {
	$t_meeting = meeting_get_by_dwg( $p_dwg_id );
	if( $t_meeting === null || !file_dwg_primary_draft_get( $p_dwg_id ) ) {
		return;
	}
	meeting_update( (int)$t_meeting['id'], array( 'status' => MEETING_MINUTES ) );
	$t_meeting = meeting_get( (int)$t_meeting['id'] );
	meeting_email_draft_minutes( $t_meeting, meeting_record_content( $t_meeting ), $p_user_id );
}

/**
 * Create the Doctis document record for a meeting via the command layer.
 *
 * @param array $p_meeting    Meeting row.
 * @param int   $p_project_id
 * @return int New dwg id.
 */
function meeting_document_create( array $p_meeting, int $p_project_id ): int {
	$t_category = (string)config_get_global( 'meeting_category' );
	if( category_get_id_by_name( $t_category, $p_project_id, false ) === false ) {
		category_add( $p_project_id, $t_category );
	}

	$t_soap_dir = dirname( __DIR__ ) . '/api/soap/';
	require_once( $t_soap_dir . 'mc_core.php' );   # mci_* helpers used by DwgAddCommand

	$t_command = new DwgAddCommand( array( 'payload' => array( 'issue' => array(
		'project'     => array( 'id' => $p_project_id ),
		'category'    => array( 'name' => $t_category ),
		'summary'     => substr( $p_meeting['title'], 0, 128 ),
		'title'       => $p_meeting['title'],
		'number'      => $p_meeting['doc_ref'],
		'author'      => meeting_user_display_name( (int)$p_meeting['chair_id'] ),
		'description' => 'Meeting record created by the Doctis Meeting Assistant.',
		'view_state'  => array( 'id' => VS_PUBLIC ),
		'custom_fields' => array(),
	) ) ) );
	$t_result = $t_command->execute();
	return (int)$t_result['issue_id'];
}


# ═══════════════════════════════════════════════════════════════════════════════
# Email
# ═══════════════════════════════════════════════════════════════════════════════
#
# Email is how participants read meeting records: they need not be members of
# the (typically private) meeting project. Every message carries the full
# record; a document link is added only for recipients who can open it.
#
#   agenda issued     → invitees
#   minutes drafted   → all participants except the author, for corrections
#                       (TMPL-SYS-001: 3 business days); the chair is also
#                       asked to approve
#   minutes approved  → all participants except the approver

/**
 * The record without its YAML frontmatter (for email bodies).
 *
 * @param string $p_content
 * @return string
 */
function meeting_record_body( string $p_content ): string {
	return ltrim( preg_replace( '/\A---\n[\s\S]*?\n---\n/', '', $p_content, 1 ) );
}

/**
 * Timestamp $p_days business days (Mon–Fri) after $p_from.
 *
 * @param int $p_from Unix timestamp.
 * @param int $p_days
 * @return int
 */
function meeting_business_days_after( int $p_from, int $p_days ): int {
	$t_ts = $p_from;
	while( $p_days > 0 ) {
		$t_ts = strtotime( '+1 day', $t_ts );
		if( (int)date( 'N', $t_ts ) <= 5 ) {
			$p_days--;
		}
	}
	return $t_ts;
}

/**
 * Enabled users taking part in a meeting: chair, minute taker, and invitees
 * with accounts (in that order, without duplicates).
 *
 * @param array $p_meeting Meeting row.
 * @return int[]
 */
function meeting_participant_ids( array $p_meeting ): array {
	$t_ids = array( (int)$p_meeting['chair_id'], (int)$p_meeting['minute_taker_id'] );
	foreach( meeting_invitees_get( (int)$p_meeting['id'] ) as $t_invitee ) {
		$t_ids[] = (int)$t_invitee['user_id'];
	}
	$t_result = array();
	foreach( array_unique( $t_ids ) as $t_id ) {
		if( $t_id > 0 && user_exists( $t_id ) && user_is_enabled( $t_id ) ) {
			$t_result[] = $t_id;
		}
	}
	return $t_result;
}

/**
 * Send one meeting email to each user.
 *
 * @param array  $p_meeting  Meeting row.
 * @param int[]  $p_user_ids Recipients.
 * @param string $p_subject  Subject (prefixed "[Doctis] ").
 * @param string $p_intro    Opening paragraph; may contain "{note}" for the
 *                           recipient-specific line from $p_extra.
 * @param string $p_content  Record (frontmatter is stripped); '' for none.
 * @param array  $p_extra    user_id => extra line for that recipient.
 * @param string $p_ics      Calendar method to attach (REQUEST, CANCEL) or ''.
 * @return array Each ['name' => ..., 'email' => ...].
 */
function meeting_email_send( array $p_meeting, array $p_user_ids, string $p_subject, string $p_intro, string $p_content, array $p_extra = array(), string $p_ics = '' ): array {
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	$t_body = meeting_record_body( $p_content );
	$t_attachments = array();
	if( $p_ics !== '' ) {
		$t_attachments[] = array(
			'content'  => meeting_ics( $p_meeting, $p_ics ),
			'filename' => ( $p_ics === 'CANCEL' ? 'cancel.ics' : 'invite.ics' ),
			'type'     => 'text/calendar; charset=utf-8; method=' . $p_ics,
		);
	}
	$t_sent = array();
	foreach( $p_user_ids as $t_uid ) {
		$t_email = meeting_user_notify_email( $t_uid );
		if( is_blank( $t_email ) ) {
			continue;
		}

		$t_text = trim( str_replace( '{note}', $p_extra[$t_uid] ?? '', $p_intro ) ) . "\n\n"
			. 'Meeting: ' . config_get_global( 'path' ) . 'meeting_view_page.php?id=' . (int)$p_meeting['id'] . "\n";
		if( $t_dwg_id > 0 && dwg_exists( $t_dwg_id )
			&& access_has_dwg_level( config_get( 'view_dwg_threshold' ), $t_dwg_id, $t_uid ) ) {
			$t_text .= 'Document: ' . string_get_dwg_view_url_with_fqdn( $t_dwg_id ) . "\n";
		}
		if( $t_body !== '' ) {
			$t_text .= "\n" . $t_body;
		}

		meeting_email_queue( $t_email, '[Doctis] ' . $p_subject, $t_text, $t_attachments );
		$t_sent[] = array( 'name' => meeting_user_display_name( $t_uid ), 'email' => $t_email );
	}
	return $t_sent;
}

/**
 * Queue one email, optionally with attachments (as email_store(), which has
 * no attachment parameter; email_send() passes metadata['attachments'] on).
 *
 * @param string $p_recipient
 * @param string $p_subject
 * @param string $p_message
 * @param array  $p_attachments Each ['content', 'filename', 'type'].
 * @return int|null Queue id, or null when email is disabled.
 */
function meeting_email_queue( string $p_recipient, string $p_subject, string $p_message, array $p_attachments = array() ): ?int {
	global $g_email_shutdown_processing;
	require_api( 'email_api.php' );

	if( is_blank( $p_recipient ) || OFF == config_get( 'enable_email_notification' ) ) {
		return null;
	}
	$t_email_data = new EmailData;
	$t_email_data->email = trim( $p_recipient );
	$t_email_data->subject = string_email( trim( $p_subject ) );
	$t_email_data->body = string_email_links( trim( $p_message ) );
	$t_email_data->metadata = array(
		'headers' => array(), 'cc' => array(), 'bcc' => array(),
		'charset' => 'utf-8', 'attachments' => $p_attachments,
	);
	$t_id = email_queue_add( $t_email_data );
	$g_email_shutdown_processing |= EMAIL_SHUTDOWN_GENERATED;
	return $t_id;
}

/**
 * Email the agenda to the meeting's invitees with accounts, with a calendar
 * invitation.
 *
 * @param array  $p_meeting
 * @param string $p_content Agenda record.
 * @param bool   $p_update  True when the meeting was changed after issue.
 * @return array Recipients.
 */
function meeting_email_agenda( array $p_meeting, string $p_content, bool $p_update = false ): array {
	$t_ids = array();
	foreach( meeting_invitees_get( (int)$p_meeting['id'] ) as $t_invitee ) {
		$t_uid = (int)$t_invitee['user_id'];
		if( $t_uid > 0 && user_exists( $t_uid ) && user_is_enabled( $t_uid ) ) {
			$t_ids[] = $t_uid;
		}
	}
	$t_when = date( 'l j F Y, H:i', (int)$p_meeting['date_start'] ) . ' (' . $p_meeting['duration'] . ' min), '
		. $p_meeting['location'];
	return meeting_email_send( $p_meeting, $t_ids,
		( $p_update ? 'Meeting Updated: ' : 'Meeting Agenda: ' ) . $p_meeting['doc_ref'] . ' — ' . $p_meeting['title'],
		( $p_update
			? $p_meeting['title'] . ' has been updated. It is now ' . $t_when . '.'
			: 'You are invited to ' . $p_meeting['title'] . ', ' . $t_when . '.' )
			. ' Chair: ' . meeting_user_display_name( (int)$p_meeting['chair_id'] ) . '.',
		$p_content, array(), 'REQUEST' );
}

/**
 * Circulate draft minutes for corrections; ask the chair to approve.
 *
 * @param array  $p_meeting
 * @param string $p_content   Draft minutes record.
 * @param int    $p_author_id User who wrote the minutes (not emailed).
 * @return array Recipients.
 */
function meeting_email_draft_minutes( array $p_meeting, string $p_content, int $p_author_id ): array {
	$t_ids = array_values( array_diff( meeting_participant_ids( $p_meeting ), array( $p_author_id ) ) );
	$t_deadline = date( 'l j F Y', meeting_business_days_after( time(), 3 ) );
	$t_chair_id = (int)$p_meeting['chair_id'];
	return meeting_email_send( $p_meeting, $t_ids,
		'Draft Minutes: ' . $p_meeting['doc_ref'] . ' — ' . $p_meeting['title'],
		'Draft minutes of ' . $p_meeting['title'] . ' have been written by '
			. meeting_user_display_name( $p_author_id ) . '. Please send any corrections to them by '
			. $t_deadline . '. {note}',
		$p_content,
		array( $t_chair_id => 'As chair, approve the minutes from the meeting page once corrections are in.' ) );
}

/**
 * Circulate the approved minutes.
 *
 * @param array  $p_meeting
 * @param string $p_content     Approved minutes record ('' = notice only).
 * @param int    $p_approver_id Chair who approved (not emailed).
 * @return array Recipients.
 */
function meeting_email_approved_minutes( array $p_meeting, string $p_content, int $p_approver_id ): array {
	$t_ids = array_values( array_diff( meeting_participant_ids( $p_meeting ), array( $p_approver_id ) ) );
	return meeting_email_send( $p_meeting, $t_ids,
		'Approved Minutes: ' . $p_meeting['doc_ref'] . ' — ' . $p_meeting['title'],
		'The minutes of ' . $p_meeting['title'] . ' have been approved by '
			. meeting_user_display_name( $p_approver_id ) . ' and are now the controlled record.',
		$p_content );
}
