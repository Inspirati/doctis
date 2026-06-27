<?php
# Doctis — Database rebuild confirmation page (DESTRUCTIVE).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_db_name = config_get_global( 'database_name' );

layout_page_header( 'Rebuild Database — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-red">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-trash', 'ace-icon' ); ?>
			Rebuild Database
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<div class="alert alert-danger">
			<strong>DESTRUCTIVE ACTION.</strong>
			This will <strong>permanently delete all data</strong> in the
			<code><?php echo htmlspecialchars( $t_db_name ); ?></code> database
			and rebuild it from <code>admin/schema.php</code>.
			Sample data will <strong>NOT</strong> be reloaded.
			This action cannot be undone.
			The git document store is unaffected.
		</div>

		<p>Type <strong>REBUILD</strong> in the box below to confirm:</p>

		<form method="post" action="manage_db_rebuild_action.php">
			<?php echo form_security_field( 'manage_db_rebuild' ); ?>
			<div class="form-group" style="max-width:300px;">
				<input type="text" name="confirm" class="form-control" placeholder="Type REBUILD to confirm" autocomplete="off" required>
			</div>
			<button type="submit" class="btn btn-sm btn-danger">
				<?php print_icon( 'fa-trash', 'ace-icon' ); ?> Rebuild Database
			</button>
			&nbsp;
			<a href="manage_overview_page.php" class="btn btn-sm btn-default">Cancel</a>
		</form>

	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
