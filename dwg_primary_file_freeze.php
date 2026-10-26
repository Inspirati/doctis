<?php
# dwg_primary_file_freeze.php
# POST handler — freeze or unfreeze a record's primary document.
#
# Freezing pins the record at its On Record commit: a later change in the
# repository no longer offers it a Draft and it cannot be promoted.  It means
# the same for both kinds —
#   a frozen source record is a *release* rather than a *line*;
#   a frozen document is one whose issued version is final.
# See doc/git/SOURCE_REPOSITORIES.md.
#
# Called from the Primary Document panel on dwg_view_inc.php.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );

use Mantis\Exceptions\ClientException;

auth_ensure_user_authenticated();
form_security_validate( 'dwg_primary_file_freeze' );

$f_dwg_id = gpc_get_int( 'dwg_id' );
$f_frozen = gpc_get_bool( 'frozen' );

# Freezing settles what the record of this document is, so it follows the
# same threshold as promoting a Draft.
access_ensure_dwg_level( MANAGER, $f_dwg_id );

if( file_dwg_primary_get( $f_dwg_id ) === null ) {
	throw new ClientException( 'Document ' . $f_dwg_id . ' has no registered primary document to freeze',
		ERROR_INVALID_FIELD_VALUE, array( 'dwg_id' ) );
}

if( dwg_is_frozen( $f_dwg_id ) === $f_frozen ) {
	print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
}

helper_ensure_confirmed(
	lang_get( $f_frozen ? 'dwg_freeze_confirm' : 'dwg_unfreeze_confirm' ),
	lang_get( $f_frozen ? 'dwg_freeze_button' : 'dwg_unfreeze_button' )
);

dwg_set_frozen( $f_dwg_id, $f_frozen );

form_security_purge( 'dwg_primary_file_freeze' );

print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
