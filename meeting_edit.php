<?php
# meeting_edit.php
# POST handler for meeting_edit_page.php: reschedule, change location, minute
# taker and invitees (meeting_change()). The agenda record is updated On
# Record; invitees are emailed the update with a calendar update, removed
# invitees a cancellation.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'meeting_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'meeting_edit' );

$f_meeting_id = gpc_get_int( 'meeting_id' );
$f_date       = gpc_get_string( 'date' );
$f_time       = gpc_get_string( 'time' );
$f_duration   = gpc_get_int( 'duration' );
$f_location   = gpc_get_string( 'location', '' );
$f_taker      = gpc_get_int( 'minute_taker_id', 0 );
$f_keep       = gpc_get_int_array( 'keep', array() );
$f_add        = gpc_get_int_array( 'add_user_ids', array() );
$f_guests     = gpc_get_string( 'guests', '' );

$t_user_id = auth_get_current_user_id();
$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null || !meeting_user_can_manage( $t_meeting, $t_user_id ) ) {
	access_denied();
}

$t_start = DateTime::createFromFormat( '!Y-m-d H:i', $f_date . ' ' . substr( $f_time, 0, 5 ) );
if( $t_start === false ) {
	error_parameters( lang_get( 'meeting_date' ) );
	trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

$t_remove = array();
foreach( meeting_invitees_get( $f_meeting_id ) as $t_invitee ) {
	if( !in_array( (int)$t_invitee['id'], $f_keep, true ) ) {
		$t_remove[] = (int)$t_invitee['id'];
	}
}

meeting_change( $t_meeting, $t_user_id,
	array(
		'date_start'      => $t_start->getTimestamp(),
		'duration'        => $f_duration,
		'location'        => $f_location,
		'minute_taker_id' => $f_taker,
	),
	$f_add,
	preg_split( '/\R/', $f_guests ),
	$t_remove
);

form_security_purge( 'meeting_edit' );

print_header_redirect( 'meeting_view_page.php?id=' . $f_meeting_id );
