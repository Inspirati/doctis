<?php
# Doctis — Add, change, achieve, reopen, delete or load default milestones
# of a project's Documentation Topics.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_expectation_api.php' );

form_security_validate( 'topic_milestone_action' );

$f_action = gpc_get_string( 'action' );
if( in_array( $f_action, array( 'create', 'load_defaults' ) ) ) {
	$t_project_id = gpc_get_int( 'project_id' );
	project_ensure_exists( $t_project_id );
} else {
	$f_milestone_id = gpc_get_int( 'id' );
	$t_project_id = (int)topic_milestone_get( $f_milestone_id )['project_id'];
}
if( !topic_can_manage_project( $t_project_id ) ) {
	access_denied();
}

switch( $f_action ) {
	case 'create':
		topic_milestone_create( $t_project_id, gpc_get_string( 'name' ), gpc_get_string( 'description', '' ) );
		break;
	case 'load_defaults':
		topic_milestone_load_defaults( $t_project_id );
		break;
	case 'update':
		topic_milestone_update( $f_milestone_id, gpc_get_string( 'name' ), gpc_get_string( 'description', '' ), gpc_get_int( 'sort_order', 0 ) );
		break;
	case 'achieve':
	case 'reopen':
		topic_milestone_set_achieved( $f_milestone_id, $f_action == 'achieve' );
		break;
	case 'delete':
		topic_milestone_delete( $f_milestone_id );
		break;
	default:
		error_parameters( 'action' );
		trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

form_security_purge( 'topic_milestone_action' );
print_header_redirect( 'topic_milestone_page.php?project_id=' . $t_project_id );
