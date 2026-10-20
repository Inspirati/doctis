<?php
# Doctis - Document Issue Tracking System, a fork of MantisBT

# Doctis is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# Doctis is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Transfer what a user still holds to a successor.
 *
 * A staff member who leaves is removed by **disabling** their account, never
 * by deleting it (owner decision 2026-10-20, doc/dwg/USER_LIFECYCLE.md).
 * Disabling preserves their name on everything they touched, but it does not
 * hand on what they were responsible for: documents they own or hold, issues
 * assigned to them, meetings they are due to chair, open meeting actions,
 * Topic expectations they own, and categories that auto-assign to them.
 *
 * This API lists those holdings, suggests a successor, and moves them.
 *
 * **The successor is chosen by an administrator, not derived.**
 * {user}.reports_to supplies the *default* suggestion only: it is optional
 * (most accounts have none), it is editable by the user themselves, and the
 * chain can contain accounts disabled after the fact. So it is treated as a
 * convenience, never as authority (owner decision 2026-10-20).
 *
 * Historical attribution is never transferred: creator_id, history rows,
 * notes, tags, revisions and the git commit trail all keep the original name.
 *
 * @package Doctis
 */

require_api( 'access_api.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'dwg_api.php' );
require_api( 'history_api.php' );
require_api( 'history_dwg_api.php' );
require_api( 'lang_api.php' );
# MEETING_AGENDA / MEETING_MINUTES / MEETING_CANCELLED are defined here.
require_api( 'meeting_api.php' );
require_api( 'topic_expectation_api.php' );
require_api( 'user_api.php' );

/**
 * The categories of holding that can be transferred.
 *
 * Each entry has:
 *   label      language string naming it
 *   eligible   what the successor must satisfy: 'own_dwg', 'handle_dwg',
 *              'handle_bug' or '' for no requirement beyond being enabled
 *
 * @return array Keyed by category name.
 */
function user_transfer_categories() {
	return array(
		'dwg_owner' => array(
			'label' => 'user_transfer_dwg_owner',
			'eligible' => 'own_dwg',
		),
		'dwg_handler' => array(
			'label' => 'user_transfer_dwg_handler',
			'eligible' => 'handle_dwg',
		),
		'bug_handler' => array(
			'label' => 'user_transfer_bug_handler',
			'eligible' => 'handle_bug',
		),
		'meeting_chair' => array(
			'label' => 'user_transfer_meeting_chair',
			'eligible' => '',
		),
		'meeting_minutes' => array(
			'label' => 'user_transfer_meeting_minutes',
			'eligible' => '',
		),
		'meeting_action' => array(
			'label' => 'user_transfer_meeting_action',
			'eligible' => '',
		),
		'topic_expectation' => array(
			'label' => 'user_transfer_topic_expectation',
			'eligible' => '',
		),
		'category_default' => array(
			'label' => 'user_transfer_category_default',
			'eligible' => 'handle_dwg',
		),
	);
}

/**
 * The row ids a user still holds, per category.
 *
 * Only live holdings: approved and cancelled meetings are historical records
 * and are left alone, and a meeting action that has already become an issue
 * is counted under bug_handler instead, not twice.
 *
 * @param int $p_user_id A user identifier.
 *
 * @return array Keyed by category name; each value an array of row ids.
 */
function user_transfer_holdings( $p_user_id ) {
	$c_user_id = (int)$p_user_id;
	$t_held = array();

	foreach( array_keys( user_transfer_categories() ) as $t_key ) {
		$t_held[$t_key] = array();
	}

	$t_queries = array(
		'dwg_owner'   => 'SELECT id FROM {dwg} WHERE owner_id=' . db_param(),
		'dwg_handler' => 'SELECT id FROM {dwg} WHERE handler_id=' . db_param(),
		'bug_handler' => 'SELECT id FROM {bug} WHERE handler_id=' . db_param(),
		'meeting_chair' => 'SELECT id FROM {meeting} WHERE chair_id=' . db_param()
			. ' AND status IN ( ' . MEETING_AGENDA . ', ' . MEETING_MINUTES . ' )',
		'meeting_minutes' => 'SELECT id FROM {meeting} WHERE minute_taker_id=' . db_param()
			. ' AND status IN ( ' . MEETING_AGENDA . ', ' . MEETING_MINUTES . ' )',
		'meeting_action' => 'SELECT a.id FROM {meeting_action} a JOIN {meeting} m ON m.id = a.meeting_id'
			. ' WHERE a.owner_id=' . db_param() . ' AND a.bug_id = 0 AND m.status <> ' . MEETING_CANCELLED,
		'topic_expectation' => 'SELECT id FROM {topic_expectation} WHERE owner_id=' . db_param()
			. ' AND enabled = 1',
		'category_default' => 'SELECT id FROM {category} WHERE user_id=' . db_param(),
	);

	foreach( $t_queries as $t_key => $t_sql ) {
		db_param_push();
		$t_result = db_query( $t_sql, array( $c_user_id ) );
		while( $t_row = db_fetch_array( $t_result ) ) {
			$t_held[$t_key][] = (int)$t_row['id'];
		}
	}

	return $t_held;
}

/**
 * How many live holdings a user has, per category, plus a total.
 *
 * @param int $p_user_id A user identifier.
 *
 * @return array Category name => count, plus 'total'.
 */
function user_transfer_summary( $p_user_id ) {
	$t_counts = array();
	$t_total = 0;

	foreach( user_transfer_holdings( $p_user_id ) as $t_key => $t_ids ) {
		$t_counts[$t_key] = count( $t_ids );
		$t_total += count( $t_ids );
	}

	$t_counts['total'] = $t_total;
	return $t_counts;
}

/**
 * Does this user still hold anything that ought to be handed on?
 *
 * @param int $p_user_id A user identifier.
 *
 * @return bool
 */
function user_transfer_has_holdings( $p_user_id ) {
	$t_summary = user_transfer_summary( $p_user_id );
	return $t_summary['total'] > 0;
}

/**
 * Suggest a successor: the first enabled account up the user's reports_to
 * chain.
 *
 * A convenience default only (see the file header). Returns 0 when the user
 * has no manager, or when every link in the chain is disabled or missing —
 * which is the common case, because reports_to is optional. The caller must
 * cope with 0 by asking the administrator to choose.
 *
 * Disabled links are skipped rather than ending the walk, because
 * user_ensure_valid_reports_to() only validates at write time: a manager
 * disabled later still has reports pointing at them.
 *
 * @param int $p_user_id A user identifier.
 *
 * @return int A user id, or 0 if none could be suggested.
 */
function user_transfer_suggest_successor( $p_user_id ) {
	$t_seen = array();
	$t_id = (int)user_get_field( $p_user_id, 'reports_to' );

	while( $t_id > 0 && !isset( $t_seen[$t_id] ) ) {
		$t_seen[$t_id] = true;

		if( !user_exists( $t_id ) ) {
			return 0;
		}

		if( user_is_enabled( $t_id ) ) {
			return $t_id;
		}

		$t_id = (int)user_get_field( $t_id, 'reports_to' );
	}

	return 0;
}

/**
 * Is the proposed successor acceptable for each category?
 *
 * Eligibility is per item, not per user: a manager one step up the chain may
 * be able to own documents but not to hold the licence a particular one
 * requires. A category is reported as ineligible when the successor fails the
 * requirement for *any* of its rows, with the count that would be skipped.
 *
 * @param int $p_user_id A user identifier (the outgoing holder).
 * @param int $p_to_user_id The proposed successor.
 *
 * @return array Category name => array( 'ok' => int, 'skip' => int ).
 */
function user_transfer_eligibility( $p_user_id, $p_to_user_id ) {
	$t_categories = user_transfer_categories();
	$t_holdings = user_transfer_holdings( $p_user_id );
	$t_result = array();

	foreach( $t_holdings as $t_key => $t_ids ) {
		$t_ok = 0;
		$t_skip = 0;

		foreach( $t_ids as $t_id ) {
			if( user_transfer_row_allowed( $t_key, $t_id, $p_to_user_id, $t_categories[$t_key]['eligible'] ) ) {
				$t_ok++;
			} else {
				$t_skip++;
			}
		}

		$t_result[$t_key] = array( 'ok' => $t_ok, 'skip' => $t_skip );
	}

	return $t_result;
}

/**
 * May this successor take over this particular row?
 *
 * @param string $p_category    Category name.
 * @param int    $p_row_id      The row's identifier.
 * @param int    $p_to_user_id  The proposed successor.
 * @param string $p_requirement '', 'own_dwg', 'handle_dwg' or 'handle_bug'.
 *
 * @return bool
 */
function user_transfer_row_allowed( $p_category, $p_row_id, $p_to_user_id, $p_requirement ) {
	if( !user_exists( $p_to_user_id ) || !user_is_enabled( $p_to_user_id ) ) {
		return false;
	}

	switch( $p_requirement ) {
		case 'own_dwg':
			# Threshold and any license the document requires.
			return access_dwg_can_be_owner( $p_row_id, $p_to_user_id );

		case 'handle_dwg':
			$t_project_id = ( $p_category === 'category_default' )
				? (int)category_get_field( $p_row_id, 'project_id' )
				: (int)dwg_get_field( $p_row_id, 'project_id' );
			return access_has_project_level(
				config_get( 'handle_dwg_threshold' ), $t_project_id, $p_to_user_id );

		case 'handle_bug':
			$t_project_id = (int)bug_get_field( $p_row_id, 'project_id' );
			return access_has_project_level(
				config_get( 'handle_bug_threshold' ), $t_project_id, $p_to_user_id );
	}

	return true;
}

/**
 * Move a user's holdings to a successor.
 *
 * Rows the successor is not eligible for are left where they are and counted
 * as skipped, so a partial transfer is reported honestly rather than failing
 * the whole operation.
 *
 * Every document and issue change is written through the normal set-field
 * path, so each one lands in {dwg_history} / {bug_history} attributed to the
 * administrator performing the transfer.
 *
 * @param int   $p_user_id    The outgoing holder.
 * @param int   $p_to_user_id The successor.
 * @param array $p_categories Category names to transfer; empty means all.
 *
 * @return array Category name => array( 'moved' => int, 'skipped' => int ).
 */
function user_transfer_perform( $p_user_id, $p_to_user_id, array $p_categories = array() ) {
	$t_categories = user_transfer_categories();
	$t_wanted = empty( $p_categories ) ? array_keys( $t_categories ) : $p_categories;
	$t_holdings = user_transfer_holdings( $p_user_id );
	$t_report = array();

	$c_to = (int)$p_to_user_id;
	$t_to_name = user_exists( $c_to ) ? user_get_name( $c_to ) : '';

	foreach( $t_wanted as $t_key ) {
		if( !isset( $t_categories[$t_key] ) ) {
			continue;
		}

		$t_moved = 0;
		$t_skipped = 0;

		foreach( $t_holdings[$t_key] as $t_id ) {
			if( !user_transfer_row_allowed( $t_key, $t_id, $c_to, $t_categories[$t_key]['eligible'] ) ) {
				$t_skipped++;
				continue;
			}

			switch( $t_key ) {
				case 'dwg_owner':
					dwg_set_field( $t_id, 'owner_id', $c_to );
					break;

				case 'dwg_handler':
					dwg_set_field( $t_id, 'handler_id', $c_to );
					break;

				case 'bug_handler':
					bug_set_field( $t_id, 'handler_id', $c_to );
					break;

				case 'meeting_chair':
					db_param_push();
					db_query( 'UPDATE {meeting} SET chair_id=' . db_param() . ' WHERE id=' . db_param(),
						array( $c_to, $t_id ) );
					break;

				case 'meeting_minutes':
					db_param_push();
					db_query( 'UPDATE {meeting} SET minute_taker_id=' . db_param() . ' WHERE id=' . db_param(),
						array( $c_to, $t_id ) );
					break;

				case 'meeting_action':
					# owner_name is denormalised beside owner_id; keep them in step.
					db_param_push();
					db_query( 'UPDATE {meeting_action} SET owner_id=' . db_param()
						. ', owner_name=' . db_param() . ' WHERE id=' . db_param(),
						array( $c_to, $t_to_name, $t_id ) );
					break;

				case 'topic_expectation':
					# Through the API, so the change is versioned and logged.
					$t_row = topic_expectation_get( $t_id );
					topic_expectation_update( $t_id, $t_row['statement'], $c_to,
						(int)$t_row['milestone_id'], (int)$t_row['maturity'] );
					break;

				case 'category_default':
					category_update( $t_id, category_get_field( $t_id, 'name' ), $c_to );
					break;
			}

			$t_moved++;
		}

		$t_report[$t_key] = array( 'moved' => $t_moved, 'skipped' => $t_skipped );
	}

	user_clear_cache( $p_user_id );
	user_clear_cache( $c_to );

	return $t_report;
}
