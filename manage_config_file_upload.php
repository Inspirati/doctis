<?php
# Doctis — Receive an uploaded config_inc.php and write it via the sudo wrapper.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'system_ops_api.php' );

form_security_validate( 'manage_config_file_upload' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

if( !isset( $_FILES['config_file'] ) || $_FILES['config_file']['error'] !== UPLOAD_ERR_OK ) {
	form_security_purge( 'manage_config_file_upload' );
	error_parameters( 'No file uploaded or upload error.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

$t_tmp = $_FILES['config_file']['tmp_name'];
$t_size = $_FILES['config_file']['size'];

if( $t_size > 131072 ) {
	form_security_purge( 'manage_config_file_upload' );
	error_parameters( 'Uploaded config file exceeds 128 KB limit.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

$t_content = file_get_contents( $t_tmp );
if( strncmp( $t_content, '<?php', 5 ) !== 0 ) {
	form_security_purge( 'manage_config_file_upload' );
	error_parameters( 'Uploaded file does not begin with <?php — rejecting.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

$t_script = '/var/www/html/doctis/admin/tools/doctis-write-config.sh';
$t_proc = proc_open(
	system_ops_sudo_prefix() . escapeshellarg( $t_script ),
	array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$t_pipes
);

if( $t_proc === false ) {
	form_security_purge( 'manage_config_file_upload' );
	error_parameters( 'Failed to launch config write helper.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

fwrite( $t_pipes[0], $t_content );
fclose( $t_pipes[0] );
$t_stdout = stream_get_contents( $t_pipes[1] );
$t_stderr = stream_get_contents( $t_pipes[2] );
fclose( $t_pipes[1] );
fclose( $t_pipes[2] );
$t_rc = proc_close( $t_proc );

form_security_purge( 'manage_config_file_upload' );

if( $t_rc !== 0 ) {
	error_parameters( 'Config write failed (exit ' . $t_rc . '): ' . trim( $t_stderr ) );
	trigger_error( ERROR_GENERIC, ERROR );
}

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' uploaded config_inc.php at ' . date( 'c' ) );

print_header_redirect( 'manage_config_file_page.php?uploaded=1' );
