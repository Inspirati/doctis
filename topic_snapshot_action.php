<?php
# Doctis — Take a Documentation Topics coverage snapshot for a review or
# baseline decision.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_snapshot_api.php' );

form_security_validate( 'topic_snapshot_action' );

$f_project_id = gpc_get_int( 'project_id' );
project_ensure_exists( $f_project_id );
if( !topic_can_snapshot( $f_project_id ) ) {
	access_denied();
}

$t_id = topic_snapshot_capture( $f_project_id, gpc_get_int( 'milestone_id', 0 ), gpc_get_string( 'title' ) );

form_security_purge( 'topic_snapshot_action' );
print_header_redirect( 'topic_snapshot_page.php?id=' . $t_id );
