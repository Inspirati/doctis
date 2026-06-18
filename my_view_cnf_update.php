<?php
# Doctis — My Configuration View update action
#
# Processes the "My Profile" form submitted from my_view_cnf_page.php.
# Updates user-maintained fields: realname, position_title, company, phone,
# department, meeting_invite, and email_secondary.
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
require_api( 'string_api.php' );
require_api( 'user_api.php' );

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
$f_meeting_invite  = gpc_get_int(    'meeting_invite',  0 );
$f_email_secondary = trim( gpc_get_string( 'email_secondary', '' ) );

# ── Validate ─────────────────────────────────────────────────────────────────

# Real name: apply the same normalisation as account_update.php
$f_realname = string_normalize( $f_realname );
$f_realname = mb_substr( $f_realname, 0, DB_FIELD_SIZE_REALNAME );

# Clamp meeting_invite to the three defined values
if( !in_array( $f_meeting_invite, [ 0, 1, 2 ], true ) ) {
	$f_meeting_invite = 0;
}

# Enforce field length limits
$f_position_title  = mb_substr( $f_position_title,  0, DB_FIELD_SIZE_POSITION_TITLE );
$f_company         = mb_substr( $f_company,         0, DB_FIELD_SIZE_COMPANY );
$f_phone           = mb_substr( $f_phone,           0, DB_FIELD_SIZE_PHONE );
$f_department      = mb_substr( $f_department,      0, DB_FIELD_SIZE_DEPARTMENT );

# Secondary email: must be blank or a valid email address
if( !is_blank( $f_email_secondary ) ) {
	if( !filter_var( $f_email_secondary, FILTER_VALIDATE_EMAIL ) ) {
		error_parameters( lang_get( 'email_secondary' ) );
		trigger_error( ERROR_EMPTY_FIELD, ERROR );
	}
	if( mb_strlen( $f_email_secondary ) > 191 ) {
		$f_email_secondary = '';   // silently drop if absurdly long
	}
}

# ── Update ───────────────────────────────────────────────────────────────────

user_set_fields( $t_user_id, [
	'realname'        => $f_realname,
	'position_title'  => $f_position_title,
	'company'         => $f_company,
	'phone'           => $f_phone,
	'department'      => $f_department,
	'meeting_invite'  => $f_meeting_invite,
	'email_secondary' => $f_email_secondary,
] );

form_security_purge( 'my_view_cnf_update' );

print_header_redirect( 'my_view_cnf_page.php?updated=1' );
