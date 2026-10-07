<?php
# Doctis — Repair the application checkout so local changes no longer block git pull.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'git_checkout_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

form_security_validate( 'manage_git_checkout' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$f_action = gpc_get_string( 'action', '' );

switch( $f_action ) {
	case 'ignore_permissions':
		$t_title = 'Ignore permission changes';
		$t_output = git_checkout_ignore_permissions( $t_rc );
		break;
	case 'restore_content':
		if( gpc_get_string( 'confirm', '' ) !== 'RESTORE' ) {
			form_security_purge( 'manage_git_checkout' );
			error_parameters( 'Confirmation text did not match. Type RESTORE to proceed.' );
			trigger_error( ERROR_GENERIC, ERROR );
		}
		$t_title = 'Restore files';
		$t_output = git_checkout_restore_content( $t_rc );
		break;
	default:
		error_parameters( 'Unknown action.' );
		trigger_error( ERROR_GENERIC, ERROR );
}

form_security_purge( 'manage_git_checkout' );

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' ran git checkout repair "' . $f_action
	. '" at ' . date( 'c' ) . ' (exit ' . $t_rc . ')' );

layout_page_header( 'Git Checkout — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box <?php echo $t_rc === 0 ? 'widget-color-blue2' : 'widget-color-red'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-code-fork', 'ace-icon' ); ?>
			<?php echo htmlspecialchars( $t_title ); ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php if( $t_rc !== 0 ) { ?>
		<div class="alert alert-danger">
			<strong>Failed</strong> (exit code <?php echo (int)$t_rc; ?>).
		</div>
		<?php } else { ?>
		<div class="alert alert-success">
			<strong>Completed successfully.</strong>
		</div>
		<?php } ?>
		<pre style="background:#f5f5f5;padding:12px;border:1px solid #ddd;overflow:auto;max-height:400px;"><?php echo htmlspecialchars( $t_output ); ?></pre>
		<div class="space-10"></div>
		<a href="manage_git_checkout_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back to Git Checkout
		</a>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
