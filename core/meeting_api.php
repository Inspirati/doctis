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
# Tables: {meeting}, {meeting_invitee} (admin/schema.php steps 60–61).
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
 * invitee), newest first. Each row gains a 'role' key.
 *
 * @param int $p_user_id
 * @return array
 */
function meeting_get_for_user( int $p_user_id ): array {
	db_param_push();
	$t_result = db_query(
		'SELECT m.* FROM {meeting} m'
		. ' WHERE m.status <> ' . db_param()
		. ' AND ( m.chair_id=' . db_param() . ' OR m.minute_taker_id=' . db_param()
		. ' OR m.created_by=' . db_param()
		. ' OR EXISTS ( SELECT 1 FROM {meeting_invitee} i'
		. ' WHERE i.meeting_id = m.id AND i.user_id=' . db_param() . ' ) )'
		. ' ORDER BY m.date_start DESC',
		array( MEETING_CANCELLED, $p_user_id, $p_user_id, $p_user_id, $p_user_id )
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
		. ' ( doc_ref, title, department, chair_id, minute_taker_id, date_start,'
		. '   duration, location, status, created_by, date_created, date_updated )'
		. ' VALUES ( ' . implode( ', ', array_fill( 0, 12, db_param() ) ) . ' )',
		array(
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
		db_param_push();
		db_query(
			'INSERT INTO {meeting_invitee} ( meeting_id, user_id, name, attendance )'
			. ' VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
			array( $t_meeting_id, (int)$t_invitee['user_id'], substr( $t_invitee['name'], 0, 128 ), MEETING_INVITED )
		);
	}
	return $t_meeting_id;
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
	exec(
		'git --git-dir=' . escapeshellarg( $t_bare ) . ' show ' . escapeshellarg( 'HEAD:' . $t_path ) . ' 2>/dev/null',
		$t_out, $t_rc
	);
	return $t_rc === 0 ? implode( "\n", $t_out ) : null;
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
	exec(
		'git --git-dir=' . escapeshellarg( $t_bare ) . ' show '
		. escapeshellarg( $t_row['git_sha'] . ':' . $t_row['git_path'] ) . ' 2>/dev/null',
		$t_out, $t_rc
	);
	return $t_rc === 0 ? implode( "\n", $t_out ) : '';
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
 * @return array{dwg_id: int, git_path: string, git_sha: string, staged: bool}
 * @throws ClientException when no project is configured or access is denied.
 */
function meeting_store_record( array $p_meeting, string $p_content, int $p_user_id, string $p_description ): array {
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
		access_ensure_dwg_level( config_get( 'update_dwg_threshold' ), $t_dwg_id, $p_user_id );
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
 * @return void
 * @throws ClientException when the user is not the chair or nothing awaits approval.
 */
function meeting_minutes_approve( array $p_meeting, int $p_user_id, string $p_expected_sha = '' ): void {
	if( !meeting_user_can_approve_minutes( $p_meeting, $p_user_id ) ) {
		throw new ClientException(
			'Only the chair can approve draft minutes for ' . $p_meeting['doc_ref'],
			ERROR_ACCESS_DENIED
		);
	}
	$t_dwg_id = (int)$p_meeting['dwg_id'];
	if( $t_dwg_id > 0 && file_dwg_primary_draft_get( $t_dwg_id ) ) {
		file_dwg_primary_sync_head( $t_dwg_id, $p_user_id, $p_expected_sha );
	}
	meeting_update( (int)$p_meeting['id'], array( 'status' => MEETING_APPROVED ) );
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
