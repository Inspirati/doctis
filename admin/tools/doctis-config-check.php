<?php
# Doctis — check a candidate config_inc.php in a separate PHP process before
# System Operations installs it (manage_config_file_upload.php).
#
# A config_inc.php that fails to load stops every page, and on a server without
# a console nobody could repair it.  The candidate is loaded the way core.php
# loads the real one (constant_inc.php, the Composer autoloader,
# config_defaults_inc.php, config/custom_constants_inc.php, then the file) so
# mistakes surface here instead:
#   - fatal errors and uncaught exceptions while it runs (e.g. an undefined
#     constant, a type error);
#   - output, such as whitespace after a closing PHP tag, which corrupts downloads
#     and API responses;
#   - missing database settings or a crypto master salt under 16 characters;
#   - database credentials that do not connect (mysqli only).
# Warnings and notices are reported but do not block.
#
# Usage: php doctis-config-check.php <candidate-file>
# Prints one line "DOCTIS-CONFIG-CHECK <json>" with "errors" and "warnings".
# A fatal error stops this process before that line; the caller treats a
# missing result as a failure and shows what PHP printed.

# Command line only.  A web server may run files under admin/tools/ (live does),
# and STDERR is not defined there.
if( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
if( !isset( $argv[1] ) || !is_readable( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php doctis-config-check.php <readable candidate config file>\n" );
	exit( 2 );
}

$g_doctis_check = array( 'errors' => array(), 'warnings' => array() );

function doctis_check_result(): void {
	global $g_doctis_check;
	$g_doctis_check['warnings'] = array_values( array_unique( $g_doctis_check['warnings'] ) );
	echo "\nDOCTIS-CONFIG-CHECK " . json_encode( $g_doctis_check, JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

set_error_handler( function( $p_type, $p_message, $p_file, $p_line ) {
	global $g_doctis_check, $g_doctis_candidate;
	$t_where = $p_file === $g_doctis_candidate ? ' (line ' . $p_line . ')' : '';
	$g_doctis_check['warnings'][] = $p_message . $t_where;
	return true;
} );

$t_root = dirname( __DIR__, 2 );
$g_doctis_candidate = realpath( $argv[1] );

# Load as core.php does.  Everything runs at global scope, like the real load,
# so the candidate can build on defaults ($g_x = array_diff( $g_x, ... )).
require_once( $t_root . '/core/constant_inc.php' );
require_once( $t_root . '/vendor/autoload.php' );
require_once( $t_root . '/config_defaults_inc.php' );
if( file_exists( $t_root . '/config/custom_constants_inc.php' ) ) {
	require_once( $t_root . '/config/custom_constants_inc.php' );
}

ob_start();
try {
	include( $g_doctis_candidate );
} catch( Throwable $e ) {
	$g_doctis_check['errors'][] = get_class( $e ) . ': ' . $e->getMessage()
		. ( $e->getFile() === $g_doctis_candidate ? ' (line ' . $e->getLine() . ')' : '' );
}
$t_output = ob_get_clean();
if( $t_output !== '' ) {
	$g_doctis_check['errors'][] = 'The file produces output (' . strlen( $t_output ) . ' bytes, starting "'
		. addcslashes( substr( $t_output, 0, 20 ), "\0..\37" ) . '"), e.g. whitespace after a closing ?>.';
}
if( $g_doctis_check['errors'] ) {
	doctis_check_result();
}

# Settings Doctis cannot start without.
foreach( array( 'hostname', 'db_type', 'database_name', 'db_username' ) as $t_name ) {
	if( !isset( $GLOBALS['g_' . $t_name] ) || !is_string( $GLOBALS['g_' . $t_name] ) || $GLOBALS['g_' . $t_name] === '' ) {
		$g_doctis_check['errors'][] = '$g_' . $t_name . ' is not set.';
	}
}
if( !is_string( $g_crypto_master_salt ?? null ) || strlen( $g_crypto_master_salt ) < 16 ) {
	$g_doctis_check['errors'][] = '$g_crypto_master_salt must be at least 16 characters.';
}
if( $g_doctis_check['errors'] ) {
	doctis_check_result();
}

# The database must accept the credentials.
if( $g_db_type === 'mysqli' ) {
	mysqli_report( MYSQLI_REPORT_OFF );
	$t_host = $g_hostname;
	$t_port = null;
	$t_socket = null;
	if( preg_match( '/^(.+):(\d+)$/', $t_host, $t_match ) ) {
		$t_host = $t_match[1];
		$t_port = (int)$t_match[2];
	} else if( preg_match( '/^(.+):(\/.+)$/', $t_host, $t_match ) ) {
		$t_host = $t_match[1];
		$t_socket = $t_match[2];
	}
	$t_db = mysqli_init();
	mysqli_options( $t_db, MYSQLI_OPT_CONNECT_TIMEOUT, 5 );
	if( !@mysqli_real_connect( $t_db, $t_host, $g_db_username, (string)( $g_db_password ?? '' ), $g_database_name, $t_port, $t_socket ) ) {
		$g_doctis_check['errors'][] = 'The database rejects these settings: ' . mysqli_connect_error();
	} else {
		mysqli_close( $t_db );
	}
} else {
	$g_doctis_check['warnings'][] = 'Database type "' . $g_db_type . '" was not test-connected (only mysqli is).';
}

doctis_check_result();
