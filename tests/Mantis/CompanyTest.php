<?php
namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

require_api( 'company_api.php' );

class CompanyTest extends MantisCoreBase {
	private $companies = array();
	private $users = array();
	private $actor;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$this->actor = auth_get_current_user_id();
	}

	protected function tearDown(): void {
		current_user_set( $this->actor );
		foreach( $this->users as $id ) { user_delete( $id ); }
		foreach( $this->companies as $id ) {
			db_query( 'DELETE FROM {company} WHERE id=' . db_param(), array( $id ) );
		}
		parent::tearDown();
	}

	private function makeCompany( string $p_name = 'Company' ): int {
		$id = company_add( $p_name . ' ' . bin2hex( random_bytes( 5 ) ) );
		$this->companies[] = $id;
		return $id;
	}

	private function makeUser(): int {
		$id = (int)user_get_id_by_cookie( user_create( 'company' . bin2hex( random_bytes( 5 ) ), 'password', '', REPORTER ) );
		$this->users[] = $id;
		return $id;
	}

	private function updateUser( int $p_id, array $p_fields, bool $p_self ): void {
		if( $p_self ) {
			current_user_set( $p_id );
		}
		try {
			$command = $p_self ? new \UserProfileUpdateCommand( array(
				'query' => array( 'user_id' => $p_id ), 'payload' => array( 'profile' => $p_fields )
			) ) : new \UserUpdateCommand( array(
				'query' => array( 'user_id' => $p_id ), 'payload' => array( 'user' => $p_fields, 'notify_user' => false )
			) );
			$command->execute();
		} finally {
			current_user_set( $this->actor );
		}
	}

	public function testDefaultCompanyAndNewUserDefault() {
		$default = company_get( COMPANY_NOT_SPECIFIED );
		$this->assertSame( 'Not specified', $default['name'] );
		$this->assertSame( 1, (int)$default['enabled'] );
		$id = $this->makeUser();
		$this->assertSame( COMPANY_NOT_SPECIFIED, (int)user_get_field( $id, 'company_id' ) );
	}

	public function testBothCommandsSelectCompanyAndPreserveOmittedSelection() {
		$company = $this->makeCompany();
		$id = $this->makeUser();
		foreach( array( true, false ) as $self ) {
			$this->updateUser( $id, array( 'company_id' => (string)$company ), $self );
			$this->assertSame( $company, (int)user_get_field( $id, 'company_id' ) );
			$this->updateUser( $id, array( 'position_title' => 'Engineer' ), $self );
			$this->assertSame( $company, (int)user_get_field( $id, 'company_id' ) );
			$this->updateUser( $id, array( 'company_id' => COMPANY_NOT_SPECIFIED ), $self );
		}
	}

	public function testInvalidSelectionsAndLegacyTextFailBeforeAnyWrites() {
		$id = $this->makeUser();
		foreach( array( true, false ) as $self ) {
			foreach( array( 0, -1, '1.5', array(), 999999999, null, false ) as $invalid ) {
				$before = user_get_row( $id );
				try {
					$this->updateUser( $id, array( 'company_id' => $invalid, 'position_title' => 'Must not save' ), $self );
					$this->fail( 'Invalid selection accepted' );
				} catch( ClientException $e ) { $this->assertSame( ERROR_INVALID_FIELD_VALUE, $e->getCode() ); }
				$this->assertSame( $before, user_get_row( $id ) );
			}
			try {
				$this->updateUser( $id, array( 'company' => 'Free text' ), $self );
				$this->fail( 'Legacy text accepted' );
			} catch( ClientException $e ) { $this->assertSame( ERROR_INVALID_FIELD_VALUE, $e->getCode() ); }
		}
	}

	public function testDisabledCompanyRetainedButCannotBeNewlySelected() {
		$company = $this->makeCompany();
		$name = company_get( $company )['name'];
		$id = $this->makeUser();
		$this->updateUser( $id, array( 'company_id' => $company ), false );
		company_update( $company, $name, false );
		$this->assertArrayNotHasKey( $company, company_get_rows() );
		$this->assertArrayHasKey( $company, company_get_rows( $company ) );
		foreach( array( true, false ) as $self ) {
			$this->updateUser( $id, array( 'company_id' => $company ), $self );
		}
		$other = $this->makeUser();
		foreach( array( true, false ) as $self ) {
			try {
				$this->updateUser( $other, array( 'company_id' => $company ), $self );
				$this->fail( 'Disabled company assigned to a new user' );
			} catch( ClientException $e ) { $this->assertSame( ERROR_INVALID_FIELD_VALUE, $e->getCode() ); }
		}
	}

	public function testRenameUpdatesChartsAndMeetingRosterWithoutChangingUserIds() {
		$company = $this->makeCompany();
		$id = $this->makeUser();
		$this->updateUser( $id, array( 'company_id' => $company ), false );
		$name = 'Renamed ' . bin2hex( random_bytes( 5 ) );
		company_update( $company, $name, true );
		$this->assertSame( $name, user_get_enabled_rows()[$id]['company'] );
		require_once dirname( __DIR__, 2 ) . '/ai_assist_meeting_api.php';
		$this->assertSame( $name, ai_assist_get_meeting_candidates()[$id]['company'] );
		$this->assertSame( $company, (int)user_get_field( $id, 'company_id' ) );
	}

	public function testDefaultCannotBeDisabledOrRenamedAndNamesMustBeUnique() {
		$company = $this->makeCompany();
		foreach( array( array( COMPANY_NOT_SPECIFIED, 'Other', true ), array( COMPANY_NOT_SPECIFIED, 'Not specified', false ),
			array( $company, '', true ), array( $company, str_repeat( 'a', DB_FIELD_SIZE_COMPANY + 1 ), true ),
			array( $company, 'not specified', true ) ) as $args ) {
			try {
				company_update( ...$args );
				$this->fail( 'Invalid company change accepted' );
			} catch( ClientException $e ) { $this->assertSame( ERROR_INVALID_FIELD_VALUE, $e->getCode() ); }
		}
		$this->assertSame( 'Not specified', company_get( COMPANY_NOT_SPECIFIED )['name'] );
	}

	public function testOrdinaryUsersCannotMaintainCompanyList() {
		$id = $this->makeUser();
		current_user_set( $id );
		try {
			company_add( 'Unauthorised company' );
			$this->fail( 'Ordinary user added a company' );
		} catch( ClientException $e ) { $this->assertSame( ERROR_ACCESS_DENIED, $e->getCode() ); }
	}

	public function testSelectorEscapesMarkupInCompanyNames() {
		$company = $this->makeCompany( '<script>"&' );
		ob_start();
		company_print_options( $company );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'value="' . $company . '" selected="selected"', $html );
	}

	public function testMigrationResetsAllLegacyNamesAndMatchesCanonicalSchema() {
		require dirname( __DIR__, 2 ) . '/admin/schema.php';
		$tables = array( 'co_old_user', 'co_new_user', 'co_config', 'co_company' );
		try {
			$new = str_replace( 'CREATE TABLE IF NOT EXISTS ' . db_get_table( 'user' ) . ' (', 'CREATE TEMPORARY TABLE co_new_user (', $g_upgrade[47][1] );
			$old = str_replace( array( 'co_new_user', '`company_id` int(10) unsigned NOT NULL DEFAULT 1' ), array( 'co_old_user', "`company` varchar(128) NOT NULL DEFAULT ''" ), $new );
			$old = str_replace( ",\n\t  KEY `idx_company_id` (`company_id`)", '', $old );
			db_query( $new );
			db_query( $old );
			db_query( str_replace( 'CREATE TABLE IF NOT EXISTS ' . db_get_table( 'config' ) . ' (', 'CREATE TEMPORARY TABLE co_config (', $g_upgrade[0][1] ) );
			db_query( "INSERT INTO co_config (config_id, value) VALUES ('database_version', '80')" );
			db_query( "INSERT INTO co_old_user (username, cookie_string, company) VALUES ('one','one','HC Robotics Pvt Ltd'), ('two','two','HC Robotics INC'), ('three','three','')" );
			$sql = file_get_contents( dirname( __DIR__, 2 ) . '/admin/tools/schema-updates/2026-11-06_companies.sql' );
			$sql = preg_replace( '/^--.*$/m', '', $sql );
			$sql = preg_replace_callback( '/\b(TABLE(?: IF NOT EXISTS)?|INTO|UPDATE) `(company|user|config)`/', function( $m ) {
				$names = array( 'company' => 'co_company', 'user' => 'co_old_user', 'config' => 'co_config' );
				return $m[1] . ' `' . $names[$m[2]] . '`';
			}, $sql );
			$sql = str_replace( 'CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql );
			foreach( explode( ';', $sql ) as $statement ) {
				if( trim( $statement ) !== '' ) { db_query( $statement ); }
			}
			$definitions = array();
			foreach( array( 'co_old_user', 'co_new_user' ) as $name ) {
				$result = db_query( 'SHOW CREATE TABLE ' . $name );
				$row = array_change_key_case( db_fetch_array( $result ), CASE_LOWER );
				$definitions[] = preg_replace( array( '/CREATE TEMPORARY TABLE `[^`]+`/', '/ AUTO_INCREMENT=[0-9]+/' ), array( 'CREATE TABLE `test`', '' ), $row['create table'] );
			}
			$this->assertSame( $definitions[1], $definitions[0] );
			$result = db_query( 'SELECT COUNT(*) AS n FROM co_old_user WHERE company_id=1' );
			$this->assertSame( 3, (int)db_fetch_array( $result )['n'] );
			$result = db_query( 'SELECT * FROM co_company' );
			$this->assertSame( 1, db_num_rows( $result ) );
			$row = db_fetch_array( $result );
			$this->assertSame( 'Not specified', $row['name'] );
			$this->assertSame( 1, (int)$row['enabled'] );
			$result = db_query( "SELECT value FROM co_config WHERE config_id='database_version'" );
			$this->assertSame( '82', db_fetch_array( $result )['value'] );
		} finally {
			foreach( $tables as $table ) { db_query( 'DROP TEMPORARY TABLE IF EXISTS ' . $table ); }
		}
	}
}
