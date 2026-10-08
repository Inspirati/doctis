<?php
# Doctis — Run an uploaded SQL data script and display the outcome.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'db_script_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'utility_api.php' );

/**
 * Stop with an error page.
 *
 * @param string $p_message Message for the administrator.
 * @return void
 */
function load_script_fail( string $p_message ): void {
	error_parameters( $p_message );
	trigger_error( ERROR_GENERIC, ERROR );
}

# A request larger than post_max_size arrives with an empty $_POST, which
# would otherwise surface as a confusing security-token error.
$t_post_max = ini_get_number( 'post_max_size' );
if( empty( $_POST ) && (int)( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > $t_post_max ) {
	load_script_fail( 'The upload is larger than the server accepts ('
		. number_format( $t_post_max / 1048576, 1 ) . ' MB). Compress the script with gzip and try again.' );
}

form_security_validate( 'manage_db_load_script' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

if( gpc_get_string( 'confirm', '' ) !== 'LOAD' ) {
	form_security_purge( 'manage_db_load_script' );
	load_script_fail( 'Confirmation text did not match. Type LOAD to proceed.' );
}
form_security_purge( 'manage_db_load_script' );

$f_file = gpc_get_file( 'script_file', null );
$f_transaction = gpc_get_bool( 'transaction', false );

if( !is_array( $f_file ) || ( $f_file['error'] ?? UPLOAD_ERR_NO_FILE ) == UPLOAD_ERR_NO_FILE ) {
	load_script_fail( 'No script file was uploaded.' );
}
if( $f_file['error'] == UPLOAD_ERR_INI_SIZE || $f_file['error'] == UPLOAD_ERR_FORM_SIZE ) {
	load_script_fail( 'The script file is larger than the server accepts. Compress it with gzip and try again.' );
}
if( $f_file['error'] != UPLOAD_ERR_OK || !is_uploaded_file( $f_file['tmp_name'] ) ) {
	load_script_fail( 'The upload failed (PHP upload error ' . (int)$f_file['error'] . ').' );
}

$t_filename = basename( (string)$f_file['name'] );
$t_raw = file_get_contents( $f_file['tmp_name'] );
if( $t_raw === false ) {
	load_script_fail( 'The uploaded file could not be read.' );
}
$t_packet_bytes = db_script_max_bytes();

# gzip, detected by its magic bytes rather than the file name.
$t_compressed = strncmp( $t_raw, "\x1f\x8b", 2 ) === 0;
if( $t_compressed ) {
	$t_sql = @gzdecode( $t_raw, $t_packet_bytes + 1 );
	if( $t_sql === false ) {
		load_script_fail( 'The file looks gzip-compressed but could not be decompressed, '
			. 'or it is larger than ' . number_format( $t_packet_bytes / 1048576, 1 ) . ' MB when decompressed.' );
	}
} else {
	$t_sql = $t_raw;
}
unset( $t_raw );

if( strncmp( $t_sql, "\xEF\xBB\xBF", 3 ) === 0 ) {
	$t_sql = substr( $t_sql, 3 );
}
if( trim( $t_sql ) === '' ) {
	load_script_fail( 'The script is empty.' );
}
if( strlen( $t_sql ) > $t_packet_bytes ) {
	load_script_fail( 'The script is ' . number_format( strlen( $t_sql ) / 1048576, 1 )
		. ' MB; the database server accepts at most '
		. number_format( $t_packet_bytes / 1048576, 1 ) . ' MB (max_allowed_packet). Split it into smaller scripts.' );
}

$t_script_version = db_script_header_version( $t_sql );
$t_db_version = db_script_schema_version();
$t_has_ddl = db_script_has_ddl( $t_sql );
$t_sha256 = hash( 'sha256', $t_sql );
$t_bytes = strlen( $t_sql );

$t_outcome = db_script_run( $t_sql, $f_transaction );
unset( $t_sql );

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' loaded data script "' . $t_filename
	. '" (' . $t_bytes . ' bytes, sha256 ' . $t_sha256 . ', transaction ' . ( $f_transaction ? 'on' : 'off' )
	. ') at ' . date( 'c' ) . ': ' . ( $t_outcome['ok']
		? 'ok, ' . $t_outcome['statements'] . ' statements'
		: 'failed at statement ' . ( $t_outcome['statements'] + 1 ) . ': ' . $t_outcome['error'] ) );

layout_page_header( 'Load Data — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box <?php echo $t_outcome['ok'] ? 'widget-color-blue2' : 'widget-color-red'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-upload', 'ace-icon' ); ?>
			Load Data Script Result
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php if( $t_outcome['ok'] ) { ?>
		<div class="alert alert-success">
			<strong>Script loaded.</strong>
			<?php echo (int)$t_outcome['statements']; ?> statements ran.
		</div>
		<?php } else { ?>
		<div class="alert alert-danger">
			<strong>Statement <?php echo (int)$t_outcome['statements'] + 1; ?> failed</strong>
			(<?php echo (int)$t_outcome['statements']; ?> statements before it succeeded).
			<?php if( $t_outcome['rolled_back'] ) { ?>
				The transaction was rolled back<?php echo $t_has_ddl
					? ', but statements MariaDB commits implicitly (CREATE, DROP, ALTER, TRUNCATE, RENAME) stay applied'
					: ', so no changes were kept'; ?>.
			<?php } else { ?>
				The statements before it stay applied.
			<?php } ?>
			<pre style="margin:8px 0 0;white-space:pre-wrap;"><?php
				echo htmlspecialchars( 'Error ' . $t_outcome['errno'] . ': ' . $t_outcome['error'] ); ?></pre>
		</div>
		<?php } ?>

		<?php if( $t_script_version !== null && $t_script_version !== $t_db_version ) { ?>
		<div class="alert alert-warning">
			The script was taken from schema version <?php echo (int)$t_script_version; ?>; this
			database is at version <?php echo (int)$t_db_version; ?>. Check that the loaded data
			fits the current schema.
		</div>
		<?php } ?>

		<table class="table table-bordered table-condensed" style="max-width:700px;">
			<tr>
				<th class="category">File</th>
				<td><?php echo htmlspecialchars( $t_filename ); ?><?php echo $t_compressed ? ' (gzip)' : ''; ?></td>
			</tr>
			<tr>
				<th class="category">Size</th>
				<td><?php echo number_format( $t_bytes ); ?> bytes</td>
			</tr>
			<tr>
				<th class="category">SHA-256</th>
				<td><code><?php echo htmlspecialchars( $t_sha256 ); ?></code></td>
			</tr>
			<tr>
				<th class="category">Transaction</th>
				<td><?php echo $f_transaction ? 'Yes' : 'No'; ?></td>
			</tr>
			<tr>
				<th class="category">Schema version</th>
				<td>Script: <?php echo $t_script_version === null ? 'not stated' : (int)$t_script_version; ?>;
					database: <?php echo (int)$t_db_version; ?></td>
			</tr>
			<tr>
				<th class="category">Rows affected</th>
				<td><?php echo number_format( $t_outcome['rows'] ); ?>
					(as reported by the server; REPLACE counts an overwritten row twice)</td>
			</tr>
		</table>

		<a href="manage_db_load_page.php" class="btn btn-sm btn-warning">
			<?php print_icon( 'fa-upload', 'ace-icon' ); ?> Load Another Script
		</a>
		&nbsp;
		<a href="manage_overview_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back to System Operations
		</a>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
