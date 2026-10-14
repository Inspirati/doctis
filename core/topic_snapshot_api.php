<?php
# Doctis - Document Issue Tracking System
#
# Documentation Topics, phase 3: coverage snapshots (TOP-FR-022/023).
#
# A snapshot freezes a project's Topic coverage at a milestone for a technical
# review or baseline decision: applicability, tags, expectations, states and
# reasons, evidence versions, latest assessments, readiness and the profile
# revision. It is captured in one consistent read and stored as JSON with a
# SHA-256 hash; it is never updated. Unresolved states are kept as they are.
# Showing a snapshot re-applies the reader's current permissions: documents
# the reader cannot see now are not named, without altering the record.

use Mantis\Exceptions\ClientException;

require_api( 'topic_expectation_api.php' );

/**
 * Longest snapshot title.
 */
const TOPIC_SNAPSHOT_TITLE_MAX = 128;

/**
 * Whether the user may take snapshots of a project ($g_topic_snapshot_threshold).
 *
 * @param integer      $p_project_id Project.
 * @param integer|null $p_user_id    User, default current.
 * @return boolean
 */
function topic_can_snapshot( int $p_project_id, ?int $p_user_id = null ): bool {
	return topic_project_is_enabled( $p_project_id )
		&& access_has_project_level( config_get( 'topic_snapshot_threshold', null, $p_user_id, $p_project_id ), $p_project_id, $p_user_id );
}

/**
 * Frozen form of a document reference.
 *
 * @param array $p_document Row with id (document), number, title, status.
 * @return array
 */
function topic_snapshot_document( array $p_document ): array {
	$t_status = (int)$p_document['status'];
	return array(
		'dwg_id' => (int)$p_document['id'],
		'label' => topic_document_label( $p_document ),
		'status' => $t_status,
		'status_label' => get_enum_element( 'dwg_status', $t_status ),
	);
}

/**
 * Build the frozen content of a snapshot from an unfiltered coverage view.
 *
 * @param array $p_view topic_coverage_view( ..., true ).
 * @return array
 */
function topic_snapshot_build( array $p_view ): array {
	$t_catalogue = $p_view['catalogue'];
	$t_milestones = $p_view['milestones'];
	$t_codes = array();
	foreach( $p_view['topics'] as $t_topic_id => $t_topic ) {
		$t_codes[$t_topic_id] = $t_topic['code'];
	}

	$t_topics = array();
	foreach( $p_view['topics'] as $t_topic_id => $t_topic ) {
		$t_tags = array();
		foreach( $t_topic['tags'] as $t_tag ) {
			$t_tags[] = topic_snapshot_document( $t_tag['document'] ) + array(
				'extent' => (int)$t_tag['extent'],
				'extent_label' => topic_extent_label( (int)$t_tag['extent'] ),
				'locator' => $t_tag['locator'],
				'inactive' => (bool)$t_tag['inactive'],
				'version' => topic_evidence_version( (int)$t_tag['dwg_id'] ),
			);
		}
		$t_expectations = array();
		foreach( $t_topic['expectations'] as $t_expectation_id => $t_expectation ) {
			$t_evidence = array();
			foreach( $t_expectation['evidence'] as $t_item ) {
				$t_evidence[] = topic_snapshot_document( $t_item ) + array(
					'version' => $t_item['version'],
					'version_label' => topic_evidence_version_label( $t_item['version'] ),
					'identifiable' => topic_evidence_version_is_identifiable( $t_item['version'] ),
					'locator' => $t_item['locator'],
				);
			}
			$t_assessment = null;
			if( $t_expectation['assessment'] !== null ) {
				$t_row = $t_expectation['assessment'];
				$t_assessment = array(
					'finding' => (int)$t_row['finding'],
					'finding_label' => topic_finding_label( (int)$t_row['finding'] ),
					'rationale' => $t_row['rationale'],
					'assessor' => topic_user_name( (int)$t_row['user_id'] ),
					'date' => (int)$t_row['date_assessed'],
					'milestone' => $t_milestones[(int)$t_row['milestone_id']]['name'] ?? '',
					'expectation_revision' => (int)$t_row['expectation_revision'],
					'evidence' => array_values( $t_row['evidence'] ),
				);
			}
			$t_expectations[] = array(
				'id' => (int)$t_expectation_id,
				'ref' => topic_expectation_ref( $t_expectation ),
				'statement' => $t_expectation['statement'],
				'owner' => topic_user_name( (int)$t_expectation['owner_id'] ),
				'due' => $t_milestones[(int)$t_expectation['milestone_id']]['name'] ?? '',
				'maturity' => (int)$t_expectation['maturity'],
				'maturity_label' => topic_maturity_label( (int)$t_expectation['maturity'] ),
				'revision' => (int)$t_expectation['revision'],
				'state' => $t_expectation['state'],
				'state_label' => topic_exp_state_label( $t_expectation['state'] ),
				'reasons' => $t_expectation['reasons'],
				'evidence' => $t_evidence,
				'assessment' => $t_assessment,
			);
		}
		$t_decision = $t_topic['decision'];
		$t_topics[] = array(
			'id' => (int)$t_topic_id,
			'code' => $t_topic['code'],
			'title' => $t_topic['title'],
			'parent' => $t_codes[(int)$t_topic['parent_id']] ?? '',
			'level' => (int)$t_topic['level'],
			'enabled' => (int)$t_topic['enabled'] == 1,
			'applicability' => (int)$t_topic['applicability'],
			'applicability_label' => topic_applicability_label( (int)$t_topic['applicability'] ),
			'rationale' => $t_decision['rationale'] ?? '',
			'decided_by' => $t_decision === null ? '' : topic_user_name( (int)$t_decision['user_id'] ),
			'decided_at' => $t_decision === null ? 0 : (int)$t_decision['date_decided'],
			'declared_state' => $t_topic['state'],
			'declared_label' => topic_state_label( $t_topic['state'] ),
			'expectation_state' => $t_topic['expectation_state'],
			'expectation_label' => topic_exp_state_label( $t_topic['expectation_state'] ),
			'tags' => $t_tags,
			'expectations' => $t_expectations,
		);
	}

	$t_profile = $p_view['profile'];
	$t_milestone = $t_milestones[$p_view['milestone_id']] ?? null;
	$t_milestone_list = array();
	foreach( $t_milestones as $t_id => $t_row ) {
		$t_milestone_list[] = array( 'id' => (int)$t_id, 'name' => $t_row['name'], 'achieved' => (int)$t_row['date_achieved'] );
	}
	return array(
		'format' => 1,
		'catalogue' => array(
			'id' => (int)$t_catalogue['id'],
			'label' => topic_catalogue_label( $t_catalogue ),
			'status' => topic_catalogue_status_label( (int)$t_catalogue['status'] ),
		),
		'milestone' => $t_milestone === null ? null : array( 'id' => (int)$p_view['milestone_id'], 'name' => $t_milestone['name'] ),
		'milestones' => $t_milestone_list,
		'readiness' => $p_view['readiness'],
		'readiness_label' => topic_exp_state_label( $p_view['readiness'] ),
		'profile' => array(
			'revision' => $t_profile['current'] === null ? 0 : (int)$t_profile['current']['revision'],
			'authorised_by' => $t_profile['current'] === null ? '' : topic_user_name( (int)$t_profile['current']['user_id'] ),
			'authorised_at' => $t_profile['current'] === null ? 0 : (int)$t_profile['current']['date_authorised'],
			'changed' => (bool)$t_profile['changed'],
		),
		'expectation_counts' => $p_view['expectation_counts'],
		'topics' => $t_topics,
	);
}

/**
 * Take a snapshot of a project's Topic coverage at a milestone, reading all
 * data in one consistent transaction.
 *
 * @param integer $p_project_id   Project with Topics enabled.
 * @param integer $p_milestone_id Milestone, 0 = current.
 * @param string  $p_title        Title, e.g. "CDR 2026-11-02".
 * @return integer New snapshot id.
 * @throws ClientException
 */
function topic_snapshot_capture( int $p_project_id, int $p_milestone_id, string $p_title ): int {
	topic_project_catalogue( $p_project_id );
	$t_title = trim( $p_title );
	if( $t_title === '' || mb_strlen( $t_title ) > TOPIC_SNAPSHOT_TITLE_MAX ) {
		topic_error( 'a snapshot needs a title of at most ' . TOPIC_SNAPSHOT_TITLE_MAX . ' characters.' );
	}

	db_query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
	try {
		$t_view = topic_coverage_view( $p_project_id, $p_milestone_id, null, true );
		$t_data = topic_snapshot_build( $t_view );
		$t_json = json_encode( $t_data, JSON_UNESCAPED_UNICODE );
		$t_milestone = $t_data['milestone'];
		db_param_push();
		db_query( 'INSERT INTO {topic_snapshot} ( project_id, title, milestone_id, milestone_name, readiness, profile_revision,
				catalogue_label, data, data_hash, user_id, date_created )
			VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
			. db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
			array( $p_project_id, $t_title, $t_milestone === null ? 0 : $t_milestone['id'], $t_milestone === null ? '' : $t_milestone['name'],
				$t_data['readiness'], $t_data['profile']['revision'], $t_data['catalogue']['label'], $t_json,
				hash( 'sha256', $t_json ), auth_get_current_user_id(), db_now() ) );
		$t_id = (int)db_insert_id( db_get_table( 'topic_snapshot' ) );
		topic_event_log( $p_project_id, 'snapshot', 'S' . $t_id . ': ' . $t_title
			. ( $t_milestone === null ? '' : ' (' . $t_milestone['name'] . ', ' . $t_data['readiness_label'] . ')' ) );
		db_query( 'COMMIT' );
	} catch( Throwable $e ) {
		db_query( 'ROLLBACK' );
		throw $e;
	}
	return $t_id;
}

/**
 * One snapshot, with 'content' decoded and 'intact' (hash matches).
 *
 * @param integer $p_snapshot_id Snapshot.
 * @return array Row of {topic_snapshot}.
 * @throws ClientException
 */
function topic_snapshot_get( int $p_snapshot_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_snapshot} WHERE id=' . db_param(), array( $p_snapshot_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'snapshot ' . $p_snapshot_id . ' does not exist.' );
	}
	$t_row['intact'] = hash( 'sha256', $t_row['data'] ) === $t_row['data_hash'];
	$t_row['content'] = json_decode( $t_row['data'], true );
	return $t_row;
}

/**
 * A project's snapshots, newest first (without their data).
 *
 * @param integer $p_project_id Project.
 * @return array Rows of {topic_snapshot} without data.
 */
function topic_snapshot_get_all( int $p_project_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT id, project_id, title, milestone_id, milestone_name, readiness, profile_revision, catalogue_label,
			data_hash, user_id, date_created
		FROM {topic_snapshot} WHERE project_id=' . db_param() . ' ORDER BY id DESC', array( $p_project_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Whether the current reader may see a document referenced by a snapshot, and
 * how to name it: its frozen label when visible now, else a generic text.
 *
 * @param array $p_document Frozen document reference (dwg_id, label).
 * @return array visible (bool), text (plain).
 */
function topic_snapshot_document_view( array $p_document ): array {
	$t_dwg_id = (int)$p_document['dwg_id'];
	if( !dwg_exists( $t_dwg_id ) ) {
		return array( 'visible' => false, 'text' => lang_get( 'topic_snapshot_document_gone' ) );
	}
	if( !topic_can_see_dwg( $t_dwg_id ) ) {
		return array( 'visible' => false, 'text' => lang_get( 'topic_restricted_short' ) );
	}
	return array( 'visible' => true, 'text' => (string)$p_document['label'] );
}

/**
 * A snapshot's topics as the current reader may see them: hidden documents
 * are replaced by a generic entry and reasons naming them are redacted.
 *
 * @param array $p_content Decoded snapshot content.
 * @return array Topics as in the content, each document with 'visible' and
 *               'text'; 'restricted' set when anything was hidden.
 */
function topic_snapshot_reader_topics( array $p_content ): array {
	$t_topics = array();
	foreach( $p_content['topics'] ?? array() as $t_topic ) {
		$t_topic['restricted'] = false;
		foreach( $t_topic['tags'] as $t_index => $t_tag ) {
			$t_topic['tags'][$t_index] += topic_snapshot_document_view( $t_tag );
			$t_topic['restricted'] = $t_topic['restricted'] || !$t_topic['tags'][$t_index]['visible'];
		}
		foreach( $t_topic['expectations'] as $t_index => $t_expectation ) {
			$t_hidden = array();
			foreach( $t_expectation['evidence'] as $t_e => $t_item ) {
				$t_item += topic_snapshot_document_view( $t_item );
				if( !$t_item['visible'] ) {
					$t_hidden[] = $t_item['label'];
					$t_topic['restricted'] = true;
				}
				$t_expectation['evidence'][$t_e] = $t_item;
			}
			$t_expectation['reasons'] = topic_redact_reasons( $t_expectation['reasons'], $t_hidden );
			$t_topic['expectations'][$t_index] = $t_expectation;
		}
		$t_topics[] = $t_topic;
	}
	return $t_topics;
}

/**
 * Export rows of a snapshot for the current reader, in the same shape as
 * topic_export_rows() and topic_export_expectation_rows().
 *
 * @param array $p_content Decoded snapshot content.
 * @return array topics (rows), expectations (rows).
 */
function topic_snapshot_export_rows( array $p_content ): array {
	$t_topic_rows = array();
	$t_expectation_rows = array();
	foreach( topic_snapshot_reader_topics( $p_content ) as $t_topic ) {
		$t_base = array(
			'code' => $t_topic['code'],
			'topic' => $t_topic['title'] . ( $t_topic['enabled'] ? '' : ' (' . lang_get( 'topic_retired' ) . ')' ),
			'parent' => $t_topic['parent'],
			'required' => $t_topic['applicability_label'],
			'rationale' => $t_topic['rationale'],
			'coverage' => $t_topic['declared_label'],
			'document' => '', 'title' => '', 'status' => '', 'extent' => '', 'sections' => '', 'inactive' => false,
		);
		$t_any = false;
		$t_hidden = false;
		foreach( $t_topic['tags'] as $t_tag ) {
			if( !$t_tag['visible'] ) {
				$t_hidden = true;
				continue;
			}
			$t_any = true;
			$t_topic_rows[] = array_merge( $t_base, array(
				'document' => $t_tag['text'], 'title' => '', 'status' => $t_tag['status_label'],
				'extent' => $t_tag['extent_label'], 'sections' => $t_tag['locator'], 'inactive' => $t_tag['inactive'],
			) );
		}
		if( $t_hidden ) {
			$t_topic_rows[] = array_merge( $t_base, array( 'document' => lang_get( 'topic_restricted_short' ) ) );
		} else if( !$t_any ) {
			$t_topic_rows[] = $t_base;
		}

		foreach( $t_topic['expectations'] as $t_expectation ) {
			$t_evidence = array();
			$t_hidden_evidence = false;
			foreach( $t_expectation['evidence'] as $t_item ) {
				if( !$t_item['visible'] ) {
					$t_hidden_evidence = true;
					continue;
				}
				$t_evidence[] = $t_item['text'] . ' [' . $t_item['version_label'] . ']' . ( $t_item['locator'] !== '' ? ' ' . $t_item['locator'] : '' );
			}
			if( $t_hidden_evidence ) {
				$t_evidence[] = lang_get( 'topic_restricted_short' );
			}
			$t_assessment = $t_expectation['assessment'];
			$t_expectation_rows[] = array(
				'code' => $t_topic['code'],
				'ref' => $t_expectation['ref'],
				'expectation' => $t_expectation['statement'],
				'owner' => $t_expectation['owner'],
				'due' => $t_expectation['due'],
				'maturity' => $t_expectation['maturity_label'],
				'state' => $t_expectation['state_label'],
				'reasons' => implode( ' ', $t_expectation['reasons'] ),
				'evidence' => implode( '; ', $t_evidence ),
				'assessment' => $t_assessment === null ? '' : $t_assessment['finding_label'] . ', '
					. date( config_get( 'short_date_format' ), (int)$t_assessment['date'] ) . ', ' . $t_assessment['assessor'],
			);
		}
	}
	return array( 'topics' => $t_topic_rows, 'expectations' => $t_expectation_rows );
}
