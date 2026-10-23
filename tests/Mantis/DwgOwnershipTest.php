<?php
# Doctis document ownership tests: creator, owner and assignee.
#
# A document has three person fields, each with its own rule
# (doc/dwg/OWNERSHIP.md):
#   creator_id  who registered it; read-only provenance
#   owner_id    who is responsible for it
#   handler_id  who holds the current task step ("Assigned To")
#
# Uses the live database: creates throwaway users and documents in a scratch
# project and removes them in tearDown. Nothing is written to git, because the
# documents never get a primary file.

namespace Mantis\tests\Mantis;

require_api( 'access_dwg_api.php' );
require_api( 'dwg_api.php' );
require_api( 'filter_dwg_api.php' );
require_api( 'license_api.php' );
require_api( 'project_api.php' );

class DwgOwnershipTest extends MantisCoreBase {
	private $user_ids = array();
	private $emails = array();
	private $dwg_ids = array();
	private $project_id = 0;
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		self::login();

		foreach( array( 'own_dwg_threshold', 'dwg_claim_owner_threshold',
			'dwg_assign_owner_threshold', 'dwg_claim_threshold',
			'handle_dwg_threshold', 'dwg_auto_set_status_to_assigned',
			'licenses_enabled' ) as $t_key ) {
			$this->saved[$t_key] = config_get_global( $t_key );
		}

		$this->project_id = (int)project_create( 'owntest' . rand(), '', 10, VS_PUBLIC );
		global $g_project_override;
		$g_project_override = $this->project_id;
	}

	protected function tearDown(): void {
		foreach( $this->dwg_ids as $t_id ) {
			db_query( 'DELETE FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {dwg_text} WHERE id IN ( SELECT dwg_text_id FROM {dwg} WHERE id=' . db_param() . ' )', array( $t_id ) );
			db_query( 'DELETE FROM {dwg} WHERE id=' . db_param(), array( $t_id ) );
		}
		if( $this->project_id > 0 ) {
			project_delete( $this->project_id );
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
		global $g_project_override;
		$g_project_override = null;
		parent::tearDown();
	}

	/** Create a throwaway user at the given project access level. */
	private function makeUser( int $p_access ): int {
		$t_name = 'owntest' . rand();
		$t_email = strtolower( $t_name ) . '@example.test';
		$t_id = (int)user_get_id_by_cookie( user_create( $t_name, 'password', $t_email ) );
		$this->user_ids[] = $t_id;
		$this->emails[] = $t_email;
		project_add_user( $this->project_id, $t_id, $p_access );
		return $t_id;
	}

	/** Create a document in the scratch project and remember it for cleanup. */
	private function makeDwg( int $p_creator, int $p_owner = 0, int $p_handler = 0 ): int {
		$t_dwg = new \DwgData();
		$t_dwg->project_id = $this->project_id;
		$t_dwg->creator_id = $p_creator;
		$t_dwg->owner_id = $p_owner;
		$t_dwg->handler_id = $p_handler;
		$t_dwg->category_id = 0;
		$t_dwg->summary = 'ownership test';
		$t_dwg->title = 'ownership test';
		$t_dwg->description = 'ownership test';
		$t_dwg->status = config_get( 'dwg_submit_status' );
		# {documents} stores these as INT UNSIGNED NOT NULL; DwgData defaults
		# them to '', which a strict-mode INSERT rejects.
		$t_dwg->revision_date = 1;
		$t_dwg->release_date = 1;
		$t_dwg->due_date = 1;

		$t_id = (int)$t_dwg->create();
		$this->dwg_ids[] = $t_id;
		return $t_id;
	}

	/**
	 * The document "assigned to" status must be a value that actually exists in
	 * $g_dwg_status_enum_string.
	 *
	 * Regression: it was ASSIGNED (50), MantisBT's bug status, which is not a
	 * document status at all. Two code paths wrote it to {dwg}.status.
	 */
	public function testAssignedStatusIsAValidDocumentStatus() {
		$t_assigned = config_get( 'dwg_assigned_status' );
		$t_enum = config_get( 'dwg_status_enum_string' );

		$this->assertNotFalse(
			\MantisEnum::getLabel( $t_enum, $t_assigned ),
			'$g_dwg_assigned_status (' . $t_assigned . ') is not in $g_dwg_status_enum_string'
		);
		$this->assertEquals( ASSIGNED_TO, $t_assigned );
	}

	/**
	 * create() must persist owner_id and handler_id.
	 *
	 * Regression: its INSERT omitted both while still writing a handler_id
	 * history row, so a document created with an assignee stored none and the
	 * audit trail asserted an assignment that never happened.
	 */
	public function testCreatePersistsOwnerAndHandler() {
		$t_creator = $this->makeUser( DEVELOPER );
		$t_owner = $this->makeUser( DEVELOPER );
		$t_handler = $this->makeUser( UPDATER );

		$t_id = $this->makeDwg( $t_creator, $t_owner, $t_handler );

		$this->assertEquals( $t_creator, dwg_get_field( $t_id, 'creator_id' ) );
		$this->assertEquals( $t_owner, dwg_get_field( $t_id, 'owner_id' ) );
		$this->assertEquals( $t_handler, dwg_get_field( $t_id, 'handler_id' ) );
	}

	/** An unspecified owner falls back to the creator (D6). */
	public function testOwnerDefaultsToCreator() {
		$t_creator = $this->makeUser( DEVELOPER );
		$t_id = $this->makeDwg( $t_creator );

		$this->assertEquals( $t_creator, dwg_get_field( $t_id, 'owner_id' ) );
	}

	/** Gaining an assignee must not move the document's status (D2). */
	public function testAssignmentDoesNotPromoteStatus() {
		config_set_global( 'dwg_auto_set_status_to_assigned', OFF );
		$t_submit = config_get( 'dwg_submit_status' );

		$this->assertEquals(
			$t_submit,
			dwg_get_status_for_assign( NO_USER, 42, $t_submit )
		);
	}

	/** With the switch on, the transition still resolves to a real status. */
	public function testAutoAssignStatusWhenEnabledIsInEnum() {
		config_set_global( 'dwg_auto_set_status_to_assigned', ON );
		$t_submit = config_get( 'dwg_submit_status' );

		$t_result = dwg_get_status_for_assign( NO_USER, 42, $t_submit );

		$this->assertNotFalse(
			\MantisEnum::getLabel( config_get( 'dwg_status_enum_string' ), $t_result )
		);
	}

	/** Owner eligibility follows $g_own_dwg_threshold, not the assignee one. */
	public function testOwnerEligibilityFollowsOwnThreshold() {
		config_set_global( 'own_dwg_threshold', DEVELOPER );
		$t_creator = $this->makeUser( DEVELOPER );
		$t_low = $this->makeUser( REPORTER );
		$t_ok = $this->makeUser( DEVELOPER );
		$t_id = $this->makeDwg( $t_creator );

		$this->assertFalse( access_dwg_can_be_owner( $t_id, $t_low ) );
		$this->assertTrue( access_dwg_can_be_owner( $t_id, $t_ok ) );
	}

	/** Releasing (NO_USER) is always an eligible target. */
	public function testNoUserIsAnEligibleOwner() {
		$t_creator = $this->makeUser( DEVELOPER );
		$t_id = $this->makeDwg( $t_creator );

		$this->assertTrue( access_dwg_can_be_owner( $t_id, NO_USER ) );
	}

	/**
	 * An unowned document can be claimed at the claim threshold; an owned one
	 * needs the higher assign-owner threshold, because that is a takeover
	 * (D1, as amended 2026-10-20).
	 */
	public function testClaimUnownedVersusTakeover() {
		config_set_global( 'own_dwg_threshold', DEVELOPER );
		config_set_global( 'dwg_claim_owner_threshold', DEVELOPER );
		config_set_global( 'dwg_assign_owner_threshold', MANAGER );

		$t_creator = $this->makeUser( DEVELOPER );
		$t_dev = $this->makeUser( DEVELOPER );
		$t_mgr = $this->makeUser( MANAGER );
		$t_other = $this->makeUser( DEVELOPER );

		# Unowned: the developer may claim it.
		$t_unowned = $this->makeDwg( $t_creator );
		dwg_set_field( $t_unowned, 'owner_id', NO_USER );
		$this->assertTrue( access_dwg_can_claim_owner( $t_unowned, $t_dev ) );

		# Owned by somebody else: the developer may not, the manager may.
		$t_owned = $this->makeDwg( $t_creator, $t_other );
		$this->assertFalse( access_dwg_can_claim_owner( $t_owned, $t_dev ) );
		$this->assertTrue( access_dwg_can_claim_owner( $t_owned, $t_mgr ) );
	}

	/** The current owner may always release; a bystander may not. */
	public function testReleaseOwnership() {
		config_set_global( 'own_dwg_threshold', DEVELOPER );
		config_set_global( 'dwg_assign_owner_threshold', MANAGER );

		$t_creator = $this->makeUser( DEVELOPER );
		$t_owner = $this->makeUser( DEVELOPER );
		$t_bystander = $this->makeUser( DEVELOPER );
		$t_mgr = $this->makeUser( MANAGER );

		$t_id = $this->makeDwg( $t_creator, $t_owner );

		$this->assertTrue( access_dwg_can_release_owner( $t_id, $t_owner ) );
		$this->assertFalse( access_dwg_can_release_owner( $t_id, $t_bystander ) );
		$this->assertTrue( access_dwg_can_release_owner( $t_id, $t_mgr ) );

		# Nothing to release once it is unowned.
		dwg_set_field( $t_id, 'owner_id', NO_USER );
		$this->assertFalse( access_dwg_can_release_owner( $t_id, $t_owner ) );
	}

	/**
	 * Claiming the assignee role has no takeover restriction: picking up the
	 * next task step from a colleague is the normal workflow.
	 */
	public function testAssigneeClaimHasNoTakeoverRestriction() {
		config_set_global( 'dwg_claim_threshold', UPDATER );
		config_set_global( 'handle_dwg_threshold', UPDATER );

		$t_creator = $this->makeUser( DEVELOPER );
		$t_holder = $this->makeUser( UPDATER );
		$t_next = $this->makeUser( UPDATER );
		$t_low = $this->makeUser( VIEWER );

		$t_id = $this->makeDwg( $t_creator, $t_creator, $t_holder );

		$this->assertTrue( access_dwg_can_claim_handler( $t_id, $t_next ) );
		$this->assertFalse( access_dwg_can_claim_handler( $t_id, $t_low ) );
	}

	/** An owner must hold every license the document requires (D5). */
	public function testOwnerMustHoldRequiredLicenses() {
		$t_creator = $this->makeUser( DEVELOPER );
		$t_id = $this->makeDwg( $t_creator );

		# With licenses off the check is a no-op, whatever is attached.
		config_set_global( 'licenses_enabled', OFF );
		$this->assertTrue( access_dwg_user_holds_licenses( $t_id, $t_creator ) );

		config_set_global( 'licenses_enabled', ON );

		# A document requiring nothing is held by everyone.
		$this->assertTrue( access_dwg_user_holds_licenses( $t_id, $t_creator ) );

		$t_license_id = license_create( 'owntest' . rand(), 'ownership test', 10 );
		license_add_dwg( $t_license_id, $t_id, DEVELOPER );

		try {
			$this->assertFalse( access_dwg_user_holds_licenses( $t_id, $t_creator ) );
			$this->assertFalse( access_dwg_can_be_owner( $t_id, $t_creator ) );

			license_add_user( $t_license_id, $t_creator, DEVELOPER );
			$this->assertTrue( access_dwg_user_holds_licenses( $t_id, $t_creator ) );
		} finally {
			db_query( 'DELETE FROM {license_dwg_list} WHERE license_id=' . db_param(), array( $t_license_id ) );
			db_query( 'DELETE FROM {license_user_list} WHERE license_id=' . db_param(), array( $t_license_id ) );
			license_delete( $t_license_id );
		}
	}

	/** The owner filter is a first-class filter property. */
	public function testOwnedByFilter() {
		$t_creator = $this->makeUser( DEVELOPER );
		$t_filter = filter_dwg_create_owned_by( $this->project_id, $t_creator );

		$this->assertArrayHasKey( FILTER_PROPERTY_OWNER_ID, $t_filter );
		$this->assertEquals( array( '0' => $t_creator ), $t_filter[FILTER_PROPERTY_OWNER_ID] );

		$t_unowned = filter_dwg_create_owned_by( $this->project_id, 0 );
		$this->assertEquals( array( '0' => META_FILTER_NONE ), $t_unowned[FILTER_PROPERTY_OWNER_ID] );
	}

	/** owner_id is available as a document list column and sorts by user name. */
	public function testOwnerIsAListColumn() {
		$this->assertContains( 'owner_id', columns_dwg_get_standard() );
		$this->assertTrue( function_exists( 'print_dwg_column_owner_id' ) );
		$this->assertTrue( function_exists( 'print_dwg_column_title_owner_id' ) );
	}

	/** History renders owner_id with the Owner label, not "Assigned To". */
	public function testHistoryLabelsOwner() {
		$this->assertEquals(
			lang_get( 'dwg_owner' ),
			history_dwg_localize_field_name( 'owner_id' )
		);
		$this->assertNotEquals(
			history_dwg_localize_field_name( 'handler_id' ),
			history_dwg_localize_field_name( 'owner_id' )
		);
	}
}
