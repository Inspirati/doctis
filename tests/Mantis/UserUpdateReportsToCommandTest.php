<?php
# Doctis administrator Reports To update tests.

namespace Mantis\tests\Mantis;

use Mantis\Exceptions\ClientException;

class UserUpdateReportsToCommandTest extends MantisCoreBase {
	private $user_id;

	protected function setUp(): void {
		parent::setUp();
		self::login();
		$t_cookie = user_create(
			'OrgReport' . rand(),
			'password',
			'org-report-' . rand() . '@example.test'
		);
		$this->user_id = user_get_id_by_cookie( $t_cookie );
	}

	protected function tearDown(): void {
		user_delete( $this->user_id );
		parent::tearDown();
	}

	public function testAdministratorUpdatesReportsTo() {
		$t_manager_id = auth_get_current_user_id();
		$t_command = new \UserUpdateCommand( $this->commandData( $t_manager_id ) );
		$t_command->execute();

		$this->assertSame( $t_manager_id, (int)user_get_field( $this->user_id, 'reports_to' ) );
	}

	public function testRejectsSelfReporting() {
		$this->expectException( ClientException::class );
		$t_command = new \UserUpdateCommand( $this->commandData( $this->user_id ) );
		$t_command->execute();
	}

	private function commandData( $p_reports_to ) {
		return array(
			'query' => array( 'user_id' => $this->user_id ),
			'payload' => array(
				'user' => array( 'reports_to' => $p_reports_to ),
				'notify_user' => false,
			),
		);
	}
}
