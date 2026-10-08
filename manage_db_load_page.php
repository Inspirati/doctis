<?php
# Doctis — Load Data page: run an uploaded SQL data script, or load the
# built-in sample data.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'db_script_api.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'utility_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_db_name = config_get_global( 'database_name' );
$t_max_bytes = min(
	ini_get_number( 'upload_max_filesize' ),
	ini_get_number( 'post_max_size' )
);
$t_packet_bytes = db_script_max_bytes();

layout_page_header( 'Load Data — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box" style="border-color:#e8a838;">
	<div class="widget-header widget-header-small" style="background:#e8a838;">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-upload', 'ace-icon' ); ?>
			Load Data Script
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<p>
			Upload an SQL script to run against the
			<code><?php echo htmlspecialchars( $t_db_name ); ?></code> database, typically
			straight after <strong>Rebuild Database</strong>. Use it to restore your
			initial user accounts, or the starting data of pilot and test projects.
		</p>
		<p>
			Create a script from an existing database with
			<a href="manage_db_backup_page.php">Backup Database</a> &rarr;
			<strong>Data Snapshot</strong>. That page writes data only, for the tables
			you choose, so the script still loads after the schema has changed.
		</p>

		<div class="alert alert-warning">
			<ul style="margin-bottom:0;">
				<li>The script runs through the Doctis database account. Statements run in
					order and <strong>stop at the first error</strong>.</li>
				<li>Run as one transaction (the default) to roll everything back if a statement
					fails. MariaDB commits CREATE, DROP, ALTER, TRUNCATE and RENAME
					immediately, so a transaction cannot undo those.</li>
				<li>A full <strong>Download Database Backup</strong> drops and recreates every
					table. Load one only on a database of the same schema version.</li>
				<li>Loading a database script does not restore primary document files. They
					live in the git store.</li>
				<li>If the script replaces your own account, you may have to log in again.</li>
				<li>Accepted files: <code>.sql</code> or gzip-compressed <code>.sql.gz</code>.
					Largest upload: <?php echo htmlspecialchars( number_format( $t_max_bytes / 1048576, 1 ) ); ?> MB.
					Largest script after decompression:
					<?php echo htmlspecialchars( number_format( $t_packet_bytes / 1048576, 1 ) ); ?> MB.
					Client commands such as <code>DELIMITER</code> and <code>SOURCE</code> are
					not supported.</li>
			</ul>
		</div>

		<form method="post" enctype="multipart/form-data" action="manage_db_load_script_action.php">
			<?php echo form_security_field( 'manage_db_load_script' ); ?>
			<div class="form-group">
				<label for="load-script-file">SQL script</label>
				<input type="file" id="load-script-file" name="script_file" accept=".sql,.gz" required />
			</div>
			<div class="checkbox">
				<label>
					<input type="checkbox" name="transaction" value="1" checked="checked" />
					Run as one transaction (roll back if a statement fails)
				</label>
			</div>
			<p>Type <strong>LOAD</strong> in the box below to confirm:</p>
			<div class="form-group" style="max-width:300px;">
				<input type="text" name="confirm" class="form-control" placeholder="Type LOAD to confirm" autocomplete="off" required>
			</div>
			<button type="submit" class="btn btn-sm btn-warning">
				<?php print_icon( 'fa-upload', 'ace-icon' ); ?> Load Data Script
			</button>
			&nbsp;
			<a href="manage_overview_page.php" class="btn btn-sm btn-default">Cancel</a>
		</form>

	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-refresh', 'ace-icon' ); ?>
			Built-in Sample Data
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<p>
			Inserts the example project, 21 licence records and 14 test user accounts
			(7 role-named and 7 LOTR characters) from
			<code>admin/tools/doctis-load-sample-data.sh</code>. The INSERT statements are
			<strong>not idempotent</strong>: run this only on a freshly rebuilt database.
		</p>

		<form method="post" action="manage_db_load_sample_action.php">
			<?php echo form_security_field( 'manage_db_load_sample' ); ?>
			<p>Type <strong>LOAD</strong> in the box below to confirm:</p>
			<div class="form-group" style="max-width:300px;">
				<input type="text" name="confirm" class="form-control" placeholder="Type LOAD to confirm" autocomplete="off" required>
			</div>
			<button type="submit" class="btn btn-sm btn-warning">
				<?php print_icon( 'fa-refresh', 'ace-icon' ); ?> Load Sample Data
			</button>
		</form>

	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
