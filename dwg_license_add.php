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

form_security_validate( 'dwg_license_add' );

$f_project_id = 1;
$f_dwg_id = gpc_get_int( 'bug_id' );
$f_licenses = trim( gpc_get_string( 'license_to_add', '' ) );

if( !is_blank( $f_licenses ) ) {
    $t_licensenames = preg_split( '/[,|]/', $f_licenses, -1, PREG_SPLIT_NO_EMPTY );
    foreach( $t_licensenames as $t_licensename ) {
		$t_match_str = preg_replace('/[^A-Z0-9]/', '', strtoupper( $t_licensename ) );
		$t_data = array(
			'payload' => array(
				'project' => array(
					'id' => $f_project_id
				),
				'license' => array(
					'name' => trim( $t_licensename )
				),
				'document' => array(
					'id' => $f_dwg_id
				),
			)
		);
		$t_command = new LicenseDwgAddCommand( $t_data );
		$t_command->execute();
    }
}

form_security_purge( 'dwg_license_add' );

print_dwg_header_redirect_view( $f_dwg_id );
