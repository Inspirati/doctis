<?php
# ai_knowledge_update.php
# POST handler for ai_knowledge_page.php: add, edit, publish, retire or
# delete a knowledge base entry (core/ai_knowledge_api.php enforces who may).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'ai_knowledge_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );
form_security_validate( 'ai_knowledge_update' );

$f_action = gpc_get_string( 'action' );
$f_id     = gpc_get_int( 'id', 0 );
$t_user_id = auth_get_current_user_id();

$t_fields = array(
	'question'   => gpc_get_string( 'question', '' ),
	'answer'     => gpc_get_string( 'answer', '' ),
	'keywords'   => gpc_get_string( 'keywords', '' ),
	'page'       => gpc_get_string( 'page', '' ),
	'project_id' => gpc_get_int( 'project_id', 0 ),
);

switch( $f_action ) {
	case 'add':
		$f_id = ai_knowledge_add( $t_fields + array( 'source' => 'manual' ), $t_user_id );
		break;
	case 'edit':
		ai_knowledge_review( $f_id, 'edit', $t_fields, $t_user_id );
		break;
	case 'publish':
	case 'retire':
		ai_knowledge_review( $f_id, $f_action, array(), $t_user_id );
		break;
	case 'delete':
		ai_knowledge_delete( $f_id, $t_user_id );
		$f_id = 0;
		break;
	default:
		trigger_error( ERROR_GENERIC, ERROR );
}

form_security_purge( 'ai_knowledge_update' );

print_header_redirect( 'ai_knowledge_page.php' . ( $f_id > 0 ? '#kb-' . $f_id : '' ) );
