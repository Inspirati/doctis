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

/**
 * The file PHP writes its error log to, as this web server runs PHP.
 *
 * @return string The path, or '' when PHP logs to the web server, syslog or a
 *                stream rather than to a file a page can read.
 */
function system_ops_php_error_log(): string {
	$t_log = (string)ini_get( 'error_log' );
	if( $t_log === '' || $t_log === 'syslog' || preg_match( '#^/(proc|dev)/#', $t_log ) ) {
		return '';
	}
	return $t_log;
}

/**
 * Read the end of a log file without loading all of it.
 *
 * @param string  $p_path      The log file.
 * @param integer $p_max_bytes Read at most this many bytes from the end.
 * @return array|false Its lines, oldest first, or false if it cannot be read.
 *                     A line cut by the byte limit is left out.
 */
function system_ops_log_tail( string $p_path, int $p_max_bytes ) {
	$t_handle = @fopen( $p_path, 'rb' );
	if( $t_handle === false ) {
		return false;
	}
	$t_start = max( 0, (int)fstat( $t_handle )['size'] - $p_max_bytes );
	if( $t_start === 0 ) {
		$t_text = (string)stream_get_contents( $t_handle );
	} else {
		# Read from the byte before the limit: if it ends a line, the first
		# line read is whole.  Otherwise drop the part line.
		fseek( $t_handle, $t_start - 1 );
		$t_text = (string)stream_get_contents( $t_handle );
		$t_cut = strpos( $t_text, "\n" );
		$t_text = $t_cut === false ? '' : substr( $t_text, $t_cut + 1 );
	}
	fclose( $t_handle );
	$t_text = rtrim( str_replace( "\r\n", "\n", $t_text ), "\n" );
	return $t_text === '' ? array() : explode( "\n", $t_text );
}

/**
 * Group PHP error log lines into entries, newest first.
 *
 * An entry starts with a "[16-Oct-2026 14:31:14 UTC]" stamp.  Lines without
 * one, such as MantisBT's stack traces, belong to the entry above them.
 * Blank lines are dropped.
 *
 * @param array $p_lines Log lines, oldest first.
 * @return array Entries, newest first, each a string of one or more lines.
 */
function system_ops_log_entries( array $p_lines ): array {
	$t_entries = array();
	foreach( $p_lines as $t_line ) {
		if( trim( $t_line ) === '' ) {
			continue;
		}
		if( $t_entries && !preg_match( '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}/', $t_line ) ) {
			$t_entries[count( $t_entries ) - 1] .= "\n" . $t_line;
		} else {
			$t_entries[] = $t_line;
		}
	}
	return array_reverse( $t_entries );
}
