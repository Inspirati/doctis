<?php
# Doctis — Application checkout status: what would block a git pull, and repairs.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'git_checkout_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_status = git_checkout_status();
$t_filemode_off = strtolower( $t_status['filemode'] ) === 'false';

/**
 * Print a list of paths, truncated to a manageable length.
 *
 * @param array   $p_paths
 * @param integer $p_limit
 * @return void
 */
function print_git_checkout_paths( array $p_paths, $p_limit = 50 ) {
	echo '<pre style="max-height:300px;overflow:auto;">';
	echo htmlspecialchars( implode( "\n", array_slice( $p_paths, 0, $p_limit ) ) );
	if( count( $p_paths ) > $p_limit ) {
		echo "\n… " . ( count( $p_paths ) - $p_limit ) . ' more (see the full report)';
	}
	echo '</pre>';
}

layout_page_header( 'Git Checkout — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-code-fork', 'ace-icon' ); ?>
			Application Git Checkout
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<?php if( empty( $t_status['blocking'] ) ) { ?>
		<div class="alert alert-success">
			No tracked file is modified: local changes will not block <code>git pull</code>.
		</div>
		<?php } else { ?>
		<div class="alert alert-warning">
			<?php echo count( $t_status['blocking'] ); ?> tracked file(s) are modified.
			<code>git pull</code> refuses any update that touches one of them.
		</div>
		<?php } ?>

		<table class="table table-bordered table-condensed" style="max-width:700px;">
			<tr><th class="category">Checkout</th><td><?php echo htmlspecialchars( git_checkout_path() ); ?></td></tr>
			<tr><th class="category">Branch</th><td><?php echo htmlspecialchars( $t_status['branch'] . ( $t_status['upstream'] ? ' → ' . $t_status['upstream'] : '' ) ); ?></td></tr>
			<tr><th class="category">Commit</th><td><?php echo htmlspecialchars( $t_status['head'] ); ?></td></tr>
			<tr><th class="category">core.fileMode</th><td><?php echo htmlspecialchars( $t_status['filemode'] !== '' ? $t_status['filemode'] : 'not set (true)' ); ?></td></tr>
			<tr><th class="category">Permission changes</th><td><?php echo count( $t_status['permission'] ); ?></td></tr>
			<tr><th class="category">Content changes</th><td><?php echo count( $t_status['content'] ); ?></td></tr>
			<tr><th class="category">Deleted files</th><td><?php echo count( $t_status['deleted'] ); ?> (do not block a pull)</td></tr>
			<tr><th class="category">Untracked paths</th><td><?php echo count( $t_status['untracked'] ); ?></td></tr>
			<tr><th class="category">Stashes</th><td><?php echo count( $t_status['stashes'] ); ?></td></tr>
			<tr><th class="category">Local commits not pushed</th><td><?php echo count( $t_status['local_commits'] ); ?></td></tr>
		</table>

		<p>
			<a href="manage_git_checkout_download.php?type=report" class="btn btn-sm btn-default">
				<?php print_icon( 'fa-file-text', 'ace-icon' ); ?> Download report (.txt)
			</a>
			&nbsp;
			<a href="manage_git_checkout_download.php?type=tree" class="btn btn-sm btn-default">
				<?php print_icon( 'fa-archive', 'ace-icon' ); ?> Download application files (.tar.gz, without .git)
			</a>
		</p>

		<?php if( !empty( $t_status['content'] ) ) { ?>
		<h5>Files with content changes</h5>
		<?php print_git_checkout_paths( $t_status['content'] ); ?>
		<?php } ?>

		<?php if( !empty( $t_status['untracked'] ) ) { ?>
		<h5>Untracked paths</h5>
		<?php print_git_checkout_paths( $t_status['untracked'] ); ?>
		<?php } ?>

		<?php if( !empty( $t_status['stashes'] ) || !empty( $t_status['local_commits'] ) ) { ?>
		<h5>Stashes and unpushed commits</h5>
		<?php print_git_checkout_paths( array_merge( $t_status['stashes'], $t_status['local_commits'] ) ); ?>
		<?php } ?>

		<hr />

		<?php if( !$t_filemode_off ) { ?>
		<form method="post" action="manage_git_checkout_action.php" style="margin-bottom:12px;">
			<?php echo form_security_field( 'manage_git_checkout' ); ?>
			<input type="hidden" name="action" value="ignore_permissions" />
			<button type="submit" class="btn btn-sm btn-primary">
				<?php print_icon( 'fa-unlock', 'ace-icon' ); ?> Ignore permission changes
			</button>
			<span class="small">Sets <code>core.fileMode=false</code>. Changes no files.</span>
		</form>
		<?php } ?>

		<?php if( !empty( $t_status['content'] ) ) { ?>
		<form method="post" action="manage_git_checkout_action.php">
			<?php echo form_security_field( 'manage_git_checkout' ); ?>
			<input type="hidden" name="action" value="restore_content" />
			<p class="small">
				Restores the <?php echo count( $t_status['content'] ); ?> file(s) above to the committed version.
				Their changes are first saved as a patch in <code>config/</code>; download the report before
				you continue. Type <strong>RESTORE</strong> to confirm.
			</p>
			<input type="text" name="confirm" size="12" autocomplete="off" />
			<button type="submit" class="btn btn-sm btn-danger">
				<?php print_icon( 'fa-undo', 'ace-icon' ); ?> Restore files
			</button>
		</form>
		<?php } ?>

	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
