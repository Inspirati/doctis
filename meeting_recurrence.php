<?php
# meeting_recurrence.php
# POST handler — the chair or organiser sets or stops the repetition of a
# meeting's series (meeting_recurrence_set()).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'meeting_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'meeting_recurrence' );

$f_meeting_id = gpc_get_int( 'meeting_id' );
$f_rule       = gpc_get_string( 'recurrence', '' );

$t_user_id = auth_get_current_user_id();
$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null || !meeting_user_can_set_recurrence( $t_meeting, $t_user_id ) ) {
	access_denied();
}

meeting_recurrence_set( $t_meeting, $f_rule, $t_user_id );

form_security_purge( 'meeting_recurrence' );

print_header_redirect( 'meeting_view_page.php?id=' . $f_meeting_id );
