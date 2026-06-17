<?php
# Doctis — AI Assistant AJAX endpoint
#
# Receives a JSON POST from ai_assist_page.php and performs one of three
# actions, selected by the `action` field in the request body:
#
#   action: 'chat'    — send a user message; calls Anthropic API; saves session
#   action: 'load'    — return the stored conversation history for this user+mode
#   action: 'clear'   — delete the stored session for this user+mode
#
# Request body (JSON):
#   action  string   'chat' | 'load' | 'clear'
#   mode    string   'help' | 'meeting' | 'sop' | 'other'
#   system  string   System prompt (chat action only)
#   history array    Full message history (chat action only)
#
# Response body (JSON):
#   reply   string|null   Assistant reply (chat action on success)
#   history array|null    Stored history (load action on success)
#   error   string|null   Error message on failure; null on success
#   usage   object|null   {input_tokens, output_tokens} (chat action)
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

# ── Parse request ─────────────────────────────────────────────────────────

$t_raw   = file_get_contents( 'php://input' );
$t_input = json_decode( $t_raw, true );

if( !is_array( $t_input ) ) {
	ai_assist_json_error( 'Invalid request body.' );
}

$t_action = $t_input['action'] ?? 'chat';
$t_mode   = $t_input['mode']   ?? 'help';

if( !in_array( $t_action, [ 'chat', 'load', 'clear' ], true ) ) {
	ai_assist_json_error( 'Unknown action.' );
}
if( !in_array( $t_mode, [ 'help', 'meeting', 'sop', 'other' ], true ) ) {
	ai_assist_json_error( 'Unknown mode.' );
}

$t_user_id = auth_get_current_user_id();

# ── Dispatch ──────────────────────────────────────────────────────────────

if( $t_action === 'load' ) {
	ai_assist_action_load( $t_user_id, $t_mode );
}

if( $t_action === 'clear' ) {
	ai_assist_action_clear( $t_user_id, $t_mode );
}

# action === 'chat' — fall through to chat handling below

# ── Must have an API key configured ───────────────────────────────────────

$t_api_key = config_get_global( 'anthropic_api_key' );
if( is_blank( $t_api_key ) ) {
	ai_assist_json_error( 'AI Assistant is not configured on this server.' );
}

# ── Validate chat-specific fields ─────────────────────────────────────────

$t_system  = $t_input['system']  ?? '';
$t_history = $t_input['history'] ?? [];

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

$t_response   = curl_exec( $t_ch );
$t_http_code  = curl_getinfo( $t_ch, CURLINFO_HTTP_CODE );
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
	$t_err_msg = $t_data['error']['message'] ?? 'API error (HTTP ' . $t_http_code . ').';
	error_log( 'ai_assist_api: Anthropic error ' . $t_http_code . ': ' . $t_err_msg );

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

$t_reply = $t_data['content'][0]['text'] ?? '';

if( is_blank( $t_reply ) ) {
	ai_assist_json_error( 'Empty response received from AI service.' );
}

# ── Persist the updated history ───────────────────────────────────────────
# Append the assistant reply to form the full history to save.

$t_messages[] = [ 'role' => 'assistant', 'content' => $t_reply ];
ai_assist_session_save( $t_user_id, $t_mode, $t_messages );

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


# ── Action handlers ───────────────────────────────────────────────────────

/**
 * Load and return the stored conversation history for user+mode.
 * Returns {history: [...]} on success; {history: null} if no session exists.
 * Exits.
 */
function ai_assist_action_load( int $p_user_id, string $p_mode ): never {
	$t_table  = db_get_table( 'ai_sessions' );
	$t_result = db_query(
		'SELECT history FROM ' . $t_table .
		' WHERE user_id = ' . db_param() . ' AND mode = ' . db_param() .
		' ORDER BY updated DESC LIMIT 1',
		[ $p_user_id, $p_mode ]
	);

	$t_history = null;
	if( db_num_rows( $t_result ) > 0 ) {
		$t_row     = db_fetch_array( $t_result );
		$t_history = json_decode( $t_row['history'], true );
		if( !is_array( $t_history ) ) {
			$t_history = null;
		}
	}

	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( [ 'history' => $t_history, 'error' => null ] );
	exit;
}

/**
 * Delete any stored session for user+mode.
 * Returns {ok: true} and exits.
 */
function ai_assist_action_clear( int $p_user_id, string $p_mode ): never {
	$t_table = db_get_table( 'ai_sessions' );
	db_query(
		'DELETE FROM ' . $t_table .
		' WHERE user_id = ' . db_param() . ' AND mode = ' . db_param(),
		[ $p_user_id, $p_mode ]
	);

	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( [ 'ok' => true, 'error' => null ] );
	exit;
}

/**
 * Upsert the conversation history for user+mode.
 * One row per user per mode; updates existing row or inserts a new one.
 */
function ai_assist_session_save( int $p_user_id, string $p_mode, array $p_messages ): void {
	$t_table   = db_get_table( 'ai_sessions' );
	$t_history = json_encode( $p_messages );
	$t_now     = db_now();

	$t_check = db_query(
		'SELECT id FROM ' . $t_table .
		' WHERE user_id = ' . db_param() . ' AND mode = ' . db_param(),
		[ $p_user_id, $p_mode ]
	);

	if( db_num_rows( $t_check ) > 0 ) {
		$t_row = db_fetch_array( $t_check );
		db_query(
			'UPDATE ' . $t_table .
			' SET history = ' . db_param() . ', updated = ' . db_param() .
			' WHERE id = ' . db_param(),
			[ $t_history, $t_now, (int)$t_row['id'] ]
		);
	} else {
		db_query(
			'INSERT INTO ' . $t_table .
			' (user_id, mode, created, updated, history)' .
			' VALUES (' . db_param() . ', ' . db_param() . ', ' .
			db_param() . ', ' . db_param() . ', ' . db_param() . ')',
			[ $p_user_id, $p_mode, $t_now, $t_now, $t_history ]
		);
	}
}

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
