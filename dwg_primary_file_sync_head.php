<?php
# dwg_primary_file_sync_head.php
# POST handler — synchronise the Doctis {dwg_primary_file} record to the
# current git HEAD commit, discarding the previously stored SHA.
# Called from the Primary Document panel on dwg_view_inc.php.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'dwg_primary_file_sync_head' );

$f_dwg_id = gpc_get_int( 'dwg_id' );

# Sync to HEAD is a privileged operation — manager level or above required.
access_ensure_dwg_level( MANAGER, $f_dwg_id );

# Show a server-side confirmation page before performing the destructive sync.
# helper_ensure_confirmed() re-posts all current params plus _confirmed=1 on
# the second pass, so form_security_validate() is satisfied on both passes
# (the token is included in the re-post and is not purged until after the sync).
helper_ensure_confirmed(
	lang_get( 'primary_document_sync_head_confirm' ),
	lang_get( 'primary_document_sync_head_button' )
);

file_dwg_primary_sync_head( $f_dwg_id, auth_get_current_user_id() );

form_security_purge( 'dwg_primary_file_sync_head' );

print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
