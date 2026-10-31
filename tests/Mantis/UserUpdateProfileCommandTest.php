<?php
# Doctis administrator profile update tests.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class UserUpdateProfileCommandTest extends MantisCoreBase {
	private $user_id;
	private $actor_id;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		# Blank email addresses prevent registration mail on the native VM.
		$this->user_id = user_get_id_by_cookie( user_create( 'profedit' . rand(), 'password', '', REPORTER ) );
		$this->actor_id = user_get_id_by_cookie( user_create( 'profactor' . rand(), 'password', '', REPORTER ) );
	}

	protected function tearDown(): void {
		user_set_fields( $this->user_id, array( 'protected' => false ) );
		user_delete( $this->user_id );
		user_delete( $this->actor_id );
		parent::tearDown();
	}

	public function testAdministratorUpdatesAllProfileFields() {
		$t_fields = $this->profile();
		$this->update( $t_fields + array( 'reports_to' => $this->actor_id ) );
		$t_user = user_get_row( $this->user_id );
		foreach( $t_fields as $t_field => $t_value ) {
			$this->assertEquals( $t_value, $t_user[$t_field], $t_field );
		}
		$this->assertSame( $this->actor_id, (int)$t_user['reports_to'] );
	}

	public function testNewAccountsAcceptAllMeetingInvitationsByDefault() {
		$this->assertSame( MEETING_INVITE_ALL, (int)user_get_field( $this->user_id, 'meeting_invite' ) );
	}

	public function testCanonicalUserSchemaDefaultsToAllMeetingInvitations() {
		require dirname( __DIR__, 2 ) . '/admin/schema.php';
		$t_table = 'invite_schema_test_' . rand();
		$t_sql = str_replace( 'CREATE TABLE IF NOT EXISTS ' . db_get_table( 'user' ) . ' (',
			'CREATE TEMPORARY TABLE ' . $t_table . ' (', $g_upgrade[47][1] );
		$this->assertStringContainsString( 'CREATE TEMPORARY TABLE ' . $t_table, $t_sql );
		db_query( $t_sql );
		try {
			db_query( 'INSERT INTO ' . $t_table . " (username) VALUES ('invitedefault')" );
			$t_result = db_query( 'SELECT meeting_invite FROM ' . $t_table );
			$t_row = db_fetch_array( $t_result );
			$this->assertSame( MEETING_INVITE_ALL, (int)$t_row['meeting_invite'] );
		} finally {
			db_query( 'DROP TEMPORARY TABLE ' . $t_table );
		}
	}

	public function testNormalizesWhitespaceAndLimitsMultibyteText() {
		$this->update( array(
			'position_title' => '  Engineer  ',
			'department' => str_repeat( '部', DB_FIELD_SIZE_DEPARTMENT + 5 ),
			'phone' => str_repeat( '1', DB_FIELD_SIZE_PHONE + 5 ),
			'email_secondary' => '  profile@example.test  ',
			'meeting_invite' => '1'
		) );
		$t_user = user_get_row( $this->user_id );
		$this->assertSame( 'Engineer', $t_user['position_title'] );
		$this->assertSame( str_repeat( '部', DB_FIELD_SIZE_DEPARTMENT ), $t_user['department'] );
		$this->assertSame( str_repeat( '1', DB_FIELD_SIZE_PHONE ), $t_user['phone'] );
		$this->assertSame( 'profile@example.test', $t_user['email_secondary'] );
		$this->assertSame( 1, (int)$t_user['meeting_invite'] );
	}

	public function testOmittedProfileFieldsAndPreferencesArePreserved() {
		$this->update( $this->profile() );
		user_pref_set_pref( $this->user_id, 'timezone', 'Asia/Tokyo' );
		$this->update( array( 'real_name' => 'Updated Name' ) );
		$t_user = user_get_row( $this->user_id );
		$this->assertSame( 'Updated Name', $t_user['realname'] );
		foreach( $this->profile() as $t_field => $t_value ) {
			$this->assertEquals( $t_value, $t_user[$t_field], $t_field );
		}
		$this->assertSame( 'Asia/Tokyo', user_pref_get_pref( $this->user_id, 'timezone' ) );
	}

	public function testProfileFieldsCanBeCleared() {
		$this->update( $this->profile() );
		$t_fields = array_fill_keys( array_keys( $this->profile() ), '' );
		$t_fields['meeting_invite'] = 0;
		$this->update( $t_fields );
		$t_user = user_get_row( $this->user_id );
		foreach( $t_fields as $t_field => $t_value ) {
			$this->assertEquals( $t_value, $t_user[$t_field], $t_field );
		}
	}

	/** @dataProvider invalidProfiles */
	public function testInvalidProfileDoesNotPartiallyUpdateAccount( array $p_fields ) {
		$t_before = user_get_row( $this->user_id );
		try {
			$this->update( $p_fields + array( 'real_name' => 'Must not save', 'enabled' => false, 'protected' => true ) );
			$this->fail( 'Invalid profile should be rejected' );
		} catch( ClientException $t_exception ) {
			$this->assertSame( ERROR_INVALID_FIELD_VALUE, $t_exception->getCode() );
		}
		user_clear_cache( $this->user_id );
		$this->assertSame( $t_before, user_get_row( $this->user_id ) );
	}

	public static function invalidProfiles(): array {
		return array(
			'invalid email' => array( array( 'email_secondary' => 'invalid address' ) ),
			'overlong email' => array( array( 'email_secondary' => str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.test' ) ),
			'non-string email' => array( array( 'email_secondary' => array() ) ),
			'invalid invitation preference' => array( array( 'meeting_invite' => 3 ) ),
			'non-integer invitation preference' => array( array( 'meeting_invite' => '1.5' ) ),
			'non-string profile field' => array( array( 'company' => array() ) )
		);
	}

	public function testAdministratorCanEditDisabledProtectedAccount() {
		user_set_fields( $this->user_id, array( 'protected' => true, 'enabled' => false ) );
		$this->update( $this->profile() );
		$this->assertSame( 'Engineer', user_get_field( $this->user_id, 'position_title' ) );
		$this->assertTrue( user_is_protected( $this->user_id ) );
		$this->assertFalse( user_is_enabled( $this->user_id ) );
	}

	public function testOrdinaryUserCannotEditAnotherUsersProfile() {
		$t_before = user_get_row( $this->user_id );
		$t_old_user_id = current_user_set( $this->actor_id );
		try {
			$this->update( $this->profile() );
			$this->fail( 'Ordinary users must not manage another account' );
		} catch( ClientException $t_exception ) {
			$this->assertSame( ERROR_ACCESS_DENIED, $t_exception->getCode() );
		} finally {
			current_user_set( $t_old_user_id );
		}
		user_clear_cache( $this->user_id );
		$this->assertSame( $t_before, user_get_row( $this->user_id ) );
	}

	private function update( array $p_fields ) {
		return ( new \UserUpdateCommand( array(
			'query' => array( 'user_id' => $this->user_id ),
			'payload' => array( 'user' => $p_fields, 'notify_user' => false )
		) ) )->execute();
	}

	private function profile(): array {
		return array(
			'position_title' => 'Engineer',
			'company' => 'Example Company',
			'department' => 'Engineering',
			'phone' => '+61 2 5550 1234',
			'alternative' => 'External Manager',
			'meeting_invite' => 2,
			'email_secondary' => 'profile@example.test'
		);
	}
}
