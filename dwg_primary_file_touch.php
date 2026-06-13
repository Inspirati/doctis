<?php
# dwg_primary_file_touch.php
# POST handler — create an empty git commit on the project repository, advancing
# the HEAD SHA without modifying any file content.  This forces a divergence
# between the git HEAD and the SHA recorded in Doctis, making the "updated"
# badge and "Sync to HEAD" button appear on the document view page.
#
# Intended for development and testing.  Restricted to MANAGER and above.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'dwg_primary_file_touch' );

$f_dwg_id = gpc_get_int( 'dwg_id' );

access_ensure_dwg_level( MANAGER, $f_dwg_id );

helper_ensure_confirmed(
	lang_get( 'primary_document_touch_confirm' ),
	lang_get( 'primary_document_touch' )
);

file_dwg_git_touch( $f_dwg_id, auth_get_current_user_id() );

form_security_purge( 'dwg_primary_file_touch' );

print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
