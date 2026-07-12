<?php
# Doctis - Document Issue Tracking System
#
# Remote Git access — Smart HTTP gateway library.
#
# Provides authenticated access to Doctis bare git repositories (under
# $g_git_storage_root, one per {repository} entity — see repository_api.php)
# by proxying to the git-http-backend CGI.  Callers authenticate with a Doctis
# API token presented as the HTTP Basic password; authorisation is by access
# level on the repository's owner project.
#
# Reads (git-upload-pack: clone/fetch) require $g_git_http_read_threshold on
# the project; writes (git-receive-pack: push) require
# $g_git_http_write_threshold.  Pushes advance the draft (HEAD) only — the
# On-Record version stays pinned to its recorded SHA until promoted inside
# Doctis.  A pre-receive hook in each bare repo rejects force-pushes, ref
# deletions, and client writes to refs/doctis/*.
#
# The entry point is git_http_handle_request(), called from git_http.php.

require_api( 'access_api.php' );
require_api( 'api_token_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'user_api.php' );

/**
 * Resolve a requested repository basename ("<slug>-r<id>") to a repository id.
 *
 * The immutable trailing "-r<id>" component is authoritative; the slug prefix
 * is cosmetic, so a URL bookmarked before a project rename keeps working.
 * Returns false when no trailing id is present or the repository does not
 * exist.
 *
 * @param string $p_basename  Repo name without ".git", e.g. "example-r1".
 * @return int|false
 */
function git_http_repo_to_repository_id( $p_basename ) {
	if( !preg_match( '/-r(\d+)$/', $p_basename, $t_m ) ) {
		return false;
	}
	$t_repository_id = (int)$t_m[1];
	if( $t_repository_id < 1 || repository_get_row( $t_repository_id ) === false ) {
		return false;
	}
	return $t_repository_id;
}

/**
 * Extract the presented API token from the request.
 *
 * Accepts the token as the HTTP Basic password (git's normal mechanism) or as a
 * bare/Bearer Authorization header (matching the REST convention).
 *
 * @return string  The plain token, or '' if none presented.
 */
function git_http_extract_token() {
	if( isset( $_SERVER['PHP_AUTH_PW'] ) && $_SERVER['PHP_AUTH_PW'] !== '' ) {
		return $_SERVER['PHP_AUTH_PW'];
	}

	# Fall back to a manually-parsed Authorization header (some SAPIs/proxies do
	# not populate PHP_AUTH_*).
	$t_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
	if( $t_header === '' ) {
		return '';
	}
	if( stripos( $t_header, 'Basic ' ) === 0 ) {
		$t_decoded = base64_decode( substr( $t_header, 6 ), true );
		if( $t_decoded !== false && strpos( $t_decoded, ':' ) !== false ) {
			list( , $t_pw ) = explode( ':', $t_decoded, 2 );
			return $t_pw;
		}
	}
	# Bare token or "Bearer <token>".
	if( stripos( $t_header, 'Bearer ' ) === 0 ) {
		return trim( substr( $t_header, 7 ) );
	}
	return trim( $t_header );
}

/**
 * Emit a minimal HTTP error response and terminate.
 *
 * @param int    $p_code
 * @param string $p_message
 * @return void
 */
function git_http_fail( $p_code, $p_message ) {
	http_response_code( $p_code );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo $p_message . "\n";
	exit;
}

/**
 * Emit a 401 challenge so the git client retries with Basic credentials, then
 * terminate.
 *
 * @return void
 */
function git_http_require_auth() {
	header( 'WWW-Authenticate: Basic realm="Doctis Git"' );
	git_http_fail( 401, 'Authentication required: use your Doctis username and an API token as the password.' );
}

/**
 * Handle one Smart-HTTP request end to end: validate, authenticate, authorise,
 * and proxy to git-http-backend.  Terminates the request.
 *
 * @return void
 */
function git_http_handle_request() {
	if( OFF == config_get_global( 'git_http_enabled' ) ) {
		git_http_fail( 404, 'Remote git access is not enabled.' );
	}

	# The repo path is everything after /git/, normally delivered as PATH_INFO.
	# Fall back to deriving it from REQUEST_URI when the SAPI/Apache config does
	# not populate PATH_INFO for the aliased script.
	$t_path_info = $_SERVER['PATH_INFO'] ?? '';
	if( $t_path_info === '' ) {
		$t_uri = strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
		if( preg_match( '#^/git(/.*)$#', $t_uri, $t_um ) ) {
			$t_path_info = $t_um[1];
		}
	}

	# Allowlist exactly the smart-HTTP endpoints; this also blocks path traversal
	# and dumb-HTTP file access.  Repo segment: <slug>-r<repository_id>.git
	if( !preg_match(
			'#^/([a-z0-9][a-z0-9\-]*\.git)/(info/refs|git-upload-pack|git-receive-pack)$#',
			$t_path_info, $t_m ) ) {
		git_http_fail( 403, 'Unsupported git request.' );
	}
	$t_repo    = $t_m[1];                       # e.g. "example-r1.git"
	$t_endpoint= $t_m[2];
	$t_basename= substr( $t_repo, 0, -4 );      # strip ".git"

	# Determine the git service and whether it is a write operation.
	if( $t_endpoint === 'info/refs' ) {
		$t_service = $_GET['service'] ?? '';
	} else {
		$t_service = $t_endpoint;
	}
	$t_is_write = ( $t_service === 'git-receive-pack' );

	# --- Authenticate via API token ---
	$t_token = git_http_extract_token();
	$t_user_id = ( $t_token === '' ) ? false : api_token_get_user( $t_token );
	if( $t_user_id === false ) {
		git_http_require_auth();
	}

	# --- Authorise: map repo name -> repository -> owner project, check
	# project-level access.  The clone/push boundary is the repository; access
	# is authorised against the project that owns it. ---
	$t_repository_id = git_http_repo_to_repository_id( $t_basename );
	if( $t_repository_id === false ) {
		git_http_fail( 404, 'Repository not found.' );
	}

	$t_repository = repository_get_row( $t_repository_id );
	$t_project_id = (int)$t_repository['owner_project_id'];
	if( $t_project_id < 1 || !project_exists( $t_project_id ) ) {
		git_http_fail( 404, 'Repository not found.' );
	}

	# Canonical on-disk repo name from the stored slug — tolerates a stale
	# slug in a URL bookmarked before a project rename.
	$t_canonical = repository_basename( $t_repository_id );
	if( !is_dir( config_get_global( 'git_storage_root' ) . '/' . $t_canonical . '.git' ) ) {
		git_http_fail( 404, 'Repository not found.' );
	}

	$t_threshold_key = $t_is_write ? 'git_http_write_threshold' : 'git_http_read_threshold';
	$t_threshold = config_get( $t_threshold_key, null, $t_user_id, $t_project_id );
	if( !access_has_project_level( $t_threshold, $t_project_id, $t_user_id ) ) {
		git_http_fail( 403, $t_is_write
			? 'You do not have push access to this project repository.'
			: 'You do not have access to this project repository.' );
	}

	# --- Proxy to git-http-backend (canonical repo name) ---
	git_http_proxy_backend( '/' . $t_canonical . '.git/' . $t_endpoint, user_get_username( $t_user_id ) );
}

/**
 * Proxy the current request to git-http-backend and stream the response.
 * Terminates the request.
 *
 * @param string $p_path_info  Repo-relative PATH_INFO, e.g. "/example.git/info/refs".
 * @param string $p_username   Authenticated username (for REMOTE_USER / logging).
 * @return void
 */
function git_http_proxy_backend( $p_path_info, $p_username ) {
	$t_backend = config_get_global( 'git_http_backend' );
	if( !is_executable( $t_backend ) ) {
		git_http_fail( 500, 'git-http-backend is not available.' );
	}

	$t_env = array(
		'GIT_PROJECT_ROOT'    => config_get_global( 'git_storage_root' ),
		'GIT_HTTP_EXPORT_ALL' => '1',
		'PATH_INFO'           => $p_path_info,
		'REQUEST_METHOD'      => $_SERVER['REQUEST_METHOD'] ?? 'GET',
		'QUERY_STRING'        => $_SERVER['QUERY_STRING'] ?? '',
		'CONTENT_TYPE'        => $_SERVER['CONTENT_TYPE'] ?? '',
		'REMOTE_USER'         => $p_username,
		'REMOTE_ADDR'         => $_SERVER['REMOTE_ADDR'] ?? '',
		'HOME'                => '/var/www',
		'PATH'                => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/usr/lib/git-core',
		# Pass through bits git-http-backend reads from the environment.
		'HTTP_CONTENT_ENCODING' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
		'GIT_PROTOCOL'          => $_SERVER['HTTP_GIT_PROTOCOL'] ?? '',
	);

	$t_desc = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$t_proc = proc_open( $t_backend, $t_desc, $t_pipes, null, $t_env );
	if( !is_resource( $t_proc ) ) {
		git_http_fail( 500, 'Unable to start git-http-backend.' );
	}

	# Feed the request body (git-upload-pack POST payload) to the backend.
	$t_input = fopen( 'php://input', 'rb' );
	if( $t_input ) {
		stream_copy_to_stream( $t_input, $t_pipes[0] );
		fclose( $t_input );
	}
	fclose( $t_pipes[0] );

	$t_stdout = stream_get_contents( $t_pipes[1] );
	fclose( $t_pipes[1] );
	$t_stderr = stream_get_contents( $t_pipes[2] );
	fclose( $t_pipes[2] );
	proc_close( $t_proc );

	# Discard any buffering so binary output is not mangled.
	while( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	# Split CGI headers from body (CRLF CRLF, or LF LF as a fallback).
	$t_sep_len = 4;
	$t_split = strpos( $t_stdout, "\r\n\r\n" );
	if( $t_split === false ) {
		$t_split = strpos( $t_stdout, "\n\n" );
		$t_sep_len = 2;
	}
	if( $t_split === false ) {
		git_http_fail( 500, 'git-http-backend produced no response. ' . $t_stderr );
	}

	$t_header_block = substr( $t_stdout, 0, $t_split );
	$t_body         = substr( $t_stdout, $t_split + $t_sep_len );

	foreach( preg_split( '/\r\n|\n/', $t_header_block ) as $t_line ) {
		if( $t_line === '' ) {
			continue;
		}
		if( stripos( $t_line, 'Status:' ) === 0 ) {
			$t_status = trim( substr( $t_line, 7 ) );
			http_response_code( (int)$t_status );
		} else {
			header( $t_line );
		}
	}

	echo $t_body;
	exit;
}
