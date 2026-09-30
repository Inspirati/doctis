<?php
# Doctis — receive a ZIP snapshot and invoke the existing one-time importer.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'import_zip_api.php' );
require_api( 'lang_api.php' );
require_api( 'project_api.php' );
require_api( 'user_api.php' );

form_security_validate( 'manage_import_data' );
auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
set_time_limit( 0 );

$t_stage = '';
$t_report = '';
$t_error = '';
$t_project_name = trim( gpc_get_string( 'project_name', '' ) );
$t_archive_name = '';
$t_project_id = 0;

try {
	if( $t_project_name === '' || strlen( $t_project_name ) > 128
		|| preg_match( '/[[:cntrl:]]/', $t_project_name ) ) {
		throw new RuntimeException( 'Enter a project name of at most 128 characters.' );
	}
	if( project_get_id_by_name( $t_project_name, 0 ) > 0 ) {
		throw new RuntimeException( 'A project with that name already exists. This import creates a new project.' );
	}
	$t_roots = array_values( array_unique( array_filter(
		array_map( 'trim', explode( ',', gpc_get_string( 'directories', '' ) ) )
	) ) );
	$t_patterns = trim( gpc_get_string( 'patterns', '' ) );
	if( $t_patterns === '' || strlen( $t_patterns ) > 255 || strpbrk( $t_patterns, "\r\n" ) !== false ) {
		throw new RuntimeException( 'Enter one or more comma-separated document patterns.' );
	}
	$t_pattern_list = array_values( array_filter( array_map( 'trim', explode( ',', $t_patterns ) ) ) );
	$t_visibility = gpc_get_string( 'visibility', 'private' );
	if( !in_array( $t_visibility, array( 'private', 'public' ), true ) ) {
		throw new RuntimeException( 'Choose private or public project visibility.' );
	}
	if( !isset( $_FILES['archive'] ) || $_FILES['archive']['error'] !== UPLOAD_ERR_OK
		|| !is_uploaded_file( $_FILES['archive']['tmp_name'] ) ) {
		throw new RuntimeException( 'The ZIP upload did not complete. Check the server upload limit.' );
	}
	$t_archive_name = basename( str_replace( '\\', '/', $_FILES['archive']['name'] ) );
	if( !preg_match( '/\.zip$/i', $t_archive_name ) || strlen( $t_archive_name ) > 200
		|| preg_match( '/[[:cntrl:]]/', $t_archive_name ) ) {
		throw new RuntimeException( 'Choose a .zip file with a filename of at most 200 bytes.' );
	}
	$t_staged = doctis_import_zip_stage( $_FILES['archive']['tmp_name'], $t_roots, $t_pattern_list );
	$t_stage = $t_staged['path'];
	$t_present_roots = array_values( array_filter( $t_roots,
		static fn( $p_root ) => is_dir( $t_stage . '/' . $p_root ) ) );
	foreach( array(
		array( 'git', 'init', '-b', 'dev', $t_stage ),
		array_merge( array( 'git', '-C', $t_stage, 'add', '-f', '--' ), $t_present_roots ),
		array( 'git', '-C', $t_stage, '-c', 'user.name=Doctis Import',
			'-c', 'user.email=doctis-import@localhost', 'commit',
			'-m', 'Import documents from ' . $t_archive_name ),
	) as $t_command ) {
		list( $t_rc, $t_output ) = doctis_import_zip_command( $t_command );
		if( $t_rc !== 0 ) {
			throw new RuntimeException( 'Could not prepare the import repository: ' . trim( $t_output ) );
		}
	}
	$t_php = PHP_BINDIR . '/php';
	if( !is_executable( $t_php ) ) {
		throw new RuntimeException( 'The PHP command-line executable is unavailable.' );
	}
	list( $t_rc, $t_report ) = doctis_import_zip_command( array(
		$t_php, __DIR__ . '/admin/import-git-repo.php',
		'--source', $t_stage, '--source-label', 'Uploaded ZIP: ' . $t_archive_name,
		'--user', user_get_username( auth_get_current_user_id() ),
		'--name', $t_project_name, '--directories', implode( ',', $t_present_roots ),
		'--patterns', $t_patterns, '--subprojects', 'none',
		'--frontmatter', 'yes', '--category-from', 'directory',
		'--project-visibility', $t_visibility,
	) );
	if( $t_rc !== 0 ) {
		throw new RuntimeException( 'The importer reported a failure. Review the report below; a partial project may require a test reset before retrying.' );
	}
	$t_project_id = project_get_id_by_name( $t_project_name, 0 );
} catch( Throwable $t_exception ) {
	$t_error = $t_exception->getMessage();
} finally {
	if( $t_stage !== '' ) {
		doctis_import_zip_cleanup( $t_stage );
	}
	form_security_purge( 'manage_import_data' );
}

layout_page_header( lang_get( 'manage_import_link' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_import_data_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">Repository import result</h4>
		</div>
		<div class="widget-body"><div class="widget-main">
			<?php if( $t_error !== '' ) { ?>
				<div class="alert alert-danger"><?php echo htmlspecialchars( $t_error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></div>
			<?php } else { ?>
				<div class="alert alert-success">Imported ZIP into the new Doctis project <strong><?php echo htmlspecialchars( $t_project_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></strong>.</div>
				<?php if( $t_project_id > 0 ) { ?><p><a href="manage_proj_edit_page.php?project_id=<?php echo $t_project_id; ?>">Open project settings</a></p><?php } ?>
			<?php } ?>
			<?php if( $t_report !== '' ) { ?><pre style="max-height: 60vh; overflow: auto"><?php echo htmlspecialchars( $t_report, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); ?></pre><?php } ?>
			<p><a href="manage_import_data_page.php">Return to Import Data</a></p>
		</div></div>
	</div>
</div>
<?php
layout_page_end();
