<?php
# Doctis — Link a document to a Documentation Topics expectation as evidence,
# or remove the link.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_expectation_api.php' );

form_security_validate( 'topic_evidence_action' );

$f_action = gpc_get_string( 'action' );
$f_milestone_view = gpc_get_int( 'milestone_view', 0 );

switch( $f_action ) {
	case 'add':
		$t_expectation_id = gpc_get_int( 'expectation_id' );
		$t_dwg_id = gpc_get_int( 'dwg_id' );
		dwg_ensure_exists( $t_dwg_id );
		if( !topic_can_tag_dwg_in_project( $t_dwg_id, (int)topic_expectation_get( $t_expectation_id )['project_id'] ) ) {
			access_denied();
		}
		topic_evidence_add( $t_expectation_id, $t_dwg_id, gpc_get_string( 'locator', '' ) );
		break;
	case 'remove':
		$t_evidence = topic_evidence_get( gpc_get_int( 'evidence_id' ) );
		$t_expectation_id = (int)$t_evidence['expectation_id'];
		if( !topic_can_tag_dwg_in_project( (int)$t_evidence['dwg_id'], (int)topic_expectation_get( $t_expectation_id )['project_id'] ) ) {
			access_denied();
		}
		topic_evidence_remove( (int)$t_evidence['id'] );
		break;
	default:
		error_parameters( 'action' );
		trigger_error( ERROR_INVALID_FIELD_VALUE, ERROR );
}

form_security_purge( 'topic_evidence_action' );
print_header_redirect( 'topic_expectation_page.php?id=' . $t_expectation_id . '&milestone_id=' . $f_milestone_view . '#evidence' );
