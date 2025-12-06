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
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses print_api.php
 * @uses utility_api.php
 */

require_once( 'core.php' );
require_api( 'error_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'utility_api.php' );

require_api( 'license_api.php' );

form_security_validate( 'dwg_license_update' );

auth_reauthenticate();

$f_dwg_id		= gpc_get_int( 'bug_id' );
$f_project_id	= gpc_get_int( 'project_id' );
$f_license_id	= gpc_get_int_array( 'license_id', array() );
$f_user_id		= gpc_get_int( 'user_id' );
$f_access_level	= gpc_get_int( 'access_level', 0 );

// $f_licenses = trim( gpc_get_string( 'license_to_add', '' ) );

if ( 10 == $f_access_level ) {
	license_apply_for_access($f_dwg_id, $f_license_id);
}


# Update license(s) to the specified dwg
foreach( $f_license_id as $t_license_id ) {

	$t_data = array(
		'query' => array(),
		'payload' => array(
			'user' => $f_user_id,
			'project' => $f_project_id,
			'license' => $t_license_id,
			'access_level' => $f_access_level,
			// 'license' => array( 'id' => $f_license_id ),
			// 'access_level' => array( 'id' => $f_access_level ),
		)
	);
			// 'document' => $f_dwg_id,
	/*
	$t_data = array(
		'query' => array(
			'user_id' => $f_user_id
		),
		'payload' => array(
			'license' => array(
				'id' => $f_license_id
			),
			'user' => array(
				'id' => $t_user_id
			),
			'access_level' => array(
				'id' => $f_access_level
			)
		)
	);
	*/
	/*
	$t_data = array(
		'query' => array(
			'user_id' => $f_user_id
		),
		'payload' => array(
			'user' => array(
				'username' => $f_username,
				'real_name' => $f_realname,
				'email' => $f_email,
				'access_level' => array( 'id' => $f_access_level ),
				'enabled' => $f_enabled,
				'protected' => $f_protected
			),
			'notify_user' => $f_send_email_notification
		)
	);
	*/
		$t_command = new LicenseUserUpdateCommand( $t_data );
		$t_command->execute();
}
// $t_command = new LicenseDwgUpdateCommand( $t_data );

/*
$f_license_id = 1;
$f_dwg_id = gpc_get_int( 'bug_id' );
$f_licenses = trim( gpc_get_string( 'license_to_add', '' ) );

if( !is_blank( $f_licenses ) ) {
    $t_licensenames = preg_split( '/[,|]/', $f_licenses, -1, PREG_SPLIT_NO_EMPTY );
    foreach( $t_licensenames as $t_licensename ) {
		$t_data = array(
			'payload' => array(
				'project' => array(
					'id' => $f_license_id
				),
				'license' => array(
		            'name' => trim( $t_licensename )
				),
				'document' => array(
					'id' => $f_dwg_id
				),
			)
		);
		// $t_command = new LicenseDwgAddCommand( $t_data );
		// $t_command->execute();
    }
}
 */
form_security_purge( 'dwg_license_update' );

print_dwg_header_redirect_view( $f_dwg_id );

function license_apply_for_access($f_dwg_id, $f_license_id) {

	email_dwg_license_apply_for_access($f_dwg_id, $f_license_id);
// // ob_start();
// // print_r($t_licenses);
// // error_log(ob_get_clean());
// 	error_log("LICENSE APPLICATION: document " . print_r($f_dwg_id, true));
// 	error_log("LICENSE APPLICATION: licenses " . print_r($f_license_id, true));

// 	$t_document_name = document_get_title( $f_dwg_id );

// 	$t_license_names = array();
// 	foreach( $f_license_id as $t_license_id ) {
// 		$t_license_names[] = license_get_name( $t_license_id );
// 	}
// 	error_log("LICENSE APPLICATION: document " . print_r($t_document_name, true));
// 	error_log("LICENSE APPLICATION: licenses " . print_r($t_license_names, true));

// 	email_dwg_license_apply_for_access($t_document_name, $t_license_names);
}
