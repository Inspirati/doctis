<?php
/**
 * Doctis metadata cut-over — remove lifecycle metadata from a project's
 * Markdown documents in one commit, then promote the new versions to On
 * Record (doc/publish/METADATA_CUTOVER.md).
 *
 * Usage (as the web server user, from the Doctis directory):
 *   sudo -u www-data php admin/doctis-metadata-cutover.php --project <name|id> --user <username> [options]
 *
 * Options:
 *   --project <name|id>  Project; sub-projects that share its repository are included
 *   --user <username>    Doctis account the commit and promotions are recorded as
 *   --dry-run            Report what would change; write nothing
 *   --diff               With --dry-run, print a unified diff of each document
 *   --no-promote         Commit only; leave each change as the document's Draft
 *   --include-drafts     Also process documents that already have a Draft
 *                        (their Draft would be promoted with the change)
 *   --keys <list>        Frontmatter keys to remove (comma-separated)
 *   --labels <list>      Header labels to remove (comma-separated)
 *   --help
 *
 * Removes YAML frontmatter keys (title, status, revision, owner, approver,
 * effective_date, review_period, classification; doc_id and other keys stay)
 * and "**Label:** value" header lines (Document ID, Revision, Status, Owner,
 * Classification, Approver, Effective Date, Review Period) under each
 * document's first heading.  Doctis holds these values; publishing prints
 * them in the document-control block.  Only the On Record version of each
 * document is changed, from the repository HEAD; documents with a Draft are
 * skipped unless --include-drafts is given.  Repeatable: a second run finds
 * nothing to change.  Exit status 0 on success.
 */

$t_options = getopt( '', array(
	'project:', 'user:', 'dry-run', 'diff', 'no-promote', 'include-drafts', 'keys:', 'labels:', 'help',
) );

if( isset( $t_options['help'] ) || !isset( $t_options['project'] ) || !isset( $t_options['user'] ) ) {
	$t_lines = file( __FILE__ );
	foreach( array_slice( $t_lines, 1, 35 ) as $t_line ) {
		if( strpos( $t_line, '*/' ) !== false ) {
			break;
		}
		echo preg_replace( '/^\s*\*( |$)/', '', $t_line );
	}
	exit( isset( $t_options['help'] ) ? 0 : 1 );
}

$t_mantis_dir = dirname( __DIR__ ) . '/';
require_once( $t_mantis_dir . 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'dwg_metadata_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'repository_api.php' );
require_api( 'user_api.php' );

function say( string $p_msg ): void {
	echo $p_msg . "\n";
}

function abort( string $p_msg ): void {
	fwrite( STDERR, "ERROR: $p_msg\n" );
	exit( 1 );
}

/** Run git in a worktree; abort on failure.  Returns stdout. */
function git( string $p_worktree, array $p_args ): string {
	$t_process = proc_open( array_merge( array( 'git', '-C', $p_worktree ), $p_args ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $t_pipes );
	$t_out = stream_get_contents( $t_pipes[1] );
	$t_err = stream_get_contents( $t_pipes[2] );
	if( proc_close( $t_process ) !== 0 ) {
		abort( 'git ' . implode( ' ', $p_args ) . ' failed: ' . trim( $t_err ) );
	}
	return $t_out;
}

$g_dry     = isset( $t_options['dry-run'] );
$t_diff    = isset( $t_options['diff'] );
$t_promote = !isset( $t_options['no-promote'] );
$t_drafts  = isset( $t_options['include-drafts'] );
$t_keys    = isset( $t_options['keys'] ) ? array_filter( array_map( 'trim', explode( ',', $t_options['keys'] ) ) ) : DWG_METADATA_KEYS;
$t_labels  = isset( $t_options['labels'] ) ? array_filter( array_map( 'trim', explode( ',', $t_options['labels'] ) ) ) : DWG_METADATA_LABELS;

if( !auth_attempt_script_login( $t_options['user'] ) ) {
	abort( 'cannot log in as "' . $t_options['user'] . '".' );
}
$t_user_id = auth_get_current_user_id();

# ── Project and repository ───────────────────────────────────────────────────

$t_project = $t_options['project'];
$t_project_id = ctype_digit( $t_project ) ? (int)$t_project : (int)project_get_id_by_name( $t_project );
if( $t_project_id < 1 || !project_exists( $t_project_id ) ) {
	abort( 'project "' . $t_project . '" not found.' );
}
$t_repository_id = repository_id_for_project( $t_project_id );
if( $t_repository_id < 1 ) {
	abort( 'project "' . project_get_name( $t_project_id ) . '" has no repository.' );
}
$t_project_ids = array( $t_project_id );
foreach( project_hierarchy_get_all_subprojects( $t_project_id, true ) as $t_sub_id ) {
	if( repository_id_for_project( (int)$t_sub_id ) === $t_repository_id ) {
		$t_project_ids[] = (int)$t_sub_id;
	}
}
$t_worktree = repository_worktree_path( $t_repository_id );

say( ( $g_dry ? 'DRY RUN — ' : '' ) . 'Metadata cut-over: ' . project_get_name( $t_project_id )
	. ' (projects ' . implode( ', ', $t_project_ids ) . '), repository ' . repository_basename( $t_repository_id )
	. ', as ' . user_get_name( $t_user_id ) );

# ── Documents ────────────────────────────────────────────────────────────────

db_param_push();
$t_params = array();
$t_in = array();
foreach( $t_project_ids as $t_id ) {
	$t_in[] = db_param();
	$t_params[] = $t_id;
}
$t_result = db_query( 'SELECT d.id, f.git_path FROM {dwg} d JOIN {dwg_primary_file} f ON f.dwg_id = d.id'
	. ' WHERE d.project_id IN (' . implode( ',', $t_in ) . ') ORDER BY f.git_path', $t_params );
$t_documents = array();
while( $t_row = db_fetch_array( $t_result ) ) {
	if( preg_match( '/\.(md|markdown)$/i', $t_row['git_path'] ) ) {
		$t_documents[] = array( 'dwg_id' => (int)$t_row['id'], 'path' => $t_row['git_path'] );
	}
}

# The git identity of the commit: the Doctis user, as for uploads.
$t_pw = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() ) : false;
if( $t_pw && !empty( $t_pw['dir'] ) ) {
	putenv( 'HOME=' . $t_pw['dir'] );
}
foreach( array( 'AUTHOR', 'COMMITTER' ) as $t_role ) {
	putenv( 'GIT_' . $t_role . '_NAME=' . user_get_name( $t_user_id ) );
	if( !is_blank( user_get_email( $t_user_id ) ) ) {
		putenv( 'GIT_' . $t_role . '_EMAIL=' . user_get_email( $t_user_id ) );
	}
}

$t_lock = dwg_git_storage_lock();
$t_changed = array();
$t_skipped = 0;
try {
	repository_ensure_on_disk( $t_repository_id );
	if( !$g_dry ) {
		dwg_git_worktree_sync( $t_worktree );
	}
	$t_bare = repository_bare_path( $t_repository_id );
	foreach( $t_documents as $t_doc ) {
		$t_head = file_dwg_git_head_info( $t_doc['dwg_id'] );
		if( $t_head && $t_head['has_draft'] && !$t_drafts ) {
			say( sprintf( '  skip  #%-5d %s (has a Draft; use --include-drafts)', $t_doc['dwg_id'], $t_doc['path'] ) );
			$t_skipped++;
			continue;
		}
		$t_content = shell_exec( 'git --git-dir=' . escapeshellarg( $t_bare ) . ' show '
			. escapeshellarg( 'HEAD:' . $t_doc['path'] ) . ' 2>/dev/null' );
		if( !is_string( $t_content ) || $t_content === '' ) {
			say( sprintf( '  skip  #%-5d %s (not at HEAD)', $t_doc['dwg_id'], $t_doc['path'] ) );
			$t_skipped++;
			continue;
		}
		$t_removed = array();
		$t_new = dwg_metadata_strip( $t_content, $t_removed, $t_keys, $t_labels );
		if( $t_new === $t_content ) {
			continue;
		}
		say( sprintf( '  %s #%-5d %s — keys: %s; header: %s', $g_dry ? 'would' : 'strip', $t_doc['dwg_id'], $t_doc['path'],
			implode( ', ', $t_removed['keys'] ) ?: '-', implode( ', ', $t_removed['labels'] ) ?: '-' ) );
		if( $g_dry && $t_diff ) {
			$t_a = tempnam( sys_get_temp_dir(), 'cut' );
			$t_b = tempnam( sys_get_temp_dir(), 'cut' );
			file_put_contents( $t_a, $t_content );
			file_put_contents( $t_b, $t_new );
			echo shell_exec( 'diff -u --label ' . escapeshellarg( 'a/' . $t_doc['path'] ) . ' --label '
				. escapeshellarg( 'b/' . $t_doc['path'] ) . ' ' . escapeshellarg( $t_a ) . ' ' . escapeshellarg( $t_b ) );
			unlink( $t_a );
			unlink( $t_b );
		}
		if( !$g_dry ) {
			file_put_contents( $t_worktree . '/' . $t_doc['path'], $t_new );
		}
		$t_changed[] = $t_doc;
	}

	if( !$g_dry && $t_changed ) {
		git( $t_worktree, array_merge( array( 'add', '--' ), array_column( $t_changed, 'path' ) ) );
		git( $t_worktree, array( 'commit', '-q', '-m',
			'Move lifecycle metadata to Doctis: ' . count( $t_changed ) . ' documents' . "\n\n"
			. 'Removed frontmatter keys and header lines that Doctis now holds'
			. ' (status, revision, owner, classification, ...). Publishing prints'
			. ' them in the document-control block.' . "\n\n"
			. 'doctis-metadata-cutover by ' . user_get_name( $t_user_id ) ) );
		$t_branch = trim( git( $t_worktree, array( 'rev-parse', '--abbrev-ref', 'HEAD' ) ) );
		git( $t_worktree, array( 'push', '-q', 'origin', $t_branch ) );
		$t_sha = trim( git( $t_worktree, array( 'rev-parse', 'HEAD' ) ) );
		say( 'Committed ' . substr( $t_sha, 0, 8 ) . ' on ' . $t_branch . ' (' . count( $t_changed ) . ' documents).' );
	}
} finally {
	dwg_git_storage_unlock( $t_lock );
}

# ── Promote ──────────────────────────────────────────────────────────────────

$t_promoted = 0;
$t_failed = 0;
if( !$g_dry && $t_promote ) {
	foreach( $t_changed as $t_doc ) {
		try {
			file_dwg_primary_sync_head( $t_doc['dwg_id'], $t_user_id );
			$t_promoted++;
		} catch( Exception $e ) {
			say( sprintf( '  promote failed #%d %s: %s', $t_doc['dwg_id'], $t_doc['path'], $e->getMessage() ) );
			$t_failed++;
		}
	}
}

say( sprintf( '%s: %d documents, %d %s, %d skipped%s.', $g_dry ? 'Dry run' : 'Done', count( $t_documents ),
	count( $t_changed ), $g_dry ? 'would change' : 'changed', $t_skipped,
	$g_dry || !$t_promote ? '' : ', ' . $t_promoted . ' promoted to On Record' . ( $t_failed ? ', ' . $t_failed . ' failed' : '' ) ) );
exit( $t_failed ? 1 : 0 );
