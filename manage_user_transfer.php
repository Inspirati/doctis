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
 * POST handler — transfer what a user holds to a successor.
 *
 * See doc/dwg/USER_LIFECYCLE.md.
 *
 * @package Doctis
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses config_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses user_api.php
 * @uses user_transfer_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'user_api.php' );
require_api( 'user_transfer_api.php' );

form_security_validate( 'manage_user_transfer' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_user_threshold' ) );

$f_user_id = gpc_get_int( 'user_id' );
$f_to_user_id = gpc_get_int( 'to_user_id', 0 );
$f_categories = gpc_get_string_array( 'categories', array() );

user_ensure_exists( $f_user_id );

if( $f_to_user_id == $f_user_id ) {
	error_parameters( lang_get( 'user_transfer_to' ) );
	trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

# The successor must be a real, enabled account. Beyond that, eligibility is
# decided per item inside user_transfer_perform(), which skips rows the
# successor may not take over rather than failing the whole transfer.
if( !user_exists( $f_to_user_id ) || !user_is_enabled( $f_to_user_id ) ) {
	error_parameters( lang_get( 'user_transfer_to' ) );
	trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

# Only the categories the form offered, so an edited POST cannot reach others.
$t_valid = array_keys( user_transfer_categories() );
$t_categories = array_values( array_intersect( $f_categories, $t_valid ) );

if( empty( $t_categories ) ) {
	print_header_redirect( 'manage_user_transfer_page.php?user_id=' . $f_user_id );
}

$t_report = user_transfer_perform( $f_user_id, $f_to_user_id, $t_categories );

$t_moved = 0;
$t_skipped = 0;
foreach( $t_report as $t_counts ) {
	$t_moved += $t_counts['moved'];
	$t_skipped += $t_counts['skipped'];
}

form_security_purge( 'manage_user_transfer' );

print_header_redirect( 'manage_user_transfer_page.php?user_id=' . $f_user_id
	. '&moved=' . $t_moved . '&skipped=' . $t_skipped );
