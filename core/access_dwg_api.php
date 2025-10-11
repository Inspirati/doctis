<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Access API
 *
 * @package CoreAPI
 * @subpackage AccessAPI
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses authentication_api.php
 * @uses bug_api.php
 * @uses bugnote_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses database_api.php
 * @uses error_api.php
 * @uses helper_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses project_api.php
 * @uses string_api.php
 * @uses user_api.php
 */

require_api( 'access_inc_api.php' );
require_api( 'authentication_api.php' );
require_api( 'dwg_api.php' );
require_api( 'dwgnote_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'database_api.php' );
require_api( 'error_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

use Mantis\Exceptions\ClientException;

/**
 * Check the current user's access against the given value and return true
 * if the user's access is equal to or higher, false otherwise.
 * This function looks up the bug's project and performs an access check
 * against that project
 * @param integer      $p_access_level Integer representing access level.
 * @param integer      $p_bug_id       Integer representing bug id to check access against.
 * @param integer|null $p_user_id      Integer representing user id, defaults to null to use current user.
 * @return boolean whether user has access level specified
 * @access public
 */
function access_has_dwg_level( $p_access_level, $p_bug_id, $p_user_id = null ) {
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Deal with not logged in silently in this case
	# @@@ we may be able to remove this and just error
	#     and once we default to anon login, we can remove it for sure
	if( empty( $p_user_id ) && !auth_is_user_authenticated() ) {
		return false;
	}

	# Check the requested access level, shortcut to fail if not satisfied
	$t_project_id = dwg_get_field( $p_bug_id, 'project_id' );
	$t_access_level = access_get_project_level( $t_project_id, $p_user_id );
	if( !access_compare_level( $t_access_level, $p_access_level ) ){
		return false;
	}

	# If the level is met, we still need to verify that user has access to the issue

	# Check if the bug is private
	$t_bug_is_user_creator = dwg_is_user_creator( $p_bug_id, $p_user_id );
	if( !$t_bug_is_user_creator && dwg_get_field( $p_bug_id, 'view_state' ) == VS_PRIVATE ) {
		$t_private_bug_threshold = config_get( 'private_dwg_threshold', null, $p_user_id, $t_project_id );
		if( !access_compare_level( $t_access_level, $t_private_bug_threshold ) ) {
			return false;
		}
	}

	# Check special limits
	# Limited view means this user can only view the issues they reported, is handling, or monitoring
	if( access_has_limited_view_dwg( $t_project_id, $p_user_id ) ) {
		$t_allowed = $t_bug_is_user_creator;
		if( !$t_allowed ) {
			$t_allowed = dwg_is_user_handler( $p_bug_id, $p_user_id );
		}
		if( !$t_allowed ) {
			$t_allowed = user_is_monitoring_dwg( $p_user_id, $p_bug_id );
		}
		if( !$t_allowed ) {
			return false;
		}
	}

	return true;
}

/**
 * Filter the provided array of user ids to those who has the specified access level for the
 * specified bug.
 * @param  int $p_access_level   The access level.
 * @param  int $p_bug_id         The bug id.
 * @param  array $p_user_ids     The array of user ids.
 * @return array filtered array of user ids.
 */
function access_has_dwg_level_filter( $p_access_level, $p_bug_id, $p_user_ids ) {
	$t_users_ids_with_access = array();
	foreach( $p_user_ids as $t_user_id ) {
		if( access_has_dwg_level( $p_access_level, $p_bug_id, $t_user_id ) ) {
			$t_users_ids_with_access[] = $t_user_id;
		}
	}

	return $t_users_ids_with_access;
}

/**
 * Check if the user has the specified access level for the given bug
 * and deny access to the page if not
 * @see access_has_bug_level
 * @param integer      $p_access_level Integer representing access level.
 * @param integer      $p_bug_id       Integer representing bug id to check access against.
 * @param integer|null $p_user_id      Integer representing user id, defaults to null to use current user.
 * @return void
 * @access public
 */
function access_ensure_dwg_level( $p_access_level, $p_bug_id, $p_user_id = null ) {
	if( !access_has_dwg_level( $p_access_level, $p_bug_id, $p_user_id ) ) {
		access_denied();
	}
}

/**
 * Check the current user's access against the given value and return true
 * if the user's access is equal to or higher, false otherwise.
 * This function looks up the bugnote's bug and performs an access check
 * against that bug
 * @param integer      $p_access_level Integer representing access level.
 * @param integer      $p_bugnote_id   Integer representing bugnote id to check access against.
 * @param integer|null $p_user_id      Integer representing user id, defaults to null to use current user.
 * @return boolean whether user has access level specified
 * @access public
 */
function access_has_dwgnote_level( $p_access_level, $p_bugnote_id, $p_user_id = null ) {
	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}

	$t_bug_id = dwgnote_get_field( $p_bugnote_id, 'dwg_id' );
	$t_project_id = dwg_get_field( $t_bug_id, 'project_id' );

	# If the bug is private and the user is not the creator, then the
	# the user must also have higher access than private_bug_threshold
	if( dwgnote_get_field( $p_bugnote_id, 'view_state' ) == VS_PRIVATE && !dwgnote_is_user_creator( $p_bugnote_id, $p_user_id ) ) {
		$t_private_bugnote_threshold = config_get( 'private_dwgnote_threshold', null, $p_user_id, $t_project_id );
		$p_access_level = max( $p_access_level, $t_private_bugnote_threshold );
	}

	return access_has_dwg_level( $p_access_level, $t_bug_id, $p_user_id );
}

/**
 * Filter the provided array of user ids to those who has the specified access level for the
 * specified bugnote.
 * @param  int $p_access_level   The access level.
 * @param  int $p_bugnote_id     The bugnote id.
 * @param  array $p_user_ids     The array of user ids.
 * @return array filtered array of user ids.
 */
function access_has_dwgnote_level_filter( $p_access_level, $p_bugnote_id, $p_user_ids ) {
	$t_users_ids_with_access = array();
	foreach( $p_user_ids as $t_user_id ) {
		if( access_has_dwgnote_level( $p_access_level, $p_bugnote_id, $t_user_id ) ) {
			$t_users_ids_with_access[] = $t_user_id;
		}
	}

	return $t_users_ids_with_access;
}

/**
 * Check if the user has the specified access level for the given bugnote
 * and deny access to the page if not
 * @see access_has_dwgnote_level
 * @param integer      $p_access_level Integer representing access level.
 * @param integer      $p_bugnote_id   Integer representing bugnote id to check access against.
 * @param integer|null $p_user_id      Integer representing user id, defaults to null to use current user.
 * @access public
 * @return void
 */
function access_ensure_dwgnote_level( $p_access_level, $p_bugnote_id, $p_user_id = null ) {
	if( !access_has_dwgnote_level( $p_access_level, $p_bugnote_id, $p_user_id ) ) {
		access_denied();
	}
}

/**
 * Check if the specified bug can be closed
 * @param BugData      $p_bug     Bug to check access against.
 * @param integer|null $p_user_id Integer representing user id, defaults to null to use current user.
 * @return boolean true if user can close the bug
 * @access public
 */
function access_can_close_dwg( DwgData $p_bug, $p_user_id = null ) {
	if( dwg_is_closed( $p_bug->id ) ) {
		# Can't close a bug that's already closed
		return false;
	}

	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}

	# If allow_creator_close is enabled, then creators can close their own bugs
	# if they are in resolved status
	if( ON == config_get( 'allow_creator_close', null, null, $p_bug->project_id )
		&& dwg_is_user_creator( $p_bug->id, $p_user_id )
		&& dwg_is_resolved( $p_bug->id )
	) {
		return true;
	}

	$t_closed_status = config_get( 'dwg_closed_status_threshold', null, null, $p_bug->project_id );
	$t_closed_status_threshold = access_get_dwg_status_threshold( $t_closed_status, $p_bug->project_id );
	return access_has_dwg_level( $t_closed_status_threshold, $p_bug->id, $p_user_id );
}

/**
 * Make sure that the user can close the specified bug
 * @see access_can_close_bug
 * @param BugData      $p_bug     Bug to check access against.
 * @param integer|null $p_user_id Integer representing user id, defaults to null to use current user.
 * @access public
 * @return void
 */
function access_ensure_can_close_dwg( DwgData $p_bug, $p_user_id = null ) {
	if( !access_can_close_dwg( $p_bug, $p_user_id ) ) {
		access_denied();
	}
}

/**
 * Check if the specified bug can be reopened
 * @param BugData      $p_bug     Bug to check access against.
 * @param integer|null $p_user_id Integer representing user id, defaults to null to use current user.
 * @return boolean whether user has access to reopen bugs
 * @access public
 */
function access_can_reopen_dwg( DwgData $p_bug, $p_user_id = null ) {
	if( !dwg_is_resolved( $p_bug->id ) ) {
		# Can't reopen a bug that's not resolved
		return false;
	}

	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	$t_reopen_status = config_get( 'dwg_reopen_status', null, null, $p_bug->project_id );

	# Reopen status must be reachable by workflow
	if( !dwg_check_workflow( $p_bug->status, $t_reopen_status ) ) {
		return false;
	}

	# If allow_creator_reopen is enabled, then creators can always reopen
	# their own bugs as long as their access level is creator or above
	if( ON == config_get( 'allow_creator_reopen', null, null, $p_bug->project_id )
		&& dwg_is_user_creator( $p_bug->id, $p_user_id )
		&& access_has_project_level( config_get( 'create_dwg_threshold', null, $p_user_id, $p_bug->project_id ), $p_bug->project_id, $p_user_id )
	) {
		return true;
	}

	# Other users's access level must allow them to reopen bugs
	$t_reopen_bug_threshold = config_get( 'reopen_dwg_threshold', null, null, $p_bug->project_id );
	if( access_has_dwg_level( $t_reopen_bug_threshold, $p_bug->id, $p_user_id ) ) {

		# User must be allowed to change status to reopen status
		$t_reopen_status_threshold = access_get_dwg_status_threshold( $t_reopen_status, $p_bug->project_id );
		return access_has_dwg_level( $t_reopen_status_threshold, $p_bug->id, $p_user_id );
	}

	return false;
}

/**
 * Make sure that the user can reopen the specified bug.
 * Calls access_denied if user has no access to terminate script
 * @see access_can_reopen_bug
 * @param BugData      $p_bug     Bug to check access against.
 * @param integer|null $p_user_id Integer representing user id, defaults to null to use current user.
 * @access public
 * @return void
 */
function access_ensure_can_reopen_dwg( DwgData $p_bug, $p_user_id = null ) {
	if( !access_can_reopen_dwg( $p_bug, $p_user_id ) ) {
		access_denied();
	}
}

/**
 * get the access level required to change the issue to the new status
 * If there is no specific differentiated access level, use the
 * generic update_bug_status_threshold.
 * @param integer $p_status     Status.
 * @param integer $p_project_id Default value ALL_PROJECTS.
 * @return integer integer representing user level e.g. DEVELOPER
 * @access public
 */
function access_get_dwg_status_threshold( $p_status, $p_project_id = ALL_PROJECTS ) {
	$t_thresh_array = config_get( 'set_status_threshold', null, null, $p_project_id );
	if( isset( $t_thresh_array[(int)$p_status] ) ) {
		return (int)$t_thresh_array[(int)$p_status];
	} else {
		if( $p_status == config_get( 'dwg_submit_status', null, null, $p_project_id ) ) {
			return config_get( 'create_dwg_threshold', null, null, $p_project_id );
		} else {
			return config_get( 'update_dwg_status_threshold', null, null, $p_project_id );
		}
	}
}

/**
 * Checks if the user can view the handler for the bug.
 * @param BugData      $p_bug     Bug to check access against.
 * @param integer|null $p_user_id Integer representing user id, defaults to null to use current user.
 * @return boolean whether user can view the handler user.
 */
function access_can_see_handler_for_dwg( DwgData $p_bug, $p_user_id = null ) {
	if( null === $p_user_id ) {
		$t_user_id = auth_get_current_user_id();
	} else {
		$t_user_id = $p_user_id;
	}

	# handler can be viewed if allowed by access level, OR the user himself is the handler
	$t_can_view_handler =
		( $p_bug->handler_id == $t_user_id )
		|| access_has_dwg_level(
			config_get( 'view_handler_threshold', null, $t_user_id, $p_bug->project_id ),
			$p_bug->id );

	return $t_can_view_handler;
}

/**
 * Returns true if the user has limited view to issues in the specified project.
 *
 * @param integer $p_project_id   Project id, or null for current project
 * @param integer $p_user_id      User id, or null for current user
 * @return boolean	Whether limited view applies
 *
 * @see $g_limit_view_unless_threshold
 * @see $g_limit_creators
 */
function access_has_limited_view_dwg( $p_project_id = null, $p_user_id = null ) {
	$t_user_id = ( null === $p_user_id ) ? auth_get_current_user_id() : $p_user_id;
	$t_project_id = ( null === $p_project_id ) ? helper_get_current_project() : $p_project_id;

	# Old 'limit_reporters' option was previously only supported for ALL_PROJECTS,
	# Use this option if set, otherwise, check the new option for "unlimited view" threshold
	$t_old_limit_reporters = config_get( 'limit_creators', null, $t_user_id, ALL_PROJECTS );
	$t_threshold_can_view = NOBODY;
	if( ON != $t_old_limit_reporters ) {
		$t_threshold_can_view = config_get( 'limit_view_unless_threshold', null, $t_user_id, $t_project_id );
	} else {
		# If old 'limit_reporters' option is enabled, use that setting
		# Note that the effective threshold can vary for each project, based on
		# the reporting threshold configuration.
		# To improve performance, esp. when processing for several projects, we
		# build a static array holding that threshold for each project
		static $s_thresholds = array();
		if( !isset( $s_thresholds[$t_project_id] ) ) {
			$t_create_dwg_threshold = config_get( 'create_dwg_threshold', null, $t_user_id, $t_project_id );
			if( empty( $t_create_dwg_threshold ) ) {
				$s_thresholds[$t_project_id] = NOBODY;
			} else {
				$s_thresholds[$t_project_id] = access_threshold_min_level( $t_create_dwg_threshold ) + 1;
			}
		}
		$t_threshold_can_view = $s_thresholds[$t_project_id];
	}

	$t_project_level = access_get_project_level( $p_project_id, $p_user_id );
	return !access_compare_level( $t_project_level, $t_threshold_can_view );
}

/**
 * Return true if user is allowed to view bug revisions.
 *
 * User must have $g_dwg_revision_view_threshold or be the bug's creator.
 *
 * @param int $p_bug_id
 * @param int $p_user_id
 *
 * @return bool
 */
function access_can_view_dwg_revisions( $p_bug_id, $p_user_id = null ) {
	if( !dwg_exists( $p_bug_id ) ) {
		return false;
	}
	$t_project_id = dwg_get_field( $p_bug_id, 'project_id' );
	$t_user_id = null === $p_user_id ? auth_get_current_user_id() : $p_user_id;

	$t_has_access = access_has_dwg_level(
		config_get( 'dwg_revision_view_threshold', null, $t_user_id, $t_project_id ),
		$p_bug_id,
		$t_user_id
	);

	return $t_has_access || dwg_is_user_creator( $p_bug_id, $t_user_id );
}

/**
 * Return true if user is allowed to view dwgnote revisions.
 *
 * User must have $g_dwg_revision_view_threshold or be the dwgnote's creator.
 *
 * @param int $p_bugnote_id
 * @param int $p_user_id
 *
 * @return bool
 */
function access_can_view_dwgnote_revisions( $p_bugnote_id, $p_user_id = null ) {
	if( !dwgnote_exists( $p_bugnote_id ) ) {
		return false;
	}
	$t_bug_id = dwgnote_get_field( $p_bugnote_id, 'dwg_id' );
	$t_project_id = dwg_get_field( $t_bug_id, 'project_id' );
	$t_user_id = null === $p_user_id ? auth_get_current_user_id() : $p_user_id;

	$t_has_access = access_has_dwgnote_level(
		config_get( 'dwg_revision_view_threshold', null, $t_user_id, $t_project_id ),
		$p_bugnote_id,
		$t_user_id
	);


	return $t_has_access || dwgnote_is_user_creator( $p_bugnote_id, $t_user_id );
}
