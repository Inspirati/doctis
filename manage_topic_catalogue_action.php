<?php
# Doctis — Manage > Topics: create, change, publish, retire, delete or load a
# Topic catalogue.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'manage_topic_catalogue_action' );
auth_reauthenticate();
if( !topic_can_manage_catalogues() ) {
	access_denied();
}

$f_action = gpc_get_string( 'action' );
$f_catalogue_id = gpc_get_int( 'id', 0 );

switch( $f_action ) {
	case 'create':
		$f_catalogue_id = topic_catalogue_create( gpc_get_string( 'name' ), gpc_get_string( 'revision', '' ),
			gpc_get_int( 'source_dwg_id', 0 ) );
		break;
	case 'update':
		topic_catalogue_update( $f_catalogue_id, gpc_get_string( 'name' ), gpc_get_string( 'revision', '' ),
			gpc_get_int( 'source_dwg_id', 0 ) );
		break;
	case 'publish':
		topic_catalogue_set_status( $f_catalogue_id, TOPIC_CATALOGUE_PUBLISHED );
		break;
	case 'retire':
		topic_catalogue_set_status( $f_catalogue_id, TOPIC_CATALOGUE_RETIRED );
		break;
	case 'delete':
		topic_catalogue_delete( $f_catalogue_id );
		$f_catalogue_id = 0;
		break;
	case 'load_seed':
		$f_catalogue_id = topic_catalogue_load_seed( gpc_get_string( 'file' ) );
		break;
	default:
		error_parameters( 'action' );
		trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

form_security_purge( 'manage_topic_catalogue_action' );

print_header_redirect( $f_catalogue_id > 0
	? 'manage_topic_catalogue_page.php?id=' . $f_catalogue_id
	: 'manage_topic_page.php' );
