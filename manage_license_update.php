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
 * Update Project
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses authentication_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 */

require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'license_api.php' );

form_security_validate( 'manage_license_update' );

auth_reauthenticate();

$f_license_id 	= gpc_get_int( 'license_id' );
$f_name 		= gpc_get_string( 'name' );
$f_description 	= gpc_get_string( 'description' );
$f_status 		= gpc_get_int( 'status' );
$f_view_state 	= gpc_get_int( 'view_state' );
$f_enabled	 	= gpc_get_bool( 'enabled' );

$t_data = array(
	'query' => array(
		'id' => $f_license_id
	),
	'payload' => array(
		'name' => $f_name,
		'description' => $f_description,
		'status' => array( 'id' => $f_status ),
		'view_state' => array( 'id' => $f_view_state ),
		'enabled' => $f_enabled,
	)
);

$t_command = new LicenseUpdateCommand( $t_data );
$t_command->execute();

form_security_purge( 'manage_license_update' );

print_header_redirect( 'manage_license_page.php' );
