<?php
# Doctis user-transfer tests: handing on what a disabled user still holds.
#
# A staff member who leaves is removed by disabling their account, never by
# deleting it, so the holdings have to be transferred separately. See
# doc/dwg/USER_LIFECYCLE.md.
#
# Uses the live database: creates throwaway users, a scratch project and
# documents, and removes them in tearDown. The documents never get a primary
# file, so nothing is written to git.

namespace Mantis\tests\Mantis;

require_api( 'category_api.php' );
require_api( 'dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'user_transfer_api.php' );

class UserTransferTest extends MantisCoreBase {
	private $user_ids = array();
	private $emails = array();
	private $dwg_ids = array();
	private $project_id = 0;
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		self::login();

		foreach( array( 'own_dwg_threshold', 'handle_dwg_threshold', 'licenses_enabled' ) as $t_key ) {
			$this->saved[$t_key] = config_get_global( $t_key );
		}
		config_set_global( 'own_dwg_threshold', DEVELOPER );
		config_set_global( 'handle_dwg_threshold', UPDATER );

		$this->project_id = (int)project_create( 'xfertest' . rand(), '', 10, VS_PUBLIC );
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
			db_query( 'UPDATE {user} SET reports_to=0 WHERE reports_to=' . db_param(), array( $t_id ) );
			user_delete( $t_id );
		}
		foreach( $this->saved as $t_key => $t_value ) {
			config_set_global( $t_key, $t_value );
		}
		global $g_project_override;
		$g_project_override = null;
		parent::tearDown();
	}

	private function makeUser( int $p_access, bool $p_enabled = true, int $p_reports_to = 0 ): int {
		$t_name = 'xfertest' . rand();
		$t_email = strtolower( $t_name ) . '@example.test';
		$t_id = (int)user_get_id_by_cookie( user_create( $t_name, 'password', $t_email ) );
		$this->user_ids[] = $t_id;
		$this->emails[] = $t_email;
		db_query( 'UPDATE {user} SET enabled=' . db_param() . ', reports_to=' . db_param() . ' WHERE id=' . db_param(),
			array( $p_enabled, $p_reports_to, $t_id ) );
		user_clear_cache( $t_id );
		project_add_user( $this->project_id, $t_id, $p_access );
		return $t_id;
	}

	private function makeDwg( int $p_creator, int $p_owner = 0, int $p_handler = 0 ): int {
		$t_dwg = new \DwgData();
		$t_dwg->project_id = $this->project_id;
		$t_dwg->creator_id = $p_creator;
		$t_dwg->owner_id = $p_owner;
		$t_dwg->handler_id = $p_handler;
		$t_dwg->category_id = 0;
		$t_dwg->summary = 'transfer test';
		$t_dwg->title = 'transfer test';
		$t_dwg->description = 'transfer test';
		$t_dwg->status = config_get( 'dwg_submit_status' );
		$t_dwg->revision_date = 1;
		$t_dwg->release_date = 1;
		$t_dwg->due_date = 1;

		$t_id = (int)$t_dwg->create();
		$this->dwg_ids[] = $t_id;
		return $t_id;
	}

	/** A user with nothing live holds nothing to hand on. */
	public function testNoHoldings() {
		$t_id = $this->makeUser( DEVELOPER );

		$this->assertFalse( user_transfer_has_holdings( $t_id ) );
		$this->assertEquals( 0, user_transfer_summary( $t_id )['total'] );
	}

	/** Owned and assigned documents are both counted, and separately. */
	public function testSummaryCountsOwnedAndAssigned() {
		$t_leaver = $this->makeUser( DEVELOPER );
		$t_other = $this->makeUser( DEVELOPER );

		$this->makeDwg( $t_other, $t_leaver, 0 );
		$this->makeDwg( $t_other, $t_other, $t_leaver );
		$this->makeDwg( $t_other, $t_leaver, $t_leaver );

		$t_summary = user_transfer_summary( $t_leaver );

		$this->assertEquals( 2, $t_summary['dwg_owner'] );
		$this->assertEquals( 2, $t_summary['dwg_handler'] );
		$this->assertEquals( 4, $t_summary['total'] );
		$this->assertTrue( user_transfer_has_holdings( $t_leaver ) );
	}

	/** The creator is never a holding: provenance is not transferable. */
	public function testCreatorIsNotAHolding() {
		$t_leaver = $this->makeUser( DEVELOPER );
		$t_other = $this->makeUser( DEVELOPER );
		$this->makeDwg( $t_leaver, $t_other, $t_other );

		$this->assertFalse( user_transfer_has_holdings( $t_leaver ) );
	}

	/** Transferring moves the live fields and leaves the creator alone. */
	public function testTransferMovesOwnerAndHandler() {
		$t_leaver = $this->makeUser( DEVELOPER );
		$t_successor = $this->makeUser( DEVELOPER );
		$t_dwg_id = $this->makeDwg( $t_leaver, $t_leaver, $t_leaver );

		$t_report = user_transfer_perform( $t_leaver, $t_successor,
			array( 'dwg_owner', 'dwg_handler' ) );

		$this->assertEquals( 1, $t_report['dwg_owner']['moved'] );
		$this->assertEquals( 1, $t_report['dwg_handler']['moved'] );
		$this->assertEquals( $t_successor, dwg_get_field( $t_dwg_id, 'owner_id' ) );
		$this->assertEquals( $t_successor, dwg_get_field( $t_dwg_id, 'handler_id' ) );
		$this->assertEquals( $t_leaver, dwg_get_field( $t_dwg_id, 'creator_id' ),
			'the creator must not be transferred' );
		$this->assertFalse( user_transfer_has_holdings( $t_leaver ) );
	}

	/** Only the categories asked for are moved. */
	public function testTransferHonoursCategorySelection() {
		$t_leaver = $this->makeUser( DEVELOPER );
		$t_successor = $this->makeUser( DEVELOPER );
		$t_dwg_id = $this->makeDwg( $t_successor, $t_leaver, $t_leaver );

		user_transfer_perform( $t_leaver, $t_successor, array( 'dwg_owner' ) );

		$this->assertEquals( $t_successor, dwg_get_field( $t_dwg_id, 'owner_id' ) );
		$this->assertEquals( $t_leaver, dwg_get_field( $t_dwg_id, 'handler_id' ),
			'dwg_handler was not selected, so it must be untouched' );
	}

	/**
	 * A successor who cannot own documents has those rows skipped rather than
	 * the whole transfer failing.
	 */
	public function testIneligibleSuccessorIsSkippedNotFatal() {
		$t_leaver = $this->makeUser( DEVELOPER );
		$t_low = $this->makeUser( REPORTER );
		$t_dwg_id = $this->makeDwg( $t_leaver, $t_leaver, 0 );

		$t_report = user_transfer_perform( $t_leaver, $t_low, array( 'dwg_owner' ) );

		$this->assertEquals( 0, $t_report['dwg_owner']['moved'] );
		$this->assertEquals( 1, $t_report['dwg_owner']['skipped'] );
		$this->assertEquals( $t_leaver, dwg_get_field( $t_dwg_id, 'owner_id' ),
			'an ineligible successor must not be recorded as owner' );
	}

	/** Eligibility is reported before anything is moved. */
	public function testEligibilityReport() {
		$t_leaver = $this->makeUser( DEVELOPER );
		$t_low = $this->makeUser( REPORTER );
		$t_ok = $this->makeUser( DEVELOPER );
		$this->makeDwg( $t_ok, $t_leaver, 0 );

		$this->assertEquals( 1, user_transfer_eligibility( $t_leaver, $t_low )['dwg_owner']['skip'] );
		$this->assertEquals( 1, user_transfer_eligibility( $t_leaver, $t_ok )['dwg_owner']['ok'] );
	}

	/** The suggestion is the user's manager when that account is enabled. */
	public function testSuggestionIsTheManager() {
		$t_manager = $this->makeUser( MANAGER );
		$t_leaver = $this->makeUser( DEVELOPER, true, $t_manager );

		$this->assertEquals( $t_manager, user_transfer_suggest_successor( $t_leaver ) );
	}

	/** With no manager recorded there is no suggestion, and that is not an error. */
	public function testNoManagerGivesNoSuggestion() {
		$t_leaver = $this->makeUser( DEVELOPER );

		$this->assertEquals( 0, user_transfer_suggest_successor( $t_leaver ) );
	}

	/**
	 * A disabled link in the chain is skipped, not treated as the answer.
	 *
	 * user_ensure_valid_reports_to() only validates at write time, so a
	 * manager disabled later still has reports pointing at them.
	 */
	public function testSuggestionClimbsPastDisabledManagers() {
		$t_top = $this->makeUser( MANAGER );
		$t_middle = $this->makeUser( MANAGER, /* enabled */ false, $t_top );
		$t_leaver = $this->makeUser( DEVELOPER, true, $t_middle );

		$this->assertEquals( $t_top, user_transfer_suggest_successor( $t_leaver ) );
	}

	/** A reporting cycle terminates the walk instead of spinning. */
	public function testSuggestionSurvivesACycle() {
		$t_a = $this->makeUser( MANAGER, /* enabled */ false );
		$t_b = $this->makeUser( MANAGER, /* enabled */ false, $t_a );
		db_query( 'UPDATE {user} SET reports_to=' . db_param() . ' WHERE id=' . db_param(),
			array( $t_b, $t_a ) );
		user_clear_cache( $t_a );
		$t_leaver = $this->makeUser( DEVELOPER, true, $t_b );

		$this->assertEquals( 0, user_transfer_suggest_successor( $t_leaver ) );
	}

	/** A category whose default assignee is disabled stops auto-assigning. */
	public function testDisabledCategoryDefaultDoesNotAutoAssign() {
		$t_leaver = $this->makeUser( UPDATER );
		$t_creator = $this->makeUser( DEVELOPER );
		$t_category_id = (int)category_add( $this->project_id, 'xfercat' . rand() );
		category_update( $t_category_id, category_get_field( $t_category_id, 'name' ), $t_leaver );

		try {
			# Enabled: the category's default assignee is picked up.
			$t_dwg = new \DwgData();
			$t_dwg->project_id = $this->project_id;
			$t_dwg->creator_id = $t_creator;
			$t_dwg->category_id = $t_category_id;
			$t_dwg->summary = 'cat test';
			$t_dwg->title = 'cat test';
			$t_dwg->description = 'cat test';
			$t_dwg->status = config_get( 'dwg_submit_status' );
			$t_dwg->revision_date = 1;
			$t_dwg->release_date = 1;
			$t_dwg->due_date = 1;
			$t_id = (int)$t_dwg->create();
			$this->dwg_ids[] = $t_id;
			$this->assertEquals( $t_leaver, dwg_get_field( $t_id, 'handler_id' ) );

			# Disabled: it is not.
			db_query( 'UPDATE {user} SET enabled=' . db_param() . ' WHERE id=' . db_param(),
				array( false, $t_leaver ) );
			user_clear_cache( $t_leaver );

			$t_dwg2 = new \DwgData();
			$t_dwg2->project_id = $this->project_id;
			$t_dwg2->creator_id = $t_creator;
			$t_dwg2->category_id = $t_category_id;
			$t_dwg2->summary = 'cat test 2';
			$t_dwg2->title = 'cat test 2';
			$t_dwg2->description = 'cat test 2';
			$t_dwg2->status = config_get( 'dwg_submit_status' );
			$t_dwg2->revision_date = 1;
			$t_dwg2->release_date = 1;
			$t_dwg2->due_date = 1;
			$t_id2 = (int)$t_dwg2->create();
			$this->dwg_ids[] = $t_id2;
			$this->assertEquals( NO_USER, dwg_get_field( $t_id2, 'handler_id' ) );
		} finally {
			category_remove( $t_category_id );
		}
	}

	/** The category list and its eligibility requirements are well formed. */
	public function testCategoriesAreWellFormed() {
		$t_categories = user_transfer_categories();

		$this->assertArrayHasKey( 'dwg_owner', $t_categories );
		$this->assertArrayHasKey( 'bug_handler', $t_categories );
		$this->assertArrayHasKey( 'meeting_action', $t_categories );
		$this->assertArrayHasKey( 'topic_expectation', $t_categories );

		foreach( $t_categories as $t_key => $t_category ) {
			$this->assertNotEmpty( lang_get( $t_category['label'] ), $t_key . ' has no label' );
			$this->assertContains( $t_category['eligible'],
				array( '', 'own_dwg', 'handle_dwg', 'handle_bug' ), $t_key );
		}
	}
}
