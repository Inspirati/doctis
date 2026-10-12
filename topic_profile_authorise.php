<?php
# Doctis — Authorise a project's Documentation Topics profile (a new
# revision of its applicability decisions, expectations and milestones).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_expectation_api.php' );

form_security_validate( 'topic_profile_authorise' );

$f_project_id = gpc_get_int( 'project_id' );
project_ensure_exists( $f_project_id );
if( !topic_can_manage_project( $f_project_id ) ) {
	access_denied();
}

topic_profile_authorise( $f_project_id, gpc_get_string( 'note', '' ) );

form_security_purge( 'topic_profile_authorise' );
print_header_redirect( 'topic_page.php?project_id=' . $f_project_id . '#profile' );
