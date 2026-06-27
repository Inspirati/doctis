<?php
# Doctis — Stream the git document store as a .tar.gz download.
# No state change; no CSRF token required.
# /var/git/doctis/ is owned by www-data so no sudo is needed.
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_filename = 'doctis_git_backup_' . date( 'Ymd_His' ) . '.tar.gz';

header( 'Content-Type: application/gzip' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

while( ob_get_level() ) {
	ob_end_clean();
}
flush();

$t_handle = popen( '/bin/tar czf - -C /var/git/doctis .', 'r' );
if( $t_handle === false ) {
	trigger_error( ERROR_GENERIC, ERROR );
}
while( !feof( $t_handle ) ) {
	echo fread( $t_handle, 65536 );
	flush();
}
pclose( $t_handle );
exit;
