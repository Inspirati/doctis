<?php
# meeting_cancel.php
# POST handler — the chair or organiser cancels a meeting (meeting_cancel()):
# status Cancelled, record stamped, invitees sent a calendar cancellation.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'meeting_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'meeting_cancel' );

$f_meeting_id = gpc_get_int( 'meeting_id' );
$f_reason     = gpc_get_string( 'reason', '' );

$t_user_id = auth_get_current_user_id();
$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null || !meeting_user_can_manage( $t_meeting, $t_user_id ) ) {
	access_denied();
}

helper_ensure_confirmed(
	sprintf( lang_get( 'meeting_cancel_confirm' ), $t_meeting['doc_ref'] . ' — ' . $t_meeting['title'] ),
	lang_get( 'meeting_cancel_button' )
);

meeting_cancel( $t_meeting, $t_user_id, $f_reason );

form_security_purge( 'meeting_cancel' );

print_header_redirect( 'meeting_view_page.php?id=' . $f_meeting_id );
