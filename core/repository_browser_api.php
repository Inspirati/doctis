<?php
# Project-scoped, read-only repository browsing. Callers must first check
# repository_user_can_clone(); no server-wide repository enumeration is needed.
require_api( 'git_store_api.php' );
require_api( 'repository_api.php' );

/** Build the Git Store descriptor for just one authorised repository. */
function repository_browser_repo( int $p_id ): array {
	return array(
		'id' => $p_id,
		'key' => repository_basename( $p_id ),
		'bare' => repository_bare_path( $p_id ),
		'bare_exists' => is_dir( repository_bare_path( $p_id ) ),
	);
}

/** Accept HEAD or a full commit SHA only; never accept caller-supplied Git expressions. */
function repository_browser_commit( array $p_repo, string $p_revision = 'HEAD' ): string {
	if( $p_revision !== 'HEAD' && !preg_match( '/\A[0-9a-f]{40}\z/', $p_revision ) ) {
		return '';
	}
	$t_sha = trim( git_store_run( $p_repo['bare'], true,
		'rev-parse --verify ' . escapeshellarg( $p_revision . '^{commit}' ), $t_rc ) );
	return $t_rc === 0 && preg_match( '/\A[0-9a-f]{40}\z/', $t_sha ) ? $t_sha : '';
}

/** Immediate children of a directory in a recursive tree; paths stay literal Git data. */
function repository_browser_entries( array $p_tree, string $p_directory ): array {
	$t_entries = array();
	$t_prefix = $p_directory === '' ? '' : $p_directory . '/';
	foreach( $p_tree as $t_path => $t_entry ) {
		if( strpos( $t_path, $t_prefix ) !== 0 ) {
			continue;
		}
		$t_relative = substr( $t_path, strlen( $t_prefix ) );
		$t_slash = strpos( $t_relative, '/' );
		if( $t_slash !== false ) {
			$t_entries[$t_prefix . substr( $t_relative, 0, $t_slash )] = array( 'type' => 'tree', 'size' => 0 );
		} else {
			$t_entries[$t_path] = $t_entry;
		}
	}
	uksort( $t_entries, static function( $a, $b ) use ( $t_entries ) {
		return ( ( $t_entries[$a]['type'] !== 'tree' ) <=> ( $t_entries[$b]['type'] !== 'tree' ) )
			?: strnatcasecmp( $a, $b );
	} );
	return $t_entries;
}

/** Paginated history at a resolved commit, optionally for one literal file/folder path.
 * Rename history remains available through the repository-wide history.
 */
function repository_browser_history( array $p_repo, string $p_commit, string $p_path = '',
	int $p_skip = 0, int $p_limit = 51 ): array {
	if( !preg_match( '/\A[0-9a-f]{40}\z/', $p_commit ) || strpos( $p_path, "\0" ) !== false ) {
		return array();
	}
	$t_output = git_store_run( $p_repo['bare'], true,
		'--literal-pathspecs log --skip=' . max( 0, $p_skip ) . ' -n ' . max( 1, min( 101, $p_limit ) )
		. ' --format=format:%H%x00%at%x00%an%x00%s%x00 ' . escapeshellarg( $p_commit )
		. ' --' . ( $p_path === '' ? '' : ' ' . escapeshellarg( $p_path ) ) );
	$t_fields = explode( "\0", $t_output );
	$t_commits = array();
	for( $i = 0; $i + 3 < count( $t_fields ); $i += 4 ) {
		$t_sha = trim( $t_fields[$i] );
		if( preg_match( '/\A[0-9a-f]{40}\z/', $t_sha ) ) {
			$t_commits[] = array( 'sha' => $t_sha, 'time' => (int)$t_fields[$i + 1],
				'author' => $t_fields[$i + 2], 'subject' => $t_fields[$i + 3] );
		}
	}
	return $t_commits;
}
