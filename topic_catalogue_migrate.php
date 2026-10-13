<?php
# Doctis — Move a project's Documentation Topics to another revision of its
# catalogue, after the preview.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'topic_catalogue_migrate' );

$f_project_id = gpc_get_int( 'project_id' );
project_ensure_exists( $f_project_id );
if( !topic_can_manage_project( $f_project_id ) ) {
	access_denied();
}

topic_catalogue_migrate( $f_project_id, gpc_get_int( 'catalogue_id' ) );

form_security_purge( 'topic_catalogue_migrate' );
print_header_redirect( 'topic_page.php?project_id=' . $f_project_id . '#profile' );
