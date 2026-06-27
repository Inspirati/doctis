<?php
# Doctis — Git document store backup info page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_store_path = '/var/git/doctis';
$t_size_output = trim( @shell_exec( 'du -sh ' . escapeshellarg( $t_store_path ) . ' 2>/dev/null' ) );
$t_store_size = $t_size_output ? explode( "\t", $t_size_output )[0] : 'unknown';

$t_repo_count = 0;
if( is_dir( $t_store_path ) ) {
	$t_entries = glob( $t_store_path . '/*.git', GLOB_ONLYDIR );
	$t_repo_count = $t_entries ? count( $t_entries ) : 0;
}

layout_page_header( 'Backup Git Store — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-archive', 'ace-icon' ); ?>
			Backup Git Document Store
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<table class="table table-bordered table-condensed" style="max-width:600px;">
			<tr>
				<th class="category">Store path</th>
				<td><?php echo htmlspecialchars( $t_store_path ); ?></td>
			</tr>
			<tr>
				<th class="category">Bare repositories</th>
				<td><?php echo (int)$t_repo_count; ?></td>
			</tr>
			<tr>
				<th class="category">Total size</th>
				<td><?php echo htmlspecialchars( $t_store_size ); ?></td>
			</tr>
		</table>
		<p>
			The download will be a <code>.tar.gz</code> archive of all bare repositories
			under <code>/var/git/doctis/</code>.  Worktrees are excluded — they can be
			reconstructed from the bare repos with <code>git clone</code>.
		</p>
		<a href="manage_git_backup_download.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-download', 'ace-icon' ); ?> Download Git Backup
		</a>
		&nbsp;
		<a href="manage_overview_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back
		</a>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
