<?php
# Doctis — retained AI conversations and request evidence. No credentials are recorded.

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );

/** Escape Unicode for legacy utf8mb3 connections; decoding restores emoji too. */
function ai_session_json( $p_value ): string {
	return json_encode( $p_value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR );
}

/** Owners see their conversations; administrators may investigate other users. */
function ai_session_can_view( array $p_conversation, int $p_user_id ): bool {
	return (int)$p_conversation['user_id'] === $p_user_id
		|| access_has_global_level( ADMINISTRATOR, $p_user_id );
}

function ai_session_get( int $p_id ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {ai_conversation} WHERE id=' . db_param(), array( $p_id ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

function ai_session_create( int $p_user_id, string $p_mode, string $p_context = '', array $p_history = array(), string $p_doc_id = '', int $p_dwg_id = 0, int $p_created = 0 ): array {
	db_param_push();
	db_query( 'INSERT INTO {ai_conversation} (user_id, mode, context_key, created, updated, history, doc_id, dwg_id)'
		. ' VALUES (' . implode( ', ', array_map( function() { return db_param(); }, range( 1, 8 ) ) ) . ')',
		array( $p_user_id, $p_mode, $p_context, $p_created ?: db_now(), db_now(), ai_session_json( $p_history ), $p_doc_id, $p_dwg_id ) );
	return ai_session_get( (int)db_insert_id( db_get_table( 'ai_conversation' ) ) );
}

/** Import the old transcript once, without deleting or changing legacy rows. */
function ai_session_import_legacy( int $p_user_id, string $p_mode ): void {
	db_param_push();
	$t_result = db_query( 'SELECT id FROM {ai_conversation} WHERE user_id=' . db_param() . ' AND mode=' . db_param(), array( $p_user_id, $p_mode ) );
	if( db_num_rows( $t_result ) > 0 ) {
		return;
	}
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {ai_sessions} WHERE user_id=' . db_param() . ' AND mode=' . db_param() . ' ORDER BY updated, id', array( $p_user_id, $p_mode ) );
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_doc = (string)( $t_row['doc_id'] ?? '' );
		$t_imported = ai_session_create( $p_user_id, $p_mode, $t_doc, json_decode( $t_row['history'], true ) ?: array(),
			$t_doc, (int)( $t_row['dwg_id'] ?? 0 ), (int)$t_row['created'] );
		db_param_push();
		db_query( 'UPDATE {ai_conversation} SET updated=' . db_param() . ' WHERE id=' . db_param(), array( $t_row['updated'], $t_imported['id'] ) );
	}
}

/** A context is a meeting reference, series:N, or '' for the general chat. */
function ai_session_current( int $p_user_id, string $p_mode, string $p_context = '', int $p_id = 0 ): array {
	ai_session_import_legacy( $p_user_id, $p_mode );
	if( $p_id > 0 ) {
		$t_row = ai_session_get( $p_id );
		# Administrator read access never grants the right to continue someone else's chat.
		if( $t_row === null || (int)$t_row['user_id'] !== $p_user_id || $t_row['mode'] !== $p_mode
			|| (int)$t_row['archived'] > 0 || ( $p_context !== '' && $t_row['context_key'] !== $p_context && $t_row['doc_id'] !== $p_context ) ) {
			throw new RuntimeException( 'This conversation is unavailable. Reload the assistant.' );
		}
		return $t_row;
	}
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {ai_conversation} WHERE user_id=' . db_param() . ' AND mode=' . db_param()
		. ' AND archived=0' . ( $p_context !== '' ? ' AND (context_key=' . db_param() . ' OR doc_id=' . db_param() . ')' : '' ) . ' ORDER BY updated DESC, id DESC LIMIT 1',
		$p_context !== '' ? array( $p_user_id, $p_mode, $p_context, $p_context ) : array( $p_user_id, $p_mode ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? ai_session_create( $p_user_id, $p_mode, $p_context ) : $t_row;
}

/** Clear starts a new conversation; the old transcript and requests are retained. */
function ai_session_archive( array $p_conversation ): array {
	$t_now = db_now();
	db_param_push();
	db_query( 'UPDATE {ai_conversation} SET archived=' . db_param() . ', updated=' . db_param()
		. ' WHERE id=' . db_param() . ' AND archived=0 AND busy_until < ' . db_param(),
		array( $t_now, $t_now, $p_conversation['id'], $t_now ) );
	if( db_affected_rows() !== 1 ) {
		throw new RuntimeException( 'A reply is still being processed. Wait before starting a new conversation.' );
	}
	return ai_session_create( (int)$p_conversation['user_id'], $p_conversation['mode'], $p_conversation['context_key'] );
}

/** Insert the submitted messages first, then claim the conversation for one request. */
function ai_session_request_begin( array $p_conversation, array $p_messages ): int {
	$t_history = json_decode( $p_conversation['history'], true ) ?: array();
	if( count( $p_messages ) !== count( $t_history ) + 1 || array_slice( $p_messages, 0, -1 ) !== $t_history
		|| end( $p_messages )['role'] !== 'user' ) {
		throw new RuntimeException( 'The conversation changed in another tab. Reload before sending again.' );
	}
	$t_now = db_now();
	db_param_push();
	db_query( 'INSERT INTO {ai_request} (conversation_id, user_id, started, request_data, response_data) VALUES ('
		. implode( ', ', array_map( function() { return db_param(); }, range( 1, 5 ) ) ) . ')',
		array( $p_conversation['id'], $p_conversation['user_id'], $t_now, ai_session_json( array( 'messages' => $p_messages ) ), '{}' ) );
	$t_id = (int)db_insert_id( db_get_table( 'ai_request' ) );
	db_param_push();
	db_query( 'UPDATE {ai_conversation} SET pending_request_id=' . db_param() . ', busy_until=' . db_param()
		. ' WHERE id=' . db_param() . ' AND archived=0 AND busy_until < ' . db_param()
		. ' AND revision=' . db_param(),
		array( $t_id, $t_now + 180, $p_conversation['id'], $t_now, $p_conversation['revision'] ) );
	if( db_affected_rows() !== 1 ) {
		ai_session_request_record( $t_id, 'rejected', array( 'error' => 'Conversation is busy or changed. Reload and retry.' ), true );
		throw new RuntimeException( 'A reply is being processed or the conversation changed. Reload and retry.' );
	}
	# A process killed outright cannot run a shutdown handler; its pending record remains evidence.
	db_param_push();
	db_query( "UPDATE {ai_request} SET status='interrupted', finished=" . db_param()
		. " WHERE conversation_id=" . db_param() . " AND status IN ('pending', 'processing') AND id<>" . db_param(),
		array( $t_now, $p_conversation['id'], $t_id ) );
	return $t_id;
}

function ai_session_request_prepare( int $p_id, array $p_data ): void {
	db_param_push();
	db_query( 'UPDATE {ai_request} SET request_data=' . db_param() . ' WHERE id=' . db_param(), array( ai_session_json( $p_data ), $p_id ) );
}

/** Renew the lease before side effects; a late response must not revive an expired request. */
function ai_session_request_renew( int $p_conversation_id, int $p_request_id ): void {
	$t_now = db_now();
	db_param_push();
	db_query( 'UPDATE {ai_conversation} SET busy_until=' . db_param() . ' WHERE id=' . db_param()
		. ' AND archived=0 AND pending_request_id=' . db_param() . ' AND busy_until>=' . db_param(),
		array( $t_now + 180, $p_conversation_id, $p_request_id, $t_now ) );
	$t_conversation = ai_session_get( $p_conversation_id );
	if( $t_conversation === null || (int)$t_conversation['archived'] > 0
		|| (int)$t_conversation['pending_request_id'] !== $p_request_id || (int)$t_conversation['busy_until'] < $t_now ) {
		throw new RuntimeException( 'The request expired; its reply is retained in History. Reload before retrying.' );
	}
}

/** Save provider evidence before document/knowledge processing can change the reply. */
function ai_session_request_record( int $p_id, string $p_status, array $p_response, bool $p_finished = false ): void {
	db_param_push();
	db_query( 'UPDATE {ai_request} SET status=' . db_param() . ', response_data=' . db_param() . ', finished=' . db_param()
		. ' WHERE id=' . db_param(), array( $p_status, ai_session_json( $p_response ), $p_finished ? db_now() : 0, $p_id ) );
	if( $p_finished ) {
		db_param_push();
		db_query( 'UPDATE {ai_conversation} SET pending_request_id=0, busy_until=0 WHERE pending_request_id=' . db_param(), array( $p_id ) );
	}
}

function ai_session_save( int $p_id, int $p_request_id, array $p_messages, string $p_doc_id = '', int $p_dwg_id = 0 ): void {
	db_param_push();
	db_query( 'UPDATE {ai_conversation} SET history=' . db_param() . ', updated=' . db_param() . ', revision=revision+1'
		. ( $p_doc_id !== '' ? ', doc_id=' . db_param() . ', dwg_id=' . db_param() : '' )
		. ' WHERE id=' . db_param() . ' AND archived=0 AND pending_request_id=' . db_param(),
		$p_doc_id !== ''
			? array( ai_session_json( $p_messages ), db_now(), $p_doc_id, $p_dwg_id, $p_id, $p_request_id )
			: array( ai_session_json( $p_messages ), db_now(), $p_id, $p_request_id ) );
	if( db_affected_rows() !== 1 ) {
		throw new RuntimeException( 'The conversation changed; the reply is preserved in History.' );
	}
}

function ai_session_requests( int $p_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {ai_request} WHERE conversation_id=' . db_param() . ' ORDER BY id', array( $p_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}
