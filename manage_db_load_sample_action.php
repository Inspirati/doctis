<?php
# Doctis — Execute the sample data loader and display output.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'system_ops_api.php' );

form_security_validate( 'manage_db_load_sample' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

if( gpc_get_string( 'confirm', '' ) !== 'LOAD' ) {
	form_security_purge( 'manage_db_load_sample' );
	error_parameters( 'Confirmation text did not match. Type LOAD to proceed.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

form_security_purge( 'manage_db_load_sample' );

$t_script = '/var/www/html/doctis/admin/tools/doctis-load-sample-data.sh';
exec( 'echo yes | ' . system_ops_sudo_prefix() . escapeshellarg( $t_script ) . ' 2>&1', $t_lines, $t_rc );
$t_output = implode( "\n", $t_lines );

$t_success = ( $t_rc === 0 && strpos( $t_output, 'Test user accounts loaded' ) !== false );

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' loaded sample data at ' . date( 'c' ) . ' (exit ' . $t_rc . ')' );

layout_page_header( 'Load Sample Data — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box <?php echo $t_success ? 'widget-color-blue2' : 'widget-color-red'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-refresh', 'ace-icon' ); ?>
			Load Sample Data Result
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php if( !$t_success ) { ?>
		<div class="alert alert-danger">
			<strong>Sample data load failed or did not confirm success.</strong>
			If the database already contained sample data, you will see duplicate-key errors above.
			Rebuild the database first, then load sample data.
		</div>
		<?php } else { ?>
		<div class="alert alert-success">
			<strong>Sample data loaded successfully.</strong>
		</div>
		<?php } ?>
		<pre style="background:#f5f5f5;padding:12px;border:1px solid #ddd;overflow:auto;max-height:400px;"><?php echo htmlspecialchars( $t_output ); ?></pre>
		<div class="space-10"></div>
		<a href="manage_overview_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back to System Operations
		</a>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
