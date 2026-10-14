<?php
# Doctis — Enable or disable Documentation Topics on a project.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'topic_project_action' );

$f_project_id = gpc_get_int( 'project_id' );
project_ensure_exists( $f_project_id );
if( !topic_can_manage_project( $f_project_id ) ) {
	access_denied();
}

switch( gpc_get_string( 'action' ) ) {
	case 'enable':
		topic_project_enable( $f_project_id, gpc_get_int( 'catalogue_id' ) );
		break;
	case 'disable':
		topic_project_disable( $f_project_id );
		break;
	default:
		error_parameters( 'action' );
		trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

form_security_purge( 'topic_project_action' );
print_header_redirect( 'topic_page.php?project_id=' . $f_project_id );
