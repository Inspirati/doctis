<?php
# Doctis — AI Assistant knowledge base
#
# Facts about Doctis and the organisation that users teach the AI Assistant
# (Help and Meeting tabs) or managers add directly. Every entry is visible to
# everyone at once, marked unverified, until a user at
# $g_ai_knowledge_review_threshold (MANAGER) publishes, edits, retires or
# deletes it on ai_knowledge_page.php. An entry tied to a project is visible
# only to users with access to that project.
#
# Entries are reference data for the assistant, never instructions: stored
# text is stripped of the assistant's marker syntax, and the prompt presents
# them as user-contributed facts.
#
# Table: {ai_knowledge} (admin/schema.php step 64).
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'access_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'lang_api.php' );
require_api( 'project_api.php' );
require_api( 'user_api.php' );

use Mantis\Exceptions\ClientException;

define( 'AI_KNOWLEDGE_UNVERIFIED', 10 );
define( 'AI_KNOWLEDGE_PUBLISHED', 30 );
define( 'AI_KNOWLEDGE_RETIRED', 90 );

/** Field limits (characters). */
const AI_KNOWLEDGE_LIMITS = array( 'question' => 255, 'answer' => 4000, 'keywords' => 255, 'page' => 255 );

/**
 * Get an entry by id.
 *
 * @param int $p_id
 * @return array|null
 */
function ai_knowledge_get( int $p_id ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {ai_knowledge} WHERE id=' . db_param(), array( $p_id ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

/**
 * Whether a user may see an entry: global entries, or project entries of a
 * project the user can access.
 *
 * @param array $p_entry
 * @param int   $p_user_id
 * @return bool
 */
function ai_knowledge_user_can_see( array $p_entry, int $p_user_id ): bool {
	$t_project = (int)$p_entry['project_id'];
	return $t_project === 0
		|| ( project_exists( $t_project ) && access_has_project_level( VIEWER, $t_project, $p_user_id ) );
}

/**
 * Whether the user reviews the knowledge base (publish, edit, retire, delete).
 *
 * @param int $p_user_id
 * @return bool
 */
function ai_knowledge_user_can_review( int $p_user_id ): bool {
	return access_has_global_level( config_get_global( 'ai_knowledge_review_threshold' ), $p_user_id );
}

/**
 * Entries a user can see, newest first.
 *
 * @param int  $p_user_id
 * @param bool $p_include_retired
 * @return array
 */
function ai_knowledge_list( int $p_user_id, bool $p_include_retired = false ): array {
	db_param_push();
	$t_result = db_query(
		'SELECT * FROM {ai_knowledge}'
		. ( $p_include_retired ? '' : ' WHERE status <> ' . db_param() )
		. ' ORDER BY date_updated DESC, id DESC',
		$p_include_retired ? array() : array( AI_KNOWLEDGE_RETIRED )
	);
	$t_rows = array();
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		if( ai_knowledge_user_can_see( $t_row, $p_user_id ) ) {
			$t_rows[] = $t_row;
		}
	}
	return $t_rows;
}

/**
 * Clean a field: marker syntax and control characters removed, whitespace
 * normalised, length limited.
 *
 * @param string $p_field  Field name (key of AI_KNOWLEDGE_LIMITS).
 * @param string $p_value
 * @return string
 */
function ai_knowledge_clean( string $p_field, string $p_value ): string {
	$t_value = str_replace( array( '<<<', '>>>' ), '', $p_value );
	$t_value = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $t_value );
	$t_value = $p_field === 'answer' ? trim( $t_value ) : trim( preg_replace( '/\s+/u', ' ', $t_value ) );
	return mb_substr( $t_value, 0, AI_KNOWLEDGE_LIMITS[$p_field] );
}

/**
 * A Doctis page reference, if it names a page that exists: "name.php" with an
 * optional query string. '' when invalid or absent.
 *
 * @param string $p_page
 * @return string
 */
function ai_knowledge_clean_page( string $p_page ): string {
	$t_page = trim( $p_page );
	$t_page = preg_replace( '#^.*/doctis/#', '', $t_page );   # tolerate a full URL to this site
	if( !preg_match( '/^([a-z0-9_]+\.php)(\?[A-Za-z0-9_=&%.#-]*)?$/', $t_page, $t_m ) ) {
		return '';
	}
	return is_file( dirname( __DIR__ ) . '/' . $t_m[1] ) ? mb_substr( $t_page, 0, AI_KNOWLEDGE_LIMITS['page'] ) : '';
}

/**
 * Number of entries a user added through the assistant in the last 24 hours.
 *
 * @param int $p_user_id
 * @return int
 */
function ai_knowledge_count_recent( int $p_user_id ): int {
	db_param_push();
	$t_result = db_query( 'SELECT COUNT(*) FROM {ai_knowledge} WHERE created_by=' . db_param()
		. ' AND date_created > ' . db_param() . ' AND source=' . db_param(),
		array( $p_user_id, db_now() - 86400, 'assistant' ) );
	return (int)db_result( $t_result );
}

/**
 * Add an entry (unverified, or published when a reviewer adds it directly).
 *
 * @param array $p_fields question, answer, keywords, page, project_id, supersedes, source.
 * @param int   $p_user_id
 * @return int New entry id.
 * @throws ClientException when the question or answer is empty, a project is
 *         not accessible, or the daily limit is reached.
 */
function ai_knowledge_add( array $p_fields, int $p_user_id ): int {
	$t_question = ai_knowledge_clean( 'question', (string)( $p_fields['question'] ?? '' ) );
	$t_answer = ai_knowledge_clean( 'answer', (string)( $p_fields['answer'] ?? '' ) );
	if( $t_question === '' || $t_answer === '' ) {
		throw new ClientException( 'A knowledge entry needs a question and an answer', ERROR_EMPTY_FIELD, array( 'question' ) );
	}
	$t_project = (int)( $p_fields['project_id'] ?? 0 );
	if( $t_project !== 0 && ( !project_exists( $t_project ) || !access_has_project_level( VIEWER, $t_project, $p_user_id ) ) ) {
		throw new ClientException( 'No access to project ' . $t_project, ERROR_ACCESS_DENIED );
	}
	$t_source = (string)( $p_fields['source'] ?? 'manual' );
	if( $t_source === 'assistant'
		&& ai_knowledge_count_recent( $p_user_id ) >= (int)config_get_global( 'ai_knowledge_daily_limit' ) ) {
		throw new ClientException( 'Daily limit of knowledge entries reached', ERROR_ACCESS_DENIED );
	}
	$t_supersedes = (int)( $p_fields['supersedes'] ?? 0 );
	if( $t_supersedes > 0 ) {
		$t_old = ai_knowledge_get( $t_supersedes );
		if( $t_old === null || !ai_knowledge_user_can_see( $t_old, $p_user_id ) ) {
			$t_supersedes = 0;
		}
	}
	$t_publish = $t_source === 'manual' && ai_knowledge_user_can_review( $p_user_id );
	$t_now = db_now();

	db_param_push();
	db_query(
		'INSERT INTO {ai_knowledge} ( question, answer, keywords, page, project_id, status, source, supersedes,'
		. ' created_by, date_created, updated_by, date_updated, reviewed_by, date_reviewed )'
		. ' VALUES ( ' . implode( ', ', array_fill( 0, 14, db_param() ) ) . ' )',
		array(
			$t_question, $t_answer,
			ai_knowledge_clean( 'keywords', (string)( $p_fields['keywords'] ?? '' ) ),
			ai_knowledge_clean_page( (string)( $p_fields['page'] ?? '' ) ),
			$t_project,
			$t_publish ? AI_KNOWLEDGE_PUBLISHED : AI_KNOWLEDGE_UNVERIFIED,
			mb_substr( $t_source, 0, 16 ), $t_supersedes,
			$p_user_id, $t_now, $p_user_id, $t_now,
			$t_publish ? $p_user_id : 0, $t_publish ? $t_now : 0,
		)
	);
	return (int)db_insert_id( db_get_table( 'ai_knowledge' ) );
}

/**
 * Review an entry: publish, retire, or save edited fields (which keeps its
 * status). Reviewers only.
 *
 * @param int    $p_id
 * @param string $p_action  publish, retire, edit.
 * @param array  $p_fields  For edit: question, answer, keywords, page, project_id.
 * @param int    $p_user_id
 * @return void
 * @throws ClientException when not permitted or the entry does not exist.
 */
function ai_knowledge_review( int $p_id, string $p_action, array $p_fields, int $p_user_id ): void {
	$t_entry = ai_knowledge_get( $p_id );
	if( $t_entry === null ) {
		throw new ClientException( 'Knowledge entry ' . $p_id . ' not found', ERROR_GENERIC );
	}
	if( !ai_knowledge_user_can_review( $p_user_id ) ) {
		throw new ClientException( 'Only reviewers can change knowledge entries', ERROR_ACCESS_DENIED );
	}
	$t_now = db_now();
	switch( $p_action ) {
		case 'publish':
		case 'retire':
			db_param_push();
			db_query( 'UPDATE {ai_knowledge} SET status=' . db_param() . ', reviewed_by=' . db_param()
				. ', date_reviewed=' . db_param() . ', updated_by=' . db_param() . ', date_updated=' . db_param()
				. ' WHERE id=' . db_param(),
				array( $p_action === 'publish' ? AI_KNOWLEDGE_PUBLISHED : AI_KNOWLEDGE_RETIRED,
					$p_user_id, $t_now, $p_user_id, $t_now, $p_id ) );
			# Publishing a correction retires the entry it supersedes.
			if( $p_action === 'publish' && (int)$t_entry['supersedes'] > 0 ) {
				ai_knowledge_review( (int)$t_entry['supersedes'], 'retire', array(), $p_user_id );
			}
			return;
		case 'edit':
			$t_question = ai_knowledge_clean( 'question', (string)( $p_fields['question'] ?? $t_entry['question'] ) );
			$t_answer = ai_knowledge_clean( 'answer', (string)( $p_fields['answer'] ?? $t_entry['answer'] ) );
			if( $t_question === '' || $t_answer === '' ) {
				throw new ClientException( 'A knowledge entry needs a question and an answer', ERROR_EMPTY_FIELD, array( 'question' ) );
			}
			$t_project = (int)( $p_fields['project_id'] ?? $t_entry['project_id'] );
			if( $t_project !== 0 && !project_exists( $t_project ) ) {
				$t_project = 0;
			}
			db_param_push();
			db_query( 'UPDATE {ai_knowledge} SET question=' . db_param() . ', answer=' . db_param()
				. ', keywords=' . db_param() . ', page=' . db_param() . ', project_id=' . db_param()
				. ', updated_by=' . db_param() . ', date_updated=' . db_param() . ' WHERE id=' . db_param(),
				array( $t_question, $t_answer,
					ai_knowledge_clean( 'keywords', (string)( $p_fields['keywords'] ?? $t_entry['keywords'] ) ),
					ai_knowledge_clean_page( (string)( $p_fields['page'] ?? $t_entry['page'] ) ),
					$t_project, $p_user_id, $t_now, $p_id ) );
			return;
	}
	throw new ClientException( 'Unknown review action "' . $p_action . '"', ERROR_INVALID_FIELD_VALUE, array( 'action' ) );
}

/**
 * Delete an entry: a reviewer, or the author while it is unverified.
 *
 * @param int $p_id
 * @param int $p_user_id
 * @return void
 * @throws ClientException when not permitted.
 */
function ai_knowledge_delete( int $p_id, int $p_user_id ): void {
	$t_entry = ai_knowledge_get( $p_id );
	if( $t_entry === null ) {
		return;
	}
	$t_own_draft = (int)$t_entry['created_by'] === $p_user_id && (int)$t_entry['status'] === AI_KNOWLEDGE_UNVERIFIED;
	if( !$t_own_draft && !ai_knowledge_user_can_review( $p_user_id ) ) {
		throw new ClientException( 'Not permitted to delete knowledge entry ' . $p_id, ERROR_ACCESS_DENIED );
	}
	db_param_push();
	db_query( 'DELETE FROM {ai_knowledge} WHERE id=' . db_param(), array( $p_id ) );
}

/**
 * Localised label for an entry status.
 *
 * @param int $p_status
 * @return string
 */
function ai_knowledge_status_label( int $p_status ): string {
	switch( $p_status ) {
		case AI_KNOWLEDGE_UNVERIFIED: return lang_get( 'ai_knowledge_unverified' );
		case AI_KNOWLEDGE_PUBLISHED:  return lang_get( 'ai_knowledge_published' );
		case AI_KNOWLEDGE_RETIRED:    return lang_get( 'ai_knowledge_retired' );
	}
	return (string)$p_status;
}
