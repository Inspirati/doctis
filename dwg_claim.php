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
 * POST handler — claim or release a document's owner or assignee.
 *
 * This handler only ever writes the *current user's* id, or NO_USER to
 * release.  Setting either field to somebody else goes through dwg_update.php,
 * which needs a higher threshold.  Keeping the two apart is what lets a
 * lower-privileged user claim a document without also gaining the ability to
 * assign a third party.  See doc/dwg/OWNERSHIP.md §6.1.
 *
 * Parameters:
 *   dwg_id  A document identifier.
 *   role    'owner' or 'assignee'.
 *   action  'claim' or 'release'.
 *
 * @package Doctis
 *
 * @uses core.php
 * @uses access_dwg_api.php
 * @uses authentication_api.php
 * @uses config_api.php
 * @uses dwg_api.php
 * @uses email_dwg_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses history_dwg_api.php
 * @uses print_dwg_api.php
 */

require_once( 'core.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'dwg_api.php' );
require_api( 'email_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'history_dwg_api.php' );
require_api( 'print_dwg_api.php' );

form_security_validate( 'dwg_claim' );

$f_dwg_id = gpc_get_int( 'dwg_id' );
$f_role   = gpc_get_string( 'role' );
$f_action = gpc_get_string( 'action' );

dwg_ensure_exists( $f_dwg_id );

$t_dwg = dwg_get( $f_dwg_id, true );
$t_current_user_id = auth_get_current_user_id();

if( $t_dwg->project_id != helper_get_current_project() ) {
	# in case the current project is not the same project of the document we are
	# viewing, override the current project. This to avoid problems with
	# categories and handlers lists etc.
	$g_project_override = $t_dwg->project_id;
}

if( !in_array( $f_role, array( 'owner', 'assignee' ), true )
	|| !in_array( $f_action, array( 'claim', 'release' ), true ) ) {
	trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

$t_claiming = ( 'claim' == $f_action );
$t_new_user_id = $t_claiming ? $t_current_user_id : NO_USER;

if( 'owner' == $f_role ) {
	# An ownership change is never blocked by a read-only status: a departing
	# owner must still be replaceable on an archived document
	# (doc/dwg/OWNERSHIP.md §9.2).
	if( $t_claiming ) {
		if( !access_dwg_can_claim_owner( $f_dwg_id, $t_current_user_id ) ) {
			access_denied();
		}
	} else {
		if( !access_dwg_can_release_owner( $f_dwg_id, $t_current_user_id ) ) {
			access_denied();
		}
	}

	$t_old_user_id = (int)$t_dwg->owner_id;

	if( $t_old_user_id != $t_new_user_id ) {
		# dwg_set_field() writes the {dwg_history} row and touches last_updated.
		dwg_set_field( $f_dwg_id, 'owner_id', $t_new_user_id );
		email_dwg_ownership_changed( $f_dwg_id, $t_old_user_id, $t_new_user_id );
	}
} else {
	# Assignee. Unlike ownership this is a workflow action, so the read-only
	# check applies as it does on dwg_update.php's ASSIGN path.
	if( dwg_is_readonly( $f_dwg_id ) ) {
		error_parameters( $f_dwg_id );
		trigger_error( ERROR_DWG_READ_ONLY_ACTION_DENIED, ERROR );
	}

	if( $t_claiming && !access_dwg_can_claim_handler( $f_dwg_id, $t_current_user_id ) ) {
		access_denied();
	}

	if( !$t_claiming ) {
		# Only the current assignee, or somebody who may reassign, may release.
		if( (int)$t_dwg->handler_id != $t_current_user_id
			&& !access_has_dwg_level(
				config_get( 'update_dwg_assign_threshold', config_get( 'update_dwg_threshold' ) ),
				$f_dwg_id ) ) {
			access_denied();
		}
	}

	$t_old_user_id = (int)$t_dwg->handler_id;

	if( $t_old_user_id != $t_new_user_id ) {
		# dwg_set_field() writes the {dwg_history} row and touches last_updated.
		dwg_set_field( $f_dwg_id, 'handler_id', $t_new_user_id );
		email_dwg_owner_changed( $f_dwg_id, $t_old_user_id, $t_new_user_id );
	}
}

form_security_purge( 'dwg_claim' );

print_dwg_header_redirect_view( $f_dwg_id );
