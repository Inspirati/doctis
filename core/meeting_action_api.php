<?php
# Doctis — meeting actions
#
# Actions are captured with the minutes (the assistant supplies them as a
# structured list next to the document) and stored in {meeting_action}. When
# the chair approves the minutes, each action becomes a Doctis issue in the
# meeting project, linked to the meeting document (so the issue records the
# approved minutes' SHA). Open actions of earlier meetings in a series are
# offered to the next agenda's "open actions review".
#
# Owners are validated against the meeting's participants. An owner is set as
# the issue's handler only when they may handle issues in the meeting project
# and the approver may assign; otherwise the owner is named in the issue text.
# Due dates follow $g_due_date_update_threshold and are always written in the
# issue text.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'access_api.php' );
require_api( 'bug_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'meeting_api.php' );
require_api( 'user_api.php' );

/** Most actions accepted from one set of minutes. */
const MEETING_ACTIONS_MAX = 50;

/**
 * Actions of a meeting, in recorded order.
 *
 * @param int $p_meeting_id
 * @return array
 */
function meeting_actions_get( int $p_meeting_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting_action} WHERE meeting_id=' . db_param() . ' ORDER BY id',
		array( $p_meeting_id ) );
	$t_rows = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Validate actions supplied by the assistant (JSON list of objects with
 * ref, action, owner_id, owner, due). Unknown owners become name-only;
 * invalid due dates are dropped; empty actions are skipped.
 *
 * @param string $p_json
 * @param array  $p_meeting
 * @return array Each ['ref', 'description', 'owner_id', 'owner_name', 'due_date'].
 */
function meeting_actions_parse( string $p_json, array $p_meeting ): array {
	$t_items = json_decode( trim( $p_json ), true );
	if( !is_array( $t_items ) ) {
		return array();
	}
	$t_participants = meeting_participant_ids( $p_meeting );
	$t_actions = array();
	foreach( array_values( $t_items ) as $i => $t_item ) {
		if( !is_array( $t_item ) || count( $t_actions ) >= MEETING_ACTIONS_MAX ) {
			continue;
		}
		$t_text = trim( (string)( $t_item['action'] ?? '' ) );
		if( $t_text === '' ) {
			continue;
		}
		$t_owner_id = (int)( $t_item['owner_id'] ?? 0 );
		if( !in_array( $t_owner_id, $t_participants, true ) ) {
			$t_owner_id = 0;
		}
		$t_due = DateTime::createFromFormat( '!Y-m-d', trim( (string)( $t_item['due'] ?? '' ) ) );
		$t_ref = trim( (string)( $t_item['ref'] ?? '' ) );
		$t_actions[] = array(
			'ref'         => mb_substr( $t_ref !== '' ? $t_ref : 'A' . ( $i + 1 ), 0, 16 ),
			'description' => mb_substr( $t_text, 0, 1024 ),
			'owner_id'    => $t_owner_id,
			'owner_name'  => $t_owner_id > 0
				? meeting_user_display_name( $t_owner_id )
				: mb_substr( trim( (string)( $t_item['owner'] ?? '' ) ), 0, 128 ),
			'due_date'    => $t_due === false ? 0 : $t_due->getTimestamp(),
		);
	}
	return $t_actions;
}

/**
 * Replace a meeting's actions that have no issue yet.
 *
 * @param int   $p_meeting_id
 * @param array $p_actions From meeting_actions_parse().
 * @return void
 */
function meeting_actions_replace( int $p_meeting_id, array $p_actions ): void {
	db_param_push();
	db_query( 'DELETE FROM {meeting_action} WHERE meeting_id=' . db_param() . ' AND bug_id=0', array( $p_meeting_id ) );
	foreach( $p_actions as $t_action ) {
		db_param_push();
		db_query(
			'INSERT INTO {meeting_action} ( meeting_id, ref, description, owner_id, owner_name, due_date, bug_id )'
			. ' VALUES ( ' . implode( ', ', array_fill( 0, 7, db_param() ) ) . ' )',
			array( $p_meeting_id, $t_action['ref'], $t_action['description'], (int)$t_action['owner_id'],
				$t_action['owner_name'], (int)$t_action['due_date'], 0 )
		);
	}
}

/**
 * Create an issue for each of the meeting's actions that has none, in the
 * meeting project, linked to the meeting document. Called on approval; the
 * acting user (the chair) is the reporter. Failures are reported per action
 * and do not stop the others.
 *
 * @param array $p_meeting
 * @param int   $p_user_id Acting user (must be the logged-in user).
 * @return array Each ['ref', 'bug_id', 'assigned' => bool, 'error' => string|null].
 */
function meeting_actions_create_issues( array $p_meeting, int $p_user_id ): array {
	$t_project_id = (int)$p_meeting['project_id'] ?: meeting_project_id( $p_meeting['department'] );
	$t_actions = array_filter( meeting_actions_get( (int)$p_meeting['id'] ), function( $p_a ) {
		return (int)$p_a['bug_id'] === 0;
	} );
	if( $t_project_id === 0 || empty( $t_actions ) ) {
		return array();
	}

	$t_category = (string)config_get_global( 'meeting_category' );
	if( category_get_id_by_name( $t_category, $t_project_id, false ) === false ) {
		category_add( $t_project_id, $t_category );
	}
	require_once( dirname( __DIR__ ) . '/api/soap/mc_core.php' );   # mci_* helpers used by IssueAddCommand

	$t_can_assign = access_has_project_level( config_get( 'update_bug_assign_threshold', null, null, $t_project_id ),
		$t_project_id, $p_user_id );
	$t_results = array();
	foreach( $t_actions as $t_action ) {
		$t_owner = (int)$t_action['owner_id'];
		$t_assign = $t_owner > 0 && $t_can_assign
			&& access_has_project_level( config_get( 'handle_bug_threshold', null, null, $t_project_id ), $t_project_id, $t_owner );
		$t_due = (int)$t_action['due_date'];
		$t_issue = array(
			'project'       => array( 'id' => $t_project_id ),
			'category'      => array( 'name' => $t_category ),
			'summary'       => mb_substr( '[' . $p_meeting['doc_ref'] . ' ' . $t_action['ref'] . '] ' . $t_action['description'], 0, 128 ),
			'description'   => meeting_action_issue_text( $p_meeting, $t_action ),
			'custom_fields' => array(),
		);
		if( $t_assign ) {
			$t_issue['handler'] = array( 'id' => $t_owner );
		}
		if( $t_due > 0 ) {
			$t_issue['due_date'] = date( 'Y-m-d', $t_due );
		}
		if( (int)$p_meeting['dwg_id'] > 0 ) {
			$t_issue['document'] = array( 'id' => (int)$p_meeting['dwg_id'] );
		}
		try {
			$t_command = new IssueAddCommand( array( 'payload' => array( 'issue' => $t_issue ) ) );
			$t_result = $t_command->execute();
			$t_bug_id = (int)$t_result['issue_id'];
			db_param_push();
			db_query( 'UPDATE {meeting_action} SET bug_id=' . db_param() . ' WHERE id=' . db_param(),
				array( $t_bug_id, (int)$t_action['id'] ) );
			$t_results[] = array( 'ref' => $t_action['ref'], 'bug_id' => $t_bug_id, 'assigned' => $t_assign, 'error' => null );
		} catch( Throwable $e ) {
			error_log( 'meeting_action_api: issue for ' . $p_meeting['doc_ref'] . ' ' . $t_action['ref'] . ' failed: ' . $e->getMessage() );
			$t_results[] = array( 'ref' => $t_action['ref'], 'bug_id' => 0, 'assigned' => false, 'error' => $e->getMessage() );
		}
	}
	return $t_results;
}

/**
 * Issue description for an action.
 *
 * @param array $p_meeting
 * @param array $p_action
 * @return string
 */
function meeting_action_issue_text( array $p_meeting, array $p_action ): string {
	return 'Action ' . $p_action['ref'] . ' from the approved minutes of ' . $p_meeting['title']
		. ' (' . $p_meeting['doc_ref'] . '), ' . date( 'j F Y', (int)$p_meeting['date_start'] ) . ".\n\n"
		. $p_action['description'] . "\n\n"
		. 'Owner: ' . ( is_blank( $p_action['owner_name'] ) ? 'not named' : $p_action['owner_name'] ) . "\n"
		. 'Due: ' . ( (int)$p_action['due_date'] > 0 ? date( 'j F Y', (int)$p_action['due_date'] ) : 'not set' );
}

/**
 * Whether an action is still open: no issue yet, or an issue below the
 * resolved threshold.
 *
 * @param array $p_action
 * @return bool
 */
function meeting_action_is_open( array $p_action ): bool {
	$t_bug_id = (int)$p_action['bug_id'];
	if( $t_bug_id === 0 || !bug_exists( $t_bug_id ) ) {
		return $t_bug_id === 0;
	}
	return (int)bug_get_field( $t_bug_id, 'status' ) < (int)config_get( 'bug_resolved_status_threshold' );
}

/**
 * Open actions from meetings of the series whose minutes are approved,
 * oldest first. Each row gains 'doc_ref'.
 *
 * @param array $p_meeting Any meeting of the series.
 * @return array
 */
function meeting_actions_open_in_series( array $p_meeting ): array {
	$t_open = array();
	foreach( meeting_series_get( $p_meeting ) as $t_m ) {
		if( (int)$t_m['status'] !== MEETING_APPROVED ) {
			continue;
		}
		foreach( meeting_actions_get( (int)$t_m['id'] ) as $t_action ) {
			if( meeting_action_is_open( $t_action ) ) {
				$t_action['doc_ref'] = $t_m['doc_ref'];
				$t_open[] = $t_action;
			}
		}
	}
	return $t_open;
}
