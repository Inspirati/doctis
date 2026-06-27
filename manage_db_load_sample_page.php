<?php
# Doctis — Load sample data confirmation page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

layout_page_header( 'Load Sample Data — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box" style="border-color:#e8a838;">
	<div class="widget-header widget-header-small" style="background:#e8a838;">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-refresh', 'ace-icon' ); ?>
			Load Sample Data
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<div class="alert alert-warning">
			<strong>Warning:</strong> This will INSERT the example project, licences, and test user
			accounts into the current database.  The INSERT statements are <strong>NOT idempotent</strong>
			— running this against a database that already contains these rows will fail with
			duplicate-key errors.  Only run on a freshly rebuilt (empty) database.
		</div>

		<p>Loads:</p>
		<ul>
			<li>1 example project</li>
			<li>21 licence records</li>
			<li>14 test user accounts (7 role-named + 7 LOTR characters)</li>
		</ul>

		<p>Type <strong>LOAD</strong> in the box below to confirm:</p>

		<form method="post" action="manage_db_load_sample_action.php">
			<?php echo form_security_field( 'manage_db_load_sample' ); ?>
			<div class="form-group" style="max-width:300px;">
				<input type="text" name="confirm" class="form-control" placeholder="Type LOAD to confirm" autocomplete="off" required>
			</div>
			<button type="submit" class="btn btn-sm btn-warning">
				<?php print_icon( 'fa-refresh', 'ace-icon' ); ?> Load Sample Data
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
