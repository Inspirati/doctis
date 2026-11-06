<?php
# Doctis — company administration action (CSRF-protected and reauthenticated).
require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'company_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );

form_security_validate( 'manage_company_update' );
auth_reauthenticate();
access_ensure_global_level( config_get( 'admin_site_threshold' ) );
$f_action = gpc_get_string( 'action' );
$f_name = gpc_get_string( 'name' );
if( $f_action === 'add' ) {
	company_add( $f_name );
} elseif( $f_action === 'update' ) {
	company_update( gpc_get_int( 'company_id' ), $f_name, gpc_get_bool( 'enabled' ) );
} else {
	throw new Mantis\Exceptions\ClientException( 'Invalid company action', ERROR_INVALID_FIELD_VALUE, array( 'action' ) );
}
form_security_purge( 'manage_company_update' );
print_header_redirect( 'manage_company_page.php' );
