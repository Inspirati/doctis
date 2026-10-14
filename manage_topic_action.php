<?php
# Doctis — Manage > Topics: add, change, retire, restore or delete a Topic.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'manage_topic_action' );
auth_reauthenticate();
if( !topic_can_manage_catalogues() ) {
	access_denied();
}

$f_action = gpc_get_string( 'action' );
$f_topic_id = gpc_get_int( 'id', 0 );

if( $f_action == 'create' ) {
	$t_catalogue_id = gpc_get_int( 'catalogue_id' );
} else {
	$t_catalogue_id = (int)topic_get( $f_topic_id )['catalogue_id'];
}

switch( $f_action ) {
	case 'create':
		topic_create( $t_catalogue_id, gpc_get_string( 'code' ), gpc_get_int( 'parent_id', 0 ),
			gpc_get_string( 'title' ), gpc_get_string( 'description', '' ), gpc_get_int( 'sort_order', 0 ) );
		break;
	case 'update':
		topic_update( $f_topic_id, gpc_get_string( 'code' ), gpc_get_int( 'parent_id', 0 ),
			gpc_get_string( 'title' ), gpc_get_string( 'description', '' ), gpc_get_int( 'sort_order', 0 ) );
		break;
	case 'retire':
	case 'restore':
		topic_set_enabled( $f_topic_id, $f_action == 'restore' );
		break;
	case 'delete':
		topic_delete( $f_topic_id );
		break;
	default:
		error_parameters( 'action' );
		trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

form_security_purge( 'manage_topic_action' );

print_header_redirect( 'manage_topic_catalogue_page.php?id=' . $t_catalogue_id );
