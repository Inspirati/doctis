<?php
namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class EmailDomainTest extends MantisCoreBase {
	private $saved;
	private $globals;
	private $actor;
	private $users = array();

	protected function setUp(): void {
		parent::setUp();
		self::login();
		require_api( 'email_domain_api.php' );
		$this->actor = auth_get_current_user_id();
		$this->globals = array();
		foreach( array_merge( EMAIL_DOMAIN_OPTIONS, array( 'enable_email_notification', 'send_reset_password', 'check_mx_record', 'validate_email' ) ) as $key ) {
			$this->globals[$key] = config_get_global( $key );
		}
		$this->saved = array();
		$result = db_query( "SELECT * FROM {config} WHERE config_id IN ('limit_email_domains','signup_captcha_exempt_domains')" );
		while( $row = db_fetch_array( $result ) ) { $this->saved[] = $row; }
		db_query( "DELETE FROM {config} WHERE config_id IN ('limit_email_domains','signup_captcha_exempt_domains')" );
		config_flush_cache();
		config_set_global( 'enable_email_notification', OFF );
		config_set_global( 'send_reset_password', OFF );
		config_set_global( 'check_mx_record', OFF );
		config_set_global( 'validate_email', ON );
		config_set_global( 'limit_email_domains', array() );
		config_set_global( 'signup_captcha_exempt_domains', array() );
	}

	protected function tearDown(): void {
		current_user_set( $this->actor );
		foreach( $this->users as $id ) { user_delete( $id ); }
		db_query( "DELETE FROM {config} WHERE config_id IN ('limit_email_domains','signup_captcha_exempt_domains')" );
		foreach( $this->saved as $row ) {
			db_query( 'INSERT INTO {config} (config_id,user_id,project_id,type,value,access_reqd) VALUES ('
				. implode( ',', array_fill( 0, 6, db_param() ) ) . ')',
				array( $row['config_id'], $row['user_id'], $row['project_id'], $row['type'], $row['value'], $row['access_reqd'] ) );
		}
		config_flush_cache();
		foreach( $this->globals as $key => $value ) { config_set_global( $key, $value ); }
		parent::tearDown();
	}

	private function lists( array $allowed, array $exempt = array() ): array {
		return array( 'limit_email_domains' => $allowed, 'signup_captcha_exempt_domains' => $exempt );
	}

	private function save( array $lists ): void {
		email_domain_save( $lists, email_domain_snapshot()['revision'] );
	}

	public function testDatabaseOverrideIsGlobalAndDoesNotAppendFallback(): void {
		config_set_global( 'limit_email_domains', array( 'fallback.example' ) );
		$this->save( $this->lists( array( ' Example.ORG ', 'example.org', 'example.com' ), array( 'example.com' ) ) );
		config_set( 'limit_email_domains', array( 'scoped.example' ), $this->actor, ALL_PROJECTS );
		$this->assertSame( array( 'example.com', 'example.org' ), email_domain_list( 'limit_email_domains' ) );
		$this->assertFalse( email_is_valid( 'x@fallback.example' ) );
		$this->assertFalse( email_is_valid( 'x@sub.example.com' ) );
		$this->assertTrue( email_is_valid( 'x@EXAMPLE.COM' ) );
		$this->assertSame( 'email_domains_source_database', email_domain_snapshot()['sources']['limit_email_domains'] );
	}

	public function testEmptyOverrideDoesNotRevertToPopulatedFileValue(): void {
		config_set_global( 'limit_email_domains', array( 'fallback.example' ) );
		$this->save( $this->lists( array(), array( 'example.org' ) ) );
		$this->assertSame( array(), email_domain_list( 'limit_email_domains' ) );
		$this->assertTrue( email_is_valid( 'x@elsewhere.example' ) );
		$this->assertSame( array( 'example.org' ), email_domain_list( 'signup_captcha_exempt_domains' ) );
	}

	public function testEmptyRestrictedEditorCannotOpenRegistration(): void {
		$this->save( $this->lists( array( 'example.com' ) ) );
		$before = email_domain_snapshot();
		try { email_domain_from_rows( array(), true ); $this->fail( 'Empty restricted policy accepted' ); }
		catch( ClientException $e ) { $this->assertSame( $before, email_domain_snapshot() ); }
	}

	public function testStaleEditorCannotOverwriteAnotherAdministratorsSave(): void {
		$old = email_domain_snapshot()['revision'];
		$this->save( $this->lists( array( 'example.com' ) ) );
		try { email_domain_save( $this->lists( array( 'example.org' ) ), $old ); $this->fail( 'Stale revision accepted' ); }
		catch( ClientException $e ) { $this->assertSame( array( 'example.com' ), email_domain_list( 'limit_email_domains' ) ); }
	}

	public function testScopedOverridesRequireExplicitConsentToRemove(): void {
		config_set( 'limit_email_domains', array( 'scoped.example' ), $this->actor, ALL_PROJECTS );
		$snapshot = email_domain_snapshot();
		$this->assertSame( 1, $snapshot['scoped'] );
		try { email_domain_save( $this->lists( array( 'example.com' ) ), $snapshot['revision'] ); $this->fail( 'Scoped conflict ignored' ); }
		catch( ClientException $e ) { $this->assertSame( 1, email_domain_snapshot()['scoped'] ); }
		email_domain_save( $this->lists( array( 'example.com' ) ), $snapshot['revision'], true );
		$this->assertSame( 0, email_domain_snapshot()['scoped'] );
	}

	public function testFailureOnSecondListRollsBackFirstAndClearsCache(): void {
		$this->save( $this->lists( array( 'example.com' ), array( 'example.com' ) ) );
		$before = email_domain_snapshot();
		$table = '`' . db_get_table( 'config' ) . '`';
		db_query( "CREATE TRIGGER email_domain_test_failure BEFORE UPDATE ON $table FOR EACH ROW BEGIN IF NEW.config_id='signup_captcha_exempt_domains' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic policy failure'; END IF; END" );
		try {
			try { $this->save( $this->lists( array( 'example.org' ), array( 'example.org' ) ) ); $this->fail( 'Second write should fail' ); }
			catch( ClientException $e ) { $this->assertSame( $before, email_domain_snapshot() ); }
		} finally { db_query( 'DROP TRIGGER email_domain_test_failure' ); }
	}

	public function testPermissionRemovalDoesNotChangeUserOrStoredEmailLogin(): void {
		$id = (int)user_get_id_by_cookie( user_create( 'ed' . bin2hex( random_bytes( 5 ) ), 'password', 'person@example.org' ) );
		$this->users[] = $id;
		$before = user_get_row( $id );
		$this->save( $this->lists( array( 'example.com' ) ) );
		$this->assertSame( $id, (int)auth_get_user_id_from_login_name( 'person@example.org' ) );
		$this->assertTrue( auth_does_password_match( $id, 'password' ) );
		$this->assertSame( $before, user_get_row( $id ) );
		$this->assertFalse( email_is_valid( 'person@example.org' ), 'Password recovery uses shared validation' );
	}

	public function testOrdinaryUserCannotWritePolicy(): void {
		$id = (int)user_get_id_by_cookie( user_create( 'ed' . bin2hex( random_bytes( 5 ) ), 'password', '', REPORTER ) );
		$this->users[] = $id;
		$revision = email_domain_snapshot()['revision'];
		current_user_set( $id );
		$this->expectException( ClientException::class );
		email_domain_save( $this->lists( array( 'example.com' ) ), $revision );
	}

	public function testGenericConfigurationCommandCannotBypassEditor(): void {
		$this->expectException( ClientException::class );
		(new \ConfigsSetCommand( array( 'payload' => array( 'configs' => array(
			array( 'option' => 'limit_email_domains', 'value' => array() ) ) ) ) ))->execute();
	}

	public function testMalformedPolicyIsNotAnUnrestrictedFallbackAndLoginStillWorks(): void {
		config_set( 'limit_email_domains', 'invalid-value', ALL_USERS, ALL_PROJECTS );
		$this->assertSame( $this->actor, (int)auth_get_user_id_from_login_name( user_get_username( $this->actor ) ) );
		$this->expectException( ClientException::class );
		email_domain_list( 'limit_email_domains' );
	}

	public function testAdministratorCanRepairMalformedStoredPolicy(): void {
		config_set( 'limit_email_domains', 'invalid-value', ALL_USERS, ALL_PROJECTS );
		$snapshot = email_domain_snapshot( true );
		$this->assertTrue( $snapshot['invalid'] );
		email_domain_save( $this->lists( array( 'example.com' ) ), $snapshot['revision'] );
		$this->assertFalse( email_domain_snapshot()['invalid'] );
		$this->assertSame( array( 'example.com' ), email_domain_list( 'limit_email_domains' ) );
	}

	public function testRemovedRowsAndDuplicateDomains(): void {
		$result = email_domain_from_rows( array(
			array( 'domain' => ' EXAMPLE.COM ', 'allowed' => 1, 'exempt' => 1 ),
			array( 'domain' => 'example.com', 'allowed' => 1 ),
			array( 'domain' => 'removed.example', 'allowed' => 1, 'remove' => 1 ),
			array( 'domain' => '' ) ), true );
		$this->assertSame( $this->lists( array( 'example.com' ), array( 'example.com' ) ), $result );
	}

	/** @dataProvider invalidDomains */
	public function testInvalidDomainInputCannotBeStored( string $domain ): void {
		$this->expectException( ClientException::class );
		email_domain_normalize( $domain );
	}

	public static function invalidDomains(): array {
		return array_map( function( $value ) { return array( $value ); }, array(
			'', 'localhost', 'https://example.com', 'x@example.com', '*.example.com',
			'example.com/path', 'example.com:25', 'example..com', '-example.com', 'example-.com',
			'example.com.', 'ex ample.com', 'उदाहरण.com', str_repeat( 'a', 64 ) . '.com' ) );
	}
}
