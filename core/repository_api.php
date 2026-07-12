<?php
# Doctis - Document Issue Tracking System

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Repository API — first-class git repository entity (Doctis-specific).
 *
 * A {repository} row represents one bare git repository under
 * $g_git_storage_root, the storage and clone/push unit for primary
 * documents.  Projects map to repositories through {project_repository}:
 * a project with an explicit link uses that repository; a project without
 * one inherits by walking up the project hierarchy; when no ancestor has a
 * repository either, one is created on demand at the top-level project.
 * A repository is therefore shared by a whole project tree unless a
 * sub-project is explicitly linked to a repository of its own.
 *
 * On-disk layout (basename "<slug>-r<id>", e.g. "example-r1"):
 *   <git_storage_root>/<slug>-r<id>.git    bare repo (authoritative store)
 *   <git_worktree_root>/<slug>-r<id>       server worktree (write staging)
 *
 * The immutable "-r<id>" suffix is what all lookup resolves by; the slug is
 * cosmetic, follows the owner project's name, and is refreshed on project
 * rename (repository_rename_for_owner_project()).  The stored slug column is
 * authoritative for the on-disk name — nothing scans the storage root.
 *
 * @package CoreAPI
 * @subpackage RepositoryAPI
 *
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses database_api.php
 * @uses project_api.php
 * @uses project_hierarchy_api.php
 * @uses utility_api.php
 */

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'project_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'utility_api.php' );

use Mantis\Exceptions\ServiceException;

$g_cache_repository = array();

# =============================================================================
# Advisory storage lock
# =============================================================================

/**
 * Take the advisory git-storage lock (blocking).
 *
 * Serialises repository relocation (repository_rename_for_owner_project)
 * against repository creation/adoption (repository_ensure_on_disk,
 * repository_adopt) so neither can observe the other mid-operation.  Read
 * paths deliberately do not take the lock: the bare-repo rename is a single
 * atomic rename(2), so resolvers see the old name or the new one, never
 * neither.
 *
 * @return resource|null  Lock handle for dwg_git_storage_unlock(), or null
 *                        when the storage root is not configured/present
 *                        (callers proceed unlocked — single-writer setups).
 */
function dwg_git_storage_lock() {
	$t_root = config_get_global( 'git_storage_root' );
	if( is_blank( $t_root ) || !is_dir( $t_root ) ) {
		return null;
	}
	$t_fp = @fopen( $t_root . '/.doctis-lock', 'c' );
	if( $t_fp === false ) {
		return null;
	}
	flock( $t_fp, LOCK_EX );
	return $t_fp;
}

/**
 * Release the advisory git-storage lock.
 *
 * @param resource|null $p_lock  Handle from dwg_git_storage_lock().
 * @return void
 */
function dwg_git_storage_unlock( $p_lock ): void {
	if( is_resource( $p_lock ) ) {
		flock( $p_lock, LOCK_UN );
		fclose( $p_lock );
	}
}

# =============================================================================
# Entity access
# =============================================================================

/**
 * Return the {repository} row for an id, or false if it does not exist.
 *
 * @param int $p_repository_id
 * @return array|false
 */
function repository_get_row( int $p_repository_id ) {
	global $g_cache_repository;
	if( isset( $g_cache_repository[$p_repository_id] ) ) {
		return $g_cache_repository[$p_repository_id];
	}
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {repository} WHERE id=' . db_param(), array( $p_repository_id ) );
	$t_row = db_fetch_array( $t_result );
	if( $t_row ) {
		$g_cache_repository[$p_repository_id] = $t_row;
	}
	return $t_row ? $t_row : false;
}

/**
 * Compute the cosmetic slug for a repository name.  A name that slugifies to
 * nothing (all punctuation / non-ASCII) falls back to "repo".
 *
 * @param string $p_name
 * @return string  e.g. "example", "hcr-hardware-designs"
 */
function repository_slug( string $p_name ): string {
	$t_slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( $p_name ) ) ), '-' );
	if( $t_slug === '' ) {
		$t_slug = 'repo';
	}
	return $t_slug;
}

/**
 * Create a {repository} row and link its owner project to it.
 * Does NOT materialise anything on disk — see repository_ensure_on_disk().
 *
 * @param string $p_name             Display name (normally the owner project name).
 * @param int    $p_owner_project_id Project that owns the repository (authz unit
 *                                   for the Smart HTTP gateway).
 * @param string $p_adopted_from     Source URL/path when imported; '' when created empty.
 * @param string $p_default_branch
 * @return int  New repository id.
 */
function repository_create( string $p_name, int $p_owner_project_id, string $p_adopted_from = '', string $p_default_branch = 'main' ): int {
	db_param_push();
	db_query(
		'INSERT INTO {repository} ( name, slug, default_branch, owner_project_id, adopted_from, date_created )
		 VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_name, repository_slug( $p_name ), $p_default_branch, $p_owner_project_id, $p_adopted_from, db_now() )
	);
	$t_repository_id = db_insert_id( db_get_table( 'repository' ) );

	repository_link_project( $p_owner_project_id, $t_repository_id );

	return $t_repository_id;
}

/**
 * Explicitly link a project to a repository (insert or replace the link).
 *
 * @param int $p_project_id
 * @param int $p_repository_id
 * @return void
 */
function repository_link_project( int $p_project_id, int $p_repository_id ): void {
	db_param_push();
	db_query( 'DELETE FROM {project_repository} WHERE project_id=' . db_param(), array( $p_project_id ) );
	db_param_push();
	db_query(
		'INSERT INTO {project_repository} ( project_id, repository_id ) VALUES ( ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $p_repository_id )
	);
}

/**
 * Repository id a project is explicitly linked to, or 0 when unlinked.
 *
 * @param int $p_project_id
 * @return int
 */
function repository_id_linked_to_project( int $p_project_id ): int {
	db_param_push();
	$t_result = db_query(
		'SELECT repository_id FROM {project_repository} WHERE project_id=' . db_param(),
		array( $p_project_id )
	);
	$t_id = db_result( $t_result );
	return $t_id === false ? 0 : (int)$t_id;
}

# =============================================================================
# Project → repository resolution
# =============================================================================

/**
 * Resolve the repository a project's documents live in: the project's own
 * explicit link if present, else the nearest linked ancestor's.
 * Returns 0 when neither the project nor any ancestor has a repository.
 *
 * @param int $p_project_id
 * @return int  Repository id, or 0.
 */
function repository_id_for_project( int $p_project_id ): int {
	$t_id   = $p_project_id;
	$t_seen = array();
	while( $t_id > 0 && !isset( $t_seen[$t_id] ) ) {
		$t_seen[$t_id] = true;
		$t_repo = repository_id_linked_to_project( $t_id );
		if( $t_repo > 0 && repository_get_row( $t_repo ) !== false ) {
			return $t_repo;
		}
		$t_id = (int)project_hierarchy_get_parent( $t_id, /* show_disabled */ true );
	}
	return 0;
}

/**
 * Resolve as repository_id_for_project(), creating the repository when the
 * whole tree has none yet: the new repository is created at (and named
 * after) the top-level ancestor, so a project tree shares one repository by
 * default.
 *
 * @param int $p_project_id
 * @return int  Repository id (always > 0).
 */
function repository_id_for_project_or_create( int $p_project_id ): int {
	$t_repo = repository_id_for_project( $p_project_id );
	if( $t_repo > 0 ) {
		return $t_repo;
	}

	# Walk to the top of the (first-parent) hierarchy chain.
	$t_top  = $p_project_id;
	$t_seen = array();
	while( !isset( $t_seen[$t_top] ) ) {
		$t_seen[$t_top] = true;
		$t_parent = (int)project_hierarchy_get_parent( $t_top, /* show_disabled */ true );
		if( $t_parent < 1 ) {
			break;
		}
		$t_top = $t_parent;
	}

	return repository_create( (string)project_get_field( $t_top, 'name' ), $t_top );
}

/**
 * All project ids whose documents resolve to the given repository: every
 * project explicitly linked to it, plus all their descendants that are not
 * explicitly linked elsewhere.  Used for per-repository uniqueness checks
 * (e.g. git_path collisions across projects sharing a repository).
 *
 * @param int $p_repository_id
 * @return array<int>
 */
function repository_project_ids( int $p_repository_id ): array {
	db_param_push();
	$t_result = db_query(
		'SELECT project_id FROM {project_repository} WHERE repository_id=' . db_param(),
		array( $p_repository_id )
	);
	$t_linked = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_linked[] = (int)$t_row['project_id'];
	}

	$t_ids = array();
	foreach( $t_linked as $t_project_id ) {
		$t_ids[$t_project_id] = true;
		foreach( project_hierarchy_get_all_subprojects( $t_project_id, /* show_disabled */ true ) as $t_sub ) {
			# A descendant explicitly linked to a different repository is excluded.
			$t_own = repository_id_linked_to_project( (int)$t_sub );
			if( $t_own === 0 || $t_own === $p_repository_id ) {
				$t_ids[(int)$t_sub] = true;
			}
		}
	}
	return array_keys( $t_ids );
}

# =============================================================================
# On-disk layout
# =============================================================================

/**
 * Canonical on-disk basename "<slug>-r<id>" (no ".git").
 *
 * @param int $p_repository_id
 * @return string  e.g. "example-r1"
 * @throws ServiceException when the repository row does not exist.
 */
function repository_basename( int $p_repository_id ): string {
	$t_row = repository_get_row( $p_repository_id );
	if( $t_row === false ) {
		throw new ServiceException( 'Unknown repository id ' . $p_repository_id, ERROR_GENERIC );
	}
	$t_slug = $t_row['slug'] !== '' ? $t_row['slug'] : 'repo';
	return $t_slug . '-r' . $p_repository_id;
}

/**
 * Absolute path to the bare repository.
 *
 * @param int $p_repository_id
 * @return string  e.g. "/var/git/doctis/example-r1.git"
 */
function repository_bare_path( int $p_repository_id ): string {
	return config_get_global( 'git_storage_root' ) . '/' . repository_basename( $p_repository_id ) . '.git';
}

/**
 * Absolute path to the server working tree.
 *
 * @param int $p_repository_id
 * @return string  e.g. "/var/www/doctis/worktrees/example-r1"
 */
function repository_worktree_path( int $p_repository_id ): string {
	return config_get_global( 'git_worktree_root' ) . '/' . repository_basename( $p_repository_id );
}

/**
 * Install/refresh the Doctis server-side configuration on a bare repo: the
 * pre-receive hook (rejects force-pushes, ref deletions, and client writes
 * to refs/doctis/*) and http.receivepack (allows authenticated push through
 * the Smart HTTP gateway).  Idempotent.  Hook source of truth:
 * admin/tools/git-hooks/pre-receive.
 *
 * @param string $p_bare  Absolute path to the bare repository.
 * @return void
 */
function repository_configure_bare( string $p_bare ): void {
	$t_hook_src = config_get_global( 'absolute_path' ) . 'admin/tools/git-hooks/pre-receive';
	$t_hook_dst = $p_bare . '/hooks/pre-receive';

	if( is_file( $t_hook_src ) ) {
		$t_content = file_get_contents( $t_hook_src );
		if( $t_content !== false
		 && ( !is_file( $t_hook_dst ) || file_get_contents( $t_hook_dst ) !== $t_content ) ) {
			file_put_contents( $t_hook_dst, $t_content );
			chmod( $t_hook_dst, 0755 );
		}
	}

	exec( 'git --git-dir=' . escapeshellarg( $p_bare ) . ' config http.receivepack true 2>&1' );
}

/**
 * Ensure the bare repository and server worktree exist on disk, creating
 * them when absent, and refresh the bare-repo configuration.  Serialised
 * against repository relocation by the advisory storage lock.
 *
 * @param int $p_repository_id
 * @return array{bare: string, worktree: string}
 * @throws ServiceException on git failure.
 */
function repository_ensure_on_disk( int $p_repository_id ): array {
	$t_lock = dwg_git_storage_lock();
	try {
		$t_bare     = repository_bare_path( $p_repository_id );
		$t_worktree = repository_worktree_path( $p_repository_id );

		if( !is_dir( $t_bare ) ) {
			exec( 'git init --bare ' . escapeshellarg( $t_bare ) . ' 2>&1', $t_out, $t_rc );
			if( $t_rc !== 0 ) {
				throw new ServiceException(
					'git init --bare failed for repository ' . $p_repository_id . ': ' . implode( ' ', $t_out ),
					ERROR_GENERIC
				);
			}
		}

		repository_configure_bare( $t_bare );

		if( !is_dir( $t_worktree ) ) {
			exec(
				'git clone ' . escapeshellarg( $t_bare ) . ' ' . escapeshellarg( $t_worktree ) . ' 2>&1',
				$t_out, $t_rc
			);
			if( $t_rc !== 0 ) {
				throw new ServiceException(
					'git clone failed for repository ' . $p_repository_id . ': ' . implode( ' ', $t_out ),
					ERROR_GENERIC
				);
			}
		}
	} finally {
		dwg_git_storage_unlock( $t_lock );
	}

	return array( 'bare' => $t_bare, 'worktree' => $t_worktree );
}

/**
 * Adopt an existing git repository as a Doctis repository: clone --bare from
 * the source into the storage root under the canonical name, strip inherited
 * remotes (Doctis becomes the authoritative home), install the pre-receive
 * hook, and create the server worktree.  The {repository} row must already
 * exist (repository_create() with adopted_from set).
 *
 * @param int    $p_repository_id
 * @param string $p_source  Local path or URL of the repository to adopt.
 * @return array{bare: string, worktree: string}
 * @throws ServiceException on git failure or when the bare path already exists.
 */
function repository_adopt( int $p_repository_id, string $p_source ): array {
	$t_lock = dwg_git_storage_lock();
	try {
		$t_bare     = repository_bare_path( $p_repository_id );
		$t_worktree = repository_worktree_path( $p_repository_id );

		if( is_dir( $t_bare ) ) {
			throw new ServiceException(
				'Repository already exists on disk: ' . $t_bare, ERROR_GENERIC
			);
		}

		exec(
			'git clone --bare ' . escapeshellarg( $p_source ) . ' ' . escapeshellarg( $t_bare ) . ' 2>&1',
			$t_out, $t_rc
		);
		if( $t_rc !== 0 ) {
			throw new ServiceException(
				'git clone --bare failed for repository ' . $p_repository_id . ': ' . implode( ' ', $t_out ),
				ERROR_GENERIC
			);
		}

		# Doctis is now the authoritative home; a leftover origin would invite
		# accidental two-way sync (rejected — see doc/git/GIT_SOLUTION_SPACE.md D3).
		$t_remotes = trim( (string)shell_exec(
			'git --git-dir=' . escapeshellarg( $t_bare ) . ' remote 2>/dev/null'
		) );
		foreach( array_filter( explode( "\n", $t_remotes ) ) as $t_remote ) {
			exec(
				'git --git-dir=' . escapeshellarg( $t_bare ) .
				' remote remove ' . escapeshellarg( trim( $t_remote ) ) . ' 2>&1'
			);
		}

		repository_configure_bare( $t_bare );

		# Record the adopted default branch on the entity.
		$t_branch = trim( (string)shell_exec(
			'git --git-dir=' . escapeshellarg( $t_bare ) . ' symbolic-ref --short HEAD 2>/dev/null'
		) );
		if( $t_branch !== '' ) {
			db_param_push();
			db_query(
				'UPDATE {repository} SET default_branch=' . db_param() . ' WHERE id=' . db_param(),
				array( $t_branch, $p_repository_id )
			);
			global $g_cache_repository;
			unset( $g_cache_repository[$p_repository_id] );
		}

		exec(
			'git clone ' . escapeshellarg( $t_bare ) . ' ' . escapeshellarg( $t_worktree ) . ' 2>&1',
			$t_out, $t_rc
		);
		if( $t_rc !== 0 ) {
			throw new ServiceException(
				'git clone (worktree) failed for repository ' . $p_repository_id . ': ' . implode( ' ', $t_out ),
				ERROR_GENERIC
			);
		}
	} finally {
		dwg_git_storage_unlock( $t_lock );
	}

	return array( 'bare' => $t_bare, 'worktree' => $t_worktree );
}

/**
 * Refresh the cosmetic slug of every repository owned by a project after the
 * project is renamed, relocating the on-disk bare repo to match.  Correctness
 * never depends on this — all lookup is by the immutable "-r<id>" suffix and
 * the stored slug — it keeps the storage root legible for operators.
 *
 * Sequence per repository, under the storage lock:
 *   1. rename(2) the bare repository — the atomic pivot;
 *   2. update the stored slug (the authoritative name source);
 *   3. delete the server worktree — a disposable staging cache whose origin
 *      URL embeds the old bare path; repository_ensure_on_disk() re-clones
 *      it on the next store.
 *
 * @param int $p_project_id  The renamed project.
 * @return void
 */
function repository_rename_for_owner_project( int $p_project_id ): void {
	$t_root = config_get_global( 'git_storage_root' );
	if( is_blank( $t_root ) || !is_dir( $t_root ) ) {
		return;
	}

	db_param_push();
	$t_result = db_query(
		'SELECT id FROM {repository} WHERE owner_project_id=' . db_param(),
		array( $p_project_id )
	);
	$t_repo_ids = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		$t_repo_ids[] = (int)$t_row['id'];
	}
	if( empty( $t_repo_ids ) ) {
		return;
	}

	$t_new_slug = repository_slug( (string)project_get_field( $p_project_id, 'name' ) );

	global $g_cache_repository;
	foreach( $t_repo_ids as $t_repo_id ) {
		$t_repo = repository_get_row( $t_repo_id );
		if( $t_repo === false || $t_repo['slug'] === $t_new_slug ) {
			continue;
		}

		$t_lock = dwg_git_storage_lock();

		$t_old_base = repository_basename( $t_repo_id );
		$t_new_base = $t_new_slug . '-r' . $t_repo_id;
		$t_old_bare = $t_root . '/' . $t_old_base . '.git';
		$t_new_bare = $t_root . '/' . $t_new_base . '.git';

		if( !is_dir( $t_old_bare ) ) {
			# Nothing materialised yet — just refresh the slug.
			db_param_push();
			db_query( 'UPDATE {repository} SET slug=' . db_param() . ' WHERE id=' . db_param(),
				array( $t_new_slug, $t_repo_id ) );
			unset( $g_cache_repository[$t_repo_id] );
			dwg_git_storage_unlock( $t_lock );
			continue;
		}

		if( file_exists( $t_new_bare ) ) {
			# Never clobber a directory that might be a repository; the old
			# name keeps working (lookup is by stored slug).
			error_log( 'repository_rename_for_owner_project: target ' . $t_new_bare . ' already exists; rename skipped' );
			dwg_git_storage_unlock( $t_lock );
			continue;
		}

		if( !rename( $t_old_bare, $t_new_bare ) ) {
			error_log( 'repository_rename_for_owner_project: rename ' . $t_old_bare . ' -> ' . $t_new_bare . ' failed' );
			dwg_git_storage_unlock( $t_lock );
			continue;
		}

		db_param_push();
		db_query( 'UPDATE {repository} SET slug=' . db_param() . ' WHERE id=' . db_param(),
			array( $t_new_slug, $t_repo_id ) );
		unset( $g_cache_repository[$t_repo_id] );

		# Remove the stale worktree (and any leftover at the new name) so the
		# lazy re-clone starts clean.
		$t_wt_root = config_get_global( 'git_worktree_root' );
		if( !is_blank( $t_wt_root ) && is_dir( $t_wt_root ) ) {
			foreach( array( $t_old_base, $t_new_base ) as $t_base ) {
				$t_worktree = $t_wt_root . '/' . $t_base;
				if( is_dir( $t_worktree ) && !is_link( $t_worktree ) ) {
					exec( 'rm -rf ' . escapeshellarg( $t_worktree ) );
				}
			}
		}

		dwg_git_storage_unlock( $t_lock );
	}
}
