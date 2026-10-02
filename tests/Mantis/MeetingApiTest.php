<?php
# Doctis meeting API and Meeting Assistant document-processing tests.
#
# Uses the live database: creates throwaway users and meetings and removes
# them (with any emails queued to them) in tearDown. Document storage is
# disabled for the duration ($g_meeting_project_id = 0), so nothing is written
# to git.

namespace Mantis\tests\Mantis;

require_api( 'meeting_api.php' );
require_once dirname( __DIR__, 2 ) . '/ai_assist_meeting_api.php';

class MeetingApiTest extends MantisCoreBase {
	private $user_ids = array();
	private $emails = array();
	private $meeting_ids = array();
	private $project_id_saved;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->project_id_saved = config_get_global( 'meeting_project_id' );
		config_set_global( 'meeting_project_id', 0 );
	}

	protected function tearDown(): void {
		foreach( $this->meeting_ids as $t_id ) {
			db_query( 'DELETE FROM {meeting_action} WHERE meeting_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {meeting_invitee} WHERE meeting_id=' . db_param(), array( $t_id ) );
			db_query( 'DELETE FROM {meeting} WHERE id=' . db_param(), array( $t_id ) );
		}
		foreach( $this->emails as $t_email ) {
			db_query( 'DELETE FROM {email} WHERE email=' . db_param(), array( $t_email ) );
		}
		foreach( $this->user_ids as $t_id ) {
			user_delete( $t_id );
		}
		config_set_global( 'meeting_project_id', $this->project_id_saved );
		parent::tearDown();
	}

	/** Create a throwaway user; $p_invite is the meeting_invite preference. */
	private function makeUser( int $p_invite ): int {
		$t_name = 'MeetingTest' . rand();
		$t_email = strtolower( $t_name ) . '@example.test';
		$t_id = user_get_id_by_cookie( user_create( $t_name, 'password', $t_email ) );
		user_set_fields( $t_id, array( 'meeting_invite' => $p_invite, 'realname' => $t_name ) );
		$this->user_ids[] = $t_id;
		$this->emails[] = $t_email;
		return (int)$t_id;
	}

	/** Create a meeting chaired by $p_chair and remember it for cleanup. */
	private function makeMeeting( int $p_chair, int $p_minute_taker, array $p_invitee_ids ): array {
		$t_invitees = array();
		foreach( $p_invitee_ids as $t_id ) {
			$t_invitees[] = array( 'user_id' => $t_id, 'name' => '' );
		}
		$t_id = meeting_create( array(
			'doc_ref' => meeting_unique_ref( 'MIN-TEST-' . rand() ),
			'title' => 'Test meeting', 'department' => 'SYS',
			'chair_id' => $p_chair, 'minute_taker_id' => $p_minute_taker,
			'date_start' => time(), 'duration' => 30, 'location' => 'Teams',
			'created_by' => $p_chair,
		), $t_invitees );
		$this->meeting_ids[] = $t_id;
		return meeting_get( $t_id );
	}

	# ── Pure helpers ─────────────────────────────────────────────────────────

	public function testMarkApprovedStampsFrontmatterAndStatusLine() {
		$t_draft = "---\ndoc_id:         MIN-SYS-1\nstatus:         Draft Minutes   # c\neffective_date:\n---\n\n"
			. "# Title\n\n**Status:** Draft Minutes  \nkept line  \n";
		$t_approved = meeting_record_mark_approved( $t_draft, '2026-10-02' );

		$this->assertStringContainsString( "status:         Approved Minutes\n", $t_approved );
		$this->assertStringContainsString( "effective_date: 2026-10-02\n", $t_approved );
		$this->assertStringContainsString( "**Status:** Approved Minutes\n", $t_approved );
		$this->assertStringContainsString( "kept line  \n", $t_approved );   # hard break preserved
		$this->assertSame( "no frontmatter\n", meeting_record_mark_approved( "no frontmatter\n", '2026-10-02' ) );
	}

	public function testRecordBodyStripsOnlyFrontmatter() {
		$this->assertSame( "# Title\nbody\n", meeting_record_body( "---\na: b\n---\n\n# Title\nbody\n" ) );
		$this->assertSame( "# Title\n", meeting_record_body( "# Title\n" ) );
	}

	public function testBusinessDaysSkipWeekends() {
		$t_friday = strtotime( '2026-10-02 12:00' );
		$this->assertSame( '2026-10-06', date( 'Y-m-d', meeting_business_days_after( $t_friday, 2 ) ) );
		$this->assertSame( '2026-10-07', date( 'Y-m-d', meeting_business_days_after( $t_friday, 3 ) ) );
	}

	public function testIdListKeepsPositiveUniqueIds() {
		$this->assertSame( array( 3, 7 ), ai_assist_meeting_id_list( '3, 3,x,0,-1,7' ) );
		$this->assertSame( array(), ai_assist_meeting_id_list( '' ) );
	}

	# ── Roles and listing ────────────────────────────────────────────────────

	public function testRolesAndPermissions() {
		$t_chair = $this->makeUser( 0 );
		$t_taker = $this->makeUser( 2 );
		$t_guest = $this->makeUser( 2 );
		$t_other = $this->makeUser( 2 );
		$t_meeting = $this->makeMeeting( $t_chair, $t_taker, array( $t_taker, $t_guest ) );

		$this->assertSame( 'chair', meeting_user_role( $t_meeting, $t_chair ) );
		$this->assertSame( 'minute_taker', meeting_user_role( $t_meeting, $t_taker ) );
		$this->assertSame( 'invitee', meeting_user_role( $t_meeting, $t_guest ) );
		$this->assertSame( '', meeting_user_role( $t_meeting, $t_other ) );

		$this->assertTrue( meeting_user_can_write_minutes( $t_meeting, $t_chair ) );
		$this->assertTrue( meeting_user_can_write_minutes( $t_meeting, $t_taker ) );
		$this->assertFalse( meeting_user_can_write_minutes( $t_meeting, $t_guest ) );

		# Only the chair approves, and only minutes awaiting approval.
		$this->assertFalse( meeting_user_can_approve_minutes( $t_meeting, $t_chair ) );
		meeting_update( (int)$t_meeting['id'], array( 'status' => MEETING_MINUTES ) );
		$t_meeting = meeting_get( (int)$t_meeting['id'] );
		$this->assertTrue( meeting_user_can_approve_minutes( $t_meeting, $t_chair ) );
		$this->assertFalse( meeting_user_can_approve_minutes( $t_meeting, $t_taker ) );
	}

	public function testListingForUserIncludesCancelled() {
		$t_chair = $this->makeUser( 0 );
		$t_guest = $this->makeUser( 2 );
		$t_meeting = $this->makeMeeting( $t_chair, 0, array( $t_guest ) );

		$t_listed = array_column( meeting_get_for_user( $t_guest ), 'role', 'id' );
		$this->assertSame( 'invitee', $t_listed[$t_meeting['id']] ?? null );

		meeting_update( (int)$t_meeting['id'], array( 'status' => MEETING_CANCELLED ) );
		$this->assertArrayHasKey( $t_meeting['id'], array_column( meeting_get_for_user( $t_guest ), 'role', 'id' ) );
		$this->assertFalse( meeting_user_can_manage( meeting_get( (int)$t_meeting['id'] ), $t_chair ) );
	}

	# ── Record rewriting ─────────────────────────────────────────────────────

	public function testRecordSetReplacesValuesLiterally() {
		$t_record = "---\nlocation:       Teams\ntime:           10:00\n---\n\n**Location:** Teams\n**Location:** second\n";
		$t_new = meeting_record_set( $t_record, array( 'location' => 'Room $1 \\2' ), array( 'Location' => 'Room $1 \\2' ) );
		$this->assertStringContainsString( "location:       Room \$1 \\2\n", $t_new );
		$this->assertStringContainsString( "**Location:** Room \$1 \\2\n**Location:** second\n", $t_new );  # first only
		$this->assertStringContainsString( "time:           10:00\n", $t_new );
	}

	public function testInviteesTableRebuilt() {
		$t_record = "# M\n\n## 1. Invitees\n\n> note\n\n| Name | Role | Required |\n|---|---|---|\n| Old | x | y |\n\n## 2. Agenda\n| a | b |\n";
		$t_new = meeting_record_set_invitees_table( $t_record, array( array( 'New A', 'Dev', 'Required' ), array( 'B|C' ) ) );
		$this->assertStringContainsString( "|---|---|---|\n| New A | Dev | Required |\n| B\\|C |  |  |\n\n## 2. Agenda\n| a | b |", $t_new );
		$this->assertStringNotContainsString( 'Old', $t_new );
		$this->assertSame( "# No table\n", meeting_record_set_invitees_table( "# No table\n", array( array( 'x' ) ) ) );
	}

	# ── Calendar ─────────────────────────────────────────────────────────────

	public function testIcsRequestAndCancel() {
		$t_chair = $this->makeUser( 0 );
		$t_guest = $this->makeUser( 2 );
		$t_meeting = $this->makeMeeting( $t_chair, 0, array( $t_guest ) );
		meeting_update( (int)$t_meeting['id'], array( 'title' => 'Review; budget, Q4 — très long titre pour plier la ligne au-delà de soixante-quinze octets', 'sequence' => 3 ) );
		$t_meeting = meeting_get( (int)$t_meeting['id'] );

		$t_ics = meeting_ics( $t_meeting, 'REQUEST' );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $t_ics );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $t_ics );
		$this->assertStringContainsString( "SEQUENCE:3\r\n", $t_ics );
		$this->assertMatchesRegularExpression( '/UID:doctis-meeting-' . $t_meeting['id'] . '@/', $t_ics );
		$this->assertStringContainsString( 'DTSTART:' . gmdate( 'Ymd\THis\Z', (int)$t_meeting['date_start'] ), $t_ics );
		$this->assertStringContainsString( 'ATTENDEE;CN="', $t_ics );
		$this->assertStringContainsString( 'SUMMARY:Review\; budget\, Q4', $t_ics );
		foreach( explode( "\r\n", $t_ics ) as $t_line ) {
			$this->assertLessThanOrEqual( 75, strlen( $t_line ) );
			$this->assertTrue( mb_check_encoding( $t_line, 'UTF-8' ) );   # folds never split a character
		}

		$t_cancel = meeting_ics( $t_meeting, 'CANCEL' );
		$this->assertStringContainsString( "METHOD:CANCEL\r\n", $t_cancel );
		$this->assertStringContainsString( "STATUS:CANCELLED\r\n", $t_cancel );
		$this->assertStringNotContainsString( 'ATTENDEE', meeting_ics( $t_meeting, 'PUBLISH' ) );
	}

	# ── Actions ──────────────────────────────────────────────────────────────

	public function testActionsParseValidatesOwnersAndDates() {
		$t_chair = $this->makeUser( 0 );
		$t_guest = $this->makeUser( 2 );
		$t_outsider = $this->makeUser( 2 );
		$t_meeting = $this->makeMeeting( $t_chair, 0, array( $t_guest ) );

		$t_actions = meeting_actions_parse( json_encode( array(
			array( 'ref' => 'A1', 'action' => 'Write it up', 'owner_id' => $t_guest, 'owner' => 'ignored', 'due' => '2026-10-23' ),
			array( 'action' => 'Outsider task', 'owner_id' => $t_outsider, 'owner' => 'Someone Else', 'due' => '23/10/2026' ),
			array( 'action' => '   ' ),
			'not an object',
		) ), $t_meeting );

		$this->assertCount( 2, $t_actions );
		$this->assertSame( $t_guest, $t_actions[0]['owner_id'] );
		$this->assertSame( meeting_user_display_name( $t_guest ), $t_actions[0]['owner_name'] );
		$this->assertSame( '2026-10-23', date( 'Y-m-d', $t_actions[0]['due_date'] ) );
		$this->assertSame( 0, $t_actions[1]['owner_id'] );              # not a participant
		$this->assertSame( 'Someone Else', $t_actions[1]['owner_name'] );
		$this->assertSame( 0, $t_actions[1]['due_date'] );               # invalid date dropped
		$this->assertSame( 'A2', $t_actions[1]['ref'] );
		$this->assertSame( array(), meeting_actions_parse( 'not json', $t_meeting ) );
	}

	public function testSeriesAndOpenActions() {
		$t_chair = $this->makeUser( 0 );
		$t_first = $this->makeMeeting( $t_chair, 0, array() );
		$t_second = $this->makeMeeting( $t_chair, 0, array() );
		meeting_update( (int)$t_second['id'], array( 'series_id' => (int)$t_first['id'] ) );
		$t_second = meeting_get( (int)$t_second['id'] );

		$this->assertSame( (int)$t_first['id'], meeting_series_root( $t_second ) );
		$this->assertSame( array( (int)$t_first['id'], (int)$t_second['id'] ),
			array_map( 'intval', array_column( meeting_series_get( $t_second ), 'id' ) ) );

		meeting_actions_replace( (int)$t_first['id'], array(
			array( 'ref' => 'A1', 'description' => 'Carry me', 'owner_id' => 0, 'owner_name' => '', 'due_date' => 0 ) ) );
		$this->assertSame( array(), meeting_actions_open_in_series( $t_second ) );   # minutes not approved yet
		meeting_update( (int)$t_first['id'], array( 'status' => MEETING_APPROVED ) );
		$t_open = meeting_actions_open_in_series( $t_second );
		$this->assertCount( 1, $t_open );
		$this->assertSame( $t_first['doc_ref'], $t_open[0]['doc_ref'] );
		db_query( 'DELETE FROM {meeting_action} WHERE meeting_id=' . db_param(), array( (int)$t_first['id'] ) );
	}

	# ── Managing and hand uploads ────────────────────────────────────────────

	public function testChangeRequiresManagerAndValidInvitees() {
		$t_me = auth_get_current_user_id();
		$t_guest = $this->makeUser( 2 );
		$t_opted_out = $this->makeUser( 0 );

		$t_foreign = $this->makeMeeting( $this->makeUser( 0 ), 0, array( $t_guest ) );
		try {
			meeting_change( $t_foreign, $t_me, array( 'duration' => 45 ), array(), array(), array() );
			$this->fail( 'non-manager changed a meeting' );
		} catch( \Mantis\Exceptions\ClientException $e ) {
			$this->assertStringContainsString( 'Only the chair or organiser', $e->getMessage() );
		}

		$t_own = $this->makeMeeting( $t_me, 0, array( $t_guest ) );
		try {
			meeting_change( $t_own, $t_me, array(), array( $t_opted_out ), array(), array() );
			$this->fail( 'invited a user who does not accept invitations' );
		} catch( \Mantis\Exceptions\ClientException $e ) {
			$this->assertStringContainsString( 'does not accept', $e->getMessage() );
		}
		try {
			meeting_change( meeting_get( (int)$t_own['id'] ), $t_me, array( 'minute_taker_id' => $t_opted_out ), array(), array(), array() );
			$this->fail( 'minute taker who is not an invitee' );
		} catch( \Mantis\Exceptions\ClientException $e ) {
			$this->assertStringContainsString( 'must be an invitee', $e->getMessage() );
		}

		# A valid change: new time, remove the guest, add a named guest; sequence bumps.
		$t_own = meeting_get( (int)$t_own['id'] );
		$t_row = meeting_invitees_get( (int)$t_own['id'] )[0];
		$t_result = meeting_change( $t_own, $t_me, array( 'date_start' => (int)$t_own['date_start'] + 3600 ),
			array(), array( 'Visitor' ), array( (int)$t_row['id'] ) );
		$t_after = meeting_get( (int)$t_own['id'] );
		$this->assertTrue( $t_result['changed'] );
		$this->assertSame( (int)$t_own['sequence'] + 1, (int)$t_after['sequence'] );
		$this->assertSame( array( 'Visitor' ), array_column( meeting_invitees_get( (int)$t_own['id'] ), 'name' ) );
		$this->assertSame( array( meeting_user_notify_email( $t_guest ) ), array_column( $t_result['removed'], 'email' ) );
	}

	public function testCancelAndHandUploadRules() {
		$t_me = auth_get_current_user_id();
		$t_taker = $this->makeUser( 2 );
		$t_meeting = $this->makeMeeting( $t_me, $t_taker, array( $t_taker ) );
		meeting_update( (int)$t_meeting['id'], array( 'dwg_id' => 999999 ) );   # pretend it has a document

		$this->assertNull( meeting_primary_upload_refusal( 999999, $t_taker ) );
		$this->assertStringContainsString( 'only its chair', meeting_primary_upload_refusal( 999999, $this->makeUser( 2 ) ) );
		$this->assertNull( meeting_primary_upload_refusal( 123456789, $t_taker ) );   # not a meeting document

		meeting_update( (int)$t_meeting['id'], array( 'dwg_id' => 0 ) );
		meeting_cancel( meeting_get( (int)$t_meeting['id'] ), $t_me, 'Clash' );
		$t_meeting = meeting_get( (int)$t_meeting['id'] );
		$this->assertSame( MEETING_CANCELLED, (int)$t_meeting['status'] );
		meeting_update( (int)$t_meeting['id'], array( 'dwg_id' => 999999 ) );
		$this->assertStringContainsString( 'no longer be changed', meeting_primary_upload_refusal( 999999, $t_taker ) );
	}

	public function testUniqueRefAddsSuffix() {
		$t_meeting = $this->makeMeeting( $this->makeUser( 0 ), 0, array() );
		$this->assertSame( $t_meeting['doc_ref'] . '-2', meeting_unique_ref( $t_meeting['doc_ref'] ) );
	}

	# ── Model-supplied attributes ────────────────────────────────────────────

	/** Process an agenda marker as the logged-in user; returns the result. */
	private function processAgenda( string $p_attrs ): array {
		$t_reply = "Draft.\n<<<MEETING_DOCUMENT type=\"agenda\" " . $p_attrs . ">>>\n# Agenda\n<<<END_MEETING_DOCUMENT>>>\nDone.";
		$t_result = ai_assist_process_meeting_document( $t_reply, auth_get_current_user_id() );
		if( !empty( $t_result['meeting_id'] ) ) {
			$this->meeting_ids[] = (int)$t_result['meeting_id'];
		}
		return $t_result;
	}

	public function testAgendaRejectsUnknownDepartmentAndBadDate() {
		$t_result = $this->processAgenda( 'dept="NOPE" date="2026-10-20" time="10:00" title="x"' );
		$this->assertFalse( $t_result['saved'] );
		$this->assertStringContainsString( 'Unknown department', $t_result['error'] );

		$t_result = $this->processAgenda( 'dept="SYS" date="20/10/2026" time="10am" title="x"' );
		$this->assertFalse( $t_result['saved'] );
		$this->assertStringContainsString( 'date/time', $t_result['error'] );
	}

	public function testAgendaBuildsReferenceAndFiltersInvitees() {
		$t_candidate = $this->makeUser( 2 );
		$t_opted_out = $this->makeUser( 0 );
		$t_result = $this->processAgenda(
			'doc_id="../../etc/passwd" dept="sys" date="2026-10-20" time="10:00" duration="9999"'
			. ' title="Planning" invitee_ids="' . $t_candidate . ',' . $t_opted_out . ',999999"'
			. ' minute_taker_id="' . $t_opted_out . '" guests="Visitor One; "' );

		$this->assertNull( $t_result['error'] );
		$this->assertSame( "Draft.\n\nDone.", $t_result['stripped_reply'] );
		$this->assertMatchesRegularExpression( '/^MIN-SYS-20261020(-\d+)?$/', $t_result['doc_id'] );
		$this->assertFalse( $t_result['stored'] );   # no meeting project during tests

		$t_meeting = meeting_get( (int)$t_result['meeting_id'] );
		$this->assertSame( 60, (int)$t_meeting['duration'] );          # out-of-range → default
		$this->assertSame( 0, (int)$t_meeting['minute_taker_id'] );    # not a candidate
		$this->assertSame( auth_get_current_user_id(), (int)$t_meeting['chair_id'] );

		$t_invitees = meeting_invitees_get( (int)$t_meeting['id'] );
		$this->assertSame( array( $t_candidate, 0 ), array_map( 'intval', array_column( $t_invitees, 'user_id' ) ) );
		$this->assertSame( 'Visitor One', $t_invitees[1]['name'] );
		$this->assertSame( array( $t_candidate ), array_map( function( $r ) {
			return user_get_id_by_email( $r['email'] );
		}, $t_result['emails_sent'] ) );
	}

	public function testMinutesRequireMeetingRoleAndFilterAttendance() {
		$t_chair = $this->makeUser( 0 );
		$t_invitee = $this->makeUser( 2 );
		$t_outsider = $this->makeUser( 2 );

		# Logged-in user has no role in this meeting.
		$t_foreign = $this->makeMeeting( $t_chair, 0, array( $t_invitee ) );
		$t_result = ai_assist_process_meeting_document(
			'<<<MEETING_DOCUMENT type="minutes" meeting_id="' . $t_foreign['id'] . '">>>x<<<END_MEETING_DOCUMENT>>>',
			auth_get_current_user_id() );
		$this->assertFalse( $t_result['saved'] );
		$this->assertStringContainsString( 'Only the chair', $t_result['error'] );

		# Logged-in user chairs this one; attendance may name only invitees.
		$t_own = $this->makeMeeting( auth_get_current_user_id(), 0, array( $t_invitee ) );
		$t_result = ai_assist_process_meeting_document(
			'<<<MEETING_DOCUMENT type="minutes" meeting_id="' . $t_own['id'] . '" attended_ids="'
			. $t_invitee . ',' . $t_outsider . '">>>---' . "\nstatus: Draft Minutes\n---\nMinutes<<<END_MEETING_DOCUMENT>>>",
			auth_get_current_user_id() );
		$this->assertTrue( $t_result['saved'] );
		$this->assertSame( MEETING_MINUTES, (int)meeting_get( (int)$t_own['id'] )['status'] );
		$t_attendance = array_column( meeting_invitees_get( (int)$t_own['id'] ), 'attendance', 'user_id' );
		$this->assertSame( array( $t_invitee => MEETING_ATTENDED ), array_map( 'intval', $t_attendance ) );
		$this->assertSame( array( $t_invitee ), array_map( function( $r ) {
			return user_get_id_by_email( $r['email'] );
		}, $t_result['emails_sent'] ) );
	}
}
