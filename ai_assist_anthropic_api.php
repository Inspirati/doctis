<?php
# Doctis — AI Assistant: Anthropic Messages API client
#
# One non-streaming call to https://api.anthropic.com/v1/messages, used by the
# chat endpoint (ai_assist_api.php) and the meeting scheduler
# (scripts/meeting_schedule.php).
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );

/**
 * Send a conversation to the Anthropic Messages API.
 *
 * @param string $p_system     System prompt.
 * @param array  $p_messages   Each ['role' => 'user'|'assistant', 'content' => string].
 * @param int    $p_max_tokens
 * @return array{reply: string|null, usage: array|null, error: string|null}
 *               error is a user-facing message; details go to the error log.
 */
function ai_assist_anthropic_request( string $p_system, array $p_messages, int $p_max_tokens ): array {
	$t_api_key = config_get_global( 'anthropic_api_key' );
	if( is_blank( $t_api_key ) ) {
		return array( 'reply' => null, 'usage' => null, 'error' => 'AI Assistant is not configured on this server.' );
	}

	$t_ch = curl_init( 'https://api.anthropic.com/v1/messages' );
	curl_setopt_array( $t_ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( [
			'model'      => config_get_global( 'ai_model' ),
			'max_tokens' => $p_max_tokens,
			'system'     => $p_system,
			'messages'   => $p_messages,
		] ),
		CURLOPT_HTTPHEADER     => [
			'Content-Type: application/json',
			'x-api-key: ' . $t_api_key,
			'anthropic-version: 2023-06-01',
			'User-Agent: Doctis/1.0',
		],
		CURLOPT_TIMEOUT        => 90,
		CURLOPT_CONNECTTIMEOUT => 10,
	] );
	$t_response   = curl_exec( $t_ch );
	$t_http_code  = curl_getinfo( $t_ch, CURLINFO_HTTP_CODE );
	$t_curl_error = curl_error( $t_ch );
	curl_close( $t_ch );

	if( $t_response === false ) {
		error_log( 'ai_assist_anthropic_api: cURL error: ' . $t_curl_error );
		return array( 'reply' => null, 'usage' => null, 'error' => 'Could not reach the AI service. Please try again.' );
	}

	$t_data = json_decode( $t_response, true );
	if( $t_http_code !== 200 ) {
		$t_err_msg = $t_data['error']['message'] ?? 'API error (HTTP ' . $t_http_code . ').';
		error_log( 'ai_assist_anthropic_api: Anthropic error ' . $t_http_code . ': ' . $t_err_msg );
		switch( $t_http_code ) {
			case 401: $t_err_msg = 'AI service authentication failed. Check the API key in config.'; break;
			case 429: $t_err_msg = 'AI service rate limit reached. Please wait a moment and try again.'; break;
			case 529: $t_err_msg = 'The AI service is currently overloaded. Please try again shortly.'; break;
		}
		return array( 'reply' => null, 'usage' => null, 'error' => $t_err_msg );
	}

	$t_reply = $t_data['content'][0]['text'] ?? '';
	if( is_blank( $t_reply ) ) {
		return array( 'reply' => null, 'usage' => null, 'error' => 'Empty response received from AI service.' );
	}
	return array(
		'reply' => $t_reply,
		'usage' => array(
			'input_tokens'  => $t_data['usage']['input_tokens']  ?? null,
			'output_tokens' => $t_data['usage']['output_tokens'] ?? null,
		),
		'error' => null,
	);
}
