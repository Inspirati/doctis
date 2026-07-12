<?php
# MantisBT - A PHP based bugtracking system

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

use CzProject\GitPhp\Git;
use CzProject\GitPhp\GitException;
use Mantis\Exceptions\ClientException;
use Mantis\Exceptions\ServiceException;

/**
 * GIT storage backend.
 *
 * Stores each uploaded document file as a git commit in a per-project bare
 * repository.  {dwg_primary_file}.git_sha holds the commit SHA; {dwg_primary_file}.folder
 * holds the absolute path to the bare repository (redundant — derivable from project).
 *
 * Repository layout (naming owned by dwg_project_* helpers in file_dwg_api.php):
 *   <git_storage_root>/<slug>-<project_id>.git   — bare repo (authoritative store)
 *   <git_worktree_root>/<slug>-<project_id>      — working tree (write staging area)
 *
 * File path within the repo:
 *   <dwg_id>/<filename>
 *
 * Config keys (config_defaults_inc.php):
 *   $g_git_storage_root   — absolute path to bare repo root
 *   $g_git_worktree_root  — absolute path to working tree root
 */
class GitFileStorageBackend implements FileStorageBackendInterface {

	# ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Ensure HOME is set so that git can locate the www-data global gitconfig,
	 * and set GIT_AUTHOR_* / GIT_COMMITTER_* from that config as a fallback.
	 *
	 * Apache does not set HOME for the www-data worker process, so git cannot
	 * find /var/www/.gitconfig.  We always force HOME to the value from
	 * /etc/passwd rather than trusting whatever Apache may have set.
	 *
	 * Additionally we propagate GIT_AUTHOR_NAME / EMAIL and their COMMITTER
	 * counterparts so that git commit never fails with "Author identity
	 * unknown" even when the HOME trick is insufficient (e.g. running under
	 * an unusual Apache configuration).
	 */
	private function ensure_git_home(): void {
		$t_www_data_home = posix_getpwuid( posix_getuid() )['dir'] ?? '/var/www';
		putenv( 'HOME=' . $t_www_data_home );

		# Read name/email from the gitconfig so we don't hard-code them here.
		# These become the fallback identity; set_git_author() overrides them
		# with the actual Doctis user's details for each operation.
		$t_gitconfig = $t_www_data_home . '/.gitconfig';
		$t_name  = trim( (string)shell_exec( 'git config --file ' . escapeshellarg( $t_gitconfig ) . ' user.name 2>/dev/null' ) );
		$t_email = trim( (string)shell_exec( 'git config --file ' . escapeshellarg( $t_gitconfig ) . ' user.email 2>/dev/null' ) );

		if( $t_name !== '' ) {
			putenv( 'GIT_AUTHOR_NAME='    . $t_name );
			putenv( 'GIT_COMMITTER_NAME=' . $t_name );
		}
		if( $t_email !== '' ) {
			putenv( 'GIT_AUTHOR_EMAIL='    . $t_email );
			putenv( 'GIT_COMMITTER_EMAIL=' . $t_email );
		}
	}

	/**
	 * Override GIT_AUTHOR_* / GIT_COMMITTER_* with the identity of the
	 * Doctis user performing the operation, so each git commit is attributed
	 * to the actual uploader rather than the generic www-data system account.
	 *
	 * Call this after ensure_git_home() — the gitconfig values set there
	 * act as a safe fallback; this method overrides only what it can resolve.
	 *
	 * Name: uses realname when set, otherwise the login username.
	 * Email: uses the address stored in the user table; if blank (the account
	 * has no email address) the gitconfig fallback is left unchanged.
	 *
	 * @param int $p_user_id  Doctis user id.
	 */
	private function set_git_author( int $p_user_id ): void {
		# user_get_name() returns realname ?? username, matching the display
		# convention used elsewhere in Doctis.
		$t_name  = user_get_name( $p_user_id );
		$t_email = user_get_email( $p_user_id );

		if( !is_blank( $t_name ) ) {
			putenv( 'GIT_AUTHOR_NAME='    . $t_name );
			putenv( 'GIT_COMMITTER_NAME=' . $t_name );
		}
		if( !is_blank( $t_email ) ) {
			putenv( 'GIT_AUTHOR_EMAIL='    . $t_email );
			putenv( 'GIT_COMMITTER_EMAIL=' . $t_email );
		}
	}

	/**
	 * Ensure the bare repo and working tree exist for the given project,
	 * creating them on first use.
	 *
	 * Repository naming (bare repo and worktree) is owned by the
	 * dwg_project_* helpers in file_dwg_api.php — "<slug>-<project_id>",
	 * resolved by the immutable id suffix.  A project rename relocates the
	 * repository via dwg_project_repo_rename(), serialised against this
	 * method by the shared storage lock.
	 *
	 * @param int $p_project_id
	 * @return array{bare: string, worktree: string}
	 * @throws ServiceException
	 */
	private function ensure_project_repo( int $p_project_id ): array {
		# Serialise against dwg_project_repo_rename() so creation cannot race
		# a concurrent relocation of the same project's repository.
		$t_lock = dwg_git_storage_lock();
		try {
			$t_bare     = dwg_project_bare_repo_path( $p_project_id );
			$t_worktree = dwg_project_worktree_path( $p_project_id );

			if( !is_dir( $t_bare ) ) {
				exec( 'git init --bare ' . escapeshellarg( $t_bare ) . ' 2>&1', $t_out, $t_rc );
				if( $t_rc !== 0 ) {
					throw new ServiceException(
						'git init --bare failed for project ' . $p_project_id . ': ' . implode( ' ', $t_out ),
						ERROR_GENERIC
					);
				}
			}

			$this->configure_bare_repo( $t_bare );

			if( !is_dir( $t_worktree ) ) {
				exec(
					'git clone ' . escapeshellarg( $t_bare ) . ' ' . escapeshellarg( $t_worktree ) . ' 2>&1',
					$t_out, $t_rc
				);
				if( $t_rc !== 0 ) {
					throw new ServiceException(
						'git clone failed for project ' . $p_project_id . ': ' . implode( ' ', $t_out ),
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
	 * Install/refresh the Doctis server-side configuration on a bare repo:
	 * the pre-receive hook (rejects force-pushes, ref deletions, and client
	 * writes to refs/doctis/*) and http.receivepack (allows authenticated
	 * push through the Smart HTTP gateway).
	 *
	 * Idempotent, and called on every ensure_project_repo() so existing
	 * repositories pick up hook updates automatically.  The hook source of
	 * truth is admin/tools/git-hooks/pre-receive.
	 *
	 * @param string $p_bare  Absolute path to the bare repository.
	 */
	private function configure_bare_repo( string $p_bare ): void {
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
	 * Relative path of a document file within its project repository.
	 *
	 * @param int    $p_dwg_id
	 * @param string $p_filename
	 * @return string  e.g. "42/report.pdf"
	 */
	private function repo_rel_path( int $p_dwg_id, string $p_filename ): string {
		return $p_dwg_id . '/' . $p_filename;
	}

	# ── Interface implementation ───────────────────────────────────────────────

	/**
	 * @inheritDoc
	 *
	 * Writes the uploaded file into the project working tree, commits it, and
	 * pushes to the bare repo.  Returns the commit SHA as the diskfile
	 * identifier and the bare repo path as the folder.
	 */
	public function store(
		string $p_tmp_file,
		int    $p_file_size,
		string $p_unique_name,
		string $p_file_path,
		bool   $p_browser_upload,
		array  $p_metadata = []
	): array {
		$this->ensure_git_home();

		$t_project_id = (int)$p_metadata['project_id'];
		$t_dwg_id     = (int)$p_metadata['dwg_id'];
		$t_filename   = $p_metadata['filename'];
		$t_user_id    = (int)$p_metadata['user_id'];

		$this->set_git_author( $t_user_id );

		$t_paths    = $this->ensure_project_repo( $t_project_id );
		$t_bare     = $t_paths['bare'];
		$t_worktree = $t_paths['worktree'];

		# Remote pushes advance the bare repo independently of this worktree;
		# sync before staging so our commit fast-forwards from origin HEAD.
		dwg_git_worktree_sync( $t_worktree );

		# Create the per-document subdirectory if needed
		$t_abs_dir = $t_worktree . '/' . $t_dwg_id;
		if( !is_dir( $t_abs_dir ) ) {
			if( !mkdir( $t_abs_dir, 0775, true ) ) {
				throw new ServiceException(
					'Unable to create directory ' . $t_abs_dir,
					ERROR_GENERIC
				);
			}
		}

		# Place the uploaded file into the working tree
		$t_rel_path = $this->repo_rel_path( $t_dwg_id, $t_filename );
		$t_abs_file = $t_worktree . '/' . $t_rel_path;

		if( $p_browser_upload ) {
			if( !move_uploaded_file( $p_tmp_file, $t_abs_file ) ) {
				throw new ServiceException(
					'Unable to move uploaded file into git working tree',
					ERROR_FILE_MOVE_FAILED
				);
			}
		} else {
			if( !copy( $p_tmp_file, $t_abs_file ) || !unlink( $p_tmp_file ) ) {
				throw new ServiceException(
					'Unable to copy uploaded file into git working tree',
					ERROR_FILE_MOVE_FAILED
				);
			}
		}

		# Commit and push via czproject/git-php
		try {
			$t_git  = new Git;
			$t_repo = $t_git->open( $t_worktree );
			$t_repo->addFile( $t_rel_path );

			$t_username   = user_get_name( $t_user_id );
			$t_commit_msg = 'dwg_id=' . $t_dwg_id . ' by ' . $t_username;

			try {
				$t_repo->commit( $t_commit_msg );
			} catch( GitException $e ) {
				# Exit code 1 means "nothing to commit" — the file content is
				# already present in the tree.  Treat it as a successful store
				# and return the current HEAD SHA.
				if( $e->getCode() !== 1 ) {
					throw $e;
				}
			}
			$t_branch = $t_repo->getCurrentBranchName();
			$t_repo->push( [ 'origin', $t_branch ] );
			$t_sha = (string)$t_repo->getLastCommitId();
		} catch( GitException $e ) {
			$t_result = $e->getRunnerResult();
			$t_stderr = $t_result ? implode( "\n", $t_result->getErrorOutput() ) : '(no result)';
			throw new ServiceException(
				'Git operation failed during store: ' . $e->getMessage() . ' | stderr: ' . $t_stderr,
				ERROR_GENERIC
			);
		}

		return array(
			'diskfile' => $t_sha,
			'folder'   => $t_bare,
			'content'  => '',
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Retrieves file content directly from the bare repository using the
	 * stored commit SHA, without touching the working tree.
	 */
	public function retrieve( array $p_row, int $p_project_id ) {
		# Derive the bare repo location from the immutable project id; the
		# stored folder column is informational only and can go stale when a
		# repository is relocated after a project rename.
		$t_bare = dwg_project_bare_repo_path( $p_project_id );
		if( !is_dir( $t_bare ) ) {
			$t_bare = $p_row['folder'];
		}
		# {dwg_primary_file} uses git_sha; {dwg_file} attachments retain the
		# MantisBT-inherited diskfile column name.  Accept either.
		$t_sha      = $p_row['git_sha'] ?? $p_row['diskfile'];
		$t_rel_path = $this->repo_rel_path( (int)$p_row['dwg_id'], $p_row['filename'] );

		$t_content = shell_exec(
			'git --git-dir=' . escapeshellarg( $t_bare ) .
			' show ' . escapeshellarg( $t_sha . ':' . $t_rel_path )
		);

		if( $t_content === null ) {
			return false;
		}

		return array(
			'type'    => $p_row['file_type'],
			'content' => $t_content,
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Performs a soft-delete: removes the file from the working tree,
	 * commits the removal, and pushes.  The file disappears from HEAD but
	 * the full commit history — including all previous content — is retained
	 * in the bare repository.
	 */
	public function delete( string $p_diskfile, int $p_project_id, array $p_metadata = [] ): void {
		$this->ensure_git_home();

		$t_dwg_id   = (int)$p_metadata['dwg_id'];
		$t_filename = $p_metadata['filename'];
		$t_user_id  = (int)$p_metadata['user_id'];

		$this->set_git_author( $t_user_id );

		$t_worktree = dwg_project_worktree_path( $p_project_id );

		dwg_git_worktree_sync( $t_worktree );

		$t_rel_path = $this->repo_rel_path( $t_dwg_id, $t_filename );

		try {
			$t_git  = new Git;
			$t_repo = $t_git->open( $t_worktree );
			$t_repo->removeFile( $t_rel_path );

			$t_username   = user_get_name( $t_user_id );
			$t_commit_msg = 'dwg_id=' . $t_dwg_id . ' FILE_DELETED by ' . $t_username;

			$t_repo->commit( $t_commit_msg );
			$t_branch = $t_repo->getCurrentBranchName();
			$t_repo->push( [ 'origin', $t_branch ] );
		} catch( GitException $e ) {
			throw new ServiceException(
				'Git operation failed during delete: ' . $e->getMessage(),
				ERROR_GENERIC
			);
		}
	}
}
