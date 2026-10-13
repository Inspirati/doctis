<?php
# Doctis username naming policy and self sign-up checks.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class UserNamePolicyTest extends MantisCoreBase {
	private $user_ids = array();

	protected function setUp(): void {
		parent::setUp();
		self::login();
		require_api( 'signup_api.php' );
	}

	protected function tearDown(): void {
		config_set_global( 'limit_email_domains', array() );
		foreach( $this->user_ids as $t_id ) {
			user_delete( $t_id );
		}
		parent::tearDown();
	}

	/** Create a throwaway reporter account and remember it for cleanup. */
	private function makeUser( string $p_name ): int {
		$t_id = (int)user_get_id_by_cookie(
			user_create( $p_name, 'password', 'policy-' . rand() . '@example.test' ) );
		$this->user_ids[] = $t_id;
		return $t_id;
	}

	public static function providerNames() {
		return array(
			'short name' => array( 'robert', '' ),
			'first name' => array( 'samwise', '' ),
			'dotted' => array( 'robert.dickenson', '' ),
			'hyphen and digit' => array( 'j-smith2', '' ),
			'20 characters' => array( str_repeat( 'a', 20 ), '' ),
			'21 characters' => array( str_repeat( 'a', 21 ), 'format' ),
			'one character' => array( 'x', 'format' ),
			'uppercase' => array( 'Robert', 'format' ),
			'email address' => array( 'robert@example.com', 'format' ),
			'space' => array( 'john smith', 'format' ),
			'plus sign' => array( 'bob+test', 'format' ),
			'ends with a dot' => array( 'bob.', 'format' ),
			'starts with a digit' => array( '2bob', 'format' ),
		);
	}

	/**
	 * @dataProvider providerNames
	 */
	public function testFormat( string $p_name, string $p_problem ) {
		$this->assertSame( $p_problem, user_name_policy_problem( $p_name ) );
	}

	public function testReservedNamesRefusedExceptForAdministrators() {
		$this->assertSame( '', user_name_policy_problem( 'admin' ), 'an administrator may use a reserved name' );

		$t_old_user_id = current_user_set( $this->makeUser( 'policyrep' . rand() ) );
		try {
			$this->assertSame( 'reserved', user_name_policy_problem( 'admin' ) );
			$this->assertSame( 'reserved', user_name_policy_problem( 'root' ) );
			$this->assertSame( 'reserved', user_name_policy_problem( 'hermione' ), 'the signup page example' );
			$this->assertSame( '', user_name_policy_problem( 'rootbeer' ) );
		} finally {
			current_user_set( $t_old_user_id );
		}
	}

	public function testLoginCheckIsUnchanged() {
		# Existing accounts and mixed-case logins must keep working
		$this->assertTrue( user_is_name_valid( 'Administrator' ) );
		$this->assertTrue( user_is_name_valid( 'admin' ) );
	}

	public function testCreateStoresLowercase() {
		$t_name = 'PolicyCase' . rand();
		$t_id = $this->makeUser( $t_name );
		$this->assertSame( strtolower( $t_name ), user_get_username( $t_id ) );
		$this->assertSame( $t_id, (int)user_get_id_by_name( $t_name ), 'lookup by name ignores case' );
	}

	public function testCreateRefusesPolicyBreach() {
		$this->expectException( ClientException::class );
		$this->makeUser( 'policy test' . rand() );
	}

	public function testEmailInDomains() {
		$t_domains = array( 'example.com', 'Example.org' );
		$this->assertTrue( signup_email_in_domains( 'a.b@example.com', $t_domains ) );
		$this->assertTrue( signup_email_in_domains( 'a@EXAMPLE.ORG', $t_domains ) );
		$this->assertFalse( signup_email_in_domains( 'a@sub.example.com', $t_domains ) );
		$this->assertFalse( signup_email_in_domains( 'a@example.com.other.test', $t_domains ) );
		$this->assertFalse( signup_email_in_domains( 'example.com', $t_domains ) );
	}

	public function testSignupProblems() {
		$t_taken = 'ptaken' . rand();
		$this->makeUser( $t_taken );
		config_set_global( 'limit_email_domains', array( 'example.test' ) );

		$t_free = 'policyfree' . rand();
		$t_email = 'free-' . rand() . '@example.test';
		$this->assertSame( 'username_email', signup_form_problem( $t_email, $t_email ) );
		$this->assertSame( 'username_format', signup_form_problem( 'john smith', $t_email ) );
		$this->assertSame( 'username_format', signup_form_problem( '', $t_email ) );
		$this->assertSame( 'email_domain', signup_form_problem( $t_free, 'someone@elsewhere.test' ) );
		$this->assertSame( 'email_invalid', signup_form_problem( $t_free, 'not-an-email' ) );
		$this->assertSame( '', signup_form_problem( $t_taken, $t_email ), 'a clash is not a form problem' );

		$this->assertSame( 'username_taken', signup_account_problem( $t_taken, $t_email ) );
		$this->assertSame( '', signup_account_problem( $t_free, $t_email ) );
	}
}
