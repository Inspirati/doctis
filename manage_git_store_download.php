<?php
# Doctis — Download one file from a git store repository, at HEAD or a commit.
# No state change; no CSRF token required.  Always served as an attachment.
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'git_store_api.php' );
require_api( 'gpc_api.php' );
require_api( 'http_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$f_repo = gpc_get_string( 'repo' );
$f_rev = gpc_get_string( 'rev', 'HEAD' );
$f_path = gpc_get_string( 'path' );

$t_repos = git_store_repositories();
if( !isset( $t_repos[$f_repo] ) || !$t_repos[$f_repo]['bare_exists']
	|| !( $f_rev === 'HEAD' || preg_match( '/^[0-9a-f]{40}$/', $f_rev ) )
	|| $f_path === '' || strpos( $f_path, "\0" ) !== false ) {
	error_parameters( 'Unknown repository, revision or path.' );
	trigger_error( ERROR_GENERIC, ERROR );
}
$t_repo = $t_repos[$f_repo];
$t_spec = $f_rev . ':' . $f_path;
if( trim( git_store_run( $t_repo['bare'], true, 'cat-file -t ' . escapeshellarg( $t_spec ) ) ) !== 'blob' ) {
	error_parameters( 'No such file in that revision.' );
	trigger_error( ERROR_GENERIC, ERROR );
}
$t_size = (int)trim( git_store_run( $t_repo['bare'], true, 'cat-file -s ' . escapeshellarg( $t_spec ) ) );

while( ob_get_level() ) {
	ob_end_clean();
}
header( 'Content-Type: application/octet-stream' );
http_content_disposition_header( basename( $f_path ) );
header( 'Content-Length: ' . $t_size );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

$t_dir = escapeshellarg( $t_repo['bare'] );
passthru( 'git -c safe.directory=' . $t_dir . ' --git-dir=' . $t_dir . ' cat-file blob ' . escapeshellarg( $t_spec ) );
exit;
