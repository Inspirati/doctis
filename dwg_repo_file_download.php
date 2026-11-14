<?php
# Download a file at the project's repository HEAD, including unregistered files.
# Access matches Repository Files and cloning, including inherited repositories.
define( 'DISABLE_INLINE_ERROR_REPORTING', true );
$g_bypass_headers = true;
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'git_lfs_api.php' );
require_api( 'git_store_api.php' );
require_api( 'gpc_api.php' );
require_api( 'http_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id' );
$f_path = gpc_get_string( 'path' );
project_ensure_exists( $f_project_id );
if( !repository_user_can_clone( $f_project_id ) ) {
	access_denied();
}

if( $f_path === '' || strpos( $f_path, "\0" ) !== false ) {
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

$t_repository_id = repository_id_for_project( $f_project_id );
$t_bare = repository_bare_path( $t_repository_id );
# Resolve to an immutable blob before reading its size/content: a concurrent
# push must not change the bytes between those operations. Paths stay Git data;
# never follow a worktree path or a repository symlink on the server filesystem.
$t_blob = trim( git_store_run( $t_bare, true,
	'rev-parse --verify ' . escapeshellarg( 'HEAD:' . $f_path ) ) );
if( !preg_match( '/^[0-9a-f]{40}$/', $t_blob )
	|| trim( git_store_run( $t_bare, true, 'cat-file -t ' . escapeshellarg( $t_blob ) ) ) !== 'blob' ) {
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}
$t_size = (int)trim( git_store_run( $t_bare, true, 'cat-file -s ' . escapeshellarg( $t_blob ) ) );

# LFS files must download as their contents, not as the pointer stored in Git.
$t_file = '';
if( $t_size > 0 && $t_size <= 1024 ) {
	$t_pointer = git_lfs_parse_pointer( git_store_run( $t_bare, true,
		'cat-file blob ' . escapeshellarg( $t_blob ) ) );
	if( $t_pointer !== null ) {
		$t_file = git_lfs_object_path( $t_repository_id, $t_pointer['oid'] );
		if( $t_file === '' || !is_file( $t_file ) || !is_readable( $t_file ) ) {
			trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
		}
		$t_size = filesize( $t_file );
	}
}

while( ob_get_level() > 0 ) {
	ob_end_clean();
}
if( ini_get( 'zlib.output_compression' ) ) {
	ini_set( 'zlib.output_compression', false );
}
http_security_headers();
http_caching_headers( false );
header( 'Content-Type: application/octet-stream' );
http_content_disposition_header( basename( $f_path ) );
header( 'Content-Length: ' . $t_size );
header( 'X-Content-Type-Options: nosniff' );

# Both regular blobs and large LFS objects are streamed without PHP buffering.
if( $t_file !== '' ) {
	readfile( $t_file );
} else {
	$t_dir = escapeshellarg( $t_bare );
	passthru( 'git -c safe.directory=' . $t_dir . ' --git-dir=' . $t_dir
		. ' cat-file blob ' . escapeshellarg( $t_blob ) );
}
exit;
