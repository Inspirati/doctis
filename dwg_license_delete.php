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
 * This file turns monitoring on or off for a bug for the current user
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
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses print_api.php
 * @uses user_api.php
 */

require_once( 'core.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'dwg_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'user_api.php' );

require_api( 'license_api.php' );

form_security_validate( 'dwg_license_delete' );

$f_dwg_id = gpc_get_int( 'bug_id' );
$t_bug = dwg_get( $f_dwg_id, true );
$f_license_id = gpc_get_int( 'user_id', NO_USER );

// $t_logged_in_user_id = auth_get_current_user_id();

// if( $f_license_id === NO_USER ) {
// 	$t_license_id = $t_logged_in_user_id;
// } else {
	license_ensure_exists( $f_license_id );
 	$t_license_id = $f_license_id;
// }

// if( user_is_anonymous( $t_license_id ) ) {
// 	trigger_error( ERROR_PROTECTED_ACCOUNT, E_USER_ERROR );
// }

dwg_ensure_exists( $f_dwg_id );

if( $t_bug->project_id != helper_get_current_project() ) {
	# in case the current project is not the same project of the bug we are viewing...
	# ... override the current project. This to avoid problems with categories and handlers lists etc.
	$g_project_override = $t_bug->project_id;
}

// if( $t_logged_in_user_id == $t_license_id ) {
// 	access_ensure_dwg_level( config_get( 'license_dwg_threshold' ), $f_dwg_id );
// } else {
// 	access_ensure_dwg_level( config_get( 'license_delete_others_dwg_threshold' ), $f_dwg_id );
// }

error_log("license_id = " . print_r($t_license_id, true));

// license_remove_dwg( $f_dwg_id, $t_license_id );
license_remove_dwg( $t_license_id, $f_dwg_id );

form_security_purge( 'dwg_license_delete' );

print_dwg_header_redirect_view( $f_dwg_id );
