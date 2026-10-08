<?php
# Doctis — Download a data snapshot (data only, selected tables) as an SQL
# script that Load Data can run after a database rebuild.
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'db_script_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );

form_security_validate( 'manage_db_snapshot' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$f_tables = gpc_get_string_array( 'tables', array() );
$f_replace = gpc_get_string( 'mode', 'replace' ) !== 'insert';

form_security_purge( 'manage_db_snapshot' );

$t_known = db_script_tables();
$t_tables = array_values( array_intersect( array_keys( $t_known ), $f_tables ) );
if( empty( $t_tables ) ) {
	error_parameters( 'Select at least one table for the snapshot.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

$t_label = count( $t_tables ) == 1 ? $t_tables[0] : count( $t_tables ) . '-tables';
$t_filename = 'doctis_data_' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $t_label ) . '_' . date( 'Ymd_His' ) . '.sql';

header( 'Content-Type: application/sql; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

while( ob_get_level() ) {
	ob_end_clean();
}

db_script_snapshot( $t_tables, $f_replace, function( string $p_chunk ) {
	echo $p_chunk;
} );

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' downloaded a data snapshot of '
	. implode( ', ', $t_tables ) . ' at ' . date( 'c' ) );
exit;
