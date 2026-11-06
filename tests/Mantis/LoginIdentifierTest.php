<?php
# Doctis username and registered-email login regressions.

namespace Mantis\tests\Mantis;

class LoginIdentifierTest extends MantisCoreBase {
	private $users = array();
	private $settings = array();
	private $previousUser;
	private $previousCookie;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->previousUser = auth_get_current_user_id();
		$this->previousCookie = $GLOBALS['g_script_login_cookie'] ?? null;
		foreach( array( 'email_login_enabled', 'email_ensure_unique', 'enable_email_notification',
			'check_mx_record', 'limit_email_domains', 'max_failed_login_count' ) as $setting ) {
			$this->settings[$setting] = config_get_global( $setting );
		}
		config_set_global( 'email_login_enabled', ON );
		config_set_global( 'email_ensure_unique', OFF );
		config_set_global( 'enable_email_notification', OFF );
		config_set_global( 'check_mx_record', OFF );
		config_set_global( 'limit_email_domains', array() );
		config_set_global( 'max_failed_login_count', 3 );
	}

	protected function tearDown(): void {
		$GLOBALS['g_script_login_cookie'] = $this->previousCookie;
		current_user_set( $this->previousUser );
		foreach( $this->users as $id ) {
			user_delete( $id );
		}
		foreach( $this->settings as $setting => $value ) {
			config_set_global( $setting, $value );
		}
		parent::tearDown();
	}

	private function makeUser( string $p_email = '', bool $p_enabled = true ): int {
		$name = 'login' . bin2hex( random_bytes( 5 ) );
		$id = (int)user_get_id_by_cookie( user_create( $name, 'test-password', $p_email, REPORTER, false, $p_enabled ) );
		$this->users[] = $id;
		return $id;
	}

	private function email(): string {
		return 'login-' . bin2hex( random_bytes( 5 ) ) . '@example.test';
	}

	public function testUsernameAndEmailResolveToSameAccountIgnoringCase() {
		$email = $this->email();
		$id = $this->makeUser( $email );
		foreach( array( user_get_username( $id ), strtoupper( user_get_username( $id ) ), $email, strtoupper( $email ) ) as $login ) {
			$this->assertSame( $id, (int)auth_get_user_id_from_login_name( auth_prepare_username( $login ) ) );
			$this->assertTrue( auth_attempt_script_login( $login, 'test-password' ) );
			$this->assertSame( $id, auth_get_current_user_id() );
		}
	}

	public function testUnknownBlankAndMalformedIdentifiersDoNotResolve() {
		$this->makeUser();
		foreach( array( '', ' ', $this->email(), 'not-an-account', "' OR 1=1 --" ) as $login ) {
			$this->assertFalse( auth_get_user_id_from_login_name( $login ) );
		}
	}

	public function testRegisteredEmailsCanUseCharactersOutsideUsernameRules() {
		foreach( array( "o'brien", 'team%ops', 'staff!ops' ) as $local ) {
			$email = $local . bin2hex( random_bytes( 3 ) ) . '@example.test';
			$id = $this->makeUser( $email );
			$this->assertSame( $email, auth_prepare_username( $email ) );
			$this->assertSame( $id, (int)auth_get_user_id_from_login_name( auth_prepare_username( $email ) ) );
		}
		$this->assertNull( auth_prepare_username( '<script>alert(1)</script>' ) );
		$this->assertNull( auth_prepare_username( str_repeat( 'a', DB_FIELD_SIZE_USERNAME ) . '@example.test' ) );
	}

	public function testDisabledAccountCannotLogInByEitherIdentifier() {
		$email = $this->email();
		$id = $this->makeUser( $email, false );
		$this->assertFalse( auth_get_user_id_from_login_name( $email ) );
		$this->assertFalse( auth_attempt_script_login( $email, 'test-password' ) );
		$this->assertFalse( auth_attempt_script_login( user_get_username( $id ), 'test-password' ) );
	}

	public function testAmbiguousEmailIsRejectedButDisabledDuplicateDoesNotBlockLogin() {
		$email = $this->email();
		$first = $this->makeUser( $email );
		$second = $this->makeUser( strtoupper( $email ) );
		$this->assertFalse( auth_get_user_id_from_login_name( $email ) );
		$this->assertSame( $first, (int)auth_get_user_id_from_login_name( user_get_username( $first ) ) );
		user_set_field( $second, 'enabled', false );
		$this->assertSame( $first, (int)auth_get_user_id_from_login_name( $email ) );
	}

	public function testExistingUsernameTakesPrecedenceOverAnotherAccountsEmail() {
		$email = $this->email();
		$emailOwner = $this->makeUser( $email );
		$legacyUser = $this->makeUser();
		# Simulate a legacy email-shaped username, disallowed for new accounts.
		user_set_field( $legacyUser, 'username', $email );
		$this->assertSame( $legacyUser, (int)auth_get_user_id_from_login_name( $email ) );
		$this->assertNotSame( $emailOwner, (int)auth_get_user_id_from_login_name( $email ) );
	}

	public function testRegistrationDomainPolicyDoesNotInvalidateStoredEmailLogin() {
		$email = $this->email();
		$id = $this->makeUser( $email );
		config_set_global( 'limit_email_domains', array( 'new-domain.example' ) );
		config_set_global( 'check_mx_record', ON );
		$this->assertSame( $id, (int)auth_get_user_id_from_login_name( $email ) );
	}

	public function testEmailLoginCanBeDisabledWithoutAffectingUsernameLogin() {
		$email = $this->email();
		$id = $this->makeUser( $email );
		config_set_global( 'email_login_enabled', OFF );
		$this->assertFalse( auth_get_user_id_from_login_name( $email ) );
		$this->assertSame( $id, (int)auth_get_user_id_from_login_name( user_get_username( $id ) ) );
	}

	public function testWrongPasswordsShareTheAccountsExistingLoginLimit() {
		$email = $this->email();
		$id = $this->makeUser( $email );
		$this->assertFalse( auth_attempt_login( $email, 'wrong-password' ) );
		$this->assertSame( 1, (int)user_get_field( $id, 'failed_login_count' ) );
		$this->assertFalse( auth_attempt_login( user_get_username( $id ), 'wrong-password' ) );
		$this->assertSame( 2, (int)user_get_field( $id, 'failed_login_count' ) );
		$this->assertFalse( auth_attempt_login( strtoupper( $email ), 'wrong-password' ) );
		$this->assertSame( 3, (int)user_get_field( $id, 'failed_login_count' ) );
		$this->assertFalse( user_is_login_request_allowed( $id ) );
		$this->assertFalse( auth_attempt_login( $email, 'test-password' ) );
	}
}
