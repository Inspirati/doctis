<?php
# Doctis Documentation Topics test base: throwaway projects, documents (rows
# only, no git files) and catalogues, removed in tearDown (project deletion
# also removes every Topic record of the project).

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

require_api( 'topic_api.php' );

abstract class TopicTestCase extends MantisCoreBase {
	protected $project_ids = array();
	protected $dwg_ids = array();
	protected $document_ids = array();
	protected $catalogue_ids = array();
	protected $topics_enabled_saved;

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

	protected function makeProject(): int {
		$t_id = (int)project_create( 'TopicTest ' . rand(), '', 10 );
		$this->project_ids[] = $t_id;
		return $t_id;
	}

	/** A document row in a project (no primary file). */
	protected function makeDocument( int $p_project_id, int $p_status = PENDING, string $p_number = '' ): int {
		# Revision "A": without a primary file, evidence needs an edition or revision.
		db_query( 'INSERT INTO {documents} ( title, number, revision ) VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
			array( 'Topic test document ' . rand(), $p_number, 'A' ) );
		$t_document_id = db_insert_id( db_get_table( 'documents' ) );
		$this->document_ids[] = $t_document_id;
		db_query( 'INSERT INTO {dwg} ( project_id, document_id, status, date_submitted, last_updated )
			VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
			array( $p_project_id, $t_document_id, $p_status, db_now(), db_now() ) );
		$t_id = (int)db_insert_id( db_get_table( 'dwg' ) );
		$this->dwg_ids[] = $t_id;
		return $t_id;
	}

	protected function setStatus( int $p_dwg_id, int $p_status ): void {
		db_query( 'UPDATE {dwg} SET status=' . db_param() . ' WHERE id=' . db_param(), array( $p_status, $p_dwg_id ) );
		dwg_clear_cache_all( $p_dwg_id );
	}

	/** A Draft catalogue named uniquely, with 10, 20 and child 21. */
	protected function makeCatalogue(): array {
		$t_id = topic_catalogue_create( 'TEST-CAT-' . rand(), 'A' );
		$this->catalogue_ids[] = $t_id;
		$t_10 = topic_create( $t_id, '10', 0, 'Requirements', '' , 10 );
		$t_20 = topic_create( $t_id, '20', 0, 'Design', '', 20 );
		$t_21 = topic_create( $t_id, '21', $t_20, 'Hardware', '', 30 );
		return array( 'id' => $t_id, '10' => $t_10, '20' => $t_20, '21' => $t_21 );
	}

	/** A project with Topics enabled on a fresh catalogue. */
	protected function makeTopicProject(): array {
		$t_catalogue = $this->makeCatalogue();
		$t_project_id = $this->makeProject();
		topic_project_enable( $t_project_id, $t_catalogue['id'] );
		$t_catalogue['project_id'] = $t_project_id;
		return $t_catalogue;
	}

	protected function expectTopicError( callable $p_call, string $p_fragment ): void {
		try {
			$p_call();
		} catch( ClientException $e ) {
			$this->assertEquals( ERROR_TOPIC, $e->getCode() );
			$this->assertStringContainsString( $p_fragment, $e->getMessage() );
			return;
		}
		$this->fail( 'Expected a Topics error containing "' . $p_fragment . '"' );
	}

	protected function historyTypes( int $p_dwg_id ): array {
		$t_result = db_query( 'SELECT type FROM {dwg_history} WHERE dwg_id=' . db_param() . ' ORDER BY id', array( $p_dwg_id ) );
		$t_types = array();
		while( $t_row = db_fetch_array( $t_result ) ) {
			$t_types[] = (int)$t_row['type'];
		}
		return $t_types;
	}
}
