<?php
# Doctis project owner tests: the person responsible for a project, and the
# inheritance of that field down the project hierarchy.
#
# A sub-project with no owner of its own inherits its parent's, so a project
# tree has one responsible person unless a sub-project names its own. See
# doc/dwg/PROJECT_OWNERSHIP.md.
#
# Uses the live database: creates throwaway projects and users and removes them
# in tearDown.

namespace Mantis\tests\Mantis;

require_api( 'project_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'user_transfer_api.php' );

class ProjectOwnerTest extends MantisCoreBase {
	private $project_ids = array();
	private $user_ids = array();
	private $emails = array();
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->saved['own_dwg_threshold'] = config_get_global( 'own_dwg_threshold' );
		config_set_global( 'own_dwg_threshold', DEVELOPER );
	}

	protected function tearDown(): void {
		# Children first, so hierarchy rows go before their parents.
		foreach( array_reverse( $this->project_ids ) as $t_id ) {
			project_delete( $t_id );
		}
		foreach( $this->emails as $t_email ) {
			db_query( 'DELETE FROM {email} WHERE email=' . db_param(), array( $t_email ) );
		}
		foreach( $this->user_ids as $t_id ) {
			user_delete( $t_id );
		}
		foreach( $this->saved as $t_key => $t_value ) {
			config_set_global( $t_key, $t_value );
		}
		project_clear_cache();
		parent::tearDown();
	}

	private function makeUser( int $p_access, bool $p_enabled = true ): int {
		$t_name = 'potest' . rand();
		$t_email = strtolower( $t_name ) . '@example.test';
		$t_id = (int)user_get_id_by_cookie( user_create( $t_name, 'password', $t_email ) );
		$this->user_ids[] = $t_id;
		$this->emails[] = $t_email;
		db_query( 'UPDATE {user} SET enabled=' . db_param() . ', access_level=' . db_param() . ' WHERE id=' . db_param(),
			array( $p_enabled, $p_access, $t_id ) );
		user_clear_cache( $t_id );
		return $t_id;
	}

	/**
	 * The hierarchy mutators invalidate their own cache now, so this only has
	 * to drop the project row cache. It stays as a helper because the cycle
	 * test writes {project_hierarchy} directly.
	 */
	private function clearHierarchyCache(): void {
		project_hierarchy_clear_cache();
		project_clear_cache();
	}

	private function makeProject( int $p_owner_id = 0, string $p_classification = '' ): int {
		$t_id = (int)project_create( 'POtest' . rand(), '', 10, VS_PUBLIC, '', true, true, 1,
			$p_owner_id, $p_classification );
		$this->project_ids[] = $t_id;
		return $t_id;
	}

	/** The owner round-trips through create. */
	public function testCreateStoresOwnerAndClassification() {
		$t_owner = $this->makeUser( MANAGER );
		$t_id = $this->makeProject( $t_owner, 'INTERNAL' );

		$this->assertEquals( $t_owner, project_get_owner( $t_id ) );
		$this->assertEquals( $t_owner, project_get_owner( $t_id, /* inherited */ false ) );
		$this->assertEquals( 'INTERNAL', project_get_field( $t_id, 'classification' ) );
	}

	/** A project with no owner anywhere in its chain reports none. */
	public function testNoOwnerAnywhere() {
		$t_id = $this->makeProject();

		$this->assertEquals( NO_USER, project_get_owner( $t_id ) );
		$this->assertEquals( ALL_PROJECTS, project_get_owner_source( $t_id ) );
	}

	/** A sub-project inherits its parent's owner, through two levels. */
	public function testSubProjectInheritsOwner() {
		$t_owner = $this->makeUser( MANAGER );
		$t_parent = $this->makeProject( $t_owner );
		$t_child = $this->makeProject();
		$t_grandchild = $this->makeProject();
		project_hierarchy_add( $t_child, $t_parent );
		project_hierarchy_add( $t_grandchild, $t_child );
		$this->clearHierarchyCache();

		$this->assertEquals( $t_owner, project_get_owner( $t_child ) );
		$this->assertEquals( $t_owner, project_get_owner( $t_grandchild ) );

		# ... but they do not hold it themselves, which is what an edit form
		# needs in order to show "inherited" rather than "set here".
		$this->assertEquals( NO_USER, project_get_owner( $t_child, false ) );
		$this->assertEquals( $t_parent, project_get_owner_source( $t_grandchild ) );
	}

	/** A sub-project naming its own owner stops inheriting, and its children follow it. */
	public function testOwnOwnerOverridesInheritance() {
		$t_top = $this->makeUser( MANAGER );
		$t_mid = $this->makeUser( MANAGER );
		$t_parent = $this->makeProject( $t_top );
		$t_child = $this->makeProject( $t_mid );
		$t_grandchild = $this->makeProject();
		project_hierarchy_add( $t_child, $t_parent );
		project_hierarchy_add( $t_grandchild, $t_child );
		$this->clearHierarchyCache();

		$this->assertEquals( $t_top, project_get_owner( $t_parent ) );
		$this->assertEquals( $t_mid, project_get_owner( $t_child ) );
		$this->assertEquals( $t_mid, project_get_owner( $t_grandchild ) );
		$this->assertEquals( $t_child, project_get_owner_source( $t_grandchild ) );
	}

	/** Clearing a sub-project's owner makes it inherit again. */
	public function testClearingOwnerRestoresInheritance() {
		$t_top = $this->makeUser( MANAGER );
		$t_mid = $this->makeUser( MANAGER );
		$t_parent = $this->makeProject( $t_top );
		$t_child = $this->makeProject( $t_mid );
		project_hierarchy_add( $t_child, $t_parent );
		$this->clearHierarchyCache();
		$this->assertEquals( $t_mid, project_get_owner( $t_child ) );

		project_update( $t_child, project_get_name( $t_child ), '', 10, VS_PUBLIC, '', true, true, 1,
			/* owner */ 0, null );
		$this->clearHierarchyCache();

		$this->assertEquals( $t_top, project_get_owner( $t_child ) );
	}

	/**
	 * A disabled owner reports as no owner: the field exists to name somebody
	 * who can be held responsible.
	 */
	public function testDisabledOwnerReportsAsNone() {
		$t_owner = $this->makeUser( MANAGER, /* enabled */ false );
		$t_id = $this->makeProject( $t_owner );

		$this->assertEquals( NO_USER, project_get_owner( $t_id ) );
		# The stored value is untouched, so re-enabling restores it.
		$this->assertEquals( $t_owner, (int)project_get_field( $t_id, 'owner_id' ) );
	}

	/** An update that omits owner or classification must not blank them. */
	public function testUpdateWithoutThemLeavesThemAlone() {
		$t_owner = $this->makeUser( MANAGER );
		$t_id = $this->makeProject( $t_owner, 'RESTRICTED' );

		project_update( $t_id, project_get_name( $t_id ), '', 10, VS_PUBLIC, '', true, true, 1 );
		project_clear_cache();

		$this->assertEquals( $t_owner, project_get_owner( $t_id ) );
		$this->assertEquals( 'RESTRICTED', project_get_field( $t_id, 'classification' ) );
	}

	/** The hierarchy walk terminates rather than spinning on a cycle. */
	public function testOwnerWalkSurvivesACycle() {
		$t_a = $this->makeProject();
		$t_b = $this->makeProject();
		project_hierarchy_add( $t_b, $t_a );
		# Force the cycle directly: project_hierarchy_add() would not allow it.
		db_query( 'UPDATE {project_hierarchy} SET parent_id=' . db_param() . ' WHERE child_id=' . db_param(),
			array( $t_b, $t_a ) );
		$this->clearHierarchyCache();

		$this->assertEquals( NO_USER, project_get_owner( $t_a ) );
	}

	/**
	 * A new document falls back to the project owner when its creator is not
	 * eligible to own documents.
	 */
	public function testDocumentOwnerFallsBackToProjectOwner() {
		$t_project_owner = $this->makeUser( MANAGER );
		$t_low_creator = $this->makeUser( REPORTER );
		$t_project_id = $this->makeProject( $t_project_owner );
		project_add_user( $t_project_id, $t_low_creator, REPORTER );
		project_add_user( $t_project_id, $t_project_owner, MANAGER );

		# Through the command, because choosing a default owner is policy and
		# lives there; DwgData::create() persists what it is given.
		$t_command = new \DwgAddCommand( array( 'payload' => array( 'issue' => array(
			'project' => array( 'id' => $t_project_id ),
			'creator' => array( 'id' => $t_low_creator ),
			'category' => array( 'name' => 'General' ),
			'summary' => 'project owner fallback',
			'description' => 'project owner fallback',
			'title' => 'project owner fallback',
		) ) ) );
		$t_id = (int)$t_command->execute()['issue_id'];

		try {
			$this->assertEquals( $t_project_owner, dwg_get_field( $t_id, 'owner_id' ),
				'an ineligible creator must not be recorded as the document owner' );
		} finally {
			db_query( 'DELETE FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {dwg} WHERE id=' . db_param(), array( $t_id ) );
		}
	}

	/**
	 * When the reports_to chain yields nothing, the successor suggestion falls
	 * back to the owner of the project where the user holds the most documents.
	 */
	public function testTransferSuggestionFallsBackToProjectOwner() {
		$t_project_owner = $this->makeUser( MANAGER );
		$t_leaver = $this->makeUser( DEVELOPER );   # no reports_to
		$t_project_id = $this->makeProject( $t_project_owner );
		project_add_user( $t_project_id, $t_leaver, DEVELOPER );

		$this->assertEquals( 0, user_transfer_suggest_successor( $t_leaver ),
			'with no holdings there is nothing to suggest from' );

		$t_dwg = new \DwgData();
		$t_dwg->project_id = $t_project_id;
		$t_dwg->creator_id = $t_leaver;
		$t_dwg->owner_id = $t_leaver;
		$t_dwg->category_id = 0;
		$t_dwg->summary = 'transfer fallback';
		$t_dwg->title = 'transfer fallback';
		$t_dwg->description = 'transfer fallback';
		$t_dwg->status = config_get( 'dwg_submit_status' );
		$t_dwg->revision_date = 1;
		$t_dwg->release_date = 1;
		$t_dwg->due_date = 1;
		$t_id = (int)$t_dwg->create();

		try {
			$this->assertEquals( $t_project_owner, user_transfer_suggest_successor( $t_leaver ) );
		} finally {
			db_query( 'DELETE FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {dwg} WHERE id=' . db_param(), array( $t_id ) );
		}
	}
}
