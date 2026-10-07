<?php
# Doctis — Execute git document store reset (DESTRUCTIVE).
# The storage and worktree roots are owned by www-data, so no sudo is needed.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'repository_api.php' );

form_security_validate( 'manage_git_reset' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

if( gpc_get_string( 'confirm', '' ) !== 'RESET' ) {
	form_security_purge( 'manage_git_reset' );
	error_parameters( 'Confirmation text did not match. Type RESET to proceed.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

if( repository_count() > 0 ) {
	form_security_purge( 'manage_git_reset' );
	error_parameters( 'The database still has repository records. Run Rebuild Database first.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

form_security_purge( 'manage_git_reset' );

$t_result = repository_reset_storage();
$t_success = empty( $t_result['failed'] );

$t_output = count( $t_result['removed'] ) . " item(s) removed.\n";
foreach( $t_result['removed'] as $t_path ) {
	$t_output .= '  removed  ' . $t_path . "\n";
}
foreach( $t_result['failed'] as $t_path => $t_error ) {
	$t_output .= '  FAILED   ' . $t_path . ( $t_error !== '' ? ': ' . $t_error : '' ) . "\n";
}

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' reset the git store at ' . date( 'c' )
	. ' (' . count( $t_result['removed'] ) . ' removed, ' . count( $t_result['failed'] ) . ' failed)' );

layout_page_header( 'Reset Git Store — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box <?php echo $t_success ? 'widget-color-blue2' : 'widget-color-red'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-trash', 'ace-icon' ); ?>
			Reset Git Store Result
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php if( !$t_success ) { ?>
		<div class="alert alert-danger">
			<strong>Some items could not be removed.</strong>
			Check the output below.
		</div>
		<?php } else { ?>
		<div class="alert alert-success">
			<strong>Git store reset successfully.</strong>
			Repositories are created again on the next document upload.
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
