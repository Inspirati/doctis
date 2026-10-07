<?php
# Doctis — Git document store reset confirmation page (DESTRUCTIVE).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'repository_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_entries = repository_storage_entries();
$t_repository_count = repository_count();

layout_page_header( 'Reset Git Store — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-red">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-trash', 'ace-icon' ); ?>
			Reset Git Store
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<div class="alert alert-danger">
			<strong>DESTRUCTIVE ACTION.</strong>
			This will <strong>permanently delete every document repository</strong> in
			<code><?php echo htmlspecialchars( config_get_global( 'git_storage_root' ) ); ?></code>
			and every server worktree in
			<code><?php echo htmlspecialchars( config_get_global( 'git_worktree_root' ) ); ?></code>,
			including their full history. The directories themselves are kept.
			This action cannot be undone; use Backup Git Store first if you need the contents.
		</div>

		<p><?php echo count( $t_entries ); ?> item(s) in the git store:</p>
		<pre style="max-height:300px;overflow:auto;"><?php echo htmlspecialchars( $t_entries ? implode( "\n", $t_entries ) : '(empty)' ); ?></pre>

		<?php if( $t_repository_count > 0 ) { ?>
		<div class="alert alert-warning">
			The database still has <?php echo $t_repository_count; ?> repository record(s), so its documents
			would lose their files. Run <a href="manage_db_rebuild_page.php">Rebuild Database</a> first;
			the git store can then be reset.
		</div>
		<?php } else if( empty( $t_entries ) ) { ?>
		<div class="alert alert-success">The git store is already empty.</div>
		<?php } else { ?>
		<p>Type <strong>RESET</strong> in the box below to confirm:</p>

		<form method="post" action="manage_git_reset_action.php">
			<?php echo form_security_field( 'manage_git_reset' ); ?>
			<div class="form-group" style="max-width:300px;">
				<input type="text" name="confirm" class="form-control" placeholder="Type RESET to confirm" autocomplete="off" required>
			</div>
			<button type="submit" class="btn btn-sm btn-danger">
				<?php print_icon( 'fa-trash', 'ace-icon' ); ?> Reset Git Store
			</button>
			&nbsp;
			<a href="manage_overview_page.php" class="btn btn-sm btn-default">Cancel</a>
		</form>
		<?php } ?>

	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
