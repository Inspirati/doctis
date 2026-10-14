<?php
# Doctis — read-only REST endpoints for Documentation Topics
# (doc/topics/TOPICS_PLAN.md §8).
#
# Routes registered here:
#   GET /api/rest/topics/catalogues                — catalogues and their Topics
#   GET /api/rest/topics/{project_id}              — live coverage (?milestone_id=)
#   GET /api/rest/topics/{project_id}/snapshots    — the project's snapshots
#   GET /api/rest/topics/snapshots/{id}            — one frozen snapshot
#
# States are stable keys (e.g. "satisfied", "not_due"), not translated labels.
# The same permissions as the pages apply: a project whose Topics the caller
# may not view is reported as not found, and documents the caller cannot see
# appear only as {"restricted": true}.

require_api( 'topic_snapshot_api.php' );

use Mantis\Exceptions\ClientException;

/**
 * @var \Slim\App $g_app
 */
$g_app->group( '/topics', function() use ( $g_app ) {
	$g_app->get( '/catalogues', 'rest_topic_catalogues' );
	$g_app->get( '/catalogues/', 'rest_topic_catalogues' );
	$g_app->get( '/snapshots/{id}', 'rest_topic_snapshot' );
	$g_app->get( '/snapshots/{id}/', 'rest_topic_snapshot' );
	$g_app->get( '/{project_id}', 'rest_topic_coverage' );
	$g_app->get( '/{project_id}/', 'rest_topic_coverage' );
	$g_app->get( '/{project_id}/snapshots', 'rest_topic_snapshots' );
	$g_app->get( '/{project_id}/snapshots/', 'rest_topic_snapshots' );
} );

/**
 * Stable key of an applicability value.
 * @param integer $p_value TOPIC_UNDECIDED, _APPLICABLE or _NOT_APPLICABLE.
 * @return string
 */
function rest_topic_applicability_key( int $p_value ): string {
	return $p_value == TOPIC_APPLICABLE ? 'applicable' : ( $p_value == TOPIC_NOT_APPLICABLE ? 'not_applicable' : 'undecided' );
}

/**
 * Stable key of a maturity level.
 * @param integer $p_value TOPIC_MATURITY_* value.
 * @return string
 */
function rest_topic_maturity_key( int $p_value ): string {
	return $p_value == TOPIC_MATURITY_APPROVED ? 'approved' : ( $p_value == TOPIC_MATURITY_UNDER_REVIEW ? 'under_review' : 'draft' );
}

/**
 * ISO 8601 date, or null for 0.
 * @param integer $p_timestamp Unix time.
 * @return string|null
 */
function rest_topic_date( int $p_timestamp ): ?string {
	return $p_timestamp > 0 ? date( 'c', $p_timestamp ) : null;
}

/**
 * Response for a Topics validation error, or 404 for a project the caller
 * may not see.
 * @param \Slim\Http\Response $p_response Response.
 * @param ClientException     $p_exception Error.
 * @return \Slim\Http\Response
 */
function rest_topic_error( \Slim\Http\Response $p_response, ClientException $p_exception ) {
	return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, $p_exception->getMessage() );
}

/**
 * GET /topics/catalogues
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 */
function rest_topic_catalogues( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	if( !topic_feature_enabled() ) {
		return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, 'Documentation Topics are not enabled' );
	}
	$t_catalogues = array();
	foreach( topic_catalogue_get_all() as $t_catalogue ) {
		$t_topics = array();
		$t_all = topic_get_all( (int)$t_catalogue['id'] );
		foreach( $t_all as $t_topic ) {
			$t_topics[] = array(
				'code' => $t_topic['code'],
				'title' => $t_topic['title'],
				'parent' => $t_all[(int)$t_topic['parent_id']]['code'] ?? null,
				'description' => $t_topic['description'],
				'retired' => (int)$t_topic['enabled'] != 1,
			);
		}
		$t_status = (int)$t_catalogue['status'];
		$t_catalogues[] = array(
			'id' => (int)$t_catalogue['id'],
			'name' => $t_catalogue['name'],
			'revision' => $t_catalogue['revision'],
			'status' => $t_status == TOPIC_CATALOGUE_PUBLISHED ? 'published' : ( $t_status == TOPIC_CATALOGUE_RETIRED ? 'retired' : 'draft' ),
			'source_document_id' => (int)$t_catalogue['source_dwg_id'] ?: null,
			'topics' => $t_topics,
		);
	}
	return $p_response->withStatus( HTTP_STATUS_SUCCESS )->withJson( array( 'catalogues' => $t_catalogues ) );
}

/**
 * GET /topics/{project_id}?milestone_id=
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 */
function rest_topic_coverage( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_project_id = (int)$p_args['project_id'];
	if( !project_exists( $t_project_id ) || !topic_project_is_enabled( $t_project_id ) || !topic_can_view_project( $t_project_id ) ) {
		return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, "Topics of project '$t_project_id' not found" );
	}
	try {
		$t_view = topic_coverage_view( $t_project_id, (int)$p_request->getParam( 'milestone_id', 0 ) );
	} catch( ClientException $e ) {
		return rest_topic_error( $p_response, $e );
	}

	$t_topics = array();
	foreach( $t_view['topics'] as $t_topic ) {
		$t_documents = array();
		foreach( $t_topic['tags'] as $t_tag ) {
			$t_document = $t_tag['document'];
			$t_documents[] = array(
				'id' => (int)$t_document['id'],
				'number' => $t_document['number'],
				'title' => $t_document['title'],
				'status' => get_enum_element( 'dwg_status', (int)$t_document['status'] ),
				'extent' => (int)$t_tag['extent'] == TOPIC_EXTENT_WHOLE ? 'whole' : 'part',
				'sections' => $t_tag['locator'],
				'inactive' => (bool)$t_tag['inactive'],
				'shared_from_project_id' => (int)$t_document['project_id'] != $t_project_id ? (int)$t_document['project_id'] : null,
			);
		}
		if( $t_topic['restricted'] ) {
			$t_documents[] = array( 'restricted' => true );
		}
		$t_expectations = array();
		foreach( $t_topic['expectations'] as $t_expectation ) {
			$t_evidence = array();
			foreach( $t_expectation['evidence_visible'] as $t_item ) {
				$t_evidence[] = array(
					'id' => (int)$t_item['id'], 'number' => $t_item['number'], 'title' => $t_item['title'],
					'status' => get_enum_element( 'dwg_status', (int)$t_item['status'] ),
					'version' => $t_item['version'], 'sections' => $t_item['locator'],
				);
			}
			if( count( $t_expectation['evidence_visible'] ) < count( $t_expectation['evidence'] ) ) {
				$t_evidence[] = array( 'restricted' => true );
			}
			$t_a = $t_expectation['assessment'];
			$t_expectations[] = array(
				'ref' => topic_expectation_ref( $t_expectation ),
				'statement' => $t_expectation['statement'],
				'owner' => topic_user_name( (int)$t_expectation['owner_id'] ),
				'due_from' => $t_view['milestones'][(int)$t_expectation['milestone_id']]['name'] ?? null,
				'maturity' => rest_topic_maturity_key( (int)$t_expectation['maturity'] ),
				'revision' => (int)$t_expectation['revision'],
				'state' => $t_expectation['state'],
				'reasons' => $t_expectation['reasons'],
				'evidence' => $t_evidence,
				'assessment' => $t_a === null ? null : array(
					'finding' => (int)$t_a['finding'] == TOPIC_FINDING_GAP ? 'gap' : 'adequate',
					'rationale' => $t_a['rationale'],
					'assessor' => topic_user_name( (int)$t_a['user_id'] ),
					'date' => rest_topic_date( (int)$t_a['date_assessed'] ),
					'expectation_revision' => (int)$t_a['expectation_revision'],
				),
			);
		}
		$t_topics[] = array(
			'code' => $t_topic['code'],
			'title' => $t_topic['title'],
			'parent' => $t_view['topics'][(int)$t_topic['parent_id']]['code'] ?? null,
			'retired' => (int)$t_topic['enabled'] != 1,
			'applicability' => rest_topic_applicability_key( (int)$t_topic['applicability'] ),
			'rationale' => $t_topic['decision']['rationale'] ?? '',
			'declared_coverage' => $t_topic['state'],
			'expectation_state' => $t_topic['expectation_state'],
			'documents' => $t_documents,
			'expectations' => $t_expectations,
		);
	}

	$t_catalogue = $t_view['catalogue'];
	$t_profile = $t_view['profile'];
	$t_milestone = $t_view['milestones'][$t_view['milestone_id']] ?? null;
	return $p_response->withStatus( HTTP_STATUS_SUCCESS )->withJson( array(
		'project' => array( 'id' => $t_project_id, 'name' => project_get_name( $t_project_id ) ),
		'catalogue' => array( 'id' => (int)$t_catalogue['id'], 'name' => $t_catalogue['name'], 'revision' => $t_catalogue['revision'] ),
		'milestone' => $t_milestone === null ? null : array( 'id' => (int)$t_view['milestone_id'], 'name' => $t_milestone['name'] ),
		'readiness' => $t_view['readiness'],
		'profile' => array(
			'revision' => $t_profile['current'] === null ? null : (int)$t_profile['current']['revision'],
			'authorised_by' => $t_profile['current'] === null ? null : topic_user_name( (int)$t_profile['current']['user_id'] ),
			'authorised_at' => $t_profile['current'] === null ? null : rest_topic_date( (int)$t_profile['current']['date_authorised'] ),
			'changed_since_authorisation' => (bool)$t_profile['changed'],
		),
		'restricted' => (bool)$t_view['restricted'],
		'live' => true,
		'topics' => $t_topics,
	) );
}

/**
 * GET /topics/{project_id}/snapshots
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 */
function rest_topic_snapshots( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_project_id = (int)$p_args['project_id'];
	if( !project_exists( $t_project_id ) || !topic_can_view_project( $t_project_id ) ) {
		return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, "Topics of project '$t_project_id' not found" );
	}
	$t_snapshots = array();
	foreach( topic_snapshot_get_all( $t_project_id ) as $t_row ) {
		$t_snapshots[] = array(
			'id' => (int)$t_row['id'],
			'title' => $t_row['title'],
			'milestone' => $t_row['milestone_name'],
			'readiness' => $t_row['readiness'],
			'profile_revision' => (int)$t_row['profile_revision'] ?: null,
			'taken_by' => topic_user_name( (int)$t_row['user_id'] ),
			'taken_at' => rest_topic_date( (int)$t_row['date_created'] ),
		);
	}
	return $p_response->withStatus( HTTP_STATUS_SUCCESS )->withJson( array( 'snapshots' => $t_snapshots ) );
}

/**
 * GET /topics/snapshots/{id}
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 */
function rest_topic_snapshot( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	try {
		$t_snapshot = topic_snapshot_get( (int)$p_args['id'] );
	} catch( ClientException $e ) {
		return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, 'Snapshot not found' );
	}
	$t_project_id = (int)$t_snapshot['project_id'];
	if( !topic_can_view_project( $t_project_id ) ) {
		return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, 'Snapshot not found' );
	}
	$t_content = is_array( $t_snapshot['content'] ) ? $t_snapshot['content'] : array( 'topics' => array() );
	$t_topics = array();
	foreach( topic_snapshot_reader_topics( $t_content ) as $t_topic ) {
		$t_reference = function( array $p_item, array $p_fields ) {
			if( !$p_item['visible'] ) {
				return array( 'restricted' => true );
			}
			$t_out = array( 'id' => (int)$p_item['dwg_id'], 'label' => $p_item['label'], 'status' => $p_item['status_label'] );
			foreach( $p_fields as $t_out_key => $t_key ) {
				$t_out[$t_out_key] = $p_item[$t_key];
			}
			return $t_out;
		};
		$t_documents = array();
		foreach( $t_topic['tags'] as $t_tag ) {
			$t_documents[] = $t_reference( $t_tag, array( 'sections' => 'locator', 'inactive' => 'inactive', 'version' => 'version' ) )
				+ ( $t_tag['visible'] ? array( 'extent' => (int)$t_tag['extent'] == TOPIC_EXTENT_WHOLE ? 'whole' : 'part' ) : array() );
		}
		$t_expectations = array();
		foreach( $t_topic['expectations'] as $t_expectation ) {
			$t_evidence = array();
			foreach( $t_expectation['evidence'] as $t_item ) {
				$t_evidence[] = $t_reference( $t_item, array( 'version' => 'version', 'sections' => 'locator' ) );
			}
			$t_a = $t_expectation['assessment'];
			$t_expectations[] = array(
				'ref' => $t_expectation['ref'],
				'statement' => $t_expectation['statement'],
				'owner' => $t_expectation['owner'],
				'due_from' => $t_expectation['due'],
				'maturity' => rest_topic_maturity_key( (int)$t_expectation['maturity'] ),
				'revision' => (int)$t_expectation['revision'],
				'state' => $t_expectation['state'],
				'reasons' => $t_expectation['reasons'],
				'evidence' => $t_evidence,
				'assessment' => $t_a === null ? null : array(
					'finding' => (int)$t_a['finding'] == TOPIC_FINDING_GAP ? 'gap' : 'adequate',
					'rationale' => $t_a['rationale'],
					'assessor' => $t_a['assessor'],
					'date' => rest_topic_date( (int)$t_a['date'] ),
					'expectation_revision' => (int)$t_a['expectation_revision'],
				),
			);
		}
		$t_topics[] = array(
			'code' => $t_topic['code'],
			'title' => $t_topic['title'],
			'parent' => $t_topic['parent'] !== '' ? $t_topic['parent'] : null,
			'retired' => !$t_topic['enabled'],
			'applicability' => rest_topic_applicability_key( (int)$t_topic['applicability'] ),
			'rationale' => $t_topic['rationale'],
			'declared_coverage' => $t_topic['declared_state'],
			'expectation_state' => $t_topic['expectation_state'],
			'documents' => $t_documents,
			'expectations' => $t_expectations,
		);
	}
	return $p_response->withStatus( HTTP_STATUS_SUCCESS )->withJson( array(
		'snapshot' => array(
			'id' => (int)$t_snapshot['id'],
			'title' => $t_snapshot['title'],
			'taken_by' => topic_user_name( (int)$t_snapshot['user_id'] ),
			'taken_at' => rest_topic_date( (int)$t_snapshot['date_created'] ),
			'intact' => (bool)$t_snapshot['intact'],
			'sha256' => $t_snapshot['data_hash'],
		),
		'project' => array( 'id' => $t_project_id, 'name' => project_get_name( $t_project_id ) ),
		'catalogue' => $t_content['catalogue']['label'] ?? $t_snapshot['catalogue_label'],
		'milestone' => $t_snapshot['milestone_name'] !== '' ? $t_snapshot['milestone_name'] : null,
		'readiness' => $t_snapshot['readiness'],
		'profile_revision' => (int)$t_snapshot['profile_revision'] ?: null,
		'live' => false,
		'topics' => $t_topics,
	) );
}
