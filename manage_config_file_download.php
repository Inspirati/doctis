<?php
# Doctis — Stream config/config_inc.php as a plain-text download.
# No state change; no CSRF token required.
# www-data can read the file (world-readable).
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_config_path = dirname( __FILE__ ) . '/config/config_inc.php';

if( !file_exists( $t_config_path ) ) {
	trigger_error( ERROR_GENERIC, ERROR );
}

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="config_inc.php"' );
header( 'Content-Length: ' . filesize( $t_config_path ) );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

while( ob_get_level() ) {
	ob_end_clean();
}

readfile( $t_config_path );
exit;
