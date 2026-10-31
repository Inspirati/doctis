<?php
# Doctis: saving a document's whole record (DwgData::update()).
#
# Until 2026-11-01 update() wrote eleven {dwg} columns and nothing in
# {documents} but the link, so title, author, publisher, number, edition,
# revision, classification, discipline, both dates, the view state and the
# summary could be posted and silently not saved.  These tests pin the record
# that Edit Document (dwg_update_page.php), dwg_update.php and mc_dwg_update
# now rely on.
#
# Uses the live database: creates documents in a scratch project and removes
# them in tearDown.  Nothing is written to git; the one test that needs a
# registered file inserts a primary-file row directly, which is all update()
# consults.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

require_api( 'dwg_api.php' );
require_api( 'project_api.php' );

class DwgUpdateTest extends MantisCoreBase {
	private $dwg_ids = array();
	private $project_id = 0;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->project_id = (int)project_create( 'updtest' . rand(), '', 10, VS_PUBLIC );
		global $g_project_override;
		$g_project_override = $this->project_id;
	}

	protected function tearDown(): void {
		foreach( $this->dwg_ids as $t_id ) {
			db_query( 'DELETE FROM {dwg_primary_file} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {documents} WHERE id IN ( SELECT document_id FROM {dwg} WHERE id=' . db_param() . ' )', array( $t_id ) );
			db_query( 'DELETE FROM {dwg_text} WHERE id IN ( SELECT dwg_text_id FROM {dwg} WHERE id=' . db_param() . ' )', array( $t_id ) );
			db_query( 'DELETE FROM {dwg} WHERE id=' . db_param(), array( $t_id ) );
		}
		if( $this->project_id > 0 ) {
			project_delete( $this->project_id );
		}
		global $g_project_override;
		$g_project_override = null;
		parent::tearDown();
	}

	private function makeDocument(): int {
		$t_dwg = new \DwgData();
		$t_dwg->project_id    = $this->project_id;
		$t_dwg->category_id   = 1;
		$t_dwg->creator_id    = auth_get_current_user_id();
		$t_dwg->title         = 'Original';
		$t_dwg->summary       = 'Original';
		$t_dwg->author        = 'Old Author';
		$t_dwg->reference     = 'OLD-REF';
		$t_dwg->description   = 'Original description';
		$t_dwg->revision_date = 1;
		$t_dwg->release_date  = 1;
		$t_id = (int)$t_dwg->create();
		$this->dwg_ids[] = $t_id;
		return $t_id;
	}

	/** Reload from the database, past every cache. */
	private function fresh( int $p_id ): \DwgData {
		dwg_clear_cache( $p_id );
		return dwg_get( $p_id, true );
	}

	private function documentsRow( int $p_id ): array {
		$t_result = db_query(
			'SELECT doc.* FROM {documents} doc JOIN {dwg} d ON d.document_id=doc.id WHERE d.id=' . db_param(),
			array( $p_id ) );
		return db_fetch_array( $t_result );
	}

	public function testUpdateSavesTheWholeRecord() {
		$t_id  = $this->makeDocument();
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->title          = 'Renamed';
		$t_dwg->author         = 'New Author';
		$t_dwg->publisher      = 'HCR Robotics';
		$t_dwg->number         = 'NEW-042';
		$t_dwg->edition        = '2nd';
		$t_dwg->revision       = 'C';
		$t_dwg->version        = '3.1';
		$t_dwg->discipline     = 'Avionics';
		$t_dwg->classification = 'Internal';
		$t_dwg->link_url       = 'https://example.com/doc';
		$t_dwg->reference      = 'NEW-REF';
		$t_dwg->view_state     = VS_PRIVATE;
		$t_dwg->revision_date  = '2026-10-15';
		$t_dwg->release_date   = strtotime( '2026-11-01' );
		$t_dwg->update( false, true );

		$t_saved = $this->fresh( $t_id );
		foreach( array( 'title' => 'Renamed', 'author' => 'New Author', 'publisher' => 'HCR Robotics',
			'number' => 'NEW-042', 'edition' => '2nd', 'revision' => 'C', 'version' => '3.1',
			'discipline' => 'Avionics', 'classification' => 'Internal',
			'link_url' => 'https://example.com/doc', 'reference' => 'NEW-REF' ) as $t_field => $t_expected ) {
			$this->assertSame( $t_expected, (string)$t_saved->$t_field, $t_field );
		}
		$this->assertSame( VS_PRIVATE, (int)$t_saved->view_state, 'view_state' );
		$this->assertSame( '2026-10-15', date( 'Y-m-d', (int)$t_saved->revision_date ), 'revision_date (from text)' );
		$this->assertSame( '2026-11-01', date( 'Y-m-d', (int)$t_saved->release_date ), 'release_date (from a timestamp)' );

		# Classification and link URL live in both tables; both copies move.
		$t_row = $this->documentsRow( $t_id );
		$this->assertSame( 'Internal', $t_row['classification'] );
		$this->assertSame( 'https://example.com/doc', $t_row['link_url'] );
	}

	public function testTitleAndSummaryMoveTogether() {
		$t_id  = $this->makeDocument();
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->title = 'By Title';
		$t_dwg->update( false, true );
		$this->assertSame( 'By Title', $this->fresh( $t_id )->summary, 'a new title renames the summary' );

		$t_dwg = $this->fresh( $t_id );
		$t_dwg->summary = 'By Summary';
		$t_dwg->update( false, true );
		$this->assertSame( 'By Summary', $this->fresh( $t_id )->title, 'a new summary renames the title' );
	}

	public function testABlankTitleIsRefused() {
		$t_id  = $this->makeDocument();
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->title = '   ';
		try {
			$t_dwg->update( false, true );
			$this->fail( 'a blank title was saved' );
		} catch( ClientException $e ) {
			$this->assertSame( ERROR_EMPTY_FIELD, $e->getCode() );
		}
		$this->assertSame( 'Original', $this->fresh( $t_id )->title, 'and nothing changed' );
	}

	public function testABlankDateMeansNone() {
		$t_id  = $this->makeDocument();
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->release_date = strtotime( '2026-11-01' );
		$t_dwg->update( false, true );
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->release_date = '';
		$t_dwg->update( false, true );
		$this->assertSame( date_get_null(), (int)$this->fresh( $t_id )->release_date );
	}

	public function testTheReferenceIsDoctisOnceAFileIsRegistered() {
		$t_id = $this->makeDocument();

		# With no file the reference is the user's.
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->reference = 'USER-REF';
		$t_dwg->update( false, true );
		$this->assertSame( 'USER-REF', $this->fresh( $t_id )->reference );

		# Once a file is registered it is the on-record commit, set by Doctis;
		# update() keeps it whatever it is given.
		db_query( 'INSERT INTO {dwg_primary_file} ( dwg_id, git_path, git_sha, git_branch, filename, filesize, file_type, date_added, user_id, description )'
			. ' VALUES ( ' . db_param() . ", 'x.md', 'abc', 'main', 'x.md', 1, 'text/markdown', 1, 1, '' )", array( $t_id ) );
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->reference = 'HIJACKED';
		$t_dwg->author    = 'Still Saved';
		$t_dwg->update( false, true );
		$t_saved = $this->fresh( $t_id );
		$this->assertSame( 'USER-REF', $t_saved->reference, 'the reference is kept' );
		$this->assertSame( 'Still Saved', $t_saved->author, 'while the rest of the record saves' );
	}

	public function testHistoryRecordsTheDocumentFields() {
		$t_id  = $this->makeDocument();
		$t_dwg = $this->fresh( $t_id );
		$t_dwg->title        = 'Logged';
		$t_dwg->author       = 'Logged Author';
		$t_dwg->release_date = strtotime( '2026-11-01' );
		$t_dwg->update( false, true );

		$t_fields = array();
		$t_result = db_query( 'SELECT field_name FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
		while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
			$t_fields[] = $t_row['field_name'];
		}
		foreach( array( 'title', 'author', 'release_date' ) as $t_field ) {
			$this->assertContains( $t_field, $t_fields, $t_field . ' is logged' );
		}
		$this->assertNotContains( 'summary', $t_fields, 'a rename is one entry, under title' );
		$this->assertNotContains( 'publisher', $t_fields, 'an unchanged field is not logged' );
	}
}
