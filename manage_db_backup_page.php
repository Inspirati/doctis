<?php
# Doctis — Database backup info page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_db_name = config_get_global( 'database_name' );

layout_page_header( 'Backup Database — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-database', 'ace-icon' ); ?>
			Backup Database
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<table class="table table-bordered table-condensed" style="max-width:600px;">
			<tr>
				<th class="category">Database</th>
				<td><?php echo htmlspecialchars( $t_db_name ); ?></td>
			</tr>
			<tr>
				<th class="category">Format</th>
				<td>SQL dump, gzip-compressed (<code>.sql.gz</code>)</td>
			</tr>
			<tr>
				<th class="category">Options</th>
				<td><code>--single-transaction --routines --triggers --add-drop-table</code></td>
			</tr>
		</table>
		<p>
			The dump is streamed directly to your browser — nothing is written to disk on the server.
		</p>
		<a href="manage_db_backup_download.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-download', 'ace-icon' ); ?> Download Database Backup
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
