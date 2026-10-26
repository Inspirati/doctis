<?php
# dwg_repo_clone_script.php
# Hand over a small script that clones a project's repository.
#
# This is the part of "clone it onto my machine" a browser can actually do.
# A page cannot run git on the visitor's computer and cannot pick a directory
# to clone into; the browser's own save dialog picks where the *script* goes,
# and the script asks where to clone when it is run.
#
# The token arrives in the POST body rather than a URL, so it stays out of
# server logs, browser history and Referer headers.

# Serve the script as a plain file, never an HTML error page inside it.
define( 'DISABLE_INLINE_ERROR_REPORTING', true );
define( 'COMPRESSION_DISABLED', true );

# core.php emits the response headers for a normal page load, including the
# CSP, and http_security_headers() cannot be called twice — the second call
# raises ERROR_GENERIC.  A page that serves a file sends its own headers, so
# it suppresses core's, as file_download.php does.
$g_bypass_headers = true;

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'api_token_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'http_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'user_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'dwg_repo_clone_script' );

$f_project_id = gpc_get_int( 'project_id' );
$f_token      = gpc_get_string( 'token', '' );
$f_os         = gpc_get_string( 'os', 'sh' ) === 'cmd' ? 'cmd' : 'sh';

project_ensure_exists( $f_project_id );

$t_repo_id = repository_id_for_project( $f_project_id );
if( $t_repo_id === 0 ) {
	error_parameters( $f_project_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

$t_repo_row   = repository_get_row( $t_repo_id );
$t_owner_proj = (int)$t_repo_row['owner_project_id'];
access_ensure_project_level(
	config_get( 'git_http_read_threshold', null, auth_get_current_user_id(), $t_owner_proj ),
	$t_owner_proj );

# Only a token this user actually holds may be embedded, so the script cannot
# be used to smuggle an arbitrary string out of the form.
if( $f_token === '' || (int)api_token_get_user( $f_token ) !== (int)auth_get_current_user_id() ) {
	trigger_error( ERROR_ACCESS_DENIED, ERROR );
}

$t_basename = repository_basename( $t_repo_id );
$t_username = user_get_username( auth_get_current_user_id() );
$t_url      = repository_clone_url( $t_basename, $t_username, $f_token );
$t_plain    = repository_clone_url( $t_basename );
$t_policy   = repository_push_policy( $t_repo_id );
$t_default  = preg_replace( '/-r\d+$/', '', $t_basename );

$t_notice = $t_policy === DWG_PUSH_POLICY_SOURCE
	? 'This is a source repository: rebasing, force-pushing and deleting branches all work.'
	: 'This is a document-control repository: you can commit and push, but force-pushing and'
		. ' deleting refs are refused, so an approved version can never be rewritten.';

if( $f_os === 'sh' ) {
	$t_name = 'clone-' . $t_basename . '.sh';
	$t_body = "#!/bin/sh\n"
		. "# Clone the Doctis repository " . $t_basename . "\n"
		. "# Generated for " . $t_username . " on " . date( 'Y-m-d H:i' ) . ".\n"
		. "#\n"
		. "# " . $t_notice . "\n"
		. "#\n"
		. "# This file contains an API token that acts as your Doctis password.\n"
		. "# Delete it once you have cloned, and keep it out of any repository.\n"
		. "\n"
		. "set -e\n"
		. "\n"
		. "DIR=\"\$1\"\n"
		. "if [ -z \"\$DIR\" ]; then\n"
		. "\tprintf 'Clone into which directory? [%s] ' '" . $t_default . "'\n"
		. "\tread -r DIR\n"
		. "\t[ -n \"\$DIR\" ] || DIR='" . $t_default . "'\n"
		. "fi\n"
		. "\n"
		. "git clone " . escapeshellarg( $t_url ) . " \"\$DIR\"\n"
		. "\n"
		. "echo\n"
		. "echo \"Cloned into \$DIR\"\n"
		. "echo 'The token is now in .git/config. To be prompted for it instead, run:'\n"
		. "echo \"  git -C \\\"\$DIR\\\" remote set-url origin " . $t_plain . "\"\n";
} else {
	$t_name = 'clone-' . $t_basename . '.cmd';
	$t_body = "@echo off\r\n"
		. "REM Clone the Doctis repository " . $t_basename . "\r\n"
		. "REM Generated for " . $t_username . " on " . date( 'Y-m-d H:i' ) . ".\r\n"
		. "REM\r\n"
		. "REM " . $t_notice . "\r\n"
		. "REM\r\n"
		. "REM This file contains an API token that acts as your Doctis password.\r\n"
		. "REM Delete it once you have cloned, and keep it out of any repository.\r\n"
		. "\r\n"
		. "setlocal\r\n"
		. "set \"DIR=%~1\"\r\n"
		. "if \"%DIR%\"==\"\" set /p \"DIR=Clone into which directory? [" . $t_default . "] \"\r\n"
		. "if \"%DIR%\"==\"\" set \"DIR=" . $t_default . "\"\r\n"
		. "\r\n"
		. "git clone \"" . $t_url . "\" \"%DIR%\"\r\n"
		. "if errorlevel 1 goto :eof\r\n"
		. "\r\n"
		. "echo.\r\n"
		. "echo Cloned into %DIR%\r\n"
		. "echo The token is now in .git\\config. To be prompted for it instead, run:\r\n"
		. "echo   git -C \"%DIR%\" remote set-url origin " . $t_plain . "\r\n"
		. "endlocal\r\n";
}

while( @ob_end_clean() ) {
}
http_security_headers();
http_caching_headers( false );
header( 'Content-Type: text/plain; charset=utf-8' );
http_content_disposition_header( $t_name );
header( 'Content-Length: ' . strlen( $t_body ) );
header( 'X-Content-Type-Options: nosniff' );
echo $t_body;
