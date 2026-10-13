<?php
# Doctis Documentation Topics phase 3 snapshot tests (TOP-FR-022/023):
# frozen content, integrity hash, unresolved states, redaction helpers.
# Helpers and cleanup: TopicTestCase.

namespace Mantis\tests\Mantis;

require_api( 'topic_snapshot_api.php' );

class TopicSnapshotApiTest extends TopicTestCase {

	/** A Topics project with milestones, one Approved expectation on Topic 10 with assessed evidence. */
	private function makeScenario(): array {
		$t_cat = $this->makeTopicProject();
		$t_project_id = $t_cat['project_id'];
		topic_milestone_load_defaults( $t_project_id );
		$t_cat['milestones'] = array_keys( topic_milestone_get_all( $t_project_id ) );
		$t_cat['expectation'] = topic_expectation_create( $t_project_id, $t_cat['10'], 'Approved requirements',
			auth_get_current_user_id(), $t_cat['milestones'][0], TOPIC_MATURITY_APPROVED );
		$t_cat['dwg'] = $this->makeDocument( $t_project_id, ACCEPTED, 'SNAP-PRD' );
		topic_dwg_add( $t_cat['dwg'], $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );
		topic_evidence_add( $t_cat['expectation'], $t_cat['dwg'], '§1' );
		topic_assessment_record( $t_cat['expectation'], TOPIC_FINDING_ADEQUATE, 'Complete.' );
		return $t_cat;
	}

	private function expectationOf( array $p_content, string $p_code ): array {
		foreach( $p_content['topics'] as $t_topic ) {
			if( $t_topic['code'] === $p_code ) {
				return $t_topic['expectations'][0];
			}
		}
		$this->fail( 'Topic ' . $p_code . ' not in snapshot' );
	}

	public function testSnapshotFreezesCoverageAndDetectsTampering() {
		$t_s = $this->makeScenario();
		$t_project_id = $t_s['project_id'];

		$t_id = topic_snapshot_capture( $t_project_id, $t_s['milestones'][0], 'Planning review' );
		$t_snapshot = topic_snapshot_get( $t_id );
		$this->assertTrue( $t_snapshot['intact'] );
		$this->assertSame( 'Planning / ECP', $t_snapshot['milestone_name'] );
		# Undecided Topics and an unauthorised profile are frozen as they are.
		$this->assertSame( TOPIC_PROFILE_UNRESOLVED, $t_snapshot['readiness'] );
		$t_frozen = $this->expectationOf( $t_snapshot['content'], '10' );
		$this->assertSame( TOPIC_EXP_SATISFIED, $t_frozen['state'] );
		$this->assertSame( 'rev:/A', $t_frozen['evidence'][0]['version'] );
		$this->assertContains( 'snapshot', array_column( topic_event_get( $t_project_id ), 'action' ) );

		# Live data moves on; the snapshot does not.
		$t_document_id = (int)db_result( db_query( 'SELECT document_id FROM {dwg} WHERE id=' . db_param(), array( $t_s['dwg'] ) ) );
		db_query( 'UPDATE {documents} SET revision=\'B\' WHERE id=' . db_param(), array( $t_document_id ) );
		dwg_clear_cache_all( $t_s['dwg'] );
		$t_live = topic_coverage_view( $t_project_id, $t_s['milestones'][0] );
		$this->assertSame( TOPIC_EXP_REASSESS, $t_live['topics'][$t_s['10']]['expectations'][$t_s['expectation']]['state'] );
		$t_again = topic_snapshot_get( $t_id );
		$this->assertSame( TOPIC_EXP_SATISFIED, $this->expectationOf( $t_again['content'], '10' )['state'] );

		# A record altered outside Doctis fails its integrity check.
		db_query( 'UPDATE {topic_snapshot} SET data=REPLACE( data, \'Complete.\', \'Changed.\' ) WHERE id=' . db_param(), array( $t_id ) );
		$this->assertFalse( topic_snapshot_get( $t_id )['intact'] );

		$this->assertCount( 1, topic_snapshot_get_all( $t_project_id ) );
		$this->expectTopicError( function() use ( $t_project_id ) { topic_snapshot_capture( $t_project_id, 0, ' ' ); }, 'title' );
	}

	public function testSnapshotExportRowsAndReaderView() {
		$t_s = $this->makeScenario();
		$t_id = topic_snapshot_capture( $t_s['project_id'], $t_s['milestones'][0], 'Export check' );
		$t_rows = topic_snapshot_export_rows( topic_snapshot_get( $t_id )['content'] );
		$this->assertContains( '10', array_column( $t_rows['topics'], 'code' ) );
		$this->assertCount( 1, $t_rows['expectations'] );
		$this->assertStringContainsString( 'SNAP-PRD', $t_rows['expectations'][0]['evidence'] );

		# A document deleted since the snapshot is no longer named.
		$t_view = topic_snapshot_document_view( array( 'dwg_id' => 999999999, 'label' => 'SECRET-DOC' ) );
		$this->assertFalse( $t_view['visible'] );
		$this->assertStringNotContainsString( 'SECRET', $t_view['text'] );
	}

	public function testReasonsNamingHiddenDocumentsAreRedacted() {
		$t_reasons = array( 'Evidence changed: HIDDEN-1 Secret plan has a new version.', 'Content assessment required.' );
		$t_redacted = topic_redact_reasons( $t_reasons, array( 'HIDDEN-1 Secret plan' ) );
		$this->assertSame( array( lang_get( 'topic_reason_restricted' ), 'Content assessment required.' ), $t_redacted );
		$this->assertSame( $t_reasons, topic_redact_reasons( $t_reasons, array() ) );
	}
}
