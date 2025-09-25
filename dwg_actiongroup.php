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
 * This page allows actions to be performed an an array of bugs
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses bug_api.php
 * @uses bugnote_api.php
 * @uses category_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses custom_field_api.php
 * @uses event_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses string_api.php
 * @uses utility_api.php
 * @uses version_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'dwg_api.php' );
require_api( 'bugnote_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'custom_field_api.php' );
//require_api( 'email_dwg_api.php' );
require_api( 'event_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'string_api.php' );
require_api( 'utility_api.php' );
require_api( 'version_api.php' );

auth_ensure_user_authenticated();
helper_begin_long_process();

$f_action	= gpc_get_string( 'action' );
$f_custom_field_id = gpc_get_int( 'custom_field_id', 0 );
$f_bug_arr	= gpc_get_int_array( 'dwg_arr', array() );
$f_bug_notetext = gpc_get_string( 'bugnote_text', '' );
$f_bug_noteprivate = gpc_get_bool( 'private' );
$t_form_name = 'dwg_actiongroup_' . $f_action;
form_security_validate( $t_form_name );

$t_custom_group_actions = config_get( 'custom_group_actions' );

foreach( $t_custom_group_actions as $t_custom_group_action ) {
	if( $f_action == $t_custom_group_action['action'] ) {
		require_once( $t_custom_group_action['action_page'] );
		exit;
	}
}

$t_failed_ids = array();

if( 0 != $f_custom_field_id ) {
	$t_custom_field_def = custom_field_get_definition( $f_custom_field_id );
}

foreach( $f_bug_arr as $t_bug_id ) {
	dwg_ensure_exists( $t_bug_id );
	$t_bug = dwg_get( $t_bug_id, true );

	if( $t_bug->project_id != helper_get_current_project() ) {
		# in case the current project is not the same project of the bug we are viewing...
		# ... override the current project. This to avoid problems with categories and handlers lists etc.
		$g_project_override = $t_bug->project_id;
		# @todo (thraxisp) the next line goes away if the cache was smarter and used project
		config_flush_cache(); # flush the config cache so that configs are refetched
	}

	# Make sure user has access to the bug
	access_ensure_dwg_level( config_get( 'view_dwg_threshold' ), $t_bug_id );

	$t_status = $t_bug->status;

	switch( $f_action ) {
		case 'CLOSE':
			$t_closed = config_get( 'dwg_closed_status_threshold' );
			if( access_can_close_dwg( $t_bug ) ) {
				if( ( $t_status < $t_closed ) &&
					dwg_check_workflow( $t_status, $t_closed ) ) {

				# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $f_bug_id, $t_bug_data, $f_bugnote_text ) );
				dwg_close( $t_bug_id, $f_bug_notetext, $f_bug_noteprivate );
				helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
			} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_status' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'DELETE':
			if( access_has_dwg_level( config_get( 'delete_dwg_threshold' ), $t_bug_id ) ) {
				$t_data = array( 'query' => array( 'id' => $t_bug_id ) );
				$t_command = new DocumentDeleteCommand( $t_data );
				$t_command->execute();
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'MOVE':
			$f_project_id = gpc_get_int( 'project_id' );
			if( access_has_dwg_level( config_get( 'move_dwg_threshold' ), $t_bug_id ) &&
				access_has_project_level( config_get( 'create_dwg_threshold', null, null, $f_project_id ), $f_project_id ) ) {
				# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );

				# Add bugnote if supplied
				if( !is_blank( $f_bug_notetext ) ) {
					$t_bugnote_id = dwgnote_add( $t_bug_id, $f_bug_notetext, null, $f_bug_noteprivate );
					dwgnote_process_mentions( $t_bug_id, $t_bugnote_id, $f_bug_notetext );
				}
				dwg_move( $t_bug_id, $f_project_id );
				helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'COPY':
			$f_project_id = gpc_get_int( 'project_id' );

			if( access_has_project_level( config_get( 'create_dwg_threshold' ), $f_project_id ) ) {
				# Copy everything except history
				dwg_copy( $t_bug_id, $f_project_id, true, true, false, true, true, true );
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'ASSIGN':
			$f_assign = gpc_get_int( 'assign' );
			$t_assign_status = dwg_get_status_for_assign( $t_bug->handler_id, $f_assign, $t_status );
			# check that new handler has rights to handle the document, and
			#  that current user has rights to assign the document
			$t_threshold = access_get_dwg_status_threshold( $t_assign_status, $t_bug->project_id );
			if( access_has_dwg_level( config_get( 'update_dwg_assign_threshold', config_get( 'update_dwg_threshold' ) ), $t_bug_id ) ) {
				# The new handler is checked at project level
				if( access_has_project_level( config_get( 'handle_dwg_threshold' ), $t_bug->project_id, $f_assign ) ) {
					if( dwg_check_workflow( $t_status, $t_assign_status ) ) {
						# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
						dwg_assign( $t_bug_id, $f_assign, $f_bug_notetext, $f_bug_noteprivate );
						helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
					} else {
						$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_status' );
					}
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_handler' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'RESOLVE':
			$t_resolved_status = config_get( 'dwg_resolved_status_threshold' );
			if( access_has_dwg_level( access_get_dwg_status_threshold( $t_resolved_status, $t_bug->project_id ), $t_bug_id ) ) {
				if( ( $t_status < $t_resolved_status ) &&
					dwg_check_workflow( $t_status, $t_resolved_status )
				) {
					$f_resolution = gpc_get_int( 'resolution' );
					$f_fixed_in_version = gpc_get_string( 'fixed_in_version', '' );
					# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
					dwg_resolve( $t_bug_id, $f_resolution, $f_fixed_in_version, $f_bug_notetext, null, null, $f_bug_noteprivate );
					helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_status' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_PRIOR':
			if( access_has_dwg_level( config_get( 'update_dwg_threshold' ), $t_bug_id ) ) {
				$f_priority = gpc_get_int( 'priority' );
				# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
				dwg_set_field( $t_bug_id, 'priority', $f_priority );
				email_dwg_updated( $t_bug_id );
				helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_STATUS':
			$f_status = gpc_get_int( 'status' );
			if( access_has_dwg_level( access_get_dwg_status_threshold( $f_status, $t_bug->project_id ), $t_bug_id ) ) {
				if( true == dwg_check_workflow( $t_status, $f_status ) ) {
					# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
					dwg_set_field( $t_bug_id, 'status', $f_status );

					# Add bugnote if supplied
					if( !is_blank( $f_bug_notetext ) ) {
						$t_bugnote_id = dwgnote_add( $t_bug_id, $f_bug_notetext, null, $f_bug_noteprivate );
						dwgnote_process_mentions( $t_bug_id, $t_bugnote_id, $f_bug_notetext );
						# No need to call email_generic(), dwgnote_add() does it
					} else {
						email_dwg_updated( $t_bug_id );
					}

					helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_status' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_CATEGORY':
			$f_category_id = gpc_get_int( 'category' );
			if( access_has_dwg_level( config_get( 'update_dwg_threshold' ), $t_bug_id ) ) {
				if( category_exists( $f_category_id )
					|| $f_category_id == 0 && config_get( 'allow_no_category' )
				) {
					# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
					dwg_set_field( $t_bug_id, 'category_id', $f_category_id );
					email_dwg_updated( $t_bug_id );
					helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_category' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_PRODUCT_VERSION':
			$f_product_version = gpc_get_string( 'product_version' );
			if( access_has_dwg_level( config_get( 'update_dwg_threshold' ), $t_bug_id ) ) {
				if( $f_product_version === '' || version_get_id( $f_product_version, $t_bug->project_id ) !== false ) {
					/** @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) ); */
					dwg_set_field( $t_bug_id, 'version', $f_product_version );
					email_dwg_updated( $t_bug_id );
					helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'bug_actiongroup_version' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_FIXED_IN_VERSION':
			$f_fixed_in_version = gpc_get_string( 'fixed_in_version' );
			if( access_has_dwg_level( config_get( 'update_dwg_threshold' ), $t_bug_id ) ) {
				if( $f_fixed_in_version === '' || version_get_id( $f_fixed_in_version, $t_bug->project_id ) !== false ) {
					# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
					dwg_set_field( $t_bug_id, 'fixed_in_version', $f_fixed_in_version );
					email_dwg_updated( $t_bug_id );
					helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
					} else {
						$t_failed_ids[$t_bug_id] = lang_get( 'bug_actiongroup_version' );
				}
				} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_TARGET_VERSION':
			$f_target_version = gpc_get_string( 'target_version' );
			if( access_has_dwg_level( config_get( 'roadmap_update_threshold' ), $t_bug_id ) ) {
				if( $f_target_version === '' || version_get_id( $f_target_version, $t_bug->project_id ) !== false ) {
					# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
					dwg_set_field( $t_bug_id, 'target_version', $f_target_version );
					email_dwg_updated( $t_bug_id );
					helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_version' );
				}
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'UP_DUE_DATE':
			$t_due_date = gpc_get_string( 'due_date', null );
			if( $t_due_date !== null ) {
				$t_due_date = date_strtotime( $t_due_date );

				if( access_has_dwg_level( config_get( 'due_date_update_threshold' ), $t_bug_id ) ) {
					dwg_set_field( $t_bug_id, 'due_date', $t_due_date );
				} else {
					$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
				}
			}
			break;
		case 'VIEW_STATUS':
			if( access_has_dwg_level( config_get( 'change_view_status_threshold' ), $t_bug_id ) ) {
				$f_view_status = gpc_get_int( 'view_status' );
				# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
				dwg_set_field( $t_bug_id, 'view_state', $f_view_status );
				email_dwg_updated( $t_bug_id );
				helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'SET_STICKY':
			if( access_has_dwg_level( config_get( 'set_dwg_sticky_threshold' ), $t_bug_id ) ) {
				$f_sticky = dwg_get_field( $t_bug_id, 'sticky' );
				# The new value is the inverted old value
				# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
				dwg_set_field( $t_bug_id, 'sticky', intval( !$f_sticky ) );
				helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
			} else {
				$t_failed_ids[$t_bug_id] = lang_get( 'dwg_actiongroup_access' );
			}
			break;
		case 'CUSTOM':
			if( 0 === $f_custom_field_id ) {
				trigger_error( ERROR_GENERIC, ERROR );
			}

			# @todo we need to issue a helper_call_custom_function( 'document_update_validate', array( $t_bug_id, $t_bug_data, $f_bugnote_text ) );
			$t_form_var = 'custom_field_' . $f_custom_field_id;
			$t_custom_field_value = gpc_get_custom_field( $t_form_var, $t_custom_field_def['type'], null );
			custom_field_set_value( $f_custom_field_id, $t_bug_id, $t_custom_field_value );
			dwg_update_date( $t_bug_id );
			email_dwg_updated( $t_bug_id );
			helper_call_custom_function( 'document_update_notify', array( $t_bug_id ) );
			break;
		default:
			trigger_error( ERROR_GENERIC, ERROR );
	}

	# Bug Action Event
	event_signal( 'EVENT_DWG_ACTION', array( $f_action, $t_bug_id ) );
}

form_security_purge( $t_form_name );

if( count( $t_failed_ids ) > 0 ) {
	require_css( 'status_config.php' );
	dwg_group_action_print_top();
	dwg_group_action_print_results( $t_failed_ids );
	dwg_group_action_print_bottom();
} else {
	print_dwg_header_redirect( 'view_dwg_page.php' );
}
