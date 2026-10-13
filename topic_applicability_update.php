<?php
# Doctis — Record whether a Documentation Topic applies to a project.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'topic_applicability_update' );

$f_project_id = gpc_get_int( 'project_id' );
$f_topic_id = gpc_get_int( 'topic_id' );
project_ensure_exists( $f_project_id );
if( !topic_can_manage_project( $f_project_id ) ) {
	access_denied();
}

topic_applicability_set( $f_project_id, $f_topic_id, gpc_get_int( 'applicability' ), gpc_get_string( 'rationale', '' ),
	gpc_isset( 'expected_decision_id' ) ? gpc_get_int( 'expected_decision_id' ) : null );

form_security_purge( 'topic_applicability_update' );
print_header_redirect( 'topic_detail_page.php?project_id=' . $f_project_id . '&topic_id=' . $f_topic_id );
