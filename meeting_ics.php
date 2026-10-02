<?php
# meeting_ics.php
# Download a meeting as an iCalendar file (METHOD:PUBLISH, or CANCEL for a
# cancelled meeting), for participants and users who can view its document.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'gpc_api.php' );
require_api( 'meeting_api.php' );

auth_ensure_user_authenticated();

$f_meeting_id = gpc_get_int( 'id' );
$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null || !meeting_user_can_view( $t_meeting, auth_get_current_user_id() ) ) {
	access_denied();
}

$t_ics = meeting_ics( $t_meeting, (int)$t_meeting['status'] === MEETING_CANCELLED ? 'CANCEL' : 'PUBLISH' );
$t_filename = preg_replace( '/[^A-Za-z0-9._-]/', '_', $t_meeting['doc_ref'] ) . '.ics';

header( 'Content-Type: text/calendar; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Content-Length: ' . strlen( $t_ics ) );
echo $t_ics;
