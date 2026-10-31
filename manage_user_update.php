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
 * Update User
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
 * @uses helper_api.php
 * @uses html_api.php
 */

require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'user_api.php' );
require_api( 'user_transfer_api.php' );

form_security_validate( 'manage_user_update' );

auth_reauthenticate();

$f_protected	= gpc_get_bool( 'protected' );
$f_enabled		= gpc_get_bool( 'enabled' );
$f_email		= gpc_get_string( 'email', '' );
$f_username		= gpc_get_string( 'username', '' );
$f_realname		= gpc_get_string( 'realname', '' );
$f_access_level	= gpc_get_int( 'access_level' );
$f_user_id		= gpc_get_int( 'user_id' );
$f_reports_to	= gpc_get_int( 'reports_to', 0 );
$f_send_email_notification = gpc_get_bool( 'send_email_notification' );

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
			'protected' => $f_protected,
			'reports_to' => $f_reports_to
		),
		'notify_user' => $f_send_email_notification
	)
);

# Preserve fields omitted by older forms; the command validates supplied values.
foreach( array( 'position_title', 'company', 'department', 'phone', 'alternative', 'email_secondary' ) as $t_field ) {
	if( gpc_isset( $t_field ) ) {
		$t_data['payload']['user'][$t_field] = gpc_get_string( $t_field );
	}
}
if( gpc_isset( 'meeting_invite' ) ) {
	$t_data['payload']['user']['meeting_invite'] = gpc_get_int( 'meeting_invite' );
}

# Was the account enabled before this update? Needed below to tell a
# disable apart from an edit to an already-disabled account.
$t_was_enabled = user_is_enabled( $f_user_id );

$t_command = new UserUpdateCommand( $t_data );
$t_command->execute();

form_security_purge( 'manage_user_update' );

# Disabling an account stops the person, but does not hand on what they were
# responsible for: documents they own or hold, issues, meetings they are due to
# chair, open meeting actions, Topic expectations, category defaults. Land the
# administrator on the transfer page rather than letting it be forgotten.
# See doc/dwg/USER_LIFECYCLE.md.
if( $t_was_enabled && !$f_enabled && user_transfer_has_holdings( $f_user_id ) ) {
	print_header_redirect( 'manage_user_transfer_page.php?user_id=' . (int)$f_user_id );
}

$t_redirect_url = 'manage_user_edit_page.php?user_id=' . (int)$f_user_id;
print_header_redirect( $t_redirect_url );
