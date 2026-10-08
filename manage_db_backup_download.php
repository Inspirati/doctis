<?php
# Doctis — Download a mysqldump of the Doctis database as a .sql.gz file.
# No state change; no CSRF token required.
#
# mysqldump runs as the web user with the application's own database
# credentials, passed in a private temporary option file so the password never
# appears on a command line. No sudoers rule is needed. The dump is written to
# a temporary file first, so a failure produces an error page rather than an
# empty download.
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

/**
 * Remove temporary files and stop with an error page.
 *
 * @param array  $p_files   Paths to remove.
 * @param string $p_message Message for the administrator.
 * @return void
 */
function backup_fail( array $p_files, string $p_message ): void {
	foreach( $p_files as $t_file ) {
		@unlink( $t_file );
	}
	error_parameters( $p_message );
	trigger_error( ERROR_GENERIC, ERROR );
}

$t_dump_bin = is_executable( '/usr/bin/mariadb-dump' ) ? '/usr/bin/mariadb-dump' : '/usr/bin/mysqldump';
if( !is_executable( $t_dump_bin ) ) {
	backup_fail( array(), 'Neither mariadb-dump nor mysqldump is installed on the server.' );
}

# hostname may be "host" or "host:port".
$t_host = (string)config_get_global( 'hostname' );
$t_port = '';
if( preg_match( '/^(.+):(\d+)$/', $t_host, $t_matches ) ) {
	$t_host = $t_matches[1];
	$t_port = $t_matches[2];
}

$t_tmp_dir = sys_get_temp_dir();
$t_option_file = tempnam( $t_tmp_dir, 'doctis-dump-cnf-' );
$t_dump_file = tempnam( $t_tmp_dir, 'doctis-dump-sql-' );
$t_error_file = tempnam( $t_tmp_dir, 'doctis-dump-err-' );
$t_files = array( $t_option_file, $t_dump_file, $t_error_file );
if( in_array( false, $t_files, true ) ) {
	backup_fail( array_filter( $t_files ), 'Could not create temporary files in ' . $t_tmp_dir . '.' );
}
chmod( $t_option_file, 0600 );

# Option file values are quoted; backslashes and quotes are escaped.
$t_quote = function( string $p_value ): string {
	return '"' . addcslashes( $p_value, "\\\"" ) . '"';
};
$t_options = "[client]\n"
	. 'user=' . $t_quote( (string)config_get_global( 'db_username' ) ) . "\n"
	. 'password=' . $t_quote( (string)config_get_global( 'db_password' ) ) . "\n"
	. 'host=' . $t_quote( $t_host ) . "\n"
	. ( $t_port !== '' ? 'port=' . $t_port . "\n" : '' );
file_put_contents( $t_option_file, $t_options );

# --defaults-extra-file must be the first option.
$t_cmd = escapeshellarg( $t_dump_bin )
	. ' --defaults-extra-file=' . escapeshellarg( $t_option_file )
	. ' --single-transaction --routines --triggers --add-drop-table '
	. escapeshellarg( (string)config_get_global( 'database_name' ) )
	. ' > ' . escapeshellarg( $t_dump_file )
	. ' 2> ' . escapeshellarg( $t_error_file );
exec( $t_cmd, $t_unused, $t_rc );
@unlink( $t_option_file );

if( $t_rc !== 0 || filesize( $t_dump_file ) === 0 ) {
	$t_error = trim( (string)file_get_contents( $t_error_file ) );
	backup_fail( $t_files, 'Database backup failed (exit ' . $t_rc . ')'
		. ( $t_error !== '' ? ': ' . $t_error : '.' ) );
}

$t_filename = 'doctis_db_backup_' . date( 'Ymd_His' ) . '.sql.gz';

header( 'Content-Type: application/gzip' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

while( ob_get_level() ) {
	ob_end_clean();
}
flush();

passthru( '/bin/gzip -c ' . escapeshellarg( $t_dump_file ) );

@unlink( $t_dump_file );
@unlink( $t_error_file );

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' downloaded a database backup at ' . date( 'c' ) );
exit;
