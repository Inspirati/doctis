<?php
# Doctis user profile command tests.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class UserProfileUpdateCommandTest extends MantisCoreBase {
	private $user_id;
	private $manager_id;
	private $original;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->user_id = auth_get_current_user_id();
		$this->original = user_get_row( $this->user_id );
		$t_cookie = user_create(
			'OrgManager' . rand(),
			'password'
		);
		$this->manager_id = user_get_id_by_cookie( $t_cookie );
	}

	protected function tearDown(): void {
		user_set_fields( $this->user_id, array(
			'realname' => $this->original['realname'],
			'position_title' => $this->original['position_title'],
			'company' => $this->original['company'],
			'phone' => $this->original['phone'],
			'department' => $this->original['department'],
			'reports_to' => $this->original['reports_to'],
			'alternative' => $this->original['alternative'],
			'meeting_invite' => $this->original['meeting_invite'],
			'email_secondary' => $this->original['email_secondary'],
		) );
		user_delete( $this->manager_id );
		parent::tearDown();
	}

	public function testUpdatesOrganisationFields() {
		$t_command = new \UserProfileUpdateCommand( $this->commandData( $this->manager_id, 'External fallback' ) );
		$t_command->execute();
		$t_user = user_get_row( $this->user_id );

		$this->assertSame( $this->manager_id, (int)$t_user['reports_to'] );
		$this->assertSame( 'External fallback', $t_user['alternative'] );
	}

	public function testRejectsSelfReporting() {
		$this->expectException( ClientException::class );
		$t_command = new \UserProfileUpdateCommand( $this->commandData( $this->user_id, '' ) );
		$t_command->execute();
	}

	public function testTimezoneCreatesPreferencesAndPreservesOtherSettings() {
		user_pref_delete( $this->manager_id );
		$t_data = $this->commandData( 0, '' );
		$t_data['payload']['profile']['timezone'] = 'Asia/Tokyo';
		$this->executeForFixture( $t_data );
		$this->assertSame( 'Asia/Tokyo', user_pref_get_pref( $this->manager_id, 'timezone' ) );

		user_pref_set_pref( $this->manager_id, 'refresh_delay', 37 );
		$t_before = user_pref_cache_row( $this->manager_id );
		$t_data['payload']['profile']['timezone'] = 'America/New_York';
		$this->executeForFixture( $t_data );
		$t_after = user_pref_cache_row( $this->manager_id );
		$this->assertSame( 'America/New_York', $t_after['timezone'] );
		unset( $t_before['timezone'], $t_after['timezone'] );
		$this->assertSame( $t_before, $t_after );
	}

	public function testInvalidTimezoneDoesNotUpdateProfileOrPreferences() {
		$t_before_user = user_get_row( $this->manager_id );
		$t_before_prefs = user_pref_cache_row( $this->manager_id, ALL_PROJECTS, false );
		$t_data = $this->commandData( 0, '' );
		$t_data['payload']['profile']['position_title'] = 'Must not be saved';
		$t_data['payload']['profile']['timezone'] = 'Australia/NotAPlace';
		try {
			$this->executeForFixture( $t_data );
			$this->fail( 'Invalid timezone should be rejected' );
		} catch( ClientException $t_exception ) {
			$this->assertSame( ERROR_INVALID_FIELD_VALUE, $t_exception->getCode() );
		}
		$this->assertSame( $t_before_user, user_get_row( $this->manager_id ) );
		$this->assertSame( $t_before_prefs, user_pref_cache_row( $this->manager_id, ALL_PROJECTS, false ) );
	}

	public function testOmittedTimezonePreservesPreferenceAndDefaultCanBeSelected() {
		user_pref_set_pref( $this->manager_id, 'timezone', 'Pacific/Auckland' );
		$t_data = $this->commandData( 0, '' );
		$this->executeForFixture( $t_data );
		$this->assertSame( 'Pacific/Auckland', user_pref_get_pref( $this->manager_id, 'timezone' ) );

		$t_data['payload']['profile']['timezone'] = config_get_global( 'default_timezone' );
		$this->executeForFixture( $t_data );
		$this->assertSame( config_get_global( 'default_timezone' ), user_pref_get_pref( $this->manager_id, 'timezone' ) );
	}

	/** Run as a disposable ordinary user, leaving the administrator's preferences alone. */
	private function executeForFixture( array $p_data ) {
		$p_data['query']['user_id'] = $this->manager_id;
		$t_old_user_id = current_user_set( $this->manager_id );
		try {
			return ( new \UserProfileUpdateCommand( $p_data ) )->execute();
		} finally {
			current_user_set( $t_old_user_id );
		}
	}

	private function commandData( $p_reports_to, $p_alternative ) {
		return array(
			'query' => array( 'user_id' => $this->user_id ),
			'payload' => array(
				'profile' => array(
					'realname' => $this->original['realname'],
					'position_title' => $this->original['position_title'],
					'company' => $this->original['company'],
					'phone' => $this->original['phone'],
					'department' => $this->original['department'],
					'reports_to' => $p_reports_to,
					'alternative' => $p_alternative,
					'meeting_invite' => $this->original['meeting_invite'],
					'email_secondary' => $this->original['email_secondary'],
				),
			),
		);
	}
}
