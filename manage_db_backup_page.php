<?php
# Doctis — Database backup info page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'db_script_api.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_db_name = config_get_global( 'database_name' );
$t_tables = db_script_tables();

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

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-table', 'ace-icon' ); ?>
			Data Snapshot
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p>
			Download the rows of the tables you select as an SQL script, to reload with
			<a href="manage_db_load_page.php">Load Data</a> after a database rebuild. For
			example, select <code>user</code> to keep your initial user accounts. The script
			holds data only. It never drops or creates tables, and every statement names its
			columns, so it still loads after a rebuild that adds columns.
		</p>
		<p>
			Rows a rebuild seeds itself (for example the <code>administrator</code> account, or
			the <code>database_version</code> setting in <code>config</code>) are in the
			snapshot too. With <strong>REPLACE</strong> the snapshot's copy overwrites them.
			Avoid loading an old copy of <code>config</code>: it can make Doctis report that
			the schema needs upgrading.
		</p>

		<form method="post" action="manage_db_snapshot_download.php">
			<?php echo form_security_field( 'manage_db_snapshot' ); ?>
			<div style="column-width:16em;column-gap:2em;margin-bottom:12px;">
			<?php foreach( $t_tables as $t_table => $t_rows ) { ?>
				<div class="checkbox" style="margin:0 0 4px;break-inside:avoid;">
					<label>
						<input type="checkbox" name="tables[]" value="<?php echo htmlspecialchars( $t_table ); ?>" />
						<code><?php echo htmlspecialchars( $t_table ); ?></code>
						<span class="grey">(<?php echo number_format( $t_rows ); ?>)</span>
					</label>
				</div>
			<?php } ?>
			</div>
			<div class="radio">
				<label>
					<input type="radio" name="mode" value="replace" checked="checked" />
					<strong>REPLACE</strong>: overwrite rows that have the same key (recommended after a rebuild)
				</label>
			</div>
			<div class="radio">
				<label>
					<input type="radio" name="mode" value="insert" />
					<strong>INSERT</strong>: stop if a row with the same key already exists
				</label>
			</div>
			<button type="submit" class="btn btn-sm btn-default">
				<?php print_icon( 'fa-download', 'ace-icon' ); ?> Download Data Snapshot
			</button>
		</form>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
