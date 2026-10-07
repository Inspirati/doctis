<?php
# meeting_minutes_approve.php
# POST handler — the meeting chair approves the draft minutes: the minutes
# Draft is promoted to On Record and the meeting is marked "Minutes approved".
# Called from my_view_meeting_page.php and from the Primary Document panel
# of a meeting document (dwg_view_inc.php).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'meeting_api.php' );
require_api( 'print_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'meeting_minutes_approve' );

$f_meeting_id = gpc_get_int( 'meeting_id' );
$f_draft_sha  = gpc_get_string( 'draft_sha', '' );
$f_return     = gpc_get_string( 'return', 'my_view_meeting_page.php' );

$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null ) {
	trigger_error( ERROR_GENERIC, ERROR );
}
if( !meeting_user_can_approve_minutes( $t_meeting, auth_get_current_user_id() ) ) {
	access_denied();
}

helper_ensure_confirmed(
	sprintf( lang_get( 'meeting_approve_minutes_confirm' ), $t_meeting['doc_ref'] ),
	lang_get( 'meeting_approve_minutes' )
);

meeting_minutes_approve( $t_meeting, auth_get_current_user_id(), $f_draft_sha );

form_security_purge( 'meeting_minutes_approve' );

print_header_redirect( string_sanitize_url( $f_return ) );
