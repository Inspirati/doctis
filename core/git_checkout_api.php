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
