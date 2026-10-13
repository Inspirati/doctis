<?php
# Doctis Documentation Topics phase 2 tests (doc/topics/TOPICS_PLAN.md §7):
# milestones, expectations, evidence, assessments, states, profile
# authorisation. Helpers and cleanup: TopicTestCase.

namespace Mantis\tests\Mantis;

require_api( 'topic_expectation_api.php' );

class TopicExpectationApiTest extends TopicTestCase {
	private $license_ids = array();
	private $assessor_licenses_saved;

	protected function setUp(): void {
		parent::setUp();
		$this->assessor_licenses_saved = config_get_global( 'topic_assessor_licenses' );
	}

	protected function tearDown(): void {
		foreach( $this->license_ids as $t_id ) {
			db_query( 'DELETE FROM {license_user_list} WHERE license_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {license} WHERE id=' . db_param(), array( $t_id ) );
		}
		config_set_global( 'topic_assessor_licenses', $this->assessor_licenses_saved );
		parent::tearDown();
	}

	/** A Topics project with the default milestones; returns catalogue ids plus project_id, milestones (ids in order). */
	private function makeProjectWithMilestones(): array {
		$t_cat = $this->makeTopicProject();
		topic_milestone_load_defaults( $t_cat['project_id'] );
		$t_cat['milestones'] = array_keys( topic_milestone_get_all( $t_cat['project_id'] ) );
		return $t_cat;
	}

	/** State of one expectation at a milestone, from fresh data. */
	private function state( int $p_expectation_id, int $p_milestone_id ): array {
		$t_expectation = topic_expectation_get( $p_expectation_id );
		$t_history = topic_assessment_history( $p_expectation_id );
		return topic_expectation_state( $t_expectation, topic_milestone_get_all( (int)$t_expectation['project_id'] ),
			$p_milestone_id, topic_evidence_get_all( $p_expectation_id ), $t_history[0] ?? null );
	}

	private function setRevision( int $p_dwg_id, string $p_revision ): void {
		$t_document_id = (int)db_result( db_query( 'SELECT document_id FROM {dwg} WHERE id=' . db_param(), array( $p_dwg_id ) ) );
		db_query( 'UPDATE {documents} SET revision=' . db_param() . ' WHERE id=' . db_param(), array( $p_revision, $t_document_id ) );
		dwg_clear_cache_all( $p_dwg_id );
	}

	# ── Milestones ───────────────────────────────────────────────────────────

	public function testDefaultMilestonesOrderAndCurrent() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_milestones = topic_milestone_get_all( $t_cat['project_id'] );
		$this->assertCount( 7, $t_milestones );
		$this->assertSame( 'Planning / ECP', reset( $t_milestones )['name'] );
		$this->assertSame( range( 0, 6 ), array_column( $t_milestones, 'position' ) );
		$this->assertEquals( $t_cat['milestones'][0], topic_milestone_current( $t_milestones ) );

		topic_milestone_set_achieved( $t_cat['milestones'][0], true );
		$this->assertEquals( $t_cat['milestones'][1], topic_milestone_current( topic_milestone_get_all( $t_cat['project_id'] ) ) );

		$this->expectTopicError( function() use ( $t_cat ) { topic_milestone_load_defaults( $t_cat['project_id'] ); }, 'already has milestones' );

		topic_expectation_create( $t_cat['project_id'], $t_cat['10'], 'Requirements baseline', 0, $t_cat['milestones'][2], TOPIC_MATURITY_DRAFT );
		$this->expectTopicError( function() use ( $t_cat ) { topic_milestone_delete( $t_cat['milestones'][2] ); }, 'used by expectations' );
		topic_milestone_delete( $t_cat['milestones'][6] );
		$this->assertCount( 6, topic_milestone_get_all( $t_cat['project_id'] ) );
	}

	# ── Expectations ─────────────────────────────────────────────────────────

	public function testExpectationRevisionsAndValidation() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_id = topic_expectation_create( $t_project_id, $t_cat['10'], 'Stakeholder needs', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		$this->assertEquals( 1, (int)topic_expectation_get( $t_id )['revision'] );

		$this->assertFalse( topic_expectation_update( $t_id, ' Stakeholder needs ', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT ) );
		$this->assertTrue( topic_expectation_update( $t_id, 'Stakeholder needs and intended use', 0, $t_cat['milestones'][1], TOPIC_MATURITY_APPROVED ) );
		topic_expectation_set_enabled( $t_id, false );
		$this->assertEquals( 3, (int)topic_expectation_get( $t_id )['revision'] );
		$this->assertSame( array( 3, 2, 1 ), array_map( 'intval', array_column( topic_expectation_revisions( $t_id ), 'revision' ) ) );
		$this->assertCount( 0, topic_expectation_get_all( $t_project_id ) );

		$t_other = $this->makeProjectWithMilestones();
		$this->expectTopicError( function() use ( $t_project_id, $t_cat, $t_other ) {
			topic_expectation_create( $t_project_id, $t_cat['10'], 'X', 0, $t_other['milestones'][0], TOPIC_MATURITY_DRAFT );
		}, 'another project' );
		$this->expectTopicError( function() use ( $t_project_id, $t_cat ) {
			topic_expectation_create( $t_project_id, $t_cat['10'], 'X', 0, $t_cat['milestones'][0], 99 );
		}, 'maturity' );
		$this->expectTopicError( function() use ( $t_project_id, $t_cat ) {
			topic_expectation_create( $t_project_id, $t_cat['10'], '  ', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		}, 'statement' );
	}

	# ── States ───────────────────────────────────────────────────────────────

	public function testExpectationStateMachine() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_m = $t_cat['milestones'];
		# Due from SRR/PDR (second milestone), Approved required.
		$t_id = topic_expectation_create( $t_project_id, $t_cat['10'], 'Approved requirements', 0, $t_m[1], TOPIC_MATURITY_APPROVED );

		$this->assertSame( TOPIC_EXP_NOT_DUE, $this->state( $t_id, $t_m[0] )['state'] );
		$this->assertSame( TOPIC_EXP_MISSING, $this->state( $t_id, $t_m[1] )['state'] );

		$t_prd = $this->makeDocument( $t_project_id, PENDING, 'PRD-T' );
		$this->expectTopicError( function() use ( $t_id, $t_prd ) { topic_evidence_add( $t_id, $t_prd, '' ); }, 'tag the document' );
		topic_dwg_add( $t_prd, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );
		topic_evidence_add( $t_id, $t_prd, '§2' );

		$t_state = $this->state( $t_id, $t_m[1] );
		$this->assertSame( TOPIC_EXP_PENDING, $t_state['state'] );
		$this->assertCount( 2, $t_state['reasons'] ); # assessment required; Draft but Approved required

		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'Covers needs and constraints.', $t_m[1] );
		$t_state = $this->state( $t_id, $t_m[1] );
		$this->assertSame( TOPIC_EXP_PENDING, $t_state['state'] );
		$this->assertCount( 1, $t_state['reasons'] );

		# Approval of the assessed version satisfies it (status is not a dependency).
		$this->setStatus( $t_prd, ACCEPTED );
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_id, $t_m[1] )['state'] );
		# Later milestones too.
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_id, $t_m[4] )['state'] );

		# A new version of the evidence needs reassessment.
		$this->setRevision( $t_prd, 'B' );
		$this->assertSame( TOPIC_EXP_REASSESS, $this->state( $t_id, $t_m[1] )['state'] );
		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'Revision B still covers it.', $t_m[1] );
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_id, $t_m[1] )['state'] );

		# More evidence changes the evidence set.
		$t_con = $this->makeDocument( $t_project_id, ACCEPTED, 'CONOPS-T' );
		topic_dwg_add( $t_con, $t_cat['10'], TOPIC_EXTENT_PART, '' );
		topic_evidence_add( $t_id, $t_con, '' );
		$this->assertSame( TOPIC_EXP_REASSESS, $this->state( $t_id, $t_m[1] )['state'] );

		# Gap.
		topic_assessment_record( $t_id, TOPIC_FINDING_GAP, 'Intended use missing.', $t_m[1] );
		$t_state = $this->state( $t_id, $t_m[1] );
		$this->assertSame( TOPIC_EXP_GAP, $t_state['state'] );
		$this->assertSame( array( 'Intended use missing.' ), $t_state['reasons'] );

		# Inactive evidence cannot count.
		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'Resolved.', $t_m[1] );
		$this->setStatus( $t_con, ARCHIVED );
		$this->assertSame( TOPIC_EXP_PENDING, $this->state( $t_id, $t_m[1] )['state'] );

		# A status with no maturity mapping fails closed.
		$this->setStatus( $t_con, 10 );
		$t_state = $this->state( $t_id, $t_m[1] );
		$this->assertSame( TOPIC_EXP_PENDING, $t_state['state'] );
		$this->assertStringContainsString( 'no maturity mapping', $t_state['reasons'][0] );
		$this->setStatus( $t_con, ACCEPTED );
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_id, $t_m[1] )['state'] );

		# Changing the expectation needs reassessment.
		topic_expectation_update( $t_id, 'Approved requirements and intended use', 0, $t_m[1], TOPIC_MATURITY_APPROVED );
		$this->assertSame( TOPIC_EXP_REASSESS, $this->state( $t_id, $t_m[1] )['state'] );

		# Removing the tag removes the evidence link, with history.
		$t_tag = topic_dwg_get_for_dwg( $t_con )[0];
		topic_dwg_remove( (int)$t_tag['id'] );
		$this->assertArrayNotHasKey( $t_con, topic_evidence_get_all( $t_id ) );
		$this->assertContains( DWG_TOPIC_EVIDENCE_REMOVED, $this->historyTypes( $t_con ) );

		# No assessment without evidence or rationale.
		$this->expectTopicError( function() use ( $t_id ) { topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, ' ' ); }, 'rationale' );
	}

	public function testMaturityOrdering() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_dwg = $this->makeDocument( $t_project_id, REVIEW, 'MAT-T' );
		topic_dwg_add( $t_dwg, $t_cat['20'], TOPIC_EXTENT_PART, '' );
		$t_draft = topic_expectation_create( $t_project_id, $t_cat['20'], 'Draft design', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		$t_review = topic_expectation_create( $t_project_id, $t_cat['20'], 'Reviewed design', 0, $t_cat['milestones'][0], TOPIC_MATURITY_UNDER_REVIEW );
		$t_approved = topic_expectation_create( $t_project_id, $t_cat['20'], 'Approved design', 0, $t_cat['milestones'][0], TOPIC_MATURITY_APPROVED );
		foreach( array( $t_draft, $t_review, $t_approved ) as $t_id ) {
			topic_evidence_add( $t_id, $t_dwg, '' );
			topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'OK', $t_cat['milestones'][0] );
		}
		# Under Review satisfies Draft and Under Review, not Approved.
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_draft, $t_cat['milestones'][0] )['state'] );
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_review, $t_cat['milestones'][0] )['state'] );
		$this->assertSame( TOPIC_EXP_PENDING, $this->state( $t_approved, $t_cat['milestones'][0] )['state'] );
		# The owner's decisions: rework (165) is Draft, accepted (180) Approved.
		$this->assertSame( TOPIC_MATURITY_DRAFT, topic_status_maturity( REWORK, $t_project_id ) );
		$this->assertSame( TOPIC_MATURITY_UNDER_REVIEW, topic_status_maturity( REVIEW, $t_project_id ) );
		$this->assertSame( TOPIC_MATURITY_APPROVED, topic_status_maturity( ACCEPTED, $t_project_id ) );
		$this->assertNull( topic_status_maturity( ARCHIVED, $t_project_id ) );
	}

	# ── Topic state, readiness and authorisation ─────────────────────────────

	public function testProfileAuthorisationAndReadiness() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_m = $t_cat['milestones'];
		$t_user_id = auth_get_current_user_id();

		$t_view = topic_coverage_view( $t_project_id, $t_m[0] );
		$this->assertSame( TOPIC_PROFILE_UNRESOLVED, $t_view['readiness'] );
		$this->assertSame( TOPIC_EXP_NONE, $t_view['topics'][$t_cat['10']]['expectation_state'] );

		$t_blockers = topic_profile_blockers( $t_project_id );
		$this->assertCount( 1, $t_blockers );
		$this->assertStringContainsString( '10, 20, 21', $t_blockers[0] );

		topic_applicability_set( $t_project_id, $t_cat['10'], TOPIC_APPLICABLE, '' );
		topic_applicability_set( $t_project_id, $t_cat['20'], TOPIC_APPLICABLE, '' );
		topic_applicability_set( $t_project_id, $t_cat['21'], TOPIC_NOT_APPLICABLE, 'No hardware.' );
		$t_e10 = topic_expectation_create( $t_project_id, $t_cat['10'], 'Requirements', 0, $t_m[0], TOPIC_MATURITY_DRAFT );
		$t_e20 = topic_expectation_create( $t_project_id, $t_cat['20'], 'Architecture', $t_user_id, $t_m[2], TOPIC_MATURITY_DRAFT );
		$t_blockers = topic_profile_blockers( $t_project_id );
		$this->assertCount( 1, $t_blockers );
		$this->assertStringContainsString( 'owner', $t_blockers[0] );
		topic_expectation_update( $t_e10, 'Requirements', $t_user_id, $t_m[0], TOPIC_MATURITY_DRAFT );

		$this->assertSame( 1, topic_profile_authorise( $t_project_id, 'Pilot profile' ) );
		$t_view = topic_coverage_view( $t_project_id, $t_m[0] );
		$this->assertFalse( $t_view['profile']['changed'] );
		# 10 is due and Missing; 20 is not due yet.
		$this->assertSame( TOPIC_NOT_READY, $t_view['readiness'] );
		$this->assertSame( TOPIC_EXP_INCOMPLETE, $t_view['topics'][$t_cat['10']]['expectation_state'] );
		$this->assertSame( TOPIC_EXP_NOT_DUE, $t_view['topics'][$t_cat['20']]['expectation_state'] );
		$this->assertSame( TOPIC_STATE_NOT_APPLICABLE, $t_view['topics'][$t_cat['21']]['expectation_state'] );

		$t_dwg = $this->makeDocument( $t_project_id, PENDING, 'REQ-T' );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );
		topic_evidence_add( $t_e10, $t_dwg, '' );
		topic_assessment_record( $t_e10, TOPIC_FINDING_ADEQUATE, 'Complete draft.', $t_m[0] );
		$t_view = topic_coverage_view( $t_project_id, $t_m[0] );
		$this->assertSame( TOPIC_READY, $t_view['readiness'] );
		$this->assertSame( TOPIC_EXP_SATISFIED, $t_view['topics'][$t_cat['10']]['expectation_state'] );
		# Evidence and assessments are not profile changes; a child never affects its parent.
		$this->assertFalse( $t_view['profile']['changed'] );

		# At CDR the architecture expectation is due and missing.
		$this->assertSame( TOPIC_NOT_READY, topic_coverage_view( $t_project_id, $t_m[2] )['readiness'] );

		# Achieving a milestone is not a profile change; editing an expectation is.
		topic_milestone_set_achieved( $t_m[0], true );
		$this->assertFalse( topic_profile_status( $t_project_id )['changed'] );
		topic_expectation_update( $t_e20, 'Architecture and interfaces', $t_user_id, $t_m[2], TOPIC_MATURITY_DRAFT );
		$this->assertTrue( topic_profile_status( $t_project_id )['changed'] );
		$this->assertSame( TOPIC_PROFILE_UNRESOLVED, topic_coverage_view( $t_project_id, $t_m[0] )['readiness'] );
		$this->assertSame( 2, topic_profile_authorise( $t_project_id ) );
		$this->assertFalse( topic_profile_status( $t_project_id )['changed'] );
	}

	public function testAssessorLicenseRequirement() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_user_id = auth_get_current_user_id();
		$this->assertTrue( topic_can_assess( $t_project_id, '10' ) );

		$t_name = 'TopicTest Assessor ' . rand();
		config_set_global( 'topic_assessor_licenses', array( '*' => $t_name ) );
		# An administrator without the license may not assess.
		$this->assertFalse( topic_can_assess( $t_project_id, '10' ) );

		db_query( 'INSERT INTO {license} ( project_id, enabled, name, match_str, type, status, view_state, access_min, description )
			VALUES ( 0, 1, ' . db_param() . ', ' . db_param() . ', \'Assessor\', 10, 10, 10, \'\' )', array( $t_name, md5( $t_name ) ) );
		$t_license_id = (int)db_insert_id( db_get_table( 'license' ) );
		$this->license_ids[] = $t_license_id;
		db_query( 'INSERT INTO {license_user_list} ( user_id, license_id, status, date_added ) VALUES ( '
			. db_param() . ', ' . db_param() . ', 10, 1 )', array( $t_user_id, $t_license_id ) );
		# Applied for, not granted.
		$this->assertFalse( topic_can_assess( $t_project_id, '10' ) );
		db_query( 'UPDATE {license_user_list} SET status=20 WHERE license_id=' . db_param(), array( $t_license_id ) );
		$this->assertTrue( topic_can_assess( $t_project_id, '10' ) );

		# A Topic-specific license overrides '*'.
		config_set_global( 'topic_assessor_licenses', array( '*' => $t_name, '23' => 'TopicTest Safety ' . rand() ) );
		$this->assertTrue( topic_can_assess( $t_project_id, '10' ) );
		$this->assertFalse( topic_can_assess( $t_project_id, '23' ) );
	}

	public function testExportRowsAndHistoryWording() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_id = topic_expectation_create( $t_project_id, $t_cat['10'], 'Requirements', auth_get_current_user_id(), $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		$t_dwg = $this->makeDocument( $t_project_id, PENDING, 'EXP-REQ' );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );
		topic_evidence_add( $t_id, $t_dwg, '§3' );
		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'Fine.', $t_cat['milestones'][0] );

		$t_rows = topic_export_expectation_rows( topic_coverage_view( $t_project_id, $t_cat['milestones'][0] ) );
		$this->assertCount( 1, $t_rows );
		$this->assertSame( 'E' . $t_id, $t_rows[0]['ref'] );
		$this->assertSame( topic_exp_state_label( TOPIC_EXP_SATISFIED ), $t_rows[0]['state'] );
		$this->assertStringContainsString( 'EXP-REQ', $t_rows[0]['evidence'] );
		$this->assertStringContainsString( '§3', $t_rows[0]['evidence'] );
		$this->assertStringStartsWith( topic_finding_label( TOPIC_FINDING_ADEQUATE ), $t_rows[0]['assessment'] );

		$t_expectation = topic_expectation_get( $t_id );
		$this->assertSame( sprintf( lang_get( 'topic_expectation_of_topic' ), 'E' . $t_id, '10' ) . ': §3',
			topic_evidence_history_describe( topic_evidence_history_value( $t_expectation, '§3' ) ) );
		$this->assertContains( DWG_TOPIC_EVIDENCE_ADDED, $this->historyTypes( $t_dwg ) );
	}

	# ── Phase 3: conflicts, activity, versioned evidence ─────────────────────

	public function testStaleWritesAreRefused() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_user_id = auth_get_current_user_id();

		# Applicability: the form saw "no decision"; someone decided meanwhile.
		topic_applicability_set( $t_project_id, $t_cat['10'], TOPIC_APPLICABLE, '' );
		$this->expectTopicError( function() use ( $t_project_id, $t_cat ) {
			topic_applicability_set( $t_project_id, $t_cat['10'], TOPIC_NOT_APPLICABLE, 'x', 0 );
		}, 'changed by someone else' );
		$t_current = (int)topic_applicability_current( $t_project_id )[$t_cat['10']]['id'];
		$this->assertTrue( topic_applicability_set( $t_project_id, $t_cat['10'], TOPIC_NOT_APPLICABLE, 'x', $t_current ) );

		# Expectation edit based on revision 1 after it reached revision 2.
		$t_id = topic_expectation_create( $t_project_id, $t_cat['20'], 'Design', $t_user_id, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		topic_expectation_update( $t_id, 'Design v2', $t_user_id, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT, 1 );
		$this->expectTopicError( function() use ( $t_id, $t_user_id, $t_cat ) {
			topic_expectation_update( $t_id, 'Design v3', $t_user_id, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT, 1 );
		}, 'changed by someone else' );
		$this->expectTopicError( function() use ( $t_id ) { topic_expectation_set_enabled( $t_id, false, 1 ); }, 'changed by someone else' );

		# Assessment: the assessor saw one evidence set; another document was added.
		$t_a = $this->makeDocument( $t_project_id );
		$t_b = $this->makeDocument( $t_project_id );
		topic_dwg_add( $t_a, $t_cat['20'], TOPIC_EXTENT_PART, '' );
		topic_dwg_add( $t_b, $t_cat['20'], TOPIC_EXTENT_PART, '' );
		topic_evidence_add( $t_id, $t_a, '' );
		$t_basis = topic_assessment_basis( $t_id );
		topic_evidence_add( $t_id, $t_b, '' );
		$this->expectTopicError( function() use ( $t_id, $t_basis ) {
			topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'OK', 0, $t_basis );
		}, 'changed by someone else' );
		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'OK', 0, topic_assessment_basis( $t_id ) );

		# Profile: authorise exactly what was shown.
		foreach( array( '21' ) as $t_code ) {
			topic_applicability_set( $t_project_id, $t_cat[$t_code], TOPIC_NOT_APPLICABLE, 'n/a' );
		}
		topic_applicability_set( $t_project_id, $t_cat['20'], TOPIC_APPLICABLE, '' );
		$t_print = topic_profile_fingerprint( $t_project_id );
		topic_expectation_create( $t_project_id, $t_cat['20'], 'Interfaces', $t_user_id, $t_cat['milestones'][1], TOPIC_MATURITY_DRAFT );
		$this->expectTopicError( function() use ( $t_project_id, $t_print ) {
			topic_profile_authorise( $t_project_id, '', $t_print );
		}, 'changed by someone else' );
		$this->assertSame( 1, topic_profile_authorise( $t_project_id, '', topic_profile_fingerprint( $t_project_id ) ) );
	}

	public function testActivityLogRecordsActions() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_id = topic_expectation_create( $t_project_id, $t_cat['10'], 'Requirements', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		$t_dwg = $this->makeDocument( $t_project_id, PENDING, 'ACT-1' );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );
		topic_evidence_add( $t_id, $t_dwg, '' );
		topic_assessment_record( $t_id, TOPIC_FINDING_GAP, 'Thin.' );
		topic_applicability_set( $t_project_id, $t_cat['10'], TOPIC_APPLICABLE, '' );

		$t_actions = array_column( topic_event_get( $t_project_id ), 'action' );
		foreach( array( 'enable', 'milestone_add', 'expectation_add', 'tag_add', 'evidence_add', 'assessment', 'applicability' ) as $t_action ) {
			$this->assertContains( $t_action, $t_actions );
		}
		$this->assertSame( 'applicability', $t_actions[0] );
		$this->assertGreaterThan( 0, topic_event_count( $t_project_id ) );
	}

	public function testUnversionedEvidenceStaysPending() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_id = topic_expectation_create( $t_project_id, $t_cat['10'], 'Standard reference', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		$t_dwg = $this->makeDocument( $t_project_id, PENDING, 'EXT-STD' );
		$this->setRevision( $t_dwg, '' );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_PART, '' );
		topic_evidence_add( $t_id, $t_dwg, '' );
		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'Right standard.' );

		$t_state = $this->state( $t_id, $t_cat['milestones'][0] );
		$this->assertSame( TOPIC_EXP_PENDING, $t_state['state'] );
		$this->assertStringContainsString( 'no identifiable version', $t_state['reasons'][0] );
		$this->assertFalse( topic_evidence_version_is_identifiable( 'rev:/' ) );
		$this->assertTrue( topic_evidence_version_is_identifiable( 'rev:/B' ) );
		$this->assertTrue( topic_evidence_version_is_identifiable( 'sha:0123abcd' ) );

		# Citing a revision makes it identifiable (and the version change calls for reassessment).
		$this->setRevision( $t_dwg, '2024' );
		$this->assertSame( TOPIC_EXP_REASSESS, $this->state( $t_id, $t_cat['milestones'][0] )['state'] );
		topic_assessment_record( $t_id, TOPIC_FINDING_ADEQUATE, 'Right standard, 2024 edition.' );
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->state( $t_id, $t_cat['milestones'][0] )['state'] );
	}

	public function testProjectDeletionRemovesPhase2Records() {
		$t_cat = $this->makeProjectWithMilestones();
		$t_project_id = $t_cat['project_id'];
		$t_id = topic_expectation_create( $t_project_id, $t_cat['10'], 'Requirements', 0, $t_cat['milestones'][0], TOPIC_MATURITY_DRAFT );
		$t_dwg = $this->makeDocument( $t_project_id );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_PART, '' );
		topic_evidence_add( $t_id, $t_dwg, '' );
		topic_assessment_record( $t_id, TOPIC_FINDING_GAP, 'Thin.' );

		topic_project_delete( $t_project_id );
		foreach( array( '{topic_milestone}', '{topic_expectation}', '{topic_profile}', '{topic_event}', '{topic_snapshot}' ) as $t_table ) {
			$this->assertEquals( 0, (int)db_result( db_query( 'SELECT COUNT(*) FROM ' . $t_table . ' WHERE project_id=' . db_param(), array( $t_project_id ) ) ) );
		}
		foreach( array( '{topic_evidence}', '{topic_assessment}', '{topic_expectation_revision}' ) as $t_table ) {
			$this->assertEquals( 0, (int)db_result( db_query( 'SELECT COUNT(*) FROM ' . $t_table . ' WHERE expectation_id=' . db_param(), array( $t_id ) ) ) );
		}
	}
}
