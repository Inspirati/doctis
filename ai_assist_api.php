<?php
# Doctis — AI Assistant AJAX endpoint
#
# Receives a JSON POST from ai_assist_page.php and performs one of three
# actions, selected by the `action` field in the request body:
#
#   action: 'chat'    — send a user message; calls Anthropic API; saves session
#   action: 'load'    — return the stored conversation history for this user+mode
#   action: 'clear'   — archive the current conversation and start a fresh one
#
# Mode-specific logic (system prompt builders, document processing) lives in:
#   ai_assist_help_api.php    — Help mode (ai_assist_help_system_prompt)
#   ai_assist_meeting_api.php — Meeting mode (ai_assist_meeting_system_prompt,
#                               ai_assist_process_meeting_document, git helpers)
#
# Request body (JSON):
#   action  string   'chat' | 'load' | 'clear'
#   mode    string   'help' | 'meeting' | 'sop' | 'other'
#   history array    Full message history (chat action only)
#   meeting_id int   Meeting whose minutes are being written (meeting mode, optional)
#   series_of  int   Meeting whose follow-up is being planned (meeting mode, optional)
#   conversation_id int Selected conversation returned by load/clear (optional for old clients)
#
# Response body (JSON):
#   reply          string|null    Assistant reply (chat action on success)
#   history        array|null     Stored history (load action on success)
#   error          string|null    Error message on failure; null on success
#   usage          object|null    {input_tokens, output_tokens} (chat action)
#   saved_document object|null    Set when a meeting document was saved (meeting mode)
#   knowledge_entry object|null   Set when the user's knowledge was saved (help/meeting)
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'ai_session_api.php' );
require_api( 'user_api.php' );
require_once( 'ai_assist_anthropic_api.php' );
require_once( 'ai_assist_help_api.php' );
require_once( 'ai_assist_meeting_api.php' );
require_once( 'ai_assist_knowledge_api.php' );

# ── Sanity checks ─────────────────────────────────────────────────────────────

# Must be a POST request from our own page (XHR header set by JS).
if( $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) !== 'XMLHttpRequest' ) {
	http_response_code( 405 );
	exit;
}

# Must be authenticated.
auth_ensure_user_authenticated();
access_ensure_global_level( config_get_global( 'ai_assist_threshold' ) );

# ── Parse request ─────────────────────────────────────────────────────────────

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

# Resolve context on the server, including permission checks.
$t_focus = null;
$t_series = null;
$t_context = '';
if( $t_mode === 'meeting' ) {
	$t_meeting_id = (int)( $t_input['meeting_id'] ?? 0 );
	$t_series_id = (int)( $t_input['series_of'] ?? 0 );
	$t_focus = ai_assist_meeting_focus( $t_user_id, $t_meeting_id );
	$t_series = ai_assist_meeting_series_base( $t_user_id, $t_series_id );
	if( ( $t_meeting_id > 0 && $t_focus === null ) || ( $t_series_id > 0 && $t_series === null ) ) {
		ai_assist_json_error( 'This meeting conversation is unavailable.' );
	}
	$t_context = $t_focus !== null ? $t_focus['doc_ref'] : ( $t_series !== null ? 'series:' . $t_series['id'] : '' );
}
try {
	$t_conversation = ai_session_current( $t_user_id, $t_mode, $t_context, (int)( $t_input['conversation_id'] ?? 0 ) );
	if( $t_action === 'clear' ) {
		$t_conversation = ai_session_archive( $t_conversation );
	}
} catch( Throwable $t_error ) {
	ai_assist_json_error( $t_error->getMessage() );
}
if( $t_action === 'load' || $t_action === 'clear' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo ai_session_json( array(
		'conversation_id' => (int)$t_conversation['id'],
		'history' => json_decode( $t_conversation['history'], true ),
		'doc_id' => $t_conversation['doc_id'],
		'dwg_id' => (int)$t_conversation['dwg_id'] ?: null,
		'ok' => true,
		'error' => null,
	) );
	exit;
}

# ── Validate chat-specific fields ─────────────────────────────────────────────

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

# Record the attempt before prompt construction, the external call, or document writes.
$t_request_id = 0;
$t_request_finished = false;
$t_evidence = array();
try {
	$t_request_id = ai_session_request_begin( $t_conversation, $t_messages );
	# Preserve an abrupt PHP failure too. Hard process termination leaves a pending row.
	register_shutdown_function( function() use ( &$t_request_finished, &$t_evidence, $t_request_id ) {
		if( !$t_request_finished ) {
			$t_last_error = error_get_last();
			$t_evidence['error'] = $t_last_error['message'] ?? 'Request ended before completion.';
			ai_session_request_record( $t_request_id, 'interrupted', $t_evidence, true );
		}
	} );
	$t_model = config_get_global( 'ai_model' );
	$t_max_tokens = $t_mode === 'meeting' ? 8192 : 2048;
	$t_system = '';
	$t_candidates = array();
	if( $t_mode === 'meeting' ) {
		# Capture exactly the roster used to build this request's prompt.
		$t_candidates = ai_assist_get_meeting_candidates();
		$t_system = ai_assist_meeting_system_prompt( $t_user_id, $t_focus, $t_series, $t_candidates );
	} else if( $t_mode === 'help' ) {
		$t_system = ai_assist_help_system_prompt( $t_user_id );
	}
	$t_hashes = array();
	foreach( array( 'ai_assist_api.php', 'ai_assist_meeting_api.php', 'ai_assist_anthropic_api.php', 'core/ai_session_api.php' ) as $t_source ) {
		$t_hashes[$t_source] = hash_file( 'sha256', __DIR__ . '/' . $t_source );
	}
	ai_session_request_prepare( $t_request_id, array(
		'messages' => $t_messages,
		'system' => $t_system,
		'candidates' => array_values( array_map( function( $p_candidate ) {
			# Keep the prompt's roster fields, not unused contact addresses.
			return array_intersect_key( $p_candidate, array_flip( array( 'id', 'username', 'realname', 'position_title', 'company', 'department', 'meeting_invite' ) ) );
		}, $t_candidates ) ),
		'model' => $t_model,
		'max_tokens' => $t_max_tokens,
		'meeting_id' => $t_focus['id'] ?? 0,
		'series_of' => $t_series['id'] ?? 0,
		'php_version' => PHP_VERSION,
		'application_version' => MANTIS_VERSION,
		'source_sha256' => $t_hashes,
	) );
	$t_answer = ai_assist_anthropic_request( $t_system, $t_messages, $t_max_tokens );
	$t_evidence = array( 'provider' => $t_answer['diagnostics'] ?? array(), 'raw_reply' => $t_answer['reply'], 'error' => $t_answer['error'] );
	ai_session_request_record( $t_request_id, 'processing', $t_evidence );
	if( $t_answer['error'] !== null ) {
		throw new RuntimeException( $t_answer['error'] );
	}
	ai_session_request_renew( (int)$t_conversation['id'], $t_request_id );
	$t_reply = $t_answer['reply'];
	$t_saved_document = null;
	$t_knowledge_entry = null;
	if( $t_mode === 'meeting' ) {
		$t_saved_document = ai_assist_process_meeting_document( $t_reply, $t_user_id );
		if( $t_saved_document !== null ) {
			$t_reply = $t_saved_document['stripped_reply'];
			unset( $t_saved_document['stripped_reply'] );
		}
	}
	if( in_array( $t_mode, array( 'help', 'meeting' ), true ) ) {
		$t_knowledge_entry = ai_assist_process_knowledge_entry( $t_reply, $t_user_id );
		if( $t_knowledge_entry !== null ) {
			$t_reply = $t_knowledge_entry['stripped_reply'];
			unset( $t_knowledge_entry['stripped_reply'] );
		}
	}
	$t_messages[] = array( 'role' => 'assistant', 'content' => $t_reply );
	$t_doc_id = $t_saved_document['doc_id'] ?? ( $t_focus['doc_ref'] ?? ( $t_series !== null ? 'series:' . $t_series['id'] : '' ) );
	$t_dwg_id = (int)( $t_saved_document['dwg_id'] ?? ( $t_focus['dwg_id'] ?? 0 ) );
	ai_session_save( (int)$t_conversation['id'], $t_request_id, $t_messages, $t_doc_id, $t_dwg_id );
	$t_evidence['displayed_reply'] = $t_reply;
	$t_evidence['saved_document'] = $t_saved_document;
	$t_evidence['knowledge_entry'] = $t_knowledge_entry;
	$t_processing_error = $t_saved_document['error'] ?? ( $t_knowledge_entry['error'] ?? null );
	ai_session_request_record( $t_request_id, $t_processing_error !== null ? 'partial' : 'success', $t_evidence, true );
	$t_request_finished = true;
} catch( Throwable $t_error ) {
	if( $t_request_id > 0 ) {
		$t_evidence['error'] = $t_error->getMessage();
		ai_session_request_record( $t_request_id, 'failed', $t_evidence, true );
		$t_request_finished = true;
	}
	ai_assist_json_error( $t_error->getMessage(), (int)$t_conversation['id'], $t_request_id );
}

# ── Return success response ───────────────────────────────────────────────────

header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode( [
	'conversation_id' => (int)$t_conversation['id'],
	'request_id' => $t_request_id,
	'reply'          => $t_reply,
	'error'          => null,
	'usage'          => [
		'input_tokens'  => $t_answer['usage']['input_tokens'] ?? null,
		'output_tokens' => $t_answer['usage']['output_tokens'] ?? null,
		'cache_read_input_tokens'     => $t_answer['usage']['cache_read_input_tokens'] ?? null,
		'cache_creation_input_tokens' => $t_answer['usage']['cache_creation_input_tokens'] ?? null,
	],
	'saved_document'  => $t_saved_document,
	'knowledge_entry' => $t_knowledge_entry,
] );
exit;


/** Output a user-facing error; diagnostics, when available, live in History. */
function ai_assist_json_error( string $p_message, int $p_conversation_id = 0, int $p_request_id = 0 ): never {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo ai_session_json( array( 'reply' => null, 'error' => $p_message,
		'conversation_id' => $p_conversation_id, 'request_id' => $p_request_id ) );
	exit;
}
