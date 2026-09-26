<?php
# Doctis — My Configuration View update action
#
# Processes the "My Profile" form submitted from my_view_cnf_page.php.
# Updates user-maintained profile and organisational fields through the
# UserProfileUpdateCommand transaction boundary.
#
# The primary email and password are managed via account_page.php.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );

form_security_validate( 'my_view_cnf_update' );

auth_ensure_user_authenticated();
current_user_ensure_unprotected();

$t_user_id = auth_get_current_user_id();

# ── Read POST values ─────────────────────────────────────────────────────────

$f_realname        = gpc_get_string( 'realname',        '' );
$f_position_title  = trim( gpc_get_string( 'position_title',  '' ) );
$f_company         = trim( gpc_get_string( 'company',         '' ) );
$f_phone           = trim( gpc_get_string( 'phone',           '' ) );
$f_department      = trim( gpc_get_string( 'department',      '' ) );
$f_reports_to      = gpc_get_int( 'reports_to', 0 );
$f_alternative     = trim( gpc_get_string( 'alternative', '' ) );
$f_meeting_invite  = gpc_get_int(    'meeting_invite',  0 );
$f_email_secondary = trim( gpc_get_string( 'email_secondary', '' ) );

# ── Update ───────────────────────────────────────────────────────────────────

$t_data = array(
	'query' => array( 'user_id' => $t_user_id ),
	'payload' => array(
		'profile' => array(
			'realname' => $f_realname,
			'position_title' => $f_position_title,
			'company' => $f_company,
			'phone' => $f_phone,
			'department' => $f_department,
			'reports_to' => $f_reports_to,
			'alternative' => $f_alternative,
			'meeting_invite' => $f_meeting_invite,
			'email_secondary' => $f_email_secondary,
		),
	),
);
$t_command = new UserProfileUpdateCommand( $t_data );
$t_command->execute();

form_security_purge( 'my_view_cnf_update' );

print_header_redirect( 'my_view_cnf_page.php?updated=1' );
