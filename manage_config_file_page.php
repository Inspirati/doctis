<?php
# Doctis — Config file download and upload page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_config_path = dirname( __FILE__ ) . '/config/config_inc.php';
$t_uploaded = gpc_get_bool( 'uploaded', false );

$t_exists   = file_exists( $t_config_path );
$t_size     = $t_exists ? filesize( $t_config_path ) : 0;
$t_mtime    = $t_exists ? date( 'Y-m-d H:i:s', filemtime( $t_config_path ) ) : 'n/a';

layout_page_header( 'Config File — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-file-text', 'ace-icon' ); ?>
			Config File
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<?php if( $t_uploaded ) { ?>
		<div class="alert alert-success">
			<strong>Config file updated successfully.</strong>
			The previous version was backed up in <code>config/</code>.
		</div>
		<?php } ?>

		<table class="table table-bordered table-condensed" style="max-width:600px;">
			<tr>
				<th class="category">Path</th>
				<td><?php echo htmlspecialchars( $t_config_path ); ?></td>
			</tr>
			<tr>
				<th class="category">Size</th>
				<td><?php echo number_format( $t_size ); ?> bytes</td>
			</tr>
			<tr>
				<th class="category">Modified</th>
				<td><?php echo htmlspecialchars( $t_mtime ); ?></td>
			</tr>
		</table>

		<h5>Download</h5>
		<p>
			<a href="manage_config_file_download.php" class="btn btn-sm btn-default">
				<?php print_icon( 'fa-download', 'ace-icon' ); ?> Download config_inc.php
			</a>
		</p>

		<hr>

		<h5>Upload Replacement</h5>
		<p class="text-warning">
			<strong>Warning:</strong> uploading an invalid config file may break the application.
			Download a backup first.
		</p>
		<form method="post" enctype="multipart/form-data" action="manage_config_file_upload.php">
			<?php echo form_security_field( 'manage_config_file_upload' ); ?>
			<div class="form-group">
				<label for="config_file">Replacement <code>config_inc.php</code></label>
				<input type="file" id="config_file" name="config_file" accept=".php,text/plain" class="form-control" required>
				<p class="help-block">Must begin with <code>&lt;?php</code>. Maximum 128 KB.</p>
			</div>
			<button type="submit" class="btn btn-sm btn-primary">
				<?php print_icon( 'fa-upload', 'ace-icon' ); ?> Upload Config
			</button>
		</form>

		<div class="space-10"></div>
		<a href="manage_overview_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back
		</a>

	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
