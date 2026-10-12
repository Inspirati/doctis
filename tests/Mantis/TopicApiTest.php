<?php
# Doctis Documentation Topics API tests (doc/topics/TOPICS_PLAN.md Phase 1).
#
# Uses the live database: creates throwaway projects, documents (rows only,
# no git files) and catalogues, and removes them in tearDown.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

require_api( 'topic_api.php' );

class TopicApiTest extends MantisCoreBase {
	private $project_ids = array();
	private $dwg_ids = array();
	private $document_ids = array();
	private $catalogue_ids = array();
	private $topics_enabled_saved;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->topics_enabled_saved = config_get_global( 'topics_enabled' );
		config_set_global( 'topics_enabled', ON );
	}

	protected function tearDown(): void {
		foreach( $this->dwg_ids as $t_id ) {
			db_query( 'DELETE FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {dwg_topic} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {dwg} WHERE id=' . db_param(), array( $t_id ) );
			dwg_clear_cache_all( $t_id );
		}
		# dwg_delete() leaves the {documents} row, so these are removed by id.
		foreach( $this->document_ids as $t_id ) {
			db_query( 'DELETE FROM {documents} WHERE id=' . db_param(), array( $t_id ) );
		}
		foreach( $this->project_ids as $t_id ) {
			topic_project_delete( $t_id );
			project_delete( $t_id );
		}
		foreach( $this->catalogue_ids as $t_id ) {
			db_query( 'DELETE FROM {topic} WHERE catalogue_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {topic_catalogue} WHERE id=' . db_param(), array( $t_id ) );
		}
		config_set_global( 'topics_enabled', $this->topics_enabled_saved );
		parent::tearDown();
	}

	private function makeProject(): int {
		$t_id = (int)project_create( 'TopicTest ' . rand(), '', 10 );
		$this->project_ids[] = $t_id;
		return $t_id;
	}

	/** A document row in a project (no primary file). */
	private function makeDocument( int $p_project_id, int $p_status = PENDING, string $p_number = '' ): int {
		db_query( 'INSERT INTO {documents} ( title, number ) VALUES ( ' . db_param() . ', ' . db_param() . ' )',
			array( 'Topic test document ' . rand(), $p_number ) );
		$t_document_id = db_insert_id( db_get_table( 'documents' ) );
		$this->document_ids[] = $t_document_id;
		db_query( 'INSERT INTO {dwg} ( project_id, document_id, status, date_submitted, last_updated )
			VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
			array( $p_project_id, $t_document_id, $p_status, db_now(), db_now() ) );
		$t_id = (int)db_insert_id( db_get_table( 'dwg' ) );
		$this->dwg_ids[] = $t_id;
		return $t_id;
	}

	private function setStatus( int $p_dwg_id, int $p_status ): void {
		db_query( 'UPDATE {dwg} SET status=' . db_param() . ' WHERE id=' . db_param(), array( $p_status, $p_dwg_id ) );
		dwg_clear_cache_all( $p_dwg_id );
	}

	/** A Draft catalogue named uniquely, with 10, 20 and child 21. */
	private function makeCatalogue(): array {
		$t_id = topic_catalogue_create( 'TEST-CAT-' . rand(), 'A' );
		$this->catalogue_ids[] = $t_id;
		$t_10 = topic_create( $t_id, '10', 0, 'Requirements', '' , 10 );
		$t_20 = topic_create( $t_id, '20', 0, 'Design', '', 20 );
		$t_21 = topic_create( $t_id, '21', $t_20, 'Hardware', '', 30 );
		return array( 'id' => $t_id, '10' => $t_10, '20' => $t_20, '21' => $t_21 );
	}

	/** A project with Topics enabled on a fresh catalogue. */
	private function makeTopicProject(): array {
		$t_catalogue = $this->makeCatalogue();
		$t_project_id = $this->makeProject();
		topic_project_enable( $t_project_id, $t_catalogue['id'] );
		$t_catalogue['project_id'] = $t_project_id;
		return $t_catalogue;
	}

	private function expectTopicError( callable $p_call, string $p_fragment ): void {
		try {
			$p_call();
		} catch( ClientException $e ) {
			$this->assertEquals( ERROR_TOPIC, $e->getCode() );
			$this->assertStringContainsString( $p_fragment, $e->getMessage() );
			return;
		}
		$this->fail( 'Expected a Topics error containing "' . $p_fragment . '"' );
	}

	private function historyTypes( int $p_dwg_id ): array {
		$t_result = db_query( 'SELECT type FROM {dwg_history} WHERE dwg_id=' . db_param() . ' ORDER BY id', array( $p_dwg_id ) );
		$t_types = array();
		while( $t_row = db_fetch_array( $t_result ) ) {
			$t_types[] = (int)$t_row['type'];
		}
		return $t_types;
	}

	# ── Catalogue ────────────────────────────────────────────────────────────

	public function testBundledCatalogueLoadsAsDraftWithStringCodes() {
		$t_seed = null;
		foreach( topic_catalogue_seeds() as $t_candidate ) {
			if( $t_candidate['file'] == 'std-eng-004-rev-a.json' ) {
				$t_seed = $t_candidate;
			}
		}
		$this->assertNotNull( $t_seed );
		if( $t_seed['loaded'] ) {
			# Already loaded here (e.g. a pilot uses it): check that copy instead.
			$t_id = (int)db_result( db_query( 'SELECT id FROM {topic_catalogue} WHERE name=' . db_param()
				. ' AND revision=' . db_param(), array( 'STD-ENG-004', 'A' ) ) );
		} else {
			$t_id = topic_catalogue_load_seed( 'std-eng-004-rev-a.json' );
			$this->catalogue_ids[] = $t_id;
			$this->assertEquals( TOPIC_CATALOGUE_DRAFT, (int)topic_catalogue_get( $t_id )['status'] );
		}

		$t_topics = topic_get_all( $t_id );
		$t_codes = array_column( $t_topics, 'code' );
		$this->assertSame( array( '00', '10', '20', '21', '22', '23', '30', '40', '50', '60' ), $t_codes );
		$t_by_code = array_column( $t_topics, null, 'code' );
		foreach( array( '21', '22', '23' ) as $t_code ) {
			$this->assertEquals( $t_by_code['20']['id'], $t_by_code[$t_code]['parent_id'] );
			$this->assertEquals( 1, $t_by_code[$t_code]['level'] );
		}

		$this->expectTopicError( function() { topic_catalogue_load_seed( 'std-eng-004-rev-a.json' ); }, 'already exists' );
	}

	public function testTopicValidation() {
		$t_cat = $this->makeCatalogue();
		$this->expectTopicError( function() use ( $t_cat ) { topic_create( $t_cat['id'], '10', 0, 'Again', '' ); }, 'already has a Topic 10' );
		$this->expectTopicError( function() use ( $t_cat ) { topic_create( $t_cat['id'], '1 0', 0, 'Bad', '' ); }, 'not valid' );
		$this->expectTopicError( function() use ( $t_cat ) { topic_create( $t_cat['id'], '211', $t_cat['21'], 'Grandchild', '' ); }, 'one level only' );
		$this->expectTopicError( function() use ( $t_cat ) {
			topic_update( $t_cat['20'], '20', $t_cat['10'], 'Design', '', 20 );
		}, 'with children' );
		$this->expectTopicError( function() use ( $t_cat ) { topic_create( $t_cat['id'], '30', 0, '', '' ); }, 'title' );
	}

	public function testPublishNeedsApprovedSourceAndFreezesCatalogue() {
		$t_cat = $this->makeCatalogue();
		$this->expectTopicError( function() use ( $t_cat ) {
			topic_catalogue_set_status( $t_cat['id'], TOPIC_CATALOGUE_PUBLISHED );
		}, 'no source document' );

		$t_source = $this->makeDocument( $this->makeProject(), PENDING );
		$t_catalogue = topic_catalogue_get( $t_cat['id'] );
		topic_catalogue_update( $t_cat['id'], $t_catalogue['name'], 'A', $t_source );
		$this->expectTopicError( function() use ( $t_cat ) {
			topic_catalogue_set_status( $t_cat['id'], TOPIC_CATALOGUE_PUBLISHED );
		}, 'not approved' );

		$this->setStatus( $t_source, ACCEPTED );
		topic_catalogue_set_status( $t_cat['id'], TOPIC_CATALOGUE_PUBLISHED );
		$this->assertEquals( TOPIC_CATALOGUE_PUBLISHED, (int)topic_catalogue_get( $t_cat['id'] )['status'] );

		# Frozen: no edits, additions or deletion; retiring a Topic is allowed.
		$this->expectTopicError( function() use ( $t_cat ) { topic_update( $t_cat['10'], '10', 0, 'Renamed', '', 10 ); }, 'only a Draft' );
		$this->expectTopicError( function() use ( $t_cat ) { topic_create( $t_cat['id'], '30', 0, 'New', '' ); }, 'only a Draft' );
		$this->expectTopicError( function() use ( $t_cat ) { topic_catalogue_delete( $t_cat['id'] ); }, 'only a Draft' );
		topic_set_enabled( $t_cat['10'], false );
		$this->assertEquals( 0, (int)topic_get( $t_cat['10'] )['enabled'] );

		topic_catalogue_set_status( $t_cat['id'], TOPIC_CATALOGUE_RETIRED );
		$this->expectTopicError( function() use ( $t_cat ) {
			topic_project_enable( $this->makeProject(), $t_cat['id'] );
		}, 'retired' );
	}

	public function testCatalogueInUseCannotBeDeleted() {
		$t_cat = $this->makeTopicProject();
		$this->expectTopicError( function() use ( $t_cat ) { topic_catalogue_delete( $t_cat['id'] ); }, 'used by a project' );
	}

	# ── Applicability ────────────────────────────────────────────────────────

	public function testApplicabilityStartsUndecidedAndKeepsHistory() {
		$t_cat = $this->makeTopicProject();
		$t_project_id = $t_cat['project_id'];

		$t_view = topic_project_view( $t_project_id );
		foreach( $t_view['topics'] as $t_topic ) {
			$this->assertEquals( TOPIC_UNDECIDED, $t_topic['applicability'] );
		}
		$this->assertEquals( 3, $t_view['applicability_counts'][TOPIC_UNDECIDED] );

		$this->expectTopicError( function() use ( $t_cat ) {
			topic_applicability_set( $t_cat['project_id'], $t_cat['21'], TOPIC_NOT_APPLICABLE, '  ' );
		}, 'needs a rationale' );

		$this->assertTrue( topic_applicability_set( $t_project_id, $t_cat['21'], TOPIC_NOT_APPLICABLE, 'No hardware is designed.' ) );
		$this->assertFalse( topic_applicability_set( $t_project_id, $t_cat['21'], TOPIC_NOT_APPLICABLE, 'No hardware is designed.' ) );
		$this->assertTrue( topic_applicability_set( $t_project_id, $t_cat['21'], TOPIC_APPLICABLE, '' ) );
		$this->assertCount( 2, topic_applicability_history( $t_project_id, $t_cat['21'] ) );
		$this->assertEquals( TOPIC_APPLICABLE, (int)topic_applicability_current( $t_project_id )[$t_cat['21']]['applicability'] );

		# A parent may be Not applicable while its child is Applicable: no dependency.
		$this->assertTrue( topic_applicability_set( $t_project_id, $t_cat['20'], TOPIC_NOT_APPLICABLE, 'Grouping only.' ) );

		# A Topic of another catalogue is refused.
		$t_other = $this->makeCatalogue();
		$this->expectTopicError( function() use ( $t_cat, $t_other ) {
			topic_applicability_set( $t_cat['project_id'], $t_other['10'], TOPIC_APPLICABLE, '' );
		}, 'not in the project' );
	}

	# ── Tags and coverage ────────────────────────────────────────────────────

	public function testTagsDriveDeclaredCoverageWithoutParentChildRollUp() {
		$t_cat = $this->makeTopicProject();
		$t_project_id = $t_cat['project_id'];
		$t_prd = $this->makeDocument( $t_project_id, PENDING, 'PRD-TEST' );
		$t_icd = $this->makeDocument( $t_project_id, PENDING, 'ICD-TEST' );
		$t_old = $this->makeDocument( $t_project_id, ARCHIVED, 'OLD-TEST' );
		$this->makeDocument( $t_project_id, PENDING, 'UNTAGGED-TEST' );

		topic_dwg_add( $t_prd, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );
		topic_dwg_add( $t_icd, $t_cat['21'], TOPIC_EXTENT_WHOLE, '§2' );
		topic_dwg_add( $t_icd, $t_cat['20'], TOPIC_EXTENT_PART, '§3-5' );
		topic_dwg_add( $t_old, $t_cat['10'], TOPIC_EXTENT_PART, '' );

		$this->expectTopicError( function() use ( $t_prd, $t_cat ) {
			topic_dwg_add( $t_prd, $t_cat['10'], TOPIC_EXTENT_PART, '' );
		}, 'already has Topic 10' );

		$t_view = topic_project_view( $t_project_id );
		$this->assertEquals( TOPIC_STATE_COMPLETE, $t_view['topics'][$t_cat['10']]['state'] );
		$this->assertEquals( TOPIC_STATE_COMPLETE, $t_view['topics'][$t_cat['21']]['state'] );
		# The child's whole tag does not complete the parent.
		$this->assertEquals( TOPIC_STATE_PARTIAL, $t_view['topics'][$t_cat['20']]['state'] );
		# The archived document is listed but not counted.
		$this->assertCount( 2, $t_view['topics'][$t_cat['10']]['tags'] );
		$this->assertSame( array( TOPIC_EXTENT_WHOLE ), $t_view['topics'][$t_cat['10']]['extents'] );
		$this->assertCount( 1, $t_view['untagged'] );

		# Not applicable overrides tags for coverage.
		topic_applicability_set( $t_project_id, $t_cat['20'], TOPIC_NOT_APPLICABLE, 'Test.' );
		$t_view = topic_project_view( $t_project_id );
		$this->assertEquals( TOPIC_STATE_NOT_APPLICABLE, $t_view['topics'][$t_cat['20']]['state'] );
		$this->assertEquals( TOPIC_STATE_COMPLETE, $t_view['topics'][$t_cat['21']]['state'] );
	}

	public function testTagChangesAreRecordedInDocumentHistory() {
		$t_cat = $this->makeTopicProject();
		$t_dwg = $this->makeDocument( $t_cat['project_id'] );

		$t_tag = topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_PART, '§1' );
		$this->assertFalse( topic_dwg_update( $t_tag, TOPIC_EXTENT_PART, ' §1 ' ) );
		$this->assertTrue( topic_dwg_update( $t_tag, TOPIC_EXTENT_WHOLE, '' ) );
		topic_dwg_remove( $t_tag );

		$this->assertSame( array( DWG_TOPIC_ATTACHED, DWG_TOPIC_UPDATED, DWG_TOPIC_DETACHED ), $this->historyTypes( $t_dwg ) );
		$this->assertSame( array(), topic_dwg_get_for_dwg( $t_dwg ) );
		$this->assertEquals( '10, ' . lang_get( 'topic_extent_part' ) . ': §1',
			topic_dwg_history_describe( topic_dwg_history_value( '10', TOPIC_EXTENT_PART, '§1' ) ) );
	}

	public function testRetiredTopicAndDisabledProjectRefuseTags() {
		$t_cat = $this->makeTopicProject();
		$t_dwg = $this->makeDocument( $t_cat['project_id'] );

		topic_set_enabled( $t_cat['10'], false );
		$this->expectTopicError( function() use ( $t_dwg, $t_cat ) {
			topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_PART, '' );
		}, 'retired' );

		topic_dwg_add( $t_dwg, $t_cat['20'], TOPIC_EXTENT_PART, '' );
		topic_project_disable( $t_cat['project_id'] );
		$this->assertFalse( topic_project_is_enabled( $t_cat['project_id'] ) );
		$this->expectTopicError( function() use ( $t_dwg, $t_cat ) {
			topic_dwg_add( $t_dwg, $t_cat['21'], TOPIC_EXTENT_PART, '' );
		}, 'not enabled' );

		# Re-enabling restores the kept records.
		topic_project_enable( $t_cat['project_id'], $t_cat['id'] );
		$this->assertCount( 1, topic_dwg_get_for_dwg( $t_dwg ) );

		# Switching catalogue is refused once records exist.
		$t_other = $this->makeCatalogue();
		$this->expectTopicError( function() use ( $t_cat, $t_other ) {
			topic_project_enable( $t_cat['project_id'], $t_other['id'] );
		}, 'changing catalogue' );
	}

	public function testFeatureSwitchOffHidesTopics() {
		$t_cat = $this->makeTopicProject();
		config_set_global( 'topics_enabled', OFF );
		$this->assertFalse( topic_project_is_enabled( $t_cat['project_id'] ) );
		$this->assertFalse( topic_sidebar_visible( $t_cat['project_id'] ) );
		config_set_global( 'topics_enabled', ON );
		$this->assertTrue( topic_project_is_enabled( $t_cat['project_id'] ) );
	}

	public function testMovedDocumentKeepsTagsOnlyWithSameCatalogue() {
		$t_cat = $this->makeTopicProject();
		$t_same = $this->makeProject();
		topic_project_enable( $t_same, $t_cat['id'] );
		$t_other_cat = $this->makeCatalogue();
		$t_other = $this->makeProject();
		topic_project_enable( $t_other, $t_other_cat['id'] );

		$t_dwg = $this->makeDocument( $t_cat['project_id'] );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );

		topic_dwg_move( $t_dwg, $t_cat['project_id'], $t_same );
		db_query( 'UPDATE {dwg} SET project_id=' . db_param() . ' WHERE id=' . db_param(), array( $t_same, $t_dwg ) );
		dwg_clear_cache_all( $t_dwg );
		$this->assertCount( 1, topic_dwg_get_for_dwg( $t_dwg ) );

		topic_dwg_move( $t_dwg, $t_same, $t_other );
		db_query( 'UPDATE {dwg} SET project_id=' . db_param() . ' WHERE id=' . db_param(), array( $t_other, $t_dwg ) );
		dwg_clear_cache_all( $t_dwg );
		$this->assertCount( 0, topic_dwg_get_for_dwg( $t_dwg ) );
		$this->assertContains( DWG_TOPIC_DETACHED, $this->historyTypes( $t_dwg ) );
	}

	public function testDocumentDeletionRemovesItsTags() {
		$t_cat = $this->makeTopicProject();
		$t_dwg = $this->makeDocument( $t_cat['project_id'] );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_WHOLE, '' );

		dwg_delete( $t_dwg );
		$t_count = (int)db_result( db_query( 'SELECT COUNT(*) FROM {dwg_topic} WHERE dwg_id=' . db_param(), array( $t_dwg ) ) );
		$this->assertEquals( 0, $t_count );
		$this->assertEquals( TOPIC_STATE_NONE, topic_project_view( $t_cat['project_id'] )['topics'][$t_cat['10']]['state'] );
	}

	public function testProjectDeletionRemovesTopicRecords() {
		$t_cat = $this->makeTopicProject();
		$t_project_id = $t_cat['project_id'];
		$t_dwg = $this->makeDocument( $t_project_id );
		topic_dwg_add( $t_dwg, $t_cat['10'], TOPIC_EXTENT_PART, '' );
		topic_applicability_set( $t_project_id, $t_cat['10'], TOPIC_APPLICABLE, '' );

		topic_project_delete( $t_project_id );
		$this->assertNull( topic_project_get( $t_project_id ) );
		$this->assertFalse( topic_project_has_records( $t_project_id ) );
	}
}
