<?php
# Doctis - Document Issue Tracking System
#
# Save a project's publishing settings from the Manage Project page
# (manage_proj_publish_inc.php, doc/publish/PUBLISHING.md).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'dwg_publish_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );

form_security_validate( 'manage_proj_publish_update' );

auth_reauthenticate();

$f_project_id = gpc_get_int( 'project_id' );
project_ensure_exists( $f_project_id );
$g_project_override = $f_project_id;
access_ensure_project_level( config_get( 'manage_project_threshold' ), $f_project_id );

if( !dwg_publish_enabled() ) {
	dwg_publish_error( 'publishing is not enabled on this server ($g_dwg_publish_roots is empty).' );
}

dwg_publish_settings_save( $f_project_id,
	gpc_get_string( 'dwg_publish_target', '' ),
	gpc_get_string( 'dwg_publish_url', '' ),
	gpc_get_string( 'dwg_publish_template', '' ),
	gpc_get_string( 'dwg_publish_logo', '' ) );

form_security_purge( 'manage_proj_publish_update' );

print_header_redirect( 'manage_proj_edit_page.php?project_id=' . $f_project_id . '#publishing' );
