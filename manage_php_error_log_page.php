<?php
# Doctis — System Operations: the newest entries in PHP's error log, for
# servers nobody can reach by console.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'system_ops_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

# Only the end of the log is read, so a large log stays cheap to show.
$t_max_bytes = 2 * 1024 * 1024;
$t_counts = array( 50, 100, 500, 2000 );

$f_count = gpc_get_int( 'count', 100 );
if( !in_array( $f_count, $t_counts, true ) ) {
	$f_count = 100;
}
$f_filter = trim( gpc_get_string( 'filter', '' ) );

$t_log = system_ops_php_error_log();
$t_exists = $t_log !== '' && is_file( $t_log );
$t_lines = $t_exists ? system_ops_log_tail( $t_log, $t_max_bytes ) : false;
$t_entries = $t_lines === false ? array() : system_ops_log_entries( $t_lines );
if( $f_filter !== '' ) {
	$t_entries = array_values( array_filter( $t_entries, function( $p_entry ) use ( $f_filter ) {
		return stripos( $p_entry, $f_filter ) !== false;
	} ) );
}
$t_shown = array_slice( $t_entries, 0, $f_count );

/**
 * Whether a php.ini flag is on, as ini_get() reports it.
 *
 * @param string $p_name
 * @return boolean
 */
function php_error_log_ini_on( $p_name ) {
	return in_array( strtolower( (string)ini_get( $p_name ) ), array( '1', 'on', 'yes', 'true', 'stdout', 'stderr' ), true );
}

layout_page_header( 'PHP Error Log — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-exclamation-triangle', 'ace-icon' ); ?>
			PHP Error Log
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<table class="table table-bordered table-condensed" style="max-width:700px;">
			<tr>
				<th class="category">Log file</th>
				<td>
					<?php if( $t_log === '' ) { ?>
					None. PHP sends errors to <code><?php echo htmlspecialchars( (string)ini_get( 'error_log' ) !== '' ? (string)ini_get( 'error_log' ) : 'the web server' ); ?></code>, which this page cannot read.
					<?php } else { ?>
					<code><?php echo htmlspecialchars( $t_log ); ?></code>
					<?php } ?>
				</td>
			</tr>
			<?php if( $t_exists ) { ?>
			<tr>
				<th class="category">Size</th>
				<td><?php echo number_format( (int)filesize( $t_log ) ); ?> bytes, last written <?php echo htmlspecialchars( date( config_get( 'normal_date_format' ), (int)filemtime( $t_log ) ) ); ?></td>
			</tr>
			<?php } ?>
			<tr>
				<th class="category">log_errors</th>
				<td><?php echo php_error_log_ini_on( 'log_errors' ) ? 'On' : '<strong>Off</strong>: PHP writes no errors to the log'; ?></td>
			</tr>
			<tr>
				<th class="category">display_errors</th>
				<td><?php echo php_error_log_ini_on( 'display_errors' )
					? '<strong>On</strong>: PHP also prints errors, with server paths, in the pages users see'
					: 'Off: PHP errors go only to the log'; ?></td>
			</tr>
		</table>

		<?php if( $t_log !== '' && !$t_exists ) { ?>
		<div class="alert alert-info">The log file does not exist yet. PHP creates it when it logs its first error.</div>
		<?php } else if( $t_exists && $t_lines === false ) { ?>
		<div class="alert alert-warning">The log file exists but the web server cannot read it.</div>
		<?php } else if( $t_exists ) { ?>

		<form method="get" action="manage_php_error_log_page.php" class="form-inline" style="margin-bottom:12px;">
			<label for="php-log-filter">Containing</label>
			<input type="text" id="php-log-filter" name="filter" size="30" class="input-sm"
				value="<?php echo htmlspecialchars( $f_filter ); ?>" />
			&nbsp;
			<label for="php-log-count">Show</label>
			<select id="php-log-count" name="count" class="input-sm">
				<?php foreach( $t_counts as $t_count ) { ?>
				<option value="<?php echo $t_count; ?>"<?php echo $t_count === $f_count ? ' selected="selected"' : ''; ?>><?php echo $t_count; ?></option>
				<?php } ?>
			</select>
			entries
			&nbsp;
			<button type="submit" class="btn btn-sm btn-primary">
				<?php print_icon( 'fa-refresh', 'ace-icon' ); ?> Show
			</button>
		</form>

		<p class="small">
			<?php if( empty( $t_entries ) ) { ?>
			No entries<?php echo $f_filter !== '' ? ' contain that text' : ''; ?>
			<?php } else { ?>
			Newest first: <?php echo count( $t_shown ); ?> of <?php echo count( $t_entries ); ?>
			<?php echo $f_filter !== '' ? 'matching entries' : 'entries'; ?>
			<?php } ?>
			in the last <?php echo number_format( $t_max_bytes / 1024 / 1024 ); ?> MB of the log.
		</p>

		<?php if( !empty( $t_shown ) ) { ?>
		<pre style="max-height:600px;overflow:auto;white-space:pre-wrap;"><?php
			echo htmlspecialchars( implode( "\n\n", $t_shown ) );
		?></pre>
		<?php } ?>

		<?php } ?>

	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
