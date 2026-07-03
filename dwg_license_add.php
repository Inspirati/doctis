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
require_api( 'dwg_api.php' );
require_api( 'error_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'utility_api.php' );

require_api( 'license_api.php' );

form_security_validate( 'dwg_license_add' );

$f_dwg_id = gpc_get_int( 'bug_id' );
# Free-text box on dwg_view (name/s) and the combo-box form's text field.
$f_licenses = trim( gpc_get_string( 'license_to_add', '' ) );
$f_license_string = trim( gpc_get_string( 'license_string', '' ) );
# Existing-licenses dropdown submits the selected license id (0 = none).
$f_license_select = gpc_get_int( 'license_select', 0 );

# Scope license/document validation to the document's own project.
$f_project_id = dwg_get_field( $f_dwg_id, 'project_id' );

# Collect the licenses to add: name(s) from either text input plus the id
# chosen in the existing-licenses dropdown.
$t_license_refs = array();

$t_text = trim( $f_licenses . ',' . $f_license_string, ", \t\n\r\0\x0B" );
if( !is_blank( $t_text ) ) {
	$t_licensenames = preg_split( '/[,|]/', $t_text, -1, PREG_SPLIT_NO_EMPTY );
	foreach( $t_licensenames as $t_licensename ) {
		$t_licensename = trim( $t_licensename );
		if( !is_blank( $t_licensename ) ) {
			$t_license_refs[] = array( 'name' => $t_licensename );
		}
	}
}

if( $f_license_select > 0 ) {
	$t_license_refs[] = array( 'id' => $f_license_select );
}

foreach( $t_license_refs as $t_license_ref ) {
	$t_data = array(
		'payload' => array(
			'project' => array(
				'id' => $f_project_id
			),
			'license' => $t_license_ref,
			'document' => array(
				'id' => $f_dwg_id
			),
		)
	);
	$t_command = new LicenseDwgAddCommand( $t_data );
	$t_command->execute();
}

form_security_purge( 'dwg_license_add' );

print_dwg_header_redirect_view( $f_dwg_id );
