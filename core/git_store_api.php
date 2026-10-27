<?php
# Doctis - Document Issue Tracking System
#
# Git Store API — read-only inspection of the git document store for the
# Manage → Git Store page.
#
# Lists every repository known to the database or present on disk, and reads
# a repository's tree, history, refs, server worktree and registered
# documents.  Nothing here writes to a repository.  Repositories are
# addressed by their on-disk basename ("<slug>-r<id>"); callers must take
# that key from git_store_repositories().  Paths and revisions reach git as
# single shell-escaped arguments.

use Mantis\Exceptions\ClientException;

require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );

/**
 * Run git against a bare repository or a worktree and return its raw stdout.
 *
 * @param string  $p_dir   Bare repository or worktree path.
 * @param boolean $p_bare  True for a bare repository (--git-dir), false for a worktree (-C).
 * @param string  $p_args  Git arguments, already shell-escaped where needed.
 * @param integer $p_rc    Receives the exit code.
 * @param string  $p_input Optional stdin.
 * @return string
 */
function git_store_run( string $p_dir, bool $p_bare, string $p_args, &$p_rc = null, string $p_input = '' ): string {
	$t_dir = escapeshellarg( $p_dir );
	$t_cmd = 'git -c safe.directory=' . $t_dir . ' -c core.quotePath=false --no-optional-locks '
		. ( $p_bare ? '--git-dir=' : '-C ' ) . $t_dir . ' ' . $p_args;
	$t_proc = proc_open( $t_cmd,
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$t_pipes );
	if( !is_resource( $t_proc ) ) {
		$p_rc = -1;
		return '';
	}
	fwrite( $t_pipes[0], $p_input );
	fclose( $t_pipes[0] );
	$t_out = stream_get_contents( $t_pipes[1] );
	fclose( $t_pipes[1] );
	$p_rc = proc_close( $t_proc );
	return $t_out === false ? '' : $t_out;
}

/**
 * Split output into non-empty lines.
 *
 * @param string $p_output
 * @return array
 */
function git_store_lines( string $p_output ): array {
	return array_values( array_filter( explode( "\n", $p_output ), 'strlen' ) );
}

/**
 * Human-readable size.
 *
 * @param integer $p_bytes
 * @return string
 */
function git_store_format_size( $p_bytes ): string {
	$t_bytes = (float)$p_bytes;
	foreach( array( 'B', 'KB', 'MB', 'GB' ) as $t_unit ) {
		if( $t_bytes < 1024 || $t_unit === 'GB' ) {
			return ( $t_unit === 'B' ? (int)$t_bytes : number_format( $t_bytes, 1 ) ) . ' ' . $t_unit;
		}
		$t_bytes /= 1024;
	}
	return '';
}

/**
 * Disk usage of a directory in bytes (du -sk), or 0.
 *
 * @param string $p_dir
 * @return integer
 */
function git_store_disk_usage( string $p_dir ): int {
	if( !is_dir( $p_dir ) ) {
		return 0;
	}
	$t_out = (string)@shell_exec( 'du -sk ' . escapeshellarg( $p_dir ) . ' 2>/dev/null' );
	return (int)$t_out * 1024;
}

/**
 * Every repository in the database or on disk, keyed by basename.
 *
 * Each entry: key, id (0 when unregistered), name, row ({repository} row or
 * null), bare, worktree, lfs, bare_exists, worktree_exists, lfs_exists,
 * projects (ids still linked), owner_exists, status
 * ('ok' | 'missing' | 'orphan' | 'worktree-only' | 'unowned').
 *
 * "orphan" means on disk with no database row.  "unowned" is the opposite
 * and was invisible until now: a row whose owner project has been deleted.
 * project_delete() never removed repositories, so deleting a project leaves
 * its row, its link rows, the bare repository, the worktree and -- the
 * expensive part -- its git-LFS objects, with nothing reporting them.
 *
 * @return array
 */
function git_store_repositories(): array {
	$t_root = rtrim( (string)config_get_global( 'git_storage_root' ), '/' );
	$t_wt_root = rtrim( (string)config_get_global( 'git_worktree_root' ), '/' );
	$t_rows = array();

	$t_result = db_query( 'SELECT * FROM {repository} ORDER BY id' );
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_rows[repository_basename( (int)$t_row['id'] )] = $t_row;
	}

	$t_keys = array_keys( $t_rows );
	foreach( array( array( $t_root, '.git' ), array( $t_wt_root, '' ) ) as $t_scan ) {
		list( $t_dir, $t_suffix ) = $t_scan;
		if( is_blank( $t_dir ) || !is_dir( $t_dir ) ) {
			continue;
		}
		foreach( scandir( $t_dir ) ?: array() as $t_name ) {
			if( $t_name[0] === '.' || !is_dir( $t_dir . '/' . $t_name ) ) {
				continue;
			}
			if( $t_suffix !== '' ) {
				if( substr( $t_name, -strlen( $t_suffix ) ) !== $t_suffix ) {
					continue;
				}
				$t_name = substr( $t_name, 0, -strlen( $t_suffix ) );
			}
			$t_keys[] = $t_name;
		}
	}

	$t_repos = array();
	foreach( array_unique( $t_keys ) as $t_key ) {
		$t_row = $t_rows[$t_key] ?? null;
		$t_bare = $t_root . '/' . $t_key . '.git';
		$t_worktree = $t_wt_root . '/' . $t_key;
		$t_lfs_root = rtrim( (string)config_get_global( 'git_lfs_root', '' ), '/' );
		$t_lfs = $t_lfs_root === '' ? '' : $t_lfs_root . '/' . $t_key;
		$t_entry = array(
			'key' => $t_key,
			'id' => $t_row ? (int)$t_row['id'] : 0,
			'name' => $t_row ? $t_row['name'] : $t_key,
			'row' => $t_row,
			'bare' => $t_bare,
			'worktree' => $t_worktree,
			'lfs' => $t_lfs,
			'bare_exists' => is_dir( $t_bare ),
			'worktree_exists' => is_dir( $t_worktree ),
			'lfs_exists' => $t_lfs !== '' && is_dir( $t_lfs ),
			'projects' => array(),
			'owner_exists' => true,
		);
		if( $t_row ) {
			# A repository is still wanted while any project links to it, or
			# while the project that owns it exists.  Checked here rather than
			# at delete time so the Git Store can show what is reachable.
			# Only links to projects that still exist count as use: deleting a
			# project leaves its {project_repository} row behind too, and a
			# dangling link must not make a dead repository look reachable.
			$t_entry['projects'] = array_values( array_filter(
				repository_project_ids( (int)$t_row['id'] ),
				static fn( $p_id ) => project_exists( (int)$p_id ) ) );
			$t_entry['owner_exists'] = project_exists( (int)$t_row['owner_project_id'] );
			if( !$t_entry['owner_exists'] && !$t_entry['projects'] ) {
				$t_entry['status'] = 'unowned';
			} else {
				$t_entry['status'] = $t_entry['bare_exists'] ? 'ok' : 'missing';
			}
		} else {
			$t_entry['status'] = $t_entry['bare_exists'] ? 'orphan' : 'worktree-only';
		}
		$t_repos[$t_key] = $t_entry;
	}
	return $t_repos;
}

/**
 * Headline facts about a bare repository: HEAD branch and commit, counts and size.
 *
 * @param array $p_repo Entry from git_store_repositories().
 * @return array Empty when the bare repository does not exist.
 */
function git_store_summary( array $p_repo ): array {
	if( !$p_repo['bare_exists'] ) {
		return array();
	}
	$t_bare = $p_repo['bare'];
	$t_summary = array(
		'branch' => trim( git_store_run( $t_bare, true, 'symbolic-ref --short HEAD' ) ),
		'head' => null,
		'commits' => 0,
		'files' => 0,
		'size' => git_store_disk_usage( $t_bare ),
		'refs' => count( git_store_lines( git_store_run( $t_bare, true, 'for-each-ref ' . escapeshellarg( '--format=%(refname)' ) ) ) ),
		'pins' => count( git_store_lines( git_store_run( $t_bare, true, 'for-each-ref ' . escapeshellarg( '--format=%(refname)' ) . ' refs/doctis' ) ) ),
	);
	$t_head = git_store_commits( $p_repo, 1 );
	if( !empty( $t_head ) ) {
		$t_summary['head'] = $t_head[0];
		$t_summary['commits'] = (int)trim( git_store_run( $t_bare, true, 'rev-list --count HEAD' ) );
		$t_summary['files'] = count( git_store_lines( git_store_run( $t_bare, true, 'ls-tree -r --name-only HEAD' ) ) );
	}
	return $t_summary;
}

/**
 * Files at HEAD, keyed by path: mode, type, sha (blob) and size.
 *
 * @param array $p_repo
 * @return array
 */
function git_store_tree( array $p_repo ): array {
	$t_tree = array();
	if( !$p_repo['bare_exists'] ) {
		return $t_tree;
	}
	$t_out = git_store_run( $p_repo['bare'], true, 'ls-tree -r -l -z --full-tree HEAD' );
	foreach( explode( "\0", $t_out ) as $t_record ) {
		if( !preg_match( '/^(\d+) (\w+) ([0-9a-f]+)\s+(-|\d+)\t(.+)$/s', $t_record, $t_m ) ) {
			continue;
		}
		$t_tree[$t_m[5]] = array( 'mode' => $t_m[1], 'type' => $t_m[2], 'sha' => $t_m[3],
			'size' => $t_m[4] === '-' ? 0 : (int)$t_m[4] );
	}
	ksort( $t_tree, SORT_NATURAL | SORT_FLAG_CASE );
	return $t_tree;
}

/**
 * Parse "log --format=%x01%H%x09%at%x09%an%x09%s" output with optional
 * --name-only / --shortstat bodies.
 *
 * @param string $p_output
 * @return array List of commits: sha, time, author, subject, files (array), stat (string).
 */
function git_store_parse_log( string $p_output ): array {
	$t_commits = array();
	foreach( explode( "\x01", $p_output ) as $t_chunk ) {
		$t_lines = explode( "\n", $t_chunk );
		$t_header = explode( "\t", array_shift( $t_lines ), 4 );
		if( count( $t_header ) < 4 ) {
			continue;
		}
		$t_commit = array( 'sha' => $t_header[0], 'time' => (int)$t_header[1], 'author' => $t_header[2],
			'subject' => $t_header[3], 'files' => array(), 'stat' => '' );
		foreach( $t_lines as $t_line ) {
			if( $t_line === '' ) {
				continue;
			}
			if( preg_match( '/^ \d+ files? changed/', $t_line ) ) {
				$t_commit['stat'] = trim( $t_line );
			} else {
				$t_commit['files'][] = $t_line;
			}
		}
		$t_commits[] = $t_commit;
	}
	return $t_commits;
}

/**
 * Most recent commits on HEAD.
 *
 * @param array   $p_repo
 * @param integer $p_limit
 * @param boolean $p_stat  Include --shortstat.
 * @return array
 */
function git_store_commits( array $p_repo, int $p_limit = 50, bool $p_stat = false ): array {
	if( !$p_repo['bare_exists'] ) {
		return array();
	}
	return git_store_parse_log( git_store_run( $p_repo['bare'], true,
		'log -n ' . $p_limit . ( $p_stat ? ' --shortstat' : '' )
		. ' --format=%x01%H%x09%at%x09%an%x09%s HEAD' ) );
}

/**
 * Latest commit touching each path at HEAD, from one pass over the history.
 *
 * @param array   $p_repo
 * @param integer $p_limit Commits scanned.
 * @return array path => commit
 */
function git_store_last_changes( array $p_repo, int $p_limit = 5000 ): array {
	$t_last = array();
	if( !$p_repo['bare_exists'] ) {
		return $t_last;
	}
	$t_log = git_store_run( $p_repo['bare'], true,
		'log -n ' . $p_limit . ' --name-only --format=%x01%H%x09%at%x09%an%x09%s HEAD' );
	foreach( git_store_parse_log( $t_log ) as $t_commit ) {
		foreach( $t_commit['files'] as $t_path ) {
			if( !isset( $t_last[$t_path] ) ) {
				$t_last[$t_path] = $t_commit;
			}
		}
	}
	return $t_last;
}

/**
 * The commit that last changed one path, as a full SHA.
 *
 * git_store_last_changes() answers the same question for every path at once,
 * but from a bounded walk (5000 commits) meant for display.  Registering a
 * document pins it to a commit, so it needs the exact answer however old the
 * file is: this asks git about the one path.
 *
 * @param array  $p_repo Git store repository entry.
 * @param string $p_path Repository-relative path.
 * @return string Full SHA, or '' when the path has no history.
 */
function git_store_last_commit_sha( array $p_repo, string $p_path ): string {
	if( !$p_repo['bare_exists'] || $p_path === '' ) {
		return '';
	}
	$t_out = trim( git_store_run( $p_repo['bare'], true,
		'log -1 --format=%H HEAD -- ' . escapeshellarg( $p_path ) ) );
	return preg_match( '/^[0-9a-f]{40}$/', $t_out ) ? $t_out : '';
}

/**
 * History of one path (following renames), newest first; each commit's
 * files[0] is the path's name in that commit.
 *
 * @param array   $p_repo
 * @param string  $p_path
 * @param integer $p_limit
 * @return array
 */
function git_store_file_history( array $p_repo, string $p_path, int $p_limit = 50 ): array {
	return git_store_parse_log( git_store_run( $p_repo['bare'], true,
		'log -n ' . $p_limit . ' --follow --name-only --format=%x01%H%x09%at%x09%an%x09%s HEAD -- '
		. escapeshellarg( $p_path ) ) );
}

/**
 * All refs: name, sha, time and subject of the commit they point to.
 *
 * @param array $p_repo
 * @return array
 */
function git_store_refs( array $p_repo ): array {
	$t_refs = array();
	if( !$p_repo['bare_exists'] ) {
		return $t_refs;
	}
	$t_out = git_store_run( $p_repo['bare'], true,
		'for-each-ref ' . escapeshellarg( '--format=%(refname)%09%(objectname)%09%(creatordate:unix)%09%(contents:subject)' ) );
	foreach( git_store_lines( $t_out ) as $t_line ) {
		$t_f = explode( "\t", $t_line, 4 );
		if( count( $t_f ) === 4 ) {
			$t_refs[] = array( 'name' => $t_f[0], 'sha' => $t_f[1], 'time' => (int)$t_f[2], 'subject' => $t_f[3] );
		}
	}
	return $t_refs;
}

/**
 * Resolve "<rev>:<path>" specs to blob ids in one git call.
 *
 * @param array $p_repo
 * @param array $p_specs List of "<rev>:<path>".
 * @return array spec => blob sha, or null when missing.
 */
function git_store_resolve_blobs( array $p_repo, array $p_specs ): array {
	$t_result = array_fill_keys( $p_specs, null );
	if( empty( $p_specs ) || !$p_repo['bare_exists'] ) {
		return $t_result;
	}
	# Default batch-check output: "<sha> <type> <size>", or "<spec> missing".
	$t_out = git_store_run( $p_repo['bare'], true, 'cat-file --batch-check',
		$t_rc, implode( "\n", $p_specs ) . "\n" );
	$t_lines = explode( "\n", $t_out );
	foreach( array_values( $p_specs ) as $t_i => $t_spec ) {
		$t_f = explode( ' ', $t_lines[$t_i] ?? '' );
		$t_result[$t_spec] = ( count( $t_f ) === 3 && $t_f[1] === 'blob' ) ? $t_f[0] : null;
	}
	return $t_result;
}

/**
 * Every registered document of a repository as a flat list, newest first.
 *
 * git_store_documents() is keyed by git_path for the Files tab, where the
 * question is "which document holds this file".  Several source records may
 * share one path — successive releases of a component, or two lines of one
 * subtree — so that map silently keeps only the last of them.  The Documents
 * tab needs them all, and uses this instead.
 *
 * @param array $p_repo Entry from git_store_repositories().
 * @param array $p_tree Output of git_store_tree() for the same repository.
 * @return array List of rows as git_store_documents() builds them, each with
 *               'path' and, for a record, 'state'.
 */
function git_store_documents_list( array $p_repo, array $p_tree ): array {
	$t_rows = array();
	if( $p_repo['id'] <= 0 ) {
		return $t_rows;
	}
	$t_head_sha = trim( git_store_run( $p_repo['bare'], true, 'rev-parse HEAD' ) );
	$t_project_repo = array();

	$t_result = db_query( 'SELECT f.dwg_id, f.git_path, f.git_sha, f.git_branch, f.filesize,'
		. ' f.file_type, f.date_added, f.description, d.project_id, d.kind, d.frozen, d.summary,'
		. ' doc.title, doc.reference FROM {dwg_primary_file} f'
		. ' JOIN {dwg} d ON d.id = f.dwg_id LEFT JOIN {documents} doc ON doc.id = d.document_id'
		. ' ORDER BY f.date_added DESC, f.dwg_id DESC' );
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_project_id = (int)$t_row['project_id'];
		if( !isset( $t_project_repo[$t_project_id] ) ) {
			$t_project_repo[$t_project_id] = repository_id_for_project( $t_project_id );
		}
		if( $t_project_repo[$t_project_id] !== $p_repo['id'] ) {
			continue;
		}
		$t_row['path'] = $t_row['git_path'];
		if( (int)$t_row['kind'] === DWG_KIND_SOURCE ) {
			$t_row['state'] = git_store_tree_state(
				$p_repo, $t_row['git_sha'], $t_head_sha, $t_row['git_path'] );
		} else if( !isset( $p_tree[$t_row['git_path']] ) ) {
			$t_row['state'] = 'missing';
		} else {
			$t_blob = git_store_resolve_blobs( $p_repo,
				array( $t_row['git_sha'] . ':' . $t_row['git_path'] ) );
			$t_object = reset( $t_blob );
			$t_row['state'] = $t_object === null ? 'unknown'
				: ( $t_object === $p_tree[$t_row['git_path']]['sha'] ? 'current' : 'changed' );
		}
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * On Record state of a source release record: compare the tree object it was
 * registered at with the one at HEAD.  '' is the repository root.
 *
 * @param array  $p_repo
 * @param string $p_record_sha  Commit the release is on record at.
 * @param string $p_head_sha    Current repository HEAD.
 * @param string $p_path        Subtree path, '' for the repository root.
 * @return string  'current', 'changed', 'missing' or 'unknown'.
 */
function git_store_tree_state( array $p_repo, string $p_record_sha, string $p_head_sha, string $p_path ): string {
	if( $p_record_sha === '' || $p_head_sha === '' ) {
		return 'unknown';
	}
	$t_record = trim( git_store_run( $p_repo['bare'], true,
		'rev-parse --verify ' . escapeshellarg( $p_record_sha . ':' . $p_path ), $t_rc_record ) );
	if( $t_rc_record !== 0 || $t_record === '' ) {
		return 'unknown';
	}
	$t_head = trim( git_store_run( $p_repo['bare'], true,
		'rev-parse --verify ' . escapeshellarg( $p_head_sha . ':' . $p_path ), $t_rc_head ) );
	if( $t_rc_head !== 0 || $t_head === '' ) {
		return 'missing';
	}
	return $t_record === $t_head ? 'current' : 'changed';
}

/**
 * Documents whose project resolves to a repository, keyed by git_path:
 * array( 'record' => row|null, 'draft' => row|null ).  Rows carry dwg_id,
 * git_path, git_sha, filesize, date_added, project_id, summary, title,
 * reference and, for records, 'state':
 * 'current' (HEAD holds the On Record content), 'changed' (HEAD differs),
 * 'missing' (path absent at HEAD) or 'unknown' (On Record commit not found).
 *
 * @param array $p_repo
 * @param array $p_tree Output of git_store_tree() for the same repository.
 * @return array
 */
function git_store_documents( array $p_repo, array $p_tree ): array {
	$t_docs = array();
	if( $p_repo['id'] <= 0 ) {
		return $t_docs;
	}
	$t_project_repo = array();
	foreach( array( 'record' => 'dwg_primary_file', 'draft' => 'dwg_primary_draft' ) as $t_kind => $t_table ) {
		$t_result = db_query( 'SELECT f.dwg_id, f.git_path, f.git_sha, f.filesize, f.file_type, f.date_added,'
			. ' d.project_id, d.kind, d.frozen, d.summary, doc.title, doc.reference FROM {' . $t_table . '} f'
			. ' JOIN {dwg} d ON d.id = f.dwg_id LEFT JOIN {documents} doc ON doc.id = d.document_id'
			. ' ORDER BY f.git_path' );
		while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
			$t_project_id = (int)$t_row['project_id'];
			if( !isset( $t_project_repo[$t_project_id] ) ) {
				$t_project_repo[$t_project_id] = repository_id_for_project( $t_project_id );
			}
			if( $t_project_repo[$t_project_id] !== $p_repo['id'] ) {
				continue;
			}
			$t_docs[$t_row['git_path']] = ( $t_docs[$t_row['git_path']] ?? array( 'record' => null, 'draft' => null ) );
			$t_docs[$t_row['git_path']][$t_kind] = $t_row;
		}
	}

	# A source release record names a tree, so it is absent from the blob tree
	# listing and must be compared tree-object to tree-object instead.
	$t_head_sha = trim( git_store_run( $p_repo['bare'], true, 'rev-parse HEAD' ) );

	$t_specs = array();
	foreach( $t_docs as $t_path => $t_doc ) {
		if( $t_doc['record'] && $t_doc['record']['git_sha'] !== ''
		 && (int)( $t_doc['record']['kind'] ?? DWG_KIND_DOCUMENT ) !== DWG_KIND_SOURCE ) {
			$t_specs[] = $t_doc['record']['git_sha'] . ':' . $t_path;
		}
	}
	$t_blobs = git_store_resolve_blobs( $p_repo, $t_specs );
	foreach( $t_docs as $t_path => &$t_doc ) {
		if( !$t_doc['record'] ) {
			continue;
		}
		if( (int)( $t_doc['record']['kind'] ?? DWG_KIND_DOCUMENT ) === DWG_KIND_SOURCE ) {
			$t_doc['record']['state'] = git_store_tree_state(
				$p_repo, $t_doc['record']['git_sha'], $t_head_sha, $t_path );
			continue;
		}
		$t_blob = $t_blobs[$t_doc['record']['git_sha'] . ':' . $t_path] ?? null;
		if( !isset( $p_tree[$t_path] ) ) {
			$t_doc['record']['state'] = 'missing';
		} else if( $t_blob === null ) {
			$t_doc['record']['state'] = 'unknown';
		} else {
			$t_doc['record']['state'] = $t_blob === $p_tree[$t_path]['sha'] ? 'current' : 'changed';
		}
	}
	unset( $t_doc );
	return $t_docs;
}

/**
 * State of a repository's server worktree: branch, HEAD, whether it matches
 * the bare repository's HEAD, tracked file count and status entries.
 *
 * @param array $p_repo
 * @return array Empty when the worktree does not exist.
 */
function git_store_worktree( array $p_repo ): array {
	if( !$p_repo['worktree_exists'] ) {
		return array();
	}
	$t_wt = $p_repo['worktree'];
	$t_head = trim( git_store_run( $t_wt, false, 'rev-parse HEAD', $t_rc ) );
	$t_bare_head = $p_repo['bare_exists'] ? trim( git_store_run( $p_repo['bare'], true, 'rev-parse HEAD' ) ) : '';
	$t_changes = array();
	foreach( explode( "\0", git_store_run( $t_wt, false, 'status --porcelain=v1 -z -uall' ) ) as $t_entry ) {
		if( strlen( $t_entry ) > 3 ) {
			$t_changes[] = array( 'code' => substr( $t_entry, 0, 2 ), 'path' => substr( $t_entry, 3 ) );
		}
	}
	return array(
		'is_repo' => $t_rc === 0,
		'branch' => trim( git_store_run( $t_wt, false, 'rev-parse --abbrev-ref HEAD' ) ),
		'head' => $t_head,
		'bare_head' => $t_bare_head,
		'in_sync' => $t_head !== '' && $t_head === $t_bare_head,
		'tracked' => count( git_store_lines( git_store_run( $t_wt, false, 'ls-files' ) ) ),
		'size' => git_store_disk_usage( $t_wt ),
		'changes' => $t_changes,
	);
}

/**
 * Content of a blob ("<rev>:<path>" or a blob sha), at most $p_max bytes.
 *
 * @param array   $p_repo
 * @param string  $p_spec
 * @param integer $p_max
 * @return string|null Null when the object is not a blob.
 */
function git_store_blob( array $p_repo, string $p_spec, int $p_max = 262144 ) {
	$t_type = trim( git_store_run( $p_repo['bare'], true, 'cat-file -t ' . escapeshellarg( $p_spec ) ) );
	if( $t_type !== 'blob' ) {
		return null;
	}
	return substr( git_store_run( $p_repo['bare'], true, 'cat-file blob ' . escapeshellarg( $p_spec ) ), 0, $p_max );
}

/**
 * Create a document for a file already in the repository and register it.
 *
 * Used by the bulk "register all with frontmatter" action.  The single-file
 * path goes through the ordinary create form instead, so a person can supply
 * what no file can state about itself.
 *
 * The document is pinned to the commit that last changed the path, not to
 * HEAD: HEAD moves whenever anyone pushes anything, and the document should
 * record the change that actually produced it (owner decision 2026-10-28).
 *
 * Goes through DwgAddCommand like the form and the importer, so one creation
 * path keeps its validation, history and notifications.
 *
 * @param array   $p_repo       Git store repository entry.
 * @param integer $p_project_id Project the document belongs to.
 * @param string  $p_path       Repository-relative path.
 * @param array   $p_front      dwg_frontmatter_parse() output.
 * @param integer $p_user_id    Acting user.
 * @return integer The new document id.
 */
function git_store_register_document( array $p_repo, int $p_project_id, string $p_path,
		array $p_front, int $p_user_id ): int {
	require_api( 'dwg_frontmatter_api.php' );
	require_api( 'file_dwg_api.php' );

	$t_sha = git_store_last_commit_sha( $p_repo, $p_path );
	if( $t_sha === '' ) {
		throw new ClientException( 'No commit changed ' . $p_path,
			ERROR_INVALID_FIELD_VALUE, array( 'git_path' ) );
	}

	$t_fields = dwg_frontmatter_fields( $p_front );
	$t_title  = (string)( $t_fields['dwg_title'] ?? pathinfo( $p_path, PATHINFO_FILENAME ) );

	# The directory the file sits in is its category, as the importer does.
	$t_dir = dirname( $p_path );
	$t_category = ( $t_dir === '.' || $t_dir === '' ) ? 'General' : $t_dir;
	$t_category = substr( $t_category, 0, 128 );
	if( category_get_id_by_name( $t_category, $p_project_id, /* trigger_errors */ false ) === false ) {
		category_add( $p_project_id, $t_category );
	}

	$t_issue = array(
		'project'        => array( 'id' => $p_project_id ),
		'category'       => array( 'name' => $t_category ),
		'summary'        => mb_substr( $t_title, 0, 255 ),
		'description'    => 'Registered from the repository (path: ' . $p_path . ')',
		'title'          => $t_title,
		'author'         => (string)( $t_fields['dwg_author'] ?? '' ),
		'number'         => (string)( $t_fields['dwg_number'] ?? '' ),
		'edition'        => (string)( $t_fields['dwg_edition'] ?? '' ),
		'revision'       => (string)( $t_fields['dwg_revision'] ?? '' ),
		'classification' => (string)( $t_fields['dwg_classification'] ?? '' ),
		'status'         => array( 'id' => dwg_frontmatter_status( (string)( $p_front['status'] ?? '' ) ) ),
		'custom_fields'  => array(),
	);

	$t_command = new DwgAddCommand( array( 'payload' => array( 'issue' => $t_issue ) ) );
	$t_result  = $t_command->execute();
	$t_dwg_id  = (int)$t_result['issue_id'];

	file_dwg_primary_register( $t_dwg_id, $p_user_id, $p_path, $t_sha,
		'Registered from the repository' );

	return $t_dwg_id;
}

/**
 * Of the given paths, those whose content carries a frontmatter block with
 * enough in it to register a document unattended.
 *
 * Bulk registration needs this: a file with no frontmatter has no title, and
 * a document with no name is worse than no document.  Those are registered
 * one at a time instead, through the create form, where a person supplies
 * the metadata -- which is also why this says nothing about file type.  A
 * .step or a .pdf simply never qualifies here.
 *
 * @param array $p_repo  Git store repository entry.
 * @param array $p_paths Repository-relative paths.
 * @return array path => frontmatter fields, for the ones that qualify.
 */
function git_store_frontmatter_ready( array $p_repo, array $p_paths ): array {
	require_api( 'dwg_frontmatter_api.php' );
	$t_ready = array();
	foreach( $p_paths as $t_path ) {
		# Only a text blob can carry a block; 4 KiB is far beyond any real
		# one, and keeps a large binary from being read at all.
		$t_blob = git_store_blob( $p_repo, 'HEAD:' . $t_path, 4096 );
		if( $t_blob === null || $t_blob === '' || !git_store_is_text( $t_blob ) ) {
			continue;
		}
		$t_front = dwg_frontmatter_parse( $t_blob );
		if( $t_front && dwg_frontmatter_sufficient( $t_front ) ) {
			$t_ready[$t_path] = $t_front;
		}
	}
	return $t_ready;
}

/**
 * Whether content looks like UTF-8 text (no NUL bytes, valid encoding).
 *
 * @param string $p_content
 * @return boolean
 */
function git_store_is_text( string $p_content ): bool {
	return strpos( substr( $p_content, 0, 8000 ), "\0" ) === false && mb_check_encoding( $p_content, 'UTF-8' );
}
