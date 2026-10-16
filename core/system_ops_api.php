<?php
# Doctis - Document Issue Tracking System
#
# System Operations helpers.
#
# The admin-only "System Operations" pages (manage_*_action.php:
# git pull / DB rebuild / sample-data load / config write / DB backup) run
# privileged shell commands as a dedicated OS account via "sudo -u".  That
# account is configured by $g_updater_run_as_user (set by the installer to the
# account performing the install) and must own the application working tree,
# hold the git-remote / database credentials, and have matching NOPASSWD rules
# in /etc/sudoers.d/doctis-web.
#
# See doc/doctis-git-server-setup.txt STEP 9 and doc/UPDATER-PLAN.md.

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'utility_api.php' );

/**
 * Return the configured OS account that System Operations run as via sudo.
 *
 * Aborts with a clear error when unset, rather than letting a command run as
 * the wrong user.
 *
 * @return string The configured account name.
 */
function system_ops_run_as_user(): string {
	$t_user = config_get_global( 'updater_run_as_user' );
	if( is_blank( $t_user ) ) {
		error_parameters(
			'System Operations are not configured: set $g_updater_run_as_user '
			. 'in config_inc.php to the OS account that owns the application '
			. 'and holds the relevant sudoers rules.'
		);
		trigger_error( ERROR_GENERIC, ERROR );
	}
	return $t_user;
}

/**
 * Return a shell-safe "sudo -u <user> " command prefix for System Operations.
 *
 * @return string  e.g. "sudo -u 'hcr' "
 */
function system_ops_sudo_prefix(): string {
	return 'sudo -u ' . escapeshellarg( system_ops_run_as_user() ) . ' ';
}

/**
 * Check a candidate config_inc.php before it replaces the real one.
 *
 * The content is parsed here, then loaded in a separate PHP process by
 * admin/tools/doctis-config-check.php the way core.php loads the real file,
 * with this request's server variables as its environment (configs may read
 * them).  Errors block the upload: syntax errors, fatal errors or exceptions
 * while it runs, output, missing database settings or crypto master salt,
 * and database credentials that do not connect.  Warnings do not block,
 * including settings that the current file sets and the candidate drops.
 *
 * @param string $p_content Candidate file content.
 * @param string $p_current Current file content ('' to skip the comparison).
 * @return array errors => list of strings, warnings => list of strings.
 */
function system_ops_config_check( string $p_content, string $p_current = '' ): array {
	$t_result = array( 'errors' => array(), 'warnings' => array() );

	try {
		token_get_all( $p_content, TOKEN_PARSE );
	} catch( ParseError $e ) {
		$t_result['errors'][] = 'Syntax error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')';
		return $t_result;
	}

	# Settings the current file sets that the candidate no longer does.
	if( $p_current !== '' ) {
		$t_pattern = '/^\s*\$g_([A-Za-z0-9_]+)\s*=/m';
		preg_match_all( $t_pattern, $p_current, $t_before );
		preg_match_all( $t_pattern, $p_content, $t_after );
		$t_dropped = array_diff( array_unique( $t_before[1] ), $t_after[1] );
		if( $t_dropped ) {
			$t_result['warnings'][] = 'No longer set (the default applies): $g_' . implode( ', $g_', $t_dropped ) . '.';
		}
	}

	# Load it in a separate PHP process (the CLI binary, not PHP-FPM).
	$t_php = PHP_BINDIR . '/php';
	if( !is_executable( $t_php ) ) {
		$t_result['errors'][] = 'The PHP command-line binary was not found at ' . $t_php
			. ', so the file cannot be test-loaded.';
		return $t_result;
	}
	$t_file = tempnam( sys_get_temp_dir(), 'doctis-config-check-' );
	file_put_contents( $t_file, $p_content );
	$t_env = array();
	foreach( $_SERVER as $t_name => $t_value ) {
		if( is_string( $t_value ) && preg_match( '/^[A-Z][A-Z0-9_]*$/', $t_name ) ) {
			$t_env[$t_name] = $t_value;
		}
	}
	try {
		$t_process = proc_open(
			array( $t_php, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
				dirname( __DIR__ ) . '/admin/tools/doctis-config-check.php', $t_file ),
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$t_pipes, sys_get_temp_dir(), $t_env );
		if( !is_resource( $t_process ) ) {
			$t_result['errors'][] = 'The configuration check could not be started.';
			return $t_result;
		}
		$t_stdout = stream_get_contents( $t_pipes[1] );
		$t_stderr = stream_get_contents( $t_pipes[2] );
		fclose( $t_pipes[1] );
		fclose( $t_pipes[2] );
		proc_close( $t_process );
	} finally {
		@unlink( $t_file );
	}

	if( !preg_match( '/^DOCTIS-CONFIG-CHECK (\{.*\})$/m', $t_stdout, $t_match )
	 || !is_array( $t_check = json_decode( $t_match[1], true ) ) ) {
		# A fatal error stopped the check: report what PHP printed.
		$t_message = trim( preg_replace( '/\s+/', ' ', str_replace( $t_file, 'config_inc.php', $t_stderr . ' ' . $t_stdout ) ) );
		$t_result['errors'][] = 'The file stops PHP when loaded: ' . ( $t_message !== '' ? mb_substr( $t_message, 0, 400 ) : 'no details' );
		return $t_result;
	}
	$t_result['errors'] = array_merge( $t_result['errors'], (array)$t_check['errors'] );
	$t_result['warnings'] = array_merge( $t_result['warnings'], (array)$t_check['warnings'] );
	return $t_result;
}
