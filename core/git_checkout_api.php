<?php
# Doctis - Document Issue Tracking System
#
# Application checkout diagnostics for the System Operations pages.
#
# "git pull" refuses to update a file that differs from its commit.  These
# helpers report how the deployed checkout differs, separating permission-only
# changes (e.g. a container build running "chmod -R") from content edits, and
# provide the two repairs that unblock a pull: ignoring permission changes
# (core.fileMode=false) and restoring edited files after saving them as a patch.
#
# Read-only commands run as the web server account.  Repairs retry as the
# System Operations account (sudo) when the web server account cannot write
# to the repository.

require_api( 'system_ops_api.php' );

/**
 * Absolute path of the application checkout.
 *
 * @return string
 */
function git_checkout_path(): string {
	return dirname( __DIR__ );
}

/**
 * Run a git command against the application checkout as the web server account.
 *
 * @param string  $p_args Git arguments, already shell-escaped where needed.
 * @param integer $p_rc   Receives the exit code.
 * @return string Combined stdout and stderr.
 */
function git_checkout_git( string $p_args, &$p_rc = null ): string {
	$t_repo = escapeshellarg( git_checkout_path() );
	$t_cmd = '/usr/bin/git -c safe.directory=' . $t_repo . ' -c core.quotePath=false'
		. ' --no-optional-locks -C ' . $t_repo . ' ' . $p_args . ' 2>&1';
	exec( $t_cmd, $t_lines, $p_rc );
	return implode( "\n", $t_lines );
}

/**
 * Run a git command that writes to the repository, retrying as the
 * System Operations account if the web server account cannot.
 *
 * @param string  $p_args Git arguments, already shell-escaped where needed.
 * @param integer $p_rc   Receives the exit code of the last attempt.
 * @return string Transcript of each attempt.
 */
function git_checkout_repair( string $p_args, &$p_rc = null ): string {
	$t_output = git_checkout_git( $p_args, $p_rc );
	$t_transcript = '$ git ' . $p_args . "\n" . $t_output;
	if( $p_rc === 0 ) {
		return $t_transcript;
	}

	$t_transcript .= "\n(exit " . $p_rc . ")\n\n";
	$t_sudo = system_ops_sudo_prefix() . '-n /usr/bin/git';
	$t_cmd = $t_sudo . ' -C ' . escapeshellarg( git_checkout_path() ) . ' ' . $p_args . ' 2>&1';
	exec( $t_cmd, $t_lines, $p_rc );
	return $t_transcript . '$ ' . $t_sudo . ' ' . $p_args . "\n" . implode( "\n", $t_lines )
		. "\n(exit " . $p_rc . ')';
}

/**
 * Split command output into non-empty lines.
 *
 * @param string $p_output
 * @return array
 */
function git_checkout_lines( string $p_output ): array {
	return array_values( array_filter( explode( "\n", $p_output ), 'strlen' ) );
}

/**
 * Mask credentials embedded in URLs (https://user:token@host).
 *
 * @param string $p_text
 * @return string
 */
function git_checkout_redact( string $p_text ): string {
	return preg_replace( '#(://)[^/@\s]+@#', '$1***@', $p_text );
}

/**
 * Collect the state of the application checkout.
 *
 * Content and permission changes are measured independently of the
 * repository's core.fileMode setting; "blocking" honours it, because that is
 * what git pull checks.
 *
 * @return array
 */
function git_checkout_status(): array {
	$t_status = array();
	$t_status['head'] = git_checkout_git( 'rev-parse --short=10 HEAD' );
	$t_status['branch'] = git_checkout_git( 'rev-parse --abbrev-ref HEAD' );
	$t_upstream = git_checkout_git( 'rev-parse --abbrev-ref ' . escapeshellarg( '@{u}' ), $t_rc );
	$t_status['upstream'] = $t_rc === 0 ? $t_upstream : '';
	$t_status['filemode'] = git_checkout_git( 'config --get core.filemode' );

	$t_status['blocking'] = git_checkout_lines( git_checkout_git( 'diff HEAD --name-only --diff-filter=M' ) );
	$t_status['content'] = git_checkout_lines( git_checkout_git( '-c core.fileMode=false diff HEAD --name-only --diff-filter=M' ) );
	$t_status['permission'] = array();
	foreach( git_checkout_lines( git_checkout_git( '-c core.fileMode=true diff HEAD --summary' ) ) as $t_line ) {
		if( preg_match( '/^ mode change \d+ => \d+ (.+)$/', $t_line, $t_match ) ) {
			$t_status['permission'][] = $t_match[1];
		}
	}
	$t_status['deleted'] = git_checkout_lines( git_checkout_git( 'diff HEAD --name-only --diff-filter=D' ) );
	$t_status['untracked'] = git_checkout_lines( git_checkout_git( 'ls-files --others --exclude-standard --directory' ) );
	$t_status['stashes'] = git_checkout_lines( git_checkout_git( 'stash list' ) );
	$t_status['local_commits'] = git_checkout_lines( git_checkout_git( 'log --oneline --branches --not --remotes' ) );

	return $t_status;
}

/**
 * Mount points under the web root and git store, from /proc/self/mountinfo.
 * Shows whether the checkout is part of a container image or a mounted volume.
 *
 * @return array
 */
function git_checkout_mounts(): array {
	$t_mounts = array();
	$t_info = @file( '/proc/self/mountinfo', FILE_IGNORE_NEW_LINES );
	foreach( $t_info ?: array() as $t_line ) {
		$t_fields = explode( ' ', $t_line );
		if( isset( $t_fields[4] ) && preg_match( '#^/var/(www|git|lib/php)#', $t_fields[4] ) ) {
			$t_mounts[] = $t_fields[4];
		}
	}
	return $t_mounts;
}

/**
 * Full plain-text report of the checkout, including the content patch.
 *
 * @return string
 */
function git_checkout_report(): string {
	$t_sections = array(
		'Generated' => date( 'c' ),
		'Checkout' => git_checkout_path(),
		'Web server account' => trim( (string)@shell_exec( 'id 2>&1' ) ),
		'git --version' => git_checkout_git( '--version' ),
		'git status --branch --porcelain' => git_checkout_git( 'status --branch --porcelain=v1' ),
		'git branch -a -vv' => git_checkout_git( 'branch -a -vv' ),
		'git remote -v' => git_checkout_redact( git_checkout_git( 'remote -v' ) ),
		'git config --local --list' => git_checkout_redact( git_checkout_git( 'config --local --list' ) ),
		'git stash list' => git_checkout_git( 'stash list' ),
		'Local commits not on any remote' => git_checkout_git( 'log --oneline --branches --not --remotes' ),
		'Untracked files' => git_checkout_git( 'ls-files --others --exclude-standard --directory' ),
		'Mount points' => implode( "\n", git_checkout_mounts() ),
		'sudo -n -l' => trim( (string)@shell_exec( 'sudo -n -l 2>&1' ) ),
		'Permission and deletion summary (core.fileMode=true)' => git_checkout_git( '-c core.fileMode=true diff HEAD --summary' ),
		'Content changes (core.fileMode=false)' => git_checkout_git( '-c core.fileMode=false diff HEAD --binary --diff-filter=M' ),
	);

	$t_report = '';
	foreach( $t_sections as $t_title => $t_body ) {
		$t_report .= '==== ' . $t_title . " ====\n" . $t_body . "\n\n";
	}
	return $t_report;
}

/**
 * Stop git reporting permission-only changes as modifications.
 *
 * @param integer $p_rc Receives the exit code.
 * @return string Transcript.
 */
function git_checkout_ignore_permissions( &$p_rc = null ): string {
	return git_checkout_repair( 'config core.fileMode false', $p_rc );
}

/**
 * Restore every file whose content differs from HEAD, after saving the
 * differences as a patch in the config directory (outside the web-served
 * paths, and a mounted volume in the Docker deployment).
 *
 * @param integer $p_rc Receives the exit code; non-zero if nothing was restored.
 * @return string Transcript.
 */
function git_checkout_restore_content( &$p_rc = null ): string {
	$t_files = git_checkout_lines( git_checkout_git( '-c core.fileMode=false diff HEAD --name-only --diff-filter=M' ) );
	if( empty( $t_files ) ) {
		$p_rc = 0;
		return 'No files with content changes; nothing to restore.';
	}

	$t_patch = git_checkout_git( '-c core.fileMode=false diff HEAD --binary --diff-filter=M', $t_rc );
	$t_patch_file = git_checkout_path() . '/config/git_local_changes_' . date( 'Ymd_His' ) . '.patch';
	if( $t_rc !== 0 || @file_put_contents( $t_patch_file, $t_patch . "\n" ) === false ) {
		$p_rc = 1;
		return 'Could not save the patch to ' . $t_patch_file . '; nothing was restored.';
	}

	$t_paths = implode( ' ', array_map( 'escapeshellarg', $t_files ) );
	return 'Saved ' . count( $t_files ) . ' changed file(s) as ' . $t_patch_file . "\n\n"
		. git_checkout_repair( 'checkout HEAD -- ' . $t_paths, $p_rc );
}

/**
 * Run a git command that writes to the repository as the System Operations
 * account (the account git pull runs as, so object and file ownership stay
 * consistent), falling back to the web server account when sudo refuses.
 *
 * @param string  $p_args Git arguments, already shell-escaped where needed.
 * @param integer $p_rc   Receives the exit code of the last attempt.
 * @return string Transcript of each attempt.
 */
function git_checkout_operator( string $p_args, &$p_rc = null ): string {
	$t_sudo = system_ops_sudo_prefix() . '-n /usr/bin/git';
	exec( $t_sudo . ' -C ' . escapeshellarg( git_checkout_path() ) . ' ' . $p_args . ' 2>&1', $t_lines, $p_rc );
	$t_output = implode( "\n", $t_lines );
	$t_transcript = '$ ' . $t_sudo . ' ' . $p_args . "\n" . $t_output;
	if( $p_rc === 0 || !preg_match( '/^(sudo:|Sorry, user)/', $t_output ) ) {
		return $t_transcript . ( $p_rc === 0 ? '' : "\n(exit " . $p_rc . ')' );
	}

	$t_output = git_checkout_git( $p_args, $p_rc );
	return $t_transcript . "\n\n" . '$ git ' . $p_args . "\n" . $t_output . ( $p_rc === 0 ? '' : "\n(exit " . $p_rc . ')' );
}

/**
 * What the web server account may run as the System Operations account:
 * 'self' (they are the same account), 'any' (every git command), 'pull'
 * (only the scoped pull rule) or 'none'.
 *
 * @return string
 */
function git_checkout_operator_access(): string {
	if( trim( (string)@shell_exec( 'id -un 2>/dev/null' ) ) === system_ops_run_as_user() ) {
		return 'self';
	}
	$t_rules = (string)@shell_exec( 'sudo -n -l 2>&1' );
	if( preg_match( '#/usr/bin/git\s*(,|$)#m', $t_rules ) ) {
		return 'any';
	}
	return strpos( $t_rules, '/usr/bin/git ' ) !== false ? 'pull' : 'none';
}

/**
 * Commit facts for a ref: sha, time, author, subject; null when it does not resolve.
 *
 * @param string $p_ref
 * @return array|null
 */
function git_checkout_commit( string $p_ref ) {
	$t_out = git_checkout_git( 'log -1 ' . escapeshellarg( '--format=%H%x09%ct%x09%an%x09%s' ) . ' '
		. escapeshellarg( $p_ref ) . ' --', $t_rc );
	$t_f = explode( "\t", $t_out, 4 );
	return ( $t_rc === 0 && count( $t_f ) === 4 )
		? array( 'sha' => $t_f[0], 'time' => (int)$t_f[1], 'author' => $t_f[2], 'subject' => $t_f[3] )
		: null;
}

/**
 * Number of upgrade steps in a ref's admin/schema.php, i.e. the
 * database_version a fresh install of that ref records; null when the ref has
 * no Doctis flat schema.
 *
 * @param string $p_ref
 * @return integer|null
 */
function git_checkout_schema_version( string $p_ref ) {
	$t_schema = git_checkout_git( 'show ' . escapeshellarg( $p_ref . ':admin/schema.php' ), $t_rc );
	$t_steps = $t_rc === 0 ? preg_match_all( '/^\$g_upgrade\[\$t_idx\+\+\]/m', $t_schema ) : 0;
	return $t_steps > 0 ? $t_steps - 1 : null;
}

/**
 * Whether a ref contains a file.
 *
 * @param string $p_ref
 * @param string $p_path
 * @return boolean
 */
function git_checkout_ref_has( string $p_ref, string $p_path ): bool {
	git_checkout_git( 'cat-file -e ' . escapeshellarg( $p_ref . ':' . $p_path ), $t_rc );
	return $t_rc === 0;
}

/**
 * Local branches and origin's remote-tracking branches, merged by name.
 *
 * Each entry: name, current (bool), local and remote (commit or null), ref
 * (the local branch if present, else origin's), upstream, track (e.g.
 * "[behind 2]"), ahead and behind (commits relative to HEAD), schema
 * (database_version the branch expects, or null), and switch: 'free' (the
 * branch has this branch switcher), 'one-way' (it can only git pull) or
 * 'blocked' (it cannot update itself).
 *
 * @return array name => entry, current branch first.
 */
function git_checkout_branches(): array {
	$t_format = escapeshellarg( '--format=%(refname)%09%(upstream:short)%09%(upstream:track)' );
	$t_current = git_checkout_git( 'symbolic-ref --quiet --short HEAD' );
	$t_branches = array();
	foreach( git_checkout_lines( git_checkout_git( 'for-each-ref ' . $t_format . ' refs/heads refs/remotes/origin' ) ) as $t_line ) {
		list( $t_refname, $t_upstream, $t_track ) = array_pad( explode( "\t", $t_line ), 3, '' );
		if( strpos( $t_refname, 'refs/heads/' ) === 0 ) {
			$t_name = substr( $t_refname, 11 );
			$t_kind = 'local';
		} else {
			$t_name = substr( $t_refname, 20 );
			$t_kind = 'remote';
			if( $t_name === 'HEAD' ) {
				continue;
			}
		}
		$t_branches[$t_name] = ( $t_branches[$t_name] ?? array( 'name' => $t_name, 'local' => null, 'remote' => null,
			'upstream' => '', 'track' => '' ) );
		$t_branches[$t_name][$t_kind] = git_checkout_commit( $t_refname );
		if( $t_kind === 'local' ) {
			$t_branches[$t_name]['upstream'] = $t_upstream;
			$t_branches[$t_name]['track'] = $t_track;
		}
	}

	foreach( $t_branches as $t_name => &$t_branch ) {
		$t_branch['current'] = $t_name === $t_current;
		$t_branch['ref'] = $t_branch['local'] ? 'refs/heads/' . $t_name : 'refs/remotes/origin/' . $t_name;
		$t_counts = explode( "\t", git_checkout_git( 'rev-list --left-right --count '
			. escapeshellarg( 'HEAD...' . $t_branch['ref'] ) ) );
		$t_branch['behind'] = (int)( $t_counts[0] ?? 0 );
		$t_branch['ahead'] = (int)( $t_counts[1] ?? 0 );
		$t_branch['schema'] = git_checkout_schema_version( $t_branch['ref'] );
		if( git_checkout_ref_has( $t_branch['ref'], 'manage_git_branch_page.php' ) ) {
			$t_branch['switch'] = 'free';
		} else if( git_checkout_ref_has( $t_branch['ref'], 'manage_git_pull_action.php' ) ) {
			$t_branch['switch'] = 'one-way';
		} else {
			$t_branch['switch'] = 'blocked';
		}
	}
	unset( $t_branch );

	uasort( $t_branches, function( $p_a, $p_b ) {
		return array( !$p_a['current'], $p_a['name'] ) <=> array( !$p_b['current'], $p_b['name'] );
	} );
	return $t_branches;
}

/**
 * When the checkout last fetched from origin (FETCH_HEAD time), or 0.
 *
 * @return integer
 */
function git_checkout_last_fetch(): int {
	$t_path = git_checkout_git( 'rev-parse --git-path FETCH_HEAD' );
	if( $t_path !== '' && $t_path[0] !== '/' ) {
		$t_path = git_checkout_path() . '/' . $t_path;
	}
	return ( $t_path !== '' && is_file( $t_path ) ) ? (int)filemtime( $t_path ) : 0;
}

/**
 * Update origin's remote-tracking branches (git fetch --prune origin).
 *
 * @param integer $p_rc Receives the exit code.
 * @return string Transcript.
 */
function git_checkout_fetch( &$p_rc = null ): string {
	return git_checkout_operator( 'fetch --prune origin', $p_rc );
}

/**
 * Switch the application checkout to a branch: the local branch if it
 * exists, otherwise a new local branch tracking origin's, so that git pull
 * then follows it.  Never discards local changes.
 *
 * @param array   $p_branch Entry from git_checkout_branches().
 * @param integer $p_rc     Receives the exit code.
 * @return string Transcript.
 */
function git_checkout_switch( array $p_branch, &$p_rc = null ): string {
	$t_args = $p_branch['local']
		? 'switch ' . escapeshellarg( $p_branch['name'] )
		: 'switch --track ' . escapeshellarg( 'origin/' . $p_branch['name'] );
	return git_checkout_operator( $t_args, $p_rc );
}
