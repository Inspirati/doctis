<?php
# Doctis — Git pull confirmation page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_branch  = trim( @shell_exec( 'git -C /var/www/html/doctis rev-parse --abbrev-ref HEAD 2>/dev/null' ) );
$t_commit  = trim( @shell_exec( 'git -C /var/www/html/doctis rev-parse --short=10 HEAD 2>/dev/null' ) );
$t_origin  = trim( @shell_exec( 'git -C /var/www/html/doctis config --get remote.origin.url 2>/dev/null' ) );

layout_page_header( 'Git Pull — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-download', 'ace-icon' ); ?>
			Git Pull (Self-Update)
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<table class="table table-bordered table-condensed" style="max-width:600px;">
			<?php if( $t_origin ) { ?>
			<tr>
				<th class="category">Remote origin</th>
				<td><?php echo htmlspecialchars( $t_origin ); ?></td>
			</tr>
			<?php } ?>
			<tr>
				<th class="category">Current branch</th>
				<td><?php echo htmlspecialchars( $t_branch ?: 'unknown' ); ?></td>
			</tr>
			<tr>
				<th class="category">Current commit</th>
				<td><?php echo htmlspecialchars( $t_commit ?: 'unknown' ); ?></td>
			</tr>
		</table>

		<p>
			This will run <code>git pull</code> on the live application directory.
			The operation is safe to retry if it fails due to local uncommitted changes
			— git will report the conflict and exit without modifying anything.
		</p>

		<form method="post" action="manage_git_pull_action.php">
			<?php echo form_security_field( 'manage_git_pull' ); ?>
			<button type="submit" class="btn btn-sm btn-primary">
				<?php print_icon( 'fa-download', 'ace-icon' ); ?> Run git pull
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
