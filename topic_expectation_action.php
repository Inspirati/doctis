<?php
# Doctis — Add, change, retire or restore a Documentation Topics expectation.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_expectation_api.php' );

form_security_validate( 'topic_expectation_action' );

$f_action = gpc_get_string( 'action' );
$f_milestone_view = gpc_get_int( 'milestone_view', 0 );
if( $f_action == 'create' ) {
	$t_project_id = gpc_get_int( 'project_id' );
	project_ensure_exists( $t_project_id );
} else {
	$f_expectation_id = gpc_get_int( 'id' );
	$t_project_id = (int)topic_expectation_get( $f_expectation_id )['project_id'];
}
if( !topic_can_manage_project( $t_project_id ) ) {
	access_denied();
}

switch( $f_action ) {
	case 'create':
		$f_expectation_id = topic_expectation_create( $t_project_id, gpc_get_int( 'topic_id' ), gpc_get_string( 'statement' ),
			gpc_get_int( 'owner_id', 0 ), gpc_get_int( 'milestone_id' ), gpc_get_int( 'maturity' ) );
		break;
	case 'update':
		topic_expectation_update( $f_expectation_id, gpc_get_string( 'statement' ), gpc_get_int( 'owner_id', 0 ),
			gpc_get_int( 'milestone_id' ), gpc_get_int( 'maturity' ) );
		break;
	case 'retire':
	case 'restore':
		topic_expectation_set_enabled( $f_expectation_id, $f_action == 'restore' );
		break;
	default:
		error_parameters( 'action' );
		trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

form_security_purge( 'topic_expectation_action' );
print_header_redirect( 'topic_expectation_page.php?id=' . $f_expectation_id . '&milestone_id=' . $f_milestone_view );
