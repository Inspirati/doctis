<?php
# Doctis — Download the application checkout report or its files (.tar.gz, without .git).
# No state change; no CSRF token required.
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'git_checkout_api.php' );
require_api( 'gpc_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$f_type = gpc_get_string( 'type', 'report' );
$t_stamp = date( 'Ymd_His' );

header( 'Cache-Control: no-cache, no-store, must-revalidate' );

if( $f_type === 'tree' ) {
	header( 'Content-Type: application/gzip' );
	header( 'Content-Disposition: attachment; filename="doctis_app_files_' . $t_stamp . '.tar.gz"' );

	while( ob_get_level() ) {
		ob_end_clean();
	}
	flush();

	$t_handle = popen( '/bin/tar czf - --exclude=./.git -C ' . escapeshellarg( git_checkout_path() ) . ' .', 'r' );
	if( $t_handle === false ) {
		trigger_error( ERROR_GENERIC, ERROR );
	}
	while( !feof( $t_handle ) ) {
		echo fread( $t_handle, 65536 );
		flush();
	}
	pclose( $t_handle );
	exit;
}

$t_report = git_checkout_report();

while( ob_get_level() ) {
	ob_end_clean();
}
header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="doctis_git_report_' . $t_stamp . '.txt"' );
header( 'Content-Length: ' . strlen( $t_report ) );
echo $t_report;
exit;
