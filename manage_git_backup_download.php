<?php
# Doctis — Stream the git document store as a .tar.gz download.
# No state change; no CSRF token required.
# The storage roots are owned by www-data so no sudo is needed.
define( 'COMPRESSION_DISABLED', true );

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_storage = rtrim( (string)config_get_global( 'git_storage_root' ), '/' );
if( $t_storage === '' || !is_dir( $t_storage ) ) {
	trigger_error( ERROR_GENERIC, ERROR );
}

# The git-LFS object store holds the bare repositories' large files and lives
# outside them, so a backup that covered only the repositories would restore
# every commit and lose every file those commits refer to.  It is included
# whenever it exists and holds something.
#
# Doing so changes the archive's layout, so it is done only when there is LFS
# content to carry: an install with none produces exactly the archive it always
# did, with the repositories at the top level, and nothing that reads those
# archives has to change.  When LFS content is present the archive instead
# holds two directories, "repositories/" and "lfs/", and the filename says so.
$t_lfs = rtrim( (string)config_get_global( 'git_lfs_root', '' ), '/' );
$t_with_lfs = $t_lfs !== '' && is_dir( $t_lfs )
	&& count( array_diff( scandir( $t_lfs ) ?: array(), array( '.', '..' ) ) ) > 0;

$t_filename = 'doctis_git_backup' . ( $t_with_lfs ? '_lfs' : '' )
	. '_' . date( 'Ymd_His' ) . '.tar.gz';

if( $t_with_lfs ) {
	# A directory of symbolic links, tarred with -h to follow them, names both
	# roots inside one archive without copying anything and without assuming
	# they share a parent directory.
	$t_stage = (string)tempnam( sys_get_temp_dir(), 'doctis-backup-' );
	@unlink( $t_stage );
	if( !@mkdir( $t_stage, 0700 ) ) {
		trigger_error( ERROR_GENERIC, ERROR );
	}
	if( !@symlink( $t_storage, $t_stage . '/repositories' )
		|| !@symlink( $t_lfs, $t_stage . '/lfs' ) ) {
		@unlink( $t_stage . '/repositories' );
		@unlink( $t_stage . '/lfs' );
		@rmdir( $t_stage );
		trigger_error( ERROR_GENERIC, ERROR );
	}
	$t_command = '/bin/tar czhf - -C ' . escapeshellarg( $t_stage ) . ' .';
} else {
	$t_stage   = '';
	$t_command = '/bin/tar czf - -C ' . escapeshellarg( $t_storage ) . ' .';
}

header( 'Content-Type: application/gzip' );
header( 'Content-Disposition: attachment; filename="' . $t_filename . '"' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );

while( ob_get_level() ) {
	ob_end_clean();
}
flush();

$t_handle = popen( $t_command, 'r' );
if( $t_handle === false ) {
	if( $t_stage !== '' ) {
		@unlink( $t_stage . '/repositories' );
		@unlink( $t_stage . '/lfs' );
		@rmdir( $t_stage );
	}
	trigger_error( ERROR_GENERIC, ERROR );
}
while( !feof( $t_handle ) ) {
	echo fread( $t_handle, 65536 );
	flush();
}
pclose( $t_handle );

if( $t_stage !== '' ) {
	@unlink( $t_stage . '/repositories' );
	@unlink( $t_stage . '/lfs' );
	@rmdir( $t_stage );
}
exit;
