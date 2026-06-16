<?php
# Doctis — AI Assistant AJAX endpoint
#
# Receives a JSON POST from ai_assist_page.php, calls the Anthropic Messages
# API, and returns the assistant's reply as JSON.
#
# Request body (JSON):
#   mode    string   'help' | 'meeting' | 'sop' | 'other'
#   system  string   System prompt constructed by the caller
#   history array    [{role: 'user'|'assistant', content: string}, ...]
#                    The last element is the new user message (already appended
#                    by the JS before sending).
#   token   string   CSRF form security token
#
# Response body (JSON):
#   reply   string   Assistant reply text (on success)
#   error   string   Error message (on failure); null on success
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );

# ── Sanity checks ─────────────────────────────────────────────────────────

# Must be a POST request from our own page (XHR header set by JS).
if( $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) !== 'XMLHttpRequest' ) {
	http_response_code( 405 );
	exit;
}

# Must be authenticated.
auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );

# Must have an API key configured.
$t_api_key = config_get_global( 'anthropic_api_key' );
if( is_blank( $t_api_key ) ) {
	ai_assist_json_error( 'AI Assistant is not configured on this server.' );
}

# ── Parse request ─────────────────────────────────────────────────────────

$t_raw = file_get_contents( 'php://input' );
$t_input = json_decode( $t_raw, true );

if( !is_array( $t_input ) ) {
	ai_assist_json_error( 'Invalid request body.' );
}

# CSRF mitigation: this endpoint is authenticated + XHR-only (checked above).
# The X-Requested-With header cannot be set cross-origin by a browser, so
# authentication + XHR check is sufficient.  No traditional form token needed.

$t_mode    = $t_input['mode']    ?? 'help';
$t_system  = $t_input['system']  ?? '';
$t_history = $t_input['history'] ?? [];

# Basic validation
if( !is_array( $t_history ) || count( $t_history ) === 0 ) {
	ai_assist_json_error( 'No message supplied.' );
}

# Ensure the last message is from the user.
$t_last = end( $t_history );
if( !isset( $t_last['role'] ) || $t_last['role'] !== 'user' ) {
	ai_assist_json_error( 'Last history entry must be a user message.' );
}

# Sanitise history: only keep role + content; strip unknown keys.
$t_messages = [];
foreach( $t_history as $t_turn ) {
	$t_role    = $t_turn['role']    ?? '';
	$t_content = $t_turn['content'] ?? '';
	if( !in_array( $t_role, [ 'user', 'assistant' ], true ) ) continue;
	if( !is_string( $t_content ) || is_blank( $t_content ) ) continue;
	$t_messages[] = [ 'role' => $t_role, 'content' => $t_content ];
}

if( empty( $t_messages ) ) {
	ai_assist_json_error( 'No valid messages to send.' );
}

# ── Call Anthropic Messages API ───────────────────────────────────────────

$t_model      = config_get_global( 'ai_model' );
$t_max_tokens = 2048;

$t_payload = json_encode( [
	'model'      => $t_model,
	'max_tokens' => $t_max_tokens,
	'system'     => is_string( $t_system ) ? $t_system : '',
	'messages'   => $t_messages,
] );

$t_ch = curl_init( 'https://api.anthropic.com/v1/messages' );
curl_setopt_array( $t_ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => $t_payload,
	CURLOPT_HTTPHEADER     => [
		'Content-Type: application/json',
		'x-api-key: ' . $t_api_key,
		'anthropic-version: 2023-06-01',
		'User-Agent: Doctis/1.0',
	],
	CURLOPT_TIMEOUT        => 60,
	CURLOPT_CONNECTTIMEOUT => 10,
] );

$t_response = curl_exec( $t_ch );
$t_http_code = curl_getinfo( $t_ch, CURLINFO_HTTP_CODE );
$t_curl_error = curl_error( $t_ch );
curl_close( $t_ch );

# ── Handle cURL / network error ───────────────────────────────────────────

if( $t_response === false ) {
	error_log( 'ai_assist_api: cURL error: ' . $t_curl_error );
	ai_assist_json_error( 'Could not reach the AI service. Please try again.' );
}

# ── Parse Anthropic response ──────────────────────────────────────────────

$t_data = json_decode( $t_response, true );

if( $t_http_code !== 200 ) {
	# Anthropic error response: {"type":"error","error":{"type":"...","message":"..."}}
	$t_err_msg = $t_data['error']['message'] ?? 'API error (HTTP ' . $t_http_code . ').';
	error_log( 'ai_assist_api: Anthropic error ' . $t_http_code . ': ' . $t_err_msg );

	# Translate common error codes into user-friendly messages.
	switch( $t_http_code ) {
		case 401:
			ai_assist_json_error( 'AI service authentication failed. Check the API key in config.' );
			break;
		case 429:
			ai_assist_json_error( 'AI service rate limit reached. Please wait a moment and try again.' );
			break;
		case 529:
			ai_assist_json_error( 'The AI service is currently overloaded. Please try again shortly.' );
			break;
		default:
			ai_assist_json_error( $t_err_msg );
	}
}

# Extract the text content from the response.
$t_reply = $t_data['content'][0]['text'] ?? '';

if( is_blank( $t_reply ) ) {
	ai_assist_json_error( 'Empty response received from AI service.' );
}

# ── Return success response ───────────────────────────────────────────────

header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode( [
	'reply' => $t_reply,
	'error' => null,
	'usage' => [
		'input_tokens'  => $t_data['usage']['input_tokens']  ?? null,
		'output_tokens' => $t_data['usage']['output_tokens'] ?? null,
	],
] );
exit;


# ── Helper ────────────────────────────────────────────────────────────────

/**
 * Output a JSON error response and exit.
 *
 * @param string $p_message Human-readable error message.
 * @return never
 */
function ai_assist_json_error( string $p_message ): never {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( [ 'reply' => null, 'error' => $p_message ] );
	exit;
}
