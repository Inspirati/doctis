<?php
# Meeting timezone regressions. Disposable blank-email accounts, no API calls.
namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class MeetingTimezoneTest extends MantisCoreBase {
	private $zone;
	private $users = array();
	private $meetings = array();

	protected function setUp(): void {
		parent::setUp();
		require_api( 'meeting_api.php' );
		$this->zone = date_default_timezone_get();
	}

	protected function tearDown(): void {
		foreach( $this->meetings as $id ) {
			db_query( 'DELETE FROM {meeting_series} WHERE series_id=' . db_param(), array( $id ) );
			db_query( 'DELETE FROM {meeting_invitee} WHERE meeting_id=' . db_param(), array( $id ) );
			db_query( 'DELETE FROM {meeting} WHERE id=' . db_param(), array( $id ) );
		}
		foreach( $this->users as $id ) { user_delete( $id ); }
		date_default_timezone_set( $this->zone );
		parent::tearDown();
	}

	/** @dataProvider recurrences */
	public function testRecurrencesRetainLocalTimeAcrossClockChanges( $zone, $start, $rule, $expected ) {
		date_default_timezone_set( 'UTC' );
		$first = new \DateTimeImmutable( $start, new \DateTimeZone( $zone ) );
		$next = meeting_recurrence_next_start( $first->getTimestamp(), $rule, $zone );
		$actual = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( new \DateTimeZone( $zone ) );
		$this->assertSame( $expected, $actual->format( 'Y-m-d H:i P' ) );
		date_default_timezone_set( 'Pacific/Honolulu' );
		$this->assertSame( $next, meeting_recurrence_next_start( $first->getTimestamp(), $rule, $zone ) );
	}

	public static function recurrences(): array {
		return array(
			array( 'Australia/Sydney', '2026-09-27 09:00', 'weekly', '2026-10-04 09:00 +11:00' ),
			array( 'Australia/Sydney', '2026-03-29 09:00', 'weekly', '2026-04-05 09:00 +10:00' ),
			array( 'Australia/Sydney', '2026-09-20 09:00', 'fortnightly', '2026-10-04 09:00 +11:00' ),
			array( 'Australia/Sydney', '2026-03-22 09:00', 'fortnightly', '2026-04-05 09:00 +10:00' ),
			array( 'Australia/Sydney', '2026-09-06 09:00', 'monthly', '2026-10-04 09:00 +11:00' ),
			array( 'Australia/Sydney', '2026-03-01 09:00', 'monthly', '2026-04-05 09:00 +10:00' ),
			array( 'America/New_York', '2026-03-01 09:00', 'weekly', '2026-03-08 09:00 -04:00' ),
			array( 'America/New_York', '2026-10-25 09:00', 'weekly', '2026-11-01 09:00 -05:00' ),
			array( 'Europe/London', '2026-03-22 09:00', 'weekly', '2026-03-29 09:00 +01:00' ),
			array( 'Australia/Brisbane', '2026-09-27 09:00', 'weekly', '2026-10-04 09:00 +10:00' ),
			array( 'Australia/Lord_Howe', '2026-09-27 09:00', 'weekly', '2026-10-04 09:00 +11:00' ),
			array( 'Australia/Sydney', '2026-10-29 09:00', 'monthly', '2026-11-26 09:00 +11:00' )
		);
	}

	/** @dataProvider invalidTimes */
	public function testRejectsInvalidOrUnresolvedLocalTimes( $date, $time, $zone ) {
		$this->expectException( ClientException::class );
		meeting_local_datetime( $date, $time, $zone );
	}

	public static function invalidTimes(): array {
		return array(
			array( '2026-10-04', '02:30', 'Australia/Sydney' ),
			array( '2026-04-05', '02:30', 'Australia/Sydney' ),
			array( '2026-03-08', '02:30', 'America/New_York' ),
			array( '2026-10-04', '02:15', 'Australia/Lord_Howe' ),
			array( '2026-02-30', '09:00', 'UTC' ),
			array( '2026-10-01', '25:00', 'UTC' ),
			array( '2026-10-01', '09:00', '+10:00' )
		);
	}

	public function testRepeatedTimesRequireAndPreserveExplicitChoice() {
		foreach( array( 'Australia/Sydney' => array( '02:30', 3600 ), 'Australia/Lord_Howe' => array( '01:45', 1800 ) ) as $zone => $case ) {
			$earlier = meeting_local_datetime( '2026-04-05', $case[0], $zone, 'earlier' );
			$later = meeting_local_datetime( '2026-04-05', $case[0], $zone, 'later' );
			$this->assertSame( $case[1], $later->getTimestamp() - $earlier->getTimestamp() );
			foreach( array( 'earlier' => $earlier, 'later' => $later ) as $choice => $date ) {
				$this->assertSame( $choice, meeting_time_choice( array( 'date_start' => $date->getTimestamp(), 'timezone' => $zone ) ) );
			}
		}
	}

	public function testSeriesZoneSurvivesUserAndProcessTimezoneChanges() {
		$meeting = $this->makeMeeting( '2026-09-27', '09:00' );
		$this->assertSame( 'Australia/Sydney', $meeting['timezone'] );
		meeting_recurrence_set( $meeting, 'weekly', (int)$meeting['chair_id'] );
		user_pref_set_pref( (int)$meeting['chair_id'], 'timezone', 'America/New_York' );
		date_default_timezone_set( 'UTC' );
		$next = meeting_local_datetime( '2026-10-04', '09:00', 'Australia/Sydney' )->getTimestamp();
		$due = array_column( meeting_schedule_due( $next ), null, 'next_start' );
		$this->assertArrayHasKey( $next, $due );
		$this->assertSame( 'Australia/Sydney', $due[$next]['series']['timezone'] );
		meeting_recurrence_set( $meeting, 'fortnightly', (int)$meeting['chair_id'] );
		$this->assertSame( 'Australia/Sydney', meeting_series_timezone( $meeting ) );
		$before = meeting_recurrence_get( $meeting );
		try {
			meeting_recurrence_set( $meeting, 'weekly', (int)$meeting['chair_id'], 'Invalid/Zone' );
			$this->fail( 'Invalid timezone accepted' );
		} catch( ClientException $e ) { $this->assertSame( $before, meeting_recurrence_get( $meeting ) ); }
	}

	/** @dataProvider pausedStarts */
	public function testSchedulerPausesInsteadOfGuessingAtClockChange( $date, $time ) {
		$meeting = $this->makeMeeting( $date, $time );
		meeting_recurrence_set( $meeting, 'weekly', (int)$meeting['chair_id'] );
		meeting_schedule_due( (int)$meeting['date_start'] );
		$rule = meeting_recurrence_get( $meeting );
		$this->assertSame( 0, (int)$rule['active'] );
		$this->assertNotSame( '', $rule['last_error'] );
		$this->assertSame( $meeting['date_start'], meeting_get( (int)$meeting['id'] )['date_start'] );
	}

	public static function pausedStarts(): array {
		return array( array( '2026-09-27', '02:30' ), array( '2026-03-29', '02:30' ) );
	}

	public function testCalendarExportsAnUnambiguousUtcInstant() {
		$meeting = $this->makeMeeting( '2026-09-27', '09:00' );
		$ics = meeting_ics( $meeting, 'PUBLISH' );
		$this->assertStringContainsString( 'DTSTART:20260926T230000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20260926T233000Z', $ics );
	}

	public function testDryRunDoesNotPauseASeries() {
		$meeting = $this->makeMeeting( '2026-09-27', '02:30' );
		meeting_recurrence_set( $meeting, 'weekly', (int)$meeting['chair_id'] );
		$before = meeting_recurrence_get( $meeting );
		meeting_schedule_due( (int)$meeting['date_start'], false, (int)$meeting['id'] );
		$this->assertSame( $before, meeting_recurrence_get( $meeting ) );
	}

	public function testAssistantFollowupUsesSavedSeriesZoneAndHonoursExplicitOverrides() {
		require_once dirname( __DIR__, 2 ) . '/ai_assist_meeting_api.php';
		$meeting = $this->makeMeeting( '2026-09-27', '09:00' );
		$chair = (int)$meeting['chair_id'];
		meeting_recurrence_set( $meeting, 'weekly', $chair, 'Australia/Sydney' );
		user_pref_set_pref( $chair, 'timezone', 'America/New_York' );
		date_default_timezone_set( 'UTC' );
		$project = config_get_global( 'meeting_project_id' );
		$departments = config_get_global( 'ai_meeting_departments' );
		$mail = config_get_global( 'enable_email_notification' );
		config_set_global( 'meeting_project_id', 0 );
		$test_departments = $departments;
		foreach( $test_departments as &$department ) { $department['project_id'] = 0; }
		unset( $department );
		config_set_global( 'ai_meeting_departments', $test_departments );
		config_set_global( 'enable_email_notification', OFF );
		try {
			$attrs = array( 'date' => '2026-10-04', 'time' => '09:00', 'dept' => 'SYS', 'series_of' => (string)$meeting['id'] );
			foreach( array( '' => '2026-10-03T22:00:00+00:00', 'UTC' => '2026-10-04T09:00:00+00:00' ) as $zone => $expected ) {
				$attrs['timezone'] = $zone;
				$result = array();
				ai_assist_meeting_process_agenda( $attrs, '# Test agenda', $chair, $result );
				$this->meetings[] = (int)$result['meeting_id'];
				$next = meeting_get( (int)$result['meeting_id'] );
				$this->assertSame( $zone === '' ? 'Australia/Sydney' : $zone, $next['timezone'] );
				$this->assertSame( $expected, gmdate( 'c', (int)$next['date_start'] ) );
			}
			$attrs['timezone'] = 'Australia/Sydney';
			$attrs['time'] = '02:30';
			$this->expectException( ClientException::class );
			$result = array();
			ai_assist_meeting_process_agenda( $attrs, '# Must not save', $chair, $result );
		} finally {
			config_set_global( 'meeting_project_id', $project );
			config_set_global( 'ai_meeting_departments', $departments );
			config_set_global( 'enable_email_notification', $mail );
		}
	}

	public function testSchemaUpdatePreservesTimestampsAndPausesLegacyRules() {
		require dirname( __DIR__, 2 ) . '/admin/schema.php';
		$tables = array( 'meeting' => 60, 'meeting_series' => 63 );
		try {
			foreach( $tables as $name => $step ) {
				$sql = str_replace( 'CREATE TABLE IF NOT EXISTS ' . db_get_table( $name ) . ' (',
					'CREATE TEMPORARY TABLE tz_old_' . $name . ' (', $g_upgrade[$step][1] );
				$this->assertStringContainsString( 'CREATE TEMPORARY TABLE tz_old_', $sql );
				db_query( str_replace( "	  `timezone` varchar(64) NOT NULL DEFAULT '',\n", '', $sql ) );
				db_query( str_replace( 'tz_old_', 'tz_new_', $sql ) );
			}
			db_query( 'INSERT INTO tz_old_meeting (id, date_start) VALUES (1, 1790463600)' );
			db_query( "INSERT INTO tz_old_meeting_series (series_id, recurrence, active) VALUES (1, 'weekly', 1)" );
			$sql = file_get_contents( dirname( __DIR__, 2 ) . '/admin/tools/schema-updates/2026-11-01_meeting-timezones.sql' );
			$sql = preg_replace( '/^--.*$/m', '', $sql );
			foreach( $tables as $name => $step ) { $sql = str_replace( '`' . $name . '`', '`tz_old_' . $name . '`', $sql ); }
			foreach( explode( ';', $sql ) as $statement ) { if( trim( $statement ) !== '' ) { db_query( $statement ); } }
			foreach( $tables as $name => $step ) {
				$definitions = array();
				foreach( array( 'old', 'new' ) as $kind ) {
					$result = db_query( 'SHOW CREATE TABLE tz_' . $kind . '_' . $name );
					$row = array_change_key_case( db_fetch_array( $result ), CASE_LOWER );
					$definitions[] = preg_replace( array( '/CREATE TEMPORARY TABLE `[^`]+`/', '/ AUTO_INCREMENT=[0-9]+/' ),
						array( 'CREATE TABLE `test`', '' ), $row['create table'] );
				}
				$this->assertSame( $definitions[1], $definitions[0], $name );
			}
			$result = db_query( 'SELECT date_start, timezone FROM tz_old_meeting' );
			$row = db_fetch_array( $result );
			$this->assertSame( 1790463600, (int)$row['date_start'] );
			$this->assertSame( '', $row['timezone'] );
			$result = db_query( 'SELECT active, last_error FROM tz_old_meeting_series' );
			$row = db_fetch_array( $result );
			$this->assertSame( 0, (int)$row['active'] );
			$this->assertStringContainsString( 'Confirm', $row['last_error'] );
		} finally {
			foreach( $tables as $name => $step ) {
				foreach( array( 'old', 'new' ) as $kind ) { db_query( 'DROP TEMPORARY TABLE IF EXISTS tz_' . $kind . '_' . $name ); }
			}
		}
	}

	private function makeMeeting( $date, $time ): array {
		auth_attempt_script_login( 'administrator' );
		$id = user_get_id_by_cookie( user_create( 'tztest' . rand(), 'password', '', REPORTER ) );
		$this->users[] = $id;
		user_pref_set_pref( $id, 'timezone', 'Australia/Sydney' );
		$meeting = meeting_create( array(
			'doc_ref' => 'TZ-TEST-' . rand(), 'title' => 'Timezone test', 'department' => 'SYS',
			'chair_id' => $id, 'minute_taker_id' => 0, 'created_by' => $id,
			'date_start' => meeting_local_datetime( $date, $time, 'Australia/Sydney' )->getTimestamp(),
			'duration' => 30, 'location' => 'Test'
		), array() );
		$this->meetings[] = $meeting;
		return meeting_get( $meeting );
	}
}
