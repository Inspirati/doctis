<?php
# Doctis — Stream a mysqldump of the doctis database as a .sql.gz download.
# No state change; no CSRF token required.
# mysqldump runs via sudo as hcr (holds ~/.my.cnf credentials).
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'system_ops_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

# Resolve the sudo prefix before sending any headers, so a misconfiguration
# (unset $g_updater_run_as_user) surfaces as a normal error page.
$t_sudo_prefix = system_ops_sudo_prefix();

$t_filename = 'doctis_db_backup_' . date( 'Ymd_His' ) . '.sql.gz';

header( 'Content-Type: application/gzip' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

while( ob_get_level() ) {
	ob_end_clean();
}
flush();

$t_script = '/var/www/html/doctis/admin/tools/doctis-backup-database.sh';
$t_cmd = $t_sudo_prefix . escapeshellarg( $t_script ) . ' | /bin/gzip';
$t_handle = popen( $t_cmd, 'r' );
if( $t_handle === false ) {
	trigger_error( ERROR_GENERIC, ERROR );
}
while( !feof( $t_handle ) ) {
	echo fread( $t_handle, 65536 );
	flush();
}
pclose( $t_handle );
exit;
