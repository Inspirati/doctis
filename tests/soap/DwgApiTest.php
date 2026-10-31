<?php
# Doctis: the document SOAP API acts on documents, never on issues.
#
# mc_dwg_api.php and DwgAddCommand were copied from their issue originals,
# and several calls were left pointing at the issue API.  Each test here
# pins one that was found and fixed on 2026-11-01:
#   - mc_dwg_update / mc_dwg_add set monitors through mci_issue_set_monitors(),
#     so a get-then-update round trip failed with "Issue #N not found" (or,
#     with an issue of the same id, changed that issue's monitors);
#   - mc_dwg_note_update checked bugnote_exists();
#   - mc_dwg_relationship_delete called relationship_delete(), which deletes
#     the *issue* relationship with that id.
#
# Works in a scratch project it creates and removes; the documents have no
# primary file, so nothing is written to git.

namespace Mantis\tests\soap;

/**
 * @requires extension soap
 * @group SOAP
 */
class DwgApiTest extends SoapBase {
	private $scratchProjectId = 0;
	private $dwgIds = array();

	protected function setUp(): void {
		parent::setUp();
		$this->scratchProjectId = $this->client->mc_project_add( $this->userName, $this->password, array(
			'name' => 'dwgsoaptest' . rand( 1, 1000000 ),
			'status' => array( 'name' => 'development' ),
			'view_state' => array( 'id' => VS_PUBLIC ),
		) );
	}

	protected function tearDown(): void {
		foreach( $this->dwgIds as $t_id ) {
			$this->client->mc_dwg_delete( $this->userName, $this->password, $t_id );
		}
		if( $this->scratchProjectId ) {
			$this->client->mc_project_delete( $this->userName, $this->password, $this->scratchProjectId );
		}
		parent::tearDown();
	}

	private function addDocument( array $p_extra = array() ) {
		$t_title = $this->getTestName() . ': test document ' . rand( 1, 1000000 );
		$t_id = $this->client->mc_dwg_add( $this->userName, $this->password, array_merge( array(
			'title' => $t_title,
			'summary' => $t_title,
			'description' => 'description of test document.',
			'project' => array( 'id' => $this->scratchProjectId ),
			'category' => $this->getCategory(),
		), $p_extra ) );
		$this->dwgIds[] = $t_id;
		return $t_id;
	}

	private function monitorIds( $p_dwg_id ) {
		$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $p_dwg_id );
		$t_ids = array();
		foreach( $t_dwg->monitors ?? array() as $t_monitor ) {
			$t_ids[] = (int)$t_monitor->id;
		}
		return $t_ids;
	}

	public function testGetThenUpdateRoundTrip() {
		$t_id  = $this->addDocument();
		$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $t_id );

		# Unchanged, monitors and all: this used to fault "Issue #N not found".
		$this->assertTrue( $this->client->mc_dwg_update( $this->userName, $this->password, $t_id, $t_dwg ) );
	}

	public function testUpdateSetsTheDocumentsMonitors() {
		$t_id = $this->addDocument();
		$this->assertSame( array(), $this->monitorIds( $t_id ) );

		$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $t_id );
		$t_dwg->monitors = array( array( 'id' => (int)$this->userId ) );
		$this->assertTrue( $this->client->mc_dwg_update( $this->userName, $this->password, $t_id, $t_dwg ) );
		$this->assertSame( array( (int)$this->userId ), $this->monitorIds( $t_id ) );

		$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $t_id );
		$t_dwg->monitors = array();
		$this->assertTrue( $this->client->mc_dwg_update( $this->userName, $this->password, $t_id, $t_dwg ) );
		$this->assertSame( array(), $this->monitorIds( $t_id ) );
	}

	public function testAddSetsTheDocumentsMonitors() {
		$t_id = $this->addDocument( array( 'monitors' => array( array( 'id' => (int)$this->userId ) ) ) );
		$this->assertSame( array( (int)$this->userId ), $this->monitorIds( $t_id ) );
	}

	public function testNoteUpdate() {
		$t_id = $this->addDocument();
		$t_note_id = $this->client->mc_dwg_note_add( $this->userName, $this->password, $t_id,
			array( 'text' => 'first wording' ) );

		# This used to fault "does not exist": the check read the issue notes.
		$this->assertTrue( $this->client->mc_dwg_note_update( $this->userName, $this->password,
			array( 'id' => $t_note_id, 'text' => 'second wording' ) ) );

		$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $t_id );
		$this->assertSame( 'second wording', $t_dwg->notes[0]->text );
	}

	public function testRelationshipAddAndDelete() {
		$t_src  = $this->addDocument();
		$t_dest = $this->addDocument();

		$t_rel_id = $this->client->mc_dwg_relationship_add( $this->userName, $this->password, $t_src,
			array( 'target_id' => $t_dest, 'type' => array( 'id' => DWG_RELATED ) ) );
		$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $t_src );
		$this->assertCount( 1, $t_dwg->relationships );
		$this->assertEquals( $t_dest, $t_dwg->relationships[0]->target_id );

		# This used to delete the issue relationship with the same id.
		$this->assertTrue( $this->client->mc_dwg_relationship_delete( $this->userName, $this->password, $t_src, $t_rel_id ) );
		foreach( array( $t_src, $t_dest ) as $t_id ) {
			$t_dwg = $this->client->mc_dwg_get( $this->userName, $this->password, $t_id );
			$this->assertEmpty( $t_dwg->relationships ?? array(), 'document ' . $t_id );
		}
	}
}
