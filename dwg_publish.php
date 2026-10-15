<?php
# Doctis - Document Issue Tracking System
#
# Publish a document's on-record version to its project's website, or
# unpublish its page (core/dwg_publish_api.php, doc/publish/PUBLISHING.md).
#
# POST: dwg_id, action = publish | unpublish.

require_once( 'core.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'dwg_api.php' );
require_api( 'dwg_publish_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'print_dwg_api.php' );

form_security_validate( 'dwg_publish' );

$f_dwg_id = gpc_get_int( 'dwg_id' );
$f_action = gpc_get_string( 'action', 'publish' );
$t_dwg = dwg_get( $f_dwg_id, true );

if( $t_dwg->project_id != helper_get_current_project() ) {
	# in case the current project is not the same project of the document we are viewing...
	# ... override the current project. This to avoid problems with categories and handlers lists etc.
	$g_project_override = $t_dwg->project_id;
}

access_ensure_dwg_level( config_get( 'dwg_publish_threshold' ), $f_dwg_id );

if( $f_action === 'unpublish' ) {
	dwg_publish_withdraw( $f_dwg_id, auth_get_current_user_id() );
} else {
	dwg_publish( $f_dwg_id, auth_get_current_user_id() );
}

form_security_purge( 'dwg_publish' );

print_dwg_header_redirect_view( $f_dwg_id );
